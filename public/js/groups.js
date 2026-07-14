/**
 * Pause group management: list, create, edit, delete.
 * Enhanced game picker for managing hundreds of games.
 */
(function() {
    App.registerRoute('#/groups', { render: renderGroupList });
    App.registerRoute('#/groups/new', { render: renderGroupForm });
    App.registerRoute('#/groups/:id', { render: renderGroupForm });

    // Module-level state for the group list page
    var allGroups = [];
    var groupSearch = '';
    var groupStatusFilter = 'all'; // 'all', 'active', 'inactive', 'paused', 'enabled', 'mixed'
    var groupPaging = { page: 1, pageSize: 25, totalItems: 0 };

    async function renderGroupList(container) {
        // Reset state on every visit
        groupSearch = '';
        groupStatusFilter = 'all';
        groupPaging = { page: 1, pageSize: 25, totalItems: 0 };

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Pause Groups' }),
                App.el('p', { className: 'page-subtitle', textContent: 'Bundles of games and kiosks paused or unpaused as a unit by schedules, overrides, and manual actions.' })
            ]),
            App.canAccess('groups_manage') ? App.el('button', {
                className: 'btn btn-primary',
                textContent: '+ New Group',
                onClick: () => { window.location.hash = '#/groups/new'; }
            }) : null
        ].filter(Boolean)));

        // Toolbar \u2014 search + status filter. Hidden until we know there's
        // enough groups to make filtering useful (revealed by renderGroupCards).
        const searchInput = App.buildSearchInput({
            placeholder: 'Search groups by name or description\u2026',
            ariaLabel: 'Search pause groups',
            onSearch: function(term) {
                groupSearch = term.toLowerCase();
                groupPaging.page = 1;
                renderGroupCards();
            }
        });
        searchInput.style.flex = '1';

        const toolbar = App.el('div', { id: 'groups-toolbar', className: 'toolbar-row', style: { display: 'none' } }, [
            searchInput,
            buildGroupStatusFilter()
        ]);
        container.appendChild(toolbar);

        const metaEl = App.el('div', { id: 'groups-meta', className: 'text-sm text-secondary', style: { marginBottom: '0.5rem' } });
        container.appendChild(metaEl);

        const listEl = App.el('div', { id: 'groups-list' });
        listEl.appendChild(App.loading());
        container.appendChild(listEl);

        container.appendChild(App.el('div', { id: 'groups-pagination' }));

        try {
            const data = await API.get('groups') || {};
            allGroups = data.groups || [];
            renderGroupCards();
        } catch (err) {
            listEl.innerHTML = '';
            App.toast(err.message, 'error');
        }
    }

    function buildGroupStatusFilter() {
        const sel = App.el('select', {
            className: 'form-input form-input-sm',
            'aria-label': 'Filter groups by state',
            onChange: function(e) {
                groupStatusFilter = e.target.value;
                groupPaging.page = 1;
                renderGroupCards();
            }
        });
        [
            ['all', 'All states'],
            ['active', 'Active'],
            ['inactive', 'Inactive'],
            ['enabled', 'Running'],
            ['paused', 'Paused'],
            ['mixed', 'Mixed']
        ].forEach(function(opt) {
            const o = App.el('option', { value: opt[0], textContent: opt[1] });
            if (opt[0] === groupStatusFilter) o.selected = true;
            sel.appendChild(o);
        });
        return sel;
    }

    function getFilteredGroups() {
        return allGroups.filter(function(g) {
            // Status filter
            if (groupStatusFilter === 'active' && g.is_active != 1) return false;
            if (groupStatusFilter === 'inactive' && g.is_active == 1) return false;
            if (groupStatusFilter === 'enabled' && g.effective_state !== 'enabled') return false;
            if (groupStatusFilter === 'paused' && g.effective_state !== 'paused') return false;
            if (groupStatusFilter === 'mixed' && g.effective_state !== 'mixed') return false;
            // Search by name or description
            if (groupSearch) {
                if (!App.matchesSearch(g, groupSearch, ['name', 'description'])) return false;
            }
            return true;
        });
    }

    /**
     * Render the list of group cards from the cached `allGroups`. Filters,
     * search, and pagination are applied before slicing.
     */
    function renderGroupCards() {
        const listEl = document.getElementById('groups-list');
        const pagerEl = document.getElementById('groups-pagination');
        const toolbarEl = document.getElementById('groups-toolbar');
        const metaEl = document.getElementById('groups-meta');
        if (!listEl) return;

        listEl.innerHTML = '';
        if (pagerEl) pagerEl.innerHTML = '';
        if (metaEl) metaEl.innerHTML = '';

        if (allGroups.length === 0) {
            if (toolbarEl) toolbarEl.style.display = 'none';
            listEl.appendChild(App.emptyState('\u25CB', 'No pause groups yet.',
                App.canAccess('groups_manage') ? App.el('button', {
                    className: 'btn btn-primary', textContent: 'Create First Group',
                    onClick: () => { window.location.hash = '#/groups/new'; }
                }) : null));
            return;
        }

        // Reveal toolbar \u2014 even with a small list, search is a faster way
        // to find a known group by name when you have many of them.
        if (toolbarEl) toolbarEl.style.display = '';

        const filtered = getFilteredGroups();
        groupPaging.totalItems = filtered.length;
        const page = App.paginate(filtered, groupPaging.page, groupPaging.pageSize);
        groupPaging.page = page.page;

        if (metaEl) {
            const pieces = [filtered.length + ' group' + (filtered.length === 1 ? '' : 's')];
            if (filtered.length !== allGroups.length) pieces.push('of ' + allGroups.length + ' total');
            metaEl.textContent = pieces.join('  \u2022  ');
        }

        if (filtered.length === 0) {
            listEl.appendChild(App.el('p', {
                className: 'text-sm text-secondary',
                style: { padding: '1rem 0' },
                textContent: 'No groups match these filters.'
            }));
            return;
        }

        page.items.forEach(group => {
            listEl.appendChild(buildGroupCard(group, listEl));
        });

        if (pagerEl && filtered.length > groupPaging.pageSize) {
            pagerEl.appendChild(App.buildPaginationBar(groupPaging, function() { renderGroupCards(); }, {
                pageSizeOptions: [25, 50, 100, 200],
                itemLabel: 'groups',
                showPageNumbers: true
            }));
        }
    }

    /**
     * Build a single group card element. State badge, member summary, and
     * quick-action buttons are all derived from the group payload.
     */
    function buildGroupCard(group, listEl) {
        const state = group.effective_state || 'empty';
        const isActive = group.is_active == 1;

        const stateBadge = isActive && state !== 'empty'
            ? App.el('span', {
                className: 'badge badge-' + (state === 'enabled' ? 'enabled' : state === 'paused' ? 'paused' : 'info'),
                textContent: state === 'enabled' ? 'Running' : state === 'paused' ? 'Paused' : 'Mixed'
            }) : null;

        // Quick-action buttons are interactive descendants — App.makeCardLink
        // ignores clicks/keystrokes that originate inside them, so the buttons
        // and the card-as-link both work without manual stopPropagation.
        const quickActions = isActive && state !== 'empty' && App.canAccess('manual_control')
            ? App.el('div', { className: 'flex gap-sm', style: { marginLeft: '0.75rem' } }, [
                state !== 'enabled' ? App.el('button', {
                    className: 'btn btn-sm btn-success',
                    textContent: 'Unpause',
                    onClick: () => { quickAction(group.id, 'unpause', group.name, listEl); }
                }) : null,
                state !== 'paused' ? App.el('button', {
                    className: 'btn btn-sm btn-warning',
                    textContent: 'Pause',
                    onClick: () => { quickAction(group.id, 'pause', group.name, listEl); }
                }) : null
            ].filter(Boolean)) : null;

        const card = App.el('div', { className: 'card', style: { marginBottom: '0.75rem' } }, [
            App.el('div', { className: 'flex-between' }, [
                App.el('div', { style: { flex: '1', minWidth: '0' } }, [
                    App.el('div', { className: 'flex-center gap-sm' }, [
                        App.el('span', { className: 'status-dot status-dot-' + (isActive ? state : 'empty') }),
                        App.el('span', { className: 'card-title', textContent: group.name }),
                        App.el('span', { className: 'badge ' + (isActive ? 'badge-active' : 'badge-inactive'),
                            textContent: isActive ? 'Active' : 'Inactive' }),
                        stateBadge
                    ].filter(Boolean)),
                    group.description ? App.el('p', { className: 'text-sm text-secondary mt-1', textContent: group.description }) : null
                ].filter(Boolean)),
                App.el('div', { className: 'flex-center' }, [
                    App.el('div', { className: 'text-sm text-secondary', style: { textAlign: 'right' } }, [
                        App.el('div', { textContent: formatGroupMembers(group) }),
                        App.el('div', { textContent: pluralize(group.schedule_count || 0, 'schedule') })
                    ]),
                    quickActions
                ].filter(Boolean))
            ])
        ]);
        // Card click-through opens the edit form — only wire it for roles
        // that can actually edit, so techs aren't bounced back with a toast.
        if (App.canAccess('groups_manage')) {
            App.makeCardLink(card, '#/groups/' + encodeURIComponent(group.id),
                { title: 'Edit group "' + group.name + '"' });
        }
        return card;
    }

    /**
     * Build a one-line summary of what a group contains. Empty parts are
     * omitted so a kiosk-only group reads "2 kiosks" rather than
     * "0 categories, 0 games, 2 kiosks".
     */
    function formatGroupMembers(group) {
        const stats = group.game_stats || {};
        const gameCount = stats.total != null ? stats.total : (group.game_count || 0);
        const kioskCount = (group.kiosk_stats && group.kiosk_stats.total) || 0;
        const catCount = group.category_count || 0;

        const parts = [];
        if (catCount > 0) parts.push(pluralize(catCount, 'category', 'categories'));
        if (gameCount > 0) parts.push(pluralize(gameCount, 'game'));
        if (kioskCount > 0) parts.push(pluralize(kioskCount, 'kiosk'));

        return parts.length === 0 ? 'No members' : parts.join(', ');
    }

    function pluralize(n, singular, plural) {
        const word = n === 1 ? singular : (plural || (singular + 's'));
        return n + ' ' + word;
    }

    async function renderGroupForm(container, params) {
        // Creating/editing groups is manager/admin only (the API enforces the
        // same policy). Techs keep the list view with pause/unpause controls.
        if (!App.canAccess('groups_manage')) {
            App.toast('Only managers and administrators can edit pause groups.', 'warning');
            window.location.hash = '#/groups';
            return;
        }

        const isEdit = params.id && params.id !== 'new';
        const groupId = isEdit ? params.id : null;

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: isEdit ? 'Edit Group' : 'New Group' }),
                App.el('p', { className: 'page-subtitle', textContent: isEdit ? 'Modify group configuration' : 'Create a new pause group' })
            ]),
            App.el('button', {
                className: 'btn btn-ghost', textContent: '\u2190 Back',
                onClick: () => { window.location.hash = '#/groups'; }
            })
        ]));

        const formWrap = App.el('div', { className: 'card' });
        formWrap.appendChild(App.loading());
        container.appendChild(formWrap);

        try {
            // Load data in parallel. Kiosks are best-effort: not every card
            // system supports the /kiosks endpoint, so a failure here just
            // means the kiosk picker stays empty.
            const promises = [
                API.get('games'),
                API.get('games/categories'),
                API.get('kiosks').catch(function() { return { kiosks: [] }; })
            ];
            if (isEdit) promises.push(API.get('groups/' + encodeURIComponent(groupId)));
            const results = await Promise.all(promises);

            const allGames = (results[0] || {}).games || [];
            const allCategories = (results[1] || {}).categories || [];
            const allKiosks = (results[2] || {}).kiosks || [];
            const existing = isEdit ? results[3] : null;

            formWrap.innerHTML = '';
            renderForm(formWrap, allGames, allCategories, allKiosks, existing, groupId);
        } catch (err) {
            formWrap.innerHTML = '';
            App.toast(err.message, 'error');
        }
    }

    function renderForm(container, allGames, allCategories, allKiosks, existing, groupId) {
        const selectedCategories = new Set((existing?.categories || []).map(c => c.category_id));
        const selectedGames = new Set((existing?.games || []).map(g => g.game_id));
        const selectedKiosks = new Set((existing?.kiosks || []).map(k => k.kiosk_id));

        // Name
        const nameInput = App.el('input', { className: 'form-input', type: 'text', value: existing?.name || '', placeholder: 'e.g., Redemption Games' });
        container.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Group Name' }),
            nameInput
        ]));

        // Description
        const descInput = App.el('textarea', { className: 'form-textarea', placeholder: 'Optional description...' });
        descInput.value = existing?.description || '';
        container.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Description' }),
            descInput
        ]));

        // Active toggle
        const activeCheck = App.el('input', { type: 'checkbox', className: 'toggle-input', id: 'group-active' });
        activeCheck.checked = existing ? !!existing.is_active : true;
        container.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'toggle-label', for: 'group-active' }, [
                activeCheck,
                App.el('span', { className: 'toggle-switch' }),
                App.el('span', { textContent: 'Active' })
            ])
        ]));

        // Categories
        container.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Categories' }),
            App.el('p', { className: 'form-help', textContent: 'All games in selected categories will be included in this group.' })
        ]));

        const catList = App.el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '0.5rem' } });
        allCategories.forEach(cat => {
            const cb = App.el('input', { type: 'checkbox', value: String(cat.id) });
            cb.checked = selectedCategories.has(cat.id);
            cb.addEventListener('change', () => {
                if (cb.checked) selectedCategories.add(cat.id);
                else selectedCategories.delete(cat.id);
            });
            catList.appendChild(App.el('label', { className: 'checkbox-label' }, [
                cb,
                App.el('span', { textContent: cat.name + ' (' + (cat.numberOfGames || 0) + ')' })
            ]));
        });
        if (allCategories.length === 0) {
            catList.appendChild(App.el('p', { className: 'text-muted text-sm', textContent: 'No categories available. Sync games first.' }));
        }
        container.appendChild(catList);

        // Individual Games — Enhanced Picker
        container.appendChild(App.el('div', { className: 'form-group mt-2' }, [
            App.el('label', { className: 'form-label', textContent: 'Individual Games' }),
            App.el('p', { className: 'form-help', textContent: 'Select specific games not covered by categories above. Use search and filters for large game lists.' })
        ]));

        // Build enhanced game picker
        const pickerContainer = App.el('div', { className: 'game-picker-container' });

        // Toolbar
        const pickerToolbar = App.el('div', { className: 'game-picker-toolbar' });

        const gameSearch = App.el('input', {
            className: 'form-input', type: 'text', placeholder: 'Search games...'
        });

        const showFilter = App.el('select', {
            className: 'form-select',
            style: { width: 'auto', minWidth: '130px', padding: '0.35rem 0.6rem', fontSize: '0.82rem' }
        });
        showFilter.appendChild(App.el('option', { value: 'all', textContent: 'All Games' }));
        showFilter.appendChild(App.el('option', { value: 'selected', textContent: 'Selected Only' }));
        showFilter.appendChild(App.el('option', { value: 'unselected', textContent: 'Unselected Only' }));

        const selectAllBtn = App.el('button', {
            className: 'btn btn-sm btn-secondary',
            textContent: 'Select Visible',
            onClick: () => {
                const visible = getVisibleGames(allGames, gameSearch.value.toLowerCase(), showFilter.value, selectedGames);
                visible.forEach(g => selectedGames.add(g.game_id));
                renderGamePicker();
            }
        });

        const deselectAllBtn = App.el('button', {
            className: 'btn btn-sm btn-ghost',
            textContent: 'Deselect Visible',
            onClick: () => {
                const visible = getVisibleGames(allGames, gameSearch.value.toLowerCase(), showFilter.value, selectedGames);
                visible.forEach(g => selectedGames.delete(g.game_id));
                renderGamePicker();
            }
        });

        const statsEl = App.el('div', { className: 'game-picker-stats', id: 'picker-stats' });

        pickerToolbar.appendChild(gameSearch);
        pickerToolbar.appendChild(showFilter);
        pickerToolbar.appendChild(selectAllBtn);
        pickerToolbar.appendChild(deselectAllBtn);
        pickerToolbar.appendChild(statsEl);
        pickerContainer.appendChild(pickerToolbar);

        // List
        const gameListEl = App.el('div', { className: 'game-picker-list', id: 'game-picker' });
        pickerContainer.appendChild(gameListEl);

        // Footer
        const footerEl = App.el('div', { className: 'game-picker-footer', id: 'picker-footer' });
        pickerContainer.appendChild(footerEl);

        container.appendChild(pickerContainer);

        // Picker page state
        let pickerPage = 1;
        const pickerPageSize = 50;

        function getVisibleGames(games, filter, showMode, selected) {
            let sorted = [...games].sort((a, b) => a.game_name.localeCompare(b.game_name));
            if (filter) {
                sorted = sorted.filter(g => g.game_name.toLowerCase().includes(filter));
            }
            if (showMode === 'selected') {
                sorted = sorted.filter(g => selected.has(g.game_id));
            } else if (showMode === 'unselected') {
                sorted = sorted.filter(g => !selected.has(g.game_id));
            }
            return sorted;
        }

        function renderGamePicker() {
            gameListEl.innerHTML = '';
            const filter = gameSearch.value.toLowerCase();
            const showMode = showFilter.value;

            const visible = getVisibleGames(allGames, filter, showMode, selectedGames);
            const totalVisible = visible.length;
            const totalPages = Math.max(1, Math.ceil(totalVisible / pickerPageSize));
            if (pickerPage > totalPages) pickerPage = totalPages;
            if (pickerPage < 1) pickerPage = 1;

            const startIdx = (pickerPage - 1) * pickerPageSize;
            const pageItems = visible.slice(startIdx, startIdx + pickerPageSize);

            pageItems.forEach(game => {
                const item = App.el('div', { className: 'game-picker-item' });
                const cb = App.el('input', { type: 'checkbox', value: game.game_id });
                cb.checked = selectedGames.has(game.game_id);
                cb.addEventListener('change', () => {
                    if (cb.checked) selectedGames.add(game.game_id);
                    else selectedGames.delete(game.game_id);
                    updatePickerStats();
                });
                item.appendChild(cb);
                item.appendChild(App.el('span', { className: 'game-name', textContent: game.game_name }));
                item.appendChild(App.el('span', { className: 'game-status', textContent: game.operation_status }));
                gameListEl.appendChild(item);
            });

            if (pageItems.length === 0) {
                gameListEl.appendChild(App.el('div', {
                    className: 'empty-state',
                    style: { padding: '1.5rem' }
                }, [
                    App.el('div', { className: 'empty-state-text', textContent: 'No games match the current filter.' })
                ]));
            }

            updatePickerStats();

            // Footer with pagination
            footerEl.innerHTML = '';
            if (totalVisible > 0) {
                var showing = App.el('span', {
                    textContent: 'Showing ' + (startIdx + 1) + '-' + Math.min(startIdx + pickerPageSize, totalVisible) + ' of ' + totalVisible
                });
                footerEl.appendChild(showing);
            }

            if (totalPages > 1) {
                var pageControls = App.el('div', { className: 'flex-center gap-sm' });
                pageControls.appendChild(App.el('button', {
                    className: 'btn btn-ghost btn-sm',
                    textContent: '\u2039 Prev',
                    disabled: pickerPage <= 1,
                    onClick: () => { pickerPage--; renderGamePicker(); }
                }));
                pageControls.appendChild(App.el('span', {
                    className: 'text-xs',
                    textContent: pickerPage + ' / ' + totalPages
                }));
                pageControls.appendChild(App.el('button', {
                    className: 'btn btn-ghost btn-sm',
                    textContent: 'Next \u203A',
                    disabled: pickerPage >= totalPages,
                    onClick: () => { pickerPage++; renderGamePicker(); }
                }));
                footerEl.appendChild(pageControls);
            }
        }

        function updatePickerStats() {
            const el = document.getElementById('picker-stats');
            if (el) {
                el.innerHTML = '';
                el.appendChild(App.el('strong', { textContent: String(selectedGames.size) }));
                el.appendChild(document.createTextNode(' of ' + allGames.length + ' selected'));
            }
        }

        gameSearch.addEventListener('input', () => { pickerPage = 1; renderGamePicker(); });
        showFilter.addEventListener('change', () => { pickerPage = 1; renderGamePicker(); });
        renderGamePicker();

        // Kiosks — simple checkbox list. There aren't typically many kiosks
        // (handful per venue), so the games picker's pagination is overkill.
        container.appendChild(App.el('div', { className: 'form-group mt-2' }, [
            App.el('label', { className: 'form-label', textContent: 'Kiosks' }),
            App.el('p', { className: 'form-help', textContent: 'Selected kiosks are paused/unpaused with the group, alongside its games.' })
        ]));

        const kioskList = App.el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '0.5rem' } });
        if (allKiosks.length === 0) {
            kioskList.appendChild(App.el('p', { className: 'text-muted text-sm', textContent: 'No kiosks available. (The card system may not support the /kiosks endpoint, or kiosks have not been synced yet.)' }));
        } else {
            allKiosks.forEach(function(k) {
                // Per the kiosk API spec, a kiosk reporting no operationStatus
                // must NOT be pause-controlled — disable the checkbox so it
                // can't be added to a pause group by mistake.
                const unknown = !k.operationStatus;
                const cb = App.el('input', { type: 'checkbox', value: String(k.id) });
                cb.checked = selectedKiosks.has(k.id);
                if (unknown) {
                    cb.disabled = true;
                    cb.title = 'Kiosk operation status unknown — cannot pause via API';
                }
                cb.addEventListener('change', function() {
                    if (cb.checked) selectedKiosks.add(k.id);
                    else selectedKiosks.delete(k.id);
                });
                const labelTxt = (k.name || k.id) + (unknown ? ' (status unknown)' : (' (' + k.operationStatus + ')'));
                kioskList.appendChild(App.el('label', { className: 'checkbox-label' }, [
                    cb,
                    App.el('span', { textContent: labelTxt })
                ]));
            });
        }
        container.appendChild(kioskList);

        // Actions
        const saveBtn = App.el('button', { className: 'btn btn-primary', textContent: groupId ? 'Save Changes' : 'Create Group' });
        const deleteBtn = groupId ? App.el('button', { className: 'btn btn-danger', textContent: 'Delete', onClick: async () => {
            const yes = await App.confirm('Delete this group and all its schedules and overrides?');
            if (!yes) return;
            try {
                await API.del('groups/' + encodeURIComponent(groupId));
                App.toast('Group deleted.', 'success');
                window.location.hash = '#/groups';
            } catch (err) { App.toast(err.message, 'error'); }
        }}) : null;

        const actions = App.el('div', { className: 'form-actions' }, [saveBtn, deleteBtn].filter(Boolean));
        container.appendChild(actions);

        saveBtn.addEventListener('click', async () => {
            const name = nameInput.value.trim();
            if (!name) { App.toast('Name is required.', 'error'); return; }

            const body = {
                name: name,
                description: descInput.value.trim(),
                is_active: activeCheck.checked ? 1 : 0,
                category_ids: Array.from(selectedCategories),
                game_ids: Array.from(selectedGames),
                kiosk_ids: Array.from(selectedKiosks)
            };

            saveBtn.disabled = true;
            try {
                if (groupId) {
                    await API.put('groups/' + encodeURIComponent(groupId), body);
                    App.toast('Group updated.', 'success');
                } else {
                    await API.post('groups', body);
                    App.toast('Group created.', 'success');
                }
                window.location.hash = '#/groups';
            } catch (err) {
                App.toast(err.message, 'error');
                saveBtn.disabled = false;
            }
        });
    }

    async function quickAction(groupId, action, groupName, listEl) {
        const verb = action === 'pause' ? 'Pause' : 'Unpause';
        const confirmed = await App.confirm(verb + ' "' + groupName + '"?');
        if (!confirmed) return;

        // Disable all quick-action buttons
        listEl.querySelectorAll('.btn-success, .btn-warning').forEach(b => { b.disabled = true; });

        try {
            const result = await API.post('groups/' + encodeURIComponent(groupId) + '/' + encodeURIComponent(action)) || {};
            const changed = result.changed || 0;
            const errors = result.errors || 0;

            if (errors > 0) {
                App.toast(verb + ' partially failed: ' + changed + ' changed, ' + errors + ' error(s).', 'warning');
            } else if (changed > 0) {
                App.toast(groupName + ': ' + pluralize(changed, 'item') + ' ' + action + 'd.', 'success');
            } else {
                App.toast(groupName + ': already ' + action + 'd.', 'info');
            }

            // Reload group list to reflect new state
            const data = await API.get('groups') || {};
            allGroups = data.groups || [];
            renderGroupCards();
        } catch (err) {
            App.toast(verb + ' failed: ' + err.message, 'error');
            listEl.querySelectorAll('.btn-success, .btn-warning').forEach(b => { b.disabled = false; });
        }
    }
})();
