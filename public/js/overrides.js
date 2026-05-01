/**
 * Override management: active/upcoming/expired sections, create, delete.
 *
 * Auto-refreshes every 15s when active overrides exist, and triggers
 * enforce + refresh at the exact moment an override expires.
 */
(function() {
    App.registerRoute('#/overrides', { render: renderOverrides });

    var refreshInterval = null;
    var refreshIntervalCleanup = null;
    var expiryTimers = [];

    function renderOverrides(container) {
        var newBtn = App.el('button', {
            className: 'btn btn-primary',
            onClick: showCreateForm
        });
        newBtn.appendChild(App.iconEl('plus', 14));
        newBtn.appendChild(App.el('span', { textContent: 'New Override' }));

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Overrides' }),
                App.el('p', { className: 'page-subtitle', textContent: 'One-off pause or unpause windows that take priority over recurring schedules.' })
            ]),
            App.el('div', { className: 'page-header-actions' }, [newBtn])
        ]));

        var content = App.el('div', { id: 'overrides-content' });
        content.appendChild(App.loading());
        container.appendChild(content);

        loadOverrides();

        return function cleanup() {
            if (refreshIntervalCleanup) refreshIntervalCleanup();
            refreshIntervalCleanup = null;
            refreshInterval = null;
            expiryTimers.forEach(function(t) { clearTimeout(t); });
            expiryTimers = [];
        };
    }

    async function loadOverrides() {
        var content = document.getElementById('overrides-content');
        if (!content) return;

        var gen = App.navGeneration();
        try {
            var data = await API.get('overrides') || {};
            if (App.navGeneration() !== gen) return;
            content.innerHTML = '';
            var active = data.active || [];
            renderSection(content, 'Active Now', active, 'badge-active');
            renderSection(content, 'Upcoming', data.upcoming || [], 'badge-info');
            renderSection(content, 'Expired', data.expired || [], 'badge-inactive');

            // Auto-refresh when overrides are active
            setupAutoRefresh(active);
        } catch (err) {
            content.innerHTML = '';
            App.toast(err.message, 'error');
        }
    }

    function setupAutoRefresh(activeOverrides) {
        // Clear existing timers
        if (refreshIntervalCleanup) refreshIntervalCleanup();
        refreshIntervalCleanup = null;
        refreshInterval = null;
        expiryTimers.forEach(function(t) { clearTimeout(t); });
        expiryTimers = [];

        if (activeOverrides.length > 0) {
            // Poll every 15s while overrides are active
            refreshIntervalCleanup = App.createVisibilityAwareInterval(loadOverrides, 15000, {
                runImmediately: false,
                runOnVisible: true
            });
            refreshInterval = true;

            // Set precise timers for each override expiry
            var now = Date.now();
            activeOverrides.forEach(function(o) {
                var endD = App.toUtcDate(o.end_datetime);
                var endMs = endD ? endD.getTime() : NaN;
                var delay = endMs - now;

                if (delay > 0 && delay < 3600000) {
                    var timer = setTimeout(function() {
                        // Call enforce for the group then refresh
                        if (o.pause_group_id) {
                            API.post('groups/' + encodeURIComponent(o.pause_group_id) + '/enforce').catch(function() {
                                // Enforce failure is non-critical; watchdog will catch up
                            });
                        }
                        setTimeout(loadOverrides, 1500);
                    }, delay + 1000);
                    expiryTimers.push(timer);
                }
            });
        }
    }

    function renderSection(container, title, overrides, badgeCls) {
        var section = App.el('div', { className: 'override-section' });
        section.appendChild(App.el('div', { className: 'override-section-title' }, [
            App.el('span', { textContent: title }),
            App.el('span', { className: 'badge ' + badgeCls, textContent: String(overrides.length) })
        ]));

        if (overrides.length === 0) {
            var emptyMsg = title === 'Active Now' ? 'Nothing active right now.'
                : title === 'Upcoming' ? 'No upcoming overrides scheduled.'
                : 'No recently expired overrides.';
            section.appendChild(App.el('p', { className: 'text-muted text-sm', style: { padding: '0.5rem 0' }, textContent: emptyMsg }));
        } else {
            overrides.forEach(function(o) {
                var actionBadge = App.el('span', {
                    className: 'badge ' + (o.action === 'pause' ? 'badge-paused' : 'badge-enabled'),
                    textContent: o.action === 'pause' ? 'Pause' : 'Unpause'
                });

                var card = App.el('div', { className: 'override-card' }, [
                    App.el('div', { className: 'override-info' }, [
                        App.el('div', { className: 'flex-center gap-sm', style: { marginBottom: '0.2rem' } }, [
                            App.el('span', { className: 'override-name', textContent: o.name }),
                            actionBadge
                        ]),
                        App.el('div', { className: 'override-meta' }, [
                            App.el('span', { textContent: (o.group_name || 'Group') + ' \u2022 ' }),
                            App.el('span', { textContent: App.formatDatetime(o.start_datetime) + ' \u2192 ' + App.formatDatetime(o.end_datetime) }),
                            o.created_by_name ? App.el('span', { textContent: ' \u2022 by ' + o.created_by_name }) : null
                        ].filter(Boolean))
                    ]),
                    App.el('div', { className: 'flex-center gap-sm' }, [
                        title === 'Active Now' ? App.el('span', { className: 'override-countdown', textContent: 'ends ' + App.formatRelative(o.end_datetime) }) : null,
                        title !== 'Expired' ? App.el('button', {
                            className: 'btn btn-ghost btn-sm text-danger',
                            type: 'button',
                            title: 'Delete this override',
                            'aria-label': 'Delete override',
                            onClick: function() { deleteOverride(o.id, title === 'Active Now'); }
                        }, [App.iconEl('cross', 13)]) : null
                    ].filter(Boolean))
                ]);
                section.appendChild(card);
            });
        }

        container.appendChild(section);
    }

    async function showCreateForm() {
        try {
            var groupData = await API.get('groups') || {};
            var groups = groupData.groups || [];

            if (groups.length === 0) {
                App.toast('Create a pause group first.', 'warning');
                return;
            }

            var form = App.el('div');

            // Group selector
            var groupSelect = App.el('select', { className: 'form-select' });
            groups.forEach(function(g) {
                groupSelect.appendChild(App.el('option', { value: String(g.id), textContent: g.name }));
            });
            form.appendChild(App.el('div', { className: 'form-group' }, [
                App.el('label', { className: 'form-label', textContent: 'Pause Group' }),
                groupSelect
            ]));

            // Name
            var nameInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'e.g., Birthday Party Override' });
            form.appendChild(App.el('div', { className: 'form-group' }, [
                App.el('label', { className: 'form-label', textContent: 'Override Name' }),
                nameInput
            ]));

            // Action
            var actionSelect = App.el('select', { className: 'form-select' });
            actionSelect.appendChild(App.el('option', { value: 'unpause', textContent: 'Unpause \u2014 Force games ON (override a pause schedule)' }));
            actionSelect.appendChild(App.el('option', { value: 'pause', textContent: 'Pause \u2014 Force games OFF (e.g., maintenance)' }));
            form.appendChild(App.el('div', { className: 'form-group' }, [
                App.el('label', { className: 'form-label', textContent: 'Action' }),
                actionSelect
            ]));

            // Datetime range
            var now = new Date();
            var pad = function(n) { return String(n).padStart(2, '0'); };
            var nowStr = now.getFullYear() + '-' + pad(now.getMonth()+1) + '-' + pad(now.getDate()) + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes());

            var nowLocal = nowStr.replace(' ', 'T');
            var startInput = App.el('input', { className: 'form-input', type: 'datetime-local', value: nowLocal });
            var endInput = App.el('input', { className: 'form-input', type: 'datetime-local' });
            form.appendChild(App.el('div', { className: 'form-row' }, [
                App.el('div', { className: 'form-group' }, [App.el('label', { className: 'form-label', textContent: 'Start' }), startInput]),
                App.el('div', { className: 'form-group' }, [App.el('label', { className: 'form-label', textContent: 'End' }), endInput])
            ]));

            var footer = App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
                App.el('button', { className: 'btn btn-primary', textContent: 'Create Override', onClick: async function() {
                    var name = nameInput.value.trim();
                    if (!name) { App.toast('Name is required.', 'error'); return; }
                    if (!startInput.value || !endInput.value) { App.toast('Both start and end times are required.', 'error'); return; }

                    try {
                        await API.post('overrides', {
                            pause_group_id: parseInt(groupSelect.value),
                            name: name,
                            action: actionSelect.value,
                            start_datetime: startInput.value.replace('T', ' ').substring(0, 16),
                            end_datetime: endInput.value.replace('T', ' ').substring(0, 16)
                        });
                        App.hideModal();
                        App.toast('Override created.', 'success');
                        await loadOverrides();
                    } catch (err) { App.toast(err.message, 'error'); }
                }})
            ]);

            App.showModal('New Override', form, footer);
        } catch (err) {
            App.toast(err.message, 'error');
        }
    }

    async function deleteOverride(id, isActive) {
        var msg = isActive
            ? 'Delete this active override? Games will revert to their scheduled state.'
            : 'Delete this override?';
        var yes = await App.confirm(msg);
        if (!yes) return;
        try {
            await API.del('overrides/' + encodeURIComponent(id));
            App.toast('Override deleted.', 'success');
            await loadOverrides();
        } catch (err) { App.toast(err.message, 'error'); }
    }
})();
