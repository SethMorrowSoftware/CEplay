/**
 * Party/Event Booking — birthday parties, group events, private rentals.
 */
(function() {
    App.registerRoute('#/parties', { render: renderParties });

    var BOOKING_STATUSES = { pending: 'Pending', confirmed: 'Confirmed', cancelled: 'Cancelled', completed: 'Completed' };

    function renderParties(container) {
        var page = App.el('div', { className: 'page-parties' });
        var packages = [];
        var activeTab = 'bookings';

        var header = App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h2', { textContent: 'Party & Event Bookings' }),
                App.el('p', { className: 'text-secondary', textContent: 'Manage birthday parties, group events, and private rentals' })
            ]),
            App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Manage Packages', onClick: function() { showTab('packages'); } }),
                App.el('button', { className: 'btn btn-primary', textContent: '+ New Booking', onClick: function() { showBookingForm(); } })
            ])
        ]);
        page.appendChild(header);

        // Tabs
        var tabBar = App.el('div', { className: 'tab-bar' });
        var tabBookings = App.el('button', { className: 'tab-btn active', textContent: 'Bookings', onClick: function() { showTab('bookings'); } });
        var tabToday = App.el('button', { className: 'tab-btn', textContent: "Today's Parties", onClick: function() { showTab('today'); } });
        var tabCalendar = App.el('button', { className: 'tab-btn', textContent: 'Calendar', onClick: function() { showTab('calendar'); } });
        var tabPkg = App.el('button', { className: 'tab-btn', textContent: 'Packages', onClick: function() { showTab('packages'); } });
        tabBar.appendChild(tabBookings);
        tabBar.appendChild(tabToday);
        tabBar.appendChild(tabCalendar);
        tabBar.appendChild(tabPkg);
        page.appendChild(tabBar);

        var content = App.el('div', { className: 'tab-content' });
        page.appendChild(content);
        container.appendChild(page);

        function showTab(tab) {
            activeTab = tab;
            [tabBookings, tabToday, tabCalendar, tabPkg].forEach(function(b) { b.classList.remove('active'); });
            if (tab === 'bookings') { tabBookings.classList.add('active'); loadBookings(); }
            else if (tab === 'today') { tabToday.classList.add('active'); loadToday(); }
            else if (tab === 'calendar') { tabCalendar.classList.add('active'); loadCalendar(); }
            else if (tab === 'packages') { tabPkg.classList.add('active'); loadPackages(); }
        }

        function loadBookings() {
            API.get('parties').then(function(data) {
                content.innerHTML = '';
                if (!data.bookings.length) {
                    content.appendChild(App.emptyState('\u2606', 'No bookings yet. Create the first one!'));
                    return;
                }
                var table = App.el('table', { className: 'data-table' });
                var thead = App.el('thead', {}, [App.el('tr', {}, [
                    App.el('th', { textContent: 'Date' }), App.el('th', { textContent: 'Time' }),
                    App.el('th', { textContent: 'Guest' }), App.el('th', { textContent: 'Package' }),
                    App.el('th', { textContent: 'Guests' }), App.el('th', { textContent: 'Status' }),
                    App.el('th', { textContent: 'Area' }), App.el('th', { textContent: 'Actions' })
                ])]);
                table.appendChild(thead);
                var tbody = App.el('tbody');
                data.bookings.forEach(function(b) {
                    var stCls = b.status === 'confirmed' ? 'badge-enabled' : b.status === 'cancelled' ? 'badge-out-of-service' : b.status === 'completed' ? 'badge-paused' : '';
                    var row = App.el('tr', {}, [
                        App.el('td', { textContent: App.formatDate(b.party_date) }),
                        App.el('td', { textContent: App.formatTime(b.start_time) + ' - ' + App.formatTime(b.end_time) }),
                        App.el('td', {}, [
                            App.el('div', { textContent: b.guest_name, style: { fontWeight: '500' } }),
                            App.el('div', { className: 'text-secondary text-sm', textContent: b.guest_phone || '' })
                        ]),
                        App.el('td', { textContent: b.package_name || '-' }),
                        App.el('td', { textContent: String(b.guest_count) }),
                        App.el('td', {}, [App.el('span', { className: 'badge ' + stCls, textContent: BOOKING_STATUSES[b.status] || b.status })]),
                        App.el('td', { textContent: b.assigned_area || '-' }),
                        App.el('td', { className: 'flex gap-sm' }, [
                            App.el('button', { className: 'btn btn-sm btn-secondary', textContent: 'Edit', onClick: function() { showBookingForm(b); } }),
                            b.status === 'confirmed' ? App.el('button', { className: 'btn btn-sm btn-success', textContent: 'Complete', onClick: function() {
                                API.post('parties/' + b.id + '/status', { status: 'completed' }).then(function() { App.toast('Marked complete', 'success'); loadBookings(); }).catch(function(err) { App.toast(err.message, 'error'); });
                            }}) : App.el('span'),
                            b.status !== 'cancelled' ? App.el('button', { className: 'btn btn-sm btn-danger', textContent: 'Cancel', onClick: function() {
                                App.confirm('Cancel this booking?').then(function(ok) {
                                    if (ok) API.post('parties/' + b.id + '/status', { status: 'cancelled' }).then(function() { App.toast('Cancelled', 'success'); loadBookings(); }).catch(function(err) { App.toast(err.message, 'error'); });
                                });
                            }}) : App.el('span')
                        ])
                    ]);
                    tbody.appendChild(row);
                });
                table.appendChild(tbody);
                content.appendChild(table);
            }).catch(function(err) { App.toast(err.message, 'error'); });
        }

        function loadToday() {
            API.get('parties/today').then(function(data) {
                content.innerHTML = '';
                if (!data.bookings.length) {
                    content.appendChild(App.emptyState('\u2606', 'No parties scheduled for today.'));
                    return;
                }
                content.appendChild(App.el('h3', { textContent: "Today's Parties (" + data.bookings.length + ')', style: { marginBottom: '12px' } }));
                data.bookings.forEach(function(b) {
                    var card = App.el('div', { className: 'card', style: { marginBottom: '12px', padding: '16px' } }, [
                        App.el('div', { className: 'flex', style: { justifyContent: 'space-between', alignItems: 'center' } }, [
                            App.el('div', {}, [
                                App.el('div', { style: { fontWeight: '600', fontSize: '1.1em' }, textContent: App.formatTime(b.start_time) + ' - ' + App.formatTime(b.end_time) }),
                                App.el('div', { textContent: b.guest_name + ' (' + b.guest_count + ' guests)', style: { marginTop: '4px' } }),
                                b.package_name ? App.el('div', { className: 'text-secondary', textContent: 'Package: ' + b.package_name }) : App.el('span'),
                                b.assigned_area ? App.el('div', { className: 'text-secondary', textContent: 'Area: ' + b.assigned_area }) : App.el('span'),
                                b.special_requests ? App.el('div', { className: 'text-secondary', textContent: 'Notes: ' + b.special_requests }) : App.el('span'),
                            ]),
                            App.el('span', { className: 'badge badge-enabled', textContent: BOOKING_STATUSES[b.status] || b.status })
                        ])
                    ]);
                    content.appendChild(card);
                });
            }).catch(function(err) { App.toast(err.message, 'error'); });
        }

        function loadCalendar() {
            var month = new Date().toISOString().substring(0, 7);
            API.get('parties/calendar?month=' + month).then(function(data) {
                content.innerHTML = '';
                content.appendChild(App.el('h3', { textContent: 'Bookings for ' + month, style: { marginBottom: '12px' } }));

                if (!data.bookings.length) {
                    content.appendChild(App.emptyState('\u2606', 'No bookings this month.'));
                    return;
                }

                // Render calendar-style list grouped by date
                Object.keys(data.calendar).sort().forEach(function(date) {
                    var dayBookings = data.calendar[date];
                    var dayEl = App.el('div', { className: 'card', style: { marginBottom: '12px', padding: '12px' } });
                    dayEl.appendChild(App.el('div', { style: { fontWeight: '600', marginBottom: '8px' }, textContent: App.formatDate(date) + ' (' + dayBookings.length + ' booking' + (dayBookings.length > 1 ? 's' : '') + ')' }));
                    dayBookings.forEach(function(b) {
                        dayEl.appendChild(App.el('div', { style: { padding: '4px 0', borderTop: '1px solid var(--border)' }, textContent: App.formatTime(b.start_time) + ' - ' + App.formatTime(b.end_time) + ': ' + b.guest_name + ' (' + b.guest_count + ' guests)' }));
                    });
                    content.appendChild(dayEl);
                });
            }).catch(function(err) { App.toast(err.message, 'error'); });
        }

        function loadPackages() {
            API.get('parties/packages').then(function(data) {
                packages = data.packages;
                content.innerHTML = '';
                content.appendChild(App.el('div', { className: 'flex', style: { justifyContent: 'space-between', marginBottom: '12px' } }, [
                    App.el('h3', { textContent: 'Party Packages' }),
                    App.el('button', { className: 'btn btn-primary btn-sm', textContent: '+ New Package', onClick: function() { showPackageForm(); } })
                ]));

                if (!packages.length) {
                    content.appendChild(App.emptyState('\u2606', 'No packages defined. Create a party package!'));
                    return;
                }

                packages.forEach(function(p) {
                    var includes = [];
                    if (p.includes_food) includes.push('Food');
                    if (p.includes_drinks) includes.push('Drinks');
                    if (p.game_credits) includes.push(p.game_credits + ' game credits');
                    if (p.ride_passes) includes.push(p.ride_passes + ' ride passes');

                    var card = App.el('div', { className: 'card', style: { marginBottom: '12px', padding: '16px' } }, [
                        App.el('div', { className: 'flex', style: { justifyContent: 'space-between', alignItems: 'center' } }, [
                            App.el('div', {}, [
                                App.el('div', { style: { fontWeight: '600', fontSize: '1.1em' }, textContent: p.name }),
                                p.description ? App.el('div', { className: 'text-secondary', textContent: p.description }) : App.el('span'),
                                App.el('div', { style: { marginTop: '8px' }, textContent: p.duration_minutes + ' min | Up to ' + p.max_guests + ' guests | $' + (p.price_cents / 100).toFixed(2) }),
                                includes.length ? App.el('div', { className: 'text-secondary', textContent: 'Includes: ' + includes.join(', ') }) : App.el('span'),
                            ]),
                            App.el('div', { className: 'flex gap-sm' }, [
                                App.el('button', { className: 'btn btn-sm btn-secondary', textContent: 'Edit', onClick: function() { showPackageForm(p); } }),
                                App.el('button', { className: 'btn btn-sm btn-danger', textContent: 'Delete', onClick: function() {
                                    App.confirm('Delete this package?').then(function(ok) {
                                        if (ok) API.del('parties/packages/' + p.id).then(function() { App.toast('Deleted', 'success'); loadPackages(); }).catch(function(err) { App.toast(err.message, 'error'); });
                                    });
                                }})
                            ])
                        ])
                    ]);
                    content.appendChild(card);
                });
            }).catch(function(err) { App.toast(err.message, 'error'); });
        }

        function showBookingForm(existing) {
            // Load packages for dropdown
            API.get('parties/packages').then(function(data) {
                packages = data.packages;
                renderBookingModal(existing);
            });
        }

        function renderBookingModal(existing) {
            var form = App.el('div', { className: 'form-grid' });

            var guestName = App.el('input', { className: 'form-input', value: existing ? existing.guest_name : '', placeholder: 'Party host name' });
            var guestPhone = App.el('input', { className: 'form-input', value: existing ? existing.guest_phone : '', placeholder: '(555) 123-4567' });
            var guestEmail = App.el('input', { className: 'form-input', type: 'email', value: existing ? existing.guest_email : '' });
            var pkgSelect = App.el('select', { className: 'form-select' });
            pkgSelect.appendChild(App.el('option', { value: '', textContent: '-- No Package --' }));
            packages.forEach(function(p) {
                var opt = App.el('option', { value: String(p.id), textContent: p.name + ' ($' + (p.price_cents / 100).toFixed(2) + ')' });
                if (existing && existing.package_id === p.id) opt.selected = true;
                pkgSelect.appendChild(opt);
            });
            var partyDate = App.el('input', { className: 'form-input', type: 'date', value: existing ? existing.party_date : '' });
            var startTime = App.el('input', { className: 'form-input', type: 'time', value: existing ? existing.start_time : '' });
            var endTime = App.el('input', { className: 'form-input', type: 'time', value: existing ? existing.end_time : '' });
            var guestCount = App.el('input', { className: 'form-input', type: 'number', value: existing ? String(existing.guest_count) : '10' });
            var area = App.el('input', { className: 'form-input', value: existing ? (existing.assigned_area || '') : '', placeholder: 'e.g. Party Room A' });
            var specialReq = App.el('textarea', { className: 'form-input', rows: '3', placeholder: 'Allergies, special decorations, etc.' });
            specialReq.value = existing ? (existing.special_requests || '') : '';
            var priceCents = App.el('input', { className: 'form-input', type: 'number', value: existing ? String(existing.total_price_cents) : '0', placeholder: 'Price in cents' });
            var depositCheck = App.el('input', { type: 'checkbox', className: 'input-checkbox' });
            if (existing && existing.deposit_paid) depositCheck.checked = true;

            form.appendChild(formGroup('Guest Name *', guestName));
            form.appendChild(formGroup('Phone', guestPhone));
            form.appendChild(formGroup('Email', guestEmail));
            form.appendChild(formGroup('Package', pkgSelect));
            form.appendChild(formGroup('Party Date *', partyDate));
            form.appendChild(formGroup('Start Time *', startTime));
            form.appendChild(formGroup('End Time *', endTime));
            form.appendChild(formGroup('Guest Count', guestCount));
            form.appendChild(formGroup('Assigned Area', area));
            form.appendChild(formGroup('Special Requests', specialReq));
            form.appendChild(formGroup('Total Price (cents)', priceCents));
            form.appendChild(formGroup('Deposit Paid', depositCheck));

            var footer = App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
                App.el('button', { className: 'btn btn-primary', textContent: existing ? 'Update' : 'Book', onClick: function() {
                    var data = {
                        guest_name: guestName.value,
                        guest_phone: guestPhone.value,
                        guest_email: guestEmail.value,
                        package_id: pkgSelect.value ? parseInt(pkgSelect.value) : null,
                        party_date: partyDate.value,
                        start_time: startTime.value,
                        end_time: endTime.value,
                        guest_count: parseInt(guestCount.value) || 10,
                        assigned_area: area.value,
                        special_requests: specialReq.value,
                        total_price_cents: parseInt(priceCents.value) || 0,
                        deposit_paid: depositCheck.checked,
                    };
                    var promise = existing ? API.put('parties/' + existing.id, data) : API.post('parties', data);
                    promise.then(function() {
                        App.hideModal();
                        App.toast(existing ? 'Booking updated' : 'Booking created', 'success');
                        loadBookings();
                    }).catch(function(err) { App.toast(err.message, 'error'); });
                }})
            ]);

            App.showModal(existing ? 'Edit Booking' : 'New Party Booking', form, footer);
        }

        function showPackageForm(existing) {
            var form = App.el('div', { className: 'form-grid' });
            var name = App.el('input', { className: 'form-input', value: existing ? existing.name : '' });
            var desc = App.el('textarea', { className: 'form-input', rows: '2' });
            desc.value = existing ? (existing.description || '') : '';
            var duration = App.el('input', { className: 'form-input', type: 'number', value: existing ? String(existing.duration_minutes) : '120' });
            var maxGuests = App.el('input', { className: 'form-input', type: 'number', value: existing ? String(existing.max_guests) : '20' });
            var price = App.el('input', { className: 'form-input', type: 'number', value: existing ? String(existing.price_cents) : '0', placeholder: 'Price in cents' });
            var food = App.el('input', { type: 'checkbox', className: 'input-checkbox' }); if (existing && existing.includes_food) food.checked = true;
            var drinks = App.el('input', { type: 'checkbox', className: 'input-checkbox' }); if (existing && existing.includes_drinks) drinks.checked = true;
            var credits = App.el('input', { className: 'form-input', type: 'number', value: existing ? String(existing.game_credits) : '0' });
            var rides = App.el('input', { className: 'form-input', type: 'number', value: existing ? String(existing.ride_passes) : '0' });

            form.appendChild(formGroup('Name *', name));
            form.appendChild(formGroup('Description', desc));
            form.appendChild(formGroup('Duration (min)', duration));
            form.appendChild(formGroup('Max Guests', maxGuests));
            form.appendChild(formGroup('Price (cents)', price));
            form.appendChild(formGroup('Includes Food', food));
            form.appendChild(formGroup('Includes Drinks', drinks));
            form.appendChild(formGroup('Game Credits', credits));
            form.appendChild(formGroup('Ride Passes', rides));

            var footer = App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
                App.el('button', { className: 'btn btn-primary', textContent: existing ? 'Update' : 'Create', onClick: function() {
                    var data = {
                        name: name.value, description: desc.value,
                        duration_minutes: parseInt(duration.value) || 120, max_guests: parseInt(maxGuests.value) || 20,
                        price_cents: parseInt(price.value) || 0,
                        includes_food: food.checked, includes_drinks: drinks.checked,
                        game_credits: parseInt(credits.value) || 0, ride_passes: parseInt(rides.value) || 0,
                    };
                    var promise = existing ? API.put('parties/packages/' + existing.id, data) : API.post('parties/packages', data);
                    promise.then(function() {
                        App.hideModal();
                        App.toast(existing ? 'Package updated' : 'Package created', 'success');
                        loadPackages();
                    }).catch(function(err) { App.toast(err.message, 'error'); });
                }})
            ]);

            App.showModal(existing ? 'Edit Package' : 'New Party Package', form, footer);
        }

        showTab('bookings');
    }

    function formGroup(label, input) {
        return App.el('div', { className: 'form-group' }, [App.el('label', { textContent: label }), input]);
    }
})();
