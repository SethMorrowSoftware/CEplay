/**
 * Database Explorer — a read-only window into the venue's CenterEdge MSSQL
 * database (shares the Go-Kart Labor connection). Three tools:
 *
 *   1. Table browser: every table, its columns/types, how far back its
 *      history goes, and a sample of rows.
 *   2. Metric hunter: total one column grouped by another over a date
 *      range — the generalized probe that found the kart money (DivNo 808).
 *   3. Free SQL: any single SELECT, guarded read-only, capped rows.
 *
 * Admin-only (settings permission); every query runs with the configured
 * SQL login's privileges and is audit-logged server-side.
 */
(function() {
    'use strict';

    var NUMERIC_TYPES = ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'money', 'smallmoney', 'float', 'real'];
    var DATE_TYPES = ['date', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset'];

    var state = {
        tables: [],
        filter: '',
        selected: null,   // table name
        detail: null,     // /explorer/table payload
        lastQuery: null   // {columns, rows} for CSV download
    };

    function isoDaysAgo(n) {
        var d = new Date();
        d.setDate(d.getDate() - n);
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    // ------------------------------------------------------------------
    async function renderExplorer(container) {
        state.selected = null;
        state.detail = null;
        state.lastQuery = null;

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Database Explorer' }),
                App.el('p', { className: 'page-subtitle', textContent:
                    'A read-only window into the CenterEdge database — find where a number lives, then wire it into a report.' })
            ])
        ]));

        container.appendChild(App.el('div', { id: 'explorer-body' }, [App.loading()]));
        loadTables();
    }

    async function loadTables() {
        var box = document.getElementById('explorer-body');
        if (!box) return;
        try {
            var data = await API.get('explorer/tables');
            box.innerHTML = '';
            if (!data.configured) {
                box.appendChild(App.el('div', { className: 'card' }, [
                    App.el('div', { className: 'card-body' }, [
                        App.el('h3', { textContent: 'Not connected yet' }),
                        App.el('p', { className: 'text-secondary', style: { marginTop: '0.4rem' }, textContent:
                            'The explorer uses the MSSQL connection configured on the Go-Kart Labor page (Connection & queries). Set it up there first.' })
                    ])
                ]));
                return;
            }
            if (data.error) {
                box.appendChild(App.el('div', { className: 'card' }, [
                    App.el('div', { className: 'card-body' }, [
                        App.el('p', { className: 'text-secondary', textContent: '⚠ ' + data.error })
                    ])
                ]));
                return;
            }
            state.tables = data.tables || [];
            buildWorkbench(box);
        } catch (err) {
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'card' }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-secondary', textContent: 'Could not list tables: ' + (err && err.message ? err.message : 'unknown error') })
                ])
            ]));
        }
    }

    // ------------------------------------------------------------------
    // Workbench layout
    // ------------------------------------------------------------------
    function buildWorkbench(box) {
        var grid = App.el('div', { className: 'explorer-grid' });

        // ---- Table list ----
        var searchIn = App.el('input', { className: 'form-input form-input-sm', placeholder: 'Filter tables…',
            'aria-label': 'Filter tables' });
        searchIn.addEventListener('input', function() {
            state.filter = searchIn.value.trim().toLowerCase();
            renderTableList();
        });
        var listEl = App.el('div', { className: 'explorer-table-list', id: 'explorer-table-list' });
        grid.appendChild(App.el('div', { className: 'card explorer-list-card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', {}, [
                    App.el('h3', { textContent: 'Tables' }),
                    App.el('div', { className: 'text-muted text-sm', textContent: state.tables.length + ' in the database' })
                ])
            ]),
            App.el('div', { className: 'card-body' }, [searchIn, listEl])
        ]));

        // ---- Detail panel ----
        grid.appendChild(App.el('div', { className: 'card explorer-detail-card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('h3', { id: 'explorer-detail-title', textContent: 'Pick a table' })
            ]),
            App.el('div', { className: 'card-body', id: 'explorer-detail' }, [
                App.el('p', { className: 'text-secondary text-sm', textContent:
                    'Click a table to see its columns, how far back its data goes, and a sample of rows.' })
            ])
        ]));
        box.appendChild(grid);

        // ---- Metric hunter ----
        box.appendChild(buildHunterCard());

        // ---- Free SQL ----
        box.appendChild(buildSqlCard());

        renderTableList();
    }

    function renderTableList() {
        var listEl = document.getElementById('explorer-table-list');
        if (!listEl) return;
        listEl.innerHTML = '';
        var shown = state.tables.filter(function(t) {
            return !state.filter || t.name.toLowerCase().indexOf(state.filter) !== -1;
        });
        if (!shown.length) {
            listEl.appendChild(App.el('p', { className: 'text-muted text-sm', style: { marginTop: '0.5rem' }, textContent: 'No tables match.' }));
            return;
        }
        shown.forEach(function(t) {
            listEl.appendChild(App.el('button', {
                className: 'explorer-table-item' + (state.selected === t.name ? ' active' : ''),
                onClick: function() { selectTable(t.name); }
            }, [
                App.el('span', { className: 'explorer-table-name', textContent: t.name }),
                App.el('span', { className: 'explorer-table-rows', textContent: t.rows != null ? t.rows.toLocaleString() : '' })
            ]));
        });
    }

    async function selectTable(name) {
        state.selected = name;
        renderTableList();
        var detail = document.getElementById('explorer-detail');
        var title = document.getElementById('explorer-detail-title');
        if (!detail) return;
        if (title) title.textContent = name;
        detail.innerHTML = '';
        detail.appendChild(App.loading());
        try {
            var d = await API.get('explorer/table?name=' + encodeURIComponent(name));
            if (state.selected !== name) return; // clicked away meanwhile
            state.detail = d;
            renderDetail(detail, d);
            syncHunterToDetail();
        } catch (err) {
            detail.innerHTML = '';
            detail.appendChild(App.el('p', { className: 'text-secondary', textContent: '⚠ ' + (err && err.message ? err.message : 'failed to load table') }));
        }
    }

    function renderDetail(detail, d) {
        detail.innerHTML = '';
        if (d.error) {
            detail.appendChild(App.el('p', { className: 'text-secondary', textContent: '⚠ ' + d.error }));
            return;
        }

        // Freshness: how deep and how fresh each date column runs.
        (d.freshness || []).forEach(function(f) {
            detail.appendChild(App.el('p', { className: 'text-sm text-secondary', style: { margin: '0 0 0.35rem' } }, [
                App.el('strong', { textContent: f.column }),
                App.el('span', { textContent: ': ' + (f.min || '—') + '  →  ' + (f.max || '—') })
            ]));
        });

        // Columns
        var colTable = App.el('table', { className: 'data-table explorer-cols' }, [
            App.el('thead', {}, [App.el('tr', {}, [
                App.el('th', { textContent: 'Column' }),
                App.el('th', { textContent: 'Type' }),
                App.el('th', { textContent: 'Nullable' })
            ])]),
            App.el('tbody', {}, (d.columns || []).map(function(c) {
                return App.el('tr', {}, [
                    App.el('td', { textContent: c.name }),
                    App.el('td', {}, [App.el('code', { textContent: c.type })]),
                    App.el('td', { textContent: c.nullable ? 'yes' : '' })
                ]);
            }))
        ]);
        detail.appendChild(App.el('div', { className: 'explorer-scroll' }, [colTable]));

        // Sample rows
        if (d.sample && d.sample.length) {
            detail.appendChild(App.el('div', { className: 'stat-label', style: { margin: '0.9rem 0 0.4rem' },
                textContent: 'Sample rows (' + d.sample.length + ')' }));
            detail.appendChild(App.el('div', { className: 'explorer-scroll' }, [
                buildResultTable(d.sample_columns || [], d.sample)
            ]));
        } else {
            detail.appendChild(App.el('p', { className: 'text-muted text-sm', style: { marginTop: '0.75rem' },
                textContent: 'No sample rows available.' }));
        }
    }

    // ------------------------------------------------------------------
    // Metric hunter
    // ------------------------------------------------------------------
    var hunter = {};

    function buildHunterCard() {
        hunter.tableSel = App.el('select', { className: 'form-input' });
        hunter.groupSel = App.el('select', { className: 'form-input' });
        hunter.sumSel   = App.el('select', { className: 'form-input' });
        hunter.dateSel  = App.el('select', { className: 'form-input' });
        hunter.fromIn   = App.el('input', { type: 'date', className: 'form-input', value: isoDaysAgo(1) });
        hunter.toIn     = App.el('input', { type: 'date', className: 'form-input', value: isoDaysAgo(1) });
        hunter.status   = App.el('span', { className: 'text-sm text-secondary' });
        hunter.results  = App.el('div', {});

        hunter.tableSel.appendChild(App.el('option', { value: '', textContent: '— pick a table —' }));
        state.tables.forEach(function(t) {
            hunter.tableSel.appendChild(App.el('option', { value: t.name, textContent: t.name }));
        });
        hunter.tableSel.addEventListener('change', function() {
            if (hunter.tableSel.value && hunter.tableSel.value !== state.selected) {
                selectTable(hunter.tableSel.value);
            }
        });

        var runBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Run',
            onClick: runHunter });

        return App.el('div', { className: 'card', style: { marginTop: '1rem' } }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', {}, [
                    App.el('h3', { textContent: 'Find a metric' }),
                    App.el('div', { className: 'text-muted text-sm', textContent:
                        'Total one column grouped by another over a date range — the probe that found the kart money under DivNo 808.' })
                ])
            ]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'explorer-hunter-grid' }, [
                    field('Table', hunter.tableSel),
                    field('Group by', hunter.groupSel),
                    field('Sum (blank = just count)', hunter.sumSel),
                    field('Date column', hunter.dateSel),
                    field('From', hunter.fromIn),
                    field('To', hunter.toIn)
                ]),
                App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', marginTop: '0.6rem' } }, [runBtn, hunter.status]),
                hunter.results
            ])
        ]);
    }

    function syncHunterToDetail() {
        if (!state.detail || !state.detail.columns) return;
        if (hunter.tableSel.value !== state.selected) hunter.tableSel.value = state.selected;

        var cols = state.detail.columns;
        fillSelect(hunter.groupSel, cols.map(function(c) { return c.name; }), null);
        fillSelect(hunter.sumSel, cols.filter(function(c) { return NUMERIC_TYPES.indexOf(c.type) !== -1; })
            .map(function(c) { return c.name; }), '— just count rows —');
        fillSelect(hunter.dateSel, cols.filter(function(c) { return DATE_TYPES.indexOf(c.type) !== -1; })
            .map(function(c) { return c.name; }), '— no date filter —');
        // Pre-select the first date column: nearly every metric hunt is
        // "for this day/range".
        if (hunter.dateSel.options.length > 1) hunter.dateSel.selectedIndex = 1;
    }

    function fillSelect(sel, names, blankLabel) {
        sel.innerHTML = '';
        if (blankLabel) sel.appendChild(App.el('option', { value: '', textContent: blankLabel }));
        names.forEach(function(n) {
            sel.appendChild(App.el('option', { value: n, textContent: n }));
        });
    }

    async function runHunter() {
        if (!hunter.tableSel.value) { App.toast('Pick a table first.', 'warning'); return; }
        if (!hunter.groupSel.value) { App.toast('Pick a group-by column.', 'warning'); return; }
        hunter.status.textContent = 'Running…';
        hunter.results.innerHTML = '';
        try {
            var payload = {
                table: hunter.tableSel.value,
                group_by: hunter.groupSel.value
            };
            if (hunter.sumSel.value) payload.sum_col = hunter.sumSel.value;
            if (hunter.dateSel.value) {
                payload.date_col = hunter.dateSel.value;
                payload.from = hunter.fromIn.value;
                payload.to = hunter.toIn.value;
            }
            var r = await API.post('explorer/aggregate', payload);
            if (r.error) { hunter.status.textContent = '✗ ' + r.error; return; }
            hunter.status.textContent = (r.rows || []).length + ' groups · ' + (r.elapsed_ms || 0) + ' ms';
            var head = [App.el('th', { textContent: hunter.groupSel.value }), App.el('th', { textContent: 'Rows' })];
            if (r.has_total) head.push(App.el('th', { textContent: 'Total' }));
            var tbody = App.el('tbody', {}, (r.rows || []).map(function(row) {
                var cells = [
                    App.el('td', { textContent: row.grp === null ? 'NULL' : String(row.grp) }),
                    App.el('td', { textContent: Number(row.lines).toLocaleString() })
                ];
                if (r.has_total) cells.push(App.el('td', { textContent: Number(row.total).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }));
                return App.el('tr', {}, cells);
            }));
            hunter.results.appendChild(App.el('div', { className: 'explorer-scroll', style: { marginTop: '0.6rem' } }, [
                App.el('table', { className: 'data-table' }, [App.el('thead', {}, [App.el('tr', {}, head)]), tbody])
            ]));
            hunter.results.appendChild(App.el('pre', { className: 'labor-diagnostics', style: { marginTop: '0.5rem' }, textContent: r.sql || '' }));
        } catch (err) {
            hunter.status.textContent = '✗ ' + (err && err.message ? err.message : 'failed');
        }
    }

    // ------------------------------------------------------------------
    // Free SQL
    // ------------------------------------------------------------------
    function buildSqlCard() {
        var ta = App.el('textarea', { className: 'form-input labor-sql', rows: 6,
            placeholder: "SELECT TOP 25 * FROM CenterEdge.dbo.Sales WHERE ShiftDate >= '2026-07-01' ORDER BY ShiftDate DESC" });
        var limitIn = App.el('input', { className: 'form-input', type: 'number', min: '1', max: '500', value: '100',
            style: { maxWidth: '7rem' }, title: 'Max rows returned (500 cap)' });
        var status = App.el('span', { className: 'text-sm text-secondary' });
        var results = App.el('div', {});
        var csvBtn = App.el('button', { className: 'btn btn-ghost btn-sm', textContent: 'Download CSV', style: { display: 'none' },
            onClick: function() { downloadCsv(); } });

        var runBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Run query',
            onClick: async function() {
                var sql = ta.value.trim();
                if (!sql) { App.toast('Type a SELECT first.', 'warning'); return; }
                status.textContent = 'Running…';
                results.innerHTML = '';
                csvBtn.style.display = 'none';
                state.lastQuery = null;
                try {
                    var r = await API.post('explorer/query', { sql: sql, limit: parseInt(limitIn.value, 10) || 100 });
                    if (r.error) { status.textContent = '✗ ' + r.error; return; }
                    status.textContent = r.row_count + ' rows' + (r.truncated ? ' (capped — raise the limit or narrow the query)' : '') + ' · ' + (r.elapsed_ms || 0) + ' ms';
                    state.lastQuery = { columns: r.columns || [], rows: r.rows || [] };
                    if (r.row_count > 0) {
                        results.appendChild(App.el('div', { className: 'explorer-scroll', style: { marginTop: '0.6rem' } }, [
                            buildResultTable(r.columns || [], r.rows || [])
                        ]));
                        csvBtn.style.display = '';
                    }
                } catch (err) {
                    status.textContent = '✗ ' + (err && err.message ? err.message : 'query failed');
                }
            } });

        return App.el('div', { className: 'card', style: { marginTop: '1rem' } }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', {}, [
                    App.el('h3', { textContent: 'Run a SELECT' }),
                    App.el('div', { className: 'text-muted text-sm', textContent:
                        'Single SELECT only, read-only, capped rows. Runs with the configured SQL login and is audit-logged.' })
                ])
            ]),
            App.el('div', { className: 'card-body' }, [
                ta,
                App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', marginTop: '0.6rem', flexWrap: 'wrap' } }, [
                    runBtn, App.el('label', { className: 'text-sm text-secondary', textContent: 'Row cap' }), limitIn, csvBtn, status
                ]),
                results
            ])
        ]);
    }

    function buildResultTable(columns, rows) {
        return App.el('table', { className: 'data-table explorer-results' }, [
            App.el('thead', {}, [App.el('tr', {}, columns.map(function(c) {
                return App.el('th', { textContent: c });
            }))]),
            App.el('tbody', {}, rows.map(function(r) {
                return App.el('tr', {}, r.map(function(v) {
                    return App.el('td', { textContent: v === null ? 'NULL' : String(v) });
                }));
            }))
        ]);
    }

    function downloadCsv() {
        if (!state.lastQuery) return;
        var esc = function(v) {
            var s = v === null || v === undefined ? '' : String(v);
            return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
        };
        var lines = [state.lastQuery.columns.map(esc).join(',')];
        state.lastQuery.rows.forEach(function(r) { lines.push(r.map(esc).join(',')); });
        var blob = new Blob([lines.join('\n')], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'explorer-results.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(function() { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    function field(label, el) {
        return App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: label }), el
        ]);
    }

    App.registerRoute('#/explorer', { render: renderExplorer });
})();
