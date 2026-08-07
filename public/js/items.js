/**
 * Item Watch page — pin specific POS inventory items (or the set of InvNo
 * values that make up a deal) and watch how they sell: units, dollars, the
 * trend across the period, and the change vs the previous period. Plus a
 * best-sellers leaderboard for the same window, so "what's selling best" is
 * answerable without knowing an InvNo up front — and one click turns anything
 * on that list into a watched card.
 *
 * Modeled on the Promotional Cards page (a managed list rendered as a card grid
 * with a click-through detail view), with the Day/Week/Month/Year/Custom period
 * picker every other report uses. Live numbers come from the venue's CenterEdge
 * POS `Sales` table via api/items.php.
 *
 * Grain is DAY, never hour: Sales.ShiftDate is a business day stamped at
 * midnight, so there is no honest hour-of-day here (same as Revenue Mix).
 * Money figures are hidden from roles without view_revenue — the server scrubs
 * them and the client drops the columns; units and unit trends stay visible.
 */
(function() {
    'use strict';

    App.registerRoute('#/items', { render: renderItems });

    var RANGE_LABELS = { day: 'Day', week: 'Week', month: 'Month', year: 'Year', custom: 'Custom' };
    var LIVE_INTERVAL_MS = 60000;

    var state;

    var SORTS = {
        units:   { label: 'Most units',    fn: function(a, b) { return sv(b, 'qty') - sv(a, 'qty'); } },
        revenue: { label: 'Most revenue',  fn: function(a, b) { return sv(b, 'amount') - sv(a, 'amount'); } },
        gain:    { label: 'Biggest gain',  fn: function(a, b) { return dv(b) - dv(a); } },
        drop:    { label: 'Biggest drop',  fn: function(a, b) { return dv(a) - dv(b); } },
        name:    { label: 'Name (A–Z)',    fn: function(a, b) { return a.name.localeCompare(b.name); } },
        added:   { label: 'Recently added', fn: function(a, b) { return (b.id || 0) - (a.id || 0); } }
    };
    /** Stat accessor that treats a stats-less entry as zero, so sorting never throws. */
    function sv(item, key) { return (item.stats && item.stats[key] != null) ? Number(item.stats[key]) : 0; }
    /** Change-vs-comparison for sorting. "No prior" sorts to the middle, not the bottom. */
    function dv(item) {
        var d = item.stats ? item.stats.qty_delta_pct : null;
        return d == null ? 0 : Number(d);
    }

    function freshState() {
        return {
            range: 'week', offset: 0, custom: { from: '', to: '' },
            compare: 'prev',
            search: '', tag: '', sort: 'units',
            itemId: null, list: null, detail: null, top: null,
            topRank: 'revenue', topLimit: 25,
            genList: 0, genDetail: 0, genTop: 0,
            live: false, timer: null
        };
    }

    function canManage() { return App.canAccess('items_manage'); }
    function moneyOK(payload) { return App.canSeeMoney() && !(payload && payload.hide_money); }

    // ------------------------------------------------------------------
    // Formatting
    // ------------------------------------------------------------------
    function fmtUnits(v) {
        if (v == null) return '—';
        var n = Number(v);
        if (!isFinite(n)) return '—';
        // QtySold can be fractional (weighed/partial items) — show decimals
        // only when they actually exist.
        return Math.abs(n - Math.round(n)) < 0.005
            ? Math.round(n).toLocaleString()
            : n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtMoney(v) {
        if (v == null) return '—';
        return '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    function fmtMoney2(v) {
        if (v == null) return '—';
        return '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtPct(v) {
        if (v == null) return '—';
        return (v > 0 ? '+' : '') + (v * 100).toFixed(1) + '%';
    }
    function fmtRate(v) {
        if (v == null) return '—';
        return (v * 100).toFixed(1) + '%';
    }
    /** Label a series key: "2026-07-19" → "Sat, Jul 19"; "2026-07" → "Jul 2026". */
    function keyLabel(key) {
        if (!key) return '';
        if (key.length === 7) {
            return new Date(key + '-15T12:00:00').toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
        }
        return new Date(key + 'T12:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
    }
    /** Same, minus the weekday — for the narrow card chips, which truncate. */
    function keyLabelShort(key) {
        if (!key) return '';
        if (key.length === 7) {
            return new Date(key + '-15T12:00:00').toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
        }
        return new Date(key + 'T12:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }
    function itemLabel(inv, names) {
        var nm = names && names[inv];
        return (nm && nm !== '') ? nm : ('Item ' + inv);
    }

    // ------------------------------------------------------------------
    async function renderItems(container) {
        state = freshState();

        container.appendChild(buildHeader());
        container.appendChild(buildControls());
        container.appendChild(buildToolbar());
        container.appendChild(App.el('div', { id: 'items-note' }));

        container.appendChild(App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } }, [
            App.el('div', { className: 'card-body', id: 'items-list' }, [App.loading()])
        ]));
        container.appendChild(App.el('div', { id: 'items-detail' }));
        container.appendChild(App.el('div', { id: 'items-top' }));

        if (App.canAccess('settings')) {
            container.appendChild(App.el('div', { id: 'items-admin' }));
            loadAdmin();
        }

        await loadAll();
        return cleanup;
    }

    function cleanup() {
        if (!state) return;
        stopLive();
        state = null;
    }

    function buildHeader() {
        var right = [];
        if (canManage()) {
            right.push(App.el('button', {
                className: 'btn btn-primary', textContent: '+ Watch an item',
                onClick: function() { openEditor(null); }
            }));
        }
        right.push(App.el('label', { className: 'items-live-toggle', title: 'Re-check the POS every minute' }, [
            App.el('input', { type: 'checkbox', id: 'items-live', onChange: function(e) { toggleLive(e.target.checked); } }),
            App.el('span', { textContent: 'Live' })
        ]));
        right.push(App.el('button', {
            className: 'btn btn-secondary btn-sm', textContent: 'Refresh',
            onClick: function() { loadAll(); }
        }));

        return App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Item Watch' }),
                App.el('p', { className: 'page-subtitle', textContent:
                    'Pin the items and deals you care about and watch how they sell — units, dollars, and the trend over any period. Click a card for the full breakdown.' })
            ]),
            App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', flexWrap: 'wrap' } }, right)
        ]);
    }

    // ------------------------------------------------------------------
    // Period controls (same model as Performance / Revenue Mix)
    // ------------------------------------------------------------------
    function buildControls() {
        var presetRow = App.el('div', { className: 'perf-range-presets', id: 'items-presets' },
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
                        if (key !== 'custom') loadAll();
                    }
                });
            })
        );
        var nav = App.el('div', { className: 'perf-nav', id: 'items-nav' }, [
            App.el('button', { className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '‹',
                title: 'Previous period', 'aria-label': 'Previous period',
                onClick: function() { if (state.range === 'custom') return; state.offset -= 1; loadAll(); } }),
            App.el('div', { className: 'perf-nav-label', id: 'items-nav-label', textContent: '…' }),
            App.el('button', { className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '›',
                id: 'items-nav-next', title: 'Next period', 'aria-label': 'Next period',
                disabled: state.range === 'custom' || state.offset >= 0,
                onClick: function() { if (state.range === 'custom' || state.offset >= 0) return; state.offset += 1; loadAll(); } }),
            App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Today',
                id: 'items-nav-today', title: 'Jump to the current period',
                disabled: state.offset === 0,
                onClick: function() { if (state.offset === 0) return; state.offset = 0; loadAll(); } })
        ]);
        var custom = App.el('div', { className: 'perf-custom', id: 'items-custom',
            style: { display: state.range === 'custom' ? '' : 'none' } }, [
            App.el('label', { className: 'text-sm text-secondary', 'for': 'items-custom-from', textContent: 'From' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'items-custom-from', value: state.custom.from }),
            App.el('label', { className: 'text-sm text-secondary', 'for': 'items-custom-to', textContent: 'To' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'items-custom-to', value: state.custom.to }),
            App.el('button', { className: 'btn btn-sm btn-primary', textContent: 'Apply',
                onClick: function() {
                    var from = document.getElementById('items-custom-from').value;
                    var to = document.getElementById('items-custom-to').value;
                    if (!from || !to) { App.toast('Pick both a start and end date.', 'warning'); return; }
                    if (from > to) { App.toast('"From" must be on or before "To".', 'warning'); return; }
                    state.custom.from = from; state.custom.to = to; loadAll();
                } })
        ]);
        return App.el('div', { className: 'card perf-controls' }, [
            App.el('div', { className: 'card-body perf-controls-body' }, [presetRow, nav, custom])
        ]);
    }

    /**
     * Second control row: what "vs last" compares against, plus search / tag /
     * sort over the watchlist and a CSV of exactly what's on screen. Everything
     * except the comparison basis is client-side over the loaded payload, so
     * these are instant and cost no extra query.
     */
    function buildToolbar() {
        function sel(id, options, current, onChange, title) {
            var s = App.el('select', { className: 'form-input form-input-sm items-toolbar-select', id: id, title: title || null },
                options.map(function(o) {
                    return App.el('option', { value: o[0], textContent: o[1], selected: o[0] === current });
                }));
            s.addEventListener('change', function() { onChange(s.value); });
            return s;
        }

        var compareSel = sel('items-compare', [
            ['prev', 'vs previous period'],
            ['yoy', 'vs same period last year']
        ], state.compare, function(v) {
            state.compare = v;
            loadAll();  // the comparison window is computed server-side
        }, 'What the change figures are measured against');

        var searchIn = App.el('input', {
            className: 'form-input form-input-sm', type: 'search', id: 'items-search',
            placeholder: 'Search name, tag or InvNo…', value: state.search,
            'aria-label': 'Filter the watchlist'
        });
        searchIn.addEventListener('input', function() {
            state.search = searchIn.value;
            renderList(state.list);
        });

        var sortSel = sel('items-sort', Object.keys(SORTS).map(function(k) { return [k, SORTS[k].label]; }),
            state.sort, function(v) { state.sort = v; renderList(state.list); }, 'Order of the watchlist cards');

        return App.el('div', { className: 'card items-toolbar' }, [
            App.el('div', { className: 'card-body items-toolbar-body' }, [
                App.el('div', { className: 'items-toolbar-group' }, [
                    App.el('label', { className: 'text-sm text-secondary', 'for': 'items-compare', textContent: 'Compare' }),
                    compareSel
                ]),
                App.el('div', { className: 'items-toolbar-group items-toolbar-grow' }, [searchIn]),
                App.el('div', { className: 'items-toolbar-group', id: 'items-tagfilter' }),
                App.el('div', { className: 'items-toolbar-group' }, [
                    App.el('label', { className: 'text-sm text-secondary', 'for': 'items-sort', textContent: 'Sort' }),
                    sortSel
                ]),
                App.el('button', { className: 'btn btn-sm btn-ghost', textContent: '⭳ CSV',
                    title: 'Download the watchlist exactly as filtered and sorted below',
                    onClick: function() { exportCsv(); } })
            ])
        ]);
    }

    /** Tag chips, rebuilt from whatever tags the current watchlist actually uses. */
    function renderTagFilter(data) {
        var box = document.getElementById('items-tagfilter');
        if (!box) return;
        box.innerHTML = '';
        var tags = [];
        (data.items || []).forEach(function(i) {
            if (i.tag && tags.indexOf(i.tag) === -1) tags.push(i.tag);
        });
        if (tags.length < 2) { state.tag = ''; return; }  // one tag filters nothing
        tags.sort();
        if (state.tag && tags.indexOf(state.tag) === -1) state.tag = '';
        box.appendChild(App.el('span', { className: 'text-sm text-secondary', textContent: 'Tag' }));
        [''].concat(tags).forEach(function(t) {
            box.appendChild(App.el('button', {
                className: 'btn btn-sm ' + (state.tag === t ? 'btn-primary' : 'btn-ghost'),
                textContent: t === '' ? 'All' : t,
                onClick: function() { state.tag = t; renderTagFilter(state.list); renderList(state.list); }
            }));
        });
    }

    /** Search + tag filter + sort, applied to the loaded watchlist. */
    function visibleItems(data) {
        var items = (data.items || []).slice();
        var q = state.search.trim().toLowerCase();
        if (q) {
            items = items.filter(function(i) {
                return i.name.toLowerCase().indexOf(q) !== -1
                    || (i.tag || '').toLowerCase().indexOf(q) !== -1
                    || (i.inv_nos || []).some(function(n) { return String(n).indexOf(q) !== -1; })
                    || Object.keys(data.names || {}).some(function(n) {
                        return (i.inv_nos || []).indexOf(n) !== -1
                            && String(data.names[n]).toLowerCase().indexOf(q) !== -1;
                    });
            });
        }
        if (state.tag) items = items.filter(function(i) { return i.tag === state.tag; });
        var sorter = (SORTS[state.sort] || SORTS.units).fn;
        items.sort(sorter);
        return items;
    }

    function exportCsv() {
        if (!state || !state.list) { App.toast('Nothing to export yet.', 'warning'); return; }
        var data = state.list;
        var money = moneyOK(data);
        var items = visibleItems(data);
        if (!items.length) { App.toast('No items match the current filter.', 'warning'); return; }

        var cols = ['Name', 'Tag', 'InvNos', 'Units'];
        if (money) cols = cols.concat(['Revenue', 'Discounts', 'Avg price']);
        if (money && data.has_cost) cols = cols.concat(['Cost', 'Margin', 'Margin %']);
        cols = cols.concat(['Days sold', 'Best ' + (isMonthly(data) ? 'month' : 'day'),
                            'Prior units', 'Units change %']);

        var esc = function(v) {
            var s = v === null || v === undefined ? '' : String(v);
            return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
        };
        var lines = [cols.map(esc).join(',')];
        items.forEach(function(i) {
            var s = i.stats || {};
            var row = [i.name, i.tag || '', (i.inv_nos || []).join(' '), s.qty != null ? s.qty : ''];
            if (money) row = row.concat([s.amount, s.discounts, s.avg_price]);
            if (money && data.has_cost) row = row.concat([s.cost, s.margin,
                s.margin_pct != null ? (s.margin_pct * 100).toFixed(1) : '']);
            row = row.concat([s.days_with_sales, s.best_key || '',
                s.prior_qty != null ? s.prior_qty : '',
                s.qty_delta_pct != null ? (s.qty_delta_pct * 100).toFixed(1) : '']);
            lines.push(row.map(esc).join(','));
        });

        var win = data.window || {};
        var name = 'item-watch-' + (win.from || 'range') + '-to-' + (win.to || '') + '.csv';
        var blob = new Blob([lines.join('\n')], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(function() { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    function refreshPresetButtons() {
        var wrap = document.getElementById('items-presets');
        if (!wrap) return;
        var keys = Object.keys(RANGE_LABELS);
        Array.prototype.forEach.call(wrap.children, function(btn, i) {
            btn.className = 'btn btn-sm ' + (keys[i] === state.range ? 'btn-primary' : 'btn-ghost');
        });
    }
    function toggleCustomRow() {
        var custom = document.getElementById('items-custom');
        var nav = document.getElementById('items-nav');
        if (custom) custom.style.display = state.range === 'custom' ? '' : 'none';
        if (nav) nav.style.display = state.range === 'custom' ? 'none' : '';
    }
    function updateNav() {
        var next = document.getElementById('items-nav-next');
        if (next) next.disabled = (state.range === 'custom' || state.offset >= 0);
        var today = document.getElementById('items-nav-today');
        if (today) today.disabled = (state.offset === 0);
    }

    /** The window query string shared by every endpoint on this page. */
    function windowQs() {
        var qs = 'range=' + encodeURIComponent(state.range) + '&offset=' + encodeURIComponent(state.offset)
               + '&compare=' + encodeURIComponent(state.compare);
        if (state.range === 'custom') {
            qs += '&from=' + encodeURIComponent(state.custom.from) + '&to=' + encodeURIComponent(state.custom.to);
        }
        return qs;
    }
    function customIncomplete() {
        return state.range === 'custom' && (!state.custom.from || !state.custom.to);
    }

    // ------------------------------------------------------------------
    // Live polling
    // ------------------------------------------------------------------
    function toggleLive(on) {
        state.live = !!on;
        stopLive();
        if (state.live) {
            state.timer = setInterval(function() {
                if (!state || !state.live) return;
                loadAll();
            }, LIVE_INTERVAL_MS);
        }
    }
    function stopLive() {
        if (state && state.timer) { clearInterval(state.timer); state.timer = null; }
    }

    // ------------------------------------------------------------------
    // Loading
    // ------------------------------------------------------------------
    async function loadAll() {
        updateNav();
        await loadList();
        if (state && state.itemId) loadDetail();
        if (state && App.canSeeMoney()) loadTop();
    }

    async function loadList() {
        var box = document.getElementById('items-list');
        if (!box) return;
        if (customIncomplete()) {
            box.innerHTML = '';
            box.appendChild(App.el('p', { className: 'text-secondary', textContent: 'Pick a From and To date above, then press Apply.' }));
            return;
        }
        var gen = ++state.genList;
        try {
            var data = await API.get('items?' + windowQs());
            if (!state || state.genList !== gen) return;
            state.list = data;
            renderNote(data);
            renderTagFilter(data);
            renderList(data);
        } catch (err) {
            if (!state || state.genList !== gen) return;
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'text-danger text-sm', textContent:
                'Failed to load: ' + (err && err.message ? err.message : 'unknown error') }));
        }
    }

    function renderNote(data) {
        var box = document.getElementById('items-note');
        if (!box) return;
        box.innerHTML = '';
        var navLabel = document.getElementById('items-nav-label');
        if (navLabel && data.window && data.window.label) navLabel.textContent = data.window.label;

        if (!data.configured) {
            box.appendChild(App.el('div', { className: 'items-note-card', style: { marginBottom: '1rem' } }, [
                App.el('span', { textContent: 'Live sales numbers appear once the POS database connection is set up (on the Go-Kart Labor page). You can pin items now — their numbers will fill in automatically.' })
            ]));
            return;
        }
        if (data.error) {
            box.appendChild(App.el('div', { className: 'items-note-card items-note-bad', style: { marginBottom: '1rem' } }, [
                App.el('span', { textContent: '⚠ Query error: ' + data.error })
            ]));
            return;
        }
        var warnings = [];
        if (data.truncated) {
            warnings.push('That period returned more rows than one pass can hold, so these totals are incomplete — pick a shorter period or watch fewer items.');
        }
        if (data.stats_skipped) {
            warnings.push(data.stats_skipped + ' watched ' + (data.stats_skipped === 1 ? 'entry is' : 'entries are')
                + ' beyond the per-load inventory-number budget and shown without numbers. Remove or split an entry to bring them back.');
        }
        warnings.forEach(function(msg) {
            box.appendChild(App.el('div', { className: 'items-note-card items-note-bad', style: { marginBottom: '1rem' } }, [
                App.el('span', { textContent: '⚠ ' + msg })
            ]));
        });
    }

    function renderList(data) {
        var box = document.getElementById('items-list');
        if (!box || !data) return;
        box.innerHTML = '';
        var money = moneyOK(data);

        if ((data.items || []).length === 0) { box.appendChild(buildEmptyState()); return; }

        box.appendChild(buildSummaryStrip(data, money));

        var items = visibleItems(data);
        if (!items.length) {
            box.appendChild(App.el('div', { className: 'items-empty' }, [
                App.el('div', { className: 'items-empty-title', textContent: 'Nothing matches that filter' }),
                App.el('div', { className: 'items-empty-sub', textContent:
                    'No watched item matches "' + state.search + '"' + (state.tag ? ' in the "' + state.tag + '" tag' : '') + '.' }),
                App.el('button', { className: 'btn btn-secondary', textContent: 'Clear filters',
                    onClick: function() {
                        state.search = ''; state.tag = '';
                        var s = document.getElementById('items-search');
                        if (s) s.value = '';
                        renderTagFilter(data); renderList(data);
                    } })
            ]));
            return;
        }
        if (items.length !== (data.items || []).length) {
            box.appendChild(App.el('div', { className: 'text-muted text-xs', style: { marginBottom: '0.6rem' },
                textContent: 'Showing ' + items.length + ' of ' + data.items.length + ' watched items.' }));
        }

        var grid = App.el('div', { className: 'items-grid' });
        items.forEach(function(it) { grid.appendChild(buildItemCard(it, data, money)); });
        box.appendChild(grid);
    }

    function buildEmptyState() {
        var wrap = App.el('div', { className: 'items-empty' }, [
            App.el('div', { className: 'items-empty-icon', 'aria-hidden': 'true', textContent: '📦' }),
            App.el('div', { className: 'items-empty-title', textContent: 'Nothing on the watchlist yet' }),
            App.el('div', { className: 'items-empty-sub', textContent:
                'Pin an item by its POS inventory number (InvNo) — or bundle several numbers into one card to track a whole deal. The best sellers list below is the quickest way to find the numbers.' })
        ]);
        if (canManage()) {
            wrap.appendChild(App.el('button', { className: 'btn btn-primary', style: { marginTop: '0.5rem' },
                textContent: '+ Watch your first item', onClick: function() { openEditor(null); } }));
        }
        return wrap;
    }

    function buildSummaryStrip(data, money) {
        var t = data.totals;
        var tiles = [
            summTile(fmtUnits((data.items || []).length), (data.items || []).length === 1 ? 'Item watched' : 'Items watched'),
            summTile(t ? fmtUnits(t.qty) : '—', 'Units sold')
        ];
        if (money) {
            tiles.push(summTile(t ? fmtMoney(t.amount) : '—', 'Revenue'));
            if (data.has_cost) tiles.push(summTile(t ? fmtMoney(t.margin) : '—', 'Gross margin'));
            else if (t && t.discounts) tiles.push(summTile(fmtMoney(t.discounts), 'Discounts'));
        }
        return App.el('div', { className: 'items-summary' }, tiles);
    }
    function summTile(value, label) {
        return App.el('div', { className: 'items-summary-tile' }, [
            App.el('div', { className: 'items-summary-value', textContent: value }),
            App.el('div', { className: 'items-summary-label', textContent: label })
        ]);
    }

    /** How the current comparison basis reads in prose ("previous period"). */
    function compareLabel(data) {
        return (data && data.window && data.window.compare_label) || 'previous period';
    }
    /** Sentence-cased, for tooltips. */
    function compareLabelCap(data) {
        var l = compareLabel(data);
        return l.charAt(0).toUpperCase() + l.slice(1);
    }

    /** Change-vs-previous-period pill. null delta renders a neutral "no prior data". */
    function deltaPill(pct, priorText) {
        if (pct == null) {
            return App.el('span', { className: 'items-delta items-delta-flat', title: priorText || 'No comparable previous period',
                textContent: '— no prior' });
        }
        var cls = pct > 0.0005 ? 'items-delta-up' : (pct < -0.0005 ? 'items-delta-down' : 'items-delta-flat');
        var arrow = pct > 0.0005 ? '▲' : (pct < -0.0005 ? '▼' : '■');
        return App.el('span', { className: 'items-delta ' + cls, title: priorText || '',
            textContent: arrow + ' ' + fmtPct(pct) });
    }

    /**
     * Bar sparkline over the period series. Kept as plain elements (no chart
     * library) so it stays cheap on a grid of cards; a single-point series is
     * not a trend, so it renders nothing.
     */
    function buildSpark(series, money) {
        if (!series || series.length < 2) return null;
        var max = series.reduce(function(m, p) { return Math.max(m, p.qty || 0); }, 0);
        if (max <= 0) return null;
        var bars = series.map(function(p) {
            var h = Math.max(p.qty > 0 ? 6 : 2, Math.round((p.qty || 0) / max * 100));
            var tip = keyLabel(p.key) + ': ' + fmtUnits(p.qty) + ' units'
                + (money && p.amount ? ' · ' + fmtMoney2(p.amount) : '');
            return App.el('span', { className: 'items-spark-bar' + (p.qty > 0 ? '' : ' items-spark-empty'),
                style: { height: h + '%' }, title: tip });
        });
        return App.el('div', { className: 'items-spark', 'aria-hidden': 'true' }, bars);
    }

    function invChips(item, names, limit) {
        var shown = item.inv_nos.slice(0, limit);
        var kids = shown.map(function(inv) {
            return App.el('span', { className: 'items-inv-chip', title: itemLabel(inv, names), textContent: '#' + inv });
        });
        if (item.inv_nos.length > shown.length) {
            kids.push(App.el('span', { className: 'text-muted text-xs',
                textContent: '+' + (item.inv_nos.length - shown.length) + ' more' }));
        }
        return kids;
    }

    /** "Runs Jul 1 → Aug 15", or a status word when the deal has ended / not started. */
    function dealWindowText(item) {
        if (!item.start_date && !item.end_date) return '';
        var today = new Date().toISOString().slice(0, 10);
        if (item.end_date && item.end_date < today) return 'Ended ' + shortDate(item.end_date);
        if (item.start_date && item.start_date > today) return 'Starts ' + shortDate(item.start_date);
        if (item.start_date && item.end_date) return shortDate(item.start_date) + ' → ' + shortDate(item.end_date);
        if (item.start_date) return 'Since ' + shortDate(item.start_date);
        return 'Through ' + shortDate(item.end_date);
    }
    function shortDate(iso) {
        if (!iso) return '—';
        return new Date(iso + 'T12:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function buildItemCard(item, data, money) {
        var s = item.stats || null;
        var names = data.names || {};

        var titleWrap = App.el('div', { className: 'items-card-titlewrap' }, [
            App.el('div', { className: 'items-card-name', textContent: item.name }),
            App.el('div', { className: 'items-card-sub' }, invChips(item, names, 4).concat(
                item.tag ? [App.el('span', { className: 'items-tag-chip', textContent: item.tag })] : []))
        ]);
        var top = App.el('div', { className: 'items-card-top' }, [titleWrap]);
        if (canManage()) {
            top.appendChild(App.el('div', { className: 'items-card-actions' }, [
                iconBtn('Edit', 'Edit this watched item', function(e) { e.stopPropagation(); openEditor(item.id); }),
                iconBtn('Delete', 'Stop watching this item', function(e) { e.stopPropagation(); deleteItem(item); }, true)
            ]));
        }

        var figures = [
            App.el('div', { className: 'items-card-bignum', textContent: s ? fmtUnits(s.qty) : '—' }),
            App.el('div', { className: 'items-card-metasub text-muted text-xs', textContent: 'units sold this period' })
        ];
        if (s) {
            figures.push(deltaPill(s.qty_delta_pct,
                s.prior_qty != null
                    ? compareLabelCap(data) + ': ' + fmtUnits(s.prior_qty) + ' units'
                    : 'No data for the ' + compareLabel(data)));
        }
        if (money && s) {
            figures.push(App.el('div', { className: 'items-card-money', textContent: fmtMoney(s.amount) }));
        }

        var body = App.el('div', { className: 'items-card-body' }, [
            App.el('div', { className: 'items-card-figures' }, figures)
        ]);
        var spark = s ? buildSpark(s.series, money) : null;
        if (spark) body.appendChild(spark);

        var chipDefs = [];
        if (s) {
            chipDefs.push(statChip('Days sold', fmtUnits(s.days_with_sales)));
            if (money) chipDefs.push(statChip('Avg price', s.avg_price != null ? fmtMoney2(s.avg_price) : '—'));
            if (money && data.has_cost) chipDefs.push(statChip('Margin', s.margin_pct != null ? fmtRate(s.margin_pct) : '—'));
            chipDefs.push(statChip('Best ' + (isMonthly(data) ? 'month' : 'day'),
                s.best_key ? keyLabelShort(s.best_key) : '—'));
        }
        var chips = chipDefs.length ? App.el('div', { className: 'items-card-stats' }, chipDefs) : null;

        var foot = App.el('div', { className: 'items-card-foot' }, [
            App.el('span', { className: 'text-muted text-xs', textContent: dealWindowText(item) || (s ? '' : 'No numbers loaded') }),
            App.el('span', { className: 'items-card-open text-xs', textContent: 'View →' })
        ]);

        var kids = [top, body];
        if (chips) kids.push(chips);
        kids.push(foot);
        var card = App.el('div', { className: 'items-card' + (state.itemId === item.id ? ' items-card-selected' : '') }, kids);
        card.addEventListener('click', function() { selectItem(item.id); });
        return card;
    }

    function isMonthly(data) {
        return !!(data && data.window && data.window.series_grain === 'month');
    }

    function statChip(label, value) {
        return App.el('div', { className: 'items-chip' }, [
            App.el('div', { className: 'items-chip-val', textContent: value }),
            App.el('div', { className: 'items-chip-label', textContent: label })
        ]);
    }

    function iconBtn(label, title, onClick, danger) {
        var btn = App.el('button', { className: 'btn btn-sm btn-ghost' + (danger ? ' text-danger' : ''), title: title, textContent: label });
        btn.addEventListener('click', onClick);
        return btn;
    }

    function selectItem(id) {
        state.itemId = id;
        renderList(state.list); // refresh card highlight
        loadDetail();
        var detail = document.getElementById('items-detail');
        if (detail) detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ------------------------------------------------------------------
    // Detail
    // ------------------------------------------------------------------
    async function loadDetail() {
        var box = document.getElementById('items-detail');
        if (!box) return;
        if (customIncomplete()) return;
        box.innerHTML = '';
        box.appendChild(App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } }, [
            App.el('div', { className: 'card-body' }, [App.loading()])
        ]));
        var gen = ++state.genDetail;
        try {
            var data = await API.get('items/detail?id=' + encodeURIComponent(state.itemId) + '&' + windowQs());
            if (!state || state.genDetail !== gen) return;
            state.detail = data;
            renderDetail(data);
        } catch (err) {
            if (!state || state.genDetail !== gen) return;
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body text-danger text-sm', textContent:
                    'Failed to load item: ' + (err && err.message ? err.message : 'unknown error') })
            ]));
        }
    }

    function renderDetail(data) {
        var box = document.getElementById('items-detail');
        if (!box) return;
        box.innerHTML = '';
        var item = data.item || {};
        var s = data.summary;
        var money = moneyOK(data);
        var names = data.names || {};

        var subParts = [(item.inv_nos || []).map(function(i) { return '#' + i; }).join(', ')];
        if (item.tag) subParts.push(item.tag);
        var dw = dealWindowText(item);
        if (dw) subParts.push(dw);

        var header = App.el('div', { className: 'card-header items-detail-header' }, [
            App.el('div', {}, [
                App.el('div', { className: 'card-title', textContent: item.name }),
                App.el('div', { className: 'text-muted text-sm', textContent: subParts.join(' · ') })
            ]),
            App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Close',
                onClick: function() { state.itemId = null; box.innerHTML = ''; renderList(state.list); } })
        ]);

        var kids = [];
        if (!data.configured) {
            kids.push(App.el('div', { className: 'text-secondary text-sm', textContent:
                'Live sales numbers appear once the POS database connection is configured (Go-Kart Labor page).' }));
        } else if (data.error) {
            kids.push(App.el('div', { className: 'text-danger text-sm', textContent: 'Query error: ' + data.error }));
        } else if (s) {
            kids.push(buildDetailHero(s, data, money));
            if (item.notes) kids.push(App.el('div', { className: 'items-notes', textContent: item.notes }));
            kids.push(buildDetailTiles(s, data, money));
            if (data.lifetime) kids.push(buildLifetime(data.lifetime, item, money, data.has_cost));
            var trend = buildTrend(s.series, data, money);
            if (trend) kids.push(trend);
            if ((item.inv_nos || []).length > 1) kids.push(buildBreakdown(data, names, money));
            // Multi-period history, independent of the page's period picker.
            var histHost = App.el('div');
            kids.push(histHost);
            mountHistoryPanel(histHost, { id: item.id });
        } else {
            kids.push(App.el('div', { className: 'text-secondary text-sm', textContent: 'No sales found for this item in this period.' }));
        }

        box.appendChild(App.el('div', { className: 'card items-detail-card' }, [
            header, App.el('div', { className: 'card-body' }, kids)
        ]));
    }

    function buildDetailHero(s, data, money) {
        var lines = [
            App.el('div', { className: 'items-hero-big', textContent: fmtUnits(s.qty) }),
            App.el('div', { className: 'text-secondary', textContent: 'units sold in ' + ((data.window && data.window.label) || 'this period') })
        ];
        var row = App.el('div', { className: 'items-hero-row' }, [
            deltaPill(s.qty_delta_pct, s.prior_qty != null
                ? compareLabelCap(data) + ': ' + fmtUnits(s.prior_qty) + ' units'
                : 'No data for the ' + compareLabel(data)),
            App.el('span', { className: 'text-muted text-sm', textContent:
                s.prior_qty != null
                    ? 'vs ' + fmtUnits(s.prior_qty) + ' units — ' + compareLabel(data)
                    : 'nothing sold in the ' + compareLabel(data) })
        ]);
        lines.push(row);
        if (money) {
            lines.push(App.el('div', { className: 'items-hero-money', textContent: fmtMoney2(s.amount) + ' in sales' }));
        }
        var spark = buildSpark(s.series, money);
        var kids = [App.el('div', { className: 'items-hero-meta' }, lines)];
        if (spark) kids.push(App.el('div', { className: 'items-hero-spark' }, [spark]));
        return App.el('div', { className: 'items-hero' }, kids);
    }

    function tile(label, value, hint) {
        return App.el('div', { className: 'items-tile' }, [
            App.el('div', { className: 'stat-label', textContent: label }),
            App.el('div', { className: 'items-tile-value', textContent: value }),
            App.el('div', { className: 'text-muted text-xs', textContent: hint || '' })
        ]);
    }

    function buildDetailTiles(s, data, money) {
        var grain = isMonthly(data) ? 'month' : 'day';
        var tiles = [tile('Units', fmtUnits(s.qty), 'sold in this period')];
        if (money) {
            tiles.push(tile('Revenue', fmtMoney2(s.amount), 'net sales dollars'));
            tiles.push(tile('Avg price', s.avg_price != null ? fmtMoney2(s.avg_price) : '—', 'revenue ÷ units'));
            if (s.discounts) tiles.push(tile('Discounts', fmtMoney2(s.discounts), 'given on these sales'));
            if (data.has_cost) {
                tiles.push(tile('Gross margin', fmtMoney2(s.margin),
                    s.margin_pct != null ? fmtRate(s.margin_pct) + ' of revenue' : 'revenue − cost of goods'));
            }
        }
        tiles.push(tile('Days sold', fmtUnits(s.days_with_sales), 'days with at least one sale'));
        tiles.push(tile('Best ' + grain, s.best_key ? keyLabel(s.best_key) : '—',
            s.best_qty != null ? fmtUnits(s.best_qty) + ' units' : 'no sales yet'));
        return App.el('div', { className: 'items-tile-grid' }, tiles);
    }

    function buildLifetime(lt, item, money, hasCost) {
        var bits = [fmtUnits(lt.qty) + ' units'];
        if (money) bits.push(fmtMoney2(lt.amount));
        if (money && hasCost) bits.push(fmtMoney2(lt.margin) + ' margin');
        if (money && lt.avg_price != null) bits.push(fmtMoney2(lt.avg_price) + ' avg price');

        var note = 'Since ' + shortDate(lt.started) + ' through ' + shortDate(lt.to)
            + ' (' + fmtUnits(lt.span_days) + ' days, sold on ' + fmtUnits(lt.days_sold) + ')';
        if (lt.clamped) {
            note += ' — the lookback is capped at 5 years, so this starts ' + shortDate(lt.from) + '.';
        }
        return App.el('div', { className: 'items-lifetime' }, [
            App.el('div', { className: 'stat-label', textContent: 'Since it launched' }),
            App.el('div', { className: 'items-lifetime-value', textContent: bits.join(' · ') }),
            App.el('div', { className: 'text-muted text-xs', textContent: note })
        ]);
    }

    function buildTrend(series, data, money) {
        if (!series || !series.length) return null;
        var max = series.reduce(function(m, p) { return Math.max(m, p.qty || 0); }, 0);
        if (max <= 0) return null;
        var grain = isMonthly(data) ? 'month' : 'day';
        var rows = series.map(function(p) {
            var w = Math.max(p.qty > 0 ? 1 : 0, Math.round((p.qty || 0) / max * 100));
            return App.el('div', { className: 'labor-hour-row', title: money && p.amount ? fmtMoney2(p.amount) : '' }, [
                App.el('span', { className: 'labor-hour-label', textContent: keyLabel(p.key) }),
                App.el('span', { className: 'labor-hour-track' }, [
                    App.el('span', { className: 'items-bar-fill', style: { width: w + '%' } })
                ]),
                App.el('span', { className: 'labor-hour-val', textContent: fmtUnits(p.qty) })
            ]);
        });
        return App.el('div', { className: 'items-trend' }, [
            App.el('div', { className: 'stat-label', style: { margin: '1rem 0 0.5rem' }, textContent: 'Units by ' + grain }),
            App.el('div', { className: 'labor-hour-list items-trend-list' }, rows)
        ]);
    }

    function buildBreakdown(data, names, money) {
        var rows = data.breakdown || [];
        var wrap = App.el('div', { className: 'items-breakdown' }, [
            App.el('div', { className: 'stat-label', style: { margin: '1rem 0 0.5rem' }, textContent:
                'Which inventory numbers are moving' })
        ]);
        if (!rows.length) {
            wrap.appendChild(App.el('div', { className: 'text-muted text-sm', textContent: 'No per-item sales in this period.' }));
            return wrap;
        }
        var head = [
            App.el('th', { scope: 'col', textContent: 'Item' }),
            App.el('th', { scope: 'col', className: 'text-right', textContent: 'Units' }),
            App.el('th', { scope: 'col', className: 'text-right', textContent: 'Share' })
        ];
        if (money) {
            head.push(App.el('th', { scope: 'col', className: 'text-right', textContent: 'Revenue' }));
            head.push(App.el('th', { scope: 'col', className: 'text-right', textContent: 'Avg price' }));
            if (data.has_cost) head.push(App.el('th', { scope: 'col', className: 'text-right', textContent: 'Margin' }));
        }
        head.push(App.el('th', { scope: 'col', className: 'text-right',
            title: 'Units in the ' + compareLabel(data),
            textContent: state.compare === 'yoy' ? 'Last yr units' : 'Prev units' }));

        var tbody = App.el('tbody', {}, rows.map(function(r) {
            var cells = [
                App.el('td', { 'data-sort': itemLabel(r.inv, names) }, [
                    App.el('strong', { textContent: itemLabel(r.inv, names) }),
                    App.el('span', { className: 'text-muted text-xs', textContent: ' #' + r.inv })
                ]),
                App.el('td', { className: 'text-right', 'data-sort': r.qty, textContent: fmtUnits(r.qty) }),
                App.el('td', { className: 'text-right', 'data-sort': r.share == null ? null : r.share,
                    textContent: r.share == null ? '—' : fmtRate(r.share) })
            ];
            if (money) {
                cells.push(App.el('td', { className: 'text-right', 'data-sort': r.amount, textContent: fmtMoney2(r.amount) }));
                cells.push(App.el('td', { className: 'text-right', 'data-sort': r.avg_price == null ? null : r.avg_price,
                    textContent: r.avg_price == null ? '—' : fmtMoney2(r.avg_price) }));
                if (data.has_cost) {
                    cells.push(App.el('td', { className: 'text-right', 'data-sort': r.margin,
                        textContent: fmtMoney2(r.margin) + (r.margin_pct != null ? ' (' + fmtRate(r.margin_pct) + ')' : '') }));
                }
            }
            cells.push(App.el('td', { className: 'text-right', 'data-sort': r.prior_qty == null ? null : r.prior_qty,
                textContent: r.prior_qty == null ? '—' : fmtUnits(r.prior_qty) }));
            return App.el('tr', {}, cells);
        }));

        var table = App.el('table', { className: 'data-table items-table' }, [
            App.el('thead', {}, [App.el('tr', {}, head)]), tbody
        ]);
        App.enhanceTableSort(table, { defaultSort: { index: 1, dir: 'desc' } });
        wrap.appendChild(App.el('div', { className: 'table-scroll-x' }, [table]));
        return wrap;
    }

    // ------------------------------------------------------------------
    // Multi-period history ("How it's tracking")
    //
    // Deliberately independent of the page's period picker: the picker answers
    // "how did it do in THIS window", this answers "how has it been doing over
    // the last N weeks / months / quarters / years". Works for a watched entry
    // (by id) or any ad-hoc InvNo, so an item can be examined straight from the
    // best-sellers list without pinning it.
    // ------------------------------------------------------------------
    var HISTORY_GRAINS = [
        ['day', 'Days'], ['week', 'Weeks'], ['month', 'Months'],
        ['quarter', 'Quarters'], ['year', 'Years']
    ];
    var HISTORY_COUNTS = {
        day: [14, 30, 60, 90], week: [8, 12, 26, 52],
        month: [6, 12, 24, 36], quarter: [4, 8, 12, 20], year: [3, 5, 10, 20]
    };
    var HISTORY_DEFAULT_COUNT = { day: 30, week: 12, month: 12, quarter: 8, year: 5 };

    /**
     * Mount a self-contained history panel into `target`. Each instance keeps
     * its own grain/count in a closure, so the inline copy on the detail view
     * and a modal copy from the leaderboard never fight over shared state.
     *
     * @param {{id?:number, inv?:string, compact?:boolean}} opts
     */
    function mountHistoryPanel(target, opts) {
        opts = opts || {};
        var st = { grain: 'month', count: HISTORY_DEFAULT_COUNT.month, gen: 0, data: null };

        var controls = App.el('div', { className: 'items-hist-controls' });
        var body = App.el('div', { className: 'items-hist-body' }, [App.loading()]);
        target.appendChild(App.el('div', { className: 'items-hist' }, [controls, body]));

        function buildControls() {
            controls.innerHTML = '';
            controls.appendChild(App.el('span', { className: 'stat-label', textContent: 'How it\'s tracking' }));
            var grainWrap = App.el('div', { className: 'items-hist-seg' }, HISTORY_GRAINS.map(function(g) {
                return App.el('button', {
                    className: 'btn btn-sm ' + (st.grain === g[0] ? 'btn-primary' : 'btn-ghost'),
                    textContent: g[1],
                    onClick: function() {
                        if (st.grain === g[0]) return;
                        st.grain = g[0];
                        st.count = HISTORY_DEFAULT_COUNT[g[0]];
                        buildControls(); load();
                    }
                });
            }));
            var countSel = App.el('select', { className: 'form-input form-input-sm items-toolbar-select',
                'aria-label': 'How many periods to show' },
                (HISTORY_COUNTS[st.grain] || [12]).map(function(n) {
                    return App.el('option', { value: String(n), textContent: 'Last ' + n, selected: n === st.count });
                }));
            countSel.addEventListener('change', function() { st.count = Number(countSel.value); load(); });
            controls.appendChild(grainWrap);
            controls.appendChild(countSel);
            controls.appendChild(App.el('button', { className: 'btn btn-sm btn-ghost', textContent: '⭳ CSV',
                title: 'Download this period-by-period history',
                onClick: function() { exportHistoryCsv(st.data); } }));
        }

        async function load() {
            var gen = ++st.gen;
            body.innerHTML = '';
            body.appendChild(App.loading());
            var qs = 'grain=' + encodeURIComponent(st.grain) + '&count=' + encodeURIComponent(st.count);
            qs += opts.id ? '&id=' + encodeURIComponent(opts.id) : '&inv=' + encodeURIComponent(opts.inv || '');
            try {
                var data = await API.get('items/history?' + qs);
                if (st.gen !== gen) return;
                st.data = data;
                render(data);
            } catch (err) {
                if (st.gen !== gen) return;
                body.innerHTML = '';
                body.appendChild(App.el('div', { className: 'text-danger text-sm', textContent:
                    'Could not load history: ' + (err && err.message ? err.message : 'unknown error') }));
            }
        }

        function render(data) {
            body.innerHTML = '';
            if (!data.configured) {
                body.appendChild(App.el('div', { className: 'text-secondary text-sm', textContent:
                    'History appears once the POS database connection is configured (Go-Kart Labor page).' }));
                return;
            }
            if (data.error) {
                body.appendChild(App.el('div', { className: 'text-danger text-sm', textContent: 'Query error: ' + data.error }));
                return;
            }
            var rows = data.periods || [];
            var money = moneyOK(data);
            if (!rows.length) {
                body.appendChild(App.el('div', { className: 'text-muted text-sm', textContent: 'No history for this item.' }));
                return;
            }

            var sum = data.summary || {};
            if (sum.complete_periods) {
                var bits = [fmtUnits(sum.qty) + ' units over ' + sum.complete_periods + ' complete '
                    + (sum.complete_periods === 1 ? grainNoun(data.grain) : grainNoun(data.grain) + 's')];
                if (sum.avg_qty != null) bits.push('avg ' + fmtUnits(sum.avg_qty) + '/' + grainNoun(data.grain));
                if (money) bits.push(fmtMoney(sum.amount) + ' total');
                if (sum.best_key) bits.push('best ' + histLabel(sum.best_key, data.grain) + ' (' + fmtUnits(sum.best_qty) + ')');
                body.appendChild(App.el('div', { className: 'items-hist-summary text-sm', textContent: bits.join(' · ') }));
            }

            var max = rows.reduce(function(m, r) { return Math.max(m, r.qty || 0); }, 0);
            var head = [
                App.el('th', { scope: 'col', textContent: grainNounCap(data.grain) }),
                App.el('th', { scope: 'col', className: 'text-right', textContent: 'Units' }),
                App.el('th', { scope: 'col', className: 'text-right', textContent: 'Change' })
            ];
            if (money) {
                head.push(App.el('th', { scope: 'col', className: 'text-right', textContent: 'Revenue' }));
                head.push(App.el('th', { scope: 'col', className: 'text-right', textContent: 'Avg price' }));
                if (data.has_cost) head.push(App.el('th', { scope: 'col', className: 'text-right', textContent: 'Margin' }));
            }
            head.push(App.el('th', { scope: 'col', className: 'text-right', textContent: 'Days sold' }));
            head.push(App.el('th', { scope: 'col', 'aria-label': 'Relative units (bar)', 'data-nosort': '' }));

            var tbody = App.el('tbody', {}, rows.map(function(r) {
                var w = max > 0 ? Math.max(r.qty > 0 ? 2 : 0, Math.round((r.qty || 0) / max * 100)) : 0;
                var nameCell = [App.el('strong', { className: 'items-nowrap', textContent: histLabel(r.key, data.grain) })];
                // A period still in progress is not comparable to a finished
                // one — flag it instead of letting it read as a collapse.
                if (!r.complete) {
                    nameCell.push(App.el('span', { className: 'items-hist-partial',
                        title: 'Still in progress — ' + r.start + ' to ' + r.end, textContent: 'in progress' }));
                }
                var cells = [
                    App.el('td', { 'data-sort': r.key }, nameCell),
                    App.el('td', { className: 'text-right', 'data-sort': r.qty, textContent: fmtUnits(r.qty) }),
                    App.el('td', { className: 'text-right', 'data-sort': r.qty_delta_pct == null ? null : r.qty_delta_pct }, [
                        r.qty_delta_pct == null
                            ? App.el('span', { className: 'text-muted', textContent: '—' })
                            // A period still running is compared against a
                            // finished one, so the number is real but the
                            // colour would lie — a month that is 3 days old is
                            // not "down 70%". Keep the figure, drop the verdict.
                            : App.el('span', {
                                className: 'items-delta ' + (!r.complete ? 'items-delta-flat'
                                    : (r.qty_delta_pct > 0.0005 ? 'items-delta-up'
                                        : (r.qty_delta_pct < -0.0005 ? 'items-delta-down' : 'items-delta-flat'))),
                                title: r.complete ? null
                                    : 'This ' + grainNoun(data.grain) + ' is only part-way through ('
                                        + r.start + ' to today), so it is not comparable with a full one yet.',
                                textContent: fmtPct(r.qty_delta_pct) + (r.complete ? '' : ' so far') })
                    ])
                ];
                if (money) {
                    cells.push(App.el('td', { className: 'text-right', 'data-sort': r.amount, textContent: fmtMoney(r.amount) }));
                    cells.push(App.el('td', { className: 'text-right', 'data-sort': r.avg_price == null ? null : r.avg_price,
                        textContent: r.avg_price == null ? '—' : fmtMoney2(r.avg_price) }));
                    if (data.has_cost) {
                        cells.push(App.el('td', { className: 'text-right items-nowrap', 'data-sort': r.margin }, [
                            App.el('span', { textContent: fmtMoney(r.margin) }),
                            r.margin_pct != null
                                ? App.el('span', { className: 'text-muted text-xs', textContent: ' ' + fmtRate(r.margin_pct) })
                                : null
                        ].filter(Boolean)));
                    }
                }
                cells.push(App.el('td', { className: 'text-right', 'data-sort': r.days_sold, textContent: fmtUnits(r.days_sold) }));
                cells.push(App.el('td', { style: { width: '22%' } }, [
                    App.el('div', { className: 'labor-bar-track', title: fmtUnits(r.qty) + ' units' }, [
                        App.el('div', { className: 'items-bar-fill' + (r.complete ? '' : ' items-bar-partial'),
                            style: { width: w + '%' } })
                    ])
                ]));
                return App.el('tr', { className: r.complete ? null : 'items-hist-row-partial' }, cells);
            }));

            var table = App.el('table', { className: 'data-table items-table' }, [
                App.el('thead', {}, [App.el('tr', {}, head)]), tbody
            ]);
            App.enhanceTableSort(table);
            body.appendChild(App.el('div', { className: 'table-scroll-x' }, [table]));
            body.appendChild(App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                'Each row is a calendar ' + grainNoun(data.grain) + ' (' + data.from + ' → ' + data.to
                + '). Change compares each row with the one above it. Totals and averages above count complete '
                + grainNoun(data.grain) + 's only.' }));
        }

        buildControls();
        load();
        return { reload: load };
    }

    /** CSV of a loaded history payload — same money rules as the table above it. */
    function exportHistoryCsv(data) {
        if (!data || !(data.periods || []).length) { App.toast('Nothing to export yet.', 'warning'); return; }
        var money = moneyOK(data);
        var cols = [grainNounCap(data.grain), 'Start', 'End', 'Complete', 'Units', 'Units change %'];
        if (money) cols = cols.concat(['Revenue', 'Avg price']);
        if (money && data.has_cost) cols = cols.concat(['Cost', 'Margin', 'Margin %']);
        cols.push('Days sold');
        var esc = function(v) {
            var t = v === null || v === undefined ? '' : String(v);
            return /[",\n]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t;
        };
        var lines = [cols.map(esc).join(',')];
        data.periods.forEach(function(r) {
            var row = [r.key, r.start, r.end, r.complete ? 'yes' : 'in progress', r.qty,
                r.qty_delta_pct != null ? (r.qty_delta_pct * 100).toFixed(1) : ''];
            if (money) row = row.concat([r.amount, r.avg_price]);
            if (money && data.has_cost) row = row.concat([r.cost, r.margin,
                r.margin_pct != null ? (r.margin_pct * 100).toFixed(1) : '']);
            row.push(r.days_sold);
            lines.push(row.map(esc).join(','));
        });
        var slug = String(data.label || 'item').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        var blob = new Blob([lines.join('\n')], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'item-history-' + (slug || 'item') + '-' + data.grain + '.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(function() { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    function grainNoun(g) { return g === 'day' ? 'day' : (g === 'week' ? 'week' : (g === 'quarter' ? 'quarter' : (g === 'year' ? 'year' : 'month'))); }
    function grainNounCap(g) { var n = grainNoun(g); return n.charAt(0).toUpperCase() + n.slice(1); }

    /** Period keys are grain-specific: "2026-07", "2026-Q3", "2026", "2026-07-19". */
    function histLabel(key, grain) {
        if (!key) return '';
        if (grain === 'quarter') return key.replace('-', ' ');
        if (grain === 'year') return key;
        if (grain === 'month') return new Date(key + '-15T12:00:00').toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
        if (grain === 'week') return 'w/c ' + new Date(key + 'T12:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        return new Date(key + 'T12:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
    }

    /** History for any InvNo, in a modal — the leaderboard's "History" action. */
    function openHistoryModal(inv, label) {
        var body = App.el('div', { className: 'items-hist-modal' });
        mountHistoryPanel(body, { inv: String(inv) });
        var footer = App.el('div', { className: 'flex gap-sm', style: { justifyContent: 'flex-end' } }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Close', onClick: function() { App.hideModal(); } })
        ]);
        if (canManage()) {
            footer.insertBefore(App.el('button', { className: 'btn btn-primary', textContent: '+ Watch this item',
                onClick: function() { App.hideModal(); openEditor(null, { name: label, inv_nos: String(inv) }); } }), footer.firstChild);
        }
        App.showModal(label + ' · #' + inv, body, footer);
    }

    // ------------------------------------------------------------------
    // Best sellers
    // ------------------------------------------------------------------
    async function loadTop() {
        var box = document.getElementById('items-top');
        if (!box) return;
        if (customIncomplete()) return;
        var gen = ++state.genTop;
        try {
            var data = await API.get('items/top?' + windowQs() + '&rank=' + encodeURIComponent(state.topRank));
            if (!state || state.genTop !== gen) return;
            state.top = data;
            renderTop(data);
        } catch (err) {
            if (!state || state.genTop !== gen) return;
            box.innerHTML = '';
            // A role without view_revenue simply doesn't get this panel; any
            // other failure is worth showing.
            if (!/403|permission|forbidden/i.test(err && err.message ? err.message : '')) {
                box.appendChild(App.el('div', { className: 'card' }, [
                    App.el('div', { className: 'card-body text-secondary text-sm', textContent:
                        'Could not load best sellers: ' + (err && err.message ? err.message : 'unknown error') })
                ]));
            }
        }
    }

    function renderTop(data) {
        var box = document.getElementById('items-top');
        if (!box) return;
        box.innerHTML = '';
        if (!data.configured) return;
        if (data.error) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body text-secondary text-sm', textContent: '⚠ Best sellers: ' + data.error })
            ]));
            return;
        }
        var allRows = data.items || [];
        if (!allRows.length) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-header' }, [App.el('h3', { textContent: 'Best sellers' })]),
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent: 'No sales recorded in this period.' })
                ])
            ]));
            return;
        }
        // The server returns the whole ranked pool; showing fewer is a display
        // choice, so it stays client-side and switching is instant.
        var rows = state.topLimit === 0 ? allRows : allRows.slice(0, state.topLimit);

        var maxAmt = rows.reduce(function(m, r) { return Math.max(m, r.amount || 0); }, 0);
        var head = [
            App.el('th', { scope: 'col', textContent: 'Item' }),
            App.el('th', { scope: 'col', className: 'text-right', textContent: 'Revenue' }),
            App.el('th', { scope: 'col', className: 'text-right', textContent: 'Share' }),
            App.el('th', { scope: 'col', className: 'text-right', textContent: 'Units' }),
            App.el('th', { scope: 'col', className: 'text-right', textContent: 'Avg price' })
        ];
        if (data.has_cost) head.push(App.el('th', { scope: 'col', className: 'text-right', textContent: 'Margin' }));
        head.push(App.el('th', { scope: 'col', 'aria-label': 'Relative revenue (bar)', 'data-nosort': '' }));
        head.push(App.el('th', { scope: 'col', 'aria-label': 'Actions', 'data-nosort': '' }));

        var tbody = App.el('tbody', {}, rows.map(function(r) {
            var w = maxAmt > 0 ? Math.max(r.amount > 0 ? 2 : 0, Math.round((r.amount || 0) / maxAmt * 100)) : 0;
            var label = (r.name && r.name !== '') ? r.name : ('Item ' + r.inv);
            var cells = [
                App.el('td', { 'data-sort': label }, [
                    App.el('strong', { textContent: label }),
                    App.el('span', { className: 'text-muted text-xs', textContent: ' #' + r.inv }),
                    r.watch_id ? App.el('span', { className: 'items-watching-chip', title: 'Already watched as "' + r.watch_name + '"', textContent: 'watching' }) : null
                ].filter(Boolean)),
                App.el('td', { className: 'text-right', 'data-sort': r.amount }, [
                    App.el('span', { className: 'items-amount', textContent: fmtMoney(r.amount) })
                ]),
                App.el('td', { className: 'text-right', 'data-sort': r.share == null ? null : r.share,
                    textContent: r.share == null ? '—' : fmtRate(r.share) }),
                App.el('td', { className: 'text-right', 'data-sort': r.qty, textContent: fmtUnits(r.qty) }),
                App.el('td', { className: 'text-right', 'data-sort': r.avg_price == null ? null : r.avg_price,
                    textContent: r.avg_price == null ? '—' : fmtMoney2(r.avg_price) })
            ];
            if (data.has_cost) {
                cells.push(App.el('td', { className: 'text-right', 'data-sort': r.margin_pct == null ? null : r.margin_pct,
                    textContent: r.margin_pct == null ? '—' : fmtRate(r.margin_pct) }));
            }
            cells.push(App.el('td', { style: { width: '18%' } }, [
                App.el('div', { className: 'labor-bar-track', title: fmtMoney2(r.amount) }, [
                    App.el('div', { className: 'items-bar-fill', style: { width: w + '%' } })
                ])
            ]));
            // History works for ANY item, watched or not — that is the point of
            // the leaderboard as a discovery surface.
            var actions = [App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'History',
                title: 'How this item has sold over the last months / quarters / years',
                onClick: function(e) { e.stopPropagation(); openHistoryModal(r.inv, label); } })];
            if (r.watch_id) {
                actions.push(App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Open',
                    onClick: function(e) { e.stopPropagation(); selectItem(r.watch_id); } }));
            } else if (canManage()) {
                actions.push(App.el('button', { className: 'btn btn-sm btn-secondary', textContent: '+ Watch',
                    onClick: function(e) { e.stopPropagation(); openEditor(null, { name: label, inv_nos: String(r.inv) }); } }));
            }
            cells.push(App.el('td', {}, [App.el('div', { className: 'items-row-actions' }, actions)]));
            return App.el('tr', {}, cells);
        }));

        var table = App.el('table', { className: 'data-table items-table' }, [
            App.el('thead', {}, [App.el('tr', {}, head)]), tbody
        ]);
        App.enhanceTableSort(table, { defaultSort: { index: 1, dir: 'desc' } });

        var RANKS = { revenue: 'revenue', units: 'units sold', margin: 'gross margin' };
        var rankSel = App.el('select', { className: 'form-input form-input-sm items-toolbar-select',
            'aria-label': 'Rank the leaderboard by', disabled: !!data.rank_locked,
            title: data.rank_locked
                ? 'The best-sellers query has been customised with its own ORDER BY, so the ranking is fixed. Restore the default query to re-enable this.'
                : 'Which measure decides the ranking' },
            Object.keys(RANKS).map(function(k) {
                return App.el('option', { value: k, textContent: 'By ' + RANKS[k], selected: k === (data.rank || 'revenue') });
            }));
        rankSel.addEventListener('change', function() { state.topRank = rankSel.value; loadTop(); });

        var limitSel = App.el('select', { className: 'form-input form-input-sm items-toolbar-select',
            'aria-label': 'How many rows to show' },
            [[10, 'Top 10'], [25, 'Top 25'], [50, 'Top 50'], [0, 'All ' + allRows.length]].map(function(o) {
                return App.el('option', { value: String(o[0]), textContent: o[1], selected: o[0] === state.topLimit });
            }));
        limitSel.addEventListener('change', function() { state.topLimit = Number(limitSel.value); renderTop(state.top); });

        // Say exactly what the pool is: the SQL picked its TOP N on the ranked
        // measure, so an item outside that N is genuinely absent, not hidden.
        var note = 'Ranked by ' + RANKS[data.rank || 'revenue'] + ' for ' + ((data.window && data.window.label) || 'this period')
            + ', from the POS Sales table grouped by InvNo. Showing ' + rows.length + ' of the '
            + allRows.length + ' items the query returned. This is a business-day roll-up, so there is no hour-of-day here.';
        if (data.rank_locked) note += ' Ranking is fixed because the query has been customised with its own ORDER BY.';

        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header items-top-header' }, [
                App.el('div', {}, [
                    App.el('h3', { textContent: 'Best sellers' }),
                    App.el('span', { className: 'text-muted text-sm', textContent: (data.window && data.window.label) || '' })
                ]),
                App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', flexWrap: 'wrap' } }, [rankSel, limitSel])
            ]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'table-scroll' }, [table]),
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent: note })
            ])
        ]));
    }

    // ------------------------------------------------------------------
    // Create / edit / delete
    // ------------------------------------------------------------------
    async function openEditor(itemId, prefill) {
        var existing = null;
        if (itemId) {
            try { existing = await API.get('items/' + itemId); }
            catch (err) { App.toast('Could not load item: ' + (err && err.message ? err.message : ''), 'error'); return; }
        }
        var e = existing || prefill || {};
        var invValue = Array.isArray(e.inv_nos) ? e.inv_nos.join(', ') : (e.inv_nos || '');

        var nameInput  = App.el('input', { className: 'form-input', type: 'text', maxLength: 100, value: e.name || '', placeholder: 'e.g. Go Kart 3-Ride Deal' });
        var invInput   = App.el('input', { className: 'form-input', type: 'text', value: invValue, placeholder: 'e.g. 7157 or 7157, 7158, 7159' });
        var tagInput   = App.el('input', { className: 'form-input', type: 'text', maxLength: 40, value: e.tag || '', placeholder: 'e.g. Deals, Food, Merch' });
        var startInput = App.el('input', { className: 'form-input', type: 'date', value: e.start_date || '' });
        var endInput   = App.el('input', { className: 'form-input', type: 'date', value: e.end_date || '' });
        var notesInput = App.el('textarea', { className: 'form-input', rows: 2, maxLength: 1000, placeholder: 'Optional notes' },
            [document.createTextNode(e.notes || '')]);

        function field(label, input, hint) {
            var kids = [App.el('label', { className: 'form-label', textContent: label }), input];
            if (hint) kids.push(App.el('div', { className: 'text-muted text-xs', textContent: hint }));
            return App.el('div', { className: 'form-group' }, kids);
        }

        var body = App.el('div', {}, [
            field('Name', nameInput, 'what you want to call it on the card'),
            field('Inventory numbers (InvNo)', invInput,
                'One number, or several separated by commas to track a deal as a single card. The best sellers table shows each item\'s number.'),
            field('Tag', tagInput, 'optional — groups the cards (cards sort by tag, then name)'),
            App.el('div', { className: 'items-form-row' }, [
                field('Starts', startInput, 'optional — enables the "since it launched" totals'),
                field('Ends', endInput, 'optional')
            ]),
            field('Notes', notesInput)
        ]);

        var saveBtn = App.el('button', { className: 'btn btn-primary', textContent: itemId ? 'Save' : 'Watch it' });
        var footer = App.el('div', { className: 'flex gap-sm', style: { justifyContent: 'flex-end' } }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
            saveBtn
        ]);

        saveBtn.addEventListener('click', async function() {
            var payload = {
                name: nameInput.value.trim(),
                inv_nos: invInput.value.trim(),
                tag: tagInput.value.trim(),
                start_date: startInput.value,
                end_date: endInput.value,
                notes: notesInput.value.trim()
            };
            if (!payload.name) { App.toast('Give it a name.', 'warning'); return; }
            if (!payload.inv_nos) { App.toast('Enter at least one inventory number.', 'warning'); return; }
            if (/[^0-9,;\s]/.test(payload.inv_nos)) {
                App.toast('Inventory numbers must be whole numbers separated by commas.', 'warning'); return;
            }
            if (payload.start_date && payload.end_date && payload.end_date < payload.start_date) {
                App.toast('"Ends" must be on or after "Starts".', 'warning'); return;
            }
            saveBtn.disabled = true;
            try {
                if (itemId) {
                    await API.put('items/' + itemId, payload);
                    App.toast('Saved.', 'success');
                } else {
                    var created = await API.post('items', payload);
                    if (created && created.id && state) state.itemId = created.id;
                    App.toast('Now watching "' + payload.name + '".', 'success');
                }
                App.hideModal();
                await loadAll();
            } catch (err) {
                saveBtn.disabled = false;
                App.toast('Save failed: ' + (err && err.message ? err.message : 'unknown error'), 'error');
            }
        });

        App.showModal(itemId ? 'Edit watched item' : 'Watch an item', body, footer);
    }

    async function deleteItem(item) {
        var ok = await App.confirm({
            title: 'Stop watching',
            message: 'Remove "' + item.name + '" from the watchlist? This only deletes the tracking card — no sales data is touched.',
            confirmLabel: 'Remove'
        });
        if (!ok) return;
        try {
            await API.del('items/' + item.id);
            if (state && state.itemId === item.id) {
                state.itemId = null;
                var d = document.getElementById('items-detail');
                if (d) d.innerHTML = '';
            }
            App.toast('Removed from the watchlist.', 'success');
            loadAll();
        } catch (err) {
            App.toast('Delete failed: ' + (err && err.message ? err.message : 'unknown error'), 'error');
        }
    }

    // ------------------------------------------------------------------
    // Admin: editable queries + test (settings only)
    // ------------------------------------------------------------------
    async function loadAdmin() {
        var gen = App.navGeneration();
        try {
            var cfg = await API.get('items/settings');
            if (App.navGeneration() !== gen) return;
            renderAdmin(cfg);
        } catch (err) { /* settings not accessible — leave empty */ }
    }

    function renderAdmin(cfg) {
        var box = document.getElementById('items-admin');
        if (!box) return;
        box.innerHTML = '';

        var statusEl = App.el('span', { className: 'text-sm text-secondary', 'aria-live': 'polite' });
        var diag = App.el('pre', { className: 'labor-diagnostics', style: { display: 'none' } });

        var editors = [
            { key: 'range_sql',  label: 'Units & dollars by day and item (:from, :to, :invnos) — drives the cards and the trend' },
            { key: 'totals_sql', label: 'Period totals per item (:from, :to, :invnos) — previous-period comparison and since-launch totals' },
            { key: 'top_sql',    label: 'Best sellers (:from, :to, :rankexpr) — the leaderboard; :rankexpr is filled from the By-revenue/units/margin control' },
            { key: 'history_sql', label: 'Calendar-period history (:from, :to, :invnos, :periodexpr) — the "How it\'s tracking" table; :periodexpr is filled per grain' }
        ];
        var areas = {};
        var fields = editors.map(function(ed) {
            var ta = App.el('textarea', { className: 'form-input labor-sql', rows: 9, spellcheck: 'false' });
            ta.value = cfg[ed.key] || '';
            areas[ed.key] = ta;
            return App.el('div', { className: 'form-group' }, [
                App.el('label', { className: 'form-label', textContent: ed.label }),
                ta,
                App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Reset this one to default',
                    title: 'Fill the box with the shipped query. Nothing is saved until you press Save queries.',
                    onClick: function() {
                        if (cfg.defaults && cfg.defaults[ed.key]) {
                            ta.value = cfg.defaults[ed.key];
                            statusEl.textContent = 'Default restored — review, then Save queries.';
                        }
                    } })
            ]);
        });

        var saveBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Save queries',
            onClick: async function() {
                saveBtn.disabled = true;
                statusEl.textContent = 'Saving…';
                try {
                    await API.put('items/settings', {
                        range_sql: areas.range_sql.value,
                        totals_sql: areas.totals_sql.value,
                        top_sql: areas.top_sql.value,
                        history_sql: areas.history_sql.value
                    });
                    statusEl.textContent = 'Saved.';
                    App.toast('Item Watch queries saved.', 'success');
                    loadAll();
                } catch (err) {
                    statusEl.textContent = '';
                    App.toast('Save failed: ' + (err && err.message ? err.message : 'unknown error'), 'error');
                }
                saveBtn.disabled = false;
            } });

        var probeInv  = App.el('input', { className: 'form-input form-input-sm', type: 'text',
            id: 'items-probe-inv', placeholder: 'blank = top seller', style: { maxWidth: '11rem' },
            title: 'Which item to reconcile. Leave blank and the range\'s best seller is probed automatically.' });
        var probeFrom = App.el('input', { className: 'form-input form-input-sm', type: 'date', 'aria-label': 'Probe from (blank = 30 days ago)' });
        var probeTo   = App.el('input', { className: 'form-input form-input-sm', type: 'date', 'aria-label': 'Probe to (blank = today)' });
        var testBtn = App.el('button', { className: 'btn btn-secondary', textContent: 'Test & reconcile',
            onClick: async function() {
                testBtn.disabled = true;
                statusEl.textContent = 'Testing…';
                diag.style.display = ''; diag.textContent = 'Running…';
                try {
                    var body = {};
                    if (probeInv.value.trim()) body.inv_nos = probeInv.value.trim();
                    if (probeFrom.value) body.from = probeFrom.value;
                    if (probeTo.value) body.to = probeTo.value;
                    var r = await API.post('items/test', body);
                    diag.textContent = JSON.stringify(r, null, 2);
                    if (r.success) {
                        var msg = '✓ Connected via ' + r.driver + '.';
                        if (r.probe && !r.probe.error) {
                            msg += ' ' + fmtUnits(r.probe.qty) + ' units / ' + fmtMoney2(r.probe.amount)
                                + (r.probe.agrees ? ' — both queries agree.' : ' — ⚠ the two queries DISAGREE, check your edits.');
                        }
                        statusEl.textContent = msg;
                    } else {
                        statusEl.textContent = '✗ ' + r.error;
                    }
                } catch (err) {
                    statusEl.textContent = '✗ ' + (err && err.message ? err.message : 'test failed');
                    diag.textContent = 'Error: ' + (err && err.message ? err.message : 'unknown');
                }
                testBtn.disabled = false;
            } });

        var connNote = cfg.connection && cfg.connection.host
            ? 'Using the MSSQL connection configured on the Go-Kart Labor page (database: ' + (cfg.connection.database || 'CenterEdge') + ').'
            : 'No MSSQL connection yet — set it up on the Go-Kart Labor page first; this report shares it.';

        var canTest = App.canAccess('data_explorer');
        var probeRow = App.el('div', { className: 'items-probe-row' }, [
            App.el('label', { className: 'text-sm text-secondary', 'for': 'items-probe-inv', textContent: 'Probe InvNo' }),
            probeInv,
            App.el('span', { className: 'text-sm text-secondary', textContent: 'from' }), probeFrom,
            App.el('span', { className: 'text-sm text-secondary', textContent: 'to' }), probeTo
        ]);
        var controls = canTest
            ? [probeRow, saveBtn, testBtn, statusEl]
            : [saveBtn, statusEl];

        box.appendChild(App.el('details', { className: 'card labor-admin-details' }, [
            App.el('summary', { className: 'labor-admin-summary', textContent: '⚙️ Item Watch queries (admin setup)' }),
            App.el('div', { className: 'card-body' }, [
                App.el('p', { className: 'text-sm text-secondary', textContent: connNote })
            ].concat(fields).concat([
                App.el('p', { className: 'text-xs text-muted', textContent:
                    'Each must be a single read-only SELECT containing its placeholders. :invnos becomes a comma-separated list of the watched inventory numbers, so keep it inside an IN (…). :periodexpr and :rankexpr are filled from a fixed server-side list (never from the browser) — leave them in place to keep the grain and ranking controls working. Defaults read the POS Sales table grouped by InvNo.' }),
                App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', marginTop: '0.6rem', flexWrap: 'wrap' } }, controls),
                diag
            ]))
        ]));
    }
})();
