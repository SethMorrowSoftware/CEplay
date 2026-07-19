/**
 * Override management: active/upcoming/expired sections, create, delete.
 *
 * Auto-refreshes every 15s when active overrides exist, and triggers
 * enforce + refresh at the exact moment an override expires.
 */
(function() {
    App.registerRoute('#/overrides', { render: renderOverrides });

    var refreshIntervalCleanup = null;
    var expiryTimers = [];

    // Cached payload + per-section search/pagination state. Shared search bar
    // applies to all three sections; each section has independent paging.
    var cached = { active: [], upcoming: [], expired: [] };
    var overrideSearch = '';
    var overrideActionFilter = 'all'; // 'all', 'pause', 'unpause'
    var sectionPaging = {
        active:   { page: 1, pageSize: 25, totalItems: 0 },
        upcoming: { page: 1, pageSize: 25, totalItems: 0 },
        expired:  { page: 1, pageSize: 25, totalItems: 0 }
    };

    function renderOverrides(container) {
        // Reset transient state
        overrideSearch = '';
        overrideActionFilter = 'all';
        Object.keys(sectionPaging).forEach(function(k) { sectionPaging[k].page = 1; });

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Schedule Overrides' }),
                App.el('p', { className: 'page-subtitle', textContent: 'One-off pause / unpause windows that take precedence over the recurring schedule.' })
            ]),
            App.canAccess('overrides_manage') ? App.el('button', {
                className: 'btn btn-primary', textContent: '+ New Override',
                onClick: showCreateForm
            }) : App.el('span')
        ]));

        // Shared toolbar — search applies across all three sections.
        var searchInput = App.buildSearchInput({
            placeholder: 'Search overrides by name or group…',
            ariaLabel: 'Search overrides',
            onSearch: function(term) {
                overrideSearch = term.toLowerCase();
                Object.keys(sectionPaging).forEach(function(k) { sectionPaging[k].page = 1; });
                renderAllSections();
            }
        });
        searchInput.style.flex = '1';

        var actionFilter = App.el('select', {
            className: 'form-input form-input-sm',
            'aria-label': 'Filter by action',
            onChange: function(e) {
                overrideActionFilter = e.target.value;
                Object.keys(sectionPaging).forEach(function(k) { sectionPaging[k].page = 1; });
                renderAllSections();
            }
        }, [
            App.el('option', { value: 'all', textContent: 'All actions' }),
            App.el('option', { value: 'pause', textContent: 'Pause' }),
            App.el('option', { value: 'unpause', textContent: 'Unpause' })
        ]);

        container.appendChild(App.el('div', { id: 'overrides-toolbar', className: 'toolbar-row', style: { display: 'none' } }, [
            searchInput, actionFilter
        ]));

        var content = App.el('div', { id: 'overrides-content' });
        content.appendChild(App.loading());
        container.appendChild(content);

        loadOverrides();

        return function cleanup() {
            if (refreshIntervalCleanup) refreshIntervalCleanup();
            refreshIntervalCleanup = null;
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
            cached.active = data.active || [];
            cached.upcoming = data.upcoming || [];
            cached.expired = data.expired || [];
            renderAllSections();
            // Auto-refresh when overrides are active
            setupAutoRefresh(cached.active);
        } catch (err) {
            if (App.navGeneration() !== gen) return;
            content.innerHTML = '';
            content.appendChild(App.emptyState('⚠️', 'Could not load overrides: ' + err.message,
                App.el('button', { className: 'btn btn-secondary btn-sm', textContent: 'Retry',
                    onClick: function() { content.innerHTML = ''; content.appendChild(App.loading()); loadOverrides(); } })));
            App.toast(err.message, 'error');
        }
    }

    function applyOverrideFilters(items) {
        return items.filter(function(o) {
            if (overrideActionFilter !== 'all' && o.action !== overrideActionFilter) return false;
            if (overrideSearch) {
                if (!App.matchesSearch(o, overrideSearch, ['name', 'group_name', 'created_by_name'])) return false;
            }
            return true;
        });
    }

    function renderAllSections() {
        var content = document.getElementById('overrides-content');
        var toolbar = document.getElementById('overrides-toolbar');
        if (!content) return;
        content.innerHTML = '';

        var totalAcrossSections = cached.active.length + cached.upcoming.length + cached.expired.length;
        if (toolbar) toolbar.style.display = totalAcrossSections === 0 ? 'none' : '';

        renderSection(content, 'Active Now', applyOverrideFilters(cached.active), 'badge-active', sectionPaging.active);
        renderSection(content, 'Upcoming', applyOverrideFilters(cached.upcoming), 'badge-info', sectionPaging.upcoming);
        renderSection(content, 'Expired', applyOverrideFilters(cached.expired), 'badge-inactive', sectionPaging.expired);
    }

    function setupAutoRefresh(activeOverrides) {
        // Clear existing timers
        if (refreshIntervalCleanup) refreshIntervalCleanup();
        refreshIntervalCleanup = null;
        expiryTimers.forEach(function(t) { clearTimeout(t); });
        expiryTimers = [];

        if (activeOverrides.length > 0) {
            // Poll every 15s while overrides are active
            refreshIntervalCleanup = App.createVisibilityAwareInterval(loadOverrides, 15000, {
                runImmediately: false,
                runOnVisible: true
            });

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

    function renderSection(container, title, overrides, badgeCls, paging) {
        var section = App.el('div', { className: 'override-section' });
        section.appendChild(App.el('div', { className: 'override-section-title' }, [
            App.el('span', { textContent: title }),
            App.el('span', { className: 'badge ' + badgeCls, textContent: String(overrides.length) })
        ]));

        if (overrides.length === 0) {
            var emptyMsg = (overrideSearch || overrideActionFilter !== 'all')
                ? 'No matches in this section.'
                : 'None.';
            section.appendChild(App.el('p', { className: 'text-muted text-sm', style: { padding: '0.5rem 0' }, textContent: emptyMsg }));
            container.appendChild(section);
            return;
        }

        paging.totalItems = overrides.length;
        var page = App.paginate(overrides, paging.page, paging.pageSize);
        paging.page = page.page;

        page.items.forEach(function(o) {
            var card = App.el('div', { className: 'override-card' }, [
                App.el('div', { className: 'override-info' }, [
                    App.el('div', { className: 'flex-center gap-sm' }, [
                        App.el('span', { className: 'override-name', textContent: o.name }),
                        App.el('span', { className: 'badge ' + (o.action === 'pause' ? 'badge-paused' : 'badge-enabled'), textContent: o.action === 'pause' ? 'Pause' : 'Unpause' })
                    ]),
                    App.el('div', { className: 'override-meta' }, [
                        App.el('span', { textContent: (o.group_name || 'Group') + ' \u2022 ' }),
                        App.el('span', { textContent: App.formatDatetime(o.start_datetime) + ' \u2014 ' + App.formatDatetime(o.end_datetime) }),
                        o.created_by_name ? App.el('span', { textContent: ' \u2022 by ' + o.created_by_name }) : null
                    ].filter(Boolean))
                ]),
                App.el('div', { className: 'flex gap-sm' }, [
                    title === 'Active Now' ? App.el('span', { className: 'override-countdown', textContent: 'ends ' + App.formatRelative(o.end_datetime) }) : null,
                    (title !== 'Expired' && App.canAccess('overrides_manage')) ? App.el('button', {
                        className: 'btn btn-ghost btn-sm text-danger', textContent: 'Delete',
                        onClick: function() { deleteOverride(o.id, title === 'Active Now'); }
                    }) : null
                ].filter(Boolean))
            ]);
            // Card click \u2192 open the affected pause group's edit page so
            // operators can review what's actually being paused / unpaused.
            // The Delete button stops propagation via makeCardLink's nested-
            // interactive guard.
            if (o.pause_group_id) {
                App.makeCardLink(card, '#/groups/' + encodeURIComponent(o.pause_group_id),
                    { title: 'Open the affected pause group' });
            }
            section.appendChild(card);
        });

        // Pagination \u2014 only show if section has more than one page worth.
        if (overrides.length > paging.pageSize) {
            section.appendChild(App.buildPaginationBar(paging, function() { renderAllSections(); }, {
                pageSizeOptions: [25, 50, 100, 200],
                itemLabel: 'overrides',
                showPageNumbers: true
            }));
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

            var createBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Create Override', onClick: async function() {
                var name = nameInput.value.trim();
                if (!name) { App.toast('Name is required.', 'error'); return; }
                if (!startInput.value || !endInput.value) { App.toast('Both start and end times are required.', 'error'); return; }

                createBtn.disabled = true; // guard against double-submit
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
                } catch (err) {
                    App.toast(err.message, 'error');
                    createBtn.disabled = false;
                }
            }});
            var footer = App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
                createBtn
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
