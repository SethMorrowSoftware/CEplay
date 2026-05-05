/**
 * Settings page: CenterEdge API config, timezone, admin user management.
 */
(function() {
    App.registerRoute('#/settings', { render: renderSettings });

    async function renderSettings(container) {
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('h1', { className: 'page-title', textContent: 'Settings' })
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
            const [settingsData, usersData] = await Promise.all([
                API.get('settings'),
                API.get('users')
            ]);

            content.innerHTML = '';

            var settings = settingsData || {};
            var users = usersData || {};

            // API Configuration section
            content.appendChild(buildApiConfigSection(settings));

            // Timezone section
            content.appendChild(buildTimezoneSection(settings));

            // Admin Users section
            content.appendChild(buildUsersSection(users.users || []));

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
                        testResult.textContent = '\u2713 Connected to ' + (result.system_name || 'CenterEdge') +
                            '. Found ' + (result.game_count || 0) + ' games, ' + (result.category_count || 0) + ' categories.' +
                            (result.supports_operation_status ? '' : ' WARNING: operationStatus not supported.');
                        testResult.className = 'text-sm';
                        testResult.style.color = 'var(--success)';
                    } else {
                        testResult.textContent = '\u2717 ' + (result.error || 'Connection failed.');
                        testResult.className = 'text-sm';
                        testResult.style.color = 'var(--danger)';
                    }
                } catch (err) {
                    testResult.textContent = '\u2717 ' + err.message;
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

    function buildUsersSection(users) {
        const section = App.el('div', { className: 'card' });
        section.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'Admin Users' }),
            App.el('button', {
                className: 'btn btn-primary btn-sm', textContent: '+ Add User',
                onClick: showCreateUserForm
            })
        ]));

        const body = App.el('div', { className: 'card-body', id: 'users-list' });

        if (users.length === 0) {
            body.appendChild(App.el('p', { className: 'text-muted', textContent: 'No users found.' }));
        } else {
            const table = App.el('table', { className: 'table' });
            const thead = App.el('thead');
            thead.appendChild(App.el('tr', {}, [
                App.el('th', { textContent: 'Username' }),
                App.el('th', { textContent: 'Display Name' }),
                App.el('th', { textContent: 'Status' }),
                App.el('th', { textContent: 'Created' }),
                App.el('th', { textContent: 'Actions' })
            ]));
            table.appendChild(thead);

            const tbody = App.el('tbody');
            users.forEach(u => {
                const currentUser = window.APP_CONFIG.user;
                const isSelf = currentUser && currentUser.id === u.id;

                tbody.appendChild(App.el('tr', {}, [
                    App.el('td', { textContent: u.username }),
                    App.el('td', { textContent: u.display_name || '\u2014' }),
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
                    App.el('td', { className: 'flex gap-sm' }, [
                        App.el('button', {
                            className: 'btn btn-ghost btn-sm', textContent: 'Edit',
                            onClick: () => showEditUserForm(u)
                        }),
                        !isSelf ? App.el('button', {
                            className: 'btn btn-ghost btn-sm text-danger',
                            textContent: u.is_active ? 'Deactivate' : 'Activate',
                            onClick: () => toggleUserActive(u)
                        }) : null
                    ].filter(Boolean))
                ]));
            });
            table.appendChild(tbody);

            const wrapper = App.el('div', { className: 'table-responsive' });
            wrapper.appendChild(table);
            body.appendChild(wrapper);
        }

        section.appendChild(body);
        return section;
    }

    function showCreateUserForm() {
        const form = App.el('div');

        const usernameInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'Username' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Username' }),
            usernameInput
        ]));

        const displayInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'Display Name' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Display Name' }),
            displayInput
        ]));

        const passwordInput = App.el('input', { className: 'form-input', type: 'password', placeholder: 'Password' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Password' }),
            passwordInput
        ]));

        const confirmInput = App.el('input', { className: 'form-input', type: 'password', placeholder: 'Confirm password' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Confirm Password' }),
            confirmInput
        ]));

        const footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: () => App.hideModal() }),
            App.el('button', { className: 'btn btn-primary', textContent: 'Create User', onClick: async () => {
                const username = usernameInput.value.trim();
                const password = passwordInput.value;

                if (!username) { App.toast('Username is required.', 'error'); return; }
                if (!password) { App.toast('Password is required.', 'error'); return; }
                if (password !== confirmInput.value) { App.toast('Passwords do not match.', 'error'); return; }
                if (password.length < 8) { App.toast('Password must be at least 8 characters.', 'error'); return; }

                try {
                    await API.post('users', {
                        username: username,
                        display_name: displayInput.value.trim(),
                        password: password
                    });
                    App.hideModal();
                    App.toast('User created.', 'success');
                    await loadSettings();
                } catch (err) { App.toast(err.message, 'error'); }
            }})
        ]);

        App.showModal('New Admin User', form, footer);
    }

    function showEditUserForm(user) {
        const form = App.el('div');

        const displayInput = App.el('input', { className: 'form-input', type: 'text', value: user.display_name || '' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Display Name' }),
            displayInput
        ]));

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

        const footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: () => App.hideModal() }),
            App.el('button', { className: 'btn btn-primary', textContent: 'Save Changes', onClick: async () => {
                const payload = { display_name: displayInput.value.trim() };
                const password = passwordInput.value;

                if (password) {
                    if (password !== confirmInput.value) { App.toast('Passwords do not match.', 'error'); return; }
                    if (password.length < 8) { App.toast('Password must be at least 8 characters.', 'error'); return; }
                    payload.password = password;
                }

                try {
                    await API.put('users/' + encodeURIComponent(user.id), payload);
                    App.hideModal();
                    App.toast('User updated.', 'success');
                    await loadSettings();
                } catch (err) { App.toast(err.message, 'error'); }
            }})
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
})();
