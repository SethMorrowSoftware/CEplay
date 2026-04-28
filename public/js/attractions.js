/**
 * Attractions Management — rides, laser tag, bumper cars, go-karts, etc.
 */
(function() {
    App.registerRoute('#/attractions', { render: renderAttractions });
    App.registerRoute('#/attractions/:id', { render: renderAttractionDetail });

    var TYPES = {
        ride: 'Ride', laser_tag: 'Laser Tag', bumper_cars: 'Bumper Cars',
        go_karts: 'Go-Karts', mini_golf: 'Mini Golf', bowling: 'Bowling',
        arcade_zone: 'Arcade Zone', attraction: 'Attraction', other: 'Other'
    };
    var STATUSES = { open: 'Open', closed: 'Closed', maintenance: 'Maintenance', seasonal: 'Seasonal' };

    function renderAttractions(container) {
        var page = App.el('div', { className: 'page-attractions' });

        var header = App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h2', { textContent: 'Attractions & Rides' }),
                App.el('p', { className: 'text-secondary', textContent: 'Manage rides, laser tag, go-karts, and other attractions' })
            ]),
            App.el('button', { className: 'btn btn-primary', textContent: '+ Add Attraction', onClick: function() { showAttractionForm(); } })
        ]);
        page.appendChild(header);

        // Filters
        var filterBar = App.el('div', { className: 'filter-bar' });
        var typeFilter = App.el('select', { className: 'form-select', onChange: function() { loadAttractions(); } });
        typeFilter.appendChild(App.el('option', { value: '', textContent: 'All Types' }));
        Object.keys(TYPES).forEach(function(k) {
            typeFilter.appendChild(App.el('option', { value: k, textContent: TYPES[k] }));
        });
        var statusFilter = App.el('select', { className: 'form-select', onChange: function() { loadAttractions(); } });
        statusFilter.appendChild(App.el('option', { value: '', textContent: 'All Statuses' }));
        Object.keys(STATUSES).forEach(function(k) {
            statusFilter.appendChild(App.el('option', { value: k, textContent: STATUSES[k] }));
        });
        filterBar.appendChild(typeFilter);
        filterBar.appendChild(statusFilter);
        page.appendChild(filterBar);

        // Stats
        var statsEl = App.el('div', { className: 'stats-row' });
        page.appendChild(statsEl);

        // Grid
        var grid = App.el('div', { className: 'attractions-grid' });
        page.appendChild(grid);

        container.appendChild(page);

        function loadAttractions() {
            var params = [];
            if (typeFilter.value) params.push('type=' + typeFilter.value);
            if (statusFilter.value) params.push('status=' + statusFilter.value);
            var qs = params.length ? '?' + params.join('&') : '';

            API.get('attractions' + qs).then(function(data) {
                renderStats(statsEl, data.stats);
                renderGrid(grid, data.attractions);
            }).catch(function(err) {
                App.toast('Failed to load attractions: ' + err.message, 'error');
            });
        }

        function renderStats(el, stats) {
            el.innerHTML = '';
            el.appendChild(statCard('Total', stats.total, 'var(--accent)'));
            el.appendChild(statCard('Open', stats.open, 'var(--success)'));
            el.appendChild(statCard('Closed', stats.closed, 'var(--text-muted)'));
            el.appendChild(statCard('Maintenance', stats.maintenance, 'var(--warning)'));
        }

        function statCard(label, value, color) {
            return App.el('div', { className: 'stat-card' }, [
                App.el('div', { className: 'stat-value', textContent: String(value), style: { color: color } }),
                App.el('div', { className: 'stat-label', textContent: label })
            ]);
        }

        function renderGrid(el, attractions) {
            el.innerHTML = '';
            if (!attractions.length) {
                el.appendChild(App.emptyState('\u2302', 'No attractions found. Add your first attraction!'));
                return;
            }
            attractions.forEach(function(a) {
                var statusCls = a.status === 'open' ? 'badge-enabled' : a.status === 'maintenance' ? 'badge-paused' : 'badge-out-of-service';
                var card = App.el('div', { className: 'card attraction-card', onClick: function() { window.location.hash = '#/attractions/' + a.id; } }, [
                    App.el('div', { className: 'attraction-card-header' }, [
                        App.el('div', { className: 'attraction-name', textContent: a.name }),
                        App.el('span', { className: 'badge ' + statusCls, textContent: STATUSES[a.status] || a.status })
                    ]),
                    App.el('div', { className: 'attraction-card-body' }, [
                        App.el('div', { className: 'attraction-type', textContent: TYPES[a.type] || a.type }),
                        a.location ? App.el('div', { className: 'text-secondary', textContent: 'Location: ' + a.location }) : App.el('span'),
                        App.el('div', { className: 'attraction-meta' }, [
                            a.capacity ? App.el('span', { textContent: 'Cap: ' + a.capacity }) : App.el('span'),
                            a.min_height_inches ? App.el('span', { textContent: 'Min height: ' + a.min_height_inches + '"' }) : App.el('span'),
                            a.duration_minutes ? App.el('span', { textContent: a.duration_minutes + ' min' }) : App.el('span'),
                            a.credit_cost ? App.el('span', { textContent: a.credit_cost + ' credits' }) : App.el('span'),
                        ])
                    ]),
                    App.el('div', { className: 'attraction-card-actions' }, [
                        App.el('button', { className: 'btn btn-sm ' + (a.status === 'open' ? 'btn-danger' : 'btn-success'), textContent: a.status === 'open' ? 'Close' : 'Open', onClick: function(e) {
                            e.stopPropagation();
                            var newStatus = a.status === 'open' ? 'closed' : 'open';
                            API.post('attractions/' + a.id + '/status', { status: newStatus }).then(function() {
                                App.toast('Status updated', 'success');
                                loadAttractions();
                            }).catch(function(err) { App.toast(err.message, 'error'); });
                        }}),
                        App.el('button', { className: 'btn btn-sm btn-warning', textContent: 'Maint.', onClick: function(e) {
                            e.stopPropagation();
                            API.post('attractions/' + a.id + '/status', { status: 'maintenance' }).then(function() {
                                App.toast('Set to maintenance', 'success');
                                loadAttractions();
                            }).catch(function(err) { App.toast(err.message, 'error'); });
                        }})
                    ])
                ]);
                el.appendChild(card);
            });
        }

        function showAttractionForm(existing) {
            var form = App.el('div', { className: 'form-grid' });
            var fields = {};

            function addField(label, name, type, opts) {
                opts = opts || {};
                var wrapper = App.el('div', { className: 'form-group' });
                wrapper.appendChild(App.el('label', { textContent: label }));
                var input;
                if (type === 'select') {
                    input = App.el('select', { className: 'form-select' });
                    (opts.options || []).forEach(function(o) {
                        input.appendChild(App.el('option', { value: o[0], textContent: o[1] }));
                    });
                } else if (type === 'checkbox') {
                    input = App.el('input', { type: 'checkbox', className: 'input-checkbox' });
                } else if (type === 'textarea') {
                    input = App.el('textarea', { className: 'form-input', rows: '3' });
                } else {
                    input = App.el('input', { type: type || 'text', className: 'form-input' });
                }
                if (existing && existing[name] !== undefined) {
                    if (type === 'checkbox') input.checked = !!existing[name];
                    else input.value = existing[name];
                }
                if (opts.placeholder) input.placeholder = opts.placeholder;
                wrapper.appendChild(input);
                form.appendChild(wrapper);
                fields[name] = input;
            }

            addField('Name *', 'name', 'text', { placeholder: 'e.g. Dragon Coaster' });
            addField('Type *', 'type', 'select', { options: Object.entries(TYPES) });
            addField('Description', 'description', 'textarea');
            addField('Location', 'location', 'text', { placeholder: 'e.g. Building A, Floor 2' });
            addField('Capacity', 'capacity', 'number');
            addField('Min Height (inches)', 'min_height_inches', 'number');
            addField('Duration (minutes)', 'duration_minutes', 'number');
            addField('Credit Cost', 'credit_cost', 'number');
            addField('Status', 'status', 'select', { options: Object.entries(STATUSES) });
            addField('Requires Wristband', 'requires_wristband', 'checkbox');
            addField('CenterEdge Game ID', 'centeredge_game_id', 'text', { placeholder: 'Link to CenterEdge game' });

            var footer = App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
                App.el('button', { className: 'btn btn-primary', textContent: existing ? 'Update' : 'Create', onClick: function() {
                    var data = {};
                    Object.keys(fields).forEach(function(k) {
                        if (fields[k].type === 'checkbox') data[k] = fields[k].checked;
                        else if (fields[k].type === 'number') data[k] = fields[k].value ? parseInt(fields[k].value) : 0;
                        else data[k] = fields[k].value;
                    });
                    var promise = existing ? API.put('attractions/' + existing.id, data) : API.post('attractions', data);
                    promise.then(function() {
                        App.hideModal();
                        App.toast(existing ? 'Attraction updated' : 'Attraction created', 'success');
                        loadAttractions();
                    }).catch(function(err) { App.toast(err.message, 'error'); });
                }})
            ]);

            App.showModal(existing ? 'Edit Attraction' : 'New Attraction', form, footer);
        }

        // Expose for detail page
        window._attractionForm = showAttractionForm;

        loadAttractions();
    }

    function renderAttractionDetail(container, params) {
        var page = App.el('div', { className: 'page-attraction-detail' });
        container.appendChild(page);

        API.get('attractions/' + params.id).then(function(data) {
            page.innerHTML = '';
            var statusCls = data.status === 'open' ? 'badge-enabled' : data.status === 'maintenance' ? 'badge-paused' : 'badge-out-of-service';

            var header = App.el('div', { className: 'page-header' }, [
                App.el('div', {}, [
                    App.el('h2', { textContent: data.name }),
                    App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', marginTop: '4px' } }, [
                        App.el('span', { className: 'badge ' + statusCls, textContent: STATUSES[data.status] || data.status }),
                        App.el('span', { className: 'text-secondary', textContent: TYPES[data.type] || data.type })
                    ])
                ]),
                App.el('div', { className: 'flex gap-sm' }, [
                    App.el('button', { className: 'btn btn-secondary', textContent: 'Edit', onClick: function() { window._attractionForm && window._attractionForm(data); } }),
                    App.el('button', { className: 'btn btn-ghost', textContent: 'Back', onClick: function() { window.location.hash = '#/attractions'; } })
                ])
            ]);
            page.appendChild(header);

            // Details grid
            var details = App.el('div', { className: 'card' }, [
                App.el('h3', { textContent: 'Details', style: { marginBottom: '12px' } }),
                detailRow('Location', data.location || '-'),
                detailRow('Capacity', data.capacity ? String(data.capacity) + ' riders' : '-'),
                detailRow('Min Height', data.min_height_inches ? data.min_height_inches + '"' : 'No restriction'),
                detailRow('Duration', data.duration_minutes ? data.duration_minutes + ' min' : '-'),
                detailRow('Credit Cost', data.credit_cost ? String(data.credit_cost) + ' credits' : 'Free'),
                detailRow('Wristband Required', data.requires_wristband ? 'Yes' : 'No'),
                detailRow('CenterEdge ID', data.centeredge_game_id || 'Not linked'),
                detailRow('Description', data.description || '-'),
            ]);
            page.appendChild(details);

            // Recent maintenance
            if (data.recent_maintenance && data.recent_maintenance.length) {
                var maintCard = App.el('div', { className: 'card', style: { marginTop: '16px' } });
                maintCard.appendChild(App.el('h3', { textContent: 'Recent Maintenance', style: { marginBottom: '12px' } }));
                data.recent_maintenance.forEach(function(t) {
                    var prCls = t.priority === 'critical' ? 'badge-danger' : t.priority === 'high' ? 'badge-paused' : '';
                    maintCard.appendChild(App.el('div', { className: 'list-item', style: { padding: '8px 0', borderBottom: '1px solid var(--border)' } }, [
                        App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center' } }, [
                            App.el('span', { className: 'badge ' + prCls, textContent: t.priority }),
                            App.el('span', { textContent: t.title }),
                            App.el('span', { className: 'text-secondary', textContent: ' - ' + t.status })
                        ]),
                        App.el('div', { className: 'text-secondary', textContent: App.formatDatetime(t.created_at) })
                    ]));
                });
                page.appendChild(maintCard);
            }
        }).catch(function(err) {
            page.appendChild(App.el('div', { className: 'alert alert-error', textContent: 'Failed to load: ' + err.message }));
        });
    }

    function detailRow(label, value) {
        return App.el('div', { className: 'detail-row' }, [
            App.el('span', { className: 'detail-label', textContent: label }),
            App.el('span', { className: 'detail-value', textContent: value })
        ]);
    }
})();
