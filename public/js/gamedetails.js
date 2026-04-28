/**
 * Game Explorer page — browse all games, view details, perform actions, transaction feed.
 */
(function() {
    App.registerRoute('#/game-explorer', { render: renderGameExplorer });
    App.registerRoute('#/game-explorer/:gameId', { render: renderGameDetail });

    var pollTimer = null;

    function renderGameExplorer(container) {
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('h1', { className: 'page-title', textContent: 'Game Explorer' }),
            App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', {
                    className: 'btn btn-secondary', textContent: 'Sync Games',
                    onClick: async function() {
                        try {
                            await API.post('games/sync');
                            App.toast('Games synced.', 'success');
                            loadGameList();
                        } catch (err) { App.toast(err.message, 'error'); }
                    }
                }),
                App.el('button', {
                    className: 'btn btn-secondary', textContent: 'Transaction Feed',
                    onClick: function() { showTransactionFeed(); }
                })
            ])
        ]));

        // Filters
        const filterRow = App.el('div', { className: 'flex gap-sm', style: { marginBottom: '1rem', flexWrap: 'wrap', alignItems: 'center' } });

        const searchInput = App.el('input', {
            className: 'form-input', type: 'text', id: 'game-search',
            placeholder: 'Search games...', style: { maxWidth: '300px' }
        });
        searchInput.addEventListener('input', filterGames);
        filterRow.appendChild(searchInput);

        const statusFilter = App.el('select', { className: 'form-select', id: 'game-status-filter', style: { maxWidth: '200px' } });
        statusFilter.appendChild(App.el('option', { value: '', textContent: 'All Statuses' }));
        statusFilter.appendChild(App.el('option', { value: 'enabled', textContent: 'Enabled' }));
        statusFilter.appendChild(App.el('option', { value: 'paused', textContent: 'Paused' }));
        statusFilter.appendChild(App.el('option', { value: 'outOfService', textContent: 'Out of Service' }));
        statusFilter.addEventListener('change', filterGames);
        filterRow.appendChild(statusFilter);

        const catFilter = App.el('select', { className: 'form-select', id: 'game-cat-filter', style: { maxWidth: '200px' } });
        catFilter.appendChild(App.el('option', { value: '', textContent: 'All Categories' }));
        catFilter.addEventListener('change', filterGames);
        filterRow.appendChild(catFilter);

        container.appendChild(filterRow);

        // Stats bar
        container.appendChild(App.el('div', { id: 'game-stats', className: 'flex gap-sm', style: { marginBottom: '1rem' } }));

        // Game list
        const listContainer = App.el('div', { id: 'game-list-container' });
        listContainer.appendChild(App.loading());
        container.appendChild(listContainer);

        loadGameList();

        return function() {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        };
    }

    var allGames = [];
    var allCategories = {};

    async function loadGameList() {
        try {
            const [gamesData, catData] = await Promise.all([
                API.get('games'),
                API.get('games/categories').catch(function() { return { categories: [] }; })
            ]);

            allGames = gamesData.games || [];

            // Build category map
            allCategories = {};
            (catData.categories || []).forEach(function(c) {
                allCategories[c.id] = c.name;
            });

            // Populate category filter
            var catFilter = document.getElementById('game-cat-filter');
            if (catFilter) {
                var current = catFilter.value;
                catFilter.innerHTML = '';
                catFilter.appendChild(App.el('option', { value: '', textContent: 'All Categories' }));
                Object.entries(allCategories).forEach(function(entry) {
                    catFilter.appendChild(App.el('option', { value: entry[0], textContent: entry[1] }));
                });
                catFilter.value = current;
            }

            updateStats();
            filterGames();
        } catch (err) {
            var container = document.getElementById('game-list-container');
            if (container) {
                container.innerHTML = '';
                App.toast(err.message, 'error');
            }
        }
    }

    function updateStats() {
        var statsEl = document.getElementById('game-stats');
        if (!statsEl) return;
        statsEl.innerHTML = '';

        var total = allGames.length;
        var enabled = allGames.filter(function(g) { return g.operation_status === 'enabled'; }).length;
        var paused = allGames.filter(function(g) { return g.operation_status === 'paused'; }).length;
        var oos = allGames.filter(function(g) { return g.operation_status === 'outOfService'; }).length;

        statsEl.appendChild(statBadge('Total', total, ''));
        statsEl.appendChild(statBadge('Enabled', enabled, 'badge-enabled'));
        statsEl.appendChild(statBadge('Paused', paused, 'badge-paused'));
        statsEl.appendChild(statBadge('Out of Service', oos, 'badge-out-of-service'));
    }

    function statBadge(label, count, cls) {
        return App.el('span', { className: 'badge ' + cls, textContent: label + ': ' + count });
    }

    function filterGames() {
        var search = (document.getElementById('game-search') || {}).value || '';
        search = search.toLowerCase();
        var status = (document.getElementById('game-status-filter') || {}).value || '';
        var cat = (document.getElementById('game-cat-filter') || {}).value || '';

        var filtered = allGames.filter(function(g) {
            if (search && !(g.game_name || g.name || '').toLowerCase().includes(search) && !(g.game_id || g.id || '').toLowerCase().includes(search)) return false;
            var gStatus = g.operation_status || g.operationStatus || '';
            if (status && gStatus !== status) return false;
            if (cat) {
                var cats = g.categories || [];
                if (typeof cats === 'string') try { cats = JSON.parse(cats); } catch(e) { cats = []; }
                if (!cats.includes(parseInt(cat)) && !cats.includes(cat)) return false;
            }
            return true;
        });

        renderGameList(filtered);
    }

    function renderGameList(games) {
        var container = document.getElementById('game-list-container');
        if (!container) return;
        container.innerHTML = '';

        if (games.length === 0) {
            container.appendChild(App.emptyState('?', 'No games found matching your filters.'));
            return;
        }

        var table = App.el('table', { className: 'table' });
        var thead = App.el('thead');
        thead.appendChild(App.el('tr', {}, [
            App.el('th', { textContent: 'Game ID' }),
            App.el('th', { textContent: 'Name' }),
            App.el('th', { textContent: 'Status' }),
            App.el('th', { textContent: 'Categories' }),
            App.el('th', { textContent: 'Actions' })
        ]));
        table.appendChild(thead);

        var tbody = App.el('tbody');
        games.forEach(function(g) {
            var gameId = g.game_id || g.id || '';
            var gameName = g.game_name || g.name || '';
            var gStatus = g.operation_status || g.operationStatus || 'unknown';
            var cats = g.categories || [];
            if (typeof cats === 'string') try { cats = JSON.parse(cats); } catch(e) { cats = []; }
            var catNames = cats.map(function(cid) { return allCategories[cid] || ('ID ' + cid); }).join(', ') || '-';

            tbody.appendChild(App.el('tr', {}, [
                App.el('td', {}, [App.el('code', { textContent: gameId })]),
                App.el('td', {}, [
                    App.el('a', { href: '#/game-explorer/' + encodeURIComponent(gameId), textContent: gameName, style: { cursor: 'pointer' } })
                ]),
                App.el('td', {}, [App.statusBadge(gStatus)]),
                App.el('td', { textContent: catNames, style: { fontSize: '0.85rem', maxWidth: '200px', overflow: 'hidden', textOverflow: 'ellipsis' } }),
                App.el('td', { className: 'flex gap-sm' }, [
                    App.el('button', {
                        className: 'btn btn-ghost btn-sm', textContent: 'Details',
                        onClick: function() { window.location.hash = '#/game-explorer/' + encodeURIComponent(gameId); }
                    })
                ])
            ]));
        });
        table.appendChild(tbody);

        var tw = App.el('div', { className: 'table-responsive' });
        tw.appendChild(table);
        container.appendChild(tw);
    }

    // Game Detail Page
    async function renderGameDetail(container, params) {
        var gameId = decodeURIComponent(params.gameId);
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center' } }, [
                App.el('button', { className: 'btn btn-ghost btn-sm', textContent: 'Back', onClick: function() { window.location.hash = '#/game-explorer'; } }),
                App.el('h1', { className: 'page-title', textContent: 'Game: ' + gameId })
            ])
        ]));

        var detailContainer = App.el('div', { id: 'game-detail-content' });
        detailContainer.appendChild(App.loading());
        container.appendChild(detailContainer);

        try {
            var game = await API.get('gamedetails/' + encodeURIComponent(gameId));
            detailContainer.innerHTML = '';
            detailContainer.appendChild(buildGameDetail(game));
        } catch (err) {
            detailContainer.innerHTML = '';
            App.toast(err.message, 'error');
            detailContainer.appendChild(App.emptyState('!', 'Failed to load game details: ' + err.message));
        }
    }

    function buildGameDetail(game) {
        var wrapper = App.el('div');

        // Info Card
        var infoCard = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        var headerBtns = App.el('div', { className: 'flex gap-sm' });

        // Status change buttons
        var statusOptions = ['enabled', 'paused', 'outOfService'];
        statusOptions.forEach(function(s) {
            var label = s === 'outOfService' ? 'Out of Service' : s.charAt(0).toUpperCase() + s.slice(1);
            headerBtns.appendChild(App.el('button', {
                className: 'btn btn-sm ' + (game.operationStatus === s ? 'btn-primary' : 'btn-secondary'),
                textContent: 'Set ' + label,
                onClick: async function() {
                    try {
                        var changes = {};
                        changes[game.id] = [{ op: 'replace', path: '/operationStatus', value: s }];
                        await API.patch('games', { games: changes });
                        App.toast('Game status updated to ' + label + '.', 'success');
                        // Reload
                        window.location.hash = '#/game-explorer/' + encodeURIComponent(game.id);
                    } catch (err) { App.toast(err.message, 'error'); }
                }
            }));
        });

        infoCard.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: game.name || game.id }),
            headerBtns
        ]));

        var body = App.el('div', { className: 'card-body' });
        var grid = App.el('div', { className: 'info-grid' });

        grid.appendChild(infoItem('Game ID', game.id));
        grid.appendChild(infoItem('Name', game.name));
        grid.appendChild(App.el('div', { className: 'info-item' }, [
            App.el('div', { className: 'info-label', textContent: 'Status' }),
            App.el('div', { className: 'info-value' }, [App.statusBadge(game.operationStatus || 'unknown')])
        ]));
        grid.appendChild(infoItem('Virtual Play', game.virtualPlayEnabled ? 'Yes' : 'No'));

        if (game.categories && game.categories.length > 0) {
            grid.appendChild(infoItem('Categories', game.categories.join(', ')));
        }

        body.appendChild(grid);

        // Supported Actions
        if (game.supportedActions && game.supportedActions.length > 0) {
            body.appendChild(App.el('h4', { textContent: 'Supported Actions', style: { margin: '1rem 0 0.5rem' } }));
            var actionRow = App.el('div', { className: 'flex gap-sm', style: { flexWrap: 'wrap' } });
            game.supportedActions.forEach(function(action) {
                actionRow.appendChild(App.el('button', {
                    className: 'btn btn-secondary btn-sm',
                    textContent: action.name + (action.requireManager ? ' (Manager)' : ''),
                    title: 'Action ID: ' + action.id,
                    onClick: async function() {
                        var yes = await App.confirm('Perform action "' + action.name + '" on game "' + game.name + '"?');
                        if (!yes) return;
                        try {
                            await API.post('gamedetails/' + encodeURIComponent(game.id) + '/action', { actionId: action.id });
                            App.toast('Action "' + action.name + '" performed successfully.', 'success');
                            window.location.hash = '#/game-explorer/' + encodeURIComponent(game.id);
                        } catch (err) { App.toast(err.message, 'error'); }
                    }
                }));
            });
            body.appendChild(actionRow);
        }

        infoCard.appendChild(body);
        wrapper.appendChild(infoCard);

        return wrapper;
    }

    function showTransactionFeed() {
        var form = App.el('div');

        var sinceInput = App.el('input', { className: 'form-input', type: 'text', value: '0', placeholder: 'Since ID (cursor)' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Since ID' }),
            sinceInput
        ]));

        var feedInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'default', value: 'default' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Feed Name' }),
            feedInput
        ]));

        var resultDiv = App.el('div', { id: 'tx-feed-result', style: { maxHeight: '400px', overflowY: 'auto' } });
        form.appendChild(resultDiv);

        var footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Close', onClick: function() { App.hideModal(); } }),
            App.el('button', { className: 'btn btn-primary', textContent: 'Fetch', onClick: async function() {
                resultDiv.innerHTML = '';
                resultDiv.appendChild(App.loading());
                try {
                    var params = 'sinceId=' + encodeURIComponent(sinceInput.value || '0');
                    var fn = feedInput.value.trim();
                    if (fn) params += '&feedName=' + encodeURIComponent(fn);
                    var result = await API.get('gamedetails/transactions?' + params);
                    resultDiv.innerHTML = '';

                    var txs = result.transactions || [];
                    if (txs.length === 0) {
                        resultDiv.appendChild(App.el('p', { className: 'text-muted', textContent: 'No new transactions.' }));
                    } else {
                        // Update sinceId for next fetch
                        if (result.sinceId) sinceInput.value = result.sinceId;

                        txs.forEach(function(tx) {
                            var item = App.el('div', { className: 'card', style: { marginBottom: '0.5rem', padding: '0.75rem' } });
                            item.appendChild(App.el('div', { className: 'flex gap-sm', style: { justifyContent: 'space-between' } }, [
                                App.el('strong', { textContent: 'Game: ' + (tx.gameId || 'N/A') }),
                                App.el('span', { className: 'text-muted text-sm', textContent: tx.transactionTime ? App.formatDatetime(tx.transactionTime) : '' })
                            ]));
                            if (tx.cardNumber) {
                                item.appendChild(App.el('div', { className: 'text-sm', textContent: 'Card: ' + tx.cardNumber }));
                            }
                            resultDiv.appendChild(item);
                        });
                    }
                } catch (err) {
                    resultDiv.innerHTML = '';
                    App.toast(err.message, 'error');
                }
            }})
        ]);

        App.showModal('Game Transaction Feed', form, footer);
    }

    function infoItem(label, value) {
        return App.el('div', { className: 'info-item' }, [
            App.el('div', { className: 'info-label', textContent: label }),
            App.el('div', { className: 'info-value', textContent: String(value !== undefined && value !== null ? value : '-') })
        ]);
    }
})();
