/**
 * Kiosks page: list kiosks pulled from CenterEdge and pause / unpause them.
 *
 * The card system reports its support for `/kiosks` via the capabilities
 * endpoint. We probe that on render so we can show a clear "not supported"
 * state instead of an empty list of kiosks.
 */
(function() {
    App.registerRoute('#/kiosks', { render: renderKiosks });

    var capCache = null;

    // Module-level state — kept across re-renders so search/pagination
    // survive a sync or background refresh.
    var allKiosks = [];
    var lastSyncedAt = null;
    var capsState = { supportsList: true, supportsPause: true };
    var kioskSearch = '';
    var kioskStatusFilter = 'all';
    var kioskPaging = { page: 1, pageSize: 25, totalItems: 0 };

    // The kiosk /performAction endpoint is a CenterEdge vendor extension —
    // it isn't part of the official OpenAPI 1.8.0 spec. If we hit a 404 on
    // a performAction call, we know this build doesn't expose it, so we
    // flip this flag and:
    //   - re-render to hide the RPC buttons across the page
    //   - show a one-time banner above the list explaining what happened
    // Reset on full reload (module reload), not on simple re-render.
    var rpcUnsupported = false;

    async function renderKiosks(container, params) {
        // Apply ?status= and ?search= deep-links so an analytics fleet tile
        // or a sidebar shortcut can land directly on a filtered kiosk view.
        var query = (params && params._query) || {};
        var validStatus = { all: 1, enabled: 1, paused: 1, outOfService: 1, unknown: 1 };
        kioskStatusFilter = validStatus[query.status] ? query.status : 'all';
        kioskSearch = (query.search || '').toLowerCase();
        kioskPaging = { page: 1, pageSize: 25, totalItems: 0 };

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Kiosks' }),
                App.el('p', { className: 'page-subtitle', textContent: 'Pause, resume, and manage kiosks reported by the CenterEdge card system.' })
            ]),
            App.el('button', {
                className: 'btn btn-ghost',
                textContent: 'Sync now',
                onClick: function() { syncAndReload(); }
            })
        ]));

        // Toolbar for search + status filter (rendered above the list)
        var searchInput = App.buildSearchInput({
            placeholder: 'Search kiosks by name or ID…',
            ariaLabel: 'Search kiosks',
            value: kioskSearch,
            onSearch: function(term) {
                kioskSearch = term.toLowerCase();
                kioskPaging.page = 1;
                renderList();
            }
        });
        searchInput.style.flex = '1';

        var toolbar = App.el('div', { id: 'kiosks-toolbar', className: 'toolbar-row', style: { display: 'none' } }, [
            searchInput,
            buildKioskStatusFilter()
        ]);
        container.appendChild(toolbar);

        // Meta line: count + last-synced
        container.appendChild(App.el('div', { id: 'kiosks-meta', className: 'text-sm text-secondary', style: { marginBottom: '0.5rem' } }));

        var listEl = App.el('div', { id: 'kiosks-list' });
        listEl.appendChild(App.loading());
        container.appendChild(listEl);

        container.appendChild(App.el('div', { id: 'kiosks-pagination' }));

        await loadList();
    }

    function buildKioskStatusFilter() {
        var sel = App.el('select', {
            className: 'form-input form-input-sm',
            'aria-label': 'Filter kiosks by status',
            onChange: function(e) {
                kioskStatusFilter = e.target.value;
                kioskPaging.page = 1;
                renderList();
            }
        });
        [
            ['all', 'All statuses'],
            ['enabled', 'Running'],
            ['paused', 'Paused'],
            ['outOfService', 'Out of service'],
            ['unknown', 'Unknown']
        ].forEach(function(opt) {
            var o = App.el('option', { value: opt[0], textContent: opt[1] });
            if (opt[0] === kioskStatusFilter) o.selected = true;
            sel.appendChild(o);
        });
        return sel;
    }

    async function loadList() {
        var listEl = document.getElementById('kiosks-list');
        if (!listEl) return;

        try {
            if (capCache === null) {
                try {
                    capCache = await API.get('capabilities');
                } catch (e) {
                    capCache = {};
                }
            }

            var caps = (capCache && capCache.kiosks) || {};
            capsState.supportsList = caps.isSupported !== false; // assume supported if missing
            capsState.supportsPause = caps.operationStatus === true;

            var data = await API.get('kiosks') || {};
            allKiosks = data.kiosks || [];
            lastSyncedAt = data.last_synced || null;

            renderList();
        } catch (err) {
            listEl.innerHTML = '';
            listEl.appendChild(App.el('p', { className: 'text-secondary', textContent: 'Error: ' + err.message }));
            App.toast(err.message, 'error');
        }
    }

    function getFilteredKiosks() {
        return allKiosks.filter(function(k) {
            // Status filter — treat missing operationStatus as 'unknown'
            var status = k.operationStatus || 'unknown';
            if (kioskStatusFilter !== 'all' && status !== kioskStatusFilter) return false;
            if (kioskSearch) {
                if (!App.matchesSearch(k, kioskSearch, ['name', 'id', function(x) { return (x.categories || []).join(' '); }])) return false;
            }
            return true;
        });
    }

    function renderList() {
        var listEl = document.getElementById('kiosks-list');
        var pagerEl = document.getElementById('kiosks-pagination');
        var toolbarEl = document.getElementById('kiosks-toolbar');
        var metaEl = document.getElementById('kiosks-meta');
        if (!listEl) return;

        listEl.innerHTML = '';
        if (pagerEl) pagerEl.innerHTML = '';
        if (metaEl) metaEl.innerHTML = '';

        if (!capsState.supportsList) {
            if (toolbarEl) toolbarEl.style.display = 'none';
            listEl.appendChild(App.emptyState('⚠',
                'This card system does not support the kiosks API.',
                null));
            return;
        }

        if (allKiosks.length === 0) {
            if (toolbarEl) toolbarEl.style.display = 'none';
            listEl.appendChild(App.emptyState('▢',
                'No kiosks reported. If you just configured the card system, click "Sync now".',
                App.el('button', {
                    className: 'btn btn-primary', textContent: 'Sync now',
                    onClick: function() { syncAndReload(); }
                })));
            return;
        }

        // Show toolbar now that we know there are kiosks to filter
        if (toolbarEl) toolbarEl.style.display = '';

        // Venue-wide banner — once we've confirmed (via a 404 from
        // /performAction) that this CenterEdge build doesn't expose kiosk
        // RPC actions, surface that prominently so operators know why
        // Reboot etc. aren't appearing.
        if (rpcUnsupported) {
            var banner = App.el('div', { className: 'alert alert-info kiosk-rpc-banner' }, [
                App.el('strong', { textContent: 'Remote kiosk actions not available on this card system. ' }),
                App.el('span', { textContent:
                    'The CenterEdge build at this venue returned 404 for the ' +
                    'kiosk /performAction endpoint. That endpoint is a vendor ' +
                    'extension and isn\'t present on every install — pause / ' +
                    'unpause / out-of-service still work as normal. Contact ' +
                    'your CenterEdge vendor if you need remote reboot.' })
            ]);
            listEl.appendChild(banner);
        }

        // Meta line: counts + last sync timestamp
        if (metaEl) {
            var pieces = [];
            pieces.push(allKiosks.length + ' kiosk' + (allKiosks.length !== 1 ? 's' : ''));
            if (lastSyncedAt) {
                pieces.push('synced ' + App.formatDatetime(lastSyncedAt) + ' (' + App.appTimezone + ')');
            }
            metaEl.textContent = pieces.join('  •  ');
        }

        if (!capsState.supportsPause) {
            listEl.appendChild(App.el('div', {
                className: 'card',
                style: { marginBottom: '0.75rem', borderColor: 'var(--color-warn, #b58a2a)' }
            }, [
                App.el('div', { className: 'card-body' }, [
                    App.el('p', { className: 'text-sm', textContent:
                        'The card system reports kiosks but does not support changing their operationStatus via the API. Pause/Unpause buttons are disabled.' })
                ])
            ]));
        }

        var filtered = getFilteredKiosks();
        kioskPaging.totalItems = filtered.length;
        var page = App.paginate(filtered, kioskPaging.page, kioskPaging.pageSize);
        kioskPaging.page = page.page;

        if (filtered.length === 0) {
            listEl.appendChild(App.el('p', {
                className: 'text-sm text-secondary',
                style: { padding: '1rem 0' },
                textContent: 'No kiosks match these filters.'
            }));
            return;
        }

        page.items.forEach(function(k) {
            listEl.appendChild(buildKioskCard(k, capsState.supportsPause));
        });

        if (pagerEl && filtered.length > kioskPaging.pageSize) {
            pagerEl.appendChild(App.buildPaginationBar(kioskPaging, function() { renderList(); }, {
                pageSizeOptions: [25, 50, 100, 200],
                itemLabel: 'kiosks',
                showPageNumbers: true
            }));
        }
    }

    function buildKioskCard(kiosk, supportsPause) {
        var status = kiosk.operationStatus || 'unknown';
        var unknown = !kiosk.operationStatus;
        // Per spec, kiosks reporting no operationStatus must NOT be pause-controlled.
        var pauseAllowed = supportsPause && !unknown;

        var statusBadge = App.el('span', {
            className: 'badge badge-' + (
                status === 'enabled' ? 'enabled' :
                status === 'paused' ? 'paused' :
                status === 'outOfService' ? 'inactive' : 'info'
            ),
            textContent: status === 'enabled' ? 'Running'
                : status === 'paused' ? 'Paused'
                : status === 'outOfService' ? 'Out of service'
                : 'Unknown'
        });

        var actionBtns = App.el('div', { className: 'flex gap-sm', style: { marginLeft: '0.75rem' } });

        if (pauseAllowed) {
            if (status !== 'enabled') {
                actionBtns.appendChild(App.el('button', {
                    className: 'btn btn-sm btn-success',
                    textContent: 'Unpause',
                    onClick: function(e) { e.stopPropagation(); doStatusChange(kiosk, 'unpause'); }
                }));
            }
            if (status !== 'paused') {
                actionBtns.appendChild(App.el('button', {
                    className: 'btn btn-sm btn-warning',
                    textContent: 'Pause',
                    onClick: function(e) { e.stopPropagation(); doStatusChange(kiosk, 'pause'); }
                }));
            }
            if (status !== 'outOfService') {
                actionBtns.appendChild(App.el('button', {
                    className: 'btn btn-sm btn-ghost',
                    textContent: 'Out of service',
                    onClick: function(e) { e.stopPropagation(); doStatusChange(kiosk, 'out-of-service'); }
                }));
            }
        }

        // RPC actions exposed by the kiosk (e.g. "reboot"). The kiosk
        // performAction endpoint is a CenterEdge vendor extension — not in
        // the official OpenAPI 1.8.0 spec — so we ONLY render a button when
        // the kiosk advertises the action AND we haven't already proven the
        // endpoint isn't reachable on this build (rpcUnsupported flag).
        var supportedActions = kiosk.supportedActions || [];
        if (rpcUnsupported) {
            // Show a single muted explanation in place of the buttons so
            // operators understand why "Reboot" isn't available without
            // having to click and read a toast.
            actionBtns.appendChild(App.el('span', {
                className: 'kiosk-rpc-unsupported',
                title: 'This CenterEdge install returned 404 for kiosk performAction. The endpoint is a vendor extension not present on every build.',
                textContent: 'Remote actions n/a'
            }));
        } else if (supportedActions.length > 0) {
            supportedActions.forEach(function(act) {
                actionBtns.appendChild(App.el('button', {
                    className: 'btn btn-sm btn-ghost',
                    textContent: act.name || act.id,
                    title: act.requireManager ? 'Manager only' : '',
                    onClick: function(e) { e.stopPropagation(); doRpcAction(kiosk, act); }
                }));
            });
        } else if (pauseAllowed) {
            // Pause-control works for this kiosk but the upstream advertised
            // no remote-action support. Helpful muted hint instead of the
            // previous silent omission.
            actionBtns.appendChild(App.el('span', {
                className: 'kiosk-rpc-unadvertised',
                title: 'The card system reports no remote actions (e.g. reboot) for this kiosk. Some CenterEdge builds expose them, this one doesn\'t for this device.',
                textContent: 'No remote actions'
            }));
        }

        var meta = [];
        meta.push('ID: ' + kiosk.id);
        if (kiosk.categories && kiosk.categories.length) {
            meta.push('Categories: ' + kiosk.categories.join(', '));
        }

        return App.el('div', {
            className: 'card', style: { marginBottom: '0.75rem' }
        }, [
            App.el('div', { className: 'flex-between' }, [
                App.el('div', { style: { flex: '1', minWidth: '0' } }, [
                    App.el('div', { className: 'flex-center gap-sm' }, [
                        App.el('span', { className: 'card-title', textContent: kiosk.name || ('Kiosk ' + kiosk.id) }),
                        statusBadge
                    ]),
                    App.el('p', { className: 'text-sm text-secondary mt-1', textContent: meta.join('  •  ') })
                ]),
                actionBtns
            ])
        ]);
    }

    // Guards against rapid double-clicks firing duplicate state changes /
    // RPC actions while a request is already in flight.
    var actionInFlight = false;

    async function doStatusChange(kiosk, action) {
        var verb = action === 'pause' ? 'Pause' : action === 'unpause' ? 'Unpause' : 'Take out of service';
        var confirmed = await App.confirm(verb + ' "' + (kiosk.name || kiosk.id) + '"?');
        if (!confirmed) return;
        if (actionInFlight) return;
        actionInFlight = true;

        try {
            var result = await API.post('kiosks/' + encodeURIComponent(kiosk.id) + '/' + action);
            if (result && result.success) {
                App.toast(verb + 'd ' + (kiosk.name || kiosk.id), 'success');
            } else {
                App.toast('Failed: ' + ((result && result.error) || 'unknown error'), 'error');
            }
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            actionInFlight = false;
        }
        await loadList();
    }

    async function doRpcAction(kiosk, action) {
        var actionLabel = action.name || action.id;
        var kioskLabel = kiosk.name || kiosk.id;
        var confirmed = await App.confirm('Run "' + actionLabel + '" on "' + kioskLabel + '"?');
        if (!confirmed) return;
        if (actionInFlight) return;
        actionInFlight = true;
        try {
            await API.post('kiosks/' + encodeURIComponent(kiosk.id) + '/action', { actionId: action.id });
            App.toast('Action "' + actionLabel + '" sent.', 'success');
        } catch (err) {
            // The /api/kiosks/{id}/action handler bubbles upstream errors as
            // a 500 with `error` populated — we recognise the original HTTP
            // code by string-matching the message body so we can show a
            // useful, vendor-aware explanation rather than a raw stack-trace.
            var msg = (err && err.message) || '';

            if (/HTTP\s*404/i.test(msg)) {
                // Remember the result for the rest of the session so we can
                // hide RPC buttons across all kiosk cards and surface the
                // banner — saves operators from clicking each one to learn
                // the same thing.
                rpcUnsupported = true;
                App.toast(
                    'This CenterEdge install doesn\'t expose kiosk remote actions. ' +
                    '/performAction is a vendor extension that isn\'t present on ' +
                    'every build. Hiding remote-action buttons for this session.',
                    'error',
                    9000
                );
                renderList();
                return;
            }

            if (/HTTP\s*403/i.test(msg)) {
                App.toast(
                    '"' + actionLabel + '" requires manager-level credentials on this card system.',
                    'warning',
                    7000
                );
                return;
            }

            if (/HTTP\s*5\d\d/i.test(msg)) {
                App.toast(
                    'Card system error running "' + actionLabel + '" on ' + kioskLabel +
                    '. Try again in a moment, or check the card-system status.',
                    'error',
                    7000
                );
                return;
            }

            App.toast(msg || ('Failed to send "' + actionLabel + '"'), 'error');
        } finally {
            actionInFlight = false;
        }
    }

    async function syncAndReload() {
        var listEl = document.getElementById('kiosks-list');
        if (listEl) { listEl.innerHTML = ''; listEl.appendChild(App.loading()); }
        try {
            await API.post('kiosks/sync');
            App.toast('Kiosks synced.', 'success');
        } catch (err) {
            App.toast('Sync failed: ' + err.message, 'error');
        }
        await loadList();
    }
})();
