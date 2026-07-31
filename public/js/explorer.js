/**
 * Database Explorer — a read-only window into the venue's CenterEdge MSSQL
 * database (shares the Go-Kart Labor connection). Three tools:
 *
 *   1. Table browser: search by table name OR by column name (the schema-
 *      hunting move: "which tables have an Hour column?"), sortable by
 *      size, with per-table columns/types, history depth, and the newest
 *      sample rows.
 *   2. Metric hunter: total one column grouped by another over a date
 *      range — the generalized probe that found the kart money (DivNo
 *      808). Click any column in the detail panel to see its top values.
 *   3. Free SQL: any single SELECT, guarded read-only, capped rows, with
 *      a recall-able history and CSV export.
 *
 * Admin-only (settings permission); every query runs with the configured
 * SQL login's privileges and is audit-logged server-side.
 */
(function() {
    'use strict';

    var NUMERIC_TYPES = ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'numeric', 'money', 'smallmoney', 'float', 'real'];
    var DATE_TYPES = ['date', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset'];
    var HISTORY_KEY = 'explorer_sql_history';
    var HISTORY_MAX = 10;

    var state = {
        tables: [],
        filter: '',
        sort: 'name',       // 'name' | 'rows'
        showEmpty: false,   // zero-row tables are noise while metric hunting
        columnHits: null,   // /explorer/search payload for the current filter
        searchSeq: 0,       // debounce/out-of-order guard for column search
        selected: null,     // table name
        detail: null,       // /explorer/table payload
        lastQuery: null     // {columns, rows} for CSV download
    };

    /**
     * Tables worth showing: anything with rows, plus anything whose count
     * is UNKNOWN (sys.partitions unreadable) — only provably-empty tables
     * hide, and the toggle brings them back.
     */
    function visibleTables() {
        return state.tables.filter(function(t) {
            return state.showEmpty || t.rows === null || t.rows > 0;
        });
    }

    function emptyCount() {
        return state.tables.filter(function(t) { return t.rows === 0; }).length;
    }

    function sortTables(list) {
        var out = list.slice();
        if (state.sort === 'rows') {
            out.sort(function(a, b) { return (b.rows || 0) - (a.rows || 0) || a.name.localeCompare(b.name); });
        } else {
            out.sort(function(a, b) { return a.name.localeCompare(b.name); });
        }
        return out;
    }

    function isoDaysAgo(n) {
        var d = new Date();
        d.setDate(d.getDate() - n);
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function fmtCount(n) {
        if (n == null) return '';
        if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (n >= 10000) return Math.round(n / 1000) + 'k';
        return n.toLocaleString();
    }

    // ------------------------------------------------------------------
    async function renderExplorer(container) {
        state.selected = null;
        state.detail = null;
        state.lastQuery = null;
        state.filter = '';
        state.columnHits = null;

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
        var searchIn = App.el('input', { className: 'form-input form-input-sm',
            placeholder: 'Search tables & columns…',
            'aria-label': 'Search tables and columns' });
        var searchTimer = null;
        searchIn.addEventListener('input', function() {
            state.filter = searchIn.value.trim().toLowerCase();
            renderTableList();          // table-name matches update instantly
            if (searchTimer) clearTimeout(searchTimer);
            var term = state.filter;
            if (term.length < 2) { state.columnHits = null; renderTableList(); return; }
            searchTimer = setTimeout(function() { runColumnSearch(term); }, 350);
        });

        // Sort: alphabetical, or biggest tables first (data lives in big tables).
        var sortWrap = App.el('div', { className: 'explorer-sort', id: 'explorer-sort' }, [
            sortBtn('name', 'A–Z'),
            sortBtn('rows', 'Biggest')
        ]);

        var listEl = App.el('div', { className: 'explorer-table-list', id: 'explorer-table-list' });
        var countEl = App.el('div', { className: 'text-muted text-sm', id: 'explorer-table-count' });
        var listChildren = [
            App.el('div', { className: 'explorer-list-controls' }, [searchIn, sortWrap])
        ];
        // Only offer the toggle when there are provably-empty tables to hide.
        if (emptyCount() > 0) {
            var emptyCb = App.el('input', { type: 'checkbox', checked: state.showEmpty });
            emptyCb.addEventListener('change', function() {
                state.showEmpty = emptyCb.checked;
                renderTableList();
                rebuildHunterTables();
            });
            listChildren.push(App.el('label', { className: 'text-sm text-secondary explorer-empty-toggle' }, [
                emptyCb,
                App.el('span', { textContent: ' Show empty tables' })
            ]));
        }
        listChildren.push(listEl);
        grid.appendChild(App.el('div', { className: 'card explorer-list-card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', {}, [
                    App.el('h3', { textContent: 'Tables' }),
                    countEl
                ])
            ]),
            App.el('div', { className: 'card-body' }, listChildren)
        ]));

        // ---- Detail panel ----
        grid.appendChild(App.el('div', { className: 'card explorer-detail-card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('h3', { id: 'explorer-detail-title', textContent: 'Pick a table' })
            ]),
            App.el('div', { className: 'card-body', id: 'explorer-detail' }, [
                App.el('p', { className: 'text-secondary text-sm', textContent:
                    'Click a table to see its columns, how far back its data goes, and its newest rows. Type in the search box to find tables by name or by column (e.g. "hour", "amt", "ticket").' })
            ])
        ]));
        box.appendChild(grid);

        // ---- How far back can per-game history reach? ----
        box.appendChild(buildHistoryCard());

        // ---- Metric hunter ----
        box.appendChild(buildHunterCard());

        // ---- Free SQL ----
        box.appendChild(buildSqlCard());

        renderTableList();
    }

    function sortBtn(key, label) {
        return App.el('button', {
            className: 'btn btn-sm ' + (state.sort === key ? 'btn-secondary' : 'btn-ghost'),
            textContent: label,
            onClick: function() {
                if (state.sort === key) return;
                state.sort = key;
                var wrap = document.getElementById('explorer-sort');
                if (wrap) Array.prototype.forEach.call(wrap.children, function(btn) {
                    btn.className = 'btn btn-sm ' + (btn.textContent === label ? 'btn-secondary' : 'btn-ghost');
                });
                renderTableList();
            }
        });
    }

    async function runColumnSearch(term) {
        var seq = ++state.searchSeq;
        try {
            var data = await API.get('explorer/search?q=' + encodeURIComponent(term));
            if (seq !== state.searchSeq || state.filter !== term) return; // superseded
            state.columnHits = data;
            renderTableList();
        } catch (err) {
            if (seq !== state.searchSeq) return;
            state.columnHits = { matches: [], error: err && err.message ? err.message : 'search failed' };
            renderTableList();
        }
    }

    function tableRowEl(t, matchedCols) {
        var children = [
            App.el('span', { className: 'explorer-table-name', textContent: t.name }),
            App.el('span', { className: 'explorer-table-rows',
                textContent: fmtCount(t.rows) + (t.cols != null ? ' · ' + t.cols + ' col' + (t.cols !== 1 ? 's' : '') : '') })
        ];
        var item = App.el('button', {
            className: 'explorer-table-item' + (state.selected === t.name ? ' active' : ''),
            onClick: function() { selectTable(t.name); }
        }, [App.el('span', { className: 'explorer-table-main' }, children)]);
        if (matchedCols && matchedCols.length) {
            item.appendChild(App.el('span', { className: 'explorer-table-hitcols',
                textContent: matchedCols.map(function(c) { return c.name; }).join(', ') }));
        }
        return item;
    }

    function renderTableList() {
        var listEl = document.getElementById('explorer-table-list');
        if (!listEl) return;
        listEl.innerHTML = '';
        var pool = visibleTables();
        var countEl = document.getElementById('explorer-table-count');
        if (countEl) {
            var hidden = state.showEmpty ? 0 : emptyCount();
            countEl.textContent = pool.length + ' with data'
                + (hidden > 0 ? ' · ' + hidden + ' empty hidden' : '');
        }

        var nameMatches = sortTables(pool.filter(function(t) {
            return !state.filter || t.name.toLowerCase().indexOf(state.filter) !== -1;
        }));

        // Column hits: tables whose COLUMNS match the term (from the server),
        // minus the ones already shown above by name.
        var colSection = [];
        if (state.filter.length >= 2 && state.columnHits) {
            var shownNames = {};
            nameMatches.forEach(function(t) { shownNames[t.name.toLowerCase()] = true; });
            var poolByName = {};
            pool.forEach(function(t) { poolByName[t.name.toLowerCase()] = t; });
            (state.columnHits.matches || []).forEach(function(m) {
                var key = m.table.toLowerCase();
                if (shownNames[key]) return;
                var t = poolByName[key];
                if (!t) return; // hidden (empty) or unknown table
                colSection.push({ t: t, cols: m.columns || [] });
            });
        }

        if (!nameMatches.length && !colSection.length) {
            var msg = state.filter.length >= 2 && !state.columnHits
                ? 'Searching columns…'
                : 'No tables or columns match.';
            listEl.appendChild(App.el('p', { className: 'text-muted text-sm', style: { marginTop: '0.5rem' }, textContent: msg }));
            return;
        }

        if (state.filter && colSection.length && nameMatches.length) {
            listEl.appendChild(App.el('div', { className: 'explorer-list-label', textContent: 'Table names' }));
        }
        nameMatches.forEach(function(t) { listEl.appendChild(tableRowEl(t, null)); });

        if (colSection.length) {
            listEl.appendChild(App.el('div', { className: 'explorer-list-label',
                textContent: 'Tables with matching columns' }));
            colSection.forEach(function(hit) { listEl.appendChild(tableRowEl(hit.t, hit.cols)); });
        }
        if (state.filter.length >= 2 && state.columnHits && state.columnHits.truncated) {
            listEl.appendChild(App.el('p', { className: 'text-muted text-xs', style: { marginTop: '0.4rem' },
                textContent: 'Column search capped — refine the term for full coverage.' }));
        }
        if (state.filter.length >= 2 && state.columnHits && state.columnHits.error) {
            listEl.appendChild(App.el('p', { className: 'text-muted text-xs', style: { marginTop: '0.4rem' },
                textContent: 'Column search unavailable: ' + state.columnHits.error }));
        }
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

        // Columns — click one to see its top values in the hunter below.
        detail.appendChild(App.el('div', { className: 'text-muted text-xs', style: { margin: '0.25rem 0 0.35rem' },
            textContent: 'Click a column to see its most common values.' }));
        var colTable = App.el('table', { className: 'data-table explorer-cols' }, [
            App.el('thead', {}, [App.el('tr', {}, [
                App.el('th', { textContent: 'Column' }),
                App.el('th', { textContent: 'Type' }),
                App.el('th', { textContent: 'Nullable' })
            ])]),
            App.el('tbody', {}, (d.columns || []).map(function(c) {
                return App.el('tr', {
                    className: 'explorer-col-row',
                    title: 'Show top values of ' + c.name,
                    onClick: function() { topValuesFor(c.name); }
                }, [
                    App.el('td', { textContent: c.name }),
                    App.el('td', {}, [App.el('code', { textContent: c.type })]),
                    App.el('td', { textContent: c.nullable ? 'yes' : '' })
                ]);
            }))
        ]);
        detail.appendChild(App.el('div', { className: 'explorer-scroll' }, [colTable]));

        // Sample rows — newest first when the server could sort them.
        if (d.sample && d.sample.length) {
            detail.appendChild(App.el('div', { className: 'stat-label', style: { margin: '0.9rem 0 0.4rem' },
                textContent: 'Sample rows (' + d.sample.length
                    + (d.sample_order ? ' · newest first by ' + d.sample_order : '') + ')' }));
            detail.appendChild(App.el('div', { className: 'explorer-scroll' }, [
                buildResultTable(d.sample_columns || [], d.sample)
            ]));
        } else {
            detail.appendChild(App.el('p', { className: 'text-muted text-sm', style: { marginTop: '0.75rem' },
                textContent: 'No sample rows available.' }));
        }
    }

    /** Column click → count-only aggregate on that column, run immediately. */
    function topValuesFor(colName) {
        if (!state.selected) return;
        if (hunter.tableSel.value !== state.selected) {
            hunter.tableSel.value = state.selected;
        }
        syncHunterToDetail();
        hunter.groupSel.value = colName;
        hunter.sumSel.value = '';
        hunter.dateSel.value = '';
        runHunter();
        var card = document.getElementById('explorer-hunter-card');
        if (card && card.scrollIntoView) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ------------------------------------------------------------------
    // Metric hunter
    // ------------------------------------------------------------------
    var hunter = {};

    // ------------------------------------------------------------------
    // "How far back can per-game history reach?"
    //
    // Performance attributes a play to a game through a reader key. That works
    // for the current CenterEdge/Kiosoft readers, but the venue ran Embed
    // before ~May 2026 and PlayerCardTrans.rdrkey is 0 from ~2013 on. This card
    // asks the live database whether ANY table carries a usable reader key on
    // pre-cutover plays, and whether those keys still resolve to today's games
    // — the two facts that decide if Embed-era history is recoverable.
    // ------------------------------------------------------------------
    function buildHistoryCard() {
        var status = App.el('span', { className: 'text-sm text-secondary' });
        var results = App.el('div', {});
        var runBtn = App.el('button', {
            className: 'btn btn-primary', textContent: 'Check history sources',
            onClick: function() { runHistoryProbe(runBtn, status, results); }
        });

        return App.el('div', { className: 'card', id: 'explorer-history-card', style: { marginTop: '1rem' } }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', {}, [
                    App.el('h3', { textContent: 'How far back can per-game history reach?' }),
                    App.el('div', { className: 'text-muted text-sm', textContent:
                        'Finds which years of play history can still be attributed to a machine, and whether '
                        + 'those reader keys resolve to today’s games. Read-only; one grouped pass per table.' })
                ])
            ]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center' } }, [runBtn, status]),
                results
            ])
        ]);
    }

    async function runHistoryProbe(btn, status, results) {
        btn.disabled = true;
        status.textContent = 'Probing… (this can take a minute on a large database)';
        results.innerHTML = '';
        var d;
        try {
            d = await API.get('explorer/history-sources');
        } catch (err) {
            status.textContent = '';
            results.appendChild(App.el('p', { className: 'text-secondary',
                textContent: '⚠ ' + (err && err.message ? err.message : 'probe failed') }));
            btn.disabled = false;
            return;
        }
        btn.disabled = false;
        status.textContent = '';

        if (d.configured === false) {
            results.appendChild(App.el('p', { className: 'text-secondary',
                textContent: 'MSSQL is not configured — set the connection up on the Go-Kart Labor page first.' }));
            return;
        }
        if (d.error) {
            results.appendChild(App.el('p', { className: 'text-secondary', textContent: '⚠ ' + d.error }));
            return;
        }

        // ---- Verdict first: the whole point of the card ----
        var rec = d.recommended;
        if (rec) {
            var pct = rec.total_readers > 0
                ? Math.round(rec.mapped_readers / rec.total_readers * 100) : 0;
            var span = rec.first_key_year === rec.last_key_year
                ? String(rec.first_key_year)
                : rec.first_key_year + '–' + rec.last_key_year;
            results.appendChild(App.el('div', { className: 'alert-success', style: { marginTop: '0.8rem' } }, [
                App.el('strong', { textContent: 'Per-game history is recoverable for ' + span + '. ' }),
                App.el('span', { textContent:
                    rec.schema + '.' + rec.table + ' carries a populated ' + rec.key_col + ' on '
                    + fmtCount(rec.attributable) + ' rows across ' + rec.key_years
                    + (rec.key_years === 1 ? ' year' : ' years') + ', and ' + rec.mapped_readers
                    + ' of ' + rec.total_readers + ' readers (' + pct + '%) match a game this app knows '
                    + 'about by name. Unmatched readers need a hand-built crosswalk — check the samples below.' })
            ]));
        } else {
            results.appendChild(App.el('div', { className: 'alert-warning', style: { marginTop: '0.8rem' } }, [
                App.el('strong', { textContent: 'No per-game source found in the tables probed. ' }),
                App.el('span', { textContent:
                    'None of the tables below carry a populated machine key in any year whose keys also '
                    + 'resolve to a current game. Check the per-year columns: a table with keys but no '
                    + 'matches is a NAMING problem (fixable by hand), not a missing-data one. Venue-wide '
                    + 'and per-area totals are unaffected — those never needed a reader key.' })
            ]));
        }

        // ---- Per-candidate evidence ----
        var cands = d.candidates || [];
        if (!cands.length) {
            results.appendChild(App.el('p', { className: 'text-muted text-sm',
                textContent: 'No table in this database has both a reader-key column and a date column.' }));
            return;
        }

        var head = ['Table', 'Machine key', 'Date column', 'Rows',
                    'Years with keys', 'Attributable rows', 'Readers → games'];
        var body = cands.map(function(c) {
            var cov = c.coverage || {};
            var map = c.mapping;
            var yearsText, attrText;
            if (!c.probed) {
                yearsText = attrText = 'not probed';
            } else if (cov.error) {
                yearsText = attrText = '⚠ ' + cov.error;
            } else if (!cov.first_key_year) {
                yearsText = 'none';
                attrText = '0';
            } else {
                yearsText = cov.first_key_year === cov.last_key_year
                    ? String(cov.first_key_year)
                    : cov.first_key_year + '–' + cov.last_key_year;
                if (cov.key_years > 1) yearsText += ' (' + cov.key_years + ')';
                attrText = fmtCount(cov.total_with_key);
            }
            var mapText = '—';
            if (map) {
                mapText = map.error
                    ? '⚠ ' + map.error
                    : map.mapped + ' of ' + map.keys_seen + ' map (' + map.year + ')';
            } else if (c.probed && !cov.error) {
                mapText = 'no keys to map';
            }
            return App.el('tr', {}, [
                App.el('td', {}, [App.el('strong', { textContent: c.table })]),
                App.el('td', { className: 'text-muted', textContent: c.key_col }),
                App.el('td', { className: 'text-muted', textContent: c.date_col }),
                App.el('td', { className: 'text-right', textContent: c.rows == null ? '—' : fmtCount(c.rows) }),
                App.el('td', { textContent: yearsText }),
                App.el('td', { className: 'text-right', textContent: attrText }),
                App.el('td', { textContent: mapText })
            ]);
        });

        results.appendChild(App.el('div', { className: 'stat-label', style: { margin: '0.9rem 0 0.4rem' },
            textContent: 'Candidate sources — rows carrying a non-zero reader key' }));
        results.appendChild(App.el('div', { className: 'explorer-scroll' }, [
            App.el('table', { className: 'data-table' }, [
                App.el('thead', {}, [App.el('tr', {}, head.map(function(h) {
                    return App.el('th', { textContent: h });
                }))]),
                App.el('tbody', {}, body)
            ])
        ]));

        results.appendChild(App.el('p', { className: 'text-muted text-xs', style: { marginTop: '0.5rem' },
            textContent: 'Reader cutover was ~' + (d.cutover || '?') + ', but coverage is measured per YEAR '
                + 'rather than assumed from the cutover — key population can stop long before a system '
                + 'change. Only the largest few tables are probed; POS register columns (StationNo) are '
                + 'excluded, since they identify a till rather than a machine.' }));

        // Per-year coverage for the deepest candidate — the evidence behind the verdict.
        var deepest = cands.filter(function(c) {
            return c.coverage && (c.coverage.years || []).length && c.coverage.first_key_year;
        })[0];
        if (deepest) {
            results.appendChild(App.el('div', { className: 'stat-label', style: { margin: '0.9rem 0 0.4rem' },
                textContent: 'Year-by-year key coverage (' + deepest.table + ')' }));
            results.appendChild(App.el('div', { className: 'explorer-scroll' }, [
                App.el('table', { className: 'data-table' }, [
                    App.el('thead', {}, [App.el('tr', {}, ['Year', 'Rows', 'With key', 'Coverage', 'Readers'].map(function(h) {
                        return App.el('th', { textContent: h });
                    }))]),
                    App.el('tbody', {}, deepest.coverage.years.map(function(y) {
                        return App.el('tr', { className: y.with_key ? '' : 'text-muted' }, [
                            App.el('td', {}, [App.el('strong', { textContent: String(y.year) })]),
                            App.el('td', { className: 'text-right', textContent: fmtCount(y.rows) }),
                            App.el('td', { className: 'text-right', textContent: fmtCount(y.with_key) }),
                            App.el('td', { className: 'text-right',
                                textContent: y.key_pct == null ? '—' : y.key_pct + '%' }),
                            App.el('td', { className: 'text-right', textContent: fmtCount(y.distinct_keys) })
                        ]);
                    }))
                ])
            ]));
        }

        // Reader → game samples make a bad mapping obvious at a glance.
        var withSamples = cands.filter(function(c) { return c.mapping && (c.mapping.samples || []).length; });
        if (withSamples.length) {
            var s = withSamples[0];
            results.appendChild(App.el('div', { className: 'stat-label', style: { margin: '0.9rem 0 0.4rem' },
                textContent: 'Sample reader → game matches (' + s.table + ')' }));
            results.appendChild(App.el('div', { className: 'explorer-scroll' }, [
                App.el('table', { className: 'data-table' }, [
                    App.el('thead', {}, [App.el('tr', {}, ['Reader key', 'Reader description', 'Matched game'].map(function(h) {
                        return App.el('th', { textContent: h });
                    }))]),
                    App.el('tbody', {}, s.mapping.samples.map(function(r) {
                        return App.el('tr', {}, [
                            App.el('td', { textContent: String(r.reader_key) }),
                            App.el('td', { className: 'text-muted', textContent: r.description || '(not in ReaderDevices)' }),
                            App.el('td', {}, r.game
                                ? [App.el('strong', { textContent: r.game })]
                                : [App.el('span', { className: 'text-muted', textContent: 'no match' })])
                        ]);
                    }))
                ])
            ]));
        }
    }

    function buildHunterCard() {
        hunter.tableSel = App.el('select', { className: 'form-input' });
        hunter.groupSel = App.el('select', { className: 'form-input' });
        hunter.sumSel   = App.el('select', { className: 'form-input' });
        hunter.dateSel  = App.el('select', { className: 'form-input' });
        hunter.fromIn   = App.el('input', { type: 'date', className: 'form-input', value: isoDaysAgo(1) });
        hunter.toIn     = App.el('input', { type: 'date', className: 'form-input', value: isoDaysAgo(1) });
        hunter.status   = App.el('span', { className: 'text-sm text-secondary' });
        hunter.results  = App.el('div', {});

        rebuildHunterTables();
        hunter.tableSel.addEventListener('change', function() {
            if (hunter.tableSel.value && hunter.tableSel.value !== state.selected) {
                selectTable(hunter.tableSel.value);
            }
        });

        var runBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Run',
            onClick: runHunter });

        return App.el('div', { className: 'card', id: 'explorer-hunter-card', style: { marginTop: '1rem' } }, [
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

    /** (Re)populate the hunter's table dropdown from the visible pool. */
    function rebuildHunterTables() {
        if (!hunter.tableSel) return;
        var current = hunter.tableSel.value;
        hunter.tableSel.innerHTML = '';
        hunter.tableSel.appendChild(App.el('option', { value: '', textContent: '— pick a table —' }));
        visibleTables().forEach(function(t) {
            hunter.tableSel.appendChild(App.el('option', { value: t.name, textContent: t.name }));
        });
        if (current) hunter.tableSel.value = current; // keep selection if still listed
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

        // Sensible defaults: group by the first NON-date column (grouping by
        // a datetime makes one group per timestamp — never what you want),
        // filter on the first date column when one exists.
        var firstNonDate = null;
        for (var i = 0; i < cols.length; i++) {
            if (DATE_TYPES.indexOf(cols[i].type) === -1) { firstNonDate = cols[i].name; break; }
        }
        if (firstNonDate) hunter.groupSel.value = firstNonDate;
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
            hunter.status.textContent = (r.rows || []).length + ' groups'
                + (r.truncated ? ' (top 100 — more exist)' : '') + ' · ' + (r.elapsed_ms || 0) + ' ms';
            var cols = [hunter.groupSel.value, 'Rows'];
            if (r.has_total) cols.push('Total');
            var rows = (r.rows || []).map(function(row) {
                var out = [row.grp === null ? null : String(row.grp), row.lines];
                if (r.has_total) out.push(row.total);
                return out;
            });
            hunter.results.appendChild(App.el('div', { className: 'explorer-scroll', style: { marginTop: '0.6rem' } }, [
                buildResultTable(cols, rows)
            ]));
            hunter.results.appendChild(App.el('pre', { className: 'labor-diagnostics', style: { marginTop: '0.5rem' }, textContent: r.sql || '' }));
        } catch (err) {
            hunter.status.textContent = '✗ ' + (err && err.message ? err.message : 'failed');
        }
    }

    // ------------------------------------------------------------------
    // Free SQL
    // ------------------------------------------------------------------
    function loadHistory() {
        try {
            var h = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
            return Array.isArray(h) ? h : [];
        } catch (e) { return []; }
    }

    function pushHistory(sql) {
        var h = loadHistory().filter(function(s) { return s !== sql; });
        h.unshift(sql);
        if (h.length > HISTORY_MAX) h = h.slice(0, HISTORY_MAX);
        try { localStorage.setItem(HISTORY_KEY, JSON.stringify(h)); } catch (e) {}
        return h;
    }

    function buildSqlCard() {
        var ta = App.el('textarea', { className: 'form-input labor-sql', rows: 6,
            placeholder: "SELECT TOP 25 * FROM CenterEdge.dbo.Sales WHERE ShiftDate >= '2026-07-01' ORDER BY ShiftDate DESC" });
        var limitIn = App.el('input', { className: 'form-input', type: 'number', min: '1', max: '500', value: '100',
            style: { maxWidth: '7rem' }, title: 'Max rows returned (500 cap)' });
        var status = App.el('span', { className: 'text-sm text-secondary' });
        var results = App.el('div', {});
        var csvBtn = App.el('button', { className: 'btn btn-ghost btn-sm', textContent: 'Download CSV', style: { display: 'none' },
            onClick: function() { downloadCsv(); } });

        // Recall past queries — schema hunting is iterative.
        var histSel = App.el('select', { className: 'form-input form-input-sm explorer-history',
            'aria-label': 'Recent queries' });
        function refreshHistory(list) {
            histSel.innerHTML = '';
            var h = list || loadHistory();
            histSel.appendChild(App.el('option', { value: '', textContent: h.length ? 'Recent queries…' : 'No recent queries' }));
            h.forEach(function(sql, i) {
                histSel.appendChild(App.el('option', { value: String(i),
                    textContent: sql.replace(/\s+/g, ' ').slice(0, 80) }));
            });
            histSel.disabled = !h.length;
        }
        histSel.addEventListener('change', function() {
            if (histSel.value === '') return;
            var h = loadHistory();
            var sql = h[parseInt(histSel.value, 10)];
            if (sql) ta.value = sql;
            histSel.value = '';
        });
        refreshHistory();

        async function run() {
            var sql = ta.value.trim();
            if (!sql) { App.toast('Type a SELECT first.', 'warning'); return; }
            status.textContent = 'Running…';
            results.innerHTML = '';
            csvBtn.style.display = 'none';
            state.lastQuery = null;
            try {
                var r = await API.post('explorer/query', { sql: sql, limit: parseInt(limitIn.value, 10) || 100 });
                if (r.error) { status.textContent = '✗ ' + r.error; return; }
                refreshHistory(pushHistory(sql));
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
        }

        // Ctrl+Enter / Cmd+Enter runs — muscle memory from every SQL tool.
        ta.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); run(); }
        });

        var runBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Run query', title: 'Ctrl+Enter',
            onClick: run });

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
                    runBtn, App.el('label', { className: 'text-sm text-secondary', textContent: 'Row cap' }), limitIn, histSel, csvBtn, status
                ]),
                results
            ])
        ]);
    }

    /**
     * Generic results table with click/keyboard-to-sort headers
     * (numeric-aware) via the shared App.enhanceTableSort helper. Sorting is
     * client-side over the rows already fetched; NULL cells carry an empty
     * data-sort so they sink to the bottom in both directions.
     */
    function buildResultTable(columns, rows) {
        var table = App.el('table', { className: 'data-table explorer-results' });
        table.appendChild(App.el('thead', {}, [
            App.el('tr', {}, columns.map(function(c) {
                return App.el('th', { textContent: String(c) });
            }))
        ]));
        var tbody = App.el('tbody', {}, rows.map(function(r) {
            return App.el('tr', {}, r.map(function(v) {
                var isNull = v === null || v === undefined;
                return App.el('td', {
                    textContent: isNull ? 'NULL' : String(v),
                    // Empty data-sort => treated as blank => sinks; keeps
                    // "NULL" text out of the numeric/string comparison.
                    'data-sort': isNull ? '' : null
                });
            }));
        }));
        table.appendChild(tbody);
        App.enhanceTableSort(table);
        return table;
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
