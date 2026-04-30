/**
 * Games page — live activity feed, game directory, per-game detail with RPC actions.
 *
 * Three sections on the page:
 *   1. Live plays feed (recent game-play transactions, polled from local cache).
 *   2. Top games (by plays) for the current window.
 *   3. Games directory — searchable list with status badges; click to open
 *      a detail modal showing live status, supported RPC actions (e.g. reboot),
 *      and recent plays for that specific game.
 *
 * The play feed comes from /api/games/transactions/recent which reads the
 * local cache populated by the watchdog poller. Manual "Refresh" forces a
 * /api/games/transactions/poll first.
 */
(function() {
    App.registerRoute('#/games', { render: renderGamesPage });

    var REFRESH_MS = 15000;
    var FEED_LIMIT = 30;
    var TOP_LIMIT = 10;

    var refreshCleanup = null;
    var allGames = [];
    var topWindow = 'today';
    var searchTerm = '';
    var statusFilter = 'all';

    async function renderGamesPage(container) {
        // Header
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Games' }),
                App.el('p', { className: 'page-subtitle', textContent: 'Live play feed, top games, and per-game controls.' })
            ]),
            App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', {
                    className: 'btn btn-ghost',
                    textContent: 'Sync games',
                    onClick: function() { syncGames(); }
                }),
                App.el('button', {
                    className: 'btn btn-primary',
                    textContent: 'Refresh feed',
                    onClick: function() { manualPoll(); }
                })
            ])
        ]));

        // Two-column layout: feed + top games
        var twoCol = App.el('div', { className: 'games-two-col' }, [
            App.el('div', { id: 'games-feed-wrap', className: 'card' }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', { className: 'card-title', textContent: 'Live play feed' }),
                    App.el('span', { id: 'games-feed-meta', className: 'text-sm text-secondary' })
                ]),
                App.el('div', { id: 'games-feed-body', className: 'card-body' }, [App.loading()])
            ]),
            App.el('div', { id: 'games-top-wrap', className: 'card' }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', { className: 'card-title', textContent: 'Top games' }),
                    buildWindowSelector()
                ]),
                App.el('div', { id: 'games-top-body', className: 'card-body' }, [App.loading()])
            ])
        ]);
        container.appendChild(twoCol);

        // Games directory
        var dir = App.el('div', { className: 'card', style: { marginTop: '1rem' } }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', { className: 'card-title', textContent: 'Games directory' })
            ]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'flex gap-sm', style: { marginBottom: '0.75rem' } }, [
                    App.el('input', {
                        id: 'games-search',
                        className: 'form-input',
                        type: 'text',
                        placeholder: 'Search games…',
                        style: { flex: '1' },
                        onInput: function(e) {
                            searchTerm = (e.target.value || '').toLowerCase();
                            renderGameList();
                        }
                    }),
                    buildStatusFilter()
                ]),
                App.el('div', { id: 'games-list' }, [App.loading()])
            ])
        ]);
        container.appendChild(dir);

        // Initial loads
        await Promise.all([loadFeed(), loadTop(), loadGames()]);

        // Live refresh — only the feed and top widget. Game directory is
        // refreshed manually via the "Sync games" button to avoid hammering
        // CenterEdge.
        refreshCleanup = App.createVisibilityAwareInterval(function() {
            loadFeed();
            loadTop();
        }, REFRESH_MS, { runImmediately: false, runOnVisible: true });

        return function cleanup() {
            if (refreshCleanup) refreshCleanup();
            refreshCleanup = null;
        };
    }

    function buildWindowSelector() {
        var sel = App.el('select', {
            className: 'form-input form-input-sm',
            onChange: function(e) {
                topWindow = e.target.value;
                loadTop();
            }
        });
        [
            ['hour', 'Last hour'],
            ['today', 'Today'],
            ['week', 'Last 7 days'],
            ['all', 'All cached']
        ].forEach(function(opt) {
            var o = App.el('option', { value: opt[0], textContent: opt[1] });
            if (opt[0] === topWindow) o.selected = true;
            sel.appendChild(o);
        });
        return sel;
    }

    function buildStatusFilter() {
        var sel = App.el('select', {
            className: 'form-input form-input-sm',
            onChange: function(e) {
                statusFilter = e.target.value;
                renderGameList();
            }
        });
        [
            ['all', 'All statuses'],
            ['enabled', 'Running'],
            ['paused', 'Paused'],
            ['outOfService', 'Out of service']
        ].forEach(function(opt) {
            var o = App.el('option', { value: opt[0], textContent: opt[1] });
            if (opt[0] === statusFilter) o.selected = true;
            sel.appendChild(o);
        });
        return sel;
    }

    async function loadFeed() {
        var body = document.getElementById('games-feed-body');
        var meta = document.getElementById('games-feed-meta');
        if (!body) return;
        try {
            var data = await API.get('games/transactions/recent?limit=' + FEED_LIMIT);
            body.innerHTML = '';

            if (meta) {
                var pieces = [];
                if (data.last_poll_at) pieces.push('Last poll ' + App.formatRelative(data.last_poll_at));
                if (typeof data.total_cached === 'number') pieces.push(data.total_cached + ' cached');
                meta.textContent = pieces.join('  •  ');
            }

            var txs = data.transactions || [];
            if (txs.length === 0) {
                body.appendChild(App.emptyState('▢',
                    'No plays cached yet. The watchdog cron polls every minute; click "Refresh feed" to force a poll now.'));
                return;
            }

            var ul = App.el('ul', { className: 'plain-list' });
            txs.forEach(function(t) { ul.appendChild(buildFeedRow(t)); });
            body.appendChild(ul);

        } catch (err) {
            body.innerHTML = '';
            body.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Feed unavailable: ' + err.message }));
        }
    }

    function buildFeedRow(t) {
        var time = App.formatDatetime(t.transaction_time);
        var name = t.game_name || ('Game ' + t.game_id);
        var card = t.no_card ? 'no card' : (t.card_number || '-');

        var meta = [];
        if (t.used_time_play) meta.push('time play');
        if (t.used_play_privilege) meta.push('privilege');
        var amt = (parseFloat(t.regular_points) || 0) + (parseFloat(t.bonus_points) || 0);
        var tickets = parseFloat(t.redemption_tickets) || 0;
        if (amt) meta.push(formatPoints(amt) + ' pts');
        if (tickets) meta.push('+' + formatPoints(tickets) + ' tix');

        return App.el('li', { className: 'plain-list-item feed-row' }, [
            App.el('div', { className: 'feed-row-time text-sm text-secondary', textContent: time }),
            App.el('div', { className: 'feed-row-main' }, [
                App.el('div', { className: 'plain-list-title', textContent: name }),
                App.el('div', { className: 'text-sm text-secondary',
                    textContent: 'Card ' + card + (meta.length ? '  •  ' + meta.join(' • ') : '') })
            ])
        ]);
    }

    async function loadTop() {
        var body = document.getElementById('games-top-body');
        if (!body) return;
        try {
            var data = await API.get('games/transactions/top?window=' + encodeURIComponent(topWindow) + '&limit=' + TOP_LIMIT);
            body.innerHTML = '';
            var rows = data.top || [];
            if (rows.length === 0) {
                body.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'No plays in this window.' }));
                return;
            }
            var maxPlays = rows.reduce(function(m, r) { return Math.max(m, r.plays || 0); }, 0) || 1;

            var list = App.el('ol', { className: 'top-games-list' });
            rows.forEach(function(r, i) {
                var pct = Math.max(4, Math.round((r.plays / maxPlays) * 100));
                var name = r.game_name || ('Game ' + r.game_id);
                var meta = r.plays + (r.plays === 1 ? ' play' : ' plays');
                if (r.sum_tickets > 0) meta += '  •  ' + formatPoints(r.sum_tickets) + ' tickets';

                list.appendChild(App.el('li', { className: 'top-games-item' }, [
                    App.el('div', { className: 'top-games-rank', textContent: '#' + (i + 1) }),
                    App.el('div', { className: 'top-games-body' }, [
                        App.el('div', { className: 'plain-list-title', textContent: name }),
                        App.el('div', { className: 'top-games-bar' }, [
                            App.el('div', { className: 'top-games-bar-fill', style: { width: pct + '%' } })
                        ]),
                        App.el('div', { className: 'text-sm text-secondary', textContent: meta })
                    ])
                ]));
            });
            body.appendChild(list);
        } catch (err) {
            body.innerHTML = '';
            body.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Top games unavailable: ' + err.message }));
        }
    }

    async function loadGames() {
        try {
            var data = await API.get('games');
            allGames = data.games || [];
            renderGameList();
        } catch (err) {
            var listEl = document.getElementById('games-list');
            if (listEl) {
                listEl.innerHTML = '';
                listEl.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Failed to load games: ' + err.message }));
            }
        }
    }

    function renderGameList() {
        var listEl = document.getElementById('games-list');
        if (!listEl) return;

        var filtered = allGames.filter(function(g) {
            if (statusFilter !== 'all' && g.operation_status !== statusFilter) return false;
            if (searchTerm && (g.game_name || '').toLowerCase().indexOf(searchTerm) === -1
                && (g.game_id || '').toLowerCase().indexOf(searchTerm) === -1) return false;
            return true;
        });

        listEl.innerHTML = '';
        if (filtered.length === 0) {
            listEl.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'No games match these filters.' }));
            return;
        }

        var table = App.el('table', { className: 'data-table' }, [
            App.el('thead', {}, [
                App.el('tr', {}, [
                    App.el('th', { textContent: 'Name' }),
                    App.el('th', { textContent: 'ID' }),
                    App.el('th', { textContent: 'Status' }),
                    App.el('th', { className: 'text-right', textContent: '' })
                ])
            ]),
            App.el('tbody', {}, filtered.map(function(g) {
                return App.el('tr', {
                    className: 'clickable-row',
                    onClick: function() { showGameDetail(g.game_id); }
                }, [
                    App.el('td', { textContent: g.game_name || ('Game ' + g.game_id) }),
                    App.el('td', { className: 'text-sm text-secondary', textContent: g.game_id }),
                    App.el('td', {}, [App.statusBadge(g.operation_status || 'enabled')]),
                    App.el('td', { className: 'text-right' }, [
                        App.el('button', {
                            className: 'btn btn-sm btn-ghost',
                            textContent: 'Details',
                            onClick: function(e) { e.stopPropagation(); showGameDetail(g.game_id); }
                        })
                    ])
                ]);
            }))
        ]);
        listEl.appendChild(table);
    }

    async function showGameDetail(gameId) {
        var body = App.el('div', { id: 'game-detail-body' }, [App.loading()]);
        var footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', {
                className: 'btn btn-secondary',
                textContent: 'Close',
                onClick: function() { App.hideModal(); }
            })
        ]);
        App.showModal('Game detail', body, footer);

        try {
            var game = await API.get('games/' + encodeURIComponent(gameId));
            renderGameDetailModal(game);
        } catch (err) {
            body.innerHTML = '';
            if (err.status === 404) {
                body.appendChild(App.el('p', { className: 'text-secondary', textContent: 'Game not found.' }));
            } else {
                body.appendChild(App.el('p', { className: 'text-secondary', textContent: 'Failed to load: ' + err.message }));
            }
        }
    }

    function renderGameDetailModal(game) {
        var body = document.getElementById('game-detail-body');
        if (!body) return;
        body.innerHTML = '';

        body.appendChild(App.el('div', { className: 'flex-between' }, [
            App.el('div', {}, [
                App.el('div', { className: 'card-title', textContent: game.name || ('Game ' + game.id) }),
                App.el('p', { className: 'text-sm text-secondary', textContent: 'ID: ' + game.id })
            ]),
            App.statusBadge(game.operationStatus || 'enabled')
        ]));

        if (game.virtualPlayEnabled) {
            body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'Virtual play enabled.' }));
        }

        if (game.categories && game.categories.length) {
            body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'Categories: ' + game.categories.join(', ') }));
        }

        // RPC actions
        var actions = game.supportedActions || [];
        if (actions.length) {
            var actionsRow = App.el('div', { className: 'flex gap-sm', style: { marginTop: '1rem' } });
            actions.forEach(function(act) {
                actionsRow.appendChild(App.el('button', {
                    className: 'btn btn-sm btn-ghost',
                    textContent: act.name || act.id,
                    title: act.requireManager ? 'Manager only' : '',
                    onClick: function() { runAction(game, act); }
                }));
            });
            body.appendChild(App.el('div', { className: 'subsection' }, [
                App.el('div', { className: 'subsection-title', textContent: 'Actions' }),
                actionsRow
            ]));
        }

        // Recent plays for this game (filter the cached feed client-side)
        body.appendChild(App.el('div', { className: 'subsection' }, [
            App.el('div', { className: 'subsection-title', textContent: 'Recent plays' }),
            App.el('div', { id: 'game-detail-plays' }, [App.loading()])
        ]));

        loadGamePlays(game.id);
    }

    async function loadGamePlays(gameId) {
        var holder = document.getElementById('game-detail-plays');
        if (!holder) return;
        try {
            // No server-side filter for this slice — it's a small enough cache
            // to filter on the client. Pull a generous chunk to find this
            // game's recent plays.
            var data = await API.get('games/transactions/recent?limit=500');
            holder.innerHTML = '';
            var matched = (data.transactions || []).filter(function(t) {
                return String(t.game_id) === String(gameId);
            }).slice(0, 20);

            if (matched.length === 0) {
                holder.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'No recent plays cached for this game.' }));
                return;
            }
            var ul = App.el('ul', { className: 'plain-list' });
            matched.forEach(function(t) { ul.appendChild(buildFeedRow(t)); });
            holder.appendChild(ul);
        } catch (err) {
            holder.innerHTML = '';
            holder.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Failed to load plays: ' + err.message }));
        }
    }

    async function runAction(game, action) {
        var confirmed = await App.confirm('Run "' + (action.name || action.id) + '" on "' + (game.name || game.id) + '"?');
        if (!confirmed) return;
        try {
            await API.post('games/' + encodeURIComponent(game.id) + '/action', { actionId: action.id });
            App.toast('Action "' + (action.name || action.id) + '" sent.', 'success');
            // Re-fetch live state in case the action changed it
            try {
                var fresh = await API.get('games/' + encodeURIComponent(game.id));
                renderGameDetailModal(fresh);
            } catch (e) { /* swallow — modal still shows pre-action state */ }
        } catch (err) {
            App.toast('Action failed: ' + err.message, 'error');
        }
    }

    async function manualPoll() {
        try {
            var result = await API.post('games/transactions/poll');
            if (result && result.fetched > 0) {
                App.toast('Pulled ' + result.fetched + ' new play(s).', 'success');
            } else {
                App.toast('Feed already up to date.', 'info');
            }
        } catch (err) {
            App.toast('Poll failed: ' + err.message, 'error');
            return;
        }
        await Promise.all([loadFeed(), loadTop()]);
    }

    async function syncGames() {
        try {
            await API.post('games/sync');
            App.toast('Games synced.', 'success');
        } catch (err) {
            App.toast('Sync failed: ' + err.message, 'error');
            return;
        }
        await loadGames();
    }

    function formatPoints(n) {
        if (typeof n !== 'number') n = parseFloat(n) || 0;
        return n % 1 === 0 ? String(n.toFixed(0)) : n.toFixed(2);
    }
})();
