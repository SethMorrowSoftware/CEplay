/**
 * Cards Management page — lookup, create, wipe, combine, bulk issue, PIN validation.
 */
(function() {
    App.registerRoute('#/cards', { render: renderCards });

    function renderCards(container) {
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('h1', { className: 'page-title', textContent: 'Card Management' }),
            App.el('p', { className: 'text-muted', textContent: 'Look up cards, manage balances, issue cards, and more.' })
        ]));

        // Card Lookup Section
        container.appendChild(buildCardLookup());

        // Quick Actions
        container.appendChild(buildQuickActions());

        // Card Details Display (populated after lookup)
        const detailsContainer = App.el('div', { id: 'card-details-container' });
        container.appendChild(detailsContainer);
    }

    function buildCardLookup() {
        const card = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        card.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'Card Lookup' })
        ]));

        const body = App.el('div', { className: 'card-body' });

        const searchRow = App.el('div', { className: 'flex gap-sm', style: { alignItems: 'flex-end' } });

        const inputGroup = App.el('div', { className: 'form-group', style: { flex: '1', marginBottom: '0' } });
        inputGroup.appendChild(App.el('label', { className: 'form-label', textContent: 'Card Number' }));
        const cardInput = App.el('input', {
            className: 'form-input', type: 'text', id: 'card-number-input',
            placeholder: 'Enter card number (6-20 characters)',
            maxlength: '20'
        });
        cardInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') lookupCard();
        });
        inputGroup.appendChild(cardInput);
        searchRow.appendChild(inputGroup);

        searchRow.appendChild(App.el('button', {
            className: 'btn btn-primary', textContent: 'Look Up',
            style: { marginBottom: '0' },
            onClick: lookupCard
        }));

        body.appendChild(searchRow);
        card.appendChild(body);
        return card;
    }

    function buildQuickActions() {
        const card = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        card.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'Quick Actions' })
        ]));

        const body = App.el('div', { className: 'card-body' });
        const btnRow = App.el('div', { className: 'flex gap-sm', style: { flexWrap: 'wrap' } });

        btnRow.appendChild(App.el('button', {
            className: 'btn btn-secondary', textContent: 'Create Empty Card',
            onClick: showCreateCardForm
        }));
        btnRow.appendChild(App.el('button', {
            className: 'btn btn-secondary', textContent: 'Combine Cards',
            onClick: showCombineForm
        }));
        btnRow.appendChild(App.el('button', {
            className: 'btn btn-secondary', textContent: 'Bulk Issue',
            onClick: showBulkIssueForm
        }));
        btnRow.appendChild(App.el('button', {
            className: 'btn btn-secondary', textContent: 'Validate PIN',
            onClick: showPinValidation
        }));

        body.appendChild(btnRow);
        card.appendChild(body);
        return card;
    }

    async function lookupCard() {
        const input = document.getElementById('card-number-input');
        const cardNumber = (input ? input.value : '').trim();
        if (!cardNumber) {
            App.toast('Please enter a card number.', 'error');
            return;
        }

        const container = document.getElementById('card-details-container');
        if (!container) return;
        container.innerHTML = '';
        container.appendChild(App.loading());

        try {
            const card = await API.get('cards/' + encodeURIComponent(cardNumber));
            container.innerHTML = '';
            container.appendChild(buildCardDetails(card));
        } catch (err) {
            container.innerHTML = '';
            if (err.status === 404) {
                container.appendChild(App.emptyState('?', 'Card "' + cardNumber + '" not found. It may not exist or has been wiped.'));
            } else {
                App.toast(err.message, 'error');
            }
        }
    }

    function buildCardDetails(card) {
        const wrapper = App.el('div');

        // Card Info
        const infoCard = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        infoCard.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'Card: ' + card.cardNumber }),
            App.el('div', { className: 'flex gap-sm' }, [
                App.el('button', {
                    className: 'btn btn-secondary btn-sm', textContent: 'View Transactions',
                    onClick: function() { loadCardTransactions(card.cardNumber); }
                }),
                App.el('button', {
                    className: 'btn btn-secondary btn-sm', textContent: 'Add Value',
                    onClick: function() { showAddValueForm(card.cardNumber); }
                }),
                App.el('button', {
                    className: 'btn btn-danger btn-sm', textContent: 'Wipe Card',
                    onClick: function() { wipeCard(card.cardNumber); }
                })
            ])
        ]));

        const body = App.el('div', { className: 'card-body' });

        // Basic info grid
        const grid = App.el('div', { className: 'info-grid' });

        grid.appendChild(infoItem('Card Number', card.cardNumber));
        grid.appendChild(infoItem('Issued At', card.issuedAtTime ? App.formatDatetime(card.issuedAtTime) : 'N/A'));

        // Balance
        if (card.balance) {
            const b = card.balance;
            if (b.regularPoints !== undefined) grid.appendChild(infoItem('Regular Points', formatNumber(b.regularPoints)));
            if (b.bonusPoints !== undefined && b.bonusPoints > 0) grid.appendChild(infoItem('Bonus Points', formatNumber(b.bonusPoints)));
            if (b.redemptionTickets !== undefined && b.redemptionTickets > 0) grid.appendChild(infoItem('Tickets', formatNumber(b.redemptionTickets)));
        }

        body.appendChild(grid);

        // Time Plays
        if (card.timePlays && card.timePlays.length > 0) {
            body.appendChild(App.el('h4', { textContent: 'Time Plays', style: { margin: '1rem 0 0.5rem' } }));
            const tpTable = App.el('table', { className: 'table' });
            const thead = App.el('thead');
            thead.appendChild(App.el('tr', {}, [
                App.el('th', { textContent: 'Type' }),
                App.el('th', { textContent: 'Group ID' }),
                App.el('th', { textContent: 'Details' }),
                App.el('th', { textContent: 'Expires' })
            ]));
            tpTable.appendChild(thead);

            const tbody = App.el('tbody');
            card.timePlays.forEach(function(tp) {
                var details = '';
                if (tp.type === 'minutes') {
                    details = (tp.started ? 'Started' : 'Not started') + ' — ' + tp.minutesRemaining + ' min remaining';
                } else if (tp.type === 'dateTimeRange') {
                    details = App.formatDatetime(tp.start) + ' to ' + App.formatDatetime(tp.end);
                }
                tbody.appendChild(App.el('tr', {}, [
                    App.el('td', {}, [App.el('span', { className: 'badge badge-info', textContent: tp.type })]),
                    App.el('td', { textContent: String(tp.groupId) }),
                    App.el('td', { textContent: details }),
                    App.el('td', { textContent: tp.expirationDateTime ? App.formatDatetime(tp.expirationDateTime) : 'Never' })
                ]));
            });
            tpTable.appendChild(tbody);
            var tw = App.el('div', { className: 'table-responsive' });
            tw.appendChild(tpTable);
            body.appendChild(tw);
        }

        // Privileges
        if (card.privileges && card.privileges.length > 0) {
            body.appendChild(App.el('h4', { textContent: 'Privileges', style: { margin: '1rem 0 0.5rem' } }));
            const privTable = App.el('table', { className: 'table' });
            const pthead = App.el('thead');
            pthead.appendChild(App.el('tr', {}, [
                App.el('th', { textContent: 'Group ID' }),
                App.el('th', { textContent: 'Count' }),
                App.el('th', { textContent: 'Expires' })
            ]));
            privTable.appendChild(pthead);

            const ptbody = App.el('tbody');
            card.privileges.forEach(function(p) {
                ptbody.appendChild(App.el('tr', {}, [
                    App.el('td', { textContent: String(p.groupId) }),
                    App.el('td', { textContent: String(p.count) }),
                    App.el('td', { textContent: p.expirationDateTime ? App.formatDatetime(p.expirationDateTime) : 'Never' })
                ]));
            });
            privTable.appendChild(ptbody);
            var pw = App.el('div', { className: 'table-responsive' });
            pw.appendChild(privTable);
            body.appendChild(pw);
        }

        infoCard.appendChild(body);
        wrapper.appendChild(infoCard);

        // Transactions container
        wrapper.appendChild(App.el('div', { id: 'card-transactions-container' }));

        return wrapper;
    }

    async function loadCardTransactions(cardNumber) {
        const container = document.getElementById('card-transactions-container');
        if (!container) return;
        container.innerHTML = '';
        container.appendChild(App.loading());

        try {
            const result = await API.get('cards/' + encodeURIComponent(cardNumber) + '/transactions?take=50');
            container.innerHTML = '';

            const card = App.el('div', { className: 'card' });
            card.appendChild(App.el('div', { className: 'card-header' }, [
                App.el('h3', { className: 'card-title', textContent: 'Transaction History' }),
                App.el('span', { className: 'text-muted', textContent: (result.totalCount || 0) + ' total transactions' })
            ]));

            const body = App.el('div', { className: 'card-body' });
            const transactions = result.transactions || [];

            if (transactions.length === 0) {
                body.appendChild(App.el('p', { className: 'text-muted', textContent: 'No transactions found.' }));
            } else {
                const table = App.el('table', { className: 'table' });
                const thead = App.el('thead');
                thead.appendChild(App.el('tr', {}, [
                    App.el('th', { textContent: 'ID' }),
                    App.el('th', { textContent: 'Type' }),
                    App.el('th', { textContent: 'Time' }),
                    App.el('th', { textContent: 'Details' })
                ]));
                table.appendChild(thead);

                const tbody = App.el('tbody');
                transactions.forEach(function(tx) {
                    var details = '';
                    if (tx.type === 'adjustment' && tx.adjustments) {
                        details = tx.adjustments.map(function(a) {
                            return a.type + (a.amount ? ' (' + formatPoints(a.amount) + ')' : '');
                        }).join(', ');
                    } else if (tx.type === 'gamePlay') {
                        details = (tx.gameDescription || tx.gameId || 'Game') +
                            (tx.amount ? ' — ' + formatPoints(tx.amount) : '');
                    } else {
                        details = tx.type || 'Unknown';
                    }

                    var typeBadge = tx.type === 'adjustment' ? 'badge-info' :
                                    tx.type === 'gamePlay' ? 'badge-enabled' : '';

                    tbody.appendChild(App.el('tr', {}, [
                        App.el('td', { textContent: String(tx.id) }),
                        App.el('td', {}, [App.el('span', { className: 'badge ' + typeBadge, textContent: tx.type })]),
                        App.el('td', { textContent: App.formatDatetime(tx.transactionTime) }),
                        App.el('td', { textContent: details, style: { maxWidth: '300px', overflow: 'hidden', textOverflow: 'ellipsis' } })
                    ]));
                });
                table.appendChild(tbody);

                var tw = App.el('div', { className: 'table-responsive' });
                tw.appendChild(table);
                body.appendChild(tw);
            }

            card.appendChild(body);
            container.appendChild(card);
        } catch (err) {
            container.innerHTML = '';
            App.toast(err.message, 'error');
        }
    }

    function showCreateCardForm() {
        const form = App.el('div');

        const cardInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'Card number (6-20 chars)' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Card Number' }),
            cardInput
        ]));

        form.appendChild(App.el('p', { className: 'text-muted text-sm', textContent: 'Creates an empty card with no balance. Optionally add customer info below.' }));

        const firstNameInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'Optional' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'First Name' }),
            firstNameInput
        ]));

        const lastNameInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'Required if adding customer' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Last Name' }),
            lastNameInput
        ]));

        const emailInput = App.el('input', { className: 'form-input', type: 'email', placeholder: 'Optional' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Email' }),
            emailInput
        ]));

        const footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
            App.el('button', { className: 'btn btn-primary', textContent: 'Create Card', onClick: async function() {
                var cn = cardInput.value.trim();
                if (!cn || cn.length < 6) { App.toast('Card number must be at least 6 characters.', 'error'); return; }

                var payload = {};
                var ln = lastNameInput.value.trim();
                if (ln) {
                    payload.customer = {
                        id: crypto.randomUUID ? crypto.randomUUID() : '00000000-0000-0000-0000-000000000000',
                        lastName: ln
                    };
                    var fn = firstNameInput.value.trim();
                    if (fn) payload.customer.firstName = fn;
                    var em = emailInput.value.trim();
                    if (em) payload.customer.emailAddress = em;
                }

                try {
                    await API.post('cards/' + encodeURIComponent(cn), payload);
                    App.hideModal();
                    App.toast('Card created successfully.', 'success');
                    document.getElementById('card-number-input').value = cn;
                    lookupCard();
                } catch (err) { App.toast(err.message, 'error'); }
            }})
        ]);

        App.showModal('Create Empty Card', form, footer);
    }

    function showCombineForm() {
        const form = App.el('div');

        const sourceInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'Card to transfer FROM' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Source Card Number' }),
            sourceInput
        ]));

        const destInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'Card to transfer TO' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Destination Card Number' }),
            destInput
        ]));

        form.appendChild(App.el('p', { className: 'text-muted text-sm', textContent: 'All balances, time plays, and privileges will be transferred from the source card to the destination card.' }));

        const footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
            App.el('button', { className: 'btn btn-primary', textContent: 'Combine Cards', onClick: async function() {
                var src = sourceInput.value.trim();
                var dst = destInput.value.trim();
                if (!src || !dst) { App.toast('Both card numbers are required.', 'error'); return; }
                if (src === dst) { App.toast('Source and destination must be different.', 'error'); return; }

                try {
                    await API.post('cards/combine', { sourceCardNumber: src, destinationCardNumber: dst });
                    App.hideModal();
                    App.toast('Cards combined successfully.', 'success');
                    document.getElementById('card-number-input').value = dst;
                    lookupCard();
                } catch (err) { App.toast(err.message, 'error'); }
            }})
        ]);

        App.showModal('Combine Cards', form, footer);
    }

    function showBulkIssueForm() {
        const form = App.el('div');

        const typeSelect = App.el('select', { className: 'form-select', id: 'bulk-issue-type' });
        typeSelect.appendChild(App.el('option', { value: 'list', textContent: 'By Card List' }));
        typeSelect.appendChild(App.el('option', { value: 'range', textContent: 'By Card Range' }));
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Issue Type' }),
            typeSelect
        ]));

        const listGroup = App.el('div', { className: 'form-group', id: 'bulk-list-group' });
        const listInput = App.el('textarea', { className: 'form-input', rows: '4', placeholder: 'Enter card numbers, one per line' });
        listGroup.appendChild(App.el('label', { className: 'form-label', textContent: 'Card Numbers' }));
        listGroup.appendChild(listInput);
        form.appendChild(listGroup);

        const rangeGroup = App.el('div', { className: 'form-group', id: 'bulk-range-group', style: { display: 'none' } });
        const startInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'Starting card number' });
        const countInput = App.el('input', { className: 'form-input', type: 'number', min: '1', max: '1000', value: '10', placeholder: 'Number of cards' });
        rangeGroup.appendChild(App.el('label', { className: 'form-label', textContent: 'Starting Card Number' }));
        rangeGroup.appendChild(startInput);
        rangeGroup.appendChild(App.el('label', { className: 'form-label', textContent: 'Number of Cards', style: { marginTop: '0.5rem' } }));
        rangeGroup.appendChild(countInput);
        form.appendChild(rangeGroup);

        typeSelect.addEventListener('change', function() {
            listGroup.style.display = typeSelect.value === 'list' ? '' : 'none';
            rangeGroup.style.display = typeSelect.value === 'range' ? '' : 'none';
        });

        // Adjustment type
        const adjTypeSelect = App.el('select', { className: 'form-select' });
        adjTypeSelect.appendChild(App.el('option', { value: 'addValue', textContent: 'Add Value (Points)' }));
        adjTypeSelect.appendChild(App.el('option', { value: 'removeValue', textContent: 'Remove Value (Points)' }));
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Adjustment Type' }),
            adjTypeSelect
        ]));

        const pointsInput = App.el('input', { className: 'form-input', type: 'number', step: '0.01', value: '0', placeholder: 'Regular points' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Regular Points' }),
            pointsInput
        ]));

        const footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
            App.el('button', { className: 'btn btn-primary', textContent: 'Issue Cards', onClick: async function() {
                var payload = {
                    type: typeSelect.value,
                    adjustments: [{
                        type: adjTypeSelect.value,
                        amount: {
                            regularPoints: parseFloat(pointsInput.value) || 0,
                            bonusPoints: 0,
                            redemptionTickets: 0
                        }
                    }]
                };

                if (typeSelect.value === 'list') {
                    var lines = listInput.value.trim().split('\n').map(function(l) { return l.trim(); }).filter(Boolean);
                    if (lines.length === 0) { App.toast('Enter at least one card number.', 'error'); return; }
                    payload.cardNumbers = lines;
                } else {
                    var sc = startInput.value.trim();
                    var nc = parseInt(countInput.value);
                    if (!sc) { App.toast('Starting card number is required.', 'error'); return; }
                    if (!nc || nc < 1) { App.toast('Number of cards must be at least 1.', 'error'); return; }
                    payload.startingCardNumber = sc;
                    payload.numberOfCards = nc;
                }

                try {
                    var result = await API.post('cards/bulk-issue', payload);
                    App.hideModal();
                    var count = (result.cards || []).length;
                    App.toast('Successfully issued ' + count + ' card(s).', 'success');
                } catch (err) { App.toast(err.message, 'error'); }
            }})
        ]);

        App.showModal('Bulk Issue Cards', form, footer);
    }

    function showPinValidation() {
        const form = App.el('div');

        const cardInput = App.el('input', { className: 'form-input', type: 'text', placeholder: 'Card number' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Card Number' }),
            cardInput
        ]));

        const pinInput = App.el('input', { className: 'form-input', type: 'password', placeholder: 'PIN to validate' });
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'PIN' }),
            pinInput
        ]));

        const resultDiv = App.el('div', { id: 'pin-result', style: { marginTop: '0.5rem' } });
        form.appendChild(resultDiv);

        const footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
            App.el('button', { className: 'btn btn-primary', textContent: 'Validate', onClick: async function() {
                var cn = cardInput.value.trim();
                var pin = pinInput.value.trim();
                if (!cn) { App.toast('Card number is required.', 'error'); return; }

                try {
                    var url = 'cards/' + encodeURIComponent(cn) + '/pin';
                    if (pin) url += '?validate=' + encodeURIComponent(pin);
                    var result = await API.get(url);
                    resultDiv.innerHTML = '';
                    if (result.isPinValid === true) {
                        resultDiv.appendChild(App.el('span', { className: 'text-sm', textContent: 'PIN is valid.', style: { color: 'var(--success)' } }));
                    } else if (result.isPinValid === false) {
                        resultDiv.appendChild(App.el('span', { className: 'text-sm', textContent: 'PIN is invalid.', style: { color: 'var(--danger)' } }));
                    } else {
                        resultDiv.appendChild(App.el('span', { className: 'text-sm', textContent: 'PIN check result: ' + JSON.stringify(result) }));
                    }
                } catch (err) {
                    resultDiv.innerHTML = '';
                    resultDiv.appendChild(App.el('span', { className: 'text-sm', textContent: err.message, style: { color: 'var(--danger)' } }));
                }
            }})
        ]);

        App.showModal('Validate Card PIN', form, footer);
    }

    function showAddValueForm(cardNumber) {
        const form = App.el('div');

        const adjType = App.el('select', { className: 'form-select' });
        adjType.appendChild(App.el('option', { value: 'addValue', textContent: 'Add Value' }));
        adjType.appendChild(App.el('option', { value: 'removeValue', textContent: 'Remove Value' }));
        adjType.appendChild(App.el('option', { value: 'addMinutes', textContent: 'Add Time Play (Minutes)' }));
        adjType.appendChild(App.el('option', { value: 'addPrivilege', textContent: 'Add Privilege' }));
        form.appendChild(App.el('div', { className: 'form-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Adjustment Type' }),
            adjType
        ]));

        const pointsInput = App.el('input', { className: 'form-input', type: 'number', step: '0.01', value: '0' });
        const pointsGroup = App.el('div', { className: 'form-group', id: 'adj-points-group' }, [
            App.el('label', { className: 'form-label', textContent: 'Regular Points' }),
            pointsInput
        ]);
        form.appendChild(pointsGroup);

        const groupIdInput = App.el('input', { className: 'form-input', type: 'number', min: '0', value: '0' });
        const groupIdGroup = App.el('div', { className: 'form-group', style: { display: 'none' }, id: 'adj-group-id' }, [
            App.el('label', { className: 'form-label', textContent: 'Group ID' }),
            groupIdInput
        ]);
        form.appendChild(groupIdGroup);

        const minutesInput = App.el('input', { className: 'form-input', type: 'number', min: '1', value: '30' });
        const minutesGroup = App.el('div', { className: 'form-group', style: { display: 'none' }, id: 'adj-minutes' }, [
            App.el('label', { className: 'form-label', textContent: 'Minutes' }),
            minutesInput
        ]);
        form.appendChild(minutesGroup);

        const countInput = App.el('input', { className: 'form-input', type: 'number', min: '1', value: '1' });
        const countGroup = App.el('div', { className: 'form-group', style: { display: 'none' }, id: 'adj-count' }, [
            App.el('label', { className: 'form-label', textContent: 'Count' }),
            countInput
        ]);
        form.appendChild(countGroup);

        adjType.addEventListener('change', function() {
            var v = adjType.value;
            pointsGroup.style.display = (v === 'addValue' || v === 'removeValue') ? '' : 'none';
            document.getElementById('adj-group-id').style.display = (v === 'addMinutes' || v === 'addPrivilege') ? '' : 'none';
            document.getElementById('adj-minutes').style.display = v === 'addMinutes' ? '' : 'none';
            document.getElementById('adj-count').style.display = v === 'addPrivilege' ? '' : 'none';
        });

        const footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', { className: 'btn btn-secondary', textContent: 'Cancel', onClick: function() { App.hideModal(); } }),
            App.el('button', { className: 'btn btn-primary', textContent: 'Submit', onClick: async function() {
                var type = adjType.value;
                var adjustment = { type: type };

                if (type === 'addValue' || type === 'removeValue') {
                    adjustment.amount = {
                        regularPoints: parseFloat(pointsInput.value) || 0,
                        bonusPoints: 0,
                        redemptionTickets: 0
                    };
                } else if (type === 'addMinutes') {
                    adjustment.groupId = parseInt(groupIdInput.value) || 0;
                    adjustment.minutes = parseInt(minutesInput.value) || 30;
                } else if (type === 'addPrivilege') {
                    adjustment.groupId = parseInt(groupIdInput.value) || 0;
                    adjustment.count = parseInt(countInput.value) || 1;
                }

                var payload = {
                    type: 'adjustment',
                    adjustments: [adjustment]
                };

                try {
                    await API.post('cards/' + encodeURIComponent(cardNumber) + '/transactions', payload);
                    App.hideModal();
                    App.toast('Transaction created successfully.', 'success');
                    lookupCard();
                } catch (err) { App.toast(err.message, 'error'); }
            }})
        ]);

        App.showModal('Add Transaction — Card ' + cardNumber, form, footer);
    }

    async function wipeCard(cardNumber) {
        var yes = await App.confirm('Are you sure you want to WIPE card "' + cardNumber + '"? This will remove all balances, time plays, privileges, and transaction history. This cannot be undone.');
        if (!yes) return;

        try {
            await API.del('cards/' + encodeURIComponent(cardNumber));
            App.toast('Card wiped successfully.', 'success');
            var container = document.getElementById('card-details-container');
            if (container) container.innerHTML = '';
        } catch (err) {
            App.toast(err.message, 'error');
        }
    }

    function infoItem(label, value) {
        return App.el('div', { className: 'info-item' }, [
            App.el('div', { className: 'info-label', textContent: label }),
            App.el('div', { className: 'info-value', textContent: String(value) })
        ]);
    }

    function formatNumber(n) {
        if (n === undefined || n === null) return '0';
        return Number(n).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 4 });
    }

    function formatPoints(amount) {
        if (!amount) return '';
        var parts = [];
        if (amount.regularPoints) parts.push(formatNumber(amount.regularPoints) + ' pts');
        if (amount.bonusPoints) parts.push(formatNumber(amount.bonusPoints) + ' bonus');
        if (amount.redemptionTickets) parts.push(formatNumber(amount.redemptionTickets) + ' tickets');
        if (amount.cash) parts.push('$' + formatNumber(amount.cash));
        return parts.join(', ') || '0';
    }
})();
