/**
 * Maintenance Tracker — issue tracking and preventive maintenance for games and rides.
 */
(function() {
    App.registerRoute('#/maintenance', { render: renderMaintenance });

    var PRIORITIES = { low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical' };
    var STATUSES = { open: 'Open', in_progress: 'In Progress', waiting_parts: 'Waiting Parts', resolved: 'Resolved', closed: 'Closed' };
    var ASSET_TYPES = { game: 'Game', attraction: 'Attraction', facility: 'Facility', other: 'Other' };

    function renderMaintenance(container) {
        var page = App.el('div', { className: 'page-maintenance' });

        var header = App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h2', { textContent: 'Maintenance Tracker' }),
                App.el('p', { className: 'text-secondary', textContent: 'Track issues, repairs, and preventive maintenance' })
            ]),
            App.el('button', { className: 'btn btn-primary', textContent: '+ New Ticket', onClick: function() { showTicketForm(); } })
        ]);
        page.appendChild(header);

        // Stats dashboard
        var statsEl = App.el('div', { className: 'stats-row' });
        page.appendChild(statsEl);

        // Filters
        var filterBar = App.el('div', { className: 'filter-bar' });
        var statusFilter = App.el('select', { className: 'form-select', onChange: function() { loadTickets(); } });
        statusFilter.appendChild(App.el('option', { value: '', textContent: 'All Statuses' }));
        Object.keys(STATUSES).forEach(function(k) { statusFilter.appendChild(App.el('option', { value: k, textContent: STATUSES[k] })); });
        var priorityFilter = App.el('select', { className: 'form-select', onChange: function() { loadTickets(); } });
        priorityFilter.appendChild(App.el('option', { value: '', textContent: 'All Priorities' }));
        Object.keys(PRIORITIES).forEach(function(k) { priorityFilter.appendChild(App.el('option', { value: k, textContent: PRIORITIES[k] })); });
        var assetFilter = App.el('select', { className: 'form-select', onChange: function() { loadTickets(); } });
        assetFilter.appendChild(App.el('option', { value: '', textContent: 'All Assets' }));
        Object.keys(ASSET_TYPES).forEach(function(k) { assetFilter.appendChild(App.el('option', { value: k, textContent: ASSET_TYPES[k] })); });
        filterBar.appendChild(statusFilter);
        filterBar.appendChild(priorityFilter);
        filterBar.appendChild(assetFilter);
        page.appendChild(filterBar);

        // Tickets list
        var ticketList = App.el('div', { className: 'ticket-list' });
        page.appendChild(ticketList);

        container.appendChild(page);

        function loadStats() {
            API.get('maintenance/stats').then(function(data) {
                statsEl.innerHTML = '';
                statsEl.appendChild(statCard('Open', data.open_count, 'var(--warning)'));
                statsEl.appendChild(statCard('Critical', data.critical_count, 'var(--danger)'));
                statsEl.appendChild(statCard('Resolved Today', data.resolved_today, 'var(--success)'));
                statsEl.appendChild(statCard('Overdue', data.overdue_count, 'var(--danger)'));
            }).catch(function(err) { App.toast('Stats unavailable: ' + err.message, 'error'); });
        }

        function loadTickets() {
            var params = [];
            if (statusFilter.value) params.push('status=' + statusFilter.value);
            if (priorityFilter.value) params.push('priority=' + priorityFilter.value);
            if (assetFilter.value) params.push('asset_type=' + assetFilter.value);
            var qs = params.length ? '?' + params.join('&') : '';

            API.get('maintenance' + qs).then(function(data) {
                renderTickets(ticketList, data.tickets);
            }).catch(function(err) {
                App.toast('Failed to load tickets: ' + err.message, 'error');
            });
        }

        function renderTickets(el, tickets) {
            el.innerHTML = '';
            if (!tickets.length) {
                el.appendChild(App.emptyState('\u2692', 'No maintenance tickets. Everything is running smoothly!'));
                return;
            }

            var table = App.el('table', { className: 'data-table' });
            var thead = App.el('thead', {}, [
                App.el('tr', {}, [
                    App.el('th', { textContent: 'Priority' }),
                    App.el('th', { textContent: 'Title' }),
                    App.el('th', { textContent: 'Asset' }),
                    App.el('th', { textContent: 'Status' }),
                    App.el('th', { textContent: 'Assigned To' }),
                    App.el('th', { textContent: 'Created' }),
                    App.el('th', { textContent: 'Actions' }),
                ])
            ]);
            table.appendChild(thead);

            var tbody = App.el('tbody');
            tickets.forEach(function(t) {
                var prCls = t.priority === 'critical' ? 'badge-danger' : t.priority === 'high' ? 'badge-paused' : t.priority === 'medium' ? 'badge-warning' : '';
                var stCls = t.status === 'resolved' || t.status === 'closed' ? 'badge-enabled' : t.status === 'in_progress' ? 'badge-paused' : '';

                var actionBtns = [];
                if (t.status !== 'resolved' && t.status !== 'closed') {
                    actionBtns.push(App.el('button', { className: 'btn btn-sm btn-success', textContent: 'Resolve', onClick: function() { showResolveForm(t); } }));
                    actionBtns.push(App.el('button', { className: 'btn btn-sm btn-secondary', textContent: 'Edit', onClick: function() { showTicketForm(t); } }));
                } else {
                    actionBtns.push(App.el('button', { className: 'btn btn-sm btn-warning', textContent: 'Reopen', onClick: function() {
                        API.post('maintenance/' + t.id + '/reopen').then(function() {
                            App.toast('Ticket reopened', 'success');
                            loadTickets();
                            loadStats();
                        }).catch(function(err) { App.toast(err.message, 'error'); });
                    }}));
                }

                var row = App.el('tr', {}, [
                    App.el('td', {}, [App.el('span', { className: 'badge ' + prCls, textContent: PRIORITIES[t.priority] || t.priority })]),
                    App.el('td', {}, [
                        App.el('div', { textContent: t.title, style: { fontWeight: '500' } }),
                        t.description ? App.el('div', { className: 'text-secondary text-sm', textContent: t.description.substring(0, 80) + (t.description.length > 80 ? '...' : '') }) : App.el('span')
                    ]),
                    App.el('td', {}, [
                        App.el('div', { textContent: t.asset_name }),
                        App.el('div', { className: 'text-secondary text-sm', textContent: ASSET_TYPES[t.asset_type] || t.asset_type })
                    ]),
                    App.el('td', {}, [App.el('span', { className: 'badge ' + stCls, textContent: STATUSES[t.status] || t.status })]),
                    App.el('td', { textContent: t.assigned_to || '-' }),
                    App.el('td', { textContent: App.formatDatetime(t.created_at) }),
                    App.el('td', { className: 'flex gap-sm' }, actionBtns)
                ]);
                tbody.appendChild(row);
            });
            table.appendChild(tbody);
            el.appendChild(table);
        }

        function showTicketForm(existing) {
            var form = App.el('div', { className: 'form-grid' });

            var nameInput = App.el('input', { className: 'form-input', value: existing ? existing.title : '', placeholder: 'Brief issue description' });
            var descInput = App.el('textarea', { className: 'form-input', rows: '3' });
            descInput.value = existing ? existing.description : '';
            var assetTypeSelect = App.el('select', { className: 'form-select' });
            Object.keys(ASSET_TYPES).forEach(function(k) {
                var opt = App.el('option', { value: k, textContent: ASSET_TYPES[k] });
                if (existing && existing.asset_type === k) opt.selected = true;
                assetTypeSelect.appendChild(opt);
            });
            var assetIdInput = App.el('input', { className: 'form-input', value: existing ? existing.asset_id : '', placeholder: 'Game/attraction ID' });
            var assetNameInput = App.el('input', { className: 'form-input', value: existing ? existing.asset_name : '', placeholder: 'Game/attraction name' });
            var prioritySelect = App.el('select', { className: 'form-select' });
            Object.keys(PRIORITIES).forEach(function(k) {
                var opt = App.el('option', { value: k, textContent: PRIORITIES[k] });
                if (existing && existing.priority === k) opt.selected = true;
                else if (!existing && k === 'medium') opt.selected = true;
                prioritySelect.appendChild(opt);
            });
            var assignedInput = App.el('input', { className: 'form-input', value: existing ? existing.assigned_to : '', placeholder: 'Staff name' });
            var dueDateInput = App.el('input', { className: 'form-input', type: 'date', value: existing ? (existing.due_date || '') : '' });

            form.appendChild(formGroup('Title *', nameInput));
            form.appendChild(formGroup('Description', descInput));
            form.appendChild(formGroup('Asset Type', assetTypeSelect));
            form.appendChild(formGroup('Asset ID *', assetIdInput));
            form.appendChild(formGroup('Asset Name *', assetNameInput));
            form.appendChild(formGroup('Priority', prioritySelect));
            form.appendChild(formGroup('Assigned To', assignedInput));
            form.appendChild(formGroup('Due Date', dueDateInput));

            var footer = App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
                App.el('button', { className: 'btn btn-primary', textContent: existing ? 'Update' : 'Create', onClick: function() {
                    var data = {
                        title: nameInput.value,
                        description: descInput.value,
                        asset_type: assetTypeSelect.value,
                        asset_id: assetIdInput.value,
                        asset_name: assetNameInput.value,
                        priority: prioritySelect.value,
                        assigned_to: assignedInput.value,
                        due_date: dueDateInput.value || null,
                    };
                    var promise = existing ? API.put('maintenance/' + existing.id, data) : API.post('maintenance', data);
                    promise.then(function() {
                        App.hideModal();
                        App.toast(existing ? 'Ticket updated' : 'Ticket created', 'success');
                        loadTickets();
                        loadStats();
                    }).catch(function(err) { App.toast(err.message, 'error'); });
                }})
            ]);

            App.showModal(existing ? 'Edit Ticket' : 'New Maintenance Ticket', form, footer);
        }

        function showResolveForm(ticket) {
            var form = App.el('div', { className: 'form-grid' });
            var notesInput = App.el('textarea', { className: 'form-input', rows: '4' });
            notesInput.placeholder = 'Describe what was done to resolve the issue...';
            form.appendChild(formGroup('Resolution Notes', notesInput));

            var footer = App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
                App.el('button', { className: 'btn btn-success', textContent: 'Mark Resolved', onClick: function() {
                    API.post('maintenance/' + ticket.id + '/resolve', { resolution_notes: notesInput.value }).then(function() {
                        App.hideModal();
                        App.toast('Ticket resolved', 'success');
                        loadTickets();
                        loadStats();
                    }).catch(function(err) { App.toast(err.message, 'error'); });
                }})
            ]);

            App.showModal('Resolve: ' + ticket.title, form, footer);
        }

        loadStats();
        loadTickets();
    }

    function statCard(label, value, color) {
        return App.el('div', { className: 'stat-card' }, [
            App.el('div', { className: 'stat-value', textContent: String(value), style: { color: color } }),
            App.el('div', { className: 'stat-label', textContent: label })
        ]);
    }

    function formGroup(label, input) {
        return App.el('div', { className: 'form-group' }, [
            App.el('label', { textContent: label }),
            input
        ]);
    }
})();
