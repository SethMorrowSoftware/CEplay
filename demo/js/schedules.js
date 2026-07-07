/**
 * Schedule management: weekly grid view, create, edit, delete.
 */
(function() {
    App.registerRoute('#/schedules', { render: renderSchedules });

    // Module-level state for the schedule list
    let allSchedules = [];
    let allGroups = [];
    let scheduleSearch = '';
    let scheduleDayFilter = 'all'; // 'all' or '0'..'6'
    let scheduleStatusFilter = 'all'; // 'all', 'active', 'inactive'
    let schedulePaging = { page: 1, pageSize: 25, totalItems: 0 };

    async function renderSchedules(container) {
        // Reset on every visit
        scheduleSearch = '';
        scheduleDayFilter = 'all';
        scheduleStatusFilter = 'all';
        schedulePaging = { page: 1, pageSize: 25, totalItems: 0 };

        // Structural schedule changes are manager/admin only — techs get a
        // read-only view (the API enforces the same policy).
        var canManage = App.canAccess('schedules_manage');

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Schedules' }),
                App.el('p', { className: 'page-subtitle', textContent: 'Recurring active-hour windows for each pause group. Hours outside a window are paused.' })
            ]),
            canManage ? App.el('button', {
                className: 'btn btn-primary', textContent: '+ New Schedule',
                onClick: showCreateForm
            }) : null
        ].filter(Boolean)));

        // Weekly grid
        container.appendChild(App.el('div', { className: 'card', id: 'schedule-card' }, [
            App.el('div', { className: 'card-title', textContent: 'Weekly Active Hours' }),
            App.el('div', { className: 'schedule-week', id: 'schedule-grid' })
        ]));

        // Schedule list below — with toolbar (search, day filter, status filter)
        const searchInput = App.buildSearchInput({
            placeholder: 'Search schedules by group name…',
            ariaLabel: 'Search schedules',
            onSearch: function(term) {
                scheduleSearch = term.toLowerCase();
                schedulePaging.page = 1;
                renderList();
            }
        });
        searchInput.style.flex = '1';

        container.appendChild(App.el('div', { className: 'card mt-2', id: 'schedule-list-card' }, [
            App.el('div', { className: 'flex-between mb-1' }, [
                App.el('div', { className: 'card-title', textContent: 'All Schedules' }),
                App.el('span', { id: 'schedule-list-meta', className: 'text-sm text-secondary' })
            ]),
            App.el('div', { id: 'schedule-toolbar', className: 'toolbar-row', style: { display: 'none' } }, [
                searchInput,
                buildDayFilter(),
                buildStatusFilter()
            ]),
            App.el('div', { id: 'schedule-list' }, [App.loading()]),
            App.el('div', { id: 'schedule-list-pagination' })
        ]));

        await loadSchedules();
    }

    function buildDayFilter() {
        const sel = App.el('select', {
            className: 'form-input form-input-sm',
            'aria-label': 'Filter schedules by day of week',
            onChange: function(e) {
                scheduleDayFilter = e.target.value;
                schedulePaging.page = 1;
                renderList();
            }
        });
        sel.appendChild(App.el('option', { value: 'all', textContent: 'All days' }));
        App.DAYS.forEach((day, i) => {
            const o = App.el('option', { value: String(i), textContent: day });
            sel.appendChild(o);
        });
        return sel;
    }

    function buildStatusFilter() {
        const sel = App.el('select', {
            className: 'form-input form-input-sm',
            'aria-label': 'Filter schedules by active state',
            onChange: function(e) {
                scheduleStatusFilter = e.target.value;
                schedulePaging.page = 1;
                renderList();
            }
        });
        [['all', 'All'], ['active', 'Active'], ['inactive', 'Inactive']].forEach(opt => {
            sel.appendChild(App.el('option', { value: opt[0], textContent: opt[1] }));
        });
        return sel;
    }

    async function loadSchedules() {
        try {
            const [schedData, groupData] = await Promise.all([
                API.get('schedules'),
                API.get('groups')
            ]);
            allSchedules = (schedData || {}).schedules || [];
            allGroups = (groupData || {}).groups || [];
            renderGrid(allSchedules);
            renderList();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    }

    function getFilteredSchedules() {
        return allSchedules.filter(function(s) {
            if (scheduleStatusFilter === 'active' && !s.is_active) return false;
            if (scheduleStatusFilter === 'inactive' && s.is_active) return false;
            if (scheduleDayFilter !== 'all' && String(s.day_of_week) !== scheduleDayFilter) return false;
            if (scheduleSearch) {
                if (!App.matchesSearch(s, scheduleSearch, ['group_name', function(x) { return App.DAYS[x.day_of_week]; }])) return false;
            }
            return true;
        });
    }

    function renderGrid(schedules) {
        const grid = document.getElementById('schedule-grid');
        if (!grid) return;
        grid.innerHTML = '';

        const todayDow = new Date().getDay();

        for (let d = 0; d < 7; d++) {
            const dayCol = App.el('div', { className: 'schedule-day' });
            // Day header is clickable: filters the list below to that day
            // so the operator can audit a single day's schedules quickly.
            const header = App.el('div', {
                className: 'schedule-day-header' + (d === todayDow ? ' today' : ''),
                textContent: App.DAYS_SHORT[d]
            });
            const dayIndex = d;
            App.makeCardClickable(header, function() {
                scheduleDayFilter = String(dayIndex);
                schedulePaging.page = 1;
                // Update the day-filter <select> so the toolbar reflects state
                const sel = document.querySelector('#schedule-toolbar select');
                if (sel) {
                    // The day filter is the second select in the toolbar.
                    const selects = document.querySelectorAll('#schedule-toolbar select');
                    if (selects.length >= 1) selects[0].value = String(dayIndex);
                }
                renderList();
                const listCard = document.getElementById('schedule-list-card');
                if (listCard && listCard.scrollIntoView) listCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, { title: 'Filter the schedule list to ' + App.DAYS[d], role: 'button' });
            dayCol.appendChild(header);

            const daySchedules = schedules.filter(s => s.day_of_week == d).sort((a, b) => a.start_time.localeCompare(b.start_time));

            daySchedules.forEach(s => {
                const block = App.el('div', { className: 'schedule-block' }, [
                    App.el('div', { className: 'schedule-block-time', textContent: App.formatTime(s.start_time) + ' - ' + App.formatTime(s.end_time) }),
                    App.el('div', { className: 'schedule-block-group', textContent: s.group_name || 'Group #' + s.pause_group_id })
                ]);
                App.makeCardClickable(block, function() { showEditForm(s); }, {
                    title: 'Edit this schedule',
                    role: 'button'
                });
                dayCol.appendChild(block);
            });

            if (daySchedules.length === 0) {
                dayCol.appendChild(App.el('div', { className: 'text-xs text-muted', style: { textAlign: 'center', padding: '0.5rem' }, textContent: 'No schedules' }));
            }

            grid.appendChild(dayCol);
        }
    }

    function renderList() {
        const listEl = document.getElementById('schedule-list');
        const pagerEl = document.getElementById('schedule-list-pagination');
        const toolbarEl = document.getElementById('schedule-toolbar');
        const metaEl = document.getElementById('schedule-list-meta');
        if (!listEl) return;
        listEl.innerHTML = '';
        if (pagerEl) pagerEl.innerHTML = '';
        if (metaEl) metaEl.innerHTML = '';

        if (allSchedules.length === 0) {
            if (toolbarEl) toolbarEl.style.display = 'none';
            listEl.appendChild(App.emptyState('\u25F4', 'No schedules configured yet.'));
            return;
        }

        if (toolbarEl) toolbarEl.style.display = '';

        const filtered = getFilteredSchedules();
        schedulePaging.totalItems = filtered.length;
        const page = App.paginate(filtered, schedulePaging.page, schedulePaging.pageSize);
        schedulePaging.page = page.page;

        if (metaEl) {
            const pieces = [filtered.length + ' schedule' + (filtered.length === 1 ? '' : 's')];
            if (filtered.length !== allSchedules.length) pieces.push('of ' + allSchedules.length + ' total');
            metaEl.textContent = pieces.join('  \u2022  ');
        }

        if (filtered.length === 0) {
            listEl.appendChild(App.el('p', {
                className: 'text-sm text-secondary',
                style: { padding: '1rem 0' },
                textContent: 'No schedules match these filters.'
            }));
            return;
        }

        const table = App.el('div', { className: 'table-wrapper' });
        const tbl = App.el('table', { className: 'data-table' });

        const thead = App.el('thead', {}, [
            App.el('tr', {}, [
                App.el('th', { textContent: 'Group' }),
                App.el('th', { textContent: 'Day' }),
                App.el('th', { textContent: 'Active From' }),
                App.el('th', { textContent: 'Active Until' }),
                App.el('th', { textContent: 'Status' }),
                App.el('th', { textContent: 'Actions' })
            ])
        ]);
        tbl.appendChild(thead);

        const tbody = App.el('tbody');
        page.items.forEach(s => {
            tbody.appendChild(App.el('tr', {}, [
                App.el('td', { textContent: s.group_name || 'Group #' + s.pause_group_id }),
                App.el('td', { textContent: App.DAYS[s.day_of_week] }),
                App.el('td', { textContent: App.formatTime(s.start_time) }),
                App.el('td', { textContent: App.formatTime(s.end_time) }),
                App.el('td', {}, [App.el('span', { className: 'badge ' + (s.is_active ? 'badge-active' : 'badge-inactive'), textContent: s.is_active ? 'Active' : 'Inactive' })]),
                App.canAccess('schedules_manage')
                    ? App.el('td', {}, [
                        App.el('button', { className: 'btn btn-ghost btn-sm', textContent: 'Edit', onClick: () => showEditForm(s) }),
                        App.el('button', { className: 'btn btn-ghost btn-sm text-danger', textContent: 'Delete', onClick: () => deleteSchedule(s.id) })
                      ])
                    : App.el('td', { className: 'text-muted text-sm', textContent: 'View only' })
            ]));
        });
        tbl.appendChild(tbody);
        table.appendChild(tbl);
        listEl.appendChild(table);

        if (pagerEl && filtered.length > schedulePaging.pageSize) {
            pagerEl.appendChild(App.buildPaginationBar(schedulePaging, function() { renderList(); }, {
                pageSizeOptions: [25, 50, 100, 200],
                itemLabel: 'schedules',
                showPageNumbers: true
            }));
        }
    }

    async function showCreateForm() {
        try {
            const groupData = await API.get('groups') || {};
            const groups = groupData.groups || [];

            if (groups.length === 0) {
                App.toast('Create a pause group first.', 'warning');
                return;
            }

            const form = App.el('div');

            const groupSelect = App.el('select', { className: 'form-select' });
            groups.forEach(g => {
                groupSelect.appendChild(App.el('option', { value: String(g.id), textContent: g.name }));
            });
            form.appendChild(App.el('div', { className: 'form-group' }, [
                App.el('label', { className: 'form-label', textContent: 'Pause Group' }),
                groupSelect
            ]));

            // Days of week checkboxes
            const dayChecks = [];
            const daysWrap = App.el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '0.75rem' } });
            App.DAYS.forEach((day, i) => {
                const cb = App.el('input', { type: 'checkbox', value: String(i) });
                dayChecks.push(cb);
                daysWrap.appendChild(App.el('label', { className: 'checkbox-label' }, [cb, App.el('span', { textContent: App.DAYS_SHORT[i] })]));
            });
            form.appendChild(App.el('div', { className: 'form-group' }, [
                App.el('label', { className: 'form-label', textContent: 'Days of Week' }),
                daysWrap
            ]));

            // Time inputs
            const startInput = App.el('input', { className: 'form-input', type: 'time', value: '09:00' });
            const endInput = App.el('input', { className: 'form-input', type: 'time', value: '17:00' });
            form.appendChild(App.el('div', { className: 'form-row' }, [
                App.el('div', { className: 'form-group' }, [App.el('label', { className: 'form-label', textContent: 'Active From' }), startInput]),
                App.el('div', { className: 'form-group' }, [App.el('label', { className: 'form-label', textContent: 'Active Until' }), endInput])
            ]));
            form.appendChild(App.el('p', { className: 'form-help', textContent: 'Games will be ACTIVE (unpaused) during these hours and PAUSED outside them. Schedules cannot cross midnight \u2014 for overnight, create two entries.' }));

            const createBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Create Schedule', onClick: async () => {
                const selectedDays = dayChecks.filter(cb => cb.checked).map(cb => parseInt(cb.value));
                if (selectedDays.length === 0) { App.toast('Select at least one day.', 'error'); return; }

                createBtn.disabled = true; // guard against double-submit
                try {
                    await API.post('schedules', {
                        pause_group_id: parseInt(groupSelect.value),
                        days_of_week: selectedDays,
                        start_time: startInput.value,
                        end_time: endInput.value,
                        is_active: 1
                    });
                    App.hideModal();
                    App.toast('Schedule(s) created.', 'success');
                    await loadSchedules();
                } catch (err) {
                    App.toast(err.message, 'error');
                    createBtn.disabled = false;
                }
            }});
            const footer = App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: () => App.hideModal() }),
                createBtn
            ]);

            App.showModal('New Schedule', form, footer);
        } catch (err) {
            App.toast(err.message, 'error');
        }
    }

    async function showEditForm(schedule) {
        const form = App.el('div');

        const daySelect = App.el('select', { className: 'form-select' });
        App.DAYS.forEach((day, i) => {
            const opt = App.el('option', { value: String(i), textContent: day });
            if (i == schedule.day_of_week) opt.selected = true;
            daySelect.appendChild(opt);
        });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Day of Week' }),
            daySelect
        ]));

        const startInput = App.el('input', { className: 'form-input', type: 'time', value: schedule.start_time });
        const endInput = App.el('input', { className: 'form-input', type: 'time', value: schedule.end_time });
        form.appendChild(App.el('div', { className: 'form-row' }, [
            App.el('div', { className: 'form-group' }, [App.el('label', { className: 'form-label', textContent: 'Active From' }), startInput]),
            App.el('div', { className: 'form-group' }, [App.el('label', { className: 'form-label', textContent: 'Active Until' }), endInput])
        ]));

        const activeCheck = App.el('input', { type: 'checkbox', className: 'toggle-input' });
        activeCheck.checked = !!schedule.is_active;
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'toggle-label' }, [activeCheck, App.el('span', { className: 'toggle-switch' }), App.el('span', { textContent: 'Active' })])
        ]));

        const saveBtn = App.el('button', { className: 'btn btn-primary', textContent: 'Save', onClick: async () => {
            saveBtn.disabled = true; // guard against double-submit
            try {
                await API.put('schedules/' + encodeURIComponent(schedule.id), {
                    day_of_week: parseInt(daySelect.value),
                    start_time: startInput.value,
                    end_time: endInput.value,
                    is_active: activeCheck.checked ? 1 : 0
                });
                App.hideModal();
                App.toast('Schedule updated.', 'success');
                await loadSchedules();
            } catch (err) {
                App.toast(err.message, 'error');
                saveBtn.disabled = false;
            }
        }});
        const footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: () => App.hideModal() }),
            saveBtn
        ]);

        App.showModal('Edit Schedule', form, footer);
    }

    async function deleteSchedule(id) {
        const yes = await App.confirm('Delete this schedule?');
        if (!yes) return;
        try {
            await API.del('schedules/' + encodeURIComponent(id));
            App.toast('Schedule deleted.', 'success');
            await loadSchedules();
        } catch (err) { App.toast(err.message, 'error'); }
    }
})();
