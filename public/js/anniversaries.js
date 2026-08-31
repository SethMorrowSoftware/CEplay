/**
 * Anniversaries page — who is coming up on a work anniversary, and every
 * setting the anniversary bot uses.
 *
 * The bot itself runs from a systemd timer (anniversaries/anniversary_bot.php);
 * this page is the front end for its configuration and a way to see what it
 * will do before it does it. Settings are stored in api_config, so saving here
 * changes the next morning's post without anyone touching a file on the server.
 *
 *   GET  /api/anniversaries          — settings + the field schema this form renders
 *   PUT  /api/anniversaries          — save (anniversaries_manage)
 *   GET  /api/anniversaries/roster   — EVERY person on the roster, their next
 *                                      anniversary, and what the bot will do
 *                                      about it. One read per page load; the
 *                                      time range, sort, search and CSV are all
 *                                      client-side over that one payload.
 *   POST /api/anniversaries/test     — health check, preview, GIF check, live post
 *
 * (/api/anniversaries/upcoming still exists and still works — the next-N-days
 * view the bot's --list prints. This page outgrew it: a 60-day window cannot
 * answer "is anybody missing" or "who is up in the last quarter".)
 *
 * The Slack token and Giphy key are write-only: the server sends back whether
 * one is set, never the value.
 *
 * The sibling of birthdays.js. The two pages are deliberately built the same
 * way — the same check row, the same save bar, the same preview dialog — so
 * whoever configures one already knows how to configure the other.
 */
