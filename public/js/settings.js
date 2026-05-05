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

            // CenterEdge API polling
            content.appendChild(buildPollingSection(settings));

            // Safety nets
            content.appendChild(buildSafetyNetsSection(settings));

            // Browser polling intervals
            content.appendChild(buildUiPollingSection(settings));

            // Data retention
            content.appendChild(buildRetentionSection(settings));

            // Scheduler behaviour
            content.appendChild(buildSchedulerSection(settings));

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
                    // Send the live form values to the test endpoint so we
                    // verify what the operator just typed without persisting
                    // a (potentially broken) config to the database.
                    const result = await API.post('settings/test', {
                        base_url: baseUrlInput.value.trim(),
                        username: usernameInput.value.trim(),
                        password: passwordInput.value,
                        api_key: apiKeyInput.value.trim()
                    }) || {};
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

    // -----------------------------------------------------------------------
    // Shared helpers for the settings sections below
    // -----------------------------------------------------------------------

    function makeSelect(options, currentValue) {
        const sel = App.el('select', { className: 'form-select' });
        options.forEach(function(o) {
            const opt = App.el('option', { value: String(o.value), textContent: o.label });
            if (o.value === currentValue) opt.selected = true;
            sel.appendChild(opt);
        });
        return sel;
    }

    function makeNumberInput(currentValue, min, max, step) {
        return App.el('input', {
            className: 'form-input',
            type: 'number',
            value: String(currentValue),
            min: String(min),
            max: String(max),
            step: String(step || 1),
            style: { maxWidth: '120px' }
        });
    }

    function formRow(label, control, hint) {
        const children = [
            App.el('label', { className: 'form-label', textContent: label }),
            control,
        ];
        if (hint) children.push(App.el('span', { className: 'text-muted text-sm', textContent: hint }));
        return App.el('div', { className: 'form-group' }, children);
    }

    function saveBtn(label, getPayload) {
        return App.el('button', {
            className: 'btn btn-primary',
            textContent: label,
            onClick: async function() {
                try {
                    await API.put('settings', getPayload());
                    App.toast('Settings saved.', 'success');
                } catch (err) {
                    App.toast(err.message, 'error');
                }
            }
        });
    }

    // -----------------------------------------------------------------------

    function buildPollingSection(data) {
        const section = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        section.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'CenterEdge API Polling' })
        ]));
        const body = App.el('div', { className: 'card-body' });

        // Transaction feed poll interval
        const txOpts = [
            { value: 60,  label: '1 minute — most responsive'  },
            { value: 120, label: '2 minutes'                   },
            { value: 300, label: '5 minutes — recommended'     },
            { value: 600, label: '10 minutes'                  },
            { value: 900, label: '15 minutes — fewest API calls' },
        ];
        const txSel = makeSelect(txOpts, data.tx_poll_interval_seconds || 60);
        body.appendChild(formRow('Transaction Feed Poll Interval', txSel,
            'How often the watchdog cron fetches new play data from CenterEdge. ' +
            'The browser always reads from the local cache — only this background feed contacts CenterEdge.'));

        body.appendChild(saveBtn('Save API Polling', function() {
            return { tx_poll_interval_seconds: parseInt(txSel.value, 10) };
        }));

        section.appendChild(body);
        return section;
    }

    function buildSafetyNetsSection(data) {
        const section = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        section.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'Safety Nets & State Sync' })
        ]));
        const body = App.el('div', { className: 'card-body' });

        const t2Opts = [
            { value: 15,   label: '15 seconds — most aggressive' },
            { value: 30,   label: '30 seconds' },
            { value: 60,   label: '1 minute — recommended' },
            { value: 120,  label: '2 minutes' },
            { value: 300,  label: '5 minutes' },
        ];
        const t2Sel = makeSelect(t2Opts, data.tier2_throttle_seconds || 60);
        body.appendChild(formRow('Per-Request Safety Net (Tier 2) Throttle', t2Sel,
            'How often the per-browser-request enforcement check fires. ' +
            'Lower = more aggressive catch-up but slightly more DB activity. ' +
            'The watchdog cron is the primary enforcer.'));

        const staleOpts = [
            { value: 30,   label: '30 seconds' },
            { value: 60,   label: '1 minute' },
            { value: 120,  label: '2 minutes' },
            { value: 300,  label: '5 minutes — recommended' },
            { value: 600,  label: '10 minutes' },
            { value: 1800, label: '30 minutes' },
        ];
        const staleSel = makeSelect(staleOpts, data.state_sync_stale_seconds || 300);
        body.appendChild(formRow('Game/Kiosk State Sync Staleness', staleSel,
            'How old the game/kiosk cache is allowed to be before the watchdog re-reads it from CenterEdge ' +
            'during enforcement. Pause/unpause actions always use a tight 30-second freshness window.'));

        body.appendChild(saveBtn('Save Safety Net Settings', function() {
            return {
                tier2_throttle_seconds:   parseInt(t2Sel.value, 10),
                state_sync_stale_seconds: parseInt(staleSel.value, 10),
            };
        }));

        section.appendChild(body);
        return section;
    }

    function buildUiPollingSection(data) {
        const section = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        section.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'Browser Polling Intervals' })
        ]));
        const body = App.el('div', { className: 'card-body' });

        body.appendChild(App.el('p', {
            className: 'text-muted text-sm',
            style: { marginBottom: '1rem' },
            textContent: 'These control how often the browser refreshes data from the local server cache. ' +
                         'They do not affect CenterEdge API traffic. Changes take effect on the next page load.'
        }));

        const msOpts = [
            { value: 5000,   label: '5 seconds' },
            { value: 10000,  label: '10 seconds' },
            { value: 15000,  label: '15 seconds' },
            { value: 30000,  label: '30 seconds' },
            { value: 60000,  label: '1 minute' },
            { value: 120000, label: '2 minutes' },
            { value: 300000, label: '5 minutes' },
        ];

        const dashSel  = makeSelect(msOpts, data.ui_poll_default_ms || 30000);
        const ovrSel   = makeSelect(msOpts, data.ui_poll_override_active_ms || 10000);
        const immSel   = makeSelect(msOpts, data.ui_poll_imminent_ms || 5000);
        const anlSel   = makeSelect(msOpts, data.ui_poll_games_analytics_ms || 30000);
        const feedSel  = makeSelect(msOpts, data.ui_poll_games_feed_ms || 15000);
        const ovrpSel  = makeSelect(msOpts, data.ui_poll_overrides_ms || 15000);

        body.appendChild(formRow('Dashboard — Default Poll', dashSel,
            'Normal refresh rate when there are no active overrides or imminent transitions.'));
        body.appendChild(formRow('Dashboard — Active Override Poll', ovrSel,
            'Faster refresh rate while a schedule override is active.'));
        body.appendChild(formRow('Dashboard — Imminent Transition Poll', immSel,
            'Used when a scheduled transition or override expiry is within 2 minutes.'));
        body.appendChild(formRow('Games Page — Analytics Refresh', anlSel,
            'How often the analytics charts and KPIs refresh on the Games page.'));
        body.appendChild(formRow('Games Page — Live Feed Refresh', feedSel,
            'How often the recent-plays feed refreshes on the Games page.'));
        body.appendChild(formRow('Overrides Page — Refresh', ovrpSel,
            'How often the active overrides list refreshes on the Overrides page.'));

        const topInp = makeNumberInput(data.dashboard_top_games_limit || 5, 1, 20, 1);
        body.appendChild(formRow('Dashboard — Top Games Widget Size', topInp,
            'How many top-played games to show in the "Top games today" card (1 – 20).'));

        body.appendChild(saveBtn('Save Browser Polling Settings', function() {
            return {
                ui_poll_default_ms:         parseInt(dashSel.value, 10),
                ui_poll_override_active_ms: parseInt(ovrSel.value, 10),
                ui_poll_imminent_ms:        parseInt(immSel.value, 10),
                ui_poll_games_analytics_ms: parseInt(anlSel.value, 10),
                ui_poll_games_feed_ms:      parseInt(feedSel.value, 10),
                ui_poll_overrides_ms:       parseInt(ovrpSel.value, 10),
                dashboard_top_games_limit:  parseInt(topInp.value, 10),
            };
        }));

        section.appendChild(body);
        return section;
    }

    function buildRetentionSection(data) {
        const section = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        section.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'Data Retention' })
        ]));
        const body = App.el('div', { className: 'card-body' });

        body.appendChild(App.el('p', {
            className: 'text-muted text-sm',
            style: { marginBottom: '1rem' },
            textContent: 'These limits are applied by the daily cron at 00:05. ' +
                         'Shorter windows save disk space; longer windows preserve audit history.'
        }));

        const alInp  = makeNumberInput(data.retention_action_log_days || 90,  7,   3650);
        const saInp  = makeNumberInput(data.retention_scheduled_actions_days || 30, 1, 365);
        const ovInp  = makeNumberInput(data.retention_overrides_days || 90,   7,   3650);
        const txInp  = makeNumberInput(data.retention_transactions_days || 395, 30, 3650);

        body.appendChild(formRow('Action Log Retention (days)', alInp,
            'How long to keep the audit log of pause/unpause actions. Min 7, max 3650.'));
        body.appendChild(formRow('Scheduled Actions Retention (days)', saInp,
            'How long to keep executed scheduled-action rows. Min 1, max 365.'));
        body.appendChild(formRow('Schedule Overrides Retention (days)', ovInp,
            'How long to keep expired override records. Min 7, max 3650.'));
        body.appendChild(formRow('Game Play Transactions Retention (days)', txInp,
            'How long to keep the transaction feed cache (~13 months default covers a full year of analytics). Min 30, max 3650.'));

        body.appendChild(saveBtn('Save Retention Settings', function() {
            return {
                retention_action_log_days:         parseInt(alInp.value, 10),
                retention_scheduled_actions_days:  parseInt(saInp.value, 10),
                retention_overrides_days:          parseInt(ovInp.value, 10),
                retention_transactions_days:       parseInt(txInp.value, 10),
            };
        }));

        section.appendChild(body);
        return section;
    }

    function buildSchedulerSection(data) {
        const section = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        section.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'Scheduler Behaviour' })
        ]));
        const body = App.el('div', { className: 'card-body' });

        const retryInp = makeNumberInput(data.retry_max_attempts || 10, 1, 50);
        body.appendChild(formRow('Max Retry Attempts', retryInp,
            'How many times the watchdog will re-attempt a failed pause/unpause before giving up. ' +
            'Each attempt is 1 minute apart (1 watchdog cycle). Min 1, max 50.'));

        body.appendChild(saveBtn('Save Scheduler Settings', function() {
            return { retry_max_attempts: parseInt(retryInp.value, 10) };
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
