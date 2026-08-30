/**
 * Anniversaries page — who is coming up on a work anniversary, and every
 * setting the anniversary bot uses.
 *
 * The bot itself runs from a systemd timer (anniversaries/anniversary_bot.php);
 * this page is the front end for its configuration and a way to see what it
 * will do before it does it. Settings are stored in api_config, so saving here
 * changes the next morning's post without anyone touching a file on the server.
 *
 *   GET  /api/anniversaries          — settings + the field schema this form renders
 *   PUT  /api/anniversaries          — save (anniversaries_manage)
 *   GET  /api/anniversaries/upcoming — the roster's next N days, and today's message
 *   POST /api/anniversaries/test     — health check, preview, GIF check, live post
 *
 * The Slack token and Giphy key are write-only: the server sends back whether
 * one is set, never the value.
 *
 * The sibling of birthdays.js. The two pages are deliberately built the same
 * way — the same check row, the same save bar, the same preview dialog — so
 * whoever configures one already knows how to configure the other.
 */
(function() {
    App.registerRoute('#/anniversaries', { render: renderAnniversariesPage });

    var state = {
        config: null,     // current values (secrets blanked)
        fields: null,     // schema from the server
        defaults: null,   // built-in pools, for the "reset to default" links
        canManage: false,
        dirty: {},        // field -> new value, pending save
        upcoming: null,
        gen: 0
    };

    function canManage() { return state.canManage && App.canAccess('anniversaries_manage'); }

    // ---------------------------------------------------------------- render

    async function renderAnniversariesPage(container) {
        var gen = ++state.gen;
        state.dirty = {};

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Work Anniversaries' }),
                App.el('p', { className: 'page-subtitle',
                    textContent: 'Years of service from the POS roster, and everything the Slack anniversary bot says when one comes round.' })
            ]),
            App.el('div', { className: 'flex gap-sm', id: 'an-header-actions' })
        ]));

        container.appendChild(App.el('div', { id: 'an-status', className: 'an-status-row' }));
        container.appendChild(App.el('div', { id: 'an-upcoming' }, [App.loading()]));
        container.appendChild(App.el('div', { id: 'an-settings' }));

        try {
            var payload = await API.get('/anniversaries');
            if (gen !== state.gen) return;
            state.config = payload.config || {};
            state.fields = payload.fields || {};
            state.defaults = payload.defaults || {};
            state.canManage = !!payload.can_manage;
        } catch (err) {
            App.toast('Could not load anniversary settings: ' + errText(err), 'error');
            return;
        }

        buildHeaderActions();
        buildSettings();
        loadUpcoming();
        runCheck();
    }

    function errText(err) { return (err && err.message) ? err.message : 'unknown error'; }

    function buildHeaderActions() {
        var box = document.getElementById('an-header-actions');
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
        var box = document.getElementById('an-status');
        if (!box) return;
        box.innerHTML = '';
        box.appendChild(App.el('div', { className: 'an-check-loading text-sm text-muted',
            textContent: 'Checking…' }));
        try {
            var res = await API.post('/anniversaries/test', { action: 'check' });
            renderChecks(res.checks || []);
            if (announce) App.toast('Re-checked.', 'success');
        } catch (err) {
            box.innerHTML = '';
            box.appendChild(App.el('div', { className: 'an-check an-check-fail' }, [
                App.el('span', { className: 'an-check-label', textContent: 'Check failed' }),
                App.el('span', { className: 'an-check-detail', textContent: errText(err) })
            ]));
        }
    }

    function renderChecks(checks) {
        var box = document.getElementById('an-status');
        if (!box) return;
        box.innerHTML = '';
        checks.forEach(function(c) {
            box.appendChild(App.el('div', { className: 'an-check an-check-' + c.status }, [
                App.el('span', { className: 'an-check-dot', 'aria-hidden': 'true' }),
                App.el('span', { className: 'an-check-label', textContent: c.label }),
                App.el('span', { className: 'an-check-detail', textContent: c.detail || '' })
            ]));
        });
    }

    // -------------------------------------------------------------- upcoming

    async function loadUpcoming() {
        var box = document.getElementById('an-upcoming');
        if (!box) return;
        try {
            state.upcoming = await API.get('/anniversaries/upcoming?days=60');
        } catch (err) {
            box.innerHTML = '';
            box.appendChild(card('Upcoming anniversaries', [
                App.el('p', { className: 'text-sm text-muted', textContent: errText(err) })
            ]));
            return;
        }
        renderUpcoming();
    }

    function renderUpcoming() {
        var box = document.getElementById('an-upcoming');
        if (!box) return;
        var d = state.upcoming || {};
        box.innerHTML = '';

        if (!d.roster_ok) {
            box.appendChild(card('Upcoming anniversaries', [
                App.el('p', { className: 'text-sm', textContent: d.error || 'The roster could not be read.' }),
                App.el('p', { className: 'text-xs text-muted',
                    textContent: 'The roster query is under Settings below. Nothing will post until this reads cleanly.' })
            ]));
            return;
        }

        var body = [];
        if (d.today_preview) {
            body.push(App.el('div', { className: 'an-today' }, [
                App.el('div', { className: 'an-today-label', textContent: 'Going out today' }),
                App.el('div', { className: 'an-message' }, messageLines(d.today_preview))
            ]));
        }

        if (!d.upcoming || !d.upcoming.length) {
            body.push(App.el('p', { className: 'text-sm text-muted',
                textContent: d.mode === 'milestones'
                    ? 'No milestone anniversaries in the next ' + (d.days || 60) + ' days. '
                      + 'Ordinary years are not posted while "Milestone years only" is selected.'
                    : 'No anniversaries in the next ' + (d.days || 60) + ' days.' }));
        } else {
            var list = App.el('div', { className: 'an-list' });
            d.upcoming.forEach(function(row) {
                list.appendChild(App.el('div', { className: 'an-row' + (row.is_today ? ' an-row-today' : '') }, [
                    App.el('div', { className: 'an-date' }, [
                        App.el('span', { className: 'an-date-day', textContent: dayNum(row.date) }),
                        App.el('span', { className: 'an-date-mon', textContent: monShort(row.date) })
                    ]),
                    App.el('div', { className: 'an-row-main' }, [
                        App.el('div', { className: 'an-names' }, peopleChips(row.people)),
                        App.el('div', { className: 'an-weekday text-xs text-muted',
                            textContent: row.is_today ? 'Today' : row.weekday + ' · ' + relDays(row.date, d.today) })
                    ])
                ]));
            });
            body.push(list);
        }

        var meta = d.people_count + ' current employees with a hire date on file';
        var sk = d.skipped || {};
        var dropped = (sk.no_hire_date || 0) + (sk.sentinel || 0) + (sk.unparsed || 0);
        if (dropped > 0) meta += ' · ' + dropped + ' roster rows had no usable date';
        if (sk.future > 0) meta += ' · ' + sk.future + ' not started yet';

        box.appendChild(card('Upcoming anniversaries', body, meta));
    }

    /** One chip per person: the name, the year count, and a milestone marker. */
    function peopleChips(people) {
        return (people || []).map(function(p) {
            return App.el('span', { className: 'an-person' + (p.milestone ? ' an-person-milestone' : '') }, [
                App.el('span', { className: 'an-person-name', textContent: p.name }),
                App.el('span', { className: 'an-person-years', textContent: yearLabel(p.years) })
            ]);
        });
    }

    function yearLabel(n) { return n + (n === 1 ? ' year' : ' years'); }

    function messageLines(text) {
        return String(text).split('\n').map(function(line) {
            return App.el('div', { className: 'an-message-line', textContent: line });
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
        { title: 'The message', keys: ['name_style', 'venue_label', 'footer_text', 'post_separately', 'greetings', 'flavors', 'milestone_greetings', 'milestone_flavors', 'multi_greetings', 'multi_flavors'] },
        { title: 'GIFs and reactions', keys: ['gifs_enabled', 'giphy_api_key', 'gifs', 'add_reactions', 'reactions'] },
        { title: 'Who gets a message', keys: ['celebrate_years', 'min_years', 'milestone_years', 'leap_day_mode', 'exclude_emp_nos', 'exclude_names', 'ignore_hire_dates', 'max_celebrants'] },
        { title: 'Roster query', keys: ['roster_sql'] }
    ];

    /**
     * The placeholders a message can use, spelled out where the wording is
     * edited. {ordinal} is the one that needs saying: it has no meaning when
     * several people share a day, and the server rejects it in those pools.
     */
    var PLACEHOLDER_NOTE = '{names} · {count} · {venue} · {years} · {year_label} · {ordinal} · {s}. '
        + 'On a shared day {years} is the COMBINED total and {ordinal} is not available.';

    function buildSettings() {
        var box = document.getElementById('an-settings');
        if (!box) return;
        box.innerHTML = '';

        if (!canManage()) {
            box.appendChild(card('Settings', [
                App.el('p', { className: 'text-sm text-muted',
                    textContent: 'You can see upcoming anniversaries, but changing the bot’s settings needs the "anniversaries_manage" permission.' })
            ]));
            return;
        }

        GROUPS.forEach(function(group) {
            var rows = [];
            if (group.title === 'The message') {
                rows.push(App.el('p', { className: 'text-xs text-muted an-placeholders',
                    textContent: PLACEHOLDER_NOTE }));
            }
            group.keys.forEach(function(key) {
                if (!state.fields[key]) return;
                rows.push(fieldRow(key, state.fields[key]));
            });
            box.appendChild(card(group.title, rows));
        });

        box.appendChild(App.el('div', { className: 'an-save-bar' }, [
            App.el('span', { id: 'an-dirty-note', className: 'text-sm text-muted', textContent: '' }),
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
                    id: 'an-save', className: 'btn btn-primary', textContent: 'Save settings',
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
        var note = document.getElementById('an-dirty-note');
        var n = Object.keys(state.dirty).length;
        if (note) note.textContent = n ? n + ' unsaved change' + (n === 1 ? '' : 's') : '';
    }

    function fieldRow(key, spec) {
        var current = state.config[key];
        var group = App.el('div', { className: 'form-group an-field' });
        var label = App.el('label', { className: 'form-label', textContent: spec.label, htmlFor: 'an-' + key });
        var input;

        if (spec.type === 'bool') {
            input = App.el('label', { className: 'an-toggle' }, [
                App.el('input', {
                    type: 'checkbox', id: 'an-' + key, checked: !!current,
                    onChange: function(e) { markDirty(key, e.target.checked); }
                }),
                App.el('span', { textContent: spec.help || '' })
            ]);
            group.appendChild(label);
            group.appendChild(input);
            return group;
        }

        if (spec.type === 'enum') {
            input = App.el('select', { className: 'form-select', id: 'an-' + key,
                onChange: function(e) { markDirty(key, e.target.value); } });
            Object.keys(spec.options || {}).forEach(function(v) {
                var opt = App.el('option', { value: v, textContent: spec.options[v] });
                if (String(current) === v) opt.selected = true;
                input.appendChild(opt);
            });
        } else if (spec.type === 'secret') {
            var isSet = !!state.config[key + '_set'];
            input = App.el('input', {
                className: 'form-input', type: 'password', id: 'an-' + key,
                placeholder: isSet ? '•••••• stored — type to replace' : 'not set',
                autocomplete: 'new-password',
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        } else if (spec.type === 'list') {
            var lines = Array.isArray(current) ? current.join('\n') : '';
            input = App.el('textarea', {
                className: 'form-input an-textarea', id: 'an-' + key, rows: 6, value: lines,
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        } else if (spec.type === 'text') {
            input = App.el('textarea', {
                className: 'form-input an-textarea an-mono', id: 'an-' + key, rows: 9,
                value: current == null ? '' : String(current),
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        } else if (spec.type === 'int') {
            input = App.el('input', {
                className: 'form-input an-narrow', type: 'number', id: 'an-' + key,
                min: spec.min == null ? '' : spec.min, max: spec.max == null ? '' : spec.max,
                value: current == null ? '' : current,
                onInput: function(e) { markDirty(key, e.target.value); }
            });
        } else {
            input = App.el('input', {
                className: 'form-input', type: 'text', id: 'an-' + key,
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
                className: 'an-link', type: 'button',
                textContent: 'load the built-in ' + state.defaults[key].length + ' lines',
                onClick: function() {
                    var el = document.getElementById('an-' + key);
                    el.value = state.defaults[key].join('\n');
                    markDirty(key, el.value);
                }
            }));
        }
        if (key === 'roster_sql' && state.defaults && state.defaults.roster_sql) {
            helpRow.push(App.el('button', {
                className: 'an-link', type: 'button', textContent: 'restore the default query',
                onClick: function() {
                    var el = document.getElementById('an-' + key);
                    el.value = state.defaults.roster_sql;
                    markDirty(key, el.value);
                }
            }));
        }
        if (spec.type === 'secret' && state.config[key + '_set']) {
            helpRow.push(App.el('button', {
                className: 'an-link an-link-danger', type: 'button', textContent: 'clear it',
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
        if (helpRow.length) group.appendChild(App.el('div', { className: 'an-help' }, helpRow));
        return group;
    }

    async function saveSettings() {
        if (!Object.keys(state.dirty).length) { App.toast('Nothing to save.', 'info'); return; }
        var btn = document.getElementById('an-save');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
        try {
            var res = await API.put('/anniversaries', state.dirty);
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
        var body = App.el('div', { id: 'an-preview-body' }, [App.loading()]);
        var note = App.el('p', { className: 'text-xs text-muted', textContent: '' });
        var count = App.el('select', { className: 'form-select an-narrow' }, [1, 2, 3].map(function(n) {
            return App.el('option', { value: n, textContent: n + (n === 1 ? ' person' : ' people') });
        }));
        async function load() {
            body.innerHTML = '';
            body.appendChild(App.loading());
            try {
                var res = await API.post('/anniversaries/test',
                    { action: 'preview', people: parseInt(count.value, 10) });
                body.innerHTML = '';
                (res.messages || []).forEach(function(m) {
                    body.appendChild(App.el('div', { className: 'an-message' }, messageLines(m)));
                });
                // Say which pool is on screen: the milestone wording only shows
                // up when the sample lands on a milestone year, and without
                // this the difference reads as randomness.
                note.textContent = 'Sample years: ' + (res.years || []).map(yearLabel).join(', ')
                    + (res.milestone ? ' — a milestone year, so these use the milestone wording.' : '.')
                    + ' Four fresh examples. Nothing is posted to Slack.';
            } catch (err) {
                body.innerHTML = '';
                body.appendChild(App.el('p', { className: 'text-sm', textContent: errText(err) }));
            }
        }
        count.addEventListener('change', load);
        App.showModal('Sample wording', App.el('div', {}, [
            App.el('div', { className: 'flex gap-sm an-preview-controls' }, [
                App.el('span', { className: 'text-sm text-muted', textContent: 'Sharing a day:' }), count
            ]),
            body,
            note
        ]), App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Close',
                onClick: function() { App.hideModal(); } })
        ]));
        load();
    }

    function openDemoDialog() {
        var count = App.el('select', { className: 'form-select an-narrow' }, [1, 2, 3].map(function(n) {
            return App.el('option', { value: n, textContent: n + (n === 1 ? ' person' : ' people') });
        }));
        var post = App.el('button', { className: 'btn btn-primary', textContent: 'Post it' });

        post.addEventListener('click', async function() {
            post.disabled = true;
            post.textContent = 'Posting…';
            try {
                var res = await API.post('/anniversaries/test',
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
                textContent: 'Nothing is recorded, so it cannot affect anybody\'s real anniversary message.' }),
            App.el('div', { className: 'flex gap-sm an-preview-controls' }, [
                App.el('span', { className: 'text-sm text-muted', textContent: 'Sharing a day:' }), count
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
            var res = await API.post('/anniversaries/test', { action: 'test_gifs' });
            body.innerHTML = '';
            if (res.giphy) {
                body.appendChild(App.el('p', { className: 'text-sm',
                    textContent: res.giphy.ok
                        ? 'Giphy is working (' + res.giphy.count + ' results). The list below is only the fallback.'
                        : 'Giphy failed: ' + res.giphy.error }));
            }
            var list = App.el('div', { className: 'an-gif-list' });
            (res.results || []).forEach(function(r) {
                list.appendChild(App.el('div', { className: 'an-gif' + (r.ok ? ' an-gif-ok' : ' an-gif-dead') }, [
                    App.el('span', { className: 'an-gif-state', textContent: r.ok ? 'ok' : 'dead' }),
                    App.el('span', { className: 'an-gif-url', textContent: r.url })
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
