/**
 * Games page — searchable game directory with per-game detail and RPC
 * actions.
 *
 * The Dashboard owns the venue-wide live play feed and ticket pulse;
 * this page keeps the top-games-by-tickets leaderboard at the top
 * (its original home) for the analytics-capable roles, then drops into
 * a searchable directory with per-game detail and RPC actions.
 */
(function() {
    App.registerRoute('#/games', { render: renderGamesPage });

    var allGames = [];
    var ticketStats = {};
    var ticketStatsFailed = false;
    var searchTerm = '';
    var statusFilter = 'all';
    var dirSort = { key: 'tickets_today', dir: 'desc' };
    var dirPaging = { page: 1, pageSize: 25, totalItems: 0 };
    var topWindow = 'today';
    var TOP_LIMIT = 8;
    var TOP_WINDOWS = [
        ['hour', 'Last hour'],
        ['today', 'Today'],
        ['week', 'Last 7 days'],
        ['all', 'All cached']
    ];

    var VALID_STATUSES = ['enabled', 'paused', 'outOfService'];

    function topWindowLabel() {
        for (var i = 0; i < TOP_WINDOWS.length; i++) {
            if (TOP_WINDOWS[i][0] === topWindow) return TOP_WINDOWS[i][1];
        }
        return topWindow;
    }

    async function renderGamesPage(container, params) {
        // Apply deep-link query params from the hash. Dashboard tiles, top-
        // games rows, and feed rows navigate here with `?status=` (set the
        // status filter), `?search=` (pre-fill the search box), or `?game=`
        // (auto-open the detail modal once games are loaded).
        var query = (params && params._query) || {};
        if (query.status && (query.status === 'all' || VALID_STATUSES.indexOf(query.status) !== -1)) {
            statusFilter = query.status;
        } else {
            statusFilter = 'all';
        }
        searchTerm = (query.search || '').toLowerCase();
        dirPaging.page = 1;
        var pendingDetailId = query.game || '';
        var gen = App.navGeneration();

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Games' }),
                App.el('p', { className: 'page-subtitle', textContent: 'Search and control individual games. Top earners and live play activity are spotlighted on the Dashboard.' })
            ]),
            App.el('div', { className: 'flex gap-sm' }, App.canAccess('manual_control') ? [
                buildSyncButton('btn btn-primary')
            ] : [])
        ]));

        // Surface an active filter chip so it's obvious why the list is
        // narrowed when the user lands here from a dashboard tile.
        if (statusFilter !== 'all' || searchTerm) {
            container.appendChild(buildActiveFilterBanner());
        }

        // Top games leaderboard — the original home of this widget. Hidden
        // for the 'tech' role since it's a sales-data view.
        if (App.canAccess('analytics')) {
            container.appendChild(buildTopGamesCard());
        }

        var dirSearchInput = App.buildSearchInput({
            placeholder: 'Search games by name or ID…',
            ariaLabel: 'Search games directory',
            value: searchTerm,
            onSearch: function(term) {
                searchTerm = term.toLowerCase();
                dirPaging.page = 1;
                renderGameList();
            }
        });
        // A real flex-basis (not 0) lets the toolbar wrap the select onto its
        // own line on very narrow screens instead of crushing the search box.
        dirSearchInput.style.flex = '1 1 12rem';
        dirSearchInput.id = 'games-search';

        var dir = App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header flex-between' }, [
                App.el('div', { className: 'flex-center gap-sm' }, [
                    App.el('div', { className: 'card-title', textContent: 'Games directory' }),
                    App.el('span', { id: 'games-dir-meta', className: 'text-sm text-secondary' })
                ]),
                App.el('span', { className: 'text-xs text-muted', textContent: 'Click any row to open game detail' })
            ]),
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'flex gap-sm', style: { marginBottom: '0.75rem', flexWrap: 'wrap' } }, [
                    dirSearchInput,
                    buildStatusFilter()
                ]),
                App.el('div', { id: 'games-list' }, [App.loading()]),
                App.el('div', { id: 'games-list-pagination' })
            ])
        ]);
        container.appendChild(dir);

        // Ticket stats expose sales data; only fetch them for roles that
        // have analytics access. Games list itself is available to all roles.
        var loaders = [loadGames()];
        if (App.canAccess('analytics')) {
            loaders.push(loadTicketStats());
            loaders.push(loadTopGames());
        }
        await Promise.all(loaders);
        if (App.navGeneration() !== gen) return;

        // After games loaded, auto-open the detail modal if the caller
        // (e.g. a dashboard top-games tile) requested a specific game.
        if (pendingDetailId) {
            showGameDetail(pendingDetailId);
        }
    }

    /**
     * "Sync games" button with busy feedback — the POST is a live
     * CenterEdge round-trip that can take seconds, so disable + relabel
     * while it runs. Used by the page header and the empty state.
     */
    function buildSyncButton(className) {
        var btn = App.el('button', {
            className: className || 'btn btn-primary',
            textContent: 'Sync games',
            onClick: function() { syncGames(btn); }
        });
        return btn;
    }

    /**
     * Banner shown when the user arrives via a deep-link with filters
     * pre-applied. "Clear" rewrites the hash to plain `#/games` so the
     * filters reset and the URL stays shareable.
     */
    function buildActiveFilterBanner() {
        var pieces = [];
        if (statusFilter !== 'all') {
            var label = statusFilter === 'enabled' ? 'Running'
                      : statusFilter === 'paused' ? 'Paused'
                      : statusFilter === 'outOfService' ? 'Out of service'
                      : statusFilter;
            pieces.push(App.el('span', { className: 'badge badge-' +
                (statusFilter === 'enabled' ? 'enabled'
                : statusFilter === 'paused' ? 'paused'
                : 'inactive'), textContent: label }));
        }
        if (searchTerm) {
            pieces.push(App.el('span', { className: 'badge badge-info',
                textContent: 'search: "' + searchTerm + '"' }));
        }
        var banner = App.el('div', { className: 'deep-link-banner' }, [
            App.el('span', { className: 'deep-link-banner-label', textContent: 'Filters applied: ' }),
            App.el('span', { className: 'flex gap-sm' }, pieces),
            App.el('button', {
                className: 'btn btn-ghost btn-sm',
                textContent: 'Clear',
                onClick: function() { window.location.hash = '#/games'; }
            })
        ]);
        return banner;
    }

    function buildStatusFilter() {
        var sel = App.el('select', {
            className: 'form-input form-input-sm',
            id: 'games-status-filter',
            'aria-label': 'Filter by status',
            onChange: function(e) {
                statusFilter = e.target.value;
                dirPaging.page = 1;
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

    async function loadGames() {
        var gen = App.navGeneration();
        try {
            var data = await API.get('games');
            if (App.navGeneration() !== gen) return;
            allGames = data.games || [];
            renderGameList();
        } catch (err) {
            if (App.navGeneration() !== gen) return;
            var listEl = document.getElementById('games-list');
            if (listEl) {
                listEl.innerHTML = '';
                listEl.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Failed to load games: ' + err.message }));
            }
        }
    }

    function renderGameList() {
        var listEl = document.getElementById('games-list');
        var pagerEl = document.getElementById('games-list-pagination');
        if (!listEl) return;

        var filtered = allGames.filter(function(g) {
            if (statusFilter !== 'all' && g.operation_status !== statusFilter) return false;
            if (searchTerm && (g.game_name || '').toLowerCase().indexOf(searchTerm) === -1
                && (g.game_id || '').toLowerCase().indexOf(searchTerm) === -1) return false;
            return true;
        });

        // Sales columns (plays/tickets/last play) are hidden from the
        // 'tech' role. Status + actions stay visible so techs can still
        // pause/unpause individual games.
        var includeSales = App.canAccess('analytics');
        var statusRank = { enabled: 0, paused: 1, outOfService: 2 };
        var columns = [
            { key: 'game_name', label: 'Name' },
            {
                key: 'operation_status', label: 'Status', type: 'number', defaultDir: 'asc',
                sortValue: function(g) {
                    return statusRank[g.operation_status] != null ? statusRank[g.operation_status] : 3;
                }
            }
        ];
        if (includeSales) {
            columns.push({ key: 'plays_today', label: 'Plays today', className: 'text-right', type: 'number',
                sortValue: function(g) { var s = ticketStats[g.game_id]; return s ? (s.plays_today || 0) : 0; } });
            columns.push({ key: 'tickets_today', label: 'Tickets today', className: 'text-right', type: 'number',
                sortValue: function(g) { var s = ticketStats[g.game_id]; return s ? (s.tickets_today || 0) : 0; } });
            columns.push({ key: 'tickets_week', label: '7 days', className: 'text-right', type: 'number',
                sortValue: function(g) { var s = ticketStats[g.game_id]; return s ? (s.tickets_week || 0) : 0; } });
            columns.push({ key: 'last_play', label: 'Last play', type: 'date',
                sortValue: function(g) { var s = ticketStats[g.game_id]; return s ? s.last_play : null; } });
        }
        columns.push({ key: '_actions', label: '', sortable: false, className: 'text-right' });

        // Repair sort selection if a previously-active sales column is
        // hidden for the current role.
        if (!includeSales && (dirSort.key === 'plays_today' || dirSort.key === 'tickets_today'
                || dirSort.key === 'tickets_week' || dirSort.key === 'last_play')) {
            dirSort = { key: 'game_name', dir: 'asc' };
        }

        filtered = App.sortRows(filtered, dirSort, columns);

        // Update directory meta line: e.g. "31 games · 12 active in last hour"
        var meta = document.getElementById('games-dir-meta');
        if (meta) {
            var activeNow = filtered.filter(function(g) {
                var s = ticketStats[g.game_id];
                return s && (s.tickets_hour > 0 || s.plays_hour > 0);
            }).length;
            var pieces = [filtered.length + ' game' + (filtered.length === 1 ? '' : 's')];
            if (filtered.length !== allGames.length) pieces.push('of ' + allGames.length + ' total');
            if (activeNow > 0) pieces.push(activeNow + ' active in last hour');
            if (includeSales && ticketStatsFailed) pieces.push('ticket stats unavailable');
            meta.textContent = pieces.join('  •  ');
        }

        listEl.innerHTML = '';
        if (filtered.length === 0) {
            listEl.appendChild(buildDirectoryEmptyState());
            if (pagerEl) pagerEl.innerHTML = '';
            return;
        }

        // Paginate so 100+ games stays scannable. Sort still applies before slicing.
        dirPaging.totalItems = filtered.length;
        var page = App.paginate(filtered, dirPaging.page, dirPaging.pageSize);
        dirPaging.page = page.page;
        var pageItems = page.items;

        // Pre-compute the busiest game across the filtered set so each row's
        // bar stays comparable across pages.
        var maxTickets = 0;
        filtered.forEach(function(g) {
            var s = ticketStats[g.game_id];
            var v = s ? (s.tickets_today || 0) : 0;
            if (v > maxTickets) maxTickets = v;
        });

        var thead = App.el('thead', {}, [
            App.el('tr', {}, columns.map(function(col) {
                return App.sortableTh(col, dirSort, function(next) {
                    dirSort = next;
                    renderGameList();
                });
            }))
        ]);

        var tbody = App.el('tbody', {}, pageItems.map(function(g) {
            var s = ticketStats[g.game_id] || {};
            var plays = s.plays_today || 0;
            var tickets = Math.round(s.tickets_today || 0);
            var ticketsHour = Math.round(s.tickets_hour || 0);
            var pct = maxTickets > 0 ? Math.min(100, Math.round((tickets / maxTickets) * 100)) : 0;

            var cells = [
                App.el('td', {}, [
                    App.el('div', { textContent: g.game_name || ('Game ' + g.game_id), style: { fontWeight: '500' } }),
                    App.el('div', { className: 'text-xs text-muted font-mono', textContent: g.game_id })
                ]),
                App.el('td', {}, [App.statusBadge(g.operation_status || 'enabled')])
            ];
            if (includeSales) {
                cells.push(App.el('td', { className: 'text-right num-cell',
                    textContent: plays > 0 ? plays.toLocaleString() : '—' }));
                cells.push(App.el('td', {
                    className: 'text-right num-cell',
                    'aria-label': (tickets > 0 ? tickets.toLocaleString() : 'no') + ' tickets today'
                        + (ticketsHour > 0 ? ', ' + ticketsHour + ' in last hour' : '')
                }, [
                    App.el('div', { className: 'tickets-cell' }, [
                        ticketsHour > 0 ? App.el('span', { className: 'tickets-dot',
                            'aria-hidden': 'true',
                            title: ticketsHour + ' in last hour' }) : null,
                        App.el('span', {
                            className: tickets > 0 ? 'tickets-amount' : 'text-muted',
                            textContent: tickets > 0 ? tickets.toLocaleString() : '—'
                        })
                    ].filter(Boolean)),
                    App.el('div', { className: 'tickets-bar', 'aria-hidden': 'true',
                        title: tickets + ' tickets today' }, [
                        App.el('div', {
                            className: 'tickets-bar-fill' + (tickets > 0 ? ' has-tickets' : ''),
                            style: { width: Math.max(0, pct) + '%' }
                        })
                    ])
                ]));
                cells.push(App.el('td', { className: 'text-right num-cell text-secondary',
                    textContent: s.tickets_week ? Math.round(s.tickets_week).toLocaleString() : '—' }));
                cells.push(App.el('td', {
                    className: 'text-sm text-secondary',
                    textContent: s.last_play ? App.formatRelative(s.last_play) : '—',
                    title: s.last_play ? App.formatDatetime(s.last_play) : ''
                }));
            }
            cells.push(App.el('td', { className: 'text-right' }, [
                App.el('div', {
                    className: 'flex gap-sm',
                    style: { justifyContent: 'flex-end', flexWrap: 'wrap' }
                }, buildStatusButtons(g).concat([
                    App.el('button', {
                        className: 'btn btn-sm btn-ghost',
                        textContent: 'Details',
                        onClick: function() { showGameDetail(g.game_id); }
                    })
                ]))
            ]));

            var tr = App.el('tr', { className: 'clickable-row' }, cells);
            App.makeCardClickable(tr, function() { showGameDetail(g.game_id); },
                { title: 'Open game detail' });
            return tr;
        }));

        var table = App.el('table', { className: 'data-table directory-table' }, [thead, tbody]);
        listEl.appendChild(App.el('div', { className: 'table-wrapper' }, [table]));

        if (pagerEl) {
            pagerEl.innerHTML = '';
            pagerEl.appendChild(App.buildPaginationBar(dirPaging, function() { renderGameList(); }, {
                pageSizeOptions: [25, 50, 100, 200],
                itemLabel: 'games',
                showPageNumbers: true
            }));
        }
    }

    /**
     * Empty directory list: a fresh install with nothing synced yet needs
     * guidance (and a sync shortcut), not a filter-mismatch message.
     */
    function buildDirectoryEmptyState() {
        if (allGames.length === 0) {
            return App.emptyState('🎮',
                'No games synced yet. Sync pulls the game list from CenterEdge — check the connection in Settings if nothing comes back.',
                App.canAccess('manual_control') ? buildSyncButton('btn btn-primary') : null);
        }
        return App.emptyState('🔍', 'No games match these filters.', App.el('button', {
            className: 'btn btn-secondary btn-sm',
            textContent: 'Clear filters',
            onClick: function() {
                searchTerm = '';
                statusFilter = 'all';
                dirPaging.page = 1;
                var si = document.getElementById('games-search');
                if (si) si.value = '';
                var sf = document.getElementById('games-status-filter');
                if (sf) sf.value = 'all';
                if (window.location.hash.indexOf('#/games?') === 0) {
                    // Deep-linked filters: rewriting the hash re-renders the
                    // page and clears the filter banner too.
                    window.location.hash = '#/games';
                } else {
                    renderGameList();
                }
            }
        }));
    }

    async function loadTicketStats() {
        var gen = App.navGeneration();
        try {
            var data = await API.get('games/transactions/stats');
            if (App.navGeneration() !== gen) return;
            ticketStats = data.stats || {};
            ticketStatsFailed = false;
            renderGameList();  // re-render to pick up new ticket data
        } catch (err) {
            // Non-fatal — directory still renders without ticket stats, but
            // surface the gap in the meta line so '—' cells aren't mistaken
            // for a quiet day.
            if (App.navGeneration() !== gen) return;
            ticketStatsFailed = true;
            renderGameList();
        }
    }

    /**
     * Top-games-by-tickets leaderboard card. The dashboard surfaces an
     * always-visible compact version next to the live feed; this is the
     * original home of the widget and gives the operator a focused
     * leaderboard at the top of the games directory.
     */
    function buildTopGamesCard() {
        var sel = App.el('select', {
            className: 'form-input form-input-sm',
            'aria-label': 'Top games time window',
            onChange: function(e) {
                topWindow = e.target.value;
                loadTopGames();
            }
        });
        TOP_WINDOWS.forEach(function(opt) {
            var o = App.el('option', { value: opt[0], textContent: opt[1] });
            if (opt[0] === topWindow) o.selected = true;
            sel.appendChild(o);
        });

        return App.el('div', { className: 'games-leaderboard-grid' }, [
            App.el('div', { className: 'card top-games-card', id: 'games-top-card' }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', { className: 'card-title', textContent: 'Top by tickets' }),
                    App.el('div', { className: 'flex-center gap-sm' }, [
                        App.el('span', { className: 'text-xs text-muted', textContent: 'click a row to drill in' }),
                        sel
                    ])
                ]),
                App.el('div', { id: 'games-top-body', className: 'card-body' }, [App.loading()])
            ]),
            App.el('div', { className: 'card top-games-card', id: 'games-topplays-card' }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', { className: 'card-title', textContent: 'Top by plays' }),
                    // Echo the shared window selector's choice so this card
                    // isn't ambiguous when the grid stacks on narrow screens.
                    App.el('span', { id: 'games-topplays-window', className: 'text-xs text-muted',
                        textContent: topWindowLabel() })
                ]),
                App.el('div', { id: 'games-topplays-body', className: 'card-body' }, [App.loading()])
            ])
        ]);
    }

    async function loadTopGames() {
        var echo = document.getElementById('games-topplays-window');
        if (echo) echo.textContent = topWindowLabel();
        // Two compact leaderboards side by side, sharing the window selector.
        await Promise.all([
            renderTopList('games-top-body', 'tickets'),
            renderTopList('games-topplays-body', 'plays')
        ]);
    }

    // Render one leaderboard for the given metric ('tickets' | 'plays') into the
    // element with the given id — the spotlight number/bar/label are the chosen
    // metric, the secondary line shows the other. Same style for both cards.
    async function renderTopList(bodyId, metric) {
        var body = document.getElementById(bodyId);
        if (!body) return;
        var gen = App.navGeneration();
        var win = topWindow;
        try {
            var data = await API.get('games/transactions/top?window=' + encodeURIComponent(topWindow)
                + '&sort=' + metric + '&limit=' + TOP_LIMIT);
            if (App.navGeneration() !== gen || topWindow !== win) return;
            var rows = data.top || [];
            body.innerHTML = '';

            if (rows.length === 0) {
                body.appendChild(App.emptyState('🏆',
                    'No plays in this window yet. Pick a wider window or wait for the next play.'));
                return;
            }

            var valOf = function(r) { return metric === 'plays' ? (r.plays || 0) : (r.sum_tickets || 0); };
            var maxVal = rows.reduce(function(m, r) { return Math.max(m, valOf(r)); }, 0) || 1;
            var medals = ['🥇', '🥈', '🥉'];

            var list = App.el('ol', { className: 'top-games-list top-games-list-modern' });
            rows.forEach(function(r, i) {
                var name = r.game_name || ('Game ' + r.game_id);
                var val = valOf(r);
                var hasMetric = val > 0;
                var pct = Math.max(4, Math.round((val / maxVal) * 100));

                var rankEl = i < 3
                    ? App.el('div', { className: 'top-games-rank top-games-rank-medal top-games-rank-' + (i + 1) }, [
                          App.el('span', { className: 'top-games-medal', textContent: medals[i] })
                      ])
                    : App.el('div', { className: 'top-games-rank', textContent: '#' + (i + 1) });

                var spotlightValue = hasMetric
                    ? (metric === 'plays' ? val.toLocaleString() : Math.round(val).toLocaleString())
                    : '—';
                var spotlightLabel = metric === 'plays' ? (val === 1 ? 'play' : 'plays') : 'tickets';
                var spotlight = App.el('div', { className: 'top-games-spotlight' }, [
                    App.el('div', {
                        className: 'top-games-spotlight-value' + (hasMetric ? ' has-tickets' : ' no-tickets'),
                        textContent: spotlightValue
                    }),
                    App.el('div', { className: 'top-games-spotlight-label', textContent: spotlightLabel })
                ]);

                var otherLine = metric === 'plays'
                    ? ((r.sum_tickets || 0) > 0 ? Math.round(r.sum_tickets).toLocaleString() + ' tickets' : 'no tickets')
                    : ((r.plays || 0).toLocaleString() + ((r.plays || 0) === 1 ? ' play' : ' plays'));

                var item = App.el('li', { className: 'top-games-item top-games-item-modern' }, [
                    rankEl,
                    App.el('div', { className: 'top-games-body' }, [
                        App.el('div', { className: 'top-games-name', textContent: name, title: name }),
                        App.el('div', { className: 'top-games-bar' }, [
                            App.el('div', {
                                className: 'top-games-bar-fill' + (hasMetric ? ' has-tickets' : ''),
                                style: { width: pct + '%' }
                            })
                        ]),
                        App.el('div', { className: 'top-games-meta text-xs text-secondary', textContent: otherLine })
                    ]),
                    spotlight
                ]);
                if (r.game_id) {
                    // Already on the Games page — open the modal directly. A
                    // '?game=' hash link would be a silent no-op on the second
                    // click (hashchange-only router) and would wipe active
                    // filters by re-rendering the whole page.
                    App.makeCardClickable(item, function() { showGameDetail(r.game_id); },
                        { title: 'Open game detail for ' + name, role: 'button' });
                }
                list.appendChild(item);
            });
            body.appendChild(list);
        } catch (err) {
            if (App.navGeneration() !== gen || topWindow !== win) return;
            body.innerHTML = '';
            body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'Top games unavailable: ' + err.message }));
        }
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

        // Ticket / play stats panel
        var s = ticketStats[game.id] || {};
        var avgToday = (s.plays_today > 0) ? (s.tickets_today / s.plays_today) : 0;
        body.appendChild(App.el('div', { className: 'subsection' }, [
            App.el('div', { className: 'subsection-title', textContent: 'Ticket activity' }),
            App.el('div', { className: 'detail-stats-grid' }, [
                App.el('div', { className: 'detail-stat' }, [
                    App.el('div', { className: 'detail-stat-label', textContent: 'Tickets today' }),
                    App.el('div', { className: 'detail-stat-value text-tickets',
                        textContent: formatBigNumber(Math.round(s.tickets_today || 0)) })
                ]),
                App.el('div', { className: 'detail-stat' }, [
                    App.el('div', { className: 'detail-stat-label', textContent: 'Plays today' }),
                    App.el('div', { className: 'detail-stat-value',
                        textContent: formatBigNumber(s.plays_today || 0) })
                ]),
                App.el('div', { className: 'detail-stat' }, [
                    App.el('div', { className: 'detail-stat-label', textContent: 'Avg / play' }),
                    App.el('div', { className: 'detail-stat-value',
                        textContent: avgToday > 0 ? formatPoints(avgToday) : '—' })
                ]),
                App.el('div', { className: 'detail-stat' }, [
                    App.el('div', { className: 'detail-stat-label', textContent: 'Last play' }),
                    App.el('div', { className: 'detail-stat-value text-sm',
                        textContent: s.last_play ? App.formatRelative(s.last_play) : '—',
                        title: s.last_play ? App.formatDatetime(s.last_play) : '' })
                ]),
                App.el('div', { className: 'detail-stat' }, [
                    App.el('div', { className: 'detail-stat-label', textContent: 'Last hour' }),
                    App.el('div', { className: 'detail-stat-value' }, [
                        App.el('span', {
                            textContent: formatBigNumber(Math.round(s.tickets_hour || 0)) + ' tix'
                        }),
                        App.el('span', { className: 'text-secondary text-sm',
                            textContent: '  •  ' + (s.plays_hour || 0) + (s.plays_hour === 1 ? ' play' : ' plays') })
                    ])
                ]),
                App.el('div', { className: 'detail-stat' }, [
                    App.el('div', { className: 'detail-stat-label', textContent: 'Last 7 days' }),
                    App.el('div', { className: 'detail-stat-value' }, [
                        App.el('span', {
                            textContent: formatBigNumber(Math.round(s.tickets_week || 0)) + ' tix'
                        }),
                        App.el('span', { className: 'text-secondary text-sm',
                            textContent: '  •  ' + formatBigNumber(s.plays_week || 0) + ' plays' })
                    ])
                ]),
                App.el('div', { className: 'detail-stat' }, [
                    App.el('div', { className: 'detail-stat-label', textContent: 'All-time' }),
                    App.el('div', { className: 'detail-stat-value' }, [
                        App.el('span', {
                            textContent: formatBigNumber(Math.round(s.tickets_all || 0)) + ' tix'
                        }),
                        App.el('span', { className: 'text-secondary text-sm',
                            textContent: '  •  ' + formatBigNumber(s.plays_all || 0) + ' plays' })
                    ])
                ])
            ])
        ]));

        if (game.categories && game.categories.length) {
            body.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'Categories: ' + game.categories.join(', ') }));
        }

        // Status controls — Pause is one-shot (next scheduled state change for
        // the game's pause group resumes it); Out of service sticks because the
        // scheduler skips outOfService games. Hidden for roles without
        // manual_control (view-only roles see live status, no buttons).
        if (App.canAccess('manual_control')) {
            body.appendChild(App.el('div', { className: 'subsection' }, [
                App.el('div', { className: 'subsection-title', textContent: 'Status' }),
                App.el('div', { className: 'flex gap-sm', style: { flexWrap: 'wrap' } }, buildStatusButtons(game)),
                App.el('p', { className: 'text-xs text-muted', style: { marginTop: '0.5rem', marginBottom: '0' } }, [
                    document.createTextNode('Pause resumes on the next scheduled state change. Out of service is sticky — the scheduler will skip this game until you return it to service.')
                ])
            ]));
        }

        // RPC actions
        var actions = App.canAccess('manual_control') ? (game.supportedActions || []) : [];
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

        // Recent plays for this game (filter the cached feed client-side).
        // Hidden from the 'tech' role since the feed exposes ticket and
        // cash data.
        if (App.canAccess('analytics')) {
            body.appendChild(App.el('div', { className: 'subsection' }, [
                App.el('div', { className: 'subsection-title', textContent: 'Recent plays' }),
                App.el('div', { id: 'game-detail-plays' }, [App.loading()])
            ]));
            loadGamePlays(game.id);
        }
    }

    async function loadGamePlays(gameId) {
        var holder = document.getElementById('game-detail-plays');
        if (!holder) return;
        try {
            // Fetch a generous chunk of recent transactions and filter client-
            // side. The dashboard owns the scrollable feed; this modal just
            // needs the last few plays for one specific game.
            var data = await API.get('games/transactions/recent?limit=500');
            // Modal may have been closed (or reopened for another game) while
            // the fetch was in flight — the old holder is detached then.
            if (!holder.isConnected) return;
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
            if (!holder.isConnected) return;
            holder.innerHTML = '';
            holder.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Failed to load plays: ' + err.message }));
        }
    }

    /** Compact one-line row for a transaction — also used by the detail modal. */
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

    /**
     * Build status-change buttons for a game. Used by both the directory row
     * (where `g.game_id` / `g.operation_status` come from the local cache)
     * and the detail modal (where `game.id` / `game.operationStatus` come
     * from the live CenterEdge response). Only buttons that change state
     * from the current status are included.
     */
    function buildStatusButtons(game) {
        // Every call site (directory rows + detail modal) goes through here,
        // so one gate keeps view-only roles button-free everywhere.
        if (!App.canAccess('manual_control')) return [];
        var id = game.id != null ? game.id : game.game_id;
        var name = game.name || game.game_name || ('Game ' + id);
        var status = game.operationStatus || game.operation_status || 'enabled';
        var ctx = { id: id, name: name, operationStatus: status };
        var btns = [];

        if (status !== 'enabled') {
            btns.push(App.el('button', {
                className: 'btn btn-sm btn-success',
                textContent: status === 'outOfService' ? 'Return to service' : 'Unpause',
                onClick: function() { doStatusChange(ctx, 'enabled'); }
            }));
        }
        if (status !== 'paused') {
            btns.push(App.el('button', {
                className: 'btn btn-sm btn-warning',
                textContent: 'Pause',
                title: 'One-shot — next scheduled state change for the pause group will resume this game',
                onClick: function() { doStatusChange(ctx, 'paused'); }
            }));
        }
        if (status !== 'outOfService') {
            btns.push(App.el('button', {
                className: 'btn btn-sm btn-ghost',
                textContent: 'Out of service',
                title: 'Sticks — scheduler will skip this game until you return it to service',
                onClick: function() { doStatusChange(ctx, 'outOfService'); }
            }));
        }
        return btns;
    }

    async function doStatusChange(game, target) {
        var current = game.operationStatus || 'enabled';
        var label = target === 'enabled'
                ? (current === 'outOfService' ? 'Return to service' : 'Unpause')
            : target === 'paused' ? 'Pause'
            : 'Take out of service';
        var confirmed = await App.confirm(label + ' "' + (game.name || game.id) + '"?');
        if (!confirmed) return;

        var payload = { games: {} };
        payload.games[game.id] = [
            { op: 'replace', path: '/operationStatus', value: target }
        ];

        try {
            var result = await API.patch('games', payload);
            var errs = (result && result.errors) || {};
            var err = errs[game.id];
            if (err) {
                var msg = (err && typeof err === 'object') ? (err.message || JSON.stringify(err)) : String(err);
                App.toast('Failed: ' + msg, 'error');
            } else {
                App.toast(label + ' applied.', 'success');
            }
        } catch (e) {
            App.toast('Status change failed: ' + e.message, 'error');
        }

        // Re-fetch live state for the modal (only when it's open — directory
        // row actions shouldn't pay a live CenterEdge round-trip for a modal
        // that isn't there) and refresh the directory cache view.
        if (document.getElementById('game-detail-body')) {
            try {
                var fresh = await API.get('games/' + encodeURIComponent(game.id));
                renderGameDetailModal(fresh);
            } catch (e) { /* swallow — modal still shows pre-action state */ }
        }
        loadGames();
    }

    async function syncGames(btn) {
        if (btn && btn.disabled) return;
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Syncing…';
        }
        try {
            await API.post('games/sync');
            App.toast('Games synced.', 'success');
            await loadGames();
        } catch (err) {
            App.toast('Sync failed: ' + err.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Sync games';
            }
        }
    }

    function formatPoints(n) {
        if (typeof n !== 'number') n = parseFloat(n) || 0;
        return n % 1 === 0 ? String(n.toFixed(0)) : n.toFixed(2);
    }

    /** Compact formatter — 1.2k / 4.5M for big counts. */
    function formatBigNumber(n) {
        n = parseFloat(n) || 0;
        if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (n >= 10000)   return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
        return Math.round(n).toLocaleString();
    }
})();
