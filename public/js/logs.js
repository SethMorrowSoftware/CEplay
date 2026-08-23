/**
 * Action log viewer: paginated table with filters.
 * Enhanced with improved pagination controls and page size selector.
 */
(function() {
    App.registerRoute('#/logs', { render: renderLogs });

    let filters = {};
    let currentPage = 1;
    let perPage = 50;
    const debouncedLoadLogs = App.debounce(function() { loadLogs(); }, 300);

    async function renderLogs(container, params) {
        // Pre-fill filters from the hash query so pages like analytics can
        // deep-link straight to a filtered log view (e.g. ?success=0).
        filters = {};
        currentPage = 1;
        const query = (params && params._query) || {};
        if (query.source) filters.source = query.source;
        if (query.action) filters.action = query.action;
        if (query.success === '0' || query.success === '1') filters.success = query.success;
        if (query.from) filters.date_from = query.from;
        if (query.to) filters.date_to = query.to;

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Action Log' }),
                App.el('p', { className: 'page-subtitle', textContent: 'Every pause / unpause action — manual, scheduled, override, cron, and watchdog — with success status and details.' })
            ])
        ]));

        if (Object.keys(filters).length > 0) {
            container.appendChild(buildLogsFilterBanner());
        }

        // Filters bar
        const filtersBar = buildFiltersBar();
        container.appendChild(filtersBar);

        const content = App.el('div', { id: 'logs-content' });
        content.appendChild(App.loading());
        container.appendChild(content);

        await loadLogs();
    }

    /**
     * Human-readable label for raw source/action identifiers:
     * "plan_day" → "Plan day", "expired_override" → "Expired override".
     */
    function prettyLabel(value) {
        if (!value) return '—';
        var s = String(value).replace(/[_-]+/g, ' ');
        return s.charAt(0).toUpperCase() + s.slice(1);
    }

    function buildLogsFilterBanner() {
        const pieces = [];
        if (filters.success === '0') {
            pieces.push(App.el('span', { className: 'badge badge-danger', textContent: 'Failures only' }));
        } else if (filters.success === '1') {
            pieces.push(App.el('span', { className: 'badge badge-active', textContent: 'Successes only' }));
        }
        if (filters.source) pieces.push(App.el('span', { className: 'badge badge-info', textContent: 'source: ' + filters.source }));
        if (filters.action) pieces.push(App.el('span', { className: 'badge badge-info', textContent: 'action: ' + filters.action }));
        if (filters.date_from) pieces.push(App.el('span', { className: 'badge badge-info', textContent: 'from: ' + filters.date_from }));
        if (filters.date_to) pieces.push(App.el('span', { className: 'badge badge-info', textContent: 'to: ' + filters.date_to }));

        return App.el('div', { className: 'deep-link-banner' }, [
            App.el('span', { className: 'deep-link-banner-label', textContent: 'Filters applied: ' }),
            App.el('span', { className: 'flex gap-sm' }, pieces),
            App.el('button', {
                className: 'btn btn-ghost btn-sm',
                textContent: 'Clear',
                onClick: function() { window.location.hash = '#/logs'; }
            })
        ]);
    }

    function buildFiltersBar() {
        const bar = App.el('div', { className: 'card', style: { marginBottom: '1.5rem', padding: '1rem' } });

        const row = App.el('div', { className: 'form-row', style: { flexWrap: 'wrap', gap: '0.75rem' } });

        // Date range — values pre-fill from `filters` so deep-links from
        // analytics show their selection in the controls, not just in the URL.
        const dateFrom = App.el('input', {
            className: 'form-input', type: 'date',
            value: filters.date_from || '',
            style: { maxWidth: '160px' },
            onChange: () => { filters.date_from = dateFrom.value || undefined; currentPage = 1; debouncedLoadLogs(); }
        });
        const dateTo = App.el('input', {
            className: 'form-input', type: 'date',
            value: filters.date_to || '',
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
            className: 'form-select', style: { maxWidth: '140px' },
            onChange: () => { filters.source = sourceSelect.value || undefined; currentPage = 1; debouncedLoadLogs(); }
        });
        sourceSelect.appendChild(App.el('option', { value: '', textContent: 'All Sources' }));
        ['cron', 'manual', 'override', 'schedule', 'game-status', 'birthdays'].forEach(s => {
            const opt = App.el('option', { value: s, textContent: s });
            if (filters.source === s) opt.selected = true;
            sourceSelect.appendChild(opt);
        });

        row.appendChild(App.el('div', { className: 'form-group', style: { marginBottom: 0, flex: 'none' } }, [
            App.el('label', { className: 'form-label', textContent: 'Source', style: { fontSize: '0.75rem' } }),
            sourceSelect
        ]));

        // Action filter
        const actionSelect = App.el('select', {
            className: 'form-select', style: { maxWidth: '140px' },
            onChange: () => { filters.action = actionSelect.value || undefined; currentPage = 1; debouncedLoadLogs(); }
        });
        actionSelect.appendChild(App.el('option', { value: '', textContent: 'All Actions' }));
        ['pause', 'unpause', 'skip', 'plan_day', 'execute_action', 'game_tagged_out', 'game_enabled', 'game_paused', 'unpause_all', 'birthday_posted', 'birthday_failed'].forEach(a => {
            const opt = App.el('option', { value: a, textContent: a });
            if (filters.action === a) opt.selected = true;
            actionSelect.appendChild(opt);
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
        ['1', '0'].forEach(function(val) {
            const opt = App.el('option', { value: val, textContent: val === '1' ? 'Success' : 'Failed' });
            if (filters.success === val) opt.selected = true;
            statusSelect.appendChild(opt);
        });

        row.appendChild(App.el('div', { className: 'form-group', style: { marginBottom: 0, flex: 'none' } }, [
            App.el('label', { className: 'form-label', textContent: 'Status', style: { fontSize: '0.75rem' } }),
            statusSelect
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

                row.appendChild(App.el('td', {}, [
                    App.el('span', {
                        className: 'badge badge-info',
                        textContent: prettyLabel(log.source),
                        style: { fontSize: '0.7rem' }
                    })
                ]));

                row.appendChild(App.el('td', { textContent: prettyLabel(log.action) }));
                row.appendChild(App.el('td', { textContent: log.group_name || '\u2014' }));

                // Details: show game_name + error_message if any
                const detailParts = [];
                if (log.game_name) detailParts.push(log.game_name);
                if (log.error_message) detailParts.push(log.error_message);
                const detailText = detailParts.join(' \u2014 ') || '\u2014';

                row.appendChild(App.el('td', {
                    textContent: detailText,
                    style: { maxWidth: '300px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' },
                    title: detailText
                }));

                row.appendChild(App.el('td', {}, [
                    App.el('span', {
                        className: 'badge ' + (log.success ? 'badge-active' : 'badge-danger'),
                        textContent: log.success ? 'Success' : 'Failed'
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
