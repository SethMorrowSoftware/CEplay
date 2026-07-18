/**
 * Promotional Cards page — track BLOCKS of giveaway cards (a card-number range)
 * and see how they performed: how many came back, extra money loaded, plays,
 * tickets and value. Modeled on the Reader Groups page (a managed list of
 * ranges + a click-through detail view); the live numbers come from the venue's
 * CenterEdge POS ledger (api/promotions.php), by card number.
 *
 * Money figures (starting value, play value, reloads) are hidden from roles
 * without view_revenue — the server scrubs them and the client hides the
 * columns; activation / plays / tickets stay visible to every analytics role.
 */
(function() {
    App.registerRoute('#/promotions', { render: renderPromotions });

    var state;

    function freshState() {
        return { batchId: null, list: null, detail: null, genList: 0, genDetail: 0, inflight: null };
    }

    function canManage() { return App.canAccess('promotions_manage'); }
    function moneyOK(payload) { return App.canSeeMoney() && !(payload && payload.hide_money); }

    // ------------------------------------------------------------------
    async function renderPromotions(container) {
        state = freshState();

        container.appendChild(buildHeader());
        container.appendChild(App.el('div', { id: 'promo-note' }));

        var listCard = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } }, [
            App.el('div', { className: 'card-body', id: 'promo-list' }, [App.loading()])
        ]);
        container.appendChild(listCard);

        container.appendChild(App.el('div', { id: 'promo-detail' }));

        if (App.canAccess('settings')) {
            container.appendChild(App.el('div', { id: 'promo-admin' }));
            loadAdmin();
        }

        await loadList();
        return cleanup;
    }

    function cleanup() {
        if (!state) return;
        if (state.inflight && typeof state.inflight.abort === 'function') {
            try { state.inflight.abort(); } catch (e) {}
        }
        state = null;
    }

    function buildHeader() {
        var right = [
            App.el('button', {
                className: 'btn btn-secondary btn-sm', textContent: 'Refresh',
                onClick: function() { loadList(); if (state.batchId) loadDetail(); }
            })
        ];
        if (canManage()) {
            right.unshift(App.el('button', {
                className: 'btn btn-primary', textContent: '+ New batch',
                onClick: function() { openEditor(null); }
            }));
        }
        return App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Promotional Cards' }),
                App.el('p', { className: 'page-subtitle', textContent: 'Track blocks of giveaway cards by card-number range — how many came back, how much extra money was loaded, and how much they played. Click a batch for the full breakdown.' })
            ]),
            App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center' } }, right)
        ]);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------
    async function loadList() {
        var gen = ++state.genList;
        try {
            var data = await API.get('promotions');
            if (!state || state.genList !== gen) return;
            state.list = data;
            renderNote(data);
            renderList(data);
        } catch (err) {
            if (!state || state.genList !== gen) return;
            var box = document.getElementById('promo-list');
            if (box) { box.innerHTML = ''; box.appendChild(App.el('div', { className: 'text-danger text-sm', textContent: 'Failed to load: ' + (err && err.message ? err.message : 'unknown error') })); }
        }
    }

    function renderNote(data) {
        var box = document.getElementById('promo-note');
        if (!box) return;
        box.innerHTML = '';
        if (!data.mssql_configured) {
            box.appendChild(App.el('div', { className: 'promo-note-card', style: { marginBottom: '1rem' } }, [
                App.el('span', { textContent: 'Live performance numbers appear once the POS database connection is set up (on the Go-Kart Labor page). You can still define batches now — their stats will fill in automatically.' })
            ]));
        }
    }

    function renderList(data) {
        var box = document.getElementById('promo-list');
        if (!box) return;
        box.innerHTML = '';
        var batches = data.batches || [];
        var money = moneyOK(data);

        if (batches.length === 0) {
            var empty = App.el('div', { className: 'text-center', style: { padding: '2rem 1rem' } }, [
                App.el('div', { className: 'text-secondary', style: { marginBottom: '0.75rem' }, textContent: 'No promotional card batches yet.' })
            ]);
            if (canManage()) {
                empty.appendChild(App.el('button', {
                    className: 'btn btn-primary', textContent: '+ Create your first batch',
                    onClick: function() { openEditor(null); }
                }));
            }
            box.appendChild(empty);
            return;
        }

        var headCells = [
            App.el('th', { textContent: 'Batch' }),
            App.el('th', { textContent: 'Card range' }),
            App.el('th', { textContent: 'Given out' }),
            App.el('th', { className: 'text-right', textContent: 'Came back' }),
            App.el('th', { className: 'text-right', textContent: 'Plays' }),
            App.el('th', { className: 'text-right', textContent: 'Tickets' }),
            App.el('th', { className: 'text-right', textContent: 'Reloaded' })
        ];
        if (money) headCells.push(App.el('th', { className: 'text-right', textContent: 'Avg added' }));
        if (canManage()) headCells.push(App.el('th', {}));

        var table = App.el('table', { className: 'data-table promo-table' }, [
            App.el('thead', {}, [App.el('tr', {}, headCells)])
        ]);
        var tbody = App.el('tbody', {});

        batches.forEach(function(b) {
            var s = b.stats || null;
            var defined = b.cards_defined;
            var used = s ? s.cards_used : null;
            var actPct = (s && s.activation_rate != null) ? Math.round(s.activation_rate * 100) : null;

            var nameCell = App.el('td', {}, [
                App.el('div', { className: 'promo-name', textContent: b.name }),
                App.el('div', { className: 'text-muted text-xs', textContent:
                    (defined != null ? formatInt(defined) + ' cards' : (b.card_from + '–' + b.card_to))
                    + (money && b.initial_value ? ' · ' + formatCurrency(b.initial_value) + ' each' : '') })
            ]);

            var cameBack = App.el('td', { className: 'text-right' });
            if (s) {
                cameBack.appendChild(App.el('div', { className: 'promo-came-back' }, [
                    App.el('strong', { textContent: formatInt(used) }),
                    App.el('span', { className: 'text-muted', textContent: defined != null ? ' / ' + formatInt(defined) : '' })
                ]));
                if (actPct != null) {
                    cameBack.appendChild(App.el('div', { className: 'promo-bar', 'aria-hidden': 'true' }, [
                        App.el('div', { className: 'promo-bar-fill', style: { width: Math.min(100, actPct) + '%' } })
                    ]));
                    cameBack.appendChild(App.el('div', { className: 'text-muted text-xs', textContent: actPct + '% activated' }));
                }
            } else {
                cameBack.appendChild(App.el('span', { className: 'text-muted', textContent: '—' }));
            }

            var reloadCell = App.el('td', { className: 'text-right', textContent:
                s ? (formatInt(s.cards_reloaded) + (s.reload_rate != null ? ' · ' + Math.round(s.reload_rate * 100) + '%' : '')) : '—' });

            var cells = [
                nameCell,
                App.el('td', {}, [App.el('span', { className: 'promo-range-chip', textContent: b.card_from + '–' + b.card_to })]),
                App.el('td', { textContent: b.giveaway_date || '—' }),
                cameBack,
                App.el('td', { className: 'text-right', textContent: s ? formatInt(s.plays) : '—' }),
                App.el('td', { className: 'text-right', textContent: s ? formatInt(Math.round(s.tickets || 0)) : '—' }),
                reloadCell
            ];
            if (money) cells.push(App.el('td', { className: 'text-right', textContent: (s && s.avg_additional != null) ? formatCurrency(s.avg_additional) : '—' }));

            if (canManage()) {
                cells.push(App.el('td', { className: 'text-right' }, [
                    App.el('div', { className: 'flex gap-sm', style: { justifyContent: 'flex-end' } }, [
                        App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Edit',
                            onClick: function(e) { e.stopPropagation(); openEditor(b.id); } }),
                        App.el('button', { className: 'btn btn-sm btn-ghost text-danger', textContent: 'Delete',
                            onClick: function(e) { e.stopPropagation(); deleteBatch(b); } })
                    ])
                ]));
            }

            var row = App.el('tr', { className: 'clickable-row' + (state.batchId === b.id ? ' promo-row-selected' : '') }, cells);
            row.addEventListener('click', function() { selectBatch(b.id); });
            tbody.appendChild(row);
        });

        table.appendChild(tbody);
        box.appendChild(App.el('div', { className: 'table-scroll-x' }, [table]));
    }

    function selectBatch(id) {
        state.batchId = id;
        renderList(state.list); // refresh row highlight
        loadDetail();
        var detail = document.getElementById('promo-detail');
        if (detail) detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ------------------------------------------------------------------
    // Detail
    // ------------------------------------------------------------------
    async function loadDetail() {
        var box = document.getElementById('promo-detail');
        if (!box) return;
        box.innerHTML = '';
        box.appendChild(App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } }, [
            App.el('div', { className: 'card-body' }, [App.loading()])
        ]));
        var gen = ++state.genDetail;
        try {
            var data = await API.get('promotions/analyze?id=' + encodeURIComponent(state.batchId));
            if (!state || state.genDetail !== gen) return;
            state.detail = data;
            renderDetail(data);
        } catch (err) {
            if (!state || state.genDetail !== gen) return;
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'card' }, [App.el('div', { className: 'card-body text-danger text-sm', textContent: 'Failed to load batch: ' + (err && err.message ? err.message : 'unknown error') })]));
        }
    }

    function renderDetail(data) {
        var box = document.getElementById('promo-detail');
        if (!box) return;
        box.innerHTML = '';
        var b = data.batch || {};
        var s = data.summary;
        var money = moneyOK(data);

        var header = App.el('div', { className: 'card-header promo-detail-header' }, [
            App.el('div', {}, [
                App.el('div', { className: 'card-title', textContent: b.name }),
                App.el('div', { className: 'text-muted text-sm', textContent:
                    'Cards ' + b.card_from + '–' + b.card_to
                    + (b.cards_defined != null ? ' (' + formatInt(b.cards_defined) + ' cards)' : '')
                    + (b.giveaway_date ? ' · given out ' + b.giveaway_date : '')
                    + (money && b.initial_value ? ' · ' + formatCurrency(b.initial_value) + '/card'
                        + (b.total_initial != null ? ' = ' + formatCurrency(b.total_initial) + ' face value' : '') : '') })
            ]),
            App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Close',
                onClick: function() { state.batchId = null; box.innerHTML = ''; renderList(state.list); } })
        ]);

        var bodyChildren = [];

        if (!data.mssql_configured) {
            bodyChildren.push(App.el('div', { className: 'text-secondary text-sm', textContent: 'Live performance numbers appear once the POS database connection is configured (Go-Kart Labor page).' }));
        } else if (data.error) {
            bodyChildren.push(App.el('div', { className: 'text-danger text-sm', textContent: 'Query error: ' + data.error }));
        } else if (s) {
            bodyChildren.push(buildDetailTiles(s, b, money));
            if (b.notes) bodyChildren.push(App.el('div', { className: 'promo-notes', textContent: b.notes }));
            bodyChildren.push(buildCardTable(data, money));
        } else {
            bodyChildren.push(App.el('div', { className: 'text-secondary text-sm', textContent: 'No activity found for this card range yet.' }));
        }

        box.appendChild(App.el('div', { className: 'card promo-detail-card' }, [
            header,
            App.el('div', { className: 'card-body' }, bodyChildren)
        ]));
    }

    function tile(label, value, hint, cls) {
        return App.el('div', { className: 'promo-tile' + (cls ? ' ' + cls : '') }, [
            App.el('div', { className: 'stat-label', textContent: label }),
            App.el('div', { className: 'promo-tile-value', textContent: value }),
            App.el('div', { className: 'text-muted text-xs', textContent: hint || '' })
        ]);
    }

    function buildDetailTiles(s, b, money) {
        var tiles = [];
        var actPct = s.activation_rate != null ? Math.round(s.activation_rate * 100) : null;
        tiles.push(tile('Came back',
            formatInt(s.cards_used) + (s.cards_defined != null ? ' / ' + formatInt(s.cards_defined) : ''),
            actPct != null ? actPct + '% of the batch was used' : 'cards with any activity',
            'promo-tile-accent'));
        tiles.push(tile('Plays', formatInt(s.plays), 'total game plays on these cards'));
        tiles.push(tile('Tickets', formatInt(Math.round(s.tickets || 0)), 'redemption tickets earned'));
        tiles.push(tile('Reloaded', formatInt(s.cards_reloaded)
            + (s.reload_rate != null ? ' · ' + Math.round(s.reload_rate * 100) + '%' : ''),
            'cards that had money added'));
        if (money) {
            tiles.push(tile('Avg added', s.avg_additional != null ? formatCurrency(s.avg_additional) : '—',
                'average reload per reloaded card'));
            tiles.push(tile('Total reloaded', formatCurrency(s.reload_value),
                s.avg_additional_all != null ? formatCurrency(s.avg_additional_all) + ' per card used' : 'extra money loaded'));
            tiles.push(tile('Value played', formatCurrency(s.play_value), 'spent at the readers'));
        }
        var seen = '';
        if (s.first_seen) seen = shortStamp(s.first_seen) + ' → ' + shortStamp(s.last_seen);
        tiles.push(tile('Active window', seen || '—', 'first to last activity'));

        return App.el('div', { className: 'promo-tile-grid' }, tiles);
    }

    function buildCardTable(data, money) {
        var cards = data.cards || [];
        var wrap = App.el('div', { className: 'promo-cards-section' }, [
            App.el('div', { className: 'stat-label', style: { margin: '1rem 0 0.5rem' }, textContent:
                'Cards used' + (data.cards_capped ? ' (top 200 most recent)' : ' (' + cards.length + ')') })
        ]);
        if (cards.length === 0) {
            wrap.appendChild(App.el('div', { className: 'text-muted text-sm', textContent: 'No individual cards to show.' }));
            return wrap;
        }
        var head = [
            App.el('th', { textContent: 'Card' }),
            App.el('th', { className: 'text-right', textContent: 'Plays' }),
            App.el('th', { className: 'text-right', textContent: 'Tickets' }),
            App.el('th', { className: 'text-right', textContent: 'Reloads' })
        ];
        if (money) {
            head.push(App.el('th', { className: 'text-right', textContent: 'Reloaded $' }));
            head.push(App.el('th', { className: 'text-right', textContent: 'Value played' }));
        }
        head.push(App.el('th', { textContent: 'Last seen' }));

        var table = App.el('table', { className: 'data-table promo-card-table' }, [App.el('thead', {}, [App.el('tr', {}, head)])]);
        var tbody = App.el('tbody', {});
        cards.forEach(function(c) {
            var cells = [
                App.el('td', {}, [App.el('span', { className: 'promo-range-chip', textContent: c.card })]),
                App.el('td', { className: 'text-right', textContent: formatInt(c.plays) }),
                App.el('td', { className: 'text-right', textContent: formatInt(Math.round(c.tickets || 0)) }),
                App.el('td', { className: 'text-right', textContent: formatInt(c.reloads) })
            ];
            if (money) {
                cells.push(App.el('td', { className: 'text-right', textContent: formatCurrency(c.reload_value) }));
                cells.push(App.el('td', { className: 'text-right', textContent: formatCurrency(c.play_value) }));
            }
            cells.push(App.el('td', { textContent: c.last_seen ? shortStamp(c.last_seen) : '—' }));
            tbody.appendChild(App.el('tr', {}, cells));
        });
        table.appendChild(tbody);
        wrap.appendChild(App.el('div', { className: 'table-scroll-x' }, [table]));
        return wrap;
    }

    // ------------------------------------------------------------------
    // Create / edit / delete
    // ------------------------------------------------------------------
    async function openEditor(batchId) {
        var existing = null;
        if (batchId) {
            try { existing = await API.get('promotions/' + batchId); }
            catch (err) { App.toast('Could not load batch: ' + (err && err.message ? err.message : ''), 'error'); return; }
        }
        var e = existing || {};
        var money = App.canSeeMoney();

        var nameInput = App.el('input', { className: 'form-input', type: 'text', maxLength: 100, value: e.name || '', placeholder: 'e.g. K104 on-air giveaway' });
        var fromInput = App.el('input', { className: 'form-input', type: 'text', inputmode: 'numeric', value: e.card_from || '', placeholder: 'e.g. 100000' });
        var toInput   = App.el('input', { className: 'form-input', type: 'text', inputmode: 'numeric', value: e.card_to || '', placeholder: 'e.g. 100499' });
        var dateInput = App.el('input', { className: 'form-input', type: 'date', value: e.giveaway_date || '' });
        var valueInput = App.el('input', { className: 'form-input', type: 'number', step: '0.01', min: '0', value: (e.initial_value != null && e.initial_value !== 0) ? e.initial_value : '', placeholder: '30.00' });
        var notesInput = App.el('textarea', { className: 'form-input', rows: 2, maxLength: 1000, placeholder: 'Optional notes' }, [document.createTextNode(e.notes || '')]);

        function field(label, input, hint) {
            var kids = [App.el('label', { className: 'form-label', textContent: label }), input];
            if (hint) kids.push(App.el('div', { className: 'text-muted text-xs', textContent: hint }));
            return App.el('div', { className: 'form-group' }, kids);
        }

        var rows = [
            field('Batch name', nameInput),
            App.el('div', { className: 'promo-form-row' }, [
                field('Card from', fromInput, 'first card number'),
                field('Card to', toInput, 'last card number (inclusive)')
            ]),
            App.el('div', { className: 'promo-form-row' }, [
                field('Given out on', dateInput, 'bounds the search — recommended'),
                money ? field('Starting value / card', valueInput, 'e.g. $30 preloaded') : App.el('div')
            ]),
            field('Notes', notesInput)
        ];

        var body = App.el('div', {}, rows);
        var saveBtn = App.el('button', { className: 'btn btn-primary', textContent: batchId ? 'Save' : 'Create' });
        var footer = App.el('div', { className: 'flex gap-sm', style: { justifyContent: 'flex-end' } }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
            saveBtn
        ]);

        saveBtn.addEventListener('click', async function() {
            var payload = {
                name: nameInput.value.trim(),
                card_from: fromInput.value.trim(),
                card_to: toInput.value.trim(),
                giveaway_date: dateInput.value,
                notes: notesInput.value.trim()
            };
            if (money) payload.initial_value = valueInput.value === '' ? 0 : valueInput.value;
            if (!payload.name) { App.toast('Give the batch a name.', 'warning'); return; }
            if (!/^\d{1,18}$/.test(payload.card_from) || !/^\d{1,18}$/.test(payload.card_to)) {
                App.toast('Card numbers must be whole numbers.', 'warning'); return;
            }
            if (Number(payload.card_to) < Number(payload.card_from)) {
                App.toast('"Card to" must be greater than or equal to "Card from".', 'warning'); return;
            }
            saveBtn.disabled = true;
            try {
                if (batchId) {
                    await API.put('promotions/' + batchId, payload);
                    App.toast('Batch updated.', 'success');
                } else {
                    var created = await API.post('promotions', payload);
                    if (created && created.id) state.batchId = created.id;
                    App.toast('Batch created.', 'success');
                }
                App.hideModal();
                await loadList();
                if (state.batchId) loadDetail();
            } catch (err) {
                saveBtn.disabled = false;
                App.toast('Save failed: ' + (err && err.message ? err.message : 'unknown error'), 'error');
            }
        });

        App.showModal(batchId ? 'Edit batch' : 'New promotional batch', body, footer);
    }

    async function deleteBatch(b) {
        var ok = await App.confirm({
            title: 'Delete batch',
            message: 'Delete "' + b.name + '"? This only removes the tracking definition — the cards and their history are untouched.',
            confirmLabel: 'Delete'
        });
        if (!ok) return;
        try {
            await API.del('promotions/' + b.id);
            if (state.batchId === b.id) { state.batchId = null; var d = document.getElementById('promo-detail'); if (d) d.innerHTML = ''; }
            App.toast('Batch deleted.', 'success');
            loadList();
        } catch (err) {
            App.toast('Delete failed: ' + (err && err.message ? err.message : 'unknown error'), 'error');
        }
    }

    // ------------------------------------------------------------------
    // Admin: editable query + test connection (reuses the Labor admin styling)
    // ------------------------------------------------------------------
    async function loadAdmin() {
        var box = document.getElementById('promo-admin');
        if (!box) return;
        try {
            var cfg = await API.get('promotions/settings');
            renderAdmin(cfg);
        } catch (err) { /* settings not accessible — leave empty */ }
    }

    function renderAdmin(cfg) {
        var box = document.getElementById('promo-admin');
        if (!box) return;
        box.innerHTML = '';

        var sqlArea = App.el('textarea', { className: 'labor-sql', rows: 10, spellcheck: 'false' }, [document.createTextNode(cfg.range_sql || '')]);
        var diag = App.el('pre', { className: 'labor-diagnostics', style: { display: 'none' } });

        var fromInput = App.el('input', { className: 'form-input form-input-sm', type: 'text', inputmode: 'numeric', placeholder: 'card from' });
        var toInput   = App.el('input', { className: 'form-input form-input-sm', type: 'text', inputmode: 'numeric', placeholder: 'card to' });
        var sinceInput = App.el('input', { className: 'form-input form-input-sm', type: 'date' });

        var saveBtn = App.el('button', { className: 'btn btn-sm btn-primary', textContent: 'Save query' });
        var resetBtn = App.el('button', { className: 'btn btn-sm btn-ghost', textContent: 'Reset to default' });
        var testBtn = App.el('button', { className: 'btn btn-sm btn-secondary', textContent: 'Test range' });

        saveBtn.addEventListener('click', async function() {
            saveBtn.disabled = true;
            try {
                await API.put('promotions/settings', { range_sql: sqlArea.value });
                App.toast('Query saved.', 'success');
            } catch (err) {
                App.toast('Save failed: ' + (err && err.message ? err.message : ''), 'error');
            }
            saveBtn.disabled = false;
        });
        resetBtn.addEventListener('click', function() {
            sqlArea.value = (cfg.defaults && cfg.defaults.range_sql) || '';
        });
        testBtn.addEventListener('click', async function() {
            if (!App.canAccess('data_explorer')) { App.toast('Testing needs the Database Explorer permission.', 'warning'); return; }
            testBtn.disabled = true;
            diag.style.display = '';
            diag.textContent = 'Running…';
            try {
                var res = await API.post('promotions/test', { card_from: fromInput.value.trim(), card_to: toInput.value.trim(), since: sinceInput.value });
                diag.textContent = JSON.stringify(res, null, 2);
            } catch (err) {
                diag.textContent = 'Error: ' + (err && err.message ? err.message : 'unknown');
            }
            testBtn.disabled = false;
        });

        var details = App.el('details', { className: 'card labor-admin-details' }, [
            App.el('summary', { textContent: 'Advanced: performance query & connection test' }),
            App.el('div', { className: 'card-body' }, [
                App.el('p', { className: 'text-muted text-sm', textContent: 'The aggregate query run for each batch. Placeholders :since, :cardfrom and :cardto are filled in per batch. Read-only single SELECT. The connection itself is configured on the Go-Kart Labor page.' }),
                sqlArea,
                App.el('div', { className: 'flex gap-sm', style: { margin: '0.5rem 0', flexWrap: 'wrap' } }, [saveBtn, resetBtn]),
                App.el('div', { className: 'promo-test-row' }, [
                    App.el('span', { className: 'text-sm text-secondary', textContent: 'Probe:' }),
                    fromInput, toInput, sinceInput, testBtn
                ]),
                diag
            ])
        ]);
        box.appendChild(details);
    }

    // ------------------------------------------------------------------
    // Formatting
    // ------------------------------------------------------------------
    function formatInt(n) { n = Number(n) || 0; return n.toLocaleString(); }
    function formatCurrency(n) { n = Number(n) || 0; return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
    function shortStamp(s) {
        if (!s) return '—';
        // s like "2026-07-15 14:03:00"
        var d = String(s).slice(0, 10);
        var parts = d.split('-');
        if (parts.length !== 3) return d;
        var dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return dt.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }
})();
