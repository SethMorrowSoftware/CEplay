/**
 * Card Lookup page — read-only customer-card inspector for floor staff.
 *
 * Capabilities surfaced:
 *   - Card balance (regular points, bonus points, redemption tickets)
 *   - Time plays remaining (with expiration)
 *   - Privileges remaining
 *   - PIN probe / validate (does the card have a PIN? optionally validate one)
 *   - Recent transaction history (paginated)
 *
 * No mutations — adjustments, wipes, combines are deferred until we have a
 * proper operator-audit story.
 */
(function() {
    App.registerRoute('#/cards', { render: renderCards });

    var TX_PAGE_SIZE = 25;
    var lastQuery = '';

    async function renderCards(container, params) {
        // Optional deep-link: `#/cards?number=80009100` pre-fills the input
        // and immediately runs a lookup. Used by the dashboard's live feed
        // to jump from a transaction straight to its card.
        var query = (params && params._query) || {};
        var prefilled = '';
        if (query.number && /^[A-Za-z0-9]{1,32}$/.test(query.number)) {
            prefilled = query.number;
        }

        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Card Lookup' }),
                App.el('p', { className: 'page-subtitle', textContent: 'Look up a card by number to see its balance, time plays, privileges, and history.' })
            ])
        ]));

        var formCard = App.el('div', { className: 'card', style: { marginBottom: '1rem' } }, [
            App.el('div', { className: 'card-body' }, [
                App.el('form', {
                    id: 'card-lookup-form',
                    className: 'flex gap-sm',
                    onSubmit: function(e) { e.preventDefault(); doLookup(); }
                }, [
                    App.el('input', {
                        id: 'card-number-input',
                        className: 'form-input',
                        type: 'text',
                        placeholder: 'Card number (e.g. 80009100)',
                        'aria-label': 'Card number',
                        autocomplete: 'off',
                        autofocus: true,
                        value: prefilled,
                        style: { flex: '1' }
                    }),
                    App.el('button', {
                        type: 'submit',
                        className: 'btn btn-primary',
                        textContent: 'Look up'
                    })
                ])
            ])
        ]);
        container.appendChild(formCard);

        var resultEl = App.el('div', { id: 'card-result' });
        container.appendChild(resultEl);

        // Auto-focus the input
        setTimeout(function() {
            var inp = document.getElementById('card-number-input');
            if (inp) inp.focus();
            if (prefilled) doLookup();
        }, 50);
    }

    async function doLookup(numberOverride) {
        var inp = document.getElementById('card-number-input');
        var resultEl = document.getElementById('card-result');
        if (!inp || !resultEl) return;

        var cardNumber = (numberOverride || inp.value || '').trim();
        if (!cardNumber) {
            App.toast('Enter a card number first.', 'warning');
            return;
        }
        if (!/^[A-Za-z0-9]{1,32}$/.test(cardNumber)) {
            App.toast('Card numbers are alphanumeric only (max 32 chars).', 'error');
            return;
        }
        if (numberOverride) inp.value = cardNumber;
        lastQuery = cardNumber;

        resultEl.innerHTML = '';
        resultEl.appendChild(App.loading());

        var gen = App.navGeneration();
        try {
            var card = await API.get('cards/' + encodeURIComponent(cardNumber));
            if (App.navGeneration() !== gen || cardNumber !== lastQuery) return;
            renderCardResult(resultEl, card);
        } catch (err) {
            if (App.navGeneration() !== gen || cardNumber !== lastQuery) return;
            resultEl.innerHTML = '';
            if (err.status === 404) {
                resultEl.appendChild(App.emptyState('?', 'Card "' + cardNumber + '" not found.'));
            } else {
                resultEl.appendChild(App.el('div', { className: 'card' }, [
                    App.el('div', { className: 'card-body' }, [
                        App.el('p', { className: 'text-secondary', textContent: 'Lookup failed: ' + err.message })
                    ])
                ]));
            }
        }
    }

    function renderCardResult(container, card) {
        container.innerHTML = '';

        // Adopt the API-canonical card number (case/leading zeros may differ
        // from what was typed) so the stale guards compare like with like.
        var cardNumber = card.cardNumber || lastQuery;
        lastQuery = cardNumber;

        var balance = card.balance || {};
        var timePlays = card.timePlays || [];
        var privileges = card.privileges || [];

        // Top summary card
        var summary = App.el('div', { className: 'card', style: { marginBottom: '1rem' } }, [
            App.el('div', { className: 'card-body' }, [
                App.el('div', { className: 'flex-between' }, [
                    App.el('div', {}, [
                        App.el('div', { className: 'card-title', textContent: 'Card ' + (cardNumber || '-') }),
                        App.el('p', { className: 'text-sm text-secondary', textContent: 'Issued ' + App.formatDatetime(card.issuedAtTime) })
                    ]),
                    App.el('div', { className: 'flex gap-sm' }, [
                        App.el('button', {
                            className: 'btn btn-sm btn-ghost',
                            textContent: 'Refresh',
                            onClick: function() { doLookup(cardNumber); }
                        }),
                        App.el('button', {
                            className: 'btn btn-sm btn-ghost',
                            textContent: 'Check PIN',
                            onClick: function() { showPinModal(cardNumber); }
                        })
                    ])
                ]),
                App.el('div', { className: 'balance-grid', style: { marginTop: '1rem' } }, [
                    balanceTile('Regular Points', balance.regularPoints),
                    balanceTile('Bonus Points', balance.bonusPoints),
                    balanceTile('Redemption Tickets', balance.redemptionTickets)
                ])
            ])
        ]);
        container.appendChild(summary);

        // Time plays
        var tpCard = App.el('div', { className: 'card', style: { marginBottom: '1rem' } }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', { className: 'card-title', textContent: 'Time plays (' + timePlays.length + ')' })
            ]),
            App.el('div', { className: 'card-body' }, [
                timePlays.length === 0
                    ? App.el('p', { className: 'text-sm text-secondary', textContent: 'No time plays on this card.' })
                    : App.el('ul', { className: 'plain-list' }, timePlays.map(buildTimePlayItem))
            ])
        ]);
        container.appendChild(tpCard);

        // Privileges
        var prCard = App.el('div', { className: 'card', style: { marginBottom: '1rem' } }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', { className: 'card-title', textContent: 'Privileges (' + privileges.length + ')' })
            ]),
            App.el('div', { className: 'card-body' }, [
                privileges.length === 0
                    ? App.el('p', { className: 'text-sm text-secondary', textContent: 'No active privileges.' })
                    : App.el('ul', { className: 'plain-list' }, privileges.map(buildPrivilegeItem))
            ])
        ]);
        container.appendChild(prCard);

        // Transaction history
        var txCard = App.el('div', { className: 'card' }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', { className: 'card-title', textContent: 'Recent transactions' })
            ]),
            App.el('div', { className: 'card-body', id: 'card-tx-body' }, [
                App.loading()
            ])
        ]);
        container.appendChild(txCard);

        loadTransactions(cardNumber, 0);
    }

    function balanceTile(label, value) {
        var v = (value === null || value === undefined) ? 0 : value;
        return App.el('div', { className: 'balance-tile' }, [
            App.el('div', { className: 'balance-tile-value', textContent: formatPoints(v) }),
            App.el('div', { className: 'balance-tile-label', textContent: label })
        ]);
    }

    function formatPoints(n) {
        if (typeof n !== 'number') n = parseFloat(n) || 0;
        // Whole numbers stay integer; fractional values keep 2 decimals.
        return n % 1 === 0 ? String(n.toFixed(0)) : n.toFixed(2);
    }

    function buildTimePlayItem(tp) {
        var label = '';
        if (tp.type === 'minutes') {
            label = (tp.minutesRemaining !== undefined ? tp.minutesRemaining : tp.minutes || 0) + ' minutes remaining';
        } else if (tp.type === 'dateTimeRange') {
            label = 'Active ' + App.formatDatetime(tp.start) + ' – ' + App.formatDatetime(tp.end);
        } else {
            label = tp.type ? 'Time play (' + tp.type + ')' : 'Time play';
        }
        // groupName is resolved server-side from /timePlayGroups when the
        // card system supports it (e.g. "All Games — 45 minutes remaining").
        if (tp.groupName) label = tp.groupName + ' — ' + label;
        var meta = [];
        if (tp.groupId !== undefined) meta.push('Group ' + tp.groupId);
        if (tp.expirationDateTime) meta.push('Expires ' + App.formatDatetime(tp.expirationDateTime));
        if (tp.suppressTickets) meta.push('Tickets suppressed');

        return App.el('li', { className: 'plain-list-item' }, [
            App.el('div', { className: 'plain-list-title', textContent: label }),
            meta.length ? App.el('div', { className: 'text-sm text-secondary', textContent: meta.join('  •  ') }) : null
        ].filter(Boolean));
    }

    function buildPrivilegeItem(p) {
        // Prefer the resolved group name ("3x Go-Kart Ride") over the raw ID.
        var label = p.groupName
            ? (p.count || 0) + 'x ' + p.groupName
            : (p.count || 0) + 'x privilege (group ' + (p.groupId !== undefined ? p.groupId : '?') + ')';
        var meta = [];
        if (p.groupName && p.groupId !== undefined) meta.push('Group ' + p.groupId);
        if (p.expirationDateTime) meta.push('Expires ' + App.formatDatetime(p.expirationDateTime));
        return App.el('li', { className: 'plain-list-item' }, [
            App.el('div', { className: 'plain-list-title', textContent: label }),
            meta.length ? App.el('div', { className: 'text-sm text-secondary', textContent: meta.join('  •  ') }) : null
        ].filter(Boolean));
    }

    async function loadTransactions(cardNumber, skip) {
        var body = document.getElementById('card-tx-body');
        if (!body) return;

        // Bail out if the user has navigated to another card mid-flight.
        if (cardNumber !== lastQuery) return;

        body.innerHTML = '';
        body.appendChild(App.loading());

        var gen = App.navGeneration();
        try {
            var data = await API.get('cards/' + encodeURIComponent(cardNumber)
                + '/transactions?skip=' + skip + '&take=' + TX_PAGE_SIZE);
            if (App.navGeneration() !== gen || cardNumber !== lastQuery) return;

            body.innerHTML = '';
            var txs = data.transactions || [];
            var total = typeof data.totalCount === 'number' ? data.totalCount : null;
            var skipped = typeof data.skipped === 'number' ? data.skipped : skip;

            if (txs.length === 0) {
                if (skip === 0) {
                    body.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'No transactions on this card.' }));
                } else {
                    // Walked one page past the end (total unknown, previous
                    // page was exactly full) — offer the way back.
                    body.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'No more transactions.' }));
                    body.appendChild(buildTxPager(cardNumber, skip, 'End of history', false));
                }
                return;
            }

            var table = App.el('table', { className: 'data-table' }, [
                App.el('thead', {}, [
                    App.el('tr', {}, [
                        App.el('th', { textContent: 'Time' }),
                        App.el('th', { textContent: 'Type' }),
                        App.el('th', { textContent: 'Detail' }),
                        App.el('th', { className: 'text-right', textContent: 'Points' }),
                        App.el('th', { className: 'text-right', textContent: 'Tickets' })
                    ])
                ]),
                App.el('tbody', {}, txs.map(renderTxRow))
            ]);
            body.appendChild(App.el('div', { className: 'table-scroll' }, [table]));

            // Pagination: the shared bar needs a known total for its page
            // math; CenterEdge doesn't always report one, so fall back to a
            // Previous/Next bar in the same pagination-bar dress when null.
            if (total !== null) {
                var pagerState = { page: Math.floor(skip / TX_PAGE_SIZE) + 1, pageSize: TX_PAGE_SIZE, totalItems: total };
                body.appendChild(App.buildPaginationBar(pagerState, function() {
                    loadTransactions(cardNumber, (pagerState.page - 1) * TX_PAGE_SIZE);
                }, { itemLabel: 'transactions', pageSizeOptions: [TX_PAGE_SIZE] }));
            } else {
                var pageInfo = 'Showing ' + (skipped + 1) + '–' + (skipped + txs.length);
                body.appendChild(buildTxPager(cardNumber, skip, pageInfo, txs.length === TX_PAGE_SIZE));
            }

        } catch (err) {
            if (App.navGeneration() !== gen || cardNumber !== lastQuery) return;
            body.innerHTML = '';
            body.appendChild(App.el('p', { className: 'text-secondary', textContent: 'Failed to load transactions: ' + err.message }));
        }
    }

    function buildTxPager(cardNumber, skip, infoText, hasNext) {
        return App.el('div', { className: 'pagination-bar' }, [
            App.el('div', { className: 'pagination-info' }, [
                App.el('span', { textContent: infoText })
            ]),
            App.el('div', { className: 'pagination-controls' }, [
                App.el('button', {
                    className: 'btn btn-ghost btn-sm',
                    textContent: 'Previous',
                    disabled: skip <= 0,
                    onClick: function() { loadTransactions(cardNumber, Math.max(0, skip - TX_PAGE_SIZE)); }
                }),
                App.el('button', {
                    className: 'btn btn-ghost btn-sm',
                    textContent: 'Next',
                    disabled: !hasNext,
                    onClick: function() { loadTransactions(cardNumber, skip + TX_PAGE_SIZE); }
                })
            ])
        ]);
    }

    function renderTxRow(tx) {
        var typeLabel = tx.type === 'gamePlay' ? 'Play'
            : tx.type === 'adjustment' ? 'Adjustment'
            : tx.type || '-';

        var detailLines = [];
        if (tx.type === 'gamePlay') {
            if (tx.gameDescription) detailLines.push(tx.gameDescription);
            else if (tx.gameId) detailLines.push('Game ' + tx.gameId);
            if (tx.usedTimePlay) detailLines.push('via time play');
            if (tx.usedPlayPrivilege) detailLines.push('via privilege');
        } else if (tx.type === 'adjustment') {
            (tx.adjustments || []).forEach(function(a) {
                detailLines.push(adjustmentLabel(a));
            });
            if ((tx.amountPaid || 0) > 0) {
                detailLines.push('Paid: $' + Number(tx.amountPaid).toFixed(2));
            }
            // Who performed it — CenterEdge includes the operator on
            // adjustments when the card system persisted it. Answers
            // "who added this value" without leaving the page.
            if (tx.operator && (tx.operator.employeeName || tx.operator.stationName)) {
                var op = 'by ' + (tx.operator.employeeName || 'staff');
                if (tx.operator.stationName) op += ' @ ' + tx.operator.stationName;
                detailLines.push(op);
            }
        }

        var amount = tx.amount || {};
        var points = (amount.regularPoints || 0) + (amount.bonusPoints || 0);
        var tickets = amount.redemptionTickets || 0;

        // For game plays, points are spent (negative); for adjustments we show
        // the underlying adjustment lines so the magnitude isn't misleading.
        var pointsLabel = tx.type === 'gamePlay' && points > 0 ? '-' + formatPoints(points) : (points ? formatPoints(points) : '-');
        var ticketsLabel = tickets ? ((tx.type === 'gamePlay' ? '+' : '') + formatPoints(tickets)) : '-';

        return App.el('tr', {}, [
            App.el('td', { textContent: App.formatDatetime(tx.transactionTime) }),
            App.el('td', {}, [
                App.el('span', { className: 'badge badge-info', textContent: typeLabel })
            ]),
            App.el('td', { textContent: detailLines.length ? detailLines.join(' • ') : '-' }),
            App.el('td', { className: 'text-right', textContent: pointsLabel }),
            App.el('td', { className: 'text-right', textContent: ticketsLabel })
        ]);
    }

    function adjustmentLabel(a) {
        var t = a.type || '';
        if (t === 'addValue') {
            var amt = a.amount || {};
            var parts = [];
            if (amt.regularPoints) parts.push('+' + formatPoints(amt.regularPoints) + ' pts');
            if (amt.bonusPoints) parts.push('+' + formatPoints(amt.bonusPoints) + ' bonus');
            if (amt.redemptionTickets) parts.push('+' + formatPoints(amt.redemptionTickets) + ' tickets');
            return 'Add value: ' + (parts.length ? parts.join(', ') : '?');
        }
        if (t === 'removeValue') return 'Remove value';
        if (t === 'addMinutes') return 'Add ' + (a.minuteCount || '?') + ' minutes (group ' + (a.groupId !== undefined ? a.groupId : '?') + ')';
        if (t === 'removeMinutes') return 'Remove ' + (a.minuteCount || '?') + ' minutes';
        if (t === 'addDateTimeRange') return 'Add time-play range';
        if (t === 'removeDateTimeRange') return 'Remove time-play range';
        if (t === 'addPrivilege') return 'Add privilege (group ' + (a.groupId !== undefined ? a.groupId : '?') + ')';
        if (t === 'removePrivilege') return 'Remove privilege';
        return t || 'adjustment';
    }

    function showPinModal(cardNumber) {
        var probe = App.el('div', { id: 'pin-probe' }, [App.loading()]);
        var pinInput = App.el('input', {
            type: 'password',
            inputmode: 'numeric',
            autocomplete: 'off',
            className: 'form-input',
            placeholder: 'PIN to validate',
            'aria-label': 'PIN to validate',
            id: 'pin-validate-input',
            style: { width: '100%', marginTop: '0.75rem' },
            onKeydown: function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    doPinValidate(cardNumber);
                }
            }
        });
        var validateResult = App.el('div', { id: 'pin-validate-result', style: { marginTop: '0.5rem' } });

        var body = App.el('div', {}, [
            App.el('p', { className: 'text-sm text-secondary', textContent: 'Card ' + cardNumber }),
            probe,
            pinInput,
            validateResult
        ]);

        var footer = App.el('div', { className: 'flex gap-sm' }, [
            App.el('button', {
                className: 'btn btn-secondary',
                textContent: 'Close',
                onClick: function() { App.hideModal(); }
            }),
            App.el('button', {
                className: 'btn btn-primary',
                textContent: 'Validate PIN',
                onClick: function() { doPinValidate(cardNumber); }
            })
        ]);

        App.showModal('PIN check', body, footer);
        // Probe whether a PIN exists at all
        doPinProbe(cardNumber);
    }

    async function doPinProbe(cardNumber) {
        var probe = document.getElementById('pin-probe');
        if (!probe) return;
        try {
            var result = await API.get('cards/' + encodeURIComponent(cardNumber) + '/pin');
            probe.innerHTML = '';
            // When no validate query is supplied, isPinValid is always false
            // but the 200 response confirms a PIN exists. The 404 path below
            // handles "card has no PIN".
            probe.appendChild(App.el('p', {
                className: 'text-sm',
                textContent: 'This card has a PIN. Enter it below to validate.'
            }));
        } catch (err) {
            probe.innerHTML = '';
            if (err.status === 404) {
                probe.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'This card has no PIN configured.' }));
            } else {
                probe.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Probe failed: ' + err.message }));
            }
        }
    }

    async function doPinValidate(cardNumber) {
        var inp = document.getElementById('pin-validate-input');
        var resultEl = document.getElementById('pin-validate-result');
        if (!inp || !resultEl) return;

        var pin = (inp.value || '').trim();
        if (!pin) {
            resultEl.innerHTML = '';
            resultEl.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Enter a PIN first.' }));
            return;
        }

        resultEl.innerHTML = '';
        resultEl.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Checking…' }));

        try {
            var result = await API.get('cards/' + encodeURIComponent(cardNumber) + '/pin?validate=' + encodeURIComponent(pin));
            resultEl.innerHTML = '';
            if (result && result.isPinValid) {
                resultEl.appendChild(App.el('p', { className: 'badge badge-enabled', textContent: 'PIN matches.' }));
            } else {
                resultEl.appendChild(App.el('p', { className: 'badge badge-paused', textContent: 'PIN does not match.' }));
            }
        } catch (err) {
            resultEl.innerHTML = '';
            if (err.status === 404) {
                resultEl.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'No PIN on this card.' }));
            } else {
                resultEl.appendChild(App.el('p', { className: 'text-sm text-secondary', textContent: 'Validation failed: ' + err.message }));
            }
        }
    }
})();
