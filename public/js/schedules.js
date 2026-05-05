/**
 * Schedule management: weekly grid view, create, edit, delete.
 */
(function() {
    App.registerRoute('#/schedules', { render: renderSchedules });

    async function renderSchedules(container) {
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('h1', { className: 'page-title', textContent: 'Schedules' }),
            App.el('button', {
                className: 'btn btn-primary', textContent: '+ New Schedule',
                onClick: showCreateForm
            })
        ]));

        // Weekly grid
        container.appendChild(App.el('div', { className: 'card', id: 'schedule-card' }, [
            App.el('div', { className: 'card-title', textContent: 'Weekly Active Hours' }),
            App.el('div', { className: 'schedule-week', id: 'schedule-grid' })
        ]));

        // Schedule list below
        container.appendChild(App.el('div', { className: 'card mt-2', id: 'schedule-list-card' }, [
            App.el('div', { className: 'card-title mb-1', textContent: 'All Schedules' }),
            App.el('div', { id: 'schedule-list' })
        ]));

        await loadSchedules();
    }

    async function loadSchedules() {
        try {
            const [schedData, groupData] = await Promise.all([
                API.get('schedules'),
                API.get('groups')
            ]);
            renderGrid((schedData || {}).schedules || []);
            renderList((schedData || {}).schedules || [], (groupData || {}).groups || []);
        } catch (err) {
            App.toast(err.message, 'error');
        }
    }

    function renderGrid(schedules) {
        const grid = document.getElementById('schedule-grid');
        if (!grid) return;
        grid.innerHTML = '';

        const todayDow = new Date().getDay();

        for (let d = 0; d < 7; d++) {
            const dayCol = App.el('div', { className: 'schedule-day' });
            const header = App.el('div', {
                className: 'schedule-day-header' + (d === todayDow ? ' today' : ''),
                textContent: App.DAYS_SHORT[d]
            });
            dayCol.appendChild(header);

            const daySchedules = schedules.filter(s => s.day_of_week == d).sort((a, b) => a.start_time.localeCompare(b.start_time));

            daySchedules.forEach(s => {
                const block = App.el('div', { className: 'schedule-block', onClick: () => showEditForm(s) }, [
                    App.el('div', { className: 'schedule-block-time', textContent: App.formatTime(s.start_time) + ' - ' + App.formatTime(s.end_time) }),
                    App.el('div', { className: 'schedule-block-group', textContent: s.group_name || 'Group #' + s.pause_group_id })
                ]);
                dayCol.appendChild(block);
            });

            if (daySchedules.length === 0) {
                dayCol.appendChild(App.el('div', { className: 'text-xs text-muted', style: { textAlign: 'center', padding: '0.5rem' }, textContent: 'No schedules' }));
            }

            grid.appendChild(dayCol);
        }
    }

    function renderList(schedules, groups) {
        const listEl = document.getElementById('schedule-list');
        if (!listEl) return;
        listEl.innerHTML = '';

        if (schedules.length === 0) {
            listEl.appendChild(App.emptyState('\u25F4', 'No schedules configured yet.'));
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
        schedules.forEach(s => {
            tbody.appendChild(App.el('tr', {}, [
                App.el('td', { textContent: s.group_name || 'Group #' + s.pause_group_id }),
                App.el('td', { textContent: App.DAYS[s.day_of_week] }),
                App.el('td', { textContent: App.formatTime(s.start_time) }),
                App.el('td', { textContent: App.formatTime(s.end_time) }),
                App.el('td', {}, [App.el('span', { className: 'badge ' + (s.is_active ? 'badge-active' : 'badge-inactive'), textContent: s.is_active ? 'Active' : 'Inactive' })]),
                App.el('td', {}, [
                    App.el('button', { className: 'btn btn-ghost btn-sm', textContent: 'Edit', onClick: () => showEditForm(s) }),
                    App.el('button', { className: 'btn btn-ghost btn-sm text-danger', textContent: 'Delete', onClick: () => deleteSchedule(s.id) })
                ])
            ]));
        });
        tbl.appendChild(tbody);
        table.appendChild(tbl);
        listEl.appendChild(table);
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

            const footer = App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: () => App.hideModal() }),
                App.el('button', { className: 'btn btn-primary', textContent: 'Create Schedule', onClick: async () => {
                    const selectedDays = dayChecks.filter(cb => cb.checked).map(cb => parseInt(cb.value));
                    if (selectedDays.length === 0) { App.toast('Select at least one day.', 'error'); return; }

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
                    } catch (err) { App.toast(err.message, 'error'); }
                }})
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

        const footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: () => App.hideModal() }),
            App.el('button', { className: 'btn btn-primary', textContent: 'Save', onClick: async () => {
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
                } catch (err) { App.toast(err.message, 'error'); }
            }})
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
