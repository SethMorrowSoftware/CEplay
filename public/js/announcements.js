/**
 * Staff Announcements — internal communication board for Castle Fun Center staff.
 */
(function() {
    App.registerRoute('#/announcements', { render: renderAnnouncements });

    var PRIORITIES = { low: 'Low', normal: 'Normal', high: 'High', urgent: 'Urgent' };
    var CATEGORIES = { general: 'General', safety: 'Safety', maintenance: 'Maintenance', events: 'Events', policy: 'Policy', kudos: 'Kudos' };
    var CATEGORY_ICONS = { general: '\u2139', safety: '\u26A0', maintenance: '\u2692', events: '\u2606', policy: '\u2710', kudos: '\u2764' };

    function renderAnnouncements(container) {
        var page = App.el('div', { className: 'page-announcements' });

        var header = App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h2', { textContent: 'Staff Announcements' }),
                App.el('p', { className: 'text-secondary', textContent: 'Internal communication board for the team' })
            ]),
            App.el('button', { className: 'btn btn-primary', textContent: '+ Post Announcement', onClick: function() { showAnnouncementForm(); } })
        ]);
        page.appendChild(header);

        // Category filter
        var filterBar = App.el('div', { className: 'filter-bar' });
        var catFilter = App.el('select', { className: 'form-select', onChange: function() { loadAnnouncements(); } });
        catFilter.appendChild(App.el('option', { value: '', textContent: 'All Categories' }));
        Object.keys(CATEGORIES).forEach(function(k) { catFilter.appendChild(App.el('option', { value: k, textContent: CATEGORIES[k] })); });
        filterBar.appendChild(catFilter);
        page.appendChild(filterBar);

        var feed = App.el('div', { className: 'announcement-feed' });
        page.appendChild(feed);
        container.appendChild(page);

        function loadAnnouncements() {
            var qs = catFilter.value ? '?category=' + catFilter.value : '';
            API.get('announcements' + qs).then(function(data) {
                feed.innerHTML = '';
                if (!data.announcements.length) {
                    feed.appendChild(App.emptyState('\u2709', 'No announcements. Post something for the team!'));
                    return;
                }

                data.announcements.forEach(function(a) {
                    var prCls = a.priority === 'urgent' ? 'announcement-urgent' : a.priority === 'high' ? 'announcement-high' : '';
                    var icon = CATEGORY_ICONS[a.category] || '\u2139';

                    var card = App.el('div', { className: 'card announcement-card ' + prCls, style: { marginBottom: '12px', padding: '16px' } });

                    // Header row
                    var headerRow = App.el('div', { className: 'flex', style: { justifyContent: 'space-between', alignItems: 'flex-start' } });
                    var leftSide = App.el('div', {}, [
                        App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', marginBottom: '4px' } }, [
                            a.is_pinned ? App.el('span', { textContent: '\u{1F4CC}', title: 'Pinned', style: { fontSize: '0.9em' } }) : App.el('span'),
                            App.el('span', { className: 'badge', textContent: icon + ' ' + (CATEGORIES[a.category] || a.category) }),
                            a.priority !== 'normal' ? App.el('span', { className: 'badge ' + (a.priority === 'urgent' ? 'badge-danger' : a.priority === 'high' ? 'badge-paused' : ''), textContent: PRIORITIES[a.priority] }) : App.el('span'),
                        ]),
                        App.el('h3', { textContent: a.title, style: { margin: '4px 0' } }),
                    ]);
                    var rightSide = App.el('div', { className: 'flex gap-sm' }, [
                        App.el('button', { className: 'btn btn-ghost btn-sm', textContent: a.is_pinned ? 'Unpin' : 'Pin', onClick: function() {
                            API.post('announcements/' + a.id + '/pin').then(function() { loadAnnouncements(); }).catch(function(err) { App.toast(err.message, 'error'); });
                        }}),
                        App.el('button', { className: 'btn btn-ghost btn-sm', textContent: 'Edit', onClick: function() { showAnnouncementForm(a); } }),
                        App.el('button', { className: 'btn btn-ghost btn-sm', textContent: 'Delete', onClick: function() {
                            App.confirm('Delete this announcement?').then(function(ok) {
                                if (ok) API.del('announcements/' + a.id).then(function() { App.toast('Deleted', 'success'); loadAnnouncements(); }).catch(function(err) { App.toast(err.message, 'error'); });
                            });
                        }})
                    ]);
                    headerRow.appendChild(leftSide);
                    headerRow.appendChild(rightSide);
                    card.appendChild(headerRow);

                    // Body
                    var bodyEl = App.el('div', { className: 'announcement-body', style: { marginTop: '8px', whiteSpace: 'pre-wrap' } });
                    bodyEl.textContent = a.body;
                    card.appendChild(bodyEl);

                    // Footer
                    var meta = [];
                    if (a.author_name) meta.push('Posted by ' + a.author_name);
                    meta.push(App.formatDatetime(a.created_at));
                    if (a.expires_at) meta.push('Expires: ' + App.formatDatetime(a.expires_at));
                    card.appendChild(App.el('div', { className: 'text-secondary text-sm', style: { marginTop: '8px' }, textContent: meta.join(' | ') }));

                    feed.appendChild(card);
                });
            }).catch(function(err) { App.toast(err.message, 'error'); });
        }

        function showAnnouncementForm(existing) {
            var form = App.el('div', { className: 'form-grid' });

            var titleInput = App.el('input', { className: 'form-input', value: existing ? existing.title : '', placeholder: 'Announcement title' });
            var bodyInput = App.el('textarea', { className: 'form-input', rows: '6', placeholder: 'Write your announcement...' });
            bodyInput.value = existing ? existing.body : '';
            var prioritySelect = App.el('select', { className: 'form-select' });
            Object.keys(PRIORITIES).forEach(function(k) {
                var opt = App.el('option', { value: k, textContent: PRIORITIES[k] });
                if (existing && existing.priority === k) opt.selected = true;
                else if (!existing && k === 'normal') opt.selected = true;
                prioritySelect.appendChild(opt);
            });
            var categorySelect = App.el('select', { className: 'form-select' });
            Object.keys(CATEGORIES).forEach(function(k) {
                var opt = App.el('option', { value: k, textContent: CATEGORIES[k] });
                if (existing && existing.category === k) opt.selected = true;
                categorySelect.appendChild(opt);
            });
            var pinnedCheck = App.el('input', { type: 'checkbox', className: 'input-checkbox' });
            if (existing && existing.is_pinned) pinnedCheck.checked = true;
            var expiresInput = App.el('input', { className: 'form-input', type: 'datetime-local', value: existing && existing.expires_at ? existing.expires_at.replace(' ', 'T') : '' });

            form.appendChild(formGroup('Title *', titleInput));
            form.appendChild(formGroup('Message *', bodyInput));
            form.appendChild(formGroup('Priority', prioritySelect));
            form.appendChild(formGroup('Category', categorySelect));
            form.appendChild(formGroup('Pin to Top', pinnedCheck));
            form.appendChild(formGroup('Expires At', expiresInput));

            var footer = App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
                App.el('button', { className: 'btn btn-primary', textContent: existing ? 'Update' : 'Post', onClick: function() {
                    var data = {
                        title: titleInput.value,
                        body: bodyInput.value,
                        priority: prioritySelect.value,
                        category: categorySelect.value,
                        is_pinned: pinnedCheck.checked,
                        expires_at: expiresInput.value ? expiresInput.value.replace('T', ' ').substring(0, 16) : null,
                    };
                    var promise = existing ? API.put('announcements/' + existing.id, data) : API.post('announcements', data);
                    promise.then(function() {
                        App.hideModal();
                        App.toast(existing ? 'Updated' : 'Posted', 'success');
                        loadAnnouncements();
                    }).catch(function(err) { App.toast(err.message, 'error'); });
                }})
            ]);

            App.showModal(existing ? 'Edit Announcement' : 'New Announcement', form, footer);
        }

        loadAnnouncements();
    }

    function formGroup(label, input) {
        return App.el('div', { className: 'form-group' }, [App.el('label', { textContent: label }), input]);
    }
})();
