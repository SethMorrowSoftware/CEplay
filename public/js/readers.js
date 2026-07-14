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
        data: null,           // last list payload
        detail: null,         // last detail payload
        charts: [],
        themeObserver: null,
        genList: 0,
        genDetail: 0
    };

    var RANGE_LABELS = { day: 'Day', week: 'Week', month: 'Month', year: 'Year', custom: 'Custom' };
    var DOW_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    function canManage() { return App.canAccess('groups_manage'); }

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
            App.el('div', { className: 'card-body perf-controls-body' }, [presetRow, nav, custom])
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
            { label: 'Avg/day', cls: 'text-right' },
            { label: 'Avg/game/day', cls: 'text-right' }
        ];
        if (money) headers.push({ label: 'Revenue', cls: 'text-right' });
        headers.push({ label: 'Busiest time', cls: '' });
        headers.push({ label: 'vs prev', cls: 'text-right' });
        if (canManage()) headers.push({ label: '', cls: 'text-right' });

        var thead = App.el('thead', {}, [
            App.el('tr', {}, headers.map(function(h) {
                return App.el('th', { className: h.cls, textContent: h.label });
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
            cells.push(App.el('td', {}, [
                g.busiest
                    ? App.el('span', { className: 'rg-busiest-chip',
                        textContent: g.busiest.label,
                        title: 'Averages ' + formatNum(g.busiest.avg_plays) + ' plays that hour (' + formatInt(g.busiest.plays) + ' total in this period)' })
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

        if (state.data.hourly_full_coverage === false && state.data.hourly_covered_from) {
            el.appendChild(App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem' } , textContent:
                'Busiest-time detail uses hour-by-hour history collected since ' + state.data.hourly_covered_from +
                '; totals and averages cover the whole period.' }));
        }
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
            App.el('div', { className: 'card-body' }, [buildKpiGrid(t, p, money)])
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
        if (money) cards.push(['Revenue', formatCurrency(t.cash), delta(t.cash, p.cash)]);
        cards.push(['Time-pass plays', formatInt(t.time_plays), null]);
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
    // Heatmap
    // ------------------------------------------------------------------
    function buildHeatmapCard(d) {
        var toggle = App.el('div', { className: 'perf-metric-toggle' },
            [['avg', 'Typical'], ['total', 'Total']].map(function(m) {
                return App.el('button', {
                    className: 'btn btn-sm ' + (m[0] === state.heatMetric ? 'btn-secondary' : 'btn-ghost'),
                    textContent: m[1],
                    title: m[0] === 'avg'
                        ? 'Average plays per hour for each weekday across the period — the staffing view'
                        : 'Total plays per hour cell across the whole period',
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
            body.push(grid.legendTop);
            body.push(grid.el);
        } else {
            body.push(App.emptyState('🕐', 'No hour-by-hour play data for this period yet.'));
        }

        if (d.busiest && d.busiest.length > 0) {
            body.push(App.el('div', { className: 'rg-busiest-row' },
                [App.el('span', { className: 'text-sm text-secondary', textContent: 'Busiest times:' })].concat(
                    d.busiest.map(function(b) {
                        return App.el('span', { className: 'rg-busiest-chip',
                            textContent: b.label + ' · ' + formatNum(b.avg_plays) + '/hr',
                            title: formatInt(b.plays) + ' plays total in this period' });
                    })
                )
            ));
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
                        textContent: state.heatMetric === 'avg' ? 'typical plays per hour, by weekday' : 'total plays per hour, by weekday' })
                ]),
                toggle
            ]),
            App.el('div', { className: 'card-body' }, body)
        ]);
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

        var gridEl = App.el('div', {
            className: 'rg-heatmap',
            style: { gridTemplateColumns: 'auto repeat(' + hours.length + ', minmax(0, 1fr))' },
            role: 'img',
            'aria-label': 'Heatmap of plays by weekday and hour'
        });

        // Header row: hour labels (sparse when narrow — every other label).
        gridEl.appendChild(App.el('div', { className: 'rg-heat-corner' }));
        hours.forEach(function(h, idx) {
            gridEl.appendChild(App.el('div', {
                className: 'rg-heat-hour',
                textContent: (hours.length > 14 && idx % 2 === 1) ? '' : hourLabel(h)
            }));
        });

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
                    cellStyle.background = heatColor(theme, Math.sqrt(intensity));
                }
                var n = Number((heat.dow_counts || [])[dw]) || 0;
                var tip = DOW_SHORT[dw] + ' ' + hourLabel(h2) + '–' + hourLabel((h2 + 1) % 24) + ': ';
                if (state.heatMetric === 'avg') {
                    tip += formatNum(val) + ' plays on a typical ' + DOW_SHORT[dw] +
                        ' (' + formatInt(totalVal) + ' total across ' + n + ' ' + DOW_SHORT[dw] + (n === 1 ? '' : 's') + ')';
                } else {
                    tip += formatInt(val) + ' plays total' + (n > 0 ? ' across ' + n + ' ' + DOW_SHORT[dw] + (n === 1 ? '' : 's') : '');
                }
                gridEl.appendChild(App.el('div', {
                    className: 'rg-heat-cell' + (val > 0 ? '' : ' rg-heat-cell-empty'),
                    style: cellStyle,
                    title: tip
                }));
            }
        }

        // Legend: low → high gradient swatches.
        var legendTop = App.el('div', { className: 'rg-heat-legend' }, [
            App.el('span', { className: 'text-xs text-muted', textContent: 'Quiet' }),
            App.el('span', { className: 'rg-heat-legend-swatches' }, [0.15, 0.35, 0.6, 1].map(function(s) {
                return App.el('span', { className: 'rg-heat-legend-swatch', style: { background: heatColor(theme, Math.sqrt(s)) } });
            })),
            App.el('span', { className: 'text-xs text-muted', textContent: 'Busy — peak ' +
                (state.heatMetric === 'avg' ? formatNum(maxVal) + ' plays/hr' : formatInt(maxVal) + ' plays') })
        ]);

        return { el: gridEl, legendTop: legendTop };
    }

    /** Accent-tinted cell color; alpha carries the intensity. */
    function heatColor(theme, intensity) {
        var a = 0.08 + 0.88 * Math.max(0, Math.min(1, intensity));
        return 'rgba(' + theme.accentRgb + ',' + a.toFixed(3) + ')';
    }

    // ------------------------------------------------------------------
    // Trend chart
    // ------------------------------------------------------------------
    function buildMetricToggle() {
        var metrics = [['plays', 'Plays'], ['tickets', 'Tickets']];
        if (App.canSeeMoney()) metrics.push(['cash', 'Revenue']);
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
                    label: metric === 'plays' ? 'Plays' : (metric === 'cash' ? 'Revenue' : 'Tickets'),
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
            if (money) headers.push({ label: 'Revenue', cls: 'text-right' });
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