(function() {
    App.registerRoute('#/anniversaries', { render: renderAnniversariesPage });

    var state = {
        config: null,     // current values (secrets blanked)
        fields: null,     // schema from the server
        defaults: null,   // built-in pools, for the "reset to default" links
        canManage: false,
        dirty: {},        // field -> new value, pending save
        roster: null,     // the whole roster, fetched ONCE per page load
        // Everything below is arrangement, applied client-side over the payload
        // above. None of it re-hits the server: a roster read is an MSSQL round
        // trip behind an 8s connect and a 30s query timeout, so changing a
        // filter must never cost one (the Item Watch toolbar rule).
        range: 'all',
        custom: { from: '', to: '' },
        search: '',
        chip: 'all',
        sort: { key: '_date', dir: 'asc' },   // the window's matched anniversary
        page: 1,
        pageSize: 50,
        showDropped: false,
        gen: 0
    };

    function canManage() { return state.canManage && App.canAccess('anniversaries_manage'); }

    // ---------------------------------------------------------------- render

    async function renderAnniversariesPage(container) {
        var gen = ++state.gen;
        state.dirty = {};

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Work Anniversaries' }),
                App.el('p', { className: 'page-subtitle',
                    textContent: 'Years of service from the POS roster, and everything the Slack anniversary bot says when one comes round.' })
            ]),
            App.el('div', { className: 'flex gap-sm', id: 'an-header-actions' })
        ]));

        container.appendChild(App.el('div', { id: 'an-status', className: 'an-status-row' }));
        container.appendChild(App.el('div', { id: 'an-today' }));
        container.appendChild(App.el('div', { id: 'an-toolbar' }));
        container.appendChild(App.el('div', { id: 'an-roster' }, [App.loading()]));
        container.appendChild(App.el('div', { id: 'an-dropped' }));
        container.appendChild(App.el('div', { id: 'an-settings' }));

        try {
            var payload = await API.get('/anniversaries');
            if (gen !== state.gen) return;
            state.config = payload.config || {};
            state.fields = payload.fields || {};
            state.defaults = payload.defaults || {};
            state.canManage = !!payload.can_manage;
        } catch (err) {
            App.toast('Could not load anniversary settings: ' + errText(err), 'error');
            return;
        }

        buildHeaderActions();
        buildSettings();
        // Sequential, not concurrent: the list and the health check each read
        // the roster over MSSQL, and firing both at once opens two connections
        // behind an 8-second connect timeout for one page load. The catch is
        // load-bearing — the check must still run when the list could not.
        loadRoster().catch(function() {}).then(function() { runCheck(); });
    }

    function errText(err) { return (err && err.message) ? err.message : 'unknown error'; }

    function buildHeaderActions() {
        var box = document.getElementById('an-header-actions');
        if (!box) return;
        box.innerHTML = '';
        box.appendChild(App.el('button', {
            className: 'btn btn-secondary', textContent: 'Re-check',
            onClick: function() { runCheck(true); }
        }));
        if (canManage()) {
            box.appendChild(App.el('button', {
                className: 'btn btn-primary', textContent: 'Post a sample',
                onClick: openDemoDialog
            }));
        }
    }

    // ---------------------------------------------------------------- status

    async function runCheck(announce) {
        var box = document.getElementById('an-status');
        if (!box) return;
        box.innerHTML = '';
        box.appendChild(App.el('div', { className: 'an-check-loading text-sm text-muted',
            textContent: 'Checking…' }));
        try {
            var res = await API.post('/anniversaries/test', { action: 'check' });
            renderChecks(res.checks || []);
            if (announce) App.toast('Re-checked.', 'success');
        } catch (err) {
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'an-check an-check-fail' }, [
                App.el('span', { className: 'an-check-label', textContent: 'Check failed' }),
                App.el('span', { className: 'an-check-detail', textContent: errText(err) })
            ]));
        }
    }

    function renderChecks(checks) {
        var box = document.getElementById('an-status');
        if (!box) return;
        box.innerHTML = '';
        checks.forEach(function(c) {
            box.appendChild(App.el('div', { className: 'an-check an-check-' + c.status }, [
                App.el('span', { className: 'an-check-dot', 'aria-hidden': 'true' }),
                App.el('span', { className: 'an-check-label', textContent: c.label }),
                App.el('span', { className: 'an-check-detail', textContent: c.detail || '' })
            ]));
        });
    }

    // ---------------------------------------------------------------- roster
    //
    // ONE list, holding EVERY person the roster query returns — not a 60-day
    // window, and not only the people the bot is going to post about. The time
    // range, the sort, the search and the CSV are all arrangement applied to
    // the payload already in the browser.
    //
    // Two dates per person, deliberately never merged into one column:
    //
    //   Next anniversary — the calendar fact. When their day falls.
    //   Slack post       — what the bot will actually do about it, once
    //                      min_years, milestone-only mode and the 29 February
    //                      rule have had their say. Often a different date, and
    //                      sometimes never.
    //
    // Show one column and this page starts answering "why didn't the bot
    // mention Dana?" wrongly. Show both and it answers it correctly, which is
    // the whole reason the list is worth having beside a Slack bot.

    async function loadRoster() {
        var gen = state.gen;
        var box = document.getElementById('an-roster');
        if (!box) return;
        try {
            var d = await API.get('/anniversaries/roster');
            if (gen !== state.gen) return;
            state.roster = d;
        } catch (err) {
            if (gen !== state.gen) return;
            // Drop the previous payload and its toolbar with it. Leaving them
            // up means the next filter click quietly redraws the old table over
            // the error, and the list then claims to be current when it is not.
            state.roster = null;
            ['an-today', 'an-toolbar', 'an-dropped'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.innerHTML = '';
            });
            box.innerHTML = '';
            box.appendChild(card('Everyone on the roster', [
                App.el('p', { className: 'text-sm', textContent: errText(err) })
            ]));
            return;
        }
        renderToday();
        renderToolbar();
        renderRoster();
        renderDropped();
    }

    /** Exactly what the bot would post today, above everything else. */
    function renderToday() {
        var box = document.getElementById('an-today');
        if (!box) return;
        box.innerHTML = '';
        var d = state.roster || {};
        if (!d.today_preview) return;
        box.appendChild(App.el('div', { className: 'an-today' }, [
            App.el('div', { className: 'an-today-label', textContent: 'Going out today' }),
            App.el('div', { className: 'an-message' }, messageLines(d.today_preview))
        ]));
    }

    // ------------------------------------------------------------ time ranges
    //
    // Every window here sits inside twelve months either side of today, and
    // that bound is not arbitrary: each person carries their PREVIOUS and their
    // NEXT anniversary and nothing else, which is exactly enough to place
    // everybody exactly once in any window of that size. A wider window would
    // silently miss people whose relevant anniversary is neither of the two.

    var RANGES = [
        ['Coming up', [
            ['next7',   'Next 7 days'],
            ['next30',  'Next 30 days'],
            ['next90',  'Next 90 days'],
            ['next365', 'Next 12 months']
        ]],
        ['Calendar', [
            ['month_this',    'This month'],
            ['month_next',    'Next month'],
            ['quarter_this',  'This quarter'],
            ['quarter_next',  'Next quarter'],
            ['rest_of_year',  'Rest of this year']
        ]],
        ['Already gone by', [
            ['past30', 'Last 30 days'],
            ['past90', 'Last 90 days'],
            ['ytd',    'Earlier this year']
        ]],
        ['Everyone', [
            ['all',    'Everyone — no date filter'],
            ['custom', 'Custom dates…']
        ]]
    ];

    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function isoOf(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
    function dateOf(iso) { return new Date(iso + 'T12:00:00'); }
    function addDays(iso, n) { var d = dateOf(iso); d.setDate(d.getDate() + n); return isoOf(d); }
    function monthStart(iso, add) {
        var d = dateOf(iso); d.setDate(1); d.setMonth(d.getMonth() + (add || 0)); return isoOf(d);
    }
    function monthEnd(iso, add) { return addDays(monthStart(iso, (add || 0) + 1), -1); }

    /** The [from, to] window a range key means today, or null for "everyone". */
    function rangeWindow(key, today) {
        var year = today.slice(0, 4);
        var q = Math.floor((parseInt(today.slice(5, 7), 10) - 1) / 3);   // 0-3
        switch (key) {
            case 'next7':   return { from: today, to: addDays(today, 7) };
            case 'next30':  return { from: today, to: addDays(today, 30) };
            case 'next90':  return { from: today, to: addDays(today, 90) };
            case 'next365': return { from: today, to: addDays(today, 365) };
            case 'month_this': return { from: monthStart(today), to: monthEnd(today) };
            case 'month_next': return { from: monthStart(today, 1), to: monthEnd(today, 1) };
            case 'quarter_this':
                return { from: year + '-' + pad2(q * 3 + 1) + '-01', to: monthEnd(year + '-' + pad2(q * 3 + 3) + '-01') };
            case 'quarter_next':
                var nq = (q + 1) % 4, ny = nq === 0 ? String(parseInt(year, 10) + 1) : year;
                return { from: ny + '-' + pad2(nq * 3 + 1) + '-01', to: monthEnd(ny + '-' + pad2(nq * 3 + 3) + '-01') };
            case 'rest_of_year': return { from: today, to: year + '-12-31' };
            case 'past30':  return { from: addDays(today, -30), to: today };
            case 'past90':  return { from: addDays(today, -90), to: today };
            case 'ytd':     return { from: year + '-01-01', to: today };
            case 'custom':
                if (!state.custom.from || !state.custom.to) return null;
                return { from: state.custom.from, to: state.custom.to };
            default: return null;
        }
    }

    function rangeLabel(key) {
        for (var i = 0; i < RANGES.length; i++) {
            for (var j = 0; j < RANGES[i][1].length; j++) {
                if (RANGES[i][1][j][0] === key) return RANGES[i][1][j][1];
            }
        }
        return key;
    }

    /**
     * Is this person's anniversary inside the window?
     *
     * Either side counts — a window that spans today has some people's
     * anniversary already behind them and some still ahead, and both are the
     * same fact about the same year.
     */
    function inWindow(r, win) {
        if (!win) return true;
        if (r.next_date && r.next_date >= win.from && r.next_date <= win.to) return true;
        if (r.prev_date && r.prev_date >= win.from && r.prev_date <= win.to) return true;
        return false;
    }

    /** A day the bot will refuse outright, because too many people share it. */
    function overLimit(r) {
        var d = state.roster || {};
        return !!r.post_date && r.shared > (d.max_celebrants || 25);
    }

    /** Everything the bot will not say something about on the next round. */
    function isSilent(r) { return r.silent !== '' || overLimit(r); }

    function inRangeRows() {
        var d = state.roster || {};
        var win = rangeWindow(state.range, d.today);
        return (d.rows || []).filter(function(r) { return inWindow(r, win); });
    }

    /**
     * WHICH anniversary put this person in the window.
     *
     * A backward range matches on the anniversary already behind them, and the
     * date column has to show that one — filter to "last 90 days" and see a
     * column full of dates a year away and the list looks broken, because the
     * date on screen is not the date that was matched.
     */
    function matched(r, win) {
        if (win && r.next_date && r.next_date >= win.from && r.next_date <= win.to) {
            return { date: r.next_date, years: r.next_years, milestone: r.next_milestone };
        }
        if (win && r.prev_date && r.prev_date >= win.from && r.prev_date <= win.to) {
            return { date: r.prev_date, years: r.prev_years, milestone: false };
        }
        return { date: r.next_date, years: r.next_years, milestone: r.next_milestone };
    }

    /** Range, then chip, then search — the order the counts are described in. */
    function visibleRows() {
        var d = state.roster || {};
        var win = rangeWindow(state.range, d.today);
        var rows = inRangeRows().filter(function(r) {
            if (state.chip === 'milestone') return !!r.next_milestone;
            if (state.chip === 'silent') return isSilent(r);
            return true;
        });
        if (state.search) {
            rows = rows.filter(function(r) {
                return App.matchesSearch(r, state.search,
                    ['name', function(x) { return x.emp_no || ''; }]);
            });
        }
        // Annotate rather than mutate: these are the fields the table sorts on
        // and renders, and they depend on the window, not on the person.
        rows = rows.map(function(r) {
            var m = matched(r, win);
            var copy = {};
            Object.keys(r).forEach(function(k) { copy[k] = r[k]; });
            copy._date = m.date;
            copy._years = m.years;
            copy._milestone = m.milestone;
            copy._past = !!(m.date && d.today && m.date < d.today);
            copy._days = m.date === r.next_date ? r.days_until
                : (m.date ? -daysBetween(m.date, d.today) : null);
            return copy;
        });
        return App.sortRows(rows, state.sort, COLUMNS);
    }

    /** Whole days between two ISO dates, both anchored at local noon. */
    function daysBetween(from, to) {
        return Math.round((dateOf(to) - dateOf(from)) / 86400000);
    }

    // ---------------------------------------------------------------- toolbar

    function renderToolbar() {
        var box = document.getElementById('an-toolbar');
        var d = state.roster || {};
        if (!box) return;
        box.innerHTML = '';
        if (!d.roster_ok) return;

        var rangeSel = App.el('select', {
            className: 'form-input form-input-sm an-toolbar-select', id: 'an-range',
            title: 'Which anniversaries to list'
        }, RANGES.map(function(grp) {
            return App.el('optgroup', { label: grp[0] }, grp[1].map(function(o) {
                return App.el('option', { value: o[0], textContent: o[1], selected: o[0] === state.range });
            }));
        }));
        rangeSel.addEventListener('change', function() {
            state.range = rangeSel.value;
            state.page = 1;
            renderToolbar();
            renderRoster();
        });

        var groups = [
            App.el('div', { className: 'an-toolbar-group' }, [
                App.el('label', { className: 'text-sm text-secondary', 'for': 'an-range', textContent: 'Show' }),
                rangeSel
            ])
        ];

        if (state.range === 'custom') {
            var from = App.el('input', {
                className: 'form-input form-input-sm', type: 'date', id: 'an-from',
                value: state.custom.from,
                min: addDays(d.today, -365), max: addDays(d.today, 365),
                'aria-label': 'From date'
            });
            var to = App.el('input', {
                className: 'form-input form-input-sm', type: 'date', id: 'an-to',
                value: state.custom.to,
                min: addDays(d.today, -365), max: addDays(d.today, 365),
                'aria-label': 'To date'
            });
            groups.push(App.el('div', { className: 'an-toolbar-group' }, [
                from, App.el('span', { className: 'text-sm text-muted', textContent: 'to' }), to,
                App.el('button', {
                    className: 'btn btn-sm btn-secondary', textContent: 'Apply',
                    onClick: function() {
                        if (!from.value || !to.value) {
                            App.toast('Pick both a start and end date.', 'warning');
                            return;
                        }
                        if (from.value > to.value) {
                            App.toast('The start date is after the end date.', 'warning');
                            return;
                        }
                        // The min/max on the inputs is a hint, not a guarantee —
                        // a typed date sails past it. Refusing is the honest
                        // answer: each person carries only their previous and
                        // next anniversary, so a wider window would quietly
                        // leave people out of a list headed "everyone".
                        var lo = addDays(d.today, -365), hi = addDays(d.today, 365);
                        if (from.value < lo || to.value > hi) {
                            App.toast('Pick dates within a year either side of today — a wider '
                                + 'window would miss people rather than show them.', 'warning');
                            return;
                        }
                        state.custom = { from: from.value, to: to.value };
                        state.page = 1;
                        renderRoster();
                    }
                })
            ]));
        }

        var searchInput = App.buildSearchInput({
            placeholder: 'Search by name…',
            ariaLabel: 'Search the roster',
            value: state.search,
            onSearch: function(term) { state.search = term; state.page = 1; renderRoster(); }
        });
        searchInput.id = 'an-search';
        searchInput.style.flex = '1 1 12rem';
        groups.push(App.el('div', { className: 'an-toolbar-group an-toolbar-grow' }, [searchInput]));
        groups.push(App.el('div', { className: 'an-toolbar-group filter-pills', id: 'an-chips' }));
        groups.push(App.el('button', {
            className: 'btn btn-sm btn-ghost', textContent: '⭳ CSV',
            title: 'Download the list exactly as filtered and sorted below',
            onClick: exportCsv
        }));

        box.appendChild(App.el('div', { className: 'card an-toolbar' }, [
            App.el('div', { className: 'card-body an-toolbar-body' }, groups)
        ]));
        renderChips();
    }

    /**
     * The counts are recomputed AFTER the time range, so each chip describes
     * the window on screen rather than the whole roster.
     */
    function renderChips() {
        var box = document.getElementById('an-chips');
        if (!box) return;
        box.innerHTML = '';
        var rows = inRangeRows();
        var counts = {
            all: rows.length,
            milestone: rows.filter(function(r) { return !!r.next_milestone; }).length,
            silent: rows.filter(isSilent).length
        };
        [['all', 'All'], ['milestone', 'Milestone years'], ['silent', 'Bot stays quiet']]
            .forEach(function(c) {
                if (c[0] !== 'all' && counts[c[0]] === 0 && state.chip !== c[0]) return;
                box.appendChild(App.el('button', {
                    className: 'filter-pill' + (state.chip === c[0] ? ' active' : ''),
                    'aria-pressed': state.chip === c[0] ? 'true' : 'false',
                    onClick: function() { state.chip = c[0]; state.page = 1; renderRoster(); }
                }, [
                    document.createTextNode(c[1]),
                    App.el('span', { className: 'pill-count', textContent: String(counts[c[0]]) })
                ]));
            });
    }

    // ------------------------------------------------------------- the table

    // `_date`/`_years` are the anniversary the CURRENT window matched, which is
    // the one behind them for a backward range — see matched().
    var COLUMNS = [
        { key: '_date',     label: 'Anniversary',      type: 'date', defaultDir: 'asc' },
        { key: 'name',      label: 'Person',           type: 'string' },
        { key: '_years',    label: 'Years marked',     type: 'number', className: 'text-right' },
        { key: 'years',     label: 'Years of service', type: 'number', className: 'text-right' },
        { key: 'hire_date', label: 'Hired',            type: 'date' },
        { key: 'post_date', label: 'Slack post',       type: 'date' }
    ];

    function renderRoster() {
        var box = document.getElementById('an-roster');
        var d = state.roster || {};
        if (!box) return;
        // The single choke point for the chip counts too: they describe the
        // current time window, and every path that changes the window ends up
        // here. Refreshing them anywhere else leaves one path that doesn't.
        renderChips();
        box.innerHTML = '';

        if (!d.roster_ok) {
            box.appendChild(card('Everyone on the roster', [
                App.el('p', { className: 'text-sm', textContent: d.error || 'The roster could not be read.' }),
                App.el('p', { className: 'text-xs text-muted',
                    textContent: 'The roster query is under Settings below. Nothing will post until this reads cleanly.' })
            ]));
            return;
        }

        var body = banners(d);

        // The wrong-column signature: rows came back and every one was set
        // aside. Print the census beside the empty state, because that is what
        // says "this query is pointed at the wrong column" rather than "nobody
        // works here".
        if (!d.rows.length) {
            body.push(App.emptyState('👥',
                d.row_count
                    ? d.row_count + ' roster rows were read and not one carried a usable hire date. '
                      + 'The roster query may be pointing at the wrong column — run '
                      + 'anniversaries/discover.php on the venue server to find the right one.'
                    : 'The roster query returned no rows at all.'));
            box.appendChild(card('Everyone on the roster', body, rosterMeta(d, 0)));
            return;
        }

        var rows = visibleRows();
        if (!rows.length) {
            body.push(App.el('p', { className: 'text-sm text-muted',
                textContent: 'Nobody on the roster matches ' + describeFilter() + '.' }));
            body.push(App.el('button', {
                className: 'an-link', type: 'button', textContent: 'Clear the filters',
                onClick: function() {
                    state.range = 'all'; state.chip = 'all'; state.search = ''; state.page = 1;
                    var s = document.getElementById('an-search');
                    if (s) s.value = '';
                    renderToolbar();
                    renderRoster();
                }
            }));
            box.appendChild(card('Everyone on the roster', body, rosterMeta(d, 0)));
            return;
        }

        var thead = App.el('thead', {}, [
            App.el('tr', {}, COLUMNS.map(function(col) {
                return App.sortableTh(col, state.sort, function(next) {
                    state.sort = next;
                    renderRoster();
                });
            }))
        ]);

        var pg = App.paginate(rows, state.page, state.pageSize);
        state.page = pg.page;
        var tbody = App.el('tbody', {}, pg.items.map(function(r) { return rosterRow(r, d); }));

        body.push(App.el('div', { className: 'table-scroll' }, [
            App.el('table', { className: 'data-table directory-table an-roster-table' }, [thead, tbody])
        ]));

        var paging = { page: pg.page, pageSize: pg.pageSize, totalItems: pg.total };
        body.push(App.buildPaginationBar(paging, function() {
            state.page = paging.page;
            state.pageSize = paging.pageSize;
            renderRoster();
        }, { itemLabel: 'people' }));

        box.appendChild(card('Everyone on the roster', body, rosterMeta(d, rows.length)));
    }

    function rosterRow(r, d) {
        var isToday = r._date === d.today;
        var cells = [];

        // 1 — the anniversary this window matched: theirs coming up, or the one
        // already behind them when the range looks backwards.
        cells.push(App.el('td', {}, [
            App.el('div', { textContent: r._date ? fullDate(r._date) : '—' }),
            App.el('div', { className: 'text-xs text-muted', textContent: whenText(r) })
        ]));

        // 2 — who. The employee number is the key an admin uses to build an
        // opt-out list, so the server only sends it to whoever can edit one.
        var who = [App.el('div', { textContent: r.name, style: { fontWeight: '500' } })];
        if (r.emp_no) {
            who.push(App.el('div', { className: 'text-xs text-muted font-mono', textContent: r.emp_no }));
        }
        cells.push(App.el('td', {}, who));

        // 3 — the number marked on that date, milestone-marked.
        cells.push(App.el('td', { className: 'text-right' },
            r._years == null ? [App.el('span', { className: 'text-muted', textContent: '—' })] : [
                App.el('span', { className: 'an-person' + (r._milestone ? ' an-person-milestone' : '') }, [
                    App.el('span', { className: 'an-person-years', textContent: yearLabel(r._years) })
                ])
            ]));

        // 4 — what they have completed today. A different number from the one
        // beside it, and under the 'skip' leap rule it can differ by more than
        // one: a 29 February hire completes years the venue never marks.
        cells.push(App.el('td', { className: 'text-right num-cell', textContent: String(r.years) }));

        cells.push(App.el('td', { className: 'text-xs', textContent: fullDate(r.hire_date) }));

        cells.push(postCell(r, d));

        return App.el('tr', { className: isToday ? 'an-row-today-row' : null }, cells);
    }

    /** What Slack will actually do — never merged with the calendar column. */
    function postCell(r, d) {
        var kids = [];
        var muted = !d.enabled;

        if (overLimit(r)) {
            kids.push(App.el('div', { className: 'an-post-none', textContent: 'Will not post' }));
            kids.push(App.el('div', { className: 'text-xs text-muted',
                textContent: r.shared + ' people share ' + shortDate(r.post_date)
                    + ', above the limit of ' + d.max_celebrants }));
        } else if (!r.post_date) {
            kids.push(App.el('div', { className: 'an-post-none', textContent: 'Never again' }));
            kids.push(App.el('div', { className: 'text-xs text-muted', textContent: silentWhy(r, d) }));
        } else if (r.post_is_next) {
            kids.push(App.el('div', { className: 'an-post-on',
                textContent: muted ? 'Would post on the day' : 'On the day' }));
        } else {
            kids.push(App.el('div', { textContent: shortDate(r.post_date) + ' · ' + yearLabel(r.post_years) }));
            kids.push(App.el('div', { className: 'text-xs text-muted', textContent: silentWhy(r, d) }));
        }
        return App.el('td', { className: muted ? 'an-post-off' : null }, kids);
    }

    function silentWhy(r, d) {
        switch (r.silent) {
            case 'below_min':
                return 'nothing is said below ' + yearLabel(d.min_years);
            case 'milestones_only':
                return 'milestone years only';
            case 'no_milestone_left':
                return 'past the last milestone year (' + (d.milestone_years || []).join(', ') + ')';
            case 'no_milestones':
                return 'milestone years only, and no milestone years are set';
            case 'never':
                return 'no future anniversary is observed';
            default:
                return '';
        }
    }

    /** Every warning that changes what the list means, above the table. */
    function banners(d) {
        var out = [];
        if (d.truncated) {
            out.push(alert('Only the first ' + d.row_limit + ' roster rows were read, so this list is '
                + 'incomplete — somebody missing from it is not necessarily somebody without an '
                + 'anniversary. The ceiling is the roster_max_rows setting in data/anniversary_config.php.'));
        }
        if (d.employment && !d.employment.ok) {
            out.push(alert('Still employed: ' + d.employment.summary
                + ' — this list, and the Slack post, cover whoever that query returns.'));
        }
        if (!d.enabled) {
            out.push(alert('Posting is turned off, so nothing goes out on any of these dates. '
                + 'The "Slack post" column below shows what would happen if it were on.'));
        }
        if (d.mode === 'milestones') {
            out.push(App.el('p', { className: 'text-sm text-muted an-mode-note',
                textContent: (d.milestone_years || []).length
                    ? 'Milestone years only (' + (d.milestone_years || []).join(', ') + '). '
                      + 'Every anniversary is listed here; only those years are posted.'
                    : 'Milestone years only, and no milestone years are set — nothing can ever post.' }));
        }
        return out;
    }

    function alert(text) {
        return App.el('div', { className: 'alert-warning', textContent: text });
    }

    /**
     * The headcount, read next to what makes it a headcount.
     *
     * "N people the roster query returns", never "N employees": the number is a
     * property of an operator-editable WHERE clause, and this list is the one
     * screen where a broken filter has no other symptom — 1,547 rows under a
     * heading like this looks exactly like the feature working.
     */
    function rosterMeta(d, shown) {
        var bits = [d.people_count + ' people the roster query returns'];
        if (shown !== d.people_count) {
            bits.push('showing ' + shown);
        }
        // RosterGuard's own wording, not a second copy of it: it already
        // resolves the fragments it matched back to the real column names.
        if (d.employment && d.employment.ok && d.employment.summary) {
            bits.push(d.employment.summary);
        }
        if (d.oldest_hire) {
            bits.push('longest here since ' + d.oldest_hire.slice(0, 4));
        }
        return bits.join(' · ');
    }

    function describeFilter() {
        var bits = [];
        // Name the actual dates for a custom window: "matches custom dates…" is
        // a sentence that tells the reader nothing about what was searched.
        if (state.range === 'custom' && state.custom.from && state.custom.to) {
            bits.push(fullDate(state.custom.from) + ' to ' + fullDate(state.custom.to));
        } else if (state.range !== 'all') {
            bits.push(rangeLabel(state.range).toLowerCase());
        }
        if (state.chip === 'milestone') bits.push('milestone years');
        if (state.chip === 'silent') bits.push('anniversaries the bot stays quiet about');
        if (state.search) bits.push('"' + state.search + '"');
        return bits.length ? bits.join(' + ') : 'that filter';
    }

    // ------------------------------------------------------- who is not listed

    var DROP_SECTIONS = [
        ['Needs fixing', ['no_hire_date', 'unparsed', 'no_name'],
         'No usable hire date on the roster row, so there is nothing to count from.'],
        ['Placeholder dates', ['sentinel'],
         'A stand-in date the POS stamped in. Adding one to "Ignore these hire dates" '
            + 'also stops the bot posting for everyone hired on it.'],
        ['Not a problem', ['future', 'duplicate'],
         'Hired but not started, or listed twice on the roster — usually two job codes.'],
        ['Opted out', ['excluded'],
         'Deliberately excluded by configuration. Shown so an opt-out entry that matches '
            + 'nobody is visibly not matching.']
    ];

    var DROP_LABELS = {
        no_hire_date: 'no hire date', unparsed: 'unreadable date', no_name: 'no name',
        sentinel: 'placeholder date', future: 'not started yet', duplicate: 'listed twice',
        excluded: 'opted out'
    };

    function renderDropped() {
        var box = document.getElementById('an-dropped');
        var d = state.roster || {};
        if (!box) return;
        box.innerHTML = '';
        if (!d.roster_ok) return;

        var sk = d.skipped || {};
        var total = Object.keys(sk).reduce(function(n, k) { return n + (sk[k] || 0); }, 0);
        if (!total) return;

        var toggle = App.el('button', {
            className: 'an-link', type: 'button',
            textContent: (state.showDropped ? '▾ ' : '▸ ') + total
                + ' roster row' + (total === 1 ? '' : 's') + ' not on the list',
            onClick: function() { state.showDropped = !state.showDropped; renderDropped(); }
        });

        var body = [App.el('p', { className: 'text-sm text-muted' }, [toggle])];

        if (state.showDropped) {
            if (d.dropped_capped) {
                body.push(App.el('p', { className: 'text-xs text-muted',
                    textContent: 'The counts below are complete; only the first '
                        + (d.dropped || []).length + ' rows are named individually.' }));
            }
            DROP_SECTIONS.forEach(function(sec) {
                var rows = (d.dropped || []).filter(function(x) { return sec[1].indexOf(x.reason) !== -1; });
                var count = sec[1].reduce(function(n, k) { return n + (sk[k] || 0); }, 0);
                if (!count) return;
                body.push(App.el('div', { className: 'an-drop-section' }, [
                    App.el('div', { className: 'an-drop-title', textContent: sec[0] + ' — ' + count }),
                    App.el('div', { className: 'text-xs text-muted', textContent: sec[2] }),
                    rows.length
                        ? App.el('div', { className: 'an-drop-names' }, rows.map(function(x) {
                            return App.el('span', { className: 'an-drop-name' }, [
                                App.el('span', { textContent: x.name || '(no name on the row)' }),
                                App.el('span', { className: 'text-xs text-muted',
                                    textContent: DROP_LABELS[x.reason] + (x.value ? ': ' + x.value : '') })
                            ]);
                        }))
                        : App.el('p', { className: 'text-xs text-muted',
                            textContent: d.dropped_capped
                                ? 'Past the cap on individually named rows.'
                                : (sec[1].indexOf('excluded') !== -1
                                    ? 'Names are only listed for accounts that can edit these settings.'
                                    : 'Not named individually.') })
                ]));
            });
        }

        box.appendChild(card('Not on the list', body));
    }

    // -------------------------------------------------------------- CSV export

    /** Exactly what is filtered and sorted on screen — never the raw payload. */
    function exportCsv() {
        var d = state.roster;
        if (!d || !d.roster_ok) { App.toast('Nothing to export yet.', 'warning'); return; }
        var rows = visibleRows();
        if (!rows.length) { App.toast('Nobody matches the current filter.', 'warning'); return; }

        var esc = function(v) {
            var s = v === null || v === undefined ? '' : String(v);
            return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
        };
        // The employee number is omitted from the header and every row for an
        // account that cannot see it, rather than blanked — the money-column
        // precedent from Item Watch.
        var cols = ['Name'];
        if (d.can_manage) cols.push('Employee no');
        // "Anniversary" is the one the current range matched — the same date
        // the screen shows — with the next one alongside it, which is the
        // column that differs when the range looks backwards.
        cols = cols.concat(['Hire date', 'Years of service', 'Anniversary', 'Years marked',
                            'Next anniversary', 'Milestone', 'Slack post', 'Slack post years',
                            'Why not']);

        var lines = [cols.map(esc).join(',')];
        rows.forEach(function(r) {
            var line = [r.name];
            if (d.can_manage) line.push(r.emp_no || '');
            line = line.concat([
                r.hire_date, r.years,
                r._date || '', r._years == null ? '' : r._years,
                r.next_date || '',
                r.next_milestone ? 'yes' : '',
                overLimit(r) ? 'will not post' : (r.post_date || 'never'),
                r.post_years == null ? '' : r.post_years,
                overLimit(r)
                    ? (r.shared + ' share that date, above the limit of ' + d.max_celebrants)
                    : silentWhy(r, d)
            ]);
            lines.push(line.map(esc).join(','));
        });

        var win = rangeWindow(state.range, d.today);
        var name = 'anniversaries-' + (win ? win.from + '-to-' + win.to : 'everyone') + '.csv';
        var blob = new Blob([lines.join('\n')], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(function() { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    // ------------------------------------------------------------- formatting

    function yearLabel(n) { return n + (n === 1 ? ' year' : ' years'); }

    function messageLines(text) {
        return String(text).split('\n').map(function(line) {
            return App.el('div', { className: 'an-message-line', textContent: line });
        });
    }

    var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var WEEKDAYS = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

    function shortDate(iso) {
        if (!iso) return '—';
        return parseInt(iso.slice(8, 10), 10) + ' ' + MONTHS[parseInt(iso.slice(5, 7), 10) - 1];
    }
    function fullDate(iso) {
        if (!iso) return '—';
        return shortDate(iso) + ' ' + iso.slice(0, 4);
    }

    /** "Today", "in 12 days", "8 months ago" — with the weekday, which is what
     *  somebody planning cover actually wants to know. */
    function whenText(r) {
        if (r._date == null || r._days == null) return '';
        var day = WEEKDAYS[dateOf(r._date).getDay()];
        var n = r._days;
        var rel;
        if (n === 0) rel = 'today';
        else if (n === 1) rel = 'tomorrow';
        else if (n === -1) rel = 'yesterday';
        else if (n > 0) rel = n <= 60 ? 'in ' + n + ' days' : 'in ' + Math.round(n / 30.44) + ' months';
        else rel = -n <= 60 ? -n + ' days ago' : Math.round(-n / 30.44) + ' months ago';
        return day + ' · ' + rel;
    }

    // -------------------------------------------------------------- settings

    var GROUPS = [
        { title: 'Slack', keys: ['enabled', 'slack_bot_token', 'slack_channel', 'bot_username', 'bot_icon_emoji', 'mention'] },
        { title: 'The message', keys: ['name_style', 'venue_label', 'footer_text', 'post_separately', 'greetings', 'flavors', 'milestone_greetings', 'milestone_flavors', 'multi_greetings', 'multi_flavors'] },
        { title: 'GIFs and reactions', keys: ['gifs_enabled', 'giphy_api_key', 'gifs', 'add_reactions', 'reactions'] },
        { title: 'Who gets a message', keys: ['celebrate_years', 'min_years', 'milestone_years', 'leap_day_mode', 'exclude_emp_nos', 'exclude_names', 'ignore_hire_dates', 'max_celebrants'] },
        { title: 'Roster query', keys: ['roster_sql'] }
    ];

    /**
     * The placeholders a message can use, spelled out where the wording is
     * edited. {ordinal} is the one that needs saying: it has no meaning when
     * several people share a day, and the server rejects it in those pools.
     */
    var PLACEHOLDER_NOTE = '{names} · {count} · {venue} · {years} · {year_label} · {ordinal} · {s}. '
        + 'On a shared day {years} is the COMBINED total and {ordinal} is not available.';

    function buildSettings() {
        var box = document.getElementById('an-settings');
        if (!box) return;
        box.innerHTML = '';

        if (!canManage()) {
            box.appendChild(card('Settings', [
                App.el('p', { className: 'text-sm text-muted',
                    textContent: 'You can see upcoming anniversaries, but changing the bot’s settings needs the "anniversaries_manage" permission.' })
            ]));
            return;
        }

        GROUPS.forEach(function(group) {
            var rows = [];
            if (group.title === 'The message') {
                rows.push(App.el('p', { className: 'text-xs text-muted an-placeholders',
                    textContent: PLACEHOLDER_NOTE }));
            }
            group.keys.forEach(function(key) {
                if (!state.fields[key]) return;
                rows.push(fieldRow(key, state.fields[key]));
            });
            box.appendChild(card(group.title, rows));
        });

        box.appendChild(App.el('div', { className: 'an-save-bar' }, [
            App.el('span', { id: 'an-dirty-note', className: 'text-sm text-muted', textContent: '' }),
            App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', {
                    className: 'btn btn-secondary', textContent: 'Preview wording',
                    onClick: openPreviewDialog
                }),
                App.el('button', {
                    className: 'btn btn-secondary', textContent: 'Check GIFs',
                    onClick: runGifTest
                }),
                App.el('button', {
                    id: 'an-save', className: 'btn btn-primary', textContent: 'Save settings',
                    onClick: saveSettings
                })
            ])
        ]));
    }

    function card(title, children, meta) {
        var header = [App.el('div', { className: 'card-title', textContent: title })];
        if (meta) header.push(App.el('span', { className: 'text-sm text-secondary', textContent: meta }));
        return App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header flex-between' }, header),
            App.el('div', { className: 'card-body' }, children)
        ]);
    }

    function markDirty(key, value) {
        state.dirty[key] = value;
        var note = document.getElementById('an-dirty-note');
        var n = Object.keys(state.dirty).length;
        if (note) note.textContent = n ? n + ' unsaved change' + (n === 1 ? '' : 's') : '';
    }

    function fieldRow(key, spec) {
        var current = state.config[key];
        var group = App.el('div', { className: 'form-group an-field' });
        // 'for', not htmlFor: App.el special-cases neither, so htmlFor lands as
        // an inert attribute and the label is not associated with the input.
        var label = App.el('label', { className: 'form-label', textContent: spec.label, 'for': 'an-' + key });
        var input;

        if (spec.type === 'bool') {
            input = App.el('label', { className: 'an-toggle' }, [
                App.el('input', {
                    type: 'checkbox', id: 'an-' + key, checked: !!current,
                    onChange: function(e) { markDirty(key, e.target.checked); }
                }),
                App.el('span', { textContent: spec.help || '' })
            ]);
            group.appendChild(label);
            group.appendChild(input);
            return group;
        }

        if (spec.type === 'enum') {
            input = App.el('select', { className: 'form-select', id: 'an-' + key,
                onChange: function(e) { markDirty(key, e.target.value); } });
            Object.keys(spec.options || {}).forEach(function(v) {
                var opt = App.el('option', { value: v, textContent: spec.options[v] });
                if (String(current) === v) opt.selected = true;
                input.appendChild(opt);
            });
        } else if (spec.type === 'secret') {
            var isSet = !!state.config[key + '_set'];
            input = App.el('input', {
                className: 'form-input', type: 'password', id: 'an-' + key,
                placeholder: isSet ? '•••••• stored — type to replace' : 'not set',
                autocomplete: 'new-password',
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        } else if (spec.type === 'list') {
            var lines = Array.isArray(current) ? current.join('\n') : '';
            input = App.el('textarea', {
                className: 'form-input an-textarea', id: 'an-' + key, rows: 6, value: lines,
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        } else if (spec.type === 'text') {
            input = App.el('textarea', {
                className: 'form-input an-textarea an-mono', id: 'an-' + key, rows: 9,
                value: current == null ? '' : String(current),
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        } else if (spec.type === 'int') {
            input = App.el('input', {
                className: 'form-input an-narrow', type: 'number', id: 'an-' + key,
                min: spec.min == null ? '' : spec.min, max: spec.max == null ? '' : spec.max,
                value: current == null ? '' : current,
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        } else {
            input = App.el('input', {
                className: 'form-input', type: 'text', id: 'an-' + key,
                value: current == null ? '' : String(current),
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        }

        group.appendChild(label);
        group.appendChild(input);

        var helpRow = [];
        if (spec.help) helpRow.push(App.el('span', { className: 'text-xs text-muted', textContent: spec.help }));

        // Pools ship with a built-in set; offer it rather than making someone
        // retype it to see what they replaced.
        if (spec.type === 'list' && state.defaults && Array.isArray(state.defaults[key])) {
            helpRow.push(App.el('button', {
                className: 'an-link', type: 'button',
                textContent: 'load the built-in ' + state.defaults[key].length + ' lines',
                onClick: function() {
                    var el = document.getElementById('an-' + key);
                    el.value = state.defaults[key].join('\n');
                    markDirty(key, el.value);
                }
            }));
        }
        if (key === 'roster_sql' && state.defaults && state.defaults.roster_sql) {
            helpRow.push(App.el('button', {
                className: 'an-link', type: 'button', textContent: 'restore the default query',
                onClick: function() {
                    var el = document.getElementById('an-' + key);
                    el.value = state.defaults.roster_sql;
                    markDirty(key, el.value);
                }
            }));
        }
        if (spec.type === 'secret' && state.config[key + '_set']) {
            helpRow.push(App.el('button', {
                className: 'an-link an-link-danger', type: 'button', textContent: 'clear it',
                onClick: async function() {
                    var ok = await App.confirm({
                        title: 'Clear this value?',
                        message: 'The bot will stop posting until it is set again.',
                        confirmLabel: 'Clear'
                    });
                    if (!ok) return;
                    state.dirty[key] = '';
                    state.dirty[key + '_clear'] = true;
                    markDirty(key, '');
                    App.toast('Will be cleared when you save.', 'warning');
                }
            }));
        }
        if (helpRow.length) group.appendChild(App.el('div', { className: 'an-help' }, helpRow));
        return group;
    }

    async function saveSettings() {
        if (!Object.keys(state.dirty).length) { App.toast('Nothing to save.', 'info'); return; }
        var btn = document.getElementById('an-save');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
        try {
            var res = await API.put('/anniversaries', state.dirty);
            state.config = res.config || state.config;
            state.dirty = {};
            buildSettings();
            App.toast('Settings saved.', 'success');
            loadRoster().catch(function() {}).then(function() { runCheck(); });
        } catch (err) {
            App.toast(errText(err), 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Save settings'; }
        }
    }

    // ----------------------------------------------------------- test actions

    async function openPreviewDialog() {
        if (Object.keys(state.dirty).length) {
            App.toast('Save first — the preview shows what is stored, not what is typed.', 'warning');
        }
        var body = App.el('div', { id: 'an-preview-body' }, [App.loading()]);
        var note = App.el('p', { className: 'text-xs text-muted', textContent: '' });
        var count = App.el('select', { className: 'form-select an-narrow' }, [1, 2, 3].map(function(n) {
            return App.el('option', { value: n, textContent: n + (n === 1 ? ' person' : ' people') });
        }));
        async function load() {
            body.innerHTML = '';
            body.appendChild(App.loading());
            try {
                var res = await API.post('/anniversaries/test',
                    { action: 'preview', people: parseInt(count.value, 10) });
                body.innerHTML = '';
                (res.messages || []).forEach(function(m) {
                    body.appendChild(App.el('div', { className: 'an-message' }, messageLines(m)));
                });
                // Say which pool is on screen: the milestone wording only shows
                // up when the sample lands on a milestone year, and without
                // this the difference reads as randomness.
                note.textContent = 'Sample years: ' + (res.years || []).map(yearLabel).join(', ')
                    + (res.milestone ? ' — a milestone year, so these use the milestone wording.' : '.')
                    + ' Four fresh examples. Nothing is posted to Slack.';
            } catch (err) {
                body.innerHTML = '';
                body.appendChild(App.el('p', { className: 'text-sm', textContent: errText(err) }));
            }
        }
        count.addEventListener('change', load);
        App.showModal('Sample wording', App.el('div', {}, [
            App.el('div', { className: 'flex gap-sm an-preview-controls' }, [
                App.el('span', { className: 'text-sm text-muted', textContent: 'Sharing a day:' }), count
            ]),
            body,
            note
        ]), App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Close',
                onClick: function() { App.hideModal(); } })
        ]));
        load();
    }

    function openDemoDialog() {
        var count = App.el('select', { className: 'form-select an-narrow' }, [1, 2, 3].map(function(n) {
            return App.el('option', { value: n, textContent: n + (n === 1 ? ' person' : ' people') });
        }));
        var post = App.el('button', { className: 'btn btn-primary', textContent: 'Post it' });

        post.addEventListener('click', async function() {
            post.disabled = true;
            post.textContent = 'Posting…';
            try {
                var res = await API.post('/anniversaries/test',
                    { action: 'demo', people: parseInt(count.value, 10) || 1 });
                App.hideModal();
                App.toast('Posted to ' + res.channel + '.', 'success');
                if (res.note) App.toast(res.note, 'warning');
            } catch (err) {
                App.toast(errText(err), 'error');
                post.disabled = false;
                post.textContent = 'Post it';
            }
        });

        App.showModal('Post a sample announcement', App.el('div', {}, [
            App.el('p', { className: 'text-sm',
                textContent: 'This goes to the configured channel for real, under placeholder names and labelled as a preview.' }),
            App.el('p', { className: 'text-sm text-muted',
                textContent: 'Nothing is recorded, so it cannot affect anybody\'s real anniversary message.' }),
            App.el('div', { className: 'flex gap-sm an-preview-controls' }, [
                App.el('span', { className: 'text-sm text-muted', textContent: 'Sharing a day:' }), count
            ])
        ]), App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel',
                onClick: function() { App.hideModal(); } }),
            post
        ]));
    }

    async function runGifTest() {
        var body = App.el('div', {}, [App.loading()]);
        App.showModal('GIF check', body, App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Close',
                onClick: function() { App.hideModal(); } })
        ]));
        try {
            var res = await API.post('/anniversaries/test', { action: 'test_gifs' });
            body.innerHTML = '';
            if (res.giphy) {
                body.appendChild(App.el('p', { className: 'text-sm',
                    textContent: res.giphy.ok
                        ? 'Giphy is working (' + res.giphy.count + ' results). The list below is only the fallback.'
                        : 'Giphy failed: ' + res.giphy.error }));
            }
            var list = App.el('div', { className: 'an-gif-list' });
            (res.results || []).forEach(function(r) {
                list.appendChild(App.el('div', { className: 'an-gif' + (r.ok ? ' an-gif-ok' : ' an-gif-dead') }, [
                    App.el('span', { className: 'an-gif-state', textContent: r.ok ? 'ok' : 'dead' }),
                    App.el('span', { className: 'an-gif-url', textContent: r.url })
                ]));
            });
            body.appendChild(list);
            body.appendChild(App.el('p', { className: 'text-sm',
                textContent: res.working + ' of ' + (res.results || []).length + ' resolve. ' + (res.note || '') }));
        } catch (err) {
            body.innerHTML = '';
            body.appendChild(App.el('p', { className: 'text-sm', textContent: errText(err) }));
        }
    }
})();
