/**
 * Tag Board — phone-first page for arcade floor staff to tag games out of
 * service (and back in) as they break and get fixed.
 *
 * Same data and actions as the Games page (GET /api/games +
 * PATCH /api/games), none of the analytics: the top cards surface what is
 * currently tagged out / paused, and the directory below is a searchable,
 * touch-friendly list where every row carries one obvious action. The list
 * auto-refreshes while the page is visible so two employees on the floor
 * see each other's tags without pulling to refresh.
 *
 * "Tagged out" = CenterEdge operationStatus 'outOfService' — sticky, the
 * scheduler skips those games until someone tags them back in. 'paused' is
 * shown for completeness but is normally schedule-owned.
 */
(function() {
    App.registerRoute('#/tags', { render: renderTagsPage });

    var allGames = [];
    var searchTerm = '';
    var statusFilter = 'all';
    var loadedOnce = false;
    var loadFailed = null;
    /** game_ids with a PATCH in flight — their buttons render disabled. */
    var busyIds = {};
    /** True while the bulk unpause-all request is in flight. */
    var bulkBusy = false;
    /**
     * Monotonic fetch sequence. Every loadGames() claims the next number and
     * only the NEWEST response may land — otherwise a slow poll GET that was
     * already in flight when a tag was confirmed would overwrite the fresh
     * state with pre-tag data for a whole poll cycle.
     */
    var loadSeq = 0;
    /** Signature of the last rendered data, to skip no-op re-renders. */
    var renderedSig = null;

    var FILTERS = [
        ['all', 'All'],
        ['enabled', 'Running'],
        ['paused', 'Paused'],
        ['outOfService', 'Tagged out']
    ];

    function renderTagsPage(container) {
        searchTerm = '';
        statusFilter = 'all';
        loadedOnce = false;
        loadFailed = null;
        busyIds = {};
        bulkBusy = false;
        renderedSig = null;

        container.appendChild(App.el('div', { className: 'page-header tags-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Tag Board' }),
                App.el('p', { className: 'page-subtitle', textContent:
                    'Tag a game out when it goes down, back in when it’s fixed. Updates on this board are live for everyone.' })
            ]),
            App.el('div', { className: 'flex gap-sm' }, [buildRefreshButton()])
        ]));

        // At-a-glance counts. Tapping a chip applies that status filter to
        // the directory below — the fastest "show me what's down" gesture.
        container.appendChild(App.el('div', { className: 'tags-summary', id: 'tags-summary' }));

        // Attention cards: what is currently tagged out / paused. Stacked on
        // phones, side by side on wide screens.
        container.appendChild(App.el('div', { className: 'tags-attn-grid' }, [
            App.el('div', { className: 'card tags-attn-card tags-attn-out' }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', { className: 'card-title', textContent: 'Tagged out' }),
                    App.el('span', { className: 'text-xs text-muted', id: 'tags-out-count' })
                ]),
                App.el('div', { className: 'card-body tags-attn-body', id: 'tags-out-body' }, [App.loading()])
            ]),
            App.el('div', { className: 'card tags-attn-card tags-attn-paused' }, [
                App.el('div', { className: 'card-header flex-between' }, [
                    App.el('div', { className: 'card-title', textContent: 'Paused' }),
                    App.el('div', { className: 'tags-attn-head' }, [
                        App.el('span', { className: 'text-xs text-muted', id: 'tags-paused-count' }),
                        App.el('span', { id: 'tags-paused-actions' })
                    ])
                ]),
                App.el('div', { className: 'card-body tags-attn-body', id: 'tags-paused-body' }, [App.loading()])
            ])
        ]));

        // The searchable directory.
        var searchInput = App.buildSearchInput({
            placeholder: 'Search games…',
            ariaLabel: 'Search games',
            className: 'form-input tags-search-input',
            onSearch: function(term) {
                searchTerm = term.toLowerCase();
                renderList();
            }
        });
        searchInput.id = 'tags-search';
        searchInput.setAttribute('enterkeyhint', 'search');
        searchInput.setAttribute('autocomplete', 'off');

        // 1px sentinel above the toolbar: when it scrolls out of view the
        // toolbar is pinned, and on phones it gets extra left padding so the
        // fixed hamburger button (top-left, z-index 200) doesn't cover the
        // start of the search box.
        var sentinel = App.el('div', { className: 'tags-toolbar-sentinel', 'aria-hidden': 'true' });
        var toolbar = App.el('div', { className: 'tags-toolbar' }, [
            searchInput,
            App.el('div', { className: 'tags-chipbar', id: 'tags-chips', role: 'group',
                'aria-label': 'Filter by status' })
        ]);

        container.appendChild(App.el('div', { className: 'card tags-dir-card' }, [
            App.el('div', { className: 'card-header flex-between' }, [
                App.el('div', { className: 'card-title', textContent: 'All games' }),
                App.el('span', { className: 'text-sm text-secondary', id: 'tags-meta' })
            ]),
            App.el('div', { className: 'card-body' }, [
                sentinel,
                toolbar,
                App.el('div', { id: 'tags-list' }, [App.loading()])
            ])
        ]));

        var stuckObserver = null;
        if (typeof IntersectionObserver !== 'undefined') {
            stuckObserver = new IntersectionObserver(function(entries) {
                toolbar.classList.toggle('is-stuck', !entries[0].isIntersecting);
            });
            stuckObserver.observe(sentinel);
        }

        renderChips();
        loadGames();

        // Keep the board live while it's on screen. Two people tagging from
        // different phones converge within one poll cycle.
        var stopPolling = App.createVisibilityAwareInterval(function() {
            loadGames();
        }, 25000, { runOnVisible: true });

        return function cleanup() {
            stopPolling();
            if (stuckObserver) stuckObserver.disconnect();
        };
    }

    function buildRefreshButton() {
        var btn = App.el('button', {
            className: 'btn btn-secondary tags-refresh-btn',
            type: 'button',
            'aria-label': 'Refresh the board now',
            title: 'Refresh now',
            onClick: async function() {
                btn.disabled = true;
                await loadGames();
                btn.disabled = false;
            }
        });
        btn.innerHTML = Icons.refresh;
        btn.appendChild(App.el('span', { textContent: 'Refresh' }));
        return btn;
    }

    /** Cheap change signature — poll re-renders only when something moved. */
    function dataSig() {
        var parts = [loadFailed ? 'F' : 'ok'];
        for (var i = 0; i < allGames.length; i++) {
            var g = allGames[i];
            parts.push(g.game_id + ':' + (g.operation_status || 'enabled')
                + (g.pending_retry ? ':r' + g.pending_retry.attempts : ''));
        }
        return parts.join('|');
    }

    async function loadGames() {
        var gen = App.navGeneration();
        var seq = ++loadSeq;
        try {
            var data = await API.get('games');
            if (App.navGeneration() !== gen || seq !== loadSeq) return;
            allGames = data.games || [];
            loadFailed = null;
        } catch (err) {
            if (App.navGeneration() !== gen || seq !== loadSeq) return;
            // Keep showing the last good list (staff may be mid-search on
            // flaky venue Wi-Fi) — surface the failure in the meta line, or
            // as the whole board when there's nothing cached yet.
            loadFailed = err.message;
        }
        var first = !loadedOnce;
        loadedOnce = true;
        // Skip the re-render when nothing changed: the 25s poll must not
        // wipe and rebuild the row DOM (swallowing an in-flight tap or the
        // user's focus) just to draw an identical board.
        var sig = dataSig();
        if (first || sig !== renderedSig) {
            renderAll();
        }
    }

    function renderAll() {
        renderedSig = dataSig();
        renderSummary();
        renderAttention('outOfService', 'tags-out-body', 'tags-out-count');
        renderAttention('paused', 'tags-paused-body', 'tags-paused-count');
        renderList();
    }

    /** True while we've never successfully loaded anything. */
    function nothingKnown() {
        return !!loadFailed && allGames.length === 0;
    }

    function countByStatus(status) {
        return allGames.filter(function(g) { return (g.operation_status || 'enabled') === status; }).length;
    }

    /**
     * Status badge with this page's vocabulary: the whole board says
     * "tagged out", so the row badge does too instead of switching to the
     * Games page's "Out of service" for the same state.
     */
    function tagsBadge(status) {
        if (status !== 'outOfService') return App.statusBadge(status);
        return App.el('span', {
            className: 'badge badge-out-of-service',
            role: 'status',
            'aria-label': 'Status: tagged out'
        }, [
            App.el('span', { className: 'badge-dot badge-dot-danger', 'aria-hidden': 'true' }),
            App.el('span', { textContent: 'Tagged out' })
        ]);
    }

    function renderSummary() {
        var host = document.getElementById('tags-summary');
        if (!host) return;
        var haveData = loadedOnce && !nothingKnown();
        host.innerHTML = '';
        [
            ['enabled', 'Running', 'success'],
            ['paused', 'Paused', 'warning'],
            ['outOfService', 'Tagged out', 'danger']
        ].forEach(function(def) {
            var count = countByStatus(def[0]);
            var chip = App.el('button', {
                className: 'tags-stat tags-stat-' + def[2] + (statusFilter === def[0] ? ' active' : ''),
                type: 'button',
                'aria-pressed': statusFilter === def[0] ? 'true' : 'false',
                'aria-label': (haveData ? count : 'unknown') + ' ' + def[1].toLowerCase() + ' — filter the list',
                onClick: function() {
                    statusFilter = statusFilter === def[0] ? 'all' : def[0];
                    renderChips();
                    renderSummary();
                    renderList();
                }
            }, [
                App.el('span', { className: 'tags-stat-value', textContent: haveData ? String(count) : '–' }),
                App.el('span', { className: 'tags-stat-label', textContent: def[1] })
            ]);
            host.appendChild(chip);
        });
    }

    function renderChips() {
        var host = document.getElementById('tags-chips');
        if (!host) return;
        host.innerHTML = '';
        FILTERS.forEach(function(f) {
            host.appendChild(App.el('button', {
                className: 'tags-chip' + (statusFilter === f[0] ? ' active' : ''),
                type: 'button',
                'aria-pressed': statusFilter === f[0] ? 'true' : 'false',
                textContent: f[1],
                onClick: function() {
                    statusFilter = f[0];
                    renderChips();
                    renderSummary();
                    renderList();
                }
            }));
        });
    }

    /** One of the two attention cards (tagged out / paused). */
    function renderAttention(status, bodyId, countId) {
        var body = document.getElementById(bodyId);
        var countEl = document.getElementById(countId);
        if (!body) return;
        var games = allGames.filter(function(g) {
            return (g.operation_status || 'enabled') === status;
        });
        if (countEl) {
            countEl.textContent = (loadedOnce && !nothingKnown())
                ? (games.length + ' game' + (games.length === 1 ? '' : 's'))
                : '';
        }
        if (status === 'paused') {
            renderUnpauseAllButton(games.length);
        }
        body.innerHTML = '';

        if (!loadedOnce) {
            body.appendChild(App.loading());
            return;
        }
        if (nothingKnown()) {
            body.appendChild(App.el('p', { className: 'tags-attn-empty text-sm text-secondary',
                textContent: 'Couldn’t load the board — check the connection and hit Refresh.' }));
            return;
        }
        if (games.length === 0) {
            body.appendChild(App.el('p', { className: 'tags-attn-empty text-sm text-secondary',
                textContent: status === 'outOfService'
                    ? '✅ Nothing tagged out — the whole floor is in service.'
                    : 'Nothing is paused right now.' }));
            return;
        }
        var list = App.el('ul', { className: 'tags-rows' });
        games.forEach(function(g) { list.appendChild(buildGameRow(g, { hideBadge: true })); });
        body.appendChild(list);
    }

    /**
     * The "Unpause all" button in the Paused card header. Server-side it
     * uses the dashboard's group-level manual unpause for games in active
     * pause groups (so enforcement doesn't quietly re-pause them a minute
     * later) and a direct patch for the rest; tagged-out games are skipped
     * at every layer.
     */
    function renderUnpauseAllButton(pausedCount) {
        var host = document.getElementById('tags-paused-actions');
        if (!host) return;
        host.innerHTML = '';
        if (!App.canAccess('manual_control')) return;
        if (!loadedOnce || nothingKnown() || (pausedCount === 0 && !bulkBusy)) return;
        host.appendChild(App.el('button', {
            className: 'btn btn-success btn-sm tags-bulk-btn',
            type: 'button',
            textContent: bulkBusy ? 'Unpausing…' : 'Unpause all',
            'aria-label': 'Unpause all ' + pausedCount + ' paused games',
            disabled: bulkBusy,
            onClick: function() { unpauseAll(pausedCount); }
        }));
    }

    async function unpauseAll(pausedCount) {
        var confirmed = await App.confirm({
            title: 'Unpause all',
            message: 'Unpause all ' + pausedCount + ' paused game' + (pausedCount === 1 ? '' : 's') + '? '
                + 'Tagged-out games stay tagged out. Games in a pause group stay unpaused until the '
                + 'group’s next scheduled change.',
            confirmLabel: 'Unpause all',
            danger: false
        });
        if (!confirmed) return;

        bulkBusy = true;
        renderAll();
        var gen = App.navGeneration();
        try {
            var result = await API.post('games/unpause-all');
            if (App.navGeneration() !== gen) return;
            var failed = Object.keys((result && result.errors) || {}).length;
            var okCount = (result && result.unpaused) || 0;
            if (failed > 0) {
                App.toast(okCount + ' unpaused, ' + failed + ' failed — those games will retry automatically.',
                    okCount > 0 ? 'warning' : 'error');
            } else if (okCount > 0) {
                App.toast(okCount + ' game' + (okCount === 1 ? '' : 's') + ' unpaused.', 'success');
            } else {
                App.toast('Nothing was paused.', 'info');
            }
        } catch (e) {
            if (App.navGeneration() !== gen) return;
            App.toast('Unpause all failed: ' + e.message, 'error');
        } finally {
            if (App.navGeneration() === gen) {
                bulkBusy = false;
                renderAll();
                loadGames();
            }
        }
    }

    function renderList() {
        var listEl = document.getElementById('tags-list');
        if (!listEl) return;

        var filtered = allGames.filter(function(g) {
            if (statusFilter !== 'all' && (g.operation_status || 'enabled') !== statusFilter) return false;
            if (searchTerm && (g.game_name || '').toLowerCase().indexOf(searchTerm) === -1
                && (g.game_id || '').toLowerCase().indexOf(searchTerm) === -1) return false;
            return true;
        });

        var meta = document.getElementById('tags-meta');
        if (meta) {
            var pieces = [];
            if (loadedOnce && !nothingKnown()) {
                pieces.push(filtered.length + ' game' + (filtered.length === 1 ? '' : 's'));
                if (filtered.length !== allGames.length) pieces.push('of ' + allGames.length);
                if (loadFailed) pieces.push('refresh failed — showing last known');
            } else if (nothingKnown()) {
                pieces.push('couldn’t load');
            }
            meta.textContent = pieces.join(' · ');
        }

        listEl.innerHTML = '';
        if (!loadedOnce) {
            listEl.appendChild(App.loading());
            return;
        }
        if (filtered.length === 0) {
            if (allGames.length === 0) {
                listEl.appendChild(App.emptyState('🎮', loadFailed
                    ? ('Couldn’t load games: ' + loadFailed)
                    : 'No games synced yet. An operator needs to sync the game list from the Games page first.'));
            } else {
                listEl.appendChild(App.emptyState('🔍', 'No games match.', App.el('button', {
                    className: 'btn btn-secondary btn-sm',
                    textContent: 'Clear search & filters',
                    onClick: function() {
                        searchTerm = '';
                        statusFilter = 'all';
                        var si = document.getElementById('tags-search');
                        if (si) si.value = '';
                        renderChips();
                        renderSummary();
                        renderList();
                    }
                })));
            }
            return;
        }

        var list = App.el('ul', { className: 'tags-rows' });
        filtered.forEach(function(g) { list.appendChild(buildGameRow(g, {})); });
        listEl.appendChild(list);
    }

    /**
     * One touch-friendly game row. Used by the attention cards (badge
     * hidden — the card already says the status) and the directory list.
     */
    function buildGameRow(g, opts) {
        var status = g.operation_status || 'enabled';
        var name = g.game_name || ('Game ' + g.game_id);

        var infoBits = [
            App.el('div', { className: 'tags-row-name', textContent: name })
        ];
        if (!opts.hideBadge) {
            infoBits.push(App.el('div', { className: 'tags-row-status' }, [tagsBadge(status)]));
        }
        // A queued retry means the last change didn't stick upstream and the
        // watchdog is re-trying it — without this note the board would show
        // a state that quietly contradicts what's about to happen.
        if (g.pending_retry) {
            var r = g.pending_retry;
            var wantLabel = r.desired_status === 'enabled' ? 'running'
                : r.desired_status === 'paused' ? 'paused' : 'tagged out';
            infoBits.push(App.el('div', {
                className: 'tags-row-retry',
                title: r.last_error || '',
                textContent: '⟳ change didn’t stick — retrying “' + wantLabel + '” ('
                    + r.attempts + '/' + r.max_attempts + ')'
            }));
        }
        var info = App.el('div', { className: 'tags-row-info' }, infoBits);

        var row = App.el('li', { className: 'tags-row tags-row-' + status }, [info]);
        if (App.canAccess('manual_control')) {
            row.appendChild(App.el('div', { className: 'tags-row-actions' }, buildActions(g)));
        }
        return row;
    }

    /** The action buttons for a game, by its current status. */
    function buildActions(g) {
        var status = g.operation_status || 'enabled';
        var name = g.game_name || ('Game ' + g.game_id);
        var busy = !!busyIds[g.game_id];
        var btns = [];

        if (status === 'outOfService') {
            btns.push(App.el('button', {
                className: 'btn btn-success tags-action-btn',
                textContent: busy ? 'Tagging…' : 'Tag in',
                'aria-label': 'Tag ' + name + ' back into service',
                disabled: busy,
                onClick: function() { changeStatus(g, 'enabled'); }
            }));
        } else {
            if (status === 'paused') {
                btns.push(App.el('button', {
                    className: 'btn btn-secondary tags-action-btn',
                    textContent: busy ? '…' : 'Unpause',
                    'aria-label': 'Unpause ' + name,
                    disabled: busy,
                    onClick: function() { changeStatus(g, 'enabled'); }
                }));
            }
            btns.push(App.el('button', {
                className: 'btn btn-danger tags-action-btn',
                textContent: busy ? 'Tagging…' : 'Tag out',
                'aria-label': 'Tag ' + name + ' out of service',
                disabled: busy,
                onClick: function() { changeStatus(g, 'outOfService'); }
            }));
        }
        return btns;
    }

    async function changeStatus(g, target) {
        var name = g.game_name || ('Game ' + g.game_id);
        var current = g.operation_status || 'enabled';
        var confirmOpts;
        if (target === 'outOfService') {
            confirmOpts = {
                title: 'Tag out of service',
                message: 'Tag “' + name + '” out of service? Automation will skip it until someone tags it back in.',
                confirmLabel: 'Tag out',
                danger: true
            };
        } else if (current === 'outOfService') {
            confirmOpts = {
                title: 'Tag back in',
                message: 'Put “' + name + '” back in service?',
                confirmLabel: 'Tag in',
                danger: false
            };
        } else {
            confirmOpts = {
                title: 'Unpause',
                message: 'Unpause “' + name + '”? Its pause group’s next scheduled change may pause it again.',
                confirmLabel: 'Unpause',
                danger: false
            };
        }
        var confirmed = await App.confirm(confirmOpts);
        if (!confirmed) return;

        busyIds[g.game_id] = true;
        renderAll();

        var payload = { games: {} };
        payload.games[g.game_id] = [
            { op: 'replace', path: '/operationStatus', value: target }
        ];

        var gen = App.navGeneration();
        try {
            var result = await API.patch('games', payload);
            if (App.navGeneration() !== gen) return;
            var err = ((result && result.errors) || {})[g.game_id];
            if (err) {
                var msg = (err && typeof err === 'object') ? (err.message || JSON.stringify(err)) : String(err);
                App.toast('Failed: ' + msg, 'error');
            } else {
                // Optimistic local flip so the row moves cards instantly;
                // the follow-up load reconciles with the server cache.
                for (var i = 0; i < allGames.length; i++) {
                    if (allGames[i].game_id === g.game_id) {
                        allGames[i].operation_status = target;
                        break;
                    }
                }
                App.toast(target === 'outOfService'
                    ? ('“' + name + '” tagged out.')
                    : (current === 'outOfService'
                        ? ('“' + name + '” is back in service.')
                        : ('“' + name + '” unpaused.')), 'success');
            }
        } catch (e) {
            if (App.navGeneration() !== gen) return;
            App.toast('Change failed: ' + e.message, 'error');
        } finally {
            if (App.navGeneration() === gen) {
                delete busyIds[g.game_id];
                renderAll();
                loadGames();
            }
        }
    }
})();
