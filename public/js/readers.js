/**
 * Reader Groups page — group games/readers into custom areas ("Redemption
 * Wall", "Front Room", "Upstairs Arcade"…) and analyze each area's traffic:
 * when it's busiest across the week (day-of-week × hour heatmap, for
 * staffing) and what play volume it averages per day and per game.
 *
 * Data comes from three endpoints (same 'analytics' gate + money scrub the
 * Analytics/Performance pages use):
 *   GET  /api/analytics/reader-groups — every group's totals, averages,
 *        busiest weekday/hour, and prior-period comparison for the window.
 *   GET  /api/analytics/reader-group  — one group's KPIs, trend, heatmap,
 *        and per-game breakdown.
 *   CRUD /api/reader-groups           — group management (groups_manage).
 *
 * Hour-of-day history accumulates in the game_hourly_stats rollup from the
 * day the feature ships; payloads carry their actual hourly coverage and the
 * page says so when a window reaches further back.
 */
(function() {
    App.registerRoute('#/readers', { render: renderReadersPage });

    var state = {
        range: 'week',
        offset: 0,
        custom: { from: '', to: '' },
        groupId: null,        // selected group (detail panel)
        metric: 'plays',      // trend chart metric
        heatMetric: 'avg',    // heatmap cells: 'avg' (per weekday) or 'total'
        includeTimePlays: true, // toggle: count time-pass plays in play figures
        data: null,           // last list payload
        detail: null,         // last detail payload
        charts: [],
        themeObserver: null,
        genList: 0,
        genDetail: 0
    };

    var RANGE_LABELS = { day: 'Day', week: 'Week', month: 'Month', year: 'Year', custom: 'Custom' };
    var DOW_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var DOW_FULL  = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    function canManage() { return App.canAccess('reader_groups_manage'); }

    async function renderReadersPage(container, params) {
        var query = (params && params._query) || {};
        if (query.range && RANGE_LABELS[query.range]) state.range = query.range;
        if (query.group) state.groupId = parseInt(query.group, 10) || null;
        state.offset = 0;
        state.detail = null;

        var headerRight = [];
        if (canManage()) {
            headerRight.push(App.el('button', {
                className: 'btn btn-primary',
                textContent: '+ New group',
                onClick: function() { openEditor(null); }
            }));
        }

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Reader Groups' }),
                App.el('p', { className: 'page-subtitle',
                    textContent: 'Group readers into areas of the venue, see when each is busiest, and compare average plays — for staffing and layout decisions.' })
            ]),
            App.el('div', { className: 'flex gap-sm' }, headerRight)
        ]));

        container.appendChild(buildControls());

        container.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header flex-between' }, [
                App.el('div', { className: 'flex-center gap-sm' }, [
                    App.el('div', { className: 'card-title', textContent: 'Areas' }),
                    App.el('span', { id: 'rg-table-meta', className: 'text-sm text-secondary' })
                ]),
                App.el('span', { className: 'text-xs text-muted', textContent: 'Click an area for its heatmap and breakdown' })
            ]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { id: 'rg-table' }, [App.loading()])
            ])
        ]));

        // Detail panel shell — filled when a group is selected.
        container.appendChild(App.el('div', { id: 'rg-detail' }));

        // Repaint charts + heatmap on theme flip (colors are read at render).
        state.themeObserver = new MutationObserver(function(records) {
            for (var i = 0; i < records.length; i++) {
                if (records[i].attributeName === 'data-theme') {
                    if (state.detail) renderDetail();
                    break;
                }
            }
        });
        state.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

        await load();

        return function cleanup() {
            destroyCharts();
            if (state.themeObserver) { state.themeObserver.disconnect(); state.themeObserver = null; }
        };
    }

    // ------------------------------------------------------------------
    // Period controls (same interaction model as the Performance page)
    // ------------------------------------------------------------------
    function buildControls() {
        var presetRow = App.el('div', { className: 'perf-range-presets', id: 'rg-presets' },
            Object.keys(RANGE_LABELS).map(function(key) {
                return App.el('button', {
                    className: 'btn btn-sm ' + (key === state.range ? 'btn-primary' : 'btn-ghost'),
                    textContent: RANGE_LABELS[key],
                    onClick: function() {
                        if (state.range === key && key !== 'custom') return;
                        state.range = key;
                        state.offset = 0;
                        refreshPresetButtons();
                        toggleCustomRow();
                        if (key !== 'custom') load();
                    }
                });
            })
        );

        var nav = App.el('div', { className: 'perf-nav', id: 'rg-nav' }, [
            App.el('button', {
                className: 'btn btn-sm btn-ghost perf-nav-btn',
                textContent: '‹',
                title: 'Previous period',
                'aria-label': 'Previous period',
                onClick: function() { if (state.range === 'custom') return; state.offset -= 1; load(); }
            }),
            App.el('div', { className: 'perf-nav-label', id: 'rg-nav-label', textContent: '…' }),
            App.el('button', {
                className: 'btn btn-sm btn-ghost perf-nav-btn',
                id: 'rg-nav-next',
                textContent: '›',
                title: 'Next period',
                'aria-label': 'Next period',
                onClick: function() { if (state.range === 'custom' || state.offset >= 0) return; state.offset += 1; load(); }
            }),
            App.el('button', {
                className: 'btn btn-sm btn-ghost',
                id: 'rg-nav-reset',
                textContent: 'Today',
                title: 'Jump to the current period',
                onClick: function() { if (state.offset === 0) return; state.offset = 0; load(); }
            })
        ]);

        var custom = App.el('div', { className: 'perf-custom', id: 'rg-custom', style: { display: state.range === 'custom' ? '' : 'none' } }, [
            App.el('label', { className: 'text-sm text-secondary', textContent: 'From' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'rg-custom-from', value: state.custom.from }),
            App.el('label', { className: 'text-sm text-secondary', textContent: 'To' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'rg-custom-to', value: state.custom.to }),
            App.el('button', {
                className: 'btn btn-sm btn-primary',
                textContent: 'Apply',
                onClick: function() {
                    var from = document.getElementById('rg-custom-from').value;
                    var to = document.getElementById('rg-custom-to').value;
                    if (!from || !to) { App.toast('Pick both a start and end date.', 'warning'); return; }
                    if (from > to) { App.toast('"From" must be on or before "To".', 'warning'); return; }
                    state.custom.from = from;
                    state.custom.to = to;
                    load();
                }
            })
        ]);

        return App.el('div', { className: 'card perf-controls' }, [
            App.el('div', { className: 'card-body perf-controls-body' }, [presetRow, nav, custom, buildTimePlaysToggle()])
        ]);
    }

    /**
     * Include/exclude time-pass plays. Flipping it re-queries everything so
     * an owner can compare the area's numbers with and without pass traffic.
     */
    function buildTimePlaysToggle() {
        var input = App.el('input', { className: 'toggle-input', type: 'checkbox', checked: state.includeTimePlays });
        input.addEventListener('change', function() {
            state.includeTimePlays = input.checked;
            load();
        });
        return App.el('label', {
            className: 'toggle-label',
            style: { marginLeft: 'auto' },
            title: 'Time-pass plays are games started on an unlimited-play time pass. Switch off to see play counts without them — tickets and payments always reflect everything.'
        }, [
            input,
            App.el('span', { className: 'toggle-switch' }),
            App.el('span', { textContent: 'Include time-pass plays' })
        ]);
    }

    function refreshPresetButtons() {
        var wrap = document.getElementById('rg-presets');
        if (!wrap) return;
        var keys = Object.keys(RANGE_LABELS);
        Array.prototype.forEach.call(wrap.children, function(btn, i) {
            btn.className = 'btn btn-sm ' + (keys[i] === state.range ? 'btn-primary' : 'btn-ghost');
        });
    }

    function toggleCustomRow() {
        var custom = document.getElementById('rg-custom');
        var nav = document.getElementById('rg-nav');
        if (custom) custom.style.display = state.range === 'custom' ? '' : 'none';
        if (nav) nav.style.display = state.range === 'custom' ? 'none' : '';
    }

    function rangeQuery() {
        var q = ['range=' + encodeURIComponent(state.range)];
        if (state.range === 'custom') {
            q.push('from=' + encodeURIComponent(state.custom.from));
            q.push('to=' + encodeURIComponent(state.custom.to));
        } else {
            q.push('offset=' + encodeURIComponent(state.offset));
        }
        if (!state.includeTimePlays) q.push('exclude_time_plays=1');
        return q.join('&');
    }

    // ------------------------------------------------------------------
    // List load + comparison table
    // ------------------------------------------------------------------
    async function load() {
        var gen = ++state.genList;
        try {
            var data = await API.get('analytics/reader-groups?' + rangeQuery());
            if (gen !== state.genList) return;
            state.data = data;
            updateNav();
            renderTable();

            // Keep the detail panel in step with the selected period; drop the
            // selection if its group was deleted elsewhere.
            var exists = state.groupId && (data.groups || []).some(function(g) { return g.id === state.groupId; });
            if (exists) {
                loadDetail();
            } else {
                state.groupId = null;
                state.detail = null;
                var panel = document.getElementById('rg-detail');
                if (panel) panel.innerHTML = '';
            }
        } catch (err) {
            if (gen !== state.genList) return;
            var t = document.getElementById('rg-table');
            if (t) {
                t.innerHTML = '';
                t.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Failed to load reader groups: ' + err.message }));
            }
        }
    }

    function updateNav() {
        var label = document.getElementById('rg-nav-label');
        if (label && state.data) label.textContent = state.data.range.label;
        var next = document.getElementById('rg-nav-next');
        if (next) next.disabled = (state.range === 'custom' || state.offset >= 0);
        var reset = document.getElementById('rg-nav-reset');
        if (reset) reset.disabled = (state.offset === 0);
    }

    function renderTable() {
        var el = document.getElementById('rg-table');
        if (!el || !state.data) return;
        var groups = state.data.groups || [];
        var money = App.canSeeMoney() && !state.data.hide_money;

        var meta = document.getElementById('rg-table-meta');
        if (meta) meta.textContent = groups.length + ' group' + (groups.length === 1 ? '' : 's');

        el.innerHTML = '';

        if (groups.length === 0) {
            var cta = canManage()
                ? App.el('button', { className: 'btn btn-primary', textContent: 'Create your first group',
                    onClick: function() { openEditor(null); } })
                : null;
            el.appendChild(App.emptyState('🗺️',
                canManage()
                    ? 'No reader groups yet. Group games by area — Redemption Wall, Front Room, Upstairs — to see when each is busiest.'
                    : 'No reader groups have been set up yet. Ask a manager to create them.',
                cta));
            return;
        }

        var headers = [
            { label: 'Area', cls: '' },
            { label: 'Games', cls: 'text-right' },
            { label: 'Plays', cls: 'text-right' },
            { label: 'Avg/day', cls: 'text-right', tip: 'Average plays per day over this period' },
            { label: 'Avg/game/day', cls: 'text-right', tip: 'Average plays per game per day — compares areas of different sizes fairly' }
        ];
        if (money) headers.push({ label: 'Reader CC', cls: 'text-right', tip: 'Credit-card payments taken at the readers' });
        headers.push({ label: 'Week rhythm', cls: '', tip: 'Plays by weekday, Sunday through Saturday — the orange bar is the busiest day' });
        headers.push({ label: 'Busiest time', cls: '', tip: 'The single busiest hour of the week for this area' });
        headers.push({ label: 'vs prev', cls: 'text-right', tip: 'Plays compared with the previous period' });
        if (canManage()) headers.push({ label: '', cls: 'text-right' });

        var thead = App.el('thead', {}, [
            App.el('tr', {}, headers.map(function(h) {
                var attrs = { className: h.cls, textContent: h.label };
                if (h.tip) attrs.title = h.tip;
                return App.el('th', attrs);
            }))
        ]);

        var maxPlays = groups.reduce(function(m, g) { return Math.max(m, g.plays || 0); }, 0);

        var tbody = App.el('tbody', {}, groups.map(function(g) {
            var cells = [
                App.el('td', {}, [
                    App.el('div', { textContent: g.name, style: { fontWeight: '500' } }),
                    g.description
                        ? App.el('div', { className: 'text-xs text-muted', textContent: g.description })
                        : App.el('div', { className: 'text-xs text-muted', textContent: g.game_count + ' game' + (g.game_count === 1 ? '' : 's') })
                ]),
                App.el('td', { className: 'text-right num-cell text-secondary', textContent: g.game_count }),
                App.el('td', { className: 'text-right num-cell' }, [
                    App.el('div', { className: 'rg-plays' }, [
                        App.el('span', { textContent: g.plays > 0 ? formatInt(g.plays) : '—',
                            style: { fontWeight: '500' } }),
                        App.el('span', { className: 'rg-plays-bar' }, [
                            App.el('span', { className: 'rg-plays-bar-fill',
                                style: { width: (maxPlays > 0 ? Math.round((g.plays / maxPlays) * 100) : 0) + '%' } })
                        ])
                    ])
                ]),
                App.el('td', { className: 'text-right num-cell text-secondary',
                    textContent: g.plays > 0 ? formatNum(g.avg_plays_per_day) : '—' }),
                App.el('td', { className: 'text-right num-cell text-secondary',
                    textContent: g.plays > 0 ? formatNum(g.avg_plays_per_game_per_day) : '—',
                    title: 'Average plays per game per day — compares areas of different sizes' })
            ];
            if (money) {
                cells.push(App.el('td', { className: 'text-right num-cell',
                    textContent: g.cash > 0 ? formatCurrency(g.cash) : '—' }));
            }
            cells.push(App.el('td', {}, [buildWeekRhythm(g)]));
            cells.push(App.el('td', {}, [
                g.busiest
                    ? App.el('span', { className: 'rg-busiest-chip',
                        textContent: '🔥 ' + DOW_SHORT[g.busiest.dow] + ' ' + hourRangeLabel(g.busiest.hour),
                        title: 'Usually around ' + formatNum(g.busiest.avg_plays) + ' plays in that hour on a ' + DOW_FULL[g.busiest.dow] + ' (' + formatInt(g.busiest.plays) + ' plays there in this whole period)' })
                    : App.el('span', { className: 'text-muted', textContent: '—' })
            ]));
            cells.push(App.el('td', { className: 'text-right' }, [delta(g.plays, g.prev_plays)]));

            if (canManage()) {
                cells.push(App.el('td', { className: 'text-right' }, [
                    App.el('div', { className: 'flex gap-sm', style: { justifyContent: 'flex-end' } }, [
                        App.el('button', {
                            className: 'btn btn-sm btn-ghost',
                            textContent: 'Edit',
                            onClick: function(e) { e.stopPropagation(); openEditor(g.id); }
                        }),
                        App.el('button', {
                            className: 'btn btn-sm btn-ghost text-danger',
                            textContent: 'Delete',
                            onClick: function(e) { e.stopPropagation(); deleteGroup(g); }
                        })
                    ])
                ]));
            }

            return App.el('tr', {
                className: 'clickable-row' + (state.groupId === g.id ? ' rg-row-selected' : ''),
                onClick: function() { selectGroup(g.id); }
            }, cells);
        }));

        var wrap = App.el('div', { className: 'table-scroll-x' }, [
            App.el('table', { className: 'data-table directory-table' }, [thead, tbody])
        ]);
        el.appendChild(wrap);

        if (state.data.exclude_time_plays) {
            var noteBits = 'Play counts exclude time-pass plays. Tickets and reader CC payments still include everything.';
            if (state.data.time_split_since && state.data.range && state.data.range.from < state.data.time_split_since) {
                noteBits += ' Day-level splits are tracked since ' + state.data.time_split_since + '; older days can’t separate pass plays.';
            }
            el.appendChild(App.el('p', { className: 'text-xs rg-exclude-note', style: { marginTop: '0.5rem' }, textContent: '⏱ ' + noteBits }));
        }
        if (state.data.hourly_full_coverage === false && state.data.hourly_covered_from) {
            el.appendChild(App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' } , textContent:
                'Busiest-time detail uses hour-by-hour history collected since ' + state.data.hourly_covered_from +
                '; totals and averages cover the whole period.' }));
        }
    }

    /**
     * Seven tiny weekday bars (Sun→Sat) showing where an area's week peaks
     * at a glance. All bars share the accent hue; only the busiest day wears
     * the warm heat color, and the adjacent "Busiest time" chip names that
     * day in words, so the highlight is never color-alone.
     */
    function buildWeekRhythm(g) {
        var dowPlays = g.dow_plays || [];
        var counts = (state.data && state.data.dow_counts) || [];
        var max = 0;
        for (var i = 0; i < 7; i++) max = Math.max(max, Number(dowPlays[i]) || 0);
        if (!(max > 0)) return App.el('span', { className: 'text-muted', textContent: '—' });
        var theme = readThemeColors();
        var peak = -1;
        for (i = 0; i < 7; i++) if ((Number(dowPlays[i]) || 0) === max) { peak = i; break; }

        var wrap = App.el('div', { className: 'rg-rhythm', role: 'img',
            'aria-label': 'Plays by weekday, Sunday through Saturday' });
        for (var d = 0; d < 7; d++) {
            var v = Number(dowPlays[d]) || 0;
            var hpct = v > 0 ? Math.max(14, Math.round(v / max * 100)) : 0;
            var n = Number(counts[d]) || 0;
            var tip = v > 0
                ? (DOW_FULL[d] + 's: ' + (n > 0 ? 'usually around ' + formatNum(v / n) + ' plays (' + formatInt(v) + ' total)' : formatInt(v) + ' plays'))
                : DOW_FULL[d] + 's: no plays in this period';
            var bar = App.el('span', { className: 'rg-rhythm-bar', title: tip });
            if (v > 0) {
                bar.appendChild(App.el('span', {
                    className: 'rg-rhythm-fill',
                    style: {
                        height: hpct + '%',
                        background: d === peak ? theme.heatSolid : 'rgba(' + theme.accentRgb + ',0.6)'
                    }
                }));
            }
            wrap.appendChild(bar);
        }
        return wrap;
    }

    function selectGroup(id) {
        state.groupId = (state.groupId === id) ? null : id;
        renderTable(); // refresh row highlight
        var panel = document.getElementById('rg-detail');
        if (!state.groupId) {
            state.detail = null;
            if (panel) panel.innerHTML = '';
            return;
        }
        loadDetail();
        if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ------------------------------------------------------------------
    // Detail panel: KPIs + heatmap + trend + per-game breakdown
    // ------------------------------------------------------------------
    async function loadDetail() {
        var panel = document.getElementById('rg-detail');
        if (!panel || !state.groupId) return;
        var gen = ++state.genDetail;
        panel.innerHTML = '';
        panel.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-body' }, [App.loading()])
        ]));
        try {
            var d = await API.get('analytics/reader-group?id=' + encodeURIComponent(state.groupId) + '&' + rangeQuery());
            if (gen !== state.genDetail || state.groupId !== d.group.id) return;
            state.detail = d;
            renderDetail();
        } catch (err) {
            if (gen !== state.genDetail) return;
            panel.innerHTML = '';
            panel.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-sm text-secondary', textContent: 'Failed to load group detail: ' + err.message })
                ])
            ]));
        }
    }

    function renderDetail() {
        var panel = document.getElementById('rg-detail');
        var d = state.detail;
        if (!panel || !d) return;
        destroyCharts();
        panel.innerHTML = '';

        var money = App.canSeeMoney() && !d.hide_money;
        var t = d.totals, p = d.previous_totals;

        // ---- Header + KPIs ----
        var headActions = [];
        if (canManage()) {
            headActions.push(App.el('button', {
                className: 'btn btn-sm btn-ghost', textContent: 'Edit group',
                onClick: function() { openEditor(d.group.id); }
            }));
        }
        headActions.push(App.el('button', {
            className: 'btn btn-sm btn-ghost', textContent: 'Close',
            'aria-label': 'Close detail panel',
            onClick: function() { selectGroup(d.group.id); } // toggles off
        }));

        // Header card: group identity, then the plain-language insight cards
        // (the headline for a non-technical reader), then the KPI grid.
        var insights = buildInsights(d);
        var kpiBody = [];
        if (insights) kpiBody.push(insights);
        kpiBody.push(buildKpiGrid(t, p, money));
        var kpiCard = App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header flex-between' }, [
                App.el('div', {}, [
                    App.el('div', { className: 'card-title', textContent: d.group.name }),
                    App.el('p', { className: 'text-sm text-secondary', textContent:
                        (d.group.description ? d.group.description + '  •  ' : '') +
                        d.group.game_count + ' game' + (d.group.game_count === 1 ? '' : 's') + '  •  ' + d.range.label })
                ]),
                App.el('div', { className: 'flex gap-sm' }, headActions)
            ]),
            App.el('div', { className: 'card-body' }, kpiBody)
        ]);
        panel.appendChild(kpiCard);

        // ---- Heatmap ----
        panel.appendChild(buildHeatmapCard(d));

        // ---- Trend ----
        panel.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header flex-between' }, [
                App.el('div', { className: 'flex-center gap-sm' }, [
                    App.el('div', { className: 'card-title', textContent: 'Trend' }),
                    App.el('span', { className: 'text-sm text-secondary', textContent: granularityNote(d.series.granularity) })
                ]),
                buildMetricToggle()
            ]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'perf-chart-holder' }, [App.el('canvas', { id: 'rg-trend-canvas' })])
            ])
        ]));
        renderTrendChart();

        // ---- Per-game breakdown ----
        panel.appendChild(buildGamesCard(d, money));
    }

    function buildKpiGrid(t, p, money) {
        var cards = [
            ['Plays', formatInt(t.plays), delta(t.plays, p.plays)],
            ['Avg plays / day', formatNum(t.avg_plays_per_day), delta(t.avg_plays_per_day, p.avg_plays_per_day)],
            ['Avg plays / game / day', formatNum(t.avg_plays_per_game_per_day), delta(t.avg_plays_per_game_per_day, p.avg_plays_per_game_per_day)],
            ['Tickets', formatInt(Math.round(t.tickets)), delta(t.tickets, p.tickets)]
        ];
        if (money) cards.push(['Reader CC payments', formatCurrency(t.cash), delta(t.cash, p.cash)]);
        var excluding = state.detail && state.detail.exclude_time_plays;
        cards.push([excluding ? 'Time-pass plays (excluded)' : 'Time-pass plays', formatInt(t.time_plays), null]);
        cards.push(['Games with plays', formatInt(t.active_games), null]);

        return App.el('div', { className: 'perf-kpi-grid' }, cards.map(function(k) {
            var ch = [
                App.el('div', { className: 'perf-kpi-label', textContent: k[0] }),
                App.el('div', { className: 'perf-kpi-value', textContent: k[1] })
            ];
            if (k[2]) ch.push(k[2]);
            return App.el('div', { className: 'perf-kpi' }, ch);
        }));
    }

    // ------------------------------------------------------------------
    // Plain-language insight cards — the headline answers, up front:
    // when is this area busiest, which day carries it, which day is dead.
    // Derived from the heatmap's per-occurrence averages so a long window
    // still reads as "a typical Saturday", never a lifetime total.
    // ------------------------------------------------------------------
    function buildInsights(d) {
        var heat = d.heatmap;
        if (!heat || !(Number(heat.max_total) > 0)) return null;

        var typical = [];
        for (var dw = 0; dw < 7; dw++) {
            var s = 0;
            for (var h = 0; h < 24; h++) s += Number(heat.avg[dw][h]) || 0;
            typical.push(s);
        }
        var busiestDay = -1, quietDay = -1;
        for (dw = 0; dw < 7; dw++) {
            if (typical[dw] <= 0) continue;
            if (busiestDay < 0 || typical[dw] > typical[busiestDay]) busiestDay = dw;
            if (quietDay < 0 || typical[dw] < typical[quietDay]) quietDay = dw;
        }

        var cards = [];
        var top = (d.busiest && d.busiest[0]) || null;
        if (top) {
            cards.push(insightCard('🔥', 'insight-heat', 'Busiest time',
                DOW_FULL[top.dow] + 's, ' + hourRangeLabel(top.hour),
                'usually around ' + formatNum(top.avg_plays) + ' plays in that hour'));
        }
        if (busiestDay >= 0) {
            cards.push(insightCard('📅', 'insight-accent', 'Busiest day',
                DOW_FULL[busiestDay] + 's',
                'usually around ' + formatInt(Math.round(typical[busiestDay])) + ' plays over the day'));
        }
        if (quietDay >= 0 && quietDay !== busiestDay) {
            cards.push(insightCard('🌙', 'insight-quiet', 'Quietest day',
                DOW_FULL[quietDay] + 's',
                'usually around ' + formatInt(Math.round(typical[quietDay])) + ' plays over the day'));
        }
        if (cards.length === 0) return null;
        return App.el('div', { className: 'insight-row' }, cards);
    }

    function insightCard(emoji, cls, label, value, sub) {
        return App.el('div', { className: 'insight-card ' + cls }, [
            App.el('div', { className: 'insight-icon', 'aria-hidden': 'true', textContent: emoji }),
            App.el('div', { className: 'insight-body' }, [
                App.el('div', { className: 'insight-label', textContent: label }),
                App.el('div', { className: 'insight-value', textContent: value }),
                App.el('div', { className: 'insight-sub', textContent: sub })
            ])
        ]);
    }

    // ------------------------------------------------------------------
    // Heatmap
    // ------------------------------------------------------------------
    function buildHeatmapCard(d) {
        var toggle = App.el('div', { className: 'perf-metric-toggle' },
            [['avg', 'Typical week'], ['total', 'Period totals']].map(function(m) {
                return App.el('button', {
                    className: 'btn btn-sm ' + (m[0] === state.heatMetric ? 'btn-secondary' : 'btn-ghost'),
                    textContent: m[1],
                    title: m[0] === 'avg'
                        ? 'What a normal week looks like: average plays for each weekday hour — the staffing view'
                        : 'Raw totals: every play in this period added up per weekday hour',
                    onClick: function() {
                        if (state.heatMetric === m[0]) return;
                        state.heatMetric = m[0];
                        renderDetail();
                    }
                });
            })
        );

        var body = [];
        var heat = d.heatmap;
        var grid = buildHeatmapGrid(d);
        if (grid) {
            var cap = state.heatMetric === 'avg'
                ? 'Each square is one hour of the week. Deeper orange = busier on a typical week. ★ marks the single busiest hour. Hover any square for the exact numbers.'
                : 'Each square is one hour of the week. Deeper orange = more plays in total over this period. ★ marks the single busiest hour. Hover any square for the exact numbers.';
            if (d.exclude_time_plays) cap += ' Time-pass plays are excluded from these counts.';
            body.push(App.el('p', { className: 'rg-heat-caption', textContent: cap }));
            var layout = [App.el('div', { className: 'rg-heat-main' }, [grid.legendTop, grid.el])];
            var rankList = buildBusiestList(d);
            if (rankList) layout.push(rankList);
            body.push(App.el('div', { className: 'rg-heat-layout' }, layout));
        } else {
            body.push(App.emptyState('🕐', 'No hour-by-hour play data for this period yet.'));
        }

        if (heat && heat.full_coverage === false && heat.covered_from) {
            body.push(App.el('p', { className: 'text-xs text-muted', textContent:
                'Heatmap reflects hour-by-hour history from ' + heat.covered_from + ' to ' + heat.covered_to +
                ' (hourly detail is collected going forward; older days only exist as daily totals).' }));
        }

        return App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header flex-between' }, [
                App.el('div', { className: 'flex-center gap-sm' }, [
                    App.el('div', { className: 'card-title', textContent: 'When is it busiest?' }),
                    App.el('span', { className: 'text-sm text-secondary',
                        textContent: state.heatMetric === 'avg' ? 'a typical week, hour by hour' : 'this period’s totals, hour by hour' })
                ]),
                toggle
            ]),
            App.el('div', { className: 'card-body' }, body)
        ]);
    }

    /**
     * Ranked "top 5 busiest times" list beside the heatmap — the same facts
     * as the strongest cells, but readable without decoding any colors.
     * All bars share one hue (length carries the ranking); the #1 row gets
     * an explicit Peak tag so nothing relies on color alone.
     */
    function buildBusiestList(d) {
        var items = d.busiest || [];
        if (items.length === 0) return null;
        var theme = readThemeColors();
        var maxAvg = 0;
        items.forEach(function(b) { maxAvg = Math.max(maxAvg, Number(b.avg_plays) || 0); });

        var rows = items.map(function(b, i) {
            var pct = maxAvg > 0 ? Math.max(6, Math.round((Number(b.avg_plays) || 0) / maxAvg * 100)) : 6;
            var labelBits = [App.el('span', { textContent: DOW_FULL[b.dow] + 's, ' + hourRangeLabel(b.hour) })];
            if (i === 0) labelBits.push(App.el('span', { className: 'rg-rank-peak-tag', textContent: '🔥 Peak' }));
            return App.el('div', {
                className: 'rg-rank-row',
                title: 'Usually around ' + formatNum(b.avg_plays) + ' plays in that hour (' + formatInt(b.plays) + ' plays there in this whole period)'
            }, [
                App.el('span', { className: 'rg-rank-num' + (i === 0 ? ' rg-rank-num-top' : ''), textContent: (i + 1) }),
                App.el('div', { className: 'rg-rank-main' }, [
                    App.el('div', { className: 'rg-rank-label' }, labelBits),
                    App.el('div', { className: 'rg-rank-bar' }, [
                        App.el('span', { className: 'rg-rank-bar-fill',
                            style: { width: pct + '%', background: 'rgba(' + theme.heatRgb + ',0.9)' } })
                    ])
                ]),
                App.el('span', { className: 'rg-rank-val', textContent: '~' + formatNum(b.avg_plays) + '/hr' })
            ]);
        });

        return App.el('div', { className: 'rg-rank-list' },
            [App.el('div', { className: 'rg-rank-title', textContent: 'Top 5 busiest times' }),
             App.el('div', { className: 'rg-rank-sub', textContent: 'on a typical week' })].concat(rows));
    }

    /**
     * 7×24 grid rendered as a table-like CSS grid. Columns are trimmed to the
     * venue's live hours (first to last hour with any plays, padded by one)
     * so overnight dead air doesn't crush the interesting cells.
     */
    function buildHeatmapGrid(d) {
        var heat = d.heatmap;
        if (!heat) return null;
        var matrix = state.heatMetric === 'avg' ? heat.avg : heat.totals;
        var maxVal = state.heatMetric === 'avg' ? Number(heat.max_avg) : Number(heat.max_total);
        if (!matrix || !(maxVal > 0)) return null;

        // Trim to active hours.
        var minH = 24, maxH = -1;
        for (var dow = 0; dow < 7; dow++) {
            for (var h = 0; h < 24; h++) {
                if (Number(heat.totals[dow][h]) > 0) {
                    if (h < minH) minH = h;
                    if (h > maxH) maxH = h;
                }
            }
        }
        if (maxH < 0) return null;
        minH = Math.max(0, minH - 1);
        maxH = Math.min(23, maxH + 1);
        var hours = [];
        for (var hh = minH; hh <= maxH; hh++) hours.push(hh);

        var theme = readThemeColors();

        // Single busiest cell of the current view gets the ★ marker; per-row
        // day summaries give each weekday a number without hovering.
        var peakD = -1, peakH = -1, peakVal = 0;
        var rowSums = [];
        for (var dws = 0; dws < 7; dws++) {
            var rs = 0;
            for (var hs = 0; hs < 24; hs++) {
                var vv = Number(matrix[dws][hs]) || 0;
                rs += vv;
                if (vv > peakVal) { peakVal = vv; peakD = dws; peakH = hs; }
            }
            rowSums.push(rs);
        }

        var gridEl = App.el('div', {
            className: 'rg-heatmap',
            style: { gridTemplateColumns: 'auto repeat(' + hours.length + ', minmax(0, 1fr)) auto' },
            role: 'img',
            'aria-label': 'Heatmap of plays by weekday and hour'
        });

        // Header row: hour labels (sparse when narrow — every other label),
        // then the day-summary column header.
        gridEl.appendChild(App.el('div', { className: 'rg-heat-corner' }));
        hours.forEach(function(h, idx) {
            gridEl.appendChild(App.el('div', {
                className: 'rg-heat-hour',
                textContent: (hours.length > 14 && idx % 2 === 1) ? '' : hourLabel(h)
            }));
        });
        gridEl.appendChild(App.el('div', { className: 'rg-heat-hour rg-heat-rowsum-head',
            textContent: state.heatMetric === 'avg' ? 'typical day' : 'day total' }));

        for (var dw = 0; dw < 7; dw++) {
            gridEl.appendChild(App.el('div', { className: 'rg-heat-dow', textContent: DOW_SHORT[dw] }));
            for (var i = 0; i < hours.length; i++) {
                var h2 = hours[i];
                var val = Number(matrix[dw][h2]) || 0;
                var totalVal = Number(heat.totals[dw][h2]) || 0;
                var intensity = maxVal > 0 ? (val / maxVal) : 0;
                var cellStyle = {};
                if (val > 0) {
                    // sqrt curve lifts mid-range values so quieter-but-real
                    // hours stay visible next to the peak.
                    cellStyle.background = heatColor(theme, heatCurve(intensity));
                }
                var n = Number((heat.dow_counts || [])[dw]) || 0;
                var tip = DOW_FULL[dw] + ', ' + hourRangeLabel(h2) + ': ';
                if (state.heatMetric === 'avg') {
                    tip += 'usually around ' + formatNum(val) + ' plays' +
                        ' (' + formatInt(totalVal) + ' total across ' + n + ' ' + DOW_FULL[dw] + (n === 1 ? '' : 's') + ')';
                } else {
                    tip += formatInt(val) + ' plays in total' + (n > 0 ? ' across ' + n + ' ' + DOW_FULL[dw] + (n === 1 ? '' : 's') : '');
                }
                var isPeak = (dw === peakD && h2 === peakH && val > 0);
                var cell = App.el('div', {
                    className: 'rg-heat-cell' + (val > 0 ? '' : ' rg-heat-cell-empty') + (isPeak ? ' rg-heat-cell-peak' : ''),
                    style: cellStyle,
                    title: isPeak ? tip + ' — the busiest hour of the week' : tip
                });
                if (isPeak) cell.appendChild(App.el('span', { className: 'rg-heat-star', 'aria-hidden': 'true', textContent: '★' }));
                gridEl.appendChild(cell);
            }
            gridEl.appendChild(App.el('div', {
                className: 'rg-heat-rowsum',
                textContent: rowSums[dw] > 0
                    ? (state.heatMetric === 'avg' ? '~' + formatInt(Math.round(rowSums[dw])) : formatInt(rowSums[dw]))
                    : '—',
                title: rowSums[dw] > 0
                    ? (state.heatMetric === 'avg'
                        ? 'Usually around ' + formatInt(Math.round(rowSums[dw])) + ' plays over a typical ' + DOW_FULL[dw]
                        : formatInt(rowSums[dw]) + ' plays on ' + DOW_FULL[dw] + 's in this period')
                    : 'No plays on ' + DOW_FULL[dw] + 's in this period'
            }));
        }

        // Legend: low → high gradient swatches.
        var legendTop = App.el('div', { className: 'rg-heat-legend' }, [
            App.el('span', { className: 'text-xs text-muted', textContent: 'Quiet' }),
            App.el('span', { className: 'rg-heat-legend-swatches' }, [0.15, 0.35, 0.6, 1].map(function(s) {
                return App.el('span', { className: 'rg-heat-legend-swatch', style: { background: heatColor(theme, heatCurve(s)) } });
            })),
            App.el('span', { className: 'text-xs text-muted', textContent: 'Busy — peak is ' +
                (state.heatMetric === 'avg' ? '~' + formatNum(maxVal) + ' plays/hr' : formatInt(maxVal) + ' plays') })
        ]);

        return { el: gridEl, legendTop: legendTop };
    }

    /**
     * Sequential heat color: ONE warm hue, alpha carries the magnitude, so
     * the ramp runs light→dark against whichever surface the theme paints
     * (the anchor flips automatically in dark mode). Peak steps were
     * contrast-checked on both surfaces (≥5:1).
     */
    function heatColor(theme, intensity) {
        var a = 0.1 + 0.85 * Math.max(0, Math.min(1, intensity));
        return 'rgba(' + theme.heatRgb + ',' + a.toFixed(3) + ')';
    }

    /**
     * Perceptual lift for cell intensity: a 0.7 power keeps quiet-but-real
     * hours visible without flattening the mid-range the way sqrt does.
     */
    function heatCurve(x) {
        return Math.pow(Math.max(0, Math.min(1, x)), 0.7);
    }

    // ------------------------------------------------------------------
    // Trend chart
    // ------------------------------------------------------------------
    function buildMetricToggle() {
        var metrics = [['plays', 'Plays'], ['tickets', 'Tickets']];
        if (App.canSeeMoney()) metrics.push(['cash', 'Reader CC']);
        return App.el('div', { className: 'perf-metric-toggle', id: 'rg-metric-toggle' },
            metrics.map(function(m) {
                return App.el('button', {
                    className: 'btn btn-sm ' + (m[0] === state.metric ? 'btn-secondary' : 'btn-ghost'),
                    textContent: m[1],
                    onClick: function() {
                        state.metric = m[0];
                        var wrap = document.getElementById('rg-metric-toggle');
                        if (wrap) Array.prototype.forEach.call(wrap.children, function(btn) {
                            btn.className = 'btn btn-sm ' + (btn.textContent === m[1] ? 'btn-secondary' : 'btn-ghost');
                        });
                        renderTrendChart();
                    }
                });
            })
        );
    }

    async function renderTrendChart() {
        if (!state.detail) return;
        var ok = await ensureChart();
        var canvas = document.getElementById('rg-trend-canvas');
        if (!ok || !canvas) return;
        destroyCharts();

        var points = (state.detail.series && state.detail.series.points) || [];
        var labels = points.map(function(pt) { return pt.label; });
        var metric = state.metric;
        if (metric === 'cash' && !App.canSeeMoney()) metric = 'plays';
        var values = points.map(function(pt) {
            return metric === 'cash' ? Number(pt.cash) : (metric === 'tickets' ? Number(pt.tickets) : Number(pt.plays));
        });

        var theme = readThemeColors();
        var barColor = metric === 'plays' ? theme.accent : (metric === 'cash' ? theme.success : theme.tickets);

        var chart = new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: metric === 'plays' ? 'Plays' : (metric === 'cash' ? 'Reader CC' : 'Tickets'),
                    data: values,
                    backgroundColor: barColor,
                    borderRadius: 4,
                    maxBarThickness: 46
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 300 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: theme.bgCard, titleColor: theme.text,
                        bodyColor: theme.textSecondary, borderColor: theme.border, borderWidth: 1, padding: 8,
                        callbacks: {
                            label: function(ctx) {
                                var v = ctx.parsed.y;
                                if (metric === 'cash') return ' ' + formatCurrency(v);
                                return ' ' + formatInt(v) + ' ' + (metric === 'plays' ? 'plays' : 'tickets');
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: theme.textSecondary, autoSkip: true, maxRotation: 0 }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: theme.textSecondary, precision: 0 }, grid: { color: theme.gridLine } }
                }
            }
        });
        state.charts.push(chart);
    }

    function destroyCharts() {
        state.charts.forEach(function(c) { try { c.destroy(); } catch (e) {} });
        state.charts = [];
    }

    // ------------------------------------------------------------------
    // Per-game breakdown table
    // ------------------------------------------------------------------
    function buildGamesCard(d, money) {
        var games = d.games || [];
        var bodyChildren = [];
        if (games.length === 0) {
            bodyChildren.push(App.emptyState('🎮', 'This group has no games yet.' +
                (canManage() ? ' Use "Edit group" to add some.' : '')));
        } else {
            var headers = [
                { label: '#', cls: '' },
                { label: 'Game', cls: '' },
                { label: 'Status', cls: '' },
                { label: 'Plays', cls: 'text-right' },
                { label: 'Avg/day', cls: 'text-right' },
                { label: '% of area', cls: 'text-right' },
                { label: 'Tickets', cls: 'text-right' }
            ];
            if (money) headers.push({ label: 'Reader CC', cls: 'text-right' });
            headers.push({ label: 'vs prev', cls: 'text-right' });

            var thead = App.el('thead', {}, [
                App.el('tr', {}, headers.map(function(h) {
                    return App.el('th', { className: h.cls, textContent: h.label });
                }))
            ]);
            var tbody = App.el('tbody', {}, games.map(function(g, i) {
                var cells = [
                    App.el('td', { className: 'text-muted num-cell', textContent: i + 1 }),
                    App.el('td', {}, [
                        App.el('div', { textContent: g.game_name || ('Game ' + g.game_id), style: { fontWeight: '500' } }),
                        App.el('div', { className: 'text-xs text-muted font-mono', textContent: g.game_id })
                    ]),
                    App.el('td', {}, [g.status ? App.statusBadge(g.status) : App.el('span', { className: 'text-muted', textContent: '—' })]),
                    App.el('td', { className: 'text-right num-cell', textContent: g.plays > 0 ? formatInt(g.plays) : '—' }),
                    App.el('td', { className: 'text-right num-cell text-secondary', textContent: g.plays > 0 ? formatNum(g.avg_plays_per_day) : '—' })
                ];
                if (g.share_pct === null || g.share_pct === undefined) {
                    cells.push(App.el('td', { className: 'text-right num-cell text-muted', textContent: '—' }));
                } else {
                    cells.push(App.el('td', { className: 'text-right num-cell' }, [
                        App.el('div', { className: 'perf-share' }, [
                            App.el('span', { className: 'perf-share-val', textContent: g.share_pct + '%' }),
                            App.el('span', { className: 'perf-share-bar' }, [
                                App.el('span', { className: 'perf-share-bar-fill', style: { width: Math.min(100, g.share_pct) + '%' } })
                            ])
                        ])
                    ]));
                }
                cells.push(App.el('td', { className: 'text-right num-cell' }, [
                    App.el('span', { className: g.tickets > 0 ? 'tickets-amount' : 'text-muted',
                        textContent: g.tickets > 0 ? formatInt(Math.round(g.tickets)) : '—' })
                ]));
                if (money) cells.push(App.el('td', { className: 'text-right num-cell', textContent: g.cash > 0 ? formatCurrency(g.cash) : '—' }));
                cells.push(App.el('td', { className: 'text-right' }, [delta(g.plays, g.prev_plays)]));

                return App.el('tr', {
                    className: 'clickable-row',
                    title: 'Open in Performance',
                    onClick: function() { window.location.hash = '#/performance?game=' + encodeURIComponent(g.game_id); }
                }, cells);
            }));
            bodyChildren.push(App.el('div', { className: 'table-scroll-x' }, [
                App.el('table', { className: 'data-table directory-table' }, [thead, tbody])
            ]));
        }

        return App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header flex-between' }, [
                App.el('div', { className: 'card-title', textContent: 'Games in this area' }),
                App.el('span', { className: 'text-xs text-muted', textContent: 'Click a game for its full performance history' })
            ]),
            App.el('div', { className: 'card-body' }, bodyChildren)
        ]);
    }

    // ------------------------------------------------------------------
    // Group editor (create / edit) + delete
    // ------------------------------------------------------------------
    async function openEditor(groupId) {
        var body = App.el('div', {}, [App.loading()]);
        App.showModal(groupId ? 'Edit reader group' : 'New reader group', body,
            App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } })
            ]));

        var group = { name: '', description: '', games: [] };
        var catalog = [];
        try {
            var results = await Promise.all([
                API.get('games'),
                groupId ? API.get('reader-groups/' + groupId) : Promise.resolve(null)
            ]);
            catalog = (results[0] && results[0].games) || [];
            if (results[1]) group = results[1];
        } catch (err) {
            body.innerHTML = '';
            body.appendChild(App.el('p', { className: 'text-secondary', textContent: 'Failed to load: ' + err.message }));
            return;
        }

        var selected = {};
        (group.games || []).forEach(function(g) { selected[g.game_id] = true; });
        // Games no longer in the CenterEdge cache but still in the group
        // (renamed/retired readers) must stay selectable so editing doesn't
        // silently drop them.
        var known = {};
        catalog.forEach(function(g) { known[g.game_id] = true; });
        (group.games || []).forEach(function(g) {
            if (!known[g.game_id]) {
                catalog.push({ game_id: g.game_id, game_name: (g.game_name || g.game_id) + ' (no longer in game list)' });
            }
        });

        var nameInput = App.el('input', { className: 'form-input', type: 'text', value: group.name || '',
            placeholder: 'e.g. Redemption Wall', maxLength: 100 });
        var descInput = App.el('input', { className: 'form-input', type: 'text', value: group.description || '',
            placeholder: 'Optional — what part of the venue is this?', maxLength: 500 });

        var countEl = App.el('span', { className: 'text-sm text-secondary' });
        function refreshCount() {
            var n = Object.keys(selected).filter(function(k) { return selected[k]; }).length;
            countEl.textContent = n + ' game' + (n === 1 ? '' : 's') + ' selected';
        }

        var listEl = App.el('div', { className: 'rg-picker-list' });
        var searchTerm = '';
        function renderPicker() {
            listEl.innerHTML = '';
            var shown = catalog.filter(function(g) {
                return App.matchesSearch(g, searchTerm, ['game_name', 'game_id']);
            });
            if (shown.length === 0) {
                listEl.appendChild(App.el('p', { className: 'text-sm text-muted', style: { padding: '0.5rem' },
                    textContent: 'No games match.' }));
                return;
            }
            shown.forEach(function(g) {
                var cb = App.el('input', { type: 'checkbox', className: 'rg-picker-check' });
                cb.checked = !!selected[g.game_id];
                cb.addEventListener('change', function() {
                    selected[g.game_id] = cb.checked;
                    refreshCount();
                });
                listEl.appendChild(App.el('label', { className: 'rg-picker-item' }, [
                    cb,
                    App.el('span', { className: 'rg-picker-name', textContent: g.game_name || g.game_id }),
                    App.el('span', { className: 'text-xs text-muted font-mono', textContent: g.game_id })
                ]));
            });
        }
        var search = App.buildSearchInput({
            placeholder: 'Filter games…',
            ariaLabel: 'Filter games',
            debounceMs: 120,
            onSearch: function(term) { searchTerm = term; renderPicker(); }
        });

        var selectShown = App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Select shown', onClick: function() {
            catalog.filter(function(g) { return App.matchesSearch(g, searchTerm, ['game_name', 'game_id']); })
                .forEach(function(g) { selected[g.game_id] = true; });
            renderPicker(); refreshCount();
        } });
        var clearAll = App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Clear all', onClick: function() {
            selected = {};
            renderPicker(); refreshCount();
        } });

        body.innerHTML = '';
        body.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Name' }), nameInput
        ]));
        body.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Description' }), descInput
        ]));
        body.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('div', { className: 'flex-between', style: { marginBottom: '0.4rem' } }, [
                App.el('label', { className: 'form-label', style: { marginBottom: '0' }, textContent: 'Games / readers in this area' }),
                countEl
            ]),
            App.el('div', { className: 'flex gap-sm', style: { marginBottom: '0.4rem' } }, [search, selectShown, clearAll]),
            listEl
        ]));
        search.style.flex = '1';
        renderPicker();
        refreshCount();

        var saveBtn = App.el('button', {
            className: 'btn btn-primary',
            textContent: groupId ? 'Save changes' : 'Create group',
            onClick: async function() {
                var name = nameInput.value.trim();
                if (!name) { App.toast('Give the group a name.', 'warning'); nameInput.focus(); return; }
                var gameIds = Object.keys(selected).filter(function(k) { return selected[k]; });
                saveBtn.disabled = true;
                try {
                    var payload = { name: name, description: descInput.value.trim(), game_ids: gameIds };
                    if (groupId) {
                        await API.put('reader-groups/' + groupId, payload);
                        App.toast('Reader group updated.', 'success');
                    } else {
                        var created = await API.post('reader-groups', payload);
                        state.groupId = created && created.id ? created.id : state.groupId;
                        App.toast('Reader group created.', 'success');
                    }
                    App.hideModal();
                    load();
                } catch (err) {
                    saveBtn.disabled = false;
                    App.toast('Save failed: ' + err.message, 'error');
                }
            }
        });

        // Rebuild the footer with the save button now that the form is live.
        App.showModal(groupId ? 'Edit reader group' : 'New reader group', body,
            App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
                saveBtn
            ]));
        requestAnimationFrame(function() { nameInput.focus(); });
    }

    async function deleteGroup(g) {
        var ok = await App.confirm({
            title: 'Delete reader group',
            message: 'Delete "' + g.name + '"? This only removes the analytics grouping — games and their history are untouched.',
            confirmLabel: 'Delete'
        });
        if (!ok) return;
        try {
            await API.del('reader-groups/' + g.id);
            if (state.groupId === g.id) state.groupId = null;
            App.toast('Reader group deleted.', 'success');
            load();
        } catch (err) {
            App.toast('Delete failed: ' + err.message, 'error');
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    function granularityNote(g) {
        if (g === 'hour') return 'by hour';
        if (g === 'month') return 'by month';
        return 'by day';
    }

    function hourLabel(h) {
        var suffix = h < 12 ? 'a' : 'p';
        var hour12 = h % 12;
        if (hour12 === 0) hour12 = 12;
        return hour12 + suffix;
    }

    function hour12(h) { var v = h % 12; return v === 0 ? 12 : v; }
    function meridiem(h) { return h < 12 ? 'AM' : 'PM'; }

    /** Owner-friendly one-hour window, e.g. "2–3 PM" or "11 AM–12 PM". */
    function hourRangeLabel(h) {
        var e = (h + 1) % 24;
        if (meridiem(h) === meridiem(e)) return hour12(h) + '–' + hour12(e) + ' ' + meridiem(h);
        return hour12(h) + ' ' + meridiem(h) + '–' + hour12(e) + ' ' + meridiem(e);
    }

    function delta(cur, prev) {
        cur = Number(cur) || 0; prev = Number(prev) || 0;
        var cls, text;
        if (prev === 0 && cur === 0) { cls = 'flat'; text = 'no change'; }
        else if (prev === 0) { cls = 'up'; text = 'new'; }
        else {
            var pct = ((cur - prev) / prev) * 100;
            var rounded = Math.round(pct * 10) / 10;
            if (rounded > 0) { cls = 'up'; text = '▲ ' + rounded.toFixed(1) + '%'; }
            else if (rounded < 0) { cls = 'down'; text = '▼ ' + Math.abs(rounded).toFixed(1) + '%'; }
            else { cls = 'flat'; text = '0%'; }
        }
        return App.el('div', { className: 'perf-kpi-delta perf-delta-' + cls, textContent: text, title: 'vs previous period' });
    }

    function ensureChart() {
        return new Promise(function(resolve) {
            if (window.Chart) return resolve(true);
            var tries = 0;
            var timer = setInterval(function() {
                if (window.Chart) { clearInterval(timer); resolve(true); }
                else if (++tries > 50) { clearInterval(timer); resolve(false); }
            }, 100);
        });
    }

    function readThemeColors() {
        var styles = getComputedStyle(document.documentElement);
        var get = function(name, fallback) { var v = styles.getPropertyValue(name); return (v && v.trim()) || fallback; };
        var isLight = document.documentElement.getAttribute('data-theme') === 'light';
        var accent = get('--accent', '#5b8def');
        return {
            accent:        accent,
            accentRgb:     hexToRgb(accent) || '91,141,239',
            // Warm single-hue ramp for the heatmap / busiest bars — deeper
            // orange in light mode, brighter in dark, both ≥5:1 at peak.
            heatRgb:       get('--viz-heat-rgb', isLight ? '194,65,12' : '249,115,22'),
            heatSolid:     'rgb(' + (get('--viz-heat-rgb', isLight ? '194,65,12' : '249,115,22')) + ')',
            success:       get('--success', '#3dd68c'),
            tickets:       get('--tickets', '#f5b942'),
            text:          get('--text-primary', '#e1e4ed'),
            textSecondary: get('--text-secondary', '#8891a8'),
            border:        get('--border', '#1e2638'),
            bgCard:        get('--bg-card', '#151a28'),
            gridLine:      isLight ? 'rgba(20, 35, 66, 0.08)' : 'rgba(255, 255, 255, 0.06)'
        };
    }

    function hexToRgb(hex) {
        var m = /^#?([0-9a-f]{6})$/i.exec((hex || '').trim());
        if (!m) return null;
        var n = parseInt(m[1], 16);
        return ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255);
    }

    function formatInt(n) { n = Number(n) || 0; return Math.round(n).toLocaleString(); }
    function formatNum(n) {
        n = Number(n) || 0;
        return (Math.abs(n) >= 100 ? Math.round(n).toLocaleString() : (Math.round(n * 10) / 10).toLocaleString());
    }
    function formatCurrency(n) { n = Number(n) || 0; return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
})();
