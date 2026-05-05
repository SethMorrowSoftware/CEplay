/**
 * Action log viewer: paginated table with filters.
 * Enhanced with improved pagination controls and page size selector.
 *
 * Filter dropdown contents are pulled live from /api/logs/options so adding
 * a new audit-logged action only requires updating the backend whitelist.
 */
(function() {
    App.registerRoute('#/logs', { render: renderLogs });

    let filters = {};
    let currentPage = 1;
    let perPage = 50;
    let filterOptions = { sources: [], actions: [] };
    const debouncedLoadLogs = App.debounce(function() { loadLogs(); }, 300);
    const debouncedTextFilter = App.debounce(function() { loadLogs(); }, 350);

    async function renderLogs(container) {
        filters = {};
        currentPage = 1;

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('h1', { className: 'page-title', textContent: 'Action Log' })
        ]));

        // Pull canonical filter dropdown values from the backend so a
        // newly audit-logged action shows up here without a JS edit.
        try {
            var opts = await API.get('logs/options');
            if (opts && Array.isArray(opts.sources)) filterOptions.sources = opts.sources;
            if (opts && Array.isArray(opts.actions)) filterOptions.actions = opts.actions;
        } catch (e) {
            // Non-fatal — filter dropdowns just stay empty.
            console.warn('Failed to load log filter options:', e && e.message ? e.message : e);
        }

        const filtersBar = buildFiltersBar();
        container.appendChild(filtersBar);

        const content = App.el('div', { id: 'logs-content' });
        content.appendChild(App.loading());
        container.appendChild(content);

        await loadLogs();
    }

    function buildFiltersBar() {
        const bar = App.el('div', { className: 'card', style: { marginBottom: '1.5rem', padding: '1rem' } });

        const row = App.el('div', { className: 'form-row', style: { flexWrap: 'wrap', gap: '0.75rem' } });

        // Date range
        const dateFrom = App.el('input', {
            className: 'form-input', type: 'date',
            style: { maxWidth: '160px' },
            onChange: () => { filters.date_from = dateFrom.value || undefined; currentPage = 1; debouncedLoadLogs(); }
        });
        const dateTo = App.el('input', {
            className: 'form-input', type: 'date',
            style: { maxWidth: '160px' },
            onChange: () => { filters.date_to = dateTo.value || undefined; currentPage = 1; debouncedLoadLogs(); }
        });

        row.appendChild(App.el('div', { className: 'form-group', style: { marginBottom: 0, flex: 'none' } }, [
            App.el('label', { className: 'form-label', textContent: 'From', style: { fontSize: '0.75rem' } }),
            dateFrom
        ]));
        row.appendChild(App.el('div', { className: 'form-group', style: { marginBottom: 0, flex: 'none' } }, [
            App.el('label', { className: 'form-label', textContent: 'To', style: { fontSize: '0.75rem' } }),
            dateTo
        ]));

        // Source filter
        const sourceSelect = App.el('select', {
            className: 'form-select', style: { maxWidth: '180px' },
            onChange: () => { filters.source = sourceSelect.value || undefined; currentPage = 1; debouncedLoadLogs(); }
        });
        sourceSelect.appendChild(App.el('option', { value: '', textContent: 'All Sources' }));
        filterOptions.sources.forEach(s => {
            sourceSelect.appendChild(App.el('option', { value: s, textContent: s }));
        });

        row.appendChild(App.el('div', { className: 'form-group', style: { marginBottom: 0, flex: 'none' } }, [
            App.el('label', { className: 'form-label', textContent: 'Source', style: { fontSize: '0.75rem' } }),
            sourceSelect
        ]));

        // Action filter
        const actionSelect = App.el('select', {
            className: 'form-select', style: { maxWidth: '220px' },
            onChange: () => { filters.action = actionSelect.value || undefined; currentPage = 1; debouncedLoadLogs(); }
        });
        actionSelect.appendChild(App.el('option', { value: '', textContent: 'All Actions' }));
        filterOptions.actions.forEach(a => {
            actionSelect.appendChild(App.el('option', { value: a, textContent: a }));
        });

        row.appendChild(App.el('div', { className: 'form-group', style: { marginBottom: 0, flex: 'none' } }, [
            App.el('label', { className: 'form-label', textContent: 'Action', style: { fontSize: '0.75rem' } }),
            actionSelect
        ]));

        // Status filter
        const statusSelect = App.el('select', {
            className: 'form-select', style: { maxWidth: '130px' },
            onChange: () => { filters.success = statusSelect.value === '' ? undefined : statusSelect.value; currentPage = 1; debouncedLoadLogs(); }
        });
        statusSelect.appendChild(App.el('option', { value: '', textContent: 'All Status' }));
        statusSelect.appendChild(App.el('option', { value: '1', textContent: 'Success' }));
        statusSelect.appendChild(App.el('option', { value: '0', textContent: 'Failed' }));

        row.appendChild(App.el('div', { className: 'form-group', style: { marginBottom: 0, flex: 'none' } }, [
            App.el('label', { className: 'form-label', textContent: 'Status', style: { fontSize: '0.75rem' } }),
            statusSelect
        ]));

        // Actor (username) filter
        const userInput = App.el('input', {
            className: 'form-input', type: 'text', placeholder: 'username',
            style: { maxWidth: '160px' },
            onInput: () => { filters.actor_username = userInput.value.trim() || undefined; currentPage = 1; debouncedTextFilter(); }
        });
        row.appendChild(App.el('div', { className: 'form-group', style: { marginBottom: 0, flex: 'none' } }, [
            App.el('label', { className: 'form-label', textContent: 'User', style: { fontSize: '0.75rem' } }),
            userInput
        ]));

        // IP filter
        const ipInput = App.el('input', {
            className: 'form-input', type: 'text', placeholder: 'IP / prefix',
            style: { maxWidth: '160px' },
            onInput: () => { filters.ip = ipInput.value.trim() || undefined; currentPage = 1; debouncedTextFilter(); }
        });
        row.appendChild(App.el('div', { className: 'form-group', style: { marginBottom: 0, flex: 'none' } }, [
            App.el('label', { className: 'form-label', textContent: 'IP', style: { fontSize: '0.75rem' } }),
            ipInput
        ]));

        bar.appendChild(row);
        return bar;
    }

    async function loadLogs() {
        const content = document.getElementById('logs-content');
        if (!content) return;

        var gen = App.navGeneration();
        try {
            const params = new URLSearchParams();
            params.set('page', String(currentPage));
            params.set('per_page', String(perPage));
            if (filters.date_from) params.set('from', filters.date_from);
            if (filters.date_to) params.set('to', filters.date_to);
            if (filters.source) params.set('source', filters.source);
            if (filters.action) params.set('action', filters.action);
            if (filters.success !== undefined) params.set('success', filters.success);
            if (filters.actor_username) params.set('actor_username', filters.actor_username);
            if (filters.ip) params.set('ip', filters.ip);

            const data = await API.get('logs?' + params.toString()) || {};
            if (App.navGeneration() !== gen) return;
            content.innerHTML = '';

            if (!data.logs || data.logs.length === 0) {
                content.appendChild(App.el('div', { className: 'empty-state' }, [
                    App.el('div', { className: 'empty-state-icon', textContent: '\uD83D\uDCCB' }),
                    App.el('div', { className: 'empty-state-text', textContent: 'No log entries found.' })
                ]));
                return;
            }

            // Table
            var scrollContainer = App.el('div', { className: 'table-scroll-container' });
            const table = App.el('table', { className: 'table' });

            const thead = App.el('thead');
            thead.appendChild(App.el('tr', {}, [
                App.el('th', { textContent: 'Time' }),
                App.el('th', { textContent: 'Actor' }),
                App.el('th', { textContent: 'IP' }),
                App.el('th', { textContent: 'Source' }),
                App.el('th', { textContent: 'Action' }),
                App.el('th', { textContent: 'Group' }),
                App.el('th', { textContent: 'Details' }),
                App.el('th', { textContent: 'Status' })
            ]));
            table.appendChild(thead);

            const tbody = App.el('tbody');
            data.logs.forEach(log => {
                const row = App.el('tr', {
                    className: log.success ? '' : 'row-error'
                });

                row.appendChild(App.el('td', {
                    textContent: App.formatDatetime(log.timestamp),
                    style: { whiteSpace: 'nowrap', fontSize: '0.8rem' }
                }));

                // Actor cell \u2014 username preferred, fall back to id, system if neither
                var actorText = log.actor_username
                    ? log.actor_username
                    : (log.actor_user_id ? '#' + log.actor_user_id : 'system');
                row.appendChild(App.el('td', {
                    textContent: actorText,
                    style: { fontSize: '0.8rem', whiteSpace: 'nowrap' }
                }));

                row.appendChild(App.el('td', {
                    textContent: log.ip_address || '\u2014',
                    style: { fontSize: '0.8rem', whiteSpace: 'nowrap', color: 'var(--text-secondary)' }
                }));

                row.appendChild(App.el('td', {}, [
                    App.el('span', {
                        className: 'badge badge-info',
                        textContent: log.source || '\u2014',
                        style: { fontSize: '0.7rem' }
                    })
                ]));

                row.appendChild(App.el('td', { textContent: log.action || '\u2014' }));
                row.appendChild(App.el('td', { textContent: log.group_name || '\u2014' }));

                // Details cell: prefer game_name + error, then surface a couple
                // of high-signal keys from the structured details JSON so the
                // operator doesn't have to inspect raw payloads in dev tools.
                const detailParts = [];
                if (log.game_name) detailParts.push(log.game_name);
                if (log.error_message) detailParts.push(log.error_message);
                if (log.details && typeof log.details === 'object') {
                    var summaryKeys = ['group_name', 'card_number', 'changed', 'errors', 'kiosk_id', 'game_count', 'kiosk_count'];
                    summaryKeys.forEach(function(k) {
                        if (log.details[k] !== undefined && log.details[k] !== null && log.details[k] !== '') {
                            detailParts.push(k + ': ' + log.details[k]);
                        }
                    });
                }
                const detailText = detailParts.join(' \u2014 ') || '\u2014';

                row.appendChild(App.el('td', {
                    textContent: detailText,
                    style: { maxWidth: '320px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' },
                    title: detailText
                }));

                row.appendChild(App.el('td', {}, [
                    App.el('span', {
                        className: 'badge ' + (log.success ? 'badge-active' : 'badge-danger'),
                        textContent: log.success ? 'OK' : 'FAIL'
                    })
                ]));

                tbody.appendChild(row);
            });
            table.appendChild(tbody);

            scrollContainer.appendChild(table);
            content.appendChild(scrollContainer);

            // Pagination bar
            const totalItems = data.total || 0;
            const totalPages = Math.ceil(totalItems / perPage);
            if (totalPages >= 1) {
                content.appendChild(buildPagination(totalItems, totalPages));
            }

        } catch (err) {
            content.innerHTML = '';
            App.toast(err.message, 'error');
        }
    }

    function buildPagination(totalItems, totalPages) {
        var bar = App.el('div', { className: 'pagination-bar' });

        var startIdx = (currentPage - 1) * perPage + 1;
        var endIdx = Math.min(currentPage * perPage, totalItems);

        bar.appendChild(App.el('div', { className: 'pagination-info' }, [
            App.el('span', { textContent: 'Showing ' + startIdx + '-' + endIdx + ' of ' + totalItems + ' entries' }),
            App.el('select', {
                className: 'page-size-select',
                onChange: function() {
                    perPage = parseInt(this.value);
                    currentPage = 1;
                    loadLogs();
                }
            }, [25, 50, 100, 200].map(function(size) {
                var opt = App.el('option', { value: String(size), textContent: size + ' / page' });
                if (size === perPage) opt.selected = true;
                return opt;
            }))
        ]));

        var controls = App.el('div', { className: 'pagination-controls' });

        // First
        controls.appendChild(App.el('button', {
            className: 'btn btn-ghost btn-sm',
            textContent: '\u00AB',
            disabled: currentPage <= 1,
            title: 'First page',
            onClick: function() { currentPage = 1; loadLogs(); }
        }));

        // Previous
        controls.appendChild(App.el('button', {
            className: 'btn btn-ghost btn-sm',
            textContent: '\u2039 Prev',
            disabled: currentPage <= 1,
            onClick: () => { currentPage--; loadLogs(); }
        }));

        // Page numbers (show max 5 around current)
        const start = Math.max(1, currentPage - 2);
        const end = Math.min(totalPages, currentPage + 2);

        if (start > 1) {
            controls.appendChild(pageBtn(1));
            if (start > 2) {
                controls.appendChild(App.el('span', { textContent: '\u2026', style: { padding: '0.25rem 0.35rem', color: 'var(--text-muted)' } }));
            }
        }

        for (let i = start; i <= end; i++) {
            controls.appendChild(pageBtn(i));
        }

        if (end < totalPages) {
            if (end < totalPages - 1) {
                controls.appendChild(App.el('span', { textContent: '\u2026', style: { padding: '0.25rem 0.35rem', color: 'var(--text-muted)' } }));
            }
            controls.appendChild(pageBtn(totalPages));
        }

        // Next
        controls.appendChild(App.el('button', {
            className: 'btn btn-ghost btn-sm',
            textContent: 'Next \u203A',
            disabled: currentPage >= totalPages,
            onClick: () => { currentPage++; loadLogs(); }
        }));

        // Last
        controls.appendChild(App.el('button', {
            className: 'btn btn-ghost btn-sm',
            textContent: '\u00BB',
            disabled: currentPage >= totalPages,
            title: 'Last page',
            onClick: function() { currentPage = totalPages; loadLogs(); }
        }));

        bar.appendChild(controls);
        return bar;
    }

    function pageBtn(page) {
        return App.el('button', {
            className: 'btn btn-sm ' + (page === currentPage ? 'btn-primary' : 'btn-ghost'),
            textContent: String(page),
            onClick: () => { currentPage = page; loadLogs(); }
        });
    }
})();
