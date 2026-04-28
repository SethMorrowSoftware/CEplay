/**
 * Card Quick-Lookup — fast staff-facing card balance and info checker.
 * Designed for kiosk-style use at the front counter.
 */
(function() {
    App.registerRoute('#/card-lookup', { render: renderCardLookup });

    function renderCardLookup(container) {
        var page = App.el('div', { className: 'page-card-lookup' });

        var header = App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h2', { textContent: 'Card Quick Lookup' }),
                App.el('p', { className: 'text-secondary', textContent: 'Scan or enter a card number to check balance and recent activity' })
            ])
        ]);
        page.appendChild(header);

        // Large search input
        var searchSection = App.el('div', { className: 'card-lookup-search' });
        var searchInput = App.el('input', {
            className: 'input card-lookup-input',
            type: 'text',
            placeholder: 'Scan card or enter card number...',
            autocomplete: 'off',
            autofocus: 'autofocus'
        });
        var searchBtn = App.el('button', { className: 'btn btn-primary btn-lg', textContent: 'Look Up', onClick: doLookup });
        searchSection.appendChild(searchInput);
        searchSection.appendChild(searchBtn);
        page.appendChild(searchSection);

        // Results area
        var results = App.el('div', { className: 'card-lookup-results' });
        page.appendChild(results);

        container.appendChild(page);

        // Enter key triggers lookup
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') doLookup();
        });

        // Auto-focus
        setTimeout(function() { searchInput.focus(); }, 100);

        function doLookup() {
            var cardNumber = searchInput.value.trim();
            if (!cardNumber) {
                App.toast('Please enter a card number', 'warning');
                return;
            }

            results.innerHTML = '';
            results.appendChild(App.loading());

            // Fetch card data and transactions in parallel
            Promise.all([
                API.get('cards/' + encodeURIComponent(cardNumber)).catch(function(e) { return { error: e.message }; }),
                API.get('cards/' + encodeURIComponent(cardNumber) + '/transactions').catch(function() { return { transactions: [] }; })
            ]).then(function(responses) {
                var cardData = responses[0];
                var txData = responses[1];

                results.innerHTML = '';

                if (cardData.error) {
                    results.appendChild(App.el('div', { className: 'card alert-card', style: { padding: '24px', textAlign: 'center' } }, [
                        App.el('div', { style: { fontSize: '2em', marginBottom: '8px' }, textContent: '\u2718' }),
                        App.el('div', { style: { fontSize: '1.2em', fontWeight: '600' }, textContent: 'Card Not Found' }),
                        App.el('div', { className: 'text-secondary', textContent: cardData.error })
                    ]));
                    return;
                }

                renderCardInfo(results, cardData, txData);
            });
        }

        function renderCardInfo(el, card, txData) {
            // Big balance display
            var balanceSection = App.el('div', { className: 'card card-balance-display', style: { padding: '24px', textAlign: 'center', marginBottom: '16px' } });

            var cardNum = card.cardNumber || card.card_number || card.number || searchInput.value;
            balanceSection.appendChild(App.el('div', { className: 'text-secondary', textContent: 'Card: ' + cardNum }));

            // Try to extract balance information
            var balanceStr = '-';
            if (card.balance !== undefined) {
                balanceStr = typeof card.balance === 'number' ? '$' + (card.balance / 100).toFixed(2) : String(card.balance);
            } else if (card.pointBalance !== undefined) {
                balanceStr = String(card.pointBalance) + ' points';
            } else if (card.creditBalance !== undefined) {
                balanceStr = String(card.creditBalance) + ' credits';
            }

            balanceSection.appendChild(App.el('div', { style: { fontSize: '3em', fontWeight: '700', color: 'var(--success)', margin: '12px 0' }, textContent: balanceStr }));

            // Status
            if (card.status || card.cardStatus) {
                var status = card.status || card.cardStatus;
                balanceSection.appendChild(App.el('span', { className: 'badge ' + (status === 'active' || status === 'Active' ? 'badge-enabled' : 'badge-paused'), textContent: status }));
            }

            // Additional card details
            var details = [];
            if (card.holderName || card.holder_name) details.push('Holder: ' + (card.holderName || card.holder_name));
            if (card.type || card.cardType) details.push('Type: ' + (card.type || card.cardType));
            if (card.expirationDate || card.expiration_date) details.push('Expires: ' + App.formatDate(card.expirationDate || card.expiration_date));
            if (card.lastUsed || card.last_used) details.push('Last used: ' + App.formatDatetime(card.lastUsed || card.last_used));

            if (details.length) {
                var detailDiv = App.el('div', { style: { marginTop: '12px' } });
                details.forEach(function(d) {
                    detailDiv.appendChild(App.el('div', { className: 'text-secondary', textContent: d }));
                });
                balanceSection.appendChild(detailDiv);
            }

            el.appendChild(balanceSection);

            // Quick actions
            var actionsBar = App.el('div', { className: 'flex gap-sm', style: { marginBottom: '16px' } }, [
                App.el('button', { className: 'btn btn-secondary', textContent: 'View Full Details', onClick: function() {
                    window.location.hash = '#/cards';
                }}),
                App.el('button', { className: 'btn btn-ghost', textContent: 'New Lookup', onClick: function() {
                    searchInput.value = '';
                    results.innerHTML = '';
                    searchInput.focus();
                }})
            ]);
            el.appendChild(actionsBar);

            // Recent transactions
            var transactions = txData.transactions || txData.items || [];
            if (transactions.length) {
                var txEl = App.el('div', { className: 'card', style: { padding: '16px' } });
                txEl.appendChild(App.el('h3', { textContent: 'Recent Transactions', style: { marginBottom: '12px' } }));

                var table = App.el('table', { className: 'data-table' });
                var thead = App.el('thead', {}, [App.el('tr', {}, [
                    App.el('th', { textContent: 'Date' }),
                    App.el('th', { textContent: 'Type' }),
                    App.el('th', { textContent: 'Description' }),
                    App.el('th', { textContent: 'Amount' }),
                ])]);
                table.appendChild(thead);
                var tbody = App.el('tbody');
                transactions.slice(0, 20).forEach(function(t) {
                    tbody.appendChild(App.el('tr', {}, [
                        App.el('td', { textContent: App.formatDatetime(t.dateTime || t.date || t.timestamp || t.created_at) }),
                        App.el('td', { textContent: t.type || t.transactionType || '-' }),
                        App.el('td', { textContent: t.description || t.details || t.gameName || '-' }),
                        App.el('td', { textContent: t.amount !== undefined ? (typeof t.amount === 'number' ? '$' + (t.amount / 100).toFixed(2) : String(t.amount)) : '-' }),
                    ]));
                });
                table.appendChild(tbody);
                txEl.appendChild(table);
                el.appendChild(txEl);
            }

            // Render all raw card properties for transparency
            var rawCard = App.el('div', { className: 'card', style: { padding: '16px', marginTop: '16px' } });
            rawCard.appendChild(App.el('h3', { textContent: 'All Card Data', style: { marginBottom: '12px' } }));
            var pre = App.el('pre', { className: 'code-block', style: { fontSize: '0.85em', maxHeight: '300px', overflow: 'auto' } });
            pre.textContent = JSON.stringify(card, null, 2);
            rawCard.appendChild(pre);
            el.appendChild(rawCard);
        }
    }
})();
