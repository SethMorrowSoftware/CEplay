/**
 * Revenue Analytics Dashboard — operational overview and CenterEdge transaction data.
 */
(function() {
    App.registerRoute('#/revenue', { render: renderRevenue });

    function renderRevenue(container) {
        var page = App.el('div', { className: 'page-revenue' });

        var header = App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h2', { textContent: 'Analytics & Overview' }),
                App.el('p', { className: 'text-secondary', textContent: 'Operational metrics and CenterEdge transaction data' })
            ]),
            App.el('button', { className: 'btn btn-secondary', textContent: 'Refresh', onClick: loadAll })
        ]);
        page.appendChild(header);

        // Stats cards
        var statsEl = App.el('div', { className: 'revenue-stats' });
        page.appendChild(statsEl);

        // Fleet & Attractions side by side
        var gridRow = App.el('div', { className: 'analytics-grid' });
        var fleetCard = App.el('div', { className: 'card', style: { padding: '16px' } });
        var attractionCard = App.el('div', { className: 'card', style: { padding: '16px' } });
        gridRow.appendChild(fleetCard);
        gridRow.appendChild(attractionCard);
        page.appendChild(gridRow);

        // Hourly activity chart
        var hourlyCard = App.el('div', { className: 'card', style: { padding: '16px', marginTop: '16px' } });
        page.appendChild(hourlyCard);

        // Recent transactions
        var txCard = App.el('div', { className: 'card', style: { padding: '16px', marginTop: '16px' } });
        page.appendChild(txCard);

        container.appendChild(page);

        function loadAll() {
            loadSummary();
            loadHourly();
        }

        function loadSummary() {
            API.get('revenue/summary').then(function(data) {
                renderTopStats(statsEl, data);
                renderFleetHealth(fleetCard, data.fleet);
                renderAttractionHealth(attractionCard, data.attractions);
                renderTransactions(txCard, data.recent_transactions);
            }).catch(function(err) {
                statsEl.innerHTML = '';
                statsEl.appendChild(App.el('div', { className: 'alert alert-error', textContent: 'Failed to load analytics: ' + err.message }));
            });
        }

        function loadHourly() {
            API.get('revenue/hourly').then(function(data) {
                renderHourlyChart(hourlyCard, data);
            }).catch(function() {
                hourlyCard.innerHTML = '';
                hourlyCard.appendChild(App.el('div', { className: 'text-secondary', textContent: 'Hourly data unavailable' }));
            });
        }

        function renderTopStats(el, data) {
            el.innerHTML = '';
            var fleet = data.fleet || {};
            var attr = data.attractions || {};
            var maint = data.maintenance || {};
            var parties = data.parties || {};
            var cards = [
                { label: 'Active Games', value: (fleet.enabled || 0) + '/' + (fleet.total_games || 0), color: 'var(--success)' },
                { label: 'Open Attractions', value: (attr.open || 0) + '/' + (attr.total || 0), color: 'var(--accent)' },
                { label: 'Maint. Tickets', value: String(maint.open_tickets || 0), color: (maint.critical_tickets || 0) > 0 ? 'var(--danger)' : 'var(--warning)' },
                { label: "Today's Parties", value: String(parties.today || 0), color: 'var(--accent)' },
                { label: 'Upcoming Parties', value: String(parties.upcoming || 0), color: 'var(--text-secondary)' },
            ];

            cards.forEach(function(c) {
                el.appendChild(App.el('div', { className: 'stat-card' }, [
                    App.el('div', { className: 'stat-value', textContent: c.value, style: { color: c.color } }),
                    App.el('div', { className: 'stat-label', textContent: c.label })
                ]));
            });
        }

        function renderFleetHealth(el, fleet) {
            el.innerHTML = '';
            el.appendChild(App.el('h3', { textContent: 'Game Fleet Health', style: { marginBottom: '12px' } }));

            var total = fleet.total_games || 1;
            var bars = [
                { label: 'Enabled', count: fleet.enabled, pct: (fleet.enabled / total * 100).toFixed(1), color: 'var(--success)' },
                { label: 'Paused', count: fleet.paused, pct: (fleet.paused / total * 100).toFixed(1), color: 'var(--warning)' },
                { label: 'Out of Service', count: fleet.out_of_service, pct: (fleet.out_of_service / total * 100).toFixed(1), color: 'var(--danger)' },
            ];

            bars.forEach(function(b) {
                var row = App.el('div', { style: { marginBottom: '8px' } });
                row.appendChild(App.el('div', { className: 'flex', style: { justifyContent: 'space-between', marginBottom: '2px' } }, [
                    App.el('span', { textContent: b.label }),
                    App.el('span', { className: 'text-secondary', textContent: b.count + ' (' + b.pct + '%)' })
                ]));
                var barBg = App.el('div', { className: 'progress-bar-bg' });
                var barFill = App.el('div', { className: 'progress-bar-fill', style: { width: b.pct + '%', background: b.color } });
                barBg.appendChild(barFill);
                row.appendChild(barBg);
                el.appendChild(row);
            });
        }

        function renderAttractionHealth(el, attr) {
            el.innerHTML = '';
            el.appendChild(App.el('h3', { textContent: 'Attraction Status', style: { marginBottom: '12px' } }));

            if (!attr.total) {
                el.appendChild(App.el('div', { className: 'text-secondary', textContent: 'No attractions configured yet.' }));
                return;
            }

            var total = attr.total || 1;
            var bars = [
                { label: 'Open', count: attr.open, pct: (attr.open / total * 100).toFixed(1), color: 'var(--success)' },
                { label: 'Closed', count: attr.closed, pct: (attr.closed / total * 100).toFixed(1), color: 'var(--text-muted)' },
                { label: 'Maintenance', count: attr.maintenance, pct: (attr.maintenance / total * 100).toFixed(1), color: 'var(--warning)' },
            ];

            bars.forEach(function(b) {
                var row = App.el('div', { style: { marginBottom: '8px' } });
                row.appendChild(App.el('div', { className: 'flex', style: { justifyContent: 'space-between', marginBottom: '2px' } }, [
                    App.el('span', { textContent: b.label }),
                    App.el('span', { className: 'text-secondary', textContent: b.count + ' (' + b.pct + '%)' })
                ]));
                var barBg = App.el('div', { className: 'progress-bar-bg' });
                var barFill = App.el('div', { className: 'progress-bar-fill', style: { width: b.pct + '%', background: b.color } });
                barBg.appendChild(barFill);
                row.appendChild(barBg);
                el.appendChild(row);
            });
        }

        function renderHourlyChart(el, data) {
            el.innerHTML = '';
            el.appendChild(App.el('h3', { textContent: "Today's Activity (" + (data.total_actions || 0) + ' actions)', style: { marginBottom: '12px' } }));

            var hourly = data.hourly || [];
            var maxActions = hourly.length > 0 ? Math.max.apply(null, hourly.map(function(h) { return h.actions; })) || 1 : 1;

            var chartContainer = App.el('div', { className: 'hourly-chart' });
            for (var i = 0; i < 24; i++) {
                var h = hourly[i] || { actions: 0, errors: 0 };
                var barHeight = (h.actions / maxActions * 100);
                var bar = App.el('div', { className: 'hourly-bar-container', title: i + ':00 - ' + h.actions + ' actions' }, [
                    App.el('div', { className: 'hourly-bar', style: { height: barHeight + '%', background: h.errors > 0 ? 'var(--warning)' : 'var(--accent)' } }),
                    App.el('div', { className: 'hourly-label', textContent: i % 3 === 0 ? String(i) : '' })
                ]);
                chartContainer.appendChild(bar);
            }
            el.appendChild(chartContainer);
        }

        function renderTransactions(el, transactions) {
            el.innerHTML = '';
            el.appendChild(App.el('h3', { textContent: 'Recent System Transactions', style: { marginBottom: '12px' } }));

            if (!transactions || !transactions.length) {
                el.appendChild(App.el('div', { className: 'text-secondary', textContent: 'No recent transactions available.' }));
                return;
            }

            var table = App.el('table', { className: 'data-table' });
            var thead = App.el('thead', {}, [App.el('tr', {}, [
                App.el('th', { textContent: 'Type' }),
                App.el('th', { textContent: 'Details' }),
                App.el('th', { textContent: 'Amount' }),
                App.el('th', { textContent: 'Time' }),
            ])]);
            table.appendChild(thead);
            var tbody = App.el('tbody');
            transactions.slice(0, 20).forEach(function(t) {
                var type = t.type || t.transactionType || '-';
                var details = t.description || t.details || '-';
                var amount = t.amount !== undefined ? '$' + (t.amount / 100).toFixed(2) : '-';
                var time = t.timestamp || t.created || t.dateTime || '-';
                tbody.appendChild(App.el('tr', {}, [
                    App.el('td', { textContent: type }),
                    App.el('td', { textContent: details }),
                    App.el('td', { textContent: amount }),
                    App.el('td', { textContent: time ? App.formatDatetime(time) : '-' }),
                ]));
            });
            table.appendChild(tbody);
            el.appendChild(table);
        }

        loadAll();
    }
})();
