/**
 * System Info page — Capabilities, Card Number Formats, System Transactions.
 */
(function() {
    App.registerRoute('#/system', { render: renderSystem });

    async function renderSystem(container) {
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('h1', { className: 'page-title', textContent: 'System Information' }),
            App.el('p', { className: 'text-muted', textContent: 'View CenterEdge system capabilities, card formats, and system transactions.' })
        ]));

        // Tabs
        var tabBar = App.el('div', { className: 'tab-bar', style: { marginBottom: '1.5rem' } });
        var tabs = ['Capabilities', 'Card Formats', 'System Transactions'];
        var activeTab = 'Capabilities';

        tabs.forEach(function(tab) {
            tabBar.appendChild(App.el('button', {
                className: 'btn ' + (tab === activeTab ? 'btn-primary' : 'btn-ghost') + ' btn-sm',
                textContent: tab,
                'data-tab': tab,
                onClick: function() {
                    document.querySelectorAll('.tab-bar button').forEach(function(b) { b.className = 'btn btn-ghost btn-sm'; });
                    this.className = 'btn btn-primary btn-sm';
                    switchTab(tab);
                }
            }));
        });
        container.appendChild(tabBar);

        var content = App.el('div', { id: 'system-content' });
        content.appendChild(App.loading());
        container.appendChild(content);

        loadCapabilities();
    }

    function switchTab(tab) {
        var content = document.getElementById('system-content');
        if (!content) return;
        content.innerHTML = '';
        content.appendChild(App.loading());

        switch(tab) {
            case 'Capabilities': loadCapabilities(); break;
            case 'Card Formats': loadCardFormats(); break;
            case 'System Transactions': loadSystemTransactions(); break;
        }
    }

    async function loadCapabilities() {
        var content = document.getElementById('system-content');
        if (!content) return;

        try {
            var caps = await API.get('capabilities');
            content.innerHTML = '';
            content.appendChild(buildCapabilitiesView(caps));
        } catch (err) {
            content.innerHTML = '';
            App.toast(err.message, 'error');
            content.appendChild(App.emptyState('!', 'Failed to load capabilities: ' + err.message));
        }
    }

    function buildCapabilitiesView(caps) {
        var wrapper = App.el('div');

        // System Info
        var sysCard = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        sysCard.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'System Overview' })
        ]));
        var sysBody = App.el('div', { className: 'card-body' });
        var grid = App.el('div', { className: 'info-grid' });
        grid.appendChild(infoItem('System Name', caps.systemName || 'Unknown'));
        grid.appendChild(infoItem('Interface Version', caps.interfaceVersion || 'Unknown'));
        grid.appendChild(infoItem('Wipe Card', caps.wipeCard ? 'Supported' : 'Not supported'));
        grid.appendChild(infoItem('Virtual Play', caps.virtualPlay ? 'Supported' : 'Not supported'));
        grid.appendChild(infoItem('Card Combine', caps.cardCombineToExistingCard ? 'Supported' : 'Not supported'));
        sysBody.appendChild(grid);
        sysCard.appendChild(sysBody);
        wrapper.appendChild(sysCard);

        // Point Types
        if (caps.pointTypes) {
            var ptCard = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
            ptCard.appendChild(App.el('div', { className: 'card-header' }, [
                App.el('h3', { className: 'card-title', textContent: 'Point Types' })
            ]));
            var ptBody = App.el('div', { className: 'card-body' });
            var ptGrid = App.el('div', { className: 'info-grid' });

            var ptypes = ['regularPoints', 'bonusPoints', 'redemptionTickets'];
            ptypes.forEach(function(pt) {
                var p = caps.pointTypes[pt];
                if (p) {
                    var label = pt.replace(/([A-Z])/g, ' $1').replace(/^./, function(s) { return s.toUpperCase(); });
                    ptGrid.appendChild(infoItem(label, p.isSupported ? 'Supported (max ' + (p.maximumBalance || 'unlimited') + ', ' + (p.maxDecimalPlaces || 0) + ' decimals)' : 'Not supported'));
                }
            });

            ptBody.appendChild(ptGrid);
            ptCard.appendChild(ptBody);
            wrapper.appendChild(ptCard);
        }

        // Adjustments
        if (caps.adjustments) {
            var adjCard = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
            adjCard.appendChild(App.el('div', { className: 'card-header' }, [
                App.el('h3', { className: 'card-title', textContent: 'Adjustments' })
            ]));
            var adjBody = App.el('div', { className: 'card-body' });
            var adjGrid = App.el('div', { className: 'info-grid' });
            adjGrid.appendChild(infoItem('Max Adjustments Per Transaction', caps.adjustments.maximumAdjustmentsPerTransaction || 'Unlimited'));
            if (caps.adjustments.allowedAdjustmentCombinations) {
                adjGrid.appendChild(infoItem('Allowed Combinations', JSON.stringify(caps.adjustments.allowedAdjustmentCombinations)));
            }
            adjBody.appendChild(adjGrid);
            adjCard.appendChild(adjBody);
            wrapper.appendChild(adjCard);
        }

        // Time Play
        if (caps.timePlay) {
            var tpCard = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
            tpCard.appendChild(App.el('div', { className: 'card-header' }, [
                App.el('h3', { className: 'card-title', textContent: 'Time Play' })
            ]));
            var tpBody = App.el('div', { className: 'card-body' });
            var tpGrid = App.el('div', { className: 'info-grid' });
            tpGrid.appendChild(infoItem('Max Time Plays Per Card', caps.timePlay.maximumTimePlaysPerCard || 'Unlimited'));
            tpGrid.appendChild(infoItem('Suppress Tickets', caps.timePlay.suppressTickets ? 'Supported' : 'Not supported'));
            if (caps.timePlay.minutes) {
                tpGrid.appendChild(infoItem('Minutes', caps.timePlay.minutes.isSupported ? 'Supported' : 'Not supported'));
            }
            if (caps.timePlay.dateTimeRange) {
                tpGrid.appendChild(infoItem('DateTime Range', caps.timePlay.dateTimeRange.isSupported ? 'Supported' : 'Not supported'));
            }
            tpBody.appendChild(tpGrid);
            tpCard.appendChild(tpBody);
            wrapper.appendChild(tpCard);
        }

        // Privileges
        if (caps.privileges) {
            var privCard = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
            privCard.appendChild(App.el('div', { className: 'card-header' }, [
                App.el('h3', { className: 'card-title', textContent: 'Privileges' })
            ]));
            var privBody = App.el('div', { className: 'card-body' });
            var privGrid = App.el('div', { className: 'info-grid' });
            privGrid.appendChild(infoItem('Supported', caps.privileges.isSupported ? 'Yes' : 'No'));
            privGrid.appendChild(infoItem('Can Expire', caps.privileges.canExpire ? 'Yes' : 'No'));
            privBody.appendChild(privGrid);
            privCard.appendChild(privBody);
            wrapper.appendChild(privCard);
        }

        // Bulk Issue
        if (caps.bulkIssue) {
            var biCard = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
            biCard.appendChild(App.el('div', { className: 'card-header' }, [
                App.el('h3', { className: 'card-title', textContent: 'Bulk Issue' })
            ]));
            var biBody = App.el('div', { className: 'card-body' });
            var biGrid = App.el('div', { className: 'info-grid' });
            biGrid.appendChild(infoItem('By Range', caps.bulkIssue.range ? 'Supported' : 'Not supported'));
            biGrid.appendChild(infoItem('By List', caps.bulkIssue.list ? 'Supported' : 'Not supported'));
            biGrid.appendChild(infoItem('Can Unlink Empty Cards', caps.bulkIssue.canUnlinkEmptyCards ? 'Yes' : 'No'));
            biGrid.appendChild(infoItem('Can Require New Cards', caps.bulkIssue.canRequireNewCards ? 'Yes' : 'No'));
            biBody.appendChild(biGrid);
            biCard.appendChild(biBody);
            wrapper.appendChild(biCard);
        }

        // Games
        if (caps.games) {
            var gCard = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
            gCard.appendChild(App.el('div', { className: 'card-header' }, [
                App.el('h3', { className: 'card-title', textContent: 'Games' })
            ]));
            var gBody = App.el('div', { className: 'card-body' });
            var gGrid = App.el('div', { className: 'info-grid' });
            gGrid.appendChild(infoItem('Operation Status', caps.games.operationStatus ? 'Supported' : 'Not supported'));
            gGrid.appendChild(infoItem('Categories', caps.games.categories ? 'Supported' : 'Not supported'));
            gGrid.appendChild(infoItem('Get Single Game', caps.games.getSingleGame ? 'Supported' : 'Not supported'));
            if (caps.games.transactionFeedNames) {
                gGrid.appendChild(infoItem('Transaction Feed Names', caps.games.transactionFeedNames.join(', ')));
            }
            gBody.appendChild(gGrid);
            gCard.appendChild(gBody);
            wrapper.appendChild(gCard);
        }

        // System Transaction Reporting
        if (caps.systemTransactionReporting) {
            var strCard = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
            strCard.appendChild(App.el('div', { className: 'card-header' }, [
                App.el('h3', { className: 'card-title', textContent: 'System Transaction Reporting' })
            ]));
            var strBody = App.el('div', { className: 'card-body' });
            var strGrid = App.el('div', { className: 'info-grid' });
            strGrid.appendChild(infoItem('Supported', caps.systemTransactionReporting.isSupported ? 'Yes' : 'No'));
            if (caps.systemTransactionReporting.transactionTypes) {
                strGrid.appendChild(infoItem('Transaction Types', caps.systemTransactionReporting.transactionTypes.join(', ')));
            }
            strBody.appendChild(strGrid);
            strCard.appendChild(strBody);
            wrapper.appendChild(strCard);
        }

        return wrapper;
    }

    async function loadCardFormats() {
        var content = document.getElementById('system-content');
        if (!content) return;

        try {
            var result = await API.get('capabilities/card-formats');
            content.innerHTML = '';

            var card = App.el('div', { className: 'card' });
            card.appendChild(App.el('div', { className: 'card-header' }, [
                App.el('h3', { className: 'card-title', textContent: 'Card Number Formats' }),
                App.el('span', { className: 'badge', textContent: (result.totalCount || 0) + ' formats' })
            ]));

            var body = App.el('div', { className: 'card-body' });
            var formats = result.formats || [];

            if (formats.length === 0) {
                body.appendChild(App.el('p', { className: 'text-muted', textContent: 'No card number formats defined.' }));
            } else {
                var table = App.el('table', { className: 'table' });
                var thead = App.el('thead');
                thead.appendChild(App.el('tr', {}, [
                    App.el('th', { textContent: 'Min Length' }),
                    App.el('th', { textContent: 'Max Length' }),
                    App.el('th', { textContent: 'Prefix' }),
                    App.el('th', { textContent: 'Suffix' }),
                    App.el('th', { textContent: 'Regex' })
                ]));
                table.appendChild(thead);

                var tbody = App.el('tbody');
                formats.forEach(function(f) {
                    tbody.appendChild(App.el('tr', {}, [
                        App.el('td', { textContent: String(f.minLength || '-') }),
                        App.el('td', { textContent: String(f.maxLength || '-') }),
                        App.el('td', {}, [App.el('code', { textContent: f.prefix || '-' })]),
                        App.el('td', {}, [App.el('code', { textContent: f.suffix || '-' })]),
                        App.el('td', {}, [App.el('code', { textContent: f.regex || '-' })])
                    ]));
                });
                table.appendChild(tbody);

                var tw = App.el('div', { className: 'table-responsive' });
                tw.appendChild(table);
                body.appendChild(tw);
            }

            card.appendChild(body);
            content.appendChild(card);
        } catch (err) {
            content.innerHTML = '';
            App.toast(err.message, 'error');
            content.appendChild(App.emptyState('!', 'Failed to load card formats: ' + err.message));
        }
    }

    async function loadSystemTransactions() {
        var content = document.getElementById('system-content');
        if (!content) return;
        content.innerHTML = '';

        var card = App.el('div', { className: 'card' });
        card.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'System Transactions' })
        ]));

        var body = App.el('div', { className: 'card-body' });

        // Controls
        var controlRow = App.el('div', { className: 'flex gap-sm', style: { marginBottom: '1rem', flexWrap: 'wrap', alignItems: 'flex-end' } });

        var sinceInput = App.el('input', { className: 'form-input', type: 'text', value: '0', placeholder: 'Since ID', style: { maxWidth: '200px' } });
        controlRow.appendChild(App.el('div', { className: 'form-group', style: { marginBottom: '0' } }, [
            App.el('label', { className: 'form-label', textContent: 'Since ID' }),
            sinceInput
        ]));

        var mergeCheck = App.el('input', { type: 'checkbox', checked: true, id: 'sys-tx-merge' });
        controlRow.appendChild(App.el('label', { className: 'flex gap-sm', style: { alignItems: 'center' } }, [
            mergeCheck, App.el('span', { textContent: 'Merge' })
        ]));

        var expCheck = App.el('input', { type: 'checkbox', checked: true, id: 'sys-tx-exp' });
        controlRow.appendChild(App.el('label', { className: 'flex gap-sm', style: { alignItems: 'center' } }, [
            expCheck, App.el('span', { textContent: 'Expiration' })
        ]));

        var fetchBtn = App.el('button', { className: 'btn btn-primary btn-sm', textContent: 'Fetch' });
        controlRow.appendChild(fetchBtn);

        body.appendChild(controlRow);

        var resultsDiv = App.el('div', { id: 'sys-tx-results' });
        body.appendChild(resultsDiv);

        card.appendChild(body);
        content.appendChild(card);

        fetchBtn.addEventListener('click', async function() {
            var types = [];
            if (mergeCheck.checked) types.push('merge');
            if (expCheck.checked) types.push('expiration');
            if (types.length === 0) { App.toast('Select at least one type.', 'error'); return; }

            resultsDiv.innerHTML = '';
            resultsDiv.appendChild(App.loading());

            try {
                var params = 'sinceId=' + encodeURIComponent(sinceInput.value || '0');
                types.forEach(function(t) { params += '&type=' + encodeURIComponent(t); });
                var result = await API.get('system/transactions?' + params);
                resultsDiv.innerHTML = '';

                if (result.sinceId) sinceInput.value = result.sinceId;

                var txs = result.transactions || [];
                if (txs.length === 0) {
                    resultsDiv.appendChild(App.el('p', { className: 'text-muted', textContent: 'No system transactions found.' }));
                } else {
                    var table = App.el('table', { className: 'table' });
                    var thead = App.el('thead');
                    thead.appendChild(App.el('tr', {}, [
                        App.el('th', { textContent: 'ID' }),
                        App.el('th', { textContent: 'Type' }),
                        App.el('th', { textContent: 'Time' }),
                        App.el('th', { textContent: 'Details' })
                    ]));
                    table.appendChild(thead);

                    var tbody = App.el('tbody');
                    txs.forEach(function(tx) {
                        var details = '';
                        if (tx.type === 'merge') {
                            details = 'Source: ' + (tx.sourceCardNumber || '?') + ' -> Dest: ' + (tx.destinationCardNumber || '?');
                        } else if (tx.type === 'expiration') {
                            details = 'Card: ' + (tx.cardNumber || '?') + (tx.isWiped ? ' (Wiped)' : '');
                            if (tx.adjustments && tx.adjustments.length > 0) {
                                details += ' — ' + tx.adjustments.length + ' adjustment(s)';
                            }
                        }

                        tbody.appendChild(App.el('tr', {}, [
                            App.el('td', { textContent: String(tx.id) }),
                            App.el('td', {}, [App.el('span', { className: 'badge ' + (tx.type === 'merge' ? 'badge-info' : 'badge-warning'), textContent: tx.type })]),
                            App.el('td', { textContent: App.formatDatetime(tx.transactionTime) }),
                            App.el('td', { textContent: details })
                        ]));
                    });
                    table.appendChild(tbody);

                    var tw = App.el('div', { className: 'table-responsive' });
                    tw.appendChild(table);
                    resultsDiv.appendChild(tw);
                }
            } catch (err) {
                resultsDiv.innerHTML = '';
                App.toast(err.message, 'error');
            }
        });
    }

    function infoItem(label, value) {
        return App.el('div', { className: 'info-item' }, [
            App.el('div', { className: 'info-label', textContent: label }),
            App.el('div', { className: 'info-value', textContent: String(value !== undefined && value !== null ? value : '-') })
        ]);
    }
})();
