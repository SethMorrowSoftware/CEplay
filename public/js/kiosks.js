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
        var syncBtn = App.el('button', {
            className: 'btn btn-secondary',
            id: 'kiosk-sync-btn',
            onClick: function () { syncAndReload(); }
        });
        syncBtn.appendChild(App.iconEl('sync', 14));
        syncBtn.appendChild(App.el('span', { textContent: 'Sync Now' }));

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Kiosks' }),
                App.el('p', { className: 'page-subtitle', textContent: 'Pause, resume, and manage kiosks reported by the CenterEdge card system.' })
            ]),
            App.el('div', { className: 'page-header-actions' }, [syncBtn])
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
                listEl.appendChild(App.emptyState('warning',
                    'This card system does not support the kiosks API.',
                    null));
                return;
            }

            var kiosks = data.kiosks || [];
            if (kiosks.length === 0) {
                var syncEmptyBtn = App.el('button', {
                    className: 'btn btn-primary',
                    onClick: function () { syncAndReload(); }
                });
                syncEmptyBtn.appendChild(App.iconEl('sync', 14));
                syncEmptyBtn.appendChild(App.el('span', { textContent: 'Sync now' }));
                listEl.appendChild(App.emptyState('kiosk',
                    'No kiosks reported by the card system. If you just configured CenterEdge, click Sync now.',
                    syncEmptyBtn));
                return;
            }

            if (data.last_synced) {
                listEl.appendChild(App.el('p', {
                    className: 'text-sm text-secondary',
                    style: { marginBottom: '0.85rem' },
                    textContent: 'Last synced ' + App.formatDatetime(data.last_synced) + ' • ' + App.appTimezone
                }));
            }

            if (!supportsPause) {
                var alertBox = App.el('div', { className: 'alert-warning', style: { marginBottom: '0.85rem' } });
                alertBox.appendChild(App.iconEl('warning', 16, 'alert-icon'));
                alertBox.appendChild(App.el('span', {
                    textContent: 'The card system reports kiosks but does not support changing their operationStatus via the API. Pause and Unpause are disabled.'
                }));
                listEl.appendChild(alertBox);
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
        var pauseAllowed = supportsPause && !unknown;

        var statusCls = status === 'enabled' ? 'enabled'
            : status === 'paused' ? 'paused'
            : status === 'outOfService' ? 'out-of-service'
            : 'inactive';
        var statusLabel = status === 'enabled' ? 'Running'
            : status === 'paused' ? 'Paused'
            : status === 'outOfService' ? 'Out of Service'
            : 'Unknown';

        var statusBadge = App.el('span', { className: 'badge badge-' + statusCls });
        statusBadge.appendChild(App.el('span', { className: 'badge-dot' }));
        statusBadge.appendChild(App.el('span', { textContent: statusLabel }));

        var statusDotState = status === 'enabled' ? 'enabled'
            : status === 'paused' ? 'paused'
            : 'empty';

        var actionBtns = App.el('div', { className: 'flex gap-sm', style: { marginLeft: '0.75rem', flexWrap: 'wrap', justifyContent: 'flex-end' } });

        function actionBtn(cls, iconName, label, handler) {
            var b = App.el('button', { className: 'btn btn-sm ' + cls, type: 'button', onClick: handler });
            if (iconName) b.appendChild(App.iconEl(iconName, 12));
            b.appendChild(App.el('span', { textContent: label }));
            return b;
        }

        if (pauseAllowed) {
            if (status !== 'enabled') {
                actionBtns.appendChild(actionBtn('btn-success', 'play', 'Unpause',
                    function(e) { e.stopPropagation(); doStatusChange(kiosk, 'unpause'); }));
            }
            if (status !== 'paused') {
                actionBtns.appendChild(actionBtn('btn-warning', 'pause', 'Pause',
                    function(e) { e.stopPropagation(); doStatusChange(kiosk, 'pause'); }));
            }
            if (status !== 'outOfService') {
                actionBtns.appendChild(actionBtn('btn-ghost', null, 'Out of service',
                    function(e) { e.stopPropagation(); doStatusChange(kiosk, 'out-of-service'); }));
            }
        }

        (kiosk.supportedActions || []).forEach(function (act) {
            actionBtns.appendChild(App.el('button', {
                className: 'btn btn-sm btn-ghost',
                textContent: act.name || act.id,
                title: act.requireManager ? 'Manager only' : '',
                onClick: function (e) { e.stopPropagation(); doRpcAction(kiosk, act); }
            }));
        });

        var meta = [];
        if (kiosk.id) meta.push(App.el('span', { className: 'font-mono text-xs', textContent: 'ID ' + kiosk.id }));
        if (kiosk.categories && kiosk.categories.length) {
            meta.push(App.el('span', { className: 'text-xs', textContent: 'Categories: ' + kiosk.categories.join(', ') }));
        }
        var metaRow = App.el('div', { className: 'flex gap-md text-secondary mt-1', style: { flexWrap: 'wrap' } });
        meta.forEach(function (m) { metaRow.appendChild(m); });

        return App.el('div', { className: 'card', style: { marginBottom: '0.75rem' } }, [
            App.el('div', { className: 'flex-between gap-md' }, [
                App.el('div', { style: { flex: '1', minWidth: '0' } }, [
                    App.el('div', { className: 'flex-center gap-sm' }, [
                        App.el('span', { className: 'status-dot status-dot-' + statusDotState }),
                        App.el('span', { className: 'card-title', textContent: kiosk.name || ('Kiosk ' + kiosk.id) }),
                        statusBadge
                    ]),
                    metaRow
                ]),
                actionBtns
            ])
        ]);
    }

    async function doStatusChange(kiosk, action) {
        var verb = action === 'pause' ? 'Pause' : action === 'unpause' ? 'Unpause' : 'Take out of service';
        var confirmed = await App.confirm(verb + ' "' + (kiosk.name || kiosk.id) + '"?');
        if (!confirmed) return;

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
        var confirmed = await App.confirm('Run "' + (action.name || action.id) + '" on "' + (kiosk.name || kiosk.id) + '"?');
        if (!confirmed) return;
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
