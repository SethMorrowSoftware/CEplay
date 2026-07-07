/**
 * Settings page: CenterEdge API config, timezone, admin user management.
 *
 * Access is server-enforced via Auth::requireAccess('settings'). The only
 * roles that load this page are 'admin' and 'tech' — managers are denied
 * at the nav and route level. Within the page, role-related controls
 * (assigning/promoting roles, granting admin) are further restricted to
 * the 'admin' role to prevent self-promotion.
 */
(function() {
    App.registerRoute('#/settings', { render: renderSettings });

    // Static fallbacks only — the live catalog (names, descriptions, custom
    // roles, per-caller assignability) comes from GET /api/users and
    // GET /api/roles and is cached here on every load.
    var ROLE_DESCRIPTIONS = {
        admin:   'Full access — including settings and user management.',
        manager: 'Full access except this Settings page.',
        tech:    'Full access except sales data (Analytics, Card Lookup).'
    };

    var DEFAULT_ROLE_ORDER = ['admin', 'manager', 'tech'];

    /** Latest role catalog from the API: [{slug,name,description,is_system,assignable}] */
    var roleCatalog = [];

    function catalogEntry(slug) {
        return roleCatalog.filter(function(r) { return r.slug === slug; })[0] || null;
    }

    function roleDisplayName(slug) {
        var entry = catalogEntry(slug);
        return (entry && entry.name) || (App.ROLE_LABELS && App.ROLE_LABELS[slug]) || slug;
    }

    function roleDescription(slug) {
        var entry = catalogEntry(slug);
        if (entry && entry.description) return entry.description;
        return ROLE_DESCRIPTIONS[slug] || '';
    }

    async function renderSettings(container) {
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('h1', { className: 'page-title', textContent: 'Settings' }),
            App.el('p', { className: 'page-subtitle text-secondary',
                textContent: 'API connection, timezone, and user access controls.' })
        ]));

        const content = App.el('div', { id: 'settings-content' });
        content.appendChild(App.loading());
        container.appendChild(content);

        await loadSettings();
    }

    async function loadSettings() {
        const content = document.getElementById('settings-content');
        if (!content) return;

        try {
            const [settingsData, usersData, rolesData] = await Promise.all([
                API.get('settings'),
                API.get('users'),
                API.get('roles').catch(function() { return null; }) // non-fatal
            ]);

            content.innerHTML = '';

            var settings = settingsData || {};
            var users = usersData || {};
            roleCatalog = (users.role_catalog && users.role_catalog.length)
                ? users.role_catalog
                : ((rolesData && rolesData.roles) || []);

            content.appendChild(buildApiConfigSection(settings));
            content.appendChild(buildTimezoneSection(settings));
            content.appendChild(buildUsersSection(users.users || [], users));
            if (rolesData && rolesData.roles) {
                content.appendChild(buildRolesSection(rolesData));
            }

        } catch (err) {
            content.innerHTML = '';
            App.toast(err.message, 'error');
        }
    }

    function buildApiConfigSection(data) {
        const section = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        section.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'CenterEdge API Configuration' })
        ]));

        const body = App.el('div', { className: 'card-body' });

        const baseUrlInput = App.el('input', {
            className: 'form-input', type: 'url',
            value: data.base_url || '',
            placeholder: 'https://your-site.centeredge.io/api/v1'
        });
        body.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'API Base URL' }),
            baseUrlInput,
            App.el('span', { className: 'text-muted text-sm', textContent: 'e.g., https://yoursite.centeredge.io/api/v1' })
        ]));

        const usernameInput = App.el('input', {
            className: 'form-input', type: 'text',
            value: data.username || '',
            placeholder: 'API username',
            autocomplete: 'off'
        });
        body.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Username' }),
            usernameInput
        ]));

        const passwordInput = App.el('input', {
            className: 'form-input', type: 'password',
            value: data.password || '',
            placeholder: 'API password'
        });
        body.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Password' }),
            passwordInput
        ]));

        const apiKeyInput = App.el('input', {
            className: 'form-input', type: 'text',
            value: data.api_key || '',
            placeholder: 'Optional API key'
        });
        body.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'API Key (optional)' }),
            apiKeyInput
        ]));

        const btnRow = App.el('div', { className: 'flex gap-sm' });

        // Test Connection button
        const testBtn = App.el('button', {
            className: 'btn btn-secondary', textContent: 'Test Connection',
            onClick: async () => {
                testBtn.disabled = true;
                testBtn.textContent = 'Testing...';
                testResult.textContent = '';
                testResult.className = '';
                try {
                    // Save first, then test
                    await saveApiConfig(baseUrlInput, usernameInput, passwordInput, apiKeyInput);
                    const result = await API.post('settings/test', {}) || {};
                    if (result.success) {
                        testResult.textContent = '✓ Connected to ' + (result.system_name || 'CenterEdge') +
                            '. Found ' + (result.game_count || 0) + ' games, ' + (result.category_count || 0) + ' categories.' +
                            (result.supports_operation_status ? '' : ' WARNING: operationStatus not supported.');
                        testResult.className = 'text-sm';
                        testResult.style.color = 'var(--success)';
                    } else {
                        testResult.textContent = '✗ ' + (result.error || 'Connection failed.');
                        testResult.className = 'text-sm';
                        testResult.style.color = 'var(--danger)';
                    }
                } catch (err) {
                    testResult.textContent = '✗ ' + err.message;
                    testResult.className = 'text-sm';
                    testResult.style.color = 'var(--danger)';
                } finally {
                    testBtn.disabled = false;
                    testBtn.textContent = 'Test Connection';
                }
            }
        });
        btnRow.appendChild(testBtn);

        // Save button
        btnRow.appendChild(App.el('button', {
            className: 'btn btn-primary', textContent: 'Save Configuration',
            onClick: async () => {
                try {
                    await saveApiConfig(baseUrlInput, usernameInput, passwordInput, apiKeyInput);
                    App.toast('API configuration saved.', 'success');
                } catch (err) {
                    App.toast(err.message, 'error');
                }
            }
        }));

        body.appendChild(btnRow);

        const testResult = App.el('div', { style: { marginTop: '0.75rem' } });
        body.appendChild(testResult);

        section.appendChild(body);
        return section;
    }

    async function saveApiConfig(baseUrlInput, usernameInput, passwordInput, apiKeyInput) {
        const baseUrl = baseUrlInput.value.trim();
        const username = usernameInput.value.trim();

        if (!baseUrl) { throw new Error('API Base URL is required.'); }
        if (!username) { throw new Error('Username is required.'); }

        await API.put('settings', {
            base_url: baseUrl,
            username: username,
            password: passwordInput.value,
            api_key: apiKeyInput.value.trim()
        });
    }

    function buildTimezoneSection(data) {
        const section = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        section.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'Timezone' })
        ]));

        const body = App.el('div', { className: 'card-body' });

        const tzSelect = App.el('select', { className: 'form-select' });
        const timezones = [
            'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
            'America/Phoenix', 'America/Anchorage', 'Pacific/Honolulu',
            'America/Indiana/Indianapolis', 'America/Detroit', 'America/Kentucky/Louisville',
            'America/Toronto', 'America/Vancouver', 'America/Edmonton', 'America/Winnipeg',
            'America/Halifax', 'America/St_Johns',
            'Europe/London', 'Europe/Berlin', 'Europe/Paris', 'Europe/Rome',
            'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Kolkata',
            'Australia/Sydney', 'Australia/Melbourne', 'Pacific/Auckland',
            'UTC'
        ];

        const currentTz = data.timezone || 'America/New_York';
        // Ensure current timezone is in the list
        if (timezones.indexOf(currentTz) === -1) {
            timezones.unshift(currentTz);
        }

        timezones.forEach(tz => {
            const opt = App.el('option', { value: tz, textContent: tz });
            if (tz === currentTz) opt.selected = true;
            tzSelect.appendChild(opt);
        });

        body.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Application Timezone' }),
            tzSelect,
            App.el('span', { className: 'text-muted text-sm', textContent: 'All schedules and logs use this timezone.' })
        ]));

        body.appendChild(App.el('button', {
            className: 'btn btn-primary', textContent: 'Save Timezone',
            onClick: async () => {
                try {
                    await API.put('settings', { timezone: tzSelect.value });
                    App.setTimezone(tzSelect.value);
                    App.toast('Timezone updated to ' + tzSelect.value + '.', 'success');
                } catch (err) { App.toast(err.message, 'error'); }
            }
        }));

        section.appendChild(body);
        return section;
    }

    /**
     * Render a role chip (small inline badge) using the same colour family
     * as the sidebar role pill so the surface stays visually consistent.
     */
    function roleBadge(role) {
        var label = roleDisplayName(role) || 'Unknown';
        return App.el('span', {
            className: 'role-pill role-pill-' + role,
            textContent: label,
            title: roleDescription(role)
        });
    }

    /**
     * Build a <select> from the dynamic role catalog. Options the caller may
     * not assign (per the server's subset rule — you can't hand out more
     * permissions than you hold) are disabled with an explanation.
     */
    function buildRoleSelect(currentValue, roles, callerRole) {
        var select = App.el('select', { className: 'form-select' });
        roles.forEach(function(role) {
            var entry = catalogEntry(role);
            var opt = App.el('option', {
                value: role,
                textContent: roleDisplayName(role)
            });
            if (role === currentValue) opt.selected = true;
            var assignable = entry ? entry.assignable !== false
                                   : !(role === 'admin' && callerRole !== 'admin');
            // The target's CURRENT role stays selectable so saving without a
            // role change never trips the disabled option.
            if (!assignable && role !== currentValue) {
                opt.disabled = true;
                opt.textContent += role === 'admin' ? ' (admin only)' : ' (beyond your permissions)';
            }
            select.appendChild(opt);
        });
        return select;
    }

    function buildUsersSection(users, meta) {
        var roles = (meta && meta.roles && meta.roles.length) ? meta.roles : DEFAULT_ROLE_ORDER;
        var callerRole = (meta && meta.current_role) || App.currentRole();
        var canCreate = callerRole === 'admin' || callerRole === 'tech';

        const section = App.el('div', { className: 'card' });
        const headerChildren = [
            App.el('h3', { className: 'card-title', textContent: 'Admin Users' })
        ];
        if (canCreate) {
            headerChildren.push(App.el('button', {
                className: 'btn btn-primary btn-sm',
                textContent: '+ Add User',
                onClick: function() { showCreateUserForm(roles, callerRole); }
            }));
        }
        section.appendChild(App.el('div', { className: 'card-header flex-between' }, headerChildren));

        const body = App.el('div', { className: 'card-body', id: 'users-list' });

        // Inline legend explaining what each role grants — driven by the
        // live catalog so custom roles show up too.
        const legend = App.el('div', { className: 'role-legend' });
        (roleCatalog.length ? roleCatalog.map(function(r) { return r.slug; }) : DEFAULT_ROLE_ORDER)
            .forEach(function(role) {
                legend.appendChild(App.el('div', { className: 'role-legend-row' }, [
                    roleBadge(role),
                    App.el('span', { className: 'text-sm text-secondary',
                        textContent: roleDescription(role) })
                ]));
            });
        body.appendChild(legend);

        if (users.length === 0) {
            body.appendChild(App.el('p', { className: 'text-muted', textContent: 'No users found.' }));
            section.appendChild(body);
            return section;
        }

        const table = App.el('table', { className: 'data-table' });
        const thead = App.el('thead');
        thead.appendChild(App.el('tr', {}, [
            App.el('th', { textContent: 'Username' }),
            App.el('th', { textContent: 'Display Name' }),
            App.el('th', { textContent: 'Role' }),
            App.el('th', { textContent: 'Status' }),
            App.el('th', { textContent: 'Created' }),
            App.el('th', { textContent: 'Actions', className: 'text-right' })
        ]));
        table.appendChild(thead);

        const tbody = App.el('tbody');
        users.forEach(function(u) {
            var currentUser = window.APP_CONFIG.user;
            var isSelf = currentUser && currentUser.id === u.id;

            var actions = [
                App.el('button', {
                    className: 'btn btn-ghost btn-sm', textContent: 'Edit',
                    onClick: function() { showEditUserForm(u, roles, callerRole); }
                })
            ];
            if (!isSelf) {
                actions.push(App.el('button', {
                    className: 'btn btn-ghost btn-sm ' + (u.is_active ? 'text-danger' : ''),
                    textContent: u.is_active ? 'Deactivate' : 'Activate',
                    onClick: function() { toggleUserActive(u); }
                }));
            }
            // Hard delete is admin-only (server enforces too). Deactivate
            // remains the everyday offboarding path; delete is for cleanup.
            if (!isSelf && callerRole === 'admin') {
                actions.push(App.el('button', {
                    className: 'btn btn-ghost btn-sm text-danger',
                    textContent: 'Delete',
                    onClick: function() { deleteUserAccount(u); }
                }));
            }

            tbody.appendChild(App.el('tr', {}, [
                App.el('td', { textContent: u.username }),
                App.el('td', { textContent: u.display_name || '—' }),
                App.el('td', {}, [roleBadge(u.role || 'admin')]),
                App.el('td', {}, [
                    App.el('span', {
                        className: 'badge ' + (u.is_active ? 'badge-active' : 'badge-inactive'),
                        textContent: u.is_active ? 'Active' : 'Inactive'
                    })
                ]),
                App.el('td', {
                    textContent: App.formatDate(u.created_at),
                    style: { fontSize: '0.8rem' }
                }),
                App.el('td', { className: 'text-right' }, [
                    App.el('div', { className: 'flex gap-sm', style: { justifyContent: 'flex-end' } }, actions)
                ])
            ]));
        });
        table.appendChild(tbody);

        const wrapper = App.el('div', { className: 'table-responsive' });
        wrapper.appendChild(table);
        body.appendChild(wrapper);

        section.appendChild(body);
        return section;
    }

    function showCreateUserForm(roles, callerRole) {
        const form = App.el('div');

        const usernameInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'Username', autocomplete: 'off' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Username' }),
            usernameInput
        ]));

        const displayInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'Display Name' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Display Name' }),
            displayInput
        ]));

        var defaultRole = 'tech'; // least privilege by default, regardless of caller
        const roleSelect = buildRoleSelect(defaultRole, roles, callerRole);
        const roleHelp = App.el('span', { className: 'text-muted text-sm',
            textContent: roleDescription(defaultRole) });
        roleSelect.addEventListener('change', function() {
            roleHelp.textContent = roleDescription(roleSelect.value);
        });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Role' }),
            roleSelect,
            roleHelp
        ]));

        const passwordInput = App.el('input', { className: 'form-input', type: 'password', placeholder: 'Password (min 8 chars)' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Password' }),
            passwordInput
        ]));

        const confirmInput = App.el('input', { className: 'form-input', type: 'password', placeholder: 'Confirm password' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Confirm Password' }),
            confirmInput
        ]));

        const submitBtn = App.el('button', {
            className: 'btn btn-primary',
            textContent: 'Create User',
            onClick: async function() {
                const username = usernameInput.value.trim();
                const password = passwordInput.value;

                if (!username) { App.toast('Username is required.', 'error'); return; }
                if (!password) { App.toast('Password is required.', 'error'); return; }
                if (password !== confirmInput.value) { App.toast('Passwords do not match.', 'error'); return; }
                if (password.length < 8) { App.toast('Password must be at least 8 characters.', 'error'); return; }

                submitBtn.disabled = true;
                try {
                    await API.post('users', {
                        username: username,
                        display_name: displayInput.value.trim(),
                        role: roleSelect.value,
                        password: password
                    });
                    App.hideModal();
                    App.toast('User created.', 'success');
                    await loadSettings();
                } catch (err) {
                    App.toast(err.message, 'error');
                    submitBtn.disabled = false;
                }
            }
        });

        const footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
            submitBtn
        ]);

        App.showModal('New User', form, footer);
    }

    function showEditUserForm(user, roles, callerRole) {
        const form = App.el('div');
        var currentUser = window.APP_CONFIG.user;
        var isSelf = currentUser && currentUser.id === user.id;
        var canChangeRole = callerRole === 'admin';

        // Show the username/role context up top so an operator editing a
        // long list of users always knows which account they're touching.
        form.appendChild(App.el('div', { className: 'edit-user-context' }, [
            App.el('div', { className: 'text-sm text-secondary',
                textContent: 'Editing ' + user.username }),
            roleBadge(user.role || 'admin')
        ]));

        const displayInput = App.el('input', { className: 'form-input', type: 'text', value: user.display_name || '' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Display Name' }),
            displayInput
        ]));

        const roleSelect = buildRoleSelect(user.role || 'admin', roles, callerRole);
        if (!canChangeRole) {
            roleSelect.disabled = true;
        }
        const roleHelp = App.el('span', { className: 'text-muted text-sm',
            textContent: roleDescription(user.role || 'admin') });
        roleSelect.addEventListener('change', function() {
            roleHelp.textContent = roleDescription(roleSelect.value);
        });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Role' }),
            roleSelect,
            roleHelp,
            !canChangeRole ? App.el('span', { className: 'text-muted text-xs',
                textContent: 'Only an administrator can change a user\'s role.' }) : null
        ].filter(Boolean)));

        const passwordInput = App.el('input', { className: 'form-input', type: 'password', placeholder: 'Leave blank to keep current' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'New Password' }),
            passwordInput
        ]));

        const confirmInput = App.el('input', { className: 'form-input', type: 'password', placeholder: 'Confirm new password' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Confirm Password' }),
            confirmInput
        ]));

        const submitBtn = App.el('button', {
            className: 'btn btn-primary',
            textContent: 'Save Changes',
            onClick: async function() {
                const payload = {
                    display_name: displayInput.value.trim(),
                    role: roleSelect.value
                };
                const password = passwordInput.value;

                if (password) {
                    if (password !== confirmInput.value) { App.toast('Passwords do not match.', 'error'); return; }
                    if (password.length < 8) { App.toast('Password must be at least 8 characters.', 'error'); return; }
                    payload.password = password;
                }

                submitBtn.disabled = true;
                try {
                    await API.put('users/' + encodeURIComponent(user.id), payload);
                    App.hideModal();
                    App.toast('User updated.', 'success');
                    // If the edited user is the signed-in user and the role
                    // changed, refresh APP_CONFIG so the nav reflects the
                    // new permissions on the next route.
                    if (isSelf && payload.role) {
                        window.APP_CONFIG.user.role = payload.role;
                        App.currentUser = window.APP_CONFIG.user;
                    }
                    await loadSettings();
                } catch (err) {
                    App.toast(err.message, 'error');
                    submitBtn.disabled = false;
                }
            }
        });

        const footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
            submitBtn
        ]);

        App.showModal('Edit User: ' + user.username, form, footer);
    }

    async function toggleUserActive(user) {
        const action = user.is_active ? 'deactivate' : 'activate';
        const yes = await App.confirm(action.charAt(0).toUpperCase() + action.slice(1) + ' user "' + user.username + '"?');
        if (!yes) return;

        try {
            await API.put('users/' + encodeURIComponent(user.id), { is_active: !user.is_active });
            App.toast('User ' + action + 'd.', 'success');
            await loadSettings();
        } catch (err) { App.toast(err.message, 'error'); }
    }

    async function deleteUserAccount(user) {
        const yes = await App.confirm(
            'Permanently delete user "' + user.username + '"? '
            + 'Their override history is kept, but the account cannot be recovered. '
            + 'Use Deactivate instead if they might return.'
        );
        if (!yes) return;

        try {
            await API.del('users/' + encodeURIComponent(user.id));
            App.toast('User "' + user.username + '" deleted.', 'success');
            await loadSettings();
        } catch (err) { App.toast(err.message, 'error'); }
    }

    // ------------------------------------------------------------------
    // Roles section — list, create, edit, delete custom roles.
    // Mutations are admin-only (the API enforces it; the UI hides what the
    // caller can't do via the per-role editable/deletable flags).
    // ------------------------------------------------------------------
    function buildRolesSection(rolesData) {
        var roles = rolesData.roles || [];
        var permCatalog = rolesData.permissions || {};
        var isAdmin = App.currentRole() === 'admin';

        const section = App.el('div', { className: 'card', style: { marginTop: '1.5rem' } });
        const headerChildren = [
            App.el('h3', { className: 'card-title', textContent: 'Roles & Permissions' })
        ];
        if (isAdmin) {
            headerChildren.push(App.el('button', {
                className: 'btn btn-primary btn-sm',
                textContent: '+ Add Role',
                onClick: function() { showRoleForm(null, permCatalog); }
            }));
        }
        section.appendChild(App.el('div', { className: 'card-header flex-between' }, headerChildren));

        const body = App.el('div', { className: 'card-body' });
        body.appendChild(App.el('p', { className: 'text-sm text-secondary', style: { marginBottom: '0.75rem' },
            textContent: 'Roles define what each user can see and do. The three built-in roles cover most venues; '
                + 'add a custom role for special cases (e.g. a view-only account for the front desk). '
                + 'The Administrator role always has every permission and cannot be changed.' }));

        const table = App.el('table', { className: 'data-table' });
        table.appendChild(App.el('thead', {}, [
            App.el('tr', {}, [
                App.el('th', { textContent: 'Role' }),
                App.el('th', { textContent: 'Description' }),
                App.el('th', { textContent: 'Permissions' }),
                App.el('th', { textContent: 'Users', className: 'text-right' }),
                App.el('th', { textContent: 'Actions', className: 'text-right' })
            ])
        ]));

        const tbody = App.el('tbody');
        roles.forEach(function(role) {
            var permCount = (role.permissions || []).length;
            var totalPerms = Object.keys(permCatalog).length;
            var permSummary = role.slug === 'admin'
                ? 'All permissions'
                : permCount + ' of ' + totalPerms;
            var permTitle = (role.permissions || [])
                .map(function(p) { return permCatalog[p] || p; }).join('\n');

            var actions = [];
            if (role.editable) {
                actions.push(App.el('button', {
                    className: 'btn btn-ghost btn-sm', textContent: 'Edit',
                    onClick: function() { showRoleForm(role, permCatalog); }
                }));
            }
            if (role.deletable) {
                actions.push(App.el('button', {
                    className: 'btn btn-ghost btn-sm text-danger', textContent: 'Delete',
                    onClick: function() { deleteRole(role); }
                }));
            }
            if (actions.length === 0) {
                actions.push(App.el('span', { className: 'text-muted text-sm',
                    textContent: role.slug === 'admin' ? 'Locked'
                        : (role.is_system && !role.editable ? 'View only'
                            : (role.user_count > 0 && !role.editable ? 'View only' : '—')) }));
            }

            tbody.appendChild(App.el('tr', {}, [
                App.el('td', {}, [
                    App.el('div', { className: 'flex-center gap-sm' }, [
                        roleBadge(role.slug),
                        role.is_system ? App.el('span', { className: 'badge badge-info', textContent: 'System',
                            title: 'Built-in role. Its permissions can be tuned (except admin), but it cannot be deleted.' }) : null
                    ].filter(Boolean))
                ]),
                App.el('td', { className: 'text-sm text-secondary', textContent: role.description || '—' }),
                App.el('td', { className: 'text-sm', textContent: permSummary, title: permTitle }),
                App.el('td', { className: 'text-right', textContent: String(role.user_count || 0) }),
                App.el('td', { className: 'text-right' }, [
                    App.el('div', { className: 'flex gap-sm', style: { justifyContent: 'flex-end' } }, actions)
                ])
            ]));
        });
        table.appendChild(tbody);

        const wrapper = App.el('div', { className: 'table-responsive' });
        wrapper.appendChild(table);
        body.appendChild(wrapper);
        section.appendChild(body);
        return section;
    }

    /** Create (role=null) or edit a role via a modal with permission checkboxes. */
    function showRoleForm(role, permCatalog) {
        var isEdit = !!role;
        const form = App.el('div');

        const nameInput = App.el('input', {
            className: 'form-input', type: 'text',
            value: isEdit ? (role.name || '') : '',
            placeholder: 'e.g. Front Desk'
        });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Role name' }),
            nameInput,
            isEdit ? App.el('span', { className: 'text-muted text-xs',
                textContent: 'Identifier: ' + role.slug + (role.is_system ? ' (built-in)' : '') }) : null
        ].filter(Boolean)));

        const descInput = App.el('input', {
            className: 'form-input', type: 'text',
            value: isEdit ? (role.description || '') : '',
            placeholder: 'Short description shown in the role legend'
        });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Description' }),
            descInput
        ]));

        // Permission checkboxes from the live catalog.
        const checks = {};
        const permWrap = App.el('div', { className: 'perm-checklist' });
        Object.keys(permCatalog).forEach(function(key) {
            var cb = App.el('input', { type: 'checkbox', value: key });
            cb.checked = isEdit && (role.permissions || []).indexOf(key) !== -1;
            checks[key] = cb;
            permWrap.appendChild(App.el('label', { className: 'checkbox-label perm-checklist-row' }, [
                cb,
                App.el('span', {}, [
                    App.el('span', { className: 'perm-key', textContent: key }),
                    App.el('span', { className: 'text-sm text-secondary', textContent: ' — ' + permCatalog[key] })
                ])
            ]));
        });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Permissions' }),
            permWrap,
            App.el('span', { className: 'text-muted text-xs',
                textContent: 'Anything not listed here (dashboard, game/kiosk status views, action log) is visible to every signed-in user.' })
        ]));

        const saveBtn = App.el('button', {
            className: 'btn btn-primary',
            textContent: isEdit ? 'Save Role' : 'Create Role',
            onClick: async function() {
                var name = nameInput.value.trim();
                if (!name) { App.toast('Role name is required.', 'error'); return; }
                var permissions = Object.keys(checks).filter(function(k) { return checks[k].checked; });

                saveBtn.disabled = true; // guard against double-submit
                try {
                    if (isEdit) {
                        await API.put('roles/' + encodeURIComponent(role.slug), {
                            name: name, description: descInput.value.trim(), permissions: permissions
                        });
                    } else {
                        await API.post('roles', {
                            name: name, description: descInput.value.trim(), permissions: permissions
                        });
                    }
                    App.hideModal();
                    App.toast(isEdit ? 'Role updated. Changes apply to signed-in users within a minute.' : 'Role created.', 'success');
                    await loadSettings();
                } catch (err) {
                    App.toast(err.message, 'error');
                    saveBtn.disabled = false;
                }
            }
        });

        const footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
            saveBtn
        ]);

        App.showModal(isEdit ? 'Edit Role: ' + (role.name || role.slug) : 'New Role', form, footer);
    }

    async function deleteRole(role) {
        const yes = await App.confirm(
            'Delete the role "' + (role.name || role.slug) + '"? '
            + 'Roles can only be deleted when no users hold them.'
        );
        if (!yes) return;

        try {
            await API.del('roles/' + encodeURIComponent(role.slug));
            App.toast('Role "' + (role.name || role.slug) + '" deleted.', 'success');
            await loadSettings();
        } catch (err) { App.toast(err.message, 'error'); }
    }
})();
