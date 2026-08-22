/**
 * Birthdays page — who's celebrating, and every setting the birthday bot uses.
 *
 * The bot itself runs from a systemd timer (birthdays/birthday_bot.php); this
 * page is the front end for its configuration and a way to see what it will do
 * before it does it. Settings are stored in api_config, so saving here changes
 * the next morning's post without anyone touching a file on the server.
 *
 *   GET  /api/birthdays          — settings + the field schema this form renders
 *   PUT  /api/birthdays          — save (birthdays_manage)
 *   GET  /api/birthdays/upcoming — the roster's next N days, and today's message
 *   POST /api/birthdays/test     — health check, preview, GIF check, live post
 *
 * The Slack token and Giphy key are write-only: the server sends back whether
 * one is set, never the value.
 */
(function() {
    App.registerRoute('#/birthdays', { render: renderBirthdaysPage });

    var state = {
        config: null,     // current values (secrets blanked)
        fields: null,     // schema from the server
        defaults: null,   // built-in pools, for the "reset to default" links
        canManage: false,
        dirty: {},        // field -> new value, pending save
        upcoming: null,
        gen: 0
    };

    function canManage() { return state.canManage && App.canAccess('birthdays_manage'); }

    // ---------------------------------------------------------------- render

    async function renderBirthdaysPage(container) {
        var gen = ++state.gen;
        state.dirty = {};

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Birthdays' }),
                App.el('p', { className: 'page-subtitle',
                    textContent: 'Staff birthdays from the POS roster, and everything the Slack birthday bot says when one comes round.' })
            ]),
            App.el('div', { className: 'flex gap-sm', id: 'bd-header-actions' })
        ]));

        container.appendChild(App.el('div', { id: 'bd-status', className: 'bd-status-row' }));
        container.appendChild(App.el('div', { id: 'bd-upcoming' }, [App.loading()]));
        container.appendChild(App.el('div', { id: 'bd-settings' }));

        try {
            var payload = await API.get('/birthdays');
            if (gen !== state.gen) return;
            state.config = payload.config || {};
            state.fields = payload.fields || {};
            state.defaults = payload.defaults || {};
            state.canManage = !!payload.can_manage;
        } catch (err) {
            App.toast('Could not load birthday settings: ' + errText(err), 'error');
            return;
        }

        buildHeaderActions();
        buildSettings();
        loadUpcoming();
        runCheck();
    }

    function errText(err) { return (err && err.message) ? err.message : 'unknown error'; }

    function buildHeaderActions() {
        var box = document.getElementById('bd-header-actions');
        if (!box) return;
        box.innerHTML = '';
        box.appendChild(App.el('button', {
            className: 'btn btn-secondary', textContent: 'Re-check',
            onClick: function() { runCheck(true); }
        }));
        if (canManage()) {
            box.appendChild(App.el('button', {
                className: 'btn btn-primary', textContent: 'Post a sample',
                onClick: openDemoDialog
            }));
        }
    }

    // ---------------------------------------------------------------- status

    async function runCheck(announce) {
        var box = document.getElementById('bd-status');
        if (!box) return;
        box.innerHTML = '';
        box.appendChild(App.el('div', { className: 'bd-check-loading text-sm text-muted',
            textContent: 'Checking…' }));
        try {
            var res = await API.post('/birthdays/test', { action: 'check' });
            renderChecks(res.checks || []);
            if (announce) App.toast('Re-checked.', 'success');
        } catch (err) {
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'bd-check bd-check-fail' }, [
                App.el('span', { className: 'bd-check-label', textContent: 'Check failed' }),
                App.el('span', { className: 'bd-check-detail', textContent: errText(err) })
            ]));
        }
    }

    function renderChecks(checks) {
        var box = document.getElementById('bd-status');
        if (!box) return;
        box.innerHTML = '';
        checks.forEach(function(c) {
            box.appendChild(App.el('div', { className: 'bd-check bd-check-' + c.status }, [
                App.el('span', { className: 'bd-check-dot', 'aria-hidden': 'true' }),
                App.el('span', { className: 'bd-check-label', textContent: c.label }),
                App.el('span', { className: 'bd-check-detail', textContent: c.detail || '' })
            ]));
        });
    }

    // -------------------------------------------------------------- upcoming

    async function loadUpcoming() {
        var box = document.getElementById('bd-upcoming');
        if (!box) return;
        try {
            state.upcoming = await API.get('/birthdays/upcoming?days=60');
        } catch (err) {
            box.innerHTML = '';
            box.appendChild(card('Upcoming birthdays', [
                App.el('p', { className: 'text-sm text-muted', textContent: errText(err) })
            ]));
            return;
        }
        renderUpcoming();
    }

    function renderUpcoming() {
        var box = document.getElementById('bd-upcoming');
        if (!box) return;
        var d = state.upcoming || {};
        box.innerHTML = '';

        if (!d.roster_ok) {
            box.appendChild(card('Upcoming birthdays', [
                App.el('p', { className: 'text-sm', textContent: d.error || 'The roster could not be read.' }),
                App.el('p', { className: 'text-xs text-muted',
                    textContent: 'The roster query is under Settings below. Nothing will post until this reads cleanly.' })
            ]));
            return;
        }

        var body = [];
        if (d.today_preview) {
            body.push(App.el('div', { className: 'bd-today' }, [
                App.el('div', { className: 'bd-today-label', textContent: 'Going out today' }),
                App.el('div', { className: 'bd-message' }, messageLines(d.today_preview))
            ]));
        }

        if (!d.upcoming || !d.upcoming.length) {
            body.push(App.el('p', { className: 'text-sm text-muted',
                textContent: 'No birthdays in the next ' + (d.days || 60) + ' days.' }));
        } else {
            var list = App.el('div', { className: 'bd-list' });
            d.upcoming.forEach(function(row) {
                list.appendChild(App.el('div', { className: 'bd-row' + (row.is_today ? ' bd-row-today' : '') }, [
                    App.el('div', { className: 'bd-date' }, [
                        App.el('span', { className: 'bd-date-day', textContent: dayNum(row.date) }),
                        App.el('span', { className: 'bd-date-mon', textContent: monShort(row.date) })
                    ]),
                    App.el('div', { className: 'bd-row-main' }, [
                        App.el('div', { className: 'bd-names', textContent: row.names.join(', ') }),
                        App.el('div', { className: 'bd-weekday text-xs text-muted',
                            textContent: row.is_today ? 'Today' : row.weekday + ' · ' + relDays(row.date, d.today) })
                    ])
                ]));
            });
            body.push(list);
        }

        var meta = d.people_count + ' current employees with a birthday on file';
        var sk = d.skipped || {};
        var dropped = (sk.no_birth_date || 0) + (sk.sentinel || 0) + (sk.unparsed || 0);
        if (dropped > 0) meta += ' · ' + dropped + ' roster rows had no usable date';

        box.appendChild(card('Upcoming birthdays', body, meta));
    }

    function messageLines(text) {
        return String(text).split('\n').map(function(line) {
            return App.el('div', { className: 'bd-message-line', textContent: line });
        });
    }

    function dayNum(iso) { return String(parseInt(iso.slice(8, 10), 10)); }
    function monShort(iso) {
        var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return m[parseInt(iso.slice(5, 7), 10) - 1] || '';
    }
    function relDays(iso, today) {
        var a = Date.parse(iso + 'T12:00:00'), b = Date.parse(today + 'T12:00:00');
        var n = Math.round((a - b) / 86400000);
        if (n <= 0) return 'today';
        if (n === 1) return 'tomorrow';
        if (n < 7) return 'in ' + n + ' days';
        if (n < 14) return 'next week';
        return 'in ' + Math.round(n / 7) + ' weeks';
    }

    // -------------------------------------------------------------- settings

    var GROUPS = [
        { title: 'Slack', keys: ['enabled', 'slack_bot_token', 'slack_channel', 'bot_username', 'bot_icon_emoji', 'mention'] },
        { title: 'The message', keys: ['name_style', 'venue_label', 'footer_text', 'post_separately', 'greetings', 'flavors', 'multi_greetings', 'multi_flavors'] },
        { title: 'GIFs and reactions', keys: ['gifs_enabled', 'giphy_api_key', 'gifs', 'add_reactions', 'reactions'] },
        { title: 'Who gets a greeting', keys: ['leap_day_mode', 'exclude_emp_nos', 'exclude_names', 'ignore_birth_dates', 'max_celebrants'] },
        { title: 'Roster query', keys: ['roster_sql'] }
    ];

    function buildSettings() {
        var box = document.getElementById('bd-settings');
        if (!box) return;
        box.innerHTML = '';

        if (!canManage()) {
            box.appendChild(card('Settings', [
                App.el('p', { className: 'text-sm text-muted',
                    textContent: 'You can see upcoming birthdays, but changing the bot’s settings needs the "birthdays_manage" permission.' })
            ]));
            return;
        }

        GROUPS.forEach(function(group) {
            var rows = [];
            group.keys.forEach(function(key) {
                if (!state.fields[key]) return;
                rows.push(fieldRow(key, state.fields[key]));
            });
            box.appendChild(card(group.title, rows));
        });

        box.appendChild(App.el('div', { className: 'bd-save-bar' }, [
            App.el('span', { id: 'bd-dirty-note', className: 'text-sm text-muted', textContent: '' }),
            App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', {
                    className: 'btn btn-secondary', textContent: 'Preview wording',
                    onClick: openPreviewDialog
                }),
                App.el('button', {
                    className: 'btn btn-secondary', textContent: 'Check GIFs',
                    onClick: runGifTest
                }),
                App.el('button', {
                    id: 'bd-save', className: 'btn btn-primary', textContent: 'Save settings',
                    onClick: saveSettings
                })
            ])
        ]));
    }

    function card(title, children, meta) {
        var header = [App.el('div', { className: 'card-title', textContent: title })];
        if (meta) header.push(App.el('span', { className: 'text-sm text-secondary', textContent: meta }));
        return App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header flex-between' }, header),
            App.el('div', { className: 'card-body' }, children)
        ]);
    }

    function markDirty(key, value) {
        state.dirty[key] = value;
        var note = document.getElementById('bd-dirty-note');
        var n = Object.keys(state.dirty).length;
        if (note) note.textContent = n ? n + ' unsaved change' + (n === 1 ? '' : 's') : '';
    }

    function fieldRow(key, spec) {
        var current = state.config[key];
        var group = App.el('div', { className: 'form-group bd-field' });
        var label = App.el('label', { className: 'form-label', textContent: spec.label, htmlFor: 'bd-' + key });
        var input;

        if (spec.type === 'bool') {
            input = App.el('label', { className: 'bd-toggle' }, [
                App.el('input', {
                    type: 'checkbox', id: 'bd-' + key, checked: !!current,
                    onChange: function(e) { markDirty(key, e.target.checked); }
                }),
                App.el('span', { textContent: spec.help || '' })
            ]);
            group.appendChild(label);
            group.appendChild(input);
            return group;
        }

        if (spec.type === 'enum') {
            input = App.el('select', { className: 'form-select', id: 'bd-' + key,
                onChange: function(e) { markDirty(key, e.target.value); } });
            Object.keys(spec.options || {}).forEach(function(v) {
                var opt = App.el('option', { value: v, textContent: spec.options[v] });
                if (String(current) === v) opt.selected = true;
                input.appendChild(opt);
            });
        } else if (spec.type === 'secret') {
            var isSet = !!state.config[key + '_set'];
            input = App.el('input', {
                className: 'form-input', type: 'password', id: 'bd-' + key,
                placeholder: isSet ? '•••••• stored — type to replace' : 'not set',
                autocomplete: 'new-password',
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        } else if (spec.type === 'list') {
            var lines = Array.isArray(current) ? current.join('\n') : '';
            input = App.el('textarea', {
                className: 'form-input bd-textarea', id: 'bd-' + key, rows: 6, value: lines,
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        } else if (spec.type === 'text') {
            input = App.el('textarea', {
                className: 'form-input bd-textarea bd-mono', id: 'bd-' + key, rows: 9,
                value: current == null ? '' : String(current),
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        } else if (spec.type === 'int') {
            input = App.el('input', {
                className: 'form-input bd-narrow', type: 'number', id: 'bd-' + key,
                min: spec.min == null ? '' : spec.min, max: spec.max == null ? '' : spec.max,
                value: current == null ? '' : current,
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        } else {
            input = App.el('input', {
                className: 'form-input', type: 'text', id: 'bd-' + key,
                value: current == null ? '' : String(current),
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        }

        group.appendChild(label);
        group.appendChild(input);

        var helpRow = [];
        if (spec.help) helpRow.push(App.el('span', { className: 'text-xs text-muted', textContent: spec.help }));

        // Pools ship with a built-in set; offer it rather than making someone
        // retype it to see what they replaced.
        if (spec.type === 'list' && state.defaults && Array.isArray(state.defaults[key])) {
            helpRow.push(App.el('button', {
                className: 'bd-link', type: 'button',
                textContent: 'load the built-in ' + state.defaults[key].length + ' lines',
                onClick: function() {
                    var el = document.getElementById('bd-' + key);
                    el.value = state.defaults[key].join('\n');
                    markDirty(key, el.value);
                }
            }));
        }
        if (key === 'roster_sql' && state.defaults && state.defaults.roster_sql) {
            helpRow.push(App.el('button', {
                className: 'bd-link', type: 'button', textContent: 'restore the verified query',
                onClick: function() {
                    var el = document.getElementById('bd-' + key);
                    el.value = state.defaults.roster_sql;
                    markDirty(key, el.value);
                }
            }));
        }
        if (spec.type === 'secret' && state.config[key + '_set']) {
            helpRow.push(App.el('button', {
                className: 'bd-link bd-link-danger', type: 'button', textContent: 'clear it',
                onClick: async function() {
                    var ok = await App.confirm({
                        title: 'Clear this value?',
                        message: 'The bot will stop posting until it is set again.',
                        confirmLabel: 'Clear'
                    });
                    if (!ok) return;
                    state.dirty[key] = '';
                    state.dirty[key + '_clear'] = true;
                    markDirty(key, '');
                    App.toast('Will be cleared when you save.', 'warning');
                }
            }));
        }
        if (helpRow.length) group.appendChild(App.el('div', { className: 'bd-help' }, helpRow));
        return group;
    }

    async function saveSettings() {
        if (!Object.keys(state.dirty).length) { App.toast('Nothing to save.', 'info'); return; }
        var btn = document.getElementById('bd-save');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
        try {
            var res = await API.put('/birthdays', state.dirty);
            state.config = res.config || state.config;
            state.dirty = {};
            buildSettings();
            App.toast('Settings saved.', 'success');
            loadUpcoming();
            runCheck();
        } catch (err) {
            App.toast(errText(err), 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Save settings'; }
        }
    }

    // ----------------------------------------------------------- test actions

    async function openPreviewDialog() {
        if (Object.keys(state.dirty).length) {
            App.toast('Save first — the preview shows what is stored, not what is typed.', 'warning');
        }
        var body = App.el('div', { id: 'bd-preview-body' }, [App.loading()]);
        var count = App.el('select', { className: 'form-select bd-narrow' }, [1, 2, 3].map(function(n) {
            return App.el('option', { value: n, textContent: n + (n === 1 ? ' person' : ' people') });
        }));
        async function load() {
            body.innerHTML = '';
            body.appendChild(App.loading());
            try {
                var res = await API.post('/birthdays/test',
                    { action: 'preview', people: parseInt(count.value, 10) });
                body.innerHTML = '';
                (res.messages || []).forEach(function(m) {
                    body.appendChild(App.el('div', { className: 'bd-message' }, messageLines(m)));
                });
            } catch (err) {
                body.innerHTML = '';
                body.appendChild(App.el('p', { className: 'text-sm', textContent: errText(err) }));
            }
        }
        count.addEventListener('change', load);
        App.showModal('Sample wording', App.el('div', {}, [
            App.el('div', { className: 'flex gap-sm bd-preview-controls' }, [
                App.el('span', { className: 'text-sm text-muted', textContent: 'Sharing a birthday:' }), count
            ]),
            body,
            App.el('p', { className: 'text-xs text-muted',
                textContent: 'Four fresh examples. Nothing is posted to Slack.' })
        ]), App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Close',
                onClick: function() { App.hideModal(); } })
        ]));
        load();
    }

    function openDemoDialog() {
        var count = App.el('select', { className: 'form-select bd-narrow' }, [1, 2, 3].map(function(n) {
            return App.el('option', { value: n, textContent: n + (n === 1 ? ' person' : ' people') });
        }));
        var post = App.el('button', { className: 'btn btn-primary', textContent: 'Post it' });

        post.addEventListener('click', async function() {
            post.disabled = true;
            post.textContent = 'Posting…';
            try {
                var res = await API.post('/birthdays/test',
                    { action: 'demo', people: parseInt(count.value, 10) || 1 });
                App.hideModal();
                App.toast('Posted to ' + res.channel + '.', 'success');
                if (res.note) App.toast(res.note, 'warning');
            } catch (err) {
                App.toast(errText(err), 'error');
                post.disabled = false;
                post.textContent = 'Post it';
            }
        });

        App.showModal('Post a sample announcement', App.el('div', {}, [
            App.el('p', { className: 'text-sm',
                textContent: 'This goes to the configured channel for real, under placeholder names and labelled as a preview.' }),
            App.el('p', { className: 'text-sm text-muted',
                textContent: 'Nothing is recorded, so it cannot affect anybody\'s real birthday greeting.' }),
            App.el('div', { className: 'flex gap-sm bd-preview-controls' }, [
                App.el('span', { className: 'text-sm text-muted', textContent: 'Sharing a birthday:' }), count
            ])
        ]), App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel',
                onClick: function() { App.hideModal(); } }),
            post
        ]));
    }

    async function runGifTest() {
        var body = App.el('div', {}, [App.loading()]);
        App.showModal('GIF check', body, App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Close',
                onClick: function() { App.hideModal(); } })
        ]));
        try {
            var res = await API.post('/birthdays/test', { action: 'test_gifs' });
            body.innerHTML = '';
            if (res.giphy) {
                body.appendChild(App.el('p', { className: 'text-sm',
                    textContent: res.giphy.ok
                        ? 'Giphy is working (' + res.giphy.count + ' results). The list below is only the fallback.'
                        : 'Giphy failed: ' + res.giphy.error }));
            }
            var list = App.el('div', { className: 'bd-gif-list' });
            (res.results || []).forEach(function(r) {
                list.appendChild(App.el('div', { className: 'bd-gif' + (r.ok ? ' bd-gif-ok' : ' bd-gif-dead') }, [
                    App.el('span', { className: 'bd-gif-state', textContent: r.ok ? 'ok' : 'dead' }),
                    App.el('span', { className: 'bd-gif-url', textContent: r.url })
                ]));
            });
            body.appendChild(list);
            body.appendChild(App.el('p', { className: 'text-sm',
                textContent: res.working + ' of ' + (res.results || []).length + ' resolve. ' + (res.note || '') }));
        } catch (err) {
            body.innerHTML = '';
            body.appendChild(App.el('p', { className: 'text-sm', textContent: errText(err) }));
        }
    }
})();
