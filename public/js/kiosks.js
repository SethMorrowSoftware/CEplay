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

    async function renderKiosks(container) {
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

        var listEl = App.el('div', { id: 'kiosks-list' });
        listEl.appendChild(App.loading());
        container.appendChild(listEl);

        await loadList();
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
            var supportsList = caps.isSupported !== false; // assume supported if missing
            var supportsPause = caps.operationStatus === true;

            var data = await API.get('kiosks') || {};
            listEl.innerHTML = '';

            if (!supportsList) {
                listEl.appendChild(App.emptyState('⚠',
                    'This card system does not support the kiosks API.',
                    null));
                return;
            }

            var kiosks = data.kiosks || [];
            if (kiosks.length === 0) {
                listEl.appendChild(App.emptyState('▢',
                    'No kiosks reported. If you just configured the card system, click "Sync now".',
                    App.el('button', {
                        className: 'btn btn-primary', textContent: 'Sync now',
                        onClick: function() { syncAndReload(); }
                    })));
                return;
            }

            if (data.last_synced) {
                listEl.appendChild(App.el('p', {
                    className: 'text-sm text-secondary',
                    style: { marginBottom: '0.75rem' },
                    textContent: 'Last synced: ' + data.last_synced + ' UTC'
                }));
            }

            if (!supportsPause) {
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

            kiosks.forEach(function(k) {
                listEl.appendChild(buildKioskCard(k, supportsPause));
            });

        } catch (err) {
            listEl.innerHTML = '';
            listEl.appendChild(App.el('p', { className: 'text-secondary', textContent: 'Error: ' + err.message }));
            App.toast(err.message, 'error');
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

        // RPC actions exposed by the kiosk (e.g. "reboot")
        (kiosk.supportedActions || []).forEach(function(act) {
            actionBtns.appendChild(App.el('button', {
                className: 'btn btn-sm btn-ghost',
                textContent: act.name || act.id,
                title: act.requireManager ? 'Manager only' : '',
                onClick: function(e) { e.stopPropagation(); doRpcAction(kiosk, act); }
            }));
        });

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

    async function doStatusChange(kiosk, action) {
        var verb = action === 'pause' ? 'Pause' : action === 'unpause' ? 'Unpause' : 'Take out of service';
        if (!confirm(verb + ' "' + (kiosk.name || kiosk.id) + '"?')) return;

        try {
            var result = await API.post('kiosks/' + encodeURIComponent(kiosk.id) + '/' + action);
            if (result && result.success) {
                App.toast(verb + 'd ' + (kiosk.name || kiosk.id), 'success');
            } else {
                App.toast('Failed: ' + ((result && result.error) || 'unknown error'), 'error');
            }
        } catch (err) {
            App.toast(err.message, 'error');
        }
        await loadList();
    }

    async function doRpcAction(kiosk, action) {
        if (!confirm('Run "' + (action.name || action.id) + '" on "' + (kiosk.name || kiosk.id) + '"?')) return;
        try {
            await API.post('kiosks/' + encodeURIComponent(kiosk.id) + '/action', { actionId: action.id });
            App.toast('Action "' + (action.name || action.id) + '" sent.', 'success');
        } catch (err) {
            App.toast(err.message, 'error');
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
