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

    function freshState() {
        return {
            range: 'week', offset: 0, custom: { from: '', to: '' },
            itemId: null, list: null, detail: null, top: null,
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
        var qs = 'range=' + encodeURIComponent(state.range) + '&offset=' + encodeURIComponent(state.offset);
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
        if (!box) return;
        box.innerHTML = '';
        var items = data.items || [];
        var money = moneyOK(data);

        if (items.length === 0) { box.appendChild(buildEmptyState()); return; }

        box.appendChild(buildSummaryStrip(data, money));

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
                s.prior_qty != null ? 'Previous period: ' + fmtUnits(s.prior_qty) + ' units' : 'No prior data'));
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
            deltaPill(s.qty_delta_pct, s.prior_qty != null ? 'Previous period: ' + fmtUnits(s.prior_qty) + ' units' : 'No prior data'),
            App.el('span', { className: 'text-muted text-sm', textContent:
                s.prior_qty != null ? 'vs ' + fmtUnits(s.prior_qty) + ' units last period' : 'no comparable previous period' })
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
        head.push(App.el('th', { scope: 'col', className: 'text-right', textContent: 'Prev units' }));

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
    // Best sellers
    // ------------------------------------------------------------------
    async function loadTop() {
        var box = document.getElementById('items-top');
        if (!box) return;
        if (customIncomplete()) return;
        var gen = ++state.genTop;
        try {
            var data = await API.get('items/top?' + windowQs());
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
        var rows = data.items || [];
        if (!rows.length) {
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-header' }, [App.el('h3', { textContent: 'Best sellers' })]),
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent: 'No sales recorded in this period.' })
                ])
            ]));
            return;
        }

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
        if (canManage()) head.push(App.el('th', { scope: 'col', 'aria-label': 'Watch this item', 'data-nosort': '' }));

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
            if (canManage()) {
                cells.push(App.el('td', {}, [
                    r.watch_id
                        ? App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Open',
                            onClick: function(e) { e.stopPropagation(); selectItem(r.watch_id); } })
                        : App.el('button', { className: 'btn btn-sm btn-secondary', textContent: '+ Watch',
                            onClick: function(e) { e.stopPropagation(); openEditor(null, { name: label, inv_nos: String(r.inv) }); } })
                ]));
            }
            return App.el('tr', {}, cells);
        }));

        var table = App.el('table', { className: 'data-table items-table' }, [
            App.el('thead', {}, [App.el('tr', {}, head)]), tbody
        ]);
        App.enhanceTableSort(table, { defaultSort: { index: 1, dir: 'desc' } });

        box.appendChild(App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('h3', { textContent: 'Best sellers' }),
                App.el('span', { className: 'text-muted text-sm', textContent: (data.window && data.window.label) || '' })
            ]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'table-scroll' }, [table]),
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' }, textContent:
                    'The top ' + rows.length + ' items by revenue for this period, from the POS Sales table (grouped by InvNo). '
                    + 'This is a business-day roll-up, so there is no hour-of-day here.' })
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
            { key: 'top_sql',    label: 'Best sellers (:from, :to) — the leaderboard, ranked by revenue' }
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
                        top_sql: areas.top_sql.value
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

        var probeInv  = App.el('input', { className: 'form-input form-input-sm', type: 'text', placeholder: 'InvNo(s), e.g. 7157', style: { maxWidth: '12rem' } });
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
        var controls = canTest
            ? [saveBtn, testBtn, probeInv, probeFrom, probeTo, statusEl]
            : [saveBtn, statusEl];

        box.appendChild(App.el('details', { className: 'card labor-admin-details' }, [
            App.el('summary', { className: 'labor-admin-summary', textContent: '⚙️ Item Watch queries (admin setup)' }),
            App.el('div', { className: 'card-body' }, [
                App.el('p', { className: 'text-sm text-secondary', textContent: connNote })
            ].concat(fields).concat([
                App.el('p', { className: 'text-xs text-muted', textContent:
                    'Each must be a single read-only SELECT containing its placeholders. :invnos is replaced with the watched inventory numbers as a comma-separated list, so keep it inside an IN (…). Defaults read the POS Sales table grouped by InvNo.' }),
                App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', marginTop: '0.6rem', flexWrap: 'wrap' } }, controls),
                diag
            ]))
        ]));
    }
})();
