/**
 * Analytics page — KPI dashboard, leaderboards and charts powered by the
 * cached game-play feed and action_log. Charts via Chart.js (CDN).
 *
 * Pulls a single /api/analytics/overview payload per refresh, then renders:
 *   - 8 KPI cards with trend deltas vs the previous equivalent period
 *   - Fleet posture (games / kiosks / groups / overrides / retries)
 *   - Daily plays + tickets trend (line)
 *   - Plays by hour of day (bar)  &  Plays by day of week (bar)
 *   - Top games by plays / by tickets (horizontal bars)
 *   - Reader CC / points value mix and Pause-action source breakdown (donuts)
 *   - Top groups by automation activity (horizontal bar)
 *   - Recent automation failures (table)
 *
 * Refresh: visibility-aware, default every 60 s. Re-renders charts on theme
 * toggle so axis/grid colors flip cleanly between light/dark.
 */
(function() {
    App.registerRoute('#/analytics', { render: renderAnalytics });

    var REFRESH_MS = 60000;
    var DAYS_SHORT = App.DAYS_SHORT;
    var SOURCE_LABEL = {
        cron: 'Daily plan',
        manual: 'Manual',
        override: 'Override',
        schedule: 'Schedule',
        watchdog: 'Watchdog',
        expired_override: 'Expired override'
    };

    var RANGE_LABELS = { day: 'Day', week: 'Week', month: 'Month', year: 'Year', custom: 'Custom' };

    // Module state — reset on every render() call.
    var state;

    function freshState() {
        return {
            range: 'week',   // Day/Week/Month/Year/Custom — shared with the other reporting pages
            offset: 0,       // 0 = current period, negative = past
            custom: { from: '', to: '' },
            includeTimePlays: true, // toggle: count time-pass plays
            overview: null,
            charts: [],
            refreshCleanup: null,
            themeObserver: null,
            inflight: null
        };
    }

    // ------------------------------------------------------------------
    // Render
    // ------------------------------------------------------------------
    async function renderAnalytics(container) {
        state = freshState();

        container.appendChild(buildHeader());
        container.appendChild(buildControls());
        // Banner shown while the time-pass toggle is excluding plays, so a
        // screenshot or glance can never be misread as the full picture.
        container.appendChild(App.el('div', {
            id: 'analytics-timeplay-note',
            className: 'analytics-timeplay-note',
            style: { display: 'none' }
        }));
        // Banner shown when the range reaches past the ~30-day detailed feed and
        // the page falls back to the venue-wide ledger rollup (deep history).
        container.appendChild(App.el('div', {
            id: 'analytics-deep-note',
            className: 'analytics-deep-note',
            style: { display: 'none' }
        }));
        // Plain-language headlines land here on every load.
        container.appendChild(App.el('div', { id: 'analytics-insights' }));
        container.appendChild(buildKpiSection());
        container.appendChild(buildGuestSection());
        container.appendChild(buildFleetSection());
        container.appendChild(buildChartsSection());
        container.appendChild(buildBottomSection());

        // First paint: skeleton already in place; fetch then re-render.
        await loadAndRender();

        // Visibility-aware polling
        state.refreshCleanup = App.createVisibilityAwareInterval(
            function() { loadAndRender(false); },
            REFRESH_MS,
            { runImmediately: false, runOnVisible: true }
        );

        // Theme observer — re-paint charts when the theme attribute flips.
        state.themeObserver = new MutationObserver(function(records) {
            for (var i = 0; i < records.length; i++) {
                if (records[i].attributeName === 'data-theme') {
                    repaintCharts();
                    break;
                }
            }
        });
        state.themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-theme']
        });

        return cleanup;
    }

    function cleanup() {
        if (!state) return;
        if (state.refreshCleanup) { try { state.refreshCleanup(); } catch (e) {} }
        if (state.themeObserver) { try { state.themeObserver.disconnect(); } catch (e) {} }
        if (state.inflight && typeof state.inflight.abort === 'function') {
            try { state.inflight.abort(); } catch (e) {}
        }
        destroyCharts();
        state = null;
    }

    // ------------------------------------------------------------------
    // Layout scaffolding
    // ------------------------------------------------------------------
    function buildHeader() {
        var refreshBtn = App.el('button', {
            className: 'btn btn-secondary btn-sm',
            textContent: 'Refresh',
            title: 'Refresh now',
            onClick: function() { loadAndRender(); }
        });

        var lastUpdated = App.el('span', {
            id: 'analytics-last-updated',
            className: 'text-muted text-sm',
            style: { minWidth: '7.5rem', textAlign: 'right' }
        });

        var actions = App.el('div', { className: 'flex gap-sm', style: { alignItems: 'center', flexWrap: 'wrap' } }, [
            refreshBtn,
            lastUpdated
        ]);

        return App.el('div', { className: 'page-header' }, [
            App.el('div', {}, [
                App.el('h1', { className: 'page-title', textContent: 'Analytics' }),
                App.el('p', { className: 'page-subtitle', textContent: 'How the venue is doing — plays, tickets, guests and payments over the range you pick. The cards up top are the headlines; the charts below are the detail.' })
            ]),
            actions
        ]);
    }

    // ------------------------------------------------------------------
    // Range controls — the SAME top bar as the Performance / Reader Groups /
    // Labor / Card Loads pages (Day / Week / Month / Year / Custom + prev-next
    // navigation), so every reporting page feels like one product.
    // ------------------------------------------------------------------
    function buildControls() {
        var presetRow = App.el('div', { className: 'perf-range-presets', id: 'analytics-presets' },
            Object.keys(RANGE_LABELS).map(function(key) {
                return App.el('button', {
                    className: 'btn btn-sm ' + (key === state.range ? 'btn-primary' : 'btn-ghost'),
                    textContent: RANGE_LABELS[key],
                    onClick: function() {
                        if (state.range === key && key !== 'custom') return;
                        state.range = key;
                        state.offset = 0;
                        refreshPresetButtons();
                        toggleCustomRow();
                        if (key !== 'custom') loadAndRender();
                    }
                });
            })
        );

        var nav = App.el('div', { className: 'perf-nav', id: 'analytics-nav' }, [
            App.el('button', {
                className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '‹',
                title: 'Previous period', 'aria-label': 'Previous period',
                onClick: function() { if (state.range === 'custom') return; state.offset -= 1; loadAndRender(); }
            }),
            App.el('div', { className: 'perf-nav-label', id: 'analytics-nav-label', textContent: '…' }),
            App.el('button', {
                className: 'btn btn-sm btn-ghost perf-nav-btn', textContent: '›',
                title: 'Next period', 'aria-label': 'Next period',
                onClick: function() { if (state.range === 'custom' || state.offset >= 0) return; state.offset += 1; loadAndRender(); }
            }),
            App.el('button', {
                className: 'btn btn-sm btn-ghost', textContent: 'Today',
                title: 'Jump to the current period',
                onClick: function() { if (state.offset === 0) return; state.offset = 0; loadAndRender(); }
            })
        ]);

        var custom = App.el('div', { className: 'perf-custom', id: 'analytics-custom',
            style: { display: state.range === 'custom' ? '' : 'none' } }, [
            App.el('label', { className: 'text-sm text-secondary', textContent: 'From' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'analytics-custom-from', value: state.custom.from }),
            App.el('label', { className: 'text-sm text-secondary', textContent: 'To' }),
            App.el('input', { type: 'date', className: 'form-input form-input-sm', id: 'analytics-custom-to', value: state.custom.to }),
            App.el('button', {
                className: 'btn btn-sm btn-primary', textContent: 'Apply',
                onClick: function() {
                    var from = document.getElementById('analytics-custom-from').value;
                    var to = document.getElementById('analytics-custom-to').value;
                    if (!from || !to) { App.toast('Pick both a start and end date.', 'warning'); return; }
                    if (from > to) { App.toast('"From" must be on or before "To".', 'warning'); return; }
                    state.custom.from = from;
                    state.custom.to = to;
                    loadAndRender();
                }
            })
        ]);

        // Include/exclude time-pass plays across the whole page. On this page
        // the exclusion is exact and total: an excluded play's tickets/points/
        // payments drop out with it (each raw transaction carries the flag), so
        // the owner sees the venue as if pass traffic never happened.
        var timeToggleInput = App.el('input', { className: 'toggle-input', type: 'checkbox', checked: state.includeTimePlays });
        timeToggleInput.addEventListener('change', function() {
            state.includeTimePlays = timeToggleInput.checked;
            var note = document.getElementById('analytics-timeplay-note');
            if (note) note.style.display = state.includeTimePlays ? 'none' : '';
            loadAndRender();
        });
        var timeToggle = App.el('label', {
            className: 'toggle-label',
            title: 'Time-pass plays are games started on an unlimited-play time pass. Switch off to see every number on this page without them — the excluded plays’ tickets, points, and payments drop out too.'
        }, [
            timeToggleInput,
            App.el('span', { className: 'toggle-switch' }),
            App.el('span', { textContent: 'Time-pass plays' })
        ]);

        return App.el('div', { className: 'card perf-controls' }, [
            App.el('div', { className: 'card-body perf-controls-body' }, [presetRow, nav, custom, timeToggle])
        ]);
    }

    function refreshPresetButtons() {
        var wrap = document.getElementById('analytics-presets');
        if (!wrap) return;
        var keys = Object.keys(RANGE_LABELS);
        Array.prototype.forEach.call(wrap.children, function(btn, i) {
            btn.className = 'btn btn-sm ' + (keys[i] === state.range ? 'btn-primary' : 'btn-ghost');
        });
    }

    function toggleCustomRow() {
        var custom = document.getElementById('analytics-custom');
        var nav = document.getElementById('analytics-nav');
        if (custom) custom.style.display = state.range === 'custom' ? '' : 'none';
        if (nav) nav.style.display = state.range === 'custom' ? 'none' : '';
    }

    function buildKpiSection() {
        // Reader CC payments and avg reader CC/play are monetary — hidden
        // from the 'tech' role, which sees plays / tickets / points only.
        // "Reader CC payments" (not "revenue"): the play feed only carries
        // card-at-reader charges, not POS sales, so calling it revenue
        // oversells what it measures.
        var canSeeMoney = App.canSeeMoney();
        var cards = [
            kpiCardSkeleton('plays', 'Plays', 'Total game plays recorded in this period'),
            kpiCardSkeleton('tickets', 'Tickets dispensed', 'Redemption tickets games paid out in this period')
        ];
        if (canSeeMoney) cards.push(kpiCardSkeleton('cash', 'Reader CC payments', 'Credit-card dollars paid at the game readers — sales at the front counter are not included'));
        cards.push(kpiCardSkeleton('points', 'Points charged', 'Card points games charged for plays in this period'));
        cards.push(kpiCardSkeleton('avg_tickets', 'Avg tickets / play', 'Average tickets dispensed per play'));
        if (canSeeMoney) cards.push(kpiCardSkeleton('avg_cash', 'Avg reader CC / play', 'Average card-at-reader payment per play. With time-pass plays excluded this usually reads HIGHER — free pass plays no longer dilute the average.'));
        cards.push(kpiCardSkeleton('unique_cards', 'Unique cards', 'Distinct play cards used in this period — a rough guest count'));
        cards.push(kpiCardSkeleton('credit_card_share', 'Credit-card plays', 'Plays paid by tapping a bank card at the reader instead of a play card'));
        // Breakage — value expired off cards by the card system itself.
        // Points/tickets, not dollars, so visible to every analytics role.
        cards.push(kpiCardSkeleton('expired', 'Expired value', 'Points and tickets that expired off guest cards (breakage)'));
        return App.el('div', { className: 'stats-grid', id: 'analytics-kpis' }, cards);
    }

    /**
     * Banner shown while time-pass plays are excluded. Besides stating the
     * mode, it pre-empts the classic misreading: totals DROP, but per-play /
     * per-visit averages usually read HIGHER, because free pass plays no
     * longer dilute the denominator — that's arithmetic, not a bug.
     */
    function renderTimePlayNote(data) {
        var note = document.getElementById('analytics-timeplay-note');
        if (!note) return;
        if (!data.exclude_time_plays) {
            note.style.display = 'none';
            return;
        }
        var n = data.excluded_time_plays;
        note.textContent = '⏱ ' + (n != null ? formatInt(n) + ' time-pass plays' : 'Time-pass plays')
            + ' excluded — totals on this page reflect non-pass traffic only (an excluded play’s tickets, points, and payments drop out with it). '
            + 'Heads-up: per-play and per-visit averages often read HIGHER in this view, because free pass plays no longer dilute them.';
        note.style.display = '';
    }

    /**
     * True when the requested range reaches past the ~30-day detailed feed and
     * the server answered from the venue-wide ledger rollup instead. In this
     * mode the headline plays/tickets/play-value are REAL and deep, but every
     * recent-only breakdown (hour-of-day, top games, payment mix, guests, …) is
     * null — so each renderer hides its panel and says why.
     */
    function isDeep(data) {
        return !!(data && data.history && data.history.active);
    }

    /**
     * Banner explaining deep-history mode + the exact coverage window, so a
     * long range never silently looks like a full-detail one. Play-value is
     * defined here once (it's a broader figure than the recent "Reader CC
     * payments" card, so we name the difference).
     */
    function renderDeepNote(data) {
        var note = document.getElementById('analytics-deep-note');
        if (!note) return;
        if (!isDeep(data)) { note.style.display = 'none'; note.textContent = ''; return; }
        var h = data.history;
        var since = h.since ? formatShortDate(h.since) : null;
        var through = h.through ? formatShortDate(h.through) : null;
        var recentSince = h.recent_metrics_since ? formatShortDate(h.recent_metrics_since) : null;
        var span = (since && through) ? (since + ' – ' + through) : 'the selected range';
        var msg = '📚 Historical view — this range reaches past the detailed feed we keep (about the last 30 days). '
            + 'The headline plays, tickets and play value below come straight from the card-system ledger, covering ' + span + '. '
            + 'Detailed breakdowns — by hour, by game, category, payment mix and guest insights — are only kept for the recent window'
            + (recentSince ? ' (since ' + recentSince + ')' : '')
            + ', so they’re hidden for this longer range. Pick a shorter range to see them.';
        note.textContent = msg;
        note.style.display = '';
    }

    /**
     * The headlines: four plain-language takeaways computed from data the
     * payload already carries, so a non-technical reader gets the story
     * before the first chart. Each card degrades away when its data is
     * absent (short ranges, hidden money, uncovered guest history).
     */
    function renderInsights(data) {
        if (isDeep(data)) { renderInsightsDeep(data); return; }
        var box = document.getElementById('analytics-insights');
        if (!box) return;
        box.innerHTML = '';
        var cards = [];

        var k = data.kpis || {};
        var pk = data.previous_kpis || {};
        if (k.plays != null) {
            var sub = 'game plays this period';
            var cls = 'insight-accent';
            if (pk.plays) {
                var chg = (k.plays - pk.plays) / pk.plays * 100;
                // One decimal, matching the KPI tiles below ("+1.7% vs prior").
                var chgTxt = Math.abs(chg).toFixed(1).replace(/\.0$/, '');
                sub = (chg >= 0 ? 'up ' : 'down ') + chgTxt + '% vs the previous period';
                cls = chg >= 0 ? 'insight-good' : 'insight-warn';
            }
            cards.push(insightCard('🎮', cls, 'Plays', Number(k.plays).toLocaleString(), sub));
        }

        var daily = (data.charts && data.charts.daily) || [];
        if (daily.length > 1) {
            var best = daily.reduce(function(a, b) { return (b.plays || 0) > (a.plays || 0) ? b : a; });
            if (best && best.date) {
                var d = new Date(best.date + 'T12:00:00');
                cards.push(insightCard('📅', 'insight-heat', 'Busiest day',
                    d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }),
                    Number(best.plays || 0).toLocaleString() + ' plays that day'));
            }
        }

        var top = (data.charts && data.charts.top_games_plays && data.charts.top_games_plays[0]) || null;
        if (top && top.game_name) {
            cards.push(insightCard('🏆', 'insight-accent', 'Most played game',
                top.game_name, Number(top.plays || 0).toLocaleString() + ' plays'));
        }

        var g = data.guests || {};
        if (g.classification_covered && g.returning_guests != null && (g.new_guests || g.returning_guests)) {
            var totalG = (g.new_guests || 0) + (g.returning_guests || 0);
            var retPct = totalG > 0 ? Math.round(g.returning_guests / totalG * 100) : 0;
            cards.push(insightCard('👥', 'insight-quiet', 'Returning guests',
                retPct + '%', 'of ' + totalG.toLocaleString() + ' carded guests had visited before'));
        } else {
            var hours = (data.charts && data.charts.plays_by_hour) || [];
            var maxH = -1, maxV = 0;
            hours.forEach(function(v, i) { if (v > maxV) { maxV = v; maxH = i; } });
            if (maxH >= 0 && maxV > 0) {
                var lbl = (maxH % 12 === 0 ? 12 : maxH % 12) + (maxH < 12 ? ' AM' : ' PM');
                cards.push(insightCard('⏰', 'insight-quiet', 'Busiest hour', lbl,
                    Number(maxV).toLocaleString() + ' plays in that hour across the period'));
            }
        }

        if (cards.length) box.appendChild(App.el('div', { className: 'insight-row' }, cards));
    }

    /**
     * Deep-history headlines built from the ledger rollup: total plays (with
     * prior-period delta), the busiest month/day in the trend, total tickets,
     * and — for money roles — total play value. No busiest-hour / most-played-
     * game / guest cards here; those need the recent detailed feed.
     */
    function renderInsightsDeep(data) {
        var box = document.getElementById('analytics-insights');
        if (!box) return;
        box.innerHTML = '';
        var h = data.history || {};
        var cards = [];

        if (h.plays != null) {
            var sub = 'game plays over this range';
            var cls = 'insight-accent';
            if (h.prev_plays) {
                var chg = (h.plays - h.prev_plays) / h.prev_plays * 100;
                var chgTxt = Math.abs(chg).toFixed(1).replace(/\.0$/, '');
                sub = (chg >= 0 ? 'up ' : 'down ') + chgTxt + '% vs the previous period';
                cls = chg >= 0 ? 'insight-good' : 'insight-warn';
            }
            cards.push(insightCard('🎮', cls, 'Plays', Number(h.plays).toLocaleString(), sub));
        }

        var trend = h.trend || [];
        if (trend.length > 1) {
            var best = trend.reduce(function(a, b) { return (b.plays || 0) > (a.plays || 0) ? b : a; });
            if (best) {
                var isMonth = h.granularity === 'month';
                var label = isMonth ? formatMonthLabel(best.month) : formatShortDate(best.date);
                cards.push(insightCard('📅', 'insight-heat', isMonth ? 'Busiest month' : 'Busiest day',
                    label, Number(best.plays || 0).toLocaleString() + ' plays'));
            }
        }

        if (h.tickets != null) {
            cards.push(insightCard('🎟️', 'insight-quiet', 'Tickets earned',
                Number(Math.round(h.tickets)).toLocaleString(), 'redemption tickets over this range'));
        }

        if (App.canSeeMoney() && h.value != null) {
            cards.push(insightCard('💵', 'insight-accent', 'Play value',
                formatCurrency(h.value), 'spent at the game readers over this range'));
        }

        if (cards.length) box.appendChild(App.el('div', { className: 'insight-row' }, cards));
    }

    function insightCard(emoji, cls, label, value, sub) {
        return App.el('div', { className: 'insight-card ' + cls }, [
            App.el('div', { className: 'insight-icon', 'aria-hidden': 'true', textContent: emoji }),
            App.el('div', { className: 'insight-body' }, [
                App.el('div', { className: 'insight-label', textContent: label }),
                App.el('div', { className: 'insight-value', textContent: value }),
                App.el('div', { className: 'insight-sub', textContent: sub })
            ])
        ]);
    }

    function kpiCardSkeleton(key, label, tip) {
        return App.el('div', { className: 'stat-card analytics-kpi', 'data-kpi': key, title: tip || '' }, [
            App.el('div', { className: 'stat-label', textContent: label }),
            App.el('div', { className: 'stat-value', 'data-role': 'value', textContent: '—' }),
            App.el('div', { className: 'stat-trend', 'data-role': 'trend' })
        ]);
    }

    // ------------------------------------------------------------------
    // Guest Insights — new vs returning, visit frequency, spend per visit
    // ------------------------------------------------------------------
    function buildGuestSection() {
        return App.el('div', { className: 'card guest-insights', style: { marginBottom: '1.5rem' } }, [
            App.el('div', { className: 'card-header' }, [
                App.el('div', {}, [
                    App.el('div', { className: 'card-title', textContent: 'Guest insights' }),
                    App.el('div', { className: 'text-muted text-sm',
                        textContent: 'New vs returning guests, visit frequency and reader CC spend — carded plays only (a “visit” is one day a card was active).' })
                ])
            ]),
            App.el('div', { className: 'card-body', id: 'analytics-guests-body' }, [
                App.el('div', { className: 'text-muted text-sm', textContent: 'Loading…' })
            ])
        ]);
    }

    function renderGuests(data) {
        var body = document.getElementById('analytics-guests-body');
        if (!body) return;
        var g = data.guests || {};
        var canSeeMoney = App.canSeeMoney();
        body.textContent = '';

        // Deep history: guest detail needs the per-transaction card list, which
        // only exists for the recent detailed feed — say so plainly.
        if (isDeep(data)) {
            var rs = data.history && data.history.recent_metrics_since;
            body.appendChild(App.el('div', { className: 'text-muted text-sm',
                textContent: 'Guest insights (new vs returning, visit frequency and reader CC spend) are only kept for '
                    + 'about the last 30 days' + (rs ? ' (since ' + formatShortDate(rs) + ')' : '')
                    + ', so they’re not available for this longer range. Pick a shorter range to see them.' }));
            return;
        }

        var total = g.total_guests || 0;
        if (total === 0) {
            body.appendChild(App.el('div', { className: 'text-muted text-sm',
                textContent: 'No carded guest activity in this range. (Credit-card and cardless plays aren’t counted as guests.)' }));
            return;
        }

        // New-vs-returning proportion bar with legend — but only when we have
        // visit history reaching back before the window starts. On a range
        // longer than our tracked history, every guest looks "new" (their
        // first visit predates our records), which made returning counts
        // collapse and a 90-day window show fewer returning guests than a
        // 7-day one. In that case we suppress the split and say why instead of
        // showing a misleading number.
        var head = App.el('div', { className: 'guest-split-head' }, [
            App.el('span', { className: 'stat-label', textContent: formatInt(total) + ' unique guests' })
        ]);
        var splitChildren = [head];

        if (g.classification_covered) {
            var newCount = g.new_guests || 0;
            var retCount = g.returning_guests || 0;
            var newPct = g.new_pct != null ? g.new_pct : 0;
            var retPct = g.returning_pct != null ? g.returning_pct : 0;

            head.appendChild(App.el('div', { className: 'guest-split-legend' }, [
                App.el('span', { className: 'guest-legend-item' }, [
                    App.el('span', { className: 'guest-dot guest-dot-new' }),
                    App.el('strong', { textContent: formatInt(newCount) }),
                    App.el('span', { className: 'text-muted', textContent: ' new (' + newPct + '%)' })
                ]),
                App.el('span', { className: 'guest-legend-item' }, [
                    App.el('span', { className: 'guest-dot guest-dot-ret' }),
                    App.el('strong', { textContent: formatInt(retCount) }),
                    App.el('span', { className: 'text-muted', textContent: ' returning (' + retPct + '%)' })
                ])
            ]));
            splitChildren.push(App.el('div', { className: 'guest-split-bar', 'aria-hidden': 'true' }, [
                App.el('div', { className: 'guest-split-seg guest-split-new',
                    style: { width: Math.max(0, newPct) + '%' } }),
                App.el('div', { className: 'guest-split-seg guest-split-ret',
                    style: { width: Math.max(0, retPct) + '%' } })
            ]));
        } else {
            var since = g.history_since ? formatShortDate(g.history_since) : null;
            var msg = since
                ? 'New vs returning needs about a full range of visit history before the range to be reliable, and we’ve only been tracking since ' + since + '. Try a shorter range, or check back as history builds.'
                : 'New vs returning appears once enough visit history has been tracked.';
            splitChildren.push(App.el('div', { className: 'text-muted text-sm', style: { marginTop: '0.35rem' },
                textContent: msg }));
        }
        body.appendChild(App.el('div', { className: 'guest-split' }, splitChildren));

        // If the range reaches past the retained raw feed (~30 days), the
        // per-guest metrics only cover what's retained — say so.
        if (g.metrics_since && g.window_from && g.metrics_since > g.window_from) {
            body.appendChild(App.el('div', { className: 'text-muted text-xs', style: { marginTop: '-0.5rem' },
                textContent: 'Guest detail below reflects plays since ' + formatShortDate(g.metrics_since) + ' (about the last 30 days are kept in detail).' }));
        }

        // Headline tiles.
        var tiles = [
            guestTile('Repeat-visit rate', pct(g.repeat_rate), 'Guests who came back more than once'),
            guestTile('Avg visits / guest', (g.avg_visits != null ? g.avg_visits.toFixed(2) : '—'), formatInt(g.total_visits || 0) + ' total visits'),
            guestTile('Attach rate', pct(g.attach_rate), 'Guests with a card payment at a reader')
        ];
        if (canSeeMoney) {
            var perVisitSub = data.exclude_time_plays
                ? 'Pass plays excluded — often reads higher, since pass-only visits leave the average'
                : 'Card-at-reader payments on carded plays';
            tiles.push(guestTile('Reader CC / visit', formatCurrency(g.spend_per_visit), perVisitSub));
            tiles.push(guestTile('Reader CC / guest', formatCurrency(g.spend_per_guest), formatCurrency(g.total_spend) + ' total'));
        }
        body.appendChild(App.el('div', { className: 'guest-tile-grid' }, tiles));

        // Visit-frequency distribution.
        var f = g.frequency || {};
        var freqDefs = [
            { label: '1 visit', v: f.one || 0 },
            { label: '2 visits', v: f.two || 0 },
            { label: '3–4 visits', v: f.three_four || 0 },
            { label: '5+ visits', v: f.five_plus || 0 }
        ];
        var maxFreq = freqDefs.reduce(function(m, d) { return Math.max(m, d.v); }, 0) || 1;
        var freqRows = freqDefs.map(function(d) {
            var share = total > 0 ? Math.round(d.v / total * 100) : 0;
            return App.el('div', { className: 'guest-freq-row' }, [
                App.el('span', { className: 'guest-freq-label', textContent: d.label }),
                App.el('span', { className: 'guest-freq-track' }, [
                    App.el('span', { className: 'guest-freq-fill', style: { width: (d.v / maxFreq * 100) + '%' } })
                ]),
                App.el('span', { className: 'guest-freq-val', textContent: formatInt(d.v) + ' · ' + share + '%' })
            ]);
        });
        body.appendChild(App.el('div', { className: 'guest-freq' }, [
            App.el('div', { className: 'stat-label', style: { marginBottom: '0.5rem' }, textContent: 'Visit frequency' })
        ].concat(freqRows)));
    }

    function guestTile(label, value, hint) {
        return App.el('div', { className: 'guest-tile' }, [
            App.el('div', { className: 'stat-label', textContent: label }),
            App.el('div', { className: 'guest-tile-value', textContent: value }),
            App.el('div', { className: 'text-muted text-sm', textContent: hint })
        ]);
    }

    function pct(v) {
        return v != null ? v + '%' : '—';
    }

    function buildFleetSection() {
        var section = App.el('div', { className: 'analytics-fleet-grid', id: 'analytics-fleet' }, [
            fleetTileSkeleton('games', 'Games', '#/games', 'Open the games directory'),
            fleetTileSkeleton('kiosks', 'Kiosks', '#/kiosks', 'Open the kiosks page'),
            fleetTileSkeleton('groups', 'Pause groups', '#/groups', 'Open pause groups'),
            fleetTileSkeleton('overrides', 'Active overrides', '#/overrides', 'Open the overrides page'),
            fleetTileSkeleton('retries', 'Pending retries', '#/logs', 'Open the action log to inspect retries')
        ]);
        return section;
    }

    function fleetTileSkeleton(key, label, href, title) {
        var tile = App.el('div', { className: 'analytics-fleet-tile', 'data-fleet': key }, [
            App.el('div', { className: 'analytics-fleet-label', textContent: label }),
            App.el('div', { className: 'analytics-fleet-value', 'data-role': 'value', textContent: '—' }),
            App.el('div', { className: 'analytics-fleet-detail', 'data-role': 'detail' })
        ]);
        if (href) {
            App.makeCardLink(tile, href, { title: title || ('Open ' + label.toLowerCase()) });
        }
        return tile;
    }

    function buildChartsSection() {
        var grid = App.el('div', { className: 'analytics-grid' });
        var canSeeMoney = App.canSeeMoney();

        grid.appendChild(chartCard('Daily activity', 'analytics-chart-daily', 'analytics-card-wide', 260,
            'Each bar is one day — taller means a busier day'));
        grid.appendChild(chartCard('Plays by hour of day', 'analytics-chart-hour', '', 220,
            'When guests are actually playing — useful for staffing'));
        grid.appendChild(chartCard('Plays by day of week', 'analytics-chart-dow', '', 220,
            'Which days of the week do best over this range'));
        grid.appendChild(chartCard('Top games — plays', 'analytics-chart-top-plays', '', 280,
            'The games guests choose most'));
        grid.appendChild(chartCard('Top games — tickets', 'analytics-chart-top-tickets', '', 280,
            'The games paying out the most tickets'));
        grid.appendChild(chartCard('Category share — plays', 'analytics-chart-cat-share', '', 240,
            'Where play happens by game type — games in multiple categories count in each'));
        grid.appendChild(chartCard('Tickets by category', 'analytics-chart-cat-tickets', '', 240,
            'Which game types hand out the tickets'));
        grid.appendChild(chartCard('Payment mix — plays', 'analytics-chart-payment-mix', '', 240,
            'How guests pay at the reader — mixed-payment plays count in each method'));
        // Brand data is payment info — server sends it only with view_revenue.
        if (canSeeMoney) {
            grid.appendChild(chartCard('Credit-card brands', 'analytics-chart-cc-brands', '', 240,
                'Which cards guests tap at the readers'));
        }
        // The value-mix donut surfaces reader CC totals — hidden from tech.
        if (canSeeMoney) {
            grid.appendChild(chartCard('Value mix — reader CC / points', 'analytics-chart-revenue', '', 240,
                'Card dollars vs game-card points spent at the readers'));
        }
        grid.appendChild(chartCard('Top groups by automation', 'analytics-chart-top-groups', 'analytics-card-wide', 240,
            'The pause groups the automation works hardest on'));

        return grid;
    }

    function chartCard(title, canvasId, extraClass, height, subtitle) {
        var canvas = App.el('canvas', { id: canvasId });
        // Chart.js sizes from the parent's height; lock min-height for layout.
        var box = App.el('div', { className: 'analytics-chart-box', style: { height: (height || 220) + 'px' } }, [canvas]);
        // Title + optional subtitle stack in one column so a long title
        // never wraps around a subtitle pushed to the far edge.
        var titleBlock = [App.el('div', { className: 'card-title', textContent: title })];
        if (subtitle) {
            titleBlock.push(App.el('div', { className: 'text-muted text-sm', textContent: subtitle }));
        }
        return App.el('div', { className: 'card analytics-card ' + (extraClass || '') }, [
            App.el('div', { className: 'analytics-card-header' }, [
                App.el('div', {}, titleBlock)
            ]),
            box
        ]);
    }

    function buildBottomSection() {
        var wrap = App.el('div', {});

        var box = App.el('div', { className: 'card analytics-card', style: { marginTop: '1rem' } });
        box.appendChild(App.el('div', { className: 'analytics-card-header' }, [
            App.el('div', { className: 'card-title', textContent: 'Recent automation failures' }),
            App.el('div', { className: 'text-muted text-sm', textContent: 'Last 10 — system-wide' })
        ]));
        var tableWrap = App.el('div', { id: 'analytics-failures', className: 'analytics-failures' });
        tableWrap.appendChild(App.loading());
        box.appendChild(tableWrap);
        wrap.appendChild(box);

        // Card-system events: merges + value expirations from the
        // /system/transactions feed (breakage detail behind the KPI).
        var sysBox = App.el('div', { className: 'card analytics-card', style: { marginTop: '1rem' } });
        sysBox.appendChild(App.el('div', { className: 'analytics-card-header' }, [
            App.el('div', { className: 'card-title', textContent: 'Card system events' }),
            App.el('div', { className: 'text-muted text-sm', textContent: 'Last 10 merges & expirations' })
        ]));
        var sysWrap = App.el('div', { id: 'analytics-system-events' });
        sysWrap.appendChild(App.loading());
        sysBox.appendChild(sysWrap);
        wrap.appendChild(sysBox);

        return wrap;
    }

    function renderSystemEvents(data) {
        var box = document.getElementById('analytics-system-events');
        if (!box) return;
        box.innerHTML = '';

        if (isDeep(data)) {
            box.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'Card-system events are only kept for the recent detailed window — pick a shorter range to see them.' }));
            return;
        }
        if (data.system_tx_supported === false) {
            box.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'This card system does not report system transactions.' }));
            return;
        }
        var rows = (data.charts && data.charts.system_events) || [];
        if (rows.length === 0) {
            box.appendChild(App.el('p', { className: 'text-sm text-secondary',
                textContent: 'No merges or expirations recorded yet.' }));
            return;
        }

        var table = App.el('table', { className: 'data-table' }, [
            App.el('thead', {}, [
                App.el('tr', {}, [
                    App.el('th', { textContent: 'Time' }),
                    App.el('th', { textContent: 'Event' }),
                    App.el('th', { textContent: 'Detail' })
                ])
            ])
        ]);
        var tbody = App.el('tbody', {});
        rows.forEach(function(r) {
            var detail;
            if (r.type === 'merge') {
                detail = 'Card ' + (r.source_card || '?') + ' → ' + (r.destination_card || '?');
            } else {
                var parts = [];
                if (r.expired_points) parts.push(formatInt(Math.round(r.expired_points)) + ' pts');
                if (r.expired_tickets) parts.push(formatInt(Math.round(r.expired_tickets)) + ' tix');
                detail = 'Card ' + (r.card_number || '?')
                    + (parts.length ? ' — ' + parts.join(', ') + ' expired' : '')
                    + (r.is_wiped ? ' · wiped' : '');
            }
            tbody.appendChild(App.el('tr', {}, [
                App.el('td', { textContent: App.formatDatetime(r.transaction_time) }),
                App.el('td', {}, [
                    App.el('span', {
                        className: r.type === 'merge' ? 'badge badge-info' : 'badge badge-paused',
                        textContent: r.type === 'merge' ? 'Merge' : 'Expiration'
                    })
                ]),
                App.el('td', { textContent: detail })
            ]));
        });
        table.appendChild(tbody);
        box.appendChild(table);
    }

    // ------------------------------------------------------------------
    // Data load + render
    // ------------------------------------------------------------------
    async function loadAndRender(showSpinner) {
        if (showSpinner === undefined) showSpinner = true;
        var gen = App.navGeneration();

        var qs = 'range=' + encodeURIComponent(state.range) + '&offset=' + encodeURIComponent(state.offset);
        if (state.range === 'custom') {
            if (!state.custom.from || !state.custom.to) return; // wait for Apply
            qs += '&from=' + encodeURIComponent(state.custom.from) + '&to=' + encodeURIComponent(state.custom.to);
        }
        if (!state.includeTimePlays) qs += '&exclude_time_plays=1';

        var lastUpdatedEl = document.getElementById('analytics-last-updated');
        if (lastUpdatedEl && showSpinner) lastUpdatedEl.textContent = 'Loading…';

        try {
            var data = await API.get('analytics/overview?' + qs);
            if (App.navGeneration() !== gen) return; // user navigated away
            state.overview = data;
            var navLabel = document.getElementById('analytics-nav-label');
            if (navLabel && data.window && data.window.label) navLabel.textContent = data.window.label;
            renderTimePlayNote(data);
            renderDeepNote(data);
            renderInsights(data);
            renderKpis(data);
            renderGuests(data);
            renderFleet(data);
            renderCharts(data);
            renderFailures(data);
            renderSystemEvents(data);
            if (lastUpdatedEl) {
                var d = new Date();
                lastUpdatedEl.textContent = 'Updated ' + d.toLocaleTimeString();
            }
        } catch (err) {
            if (App.navGeneration() !== gen) return;
            console.error('Analytics load failed:', err);
            if (lastUpdatedEl) lastUpdatedEl.textContent = 'Load failed';
            App.toast('Failed to load analytics: ' + (err && err.message ? err.message : 'unknown error'), 'error');
        }
    }

    // ------------------------------------------------------------------
    // KPI cards
    // ------------------------------------------------------------------
    // KPI cards that only make sense on the recent detailed feed — hidden in
    // deep-history mode (the ledger rollup can't rebuild them accurately).
    var DEEP_HIDDEN_KPIS = ['points', 'avg_cash', 'unique_cards', 'credit_card_share', 'expired'];

    function kpiCardEl(key) {
        return document.querySelector('.analytics-kpi[data-kpi="' + key + '"]');
    }
    function kpiSetVisible(key, show) {
        var c = kpiCardEl(key);
        if (c) c.style.display = show ? '' : 'none';
    }
    function kpiSetLabel(key, label, tip) {
        var c = kpiCardEl(key);
        if (!c) return;
        var lbl = c.querySelector('.stat-label');
        if (lbl) lbl.textContent = label;
        if (tip != null) c.title = tip;
    }

    function renderKpis(data) {
        if (isDeep(data)) { renderKpisDeep(data); return; }

        var k = data.kpis || {};
        var p = data.previous_kpis || {};
        var canSeeMoney = App.canSeeMoney();

        // Restore the normal layout (a previous deep render may have hidden
        // cards / relabeled the money card).
        DEEP_HIDDEN_KPIS.forEach(function(key) { kpiSetVisible(key, true); });
        kpiSetVisible('avg_tickets', true);
        if (canSeeMoney) {
            kpiSetLabel('cash', 'Reader CC payments',
                'Credit-card dollars paid at the game readers — sales at the front counter are not included');
        }

        kpiUpdate('plays',           formatInt(k.plays),                       deltaText(k.plays, p.plays));
        kpiUpdate('tickets',         formatInt(Math.round(k.tickets || 0)),    deltaText(k.tickets, p.tickets));
        if (canSeeMoney) {
            kpiUpdate('cash',        formatCurrency(k.cash),                   deltaText(k.cash, p.cash));
        }
        kpiUpdate('points',          formatInt(Math.round(k.points || 0)),     deltaText(k.points, p.points));
        kpiUpdate('avg_tickets',     (k.avg_tickets_per_play || 0).toFixed(2), deltaText(k.avg_tickets_per_play, p.avg_tickets_per_play));
        if (canSeeMoney) {
            kpiUpdate('avg_cash',    formatCurrency(k.avg_cash_per_play),      deltaText(k.avg_cash_per_play, p.avg_cash_per_play));
        }
        kpiUpdate('unique_cards',    formatInt(k.unique_cards),                deltaText(k.unique_cards, p.unique_cards));

        var cc = k.credit_card_plays || 0;
        var totalPlays = (k.plays || 0);
        var ccShare = totalPlays > 0 ? Math.round((cc / totalPlays) * 100) : 0;
        kpiUpdate('credit_card_share', formatInt(cc),
            ccShare + '% of plays · ' + (k.card_plays || 0).toLocaleString() + ' on cards');

        // Breakage KPI from the system-transaction feed.
        if (data.system_tx_supported === false) {
            kpiUpdate('expired', '—', 'Not reported by this card system');
        } else {
            var expDetail = deltaText(k.expired_points || 0, p.expired_points || 0);
            if ((k.expired_tickets || 0) > 0 || (k.merges || 0) > 0) {
                var mergeCount = k.merges || 0;
                expDetail = formatInt(Math.round(k.expired_tickets || 0)) + ' tix expired · '
                    + formatInt(mergeCount) + (mergeCount === 1 ? ' merge' : ' merges');
            }
            kpiUpdate('expired', formatInt(Math.round(k.expired_points || 0)) + ' pts', expDetail);
        }
    }

    /**
     * Deep-history KPIs: only the three the ledger rollup can report accurately
     * over a long range — Plays, Tickets earned, and (money roles) Play value —
     * plus Avg tickets/play, which is just tickets ÷ plays. Every recent-only
     * card is hidden. Play value is a broader figure than the recent "Reader CC
     * payments" card, so the money card is relabeled and re-tipped to say so.
     */
    function renderKpisDeep(data) {
        var h = data.history || {};
        var canSeeMoney = App.canSeeMoney();

        DEEP_HIDDEN_KPIS.forEach(function(key) { kpiSetVisible(key, false); });
        kpiSetVisible('plays', true);
        kpiSetVisible('tickets', true);
        kpiSetVisible('avg_tickets', true);

        kpiUpdate('plays',   formatInt(h.plays),                    deltaText(h.plays, h.prev_plays));
        kpiUpdate('tickets', formatInt(Math.round(h.tickets || 0)), deltaText(h.tickets, h.prev_tickets));

        if (canSeeMoney) {
            kpiSetVisible('cash', true);
            kpiSetLabel('cash', 'Play value',
                'Total dollars spent at the game readers over this range (stored card value + cash), straight from '
                + 'the card-system ledger. Broader than “Reader CC payments,” which counts only walk-up credit-card '
                + 'taps in the recent detailed window.');
            kpiUpdate('cash', formatCurrency(h.value), deltaText(h.value, h.prev_value));
        }

        var avg = h.plays > 0 ? (h.tickets / h.plays) : 0;
        var pavg = h.prev_plays > 0 ? (h.prev_tickets / h.prev_plays) : 0;
        kpiUpdate('avg_tickets', avg.toFixed(2), deltaText(avg, pavg));
    }

    function kpiUpdate(key, value, trend) {
        var card = document.querySelector('.analytics-kpi[data-kpi="' + key + '"]');
        if (!card) return;
        card.querySelector('[data-role="value"]').textContent = value;
        var t = card.querySelector('[data-role="trend"]');
        t.textContent = '';
        if (typeof trend === 'string') {
            t.textContent = trend;
        } else if (trend && trend.text) {
            t.textContent = trend.text;
            t.classList.remove('trend-up', 'trend-down', 'trend-flat');
            if (trend.dir) t.classList.add('trend-' + trend.dir);
        }
    }

    /**
     * Build a "↑ 12% vs previous" string. dir is up/down/flat for color coding.
     */
    function deltaText(current, previous) {
        current = Number(current) || 0;
        previous = Number(previous) || 0;
        if (previous === 0 && current === 0) return { text: 'No prior activity', dir: 'flat' };
        if (previous === 0) return { text: '↑ new activity', dir: 'up' };
        var pct = ((current - previous) / previous) * 100;
        var arrow = pct >= 0.5 ? '↑' : pct <= -0.5 ? '↓' : '→';
        var dir = pct >= 0.5 ? 'up' : pct <= -0.5 ? 'down' : 'flat';
        var sign = pct >= 0 ? '+' : '';
        return { text: arrow + ' ' + sign + pct.toFixed(1) + '% vs prior', dir: dir };
    }

    // ------------------------------------------------------------------
    // Fleet posture
    // ------------------------------------------------------------------
    function renderFleet(data) {
        var f = data.fleet || {};

        fleetUpdate('games',
            (f.enabled_games || 0) + ' / ' + (f.total_games || 0),
            (f.paused_games || 0) + ' paused · ' + (f.out_of_service_games || 0) + ' out of service');

        var kioskDetail = (f.paused_kiosks || 0) + ' paused';
        if ((f.out_of_service_kiosks || 0) > 0) kioskDetail += ' · ' + f.out_of_service_kiosks + ' OOS';
        if ((f.unknown_kiosks || 0) > 0) kioskDetail += ' · ' + f.unknown_kiosks + ' unknown';
        fleetUpdate('kiosks',
            (f.enabled_kiosks || 0) + ' / ' + (f.total_kiosks || 0),
            kioskDetail);

        fleetUpdate('groups',
            String(f.active_groups || 0),
            (f.groups_with_manual_override || 0) + ' on manual override');

        fleetUpdate('overrides',
            String(f.active_overrides || 0),
            f.active_overrides ? 'Currently in effect' : 'None active');

        fleetUpdate('retries',
            String(f.pending_retries || 0),
            f.pending_retries ? 'Will retry on next watchdog' : 'Queue is clear');
    }

    function fleetUpdate(key, value, detail) {
        var tile = document.querySelector('.analytics-fleet-tile[data-fleet="' + key + '"]');
        if (!tile) return;
        tile.querySelector('[data-role="value"]').textContent = value;
        tile.querySelector('[data-role="detail"]').textContent = detail || '';
    }

    // ------------------------------------------------------------------
    // Charts
    // ------------------------------------------------------------------
    // Every chart canvas on the page, and the subset that survives deep-history
    // mode (the two the ledger rollup can feed accurately over a long range).
    var ALL_CHART_CANVASES = [
        'analytics-chart-daily', 'analytics-chart-hour', 'analytics-chart-dow',
        'analytics-chart-top-plays', 'analytics-chart-top-tickets',
        'analytics-chart-cat-share', 'analytics-chart-cat-tickets',
        'analytics-chart-payment-mix', 'analytics-chart-cc-brands',
        'analytics-chart-revenue', 'analytics-chart-top-groups'
    ];
    var DEEP_VISIBLE_CANVASES = ['analytics-chart-daily', 'analytics-chart-dow'];

    function setChartCardVisible(canvasId, show) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;
        var card = canvas.closest('.analytics-card');
        if (card) card.style.display = show ? '' : 'none';
    }

    function renderCharts(data) {
        if (typeof window.Chart === 'undefined') {
            // Chart.js still loading or blocked. Try once more shortly.
            setTimeout(function() { if (state && state.overview) renderCharts(state.overview); }, 800);
            return;
        }

        destroyCharts();
        var theme = readThemeColors();

        if (isDeep(data)) { renderChartsDeep(data, theme); return; }

        // Restore all chart cards + the daily title (a previous deep render may
        // have hidden cards / renamed the daily chart).
        ALL_CHART_CANVASES.forEach(function(id) { setChartCardVisible(id, true); });
        setChartCardTitle('analytics-chart-daily', 'Daily activity', 'Each bar is one day — taller means a busier day');
        var charts = data.charts || {};

        // Daily trend (combo: bars=plays, line=tickets)
        var dailyLabels = (charts.daily || []).map(function(d) { return formatShortDate(d.date); });
        var dailyPlays = (charts.daily || []).map(function(d) { return d.plays; });
        var dailyTickets = (charts.daily || []).map(function(d) { return Math.round(d.tickets || 0); });
        registerChart('analytics-chart-daily', {
            type: 'bar',
            data: {
                labels: dailyLabels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Plays',
                        data: dailyPlays,
                        backgroundColor: theme.accentSubtle,
                        borderColor: theme.accent,
                        borderWidth: 1,
                        yAxisID: 'y',
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Tickets',
                        data: dailyTickets,
                        borderColor: theme.tickets,
                        backgroundColor: 'transparent',
                        tension: 0.3,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        yAxisID: 'y1',
                        order: 1
                    }
                ]
            },
            options: dualAxisOptions(theme, 'Plays', 'Tickets')
        });

        // Hour of day
        registerChart('analytics-chart-hour', {
            type: 'bar',
            data: {
                labels: Array.from({ length: 24 }, function(_, i) { return formatHour(i); }),
                datasets: [{
                    label: 'Plays',
                    data: charts.plays_by_hour || [],
                    backgroundColor: theme.accent,
                    borderRadius: 3
                }]
            },
            options: simpleBarOptions(theme)
        });

        // Day of week
        registerChart('analytics-chart-dow', {
            type: 'bar',
            data: {
                labels: DAYS_SHORT,
                datasets: [{
                    label: 'Plays',
                    data: charts.plays_by_dow || [],
                    backgroundColor: theme.accent,
                    borderRadius: 3
                }]
            },
            options: simpleBarOptions(theme)
        });

        // Top games — plays
        registerChart('analytics-chart-top-plays', horizontalBarConfig(
            (charts.top_games_plays || []).map(function(g) { return g.game_name; }),
            (charts.top_games_plays || []).map(function(g) { return g.plays; }),
            'Plays',
            theme.accent,
            theme
        ));

        // Top games — tickets
        registerChart('analytics-chart-top-tickets', horizontalBarConfig(
            (charts.top_games_tickets || []).map(function(g) { return g.game_name; }),
            (charts.top_games_tickets || []).map(function(g) { return Math.round(g.tickets || 0); }),
            'Tickets',
            theme.tickets,
            theme
        ));

        // Category breakdown — plays share donut + tickets bar. Categories
        // come straight from CenterEdge (e.g. Arcade / Rides / Batting Cages).
        var byCat = charts.by_category || [];
        var catPalette = palette(byCat.length, theme);
        registerChart('analytics-chart-cat-share', donutConfig(
            byCat.map(function(c) { return c.name; }),
            byCat.map(function(c) { return c.plays; }),
            catPalette,
            theme,
            'plays'
        ));
        registerChart('analytics-chart-cat-tickets', horizontalBarConfig(
            byCat.map(function(c) { return c.name; }),
            byCat.map(function(c) { return Math.round(c.tickets || 0); }),
            'Tickets',
            theme.tickets,
            theme
        ));

        // Payment mix — how plays were paid (counts; overlaps possible on
        // mixed-payment plays, noted in the card subtitle).
        var pm = charts.payment_mix || {};
        registerChart('analytics-chart-payment-mix', donutConfig(
            ['Points', 'Reader cash', 'Reader CC', 'Time play', 'Privilege'],
            [pm.points_plays || 0, pm.cash_plays || 0, pm.credit_card_plays || 0,
             pm.time_plays || 0, pm.privilege_plays || 0],
            palette(5, theme),
            theme,
            'plays'
        ));

        // Credit-card brand mix — server scrubs this to [] without
        // view_revenue, and the canvas only exists for money-roles anyway.
        if (App.canSeeMoney()) {
            var bm = charts.cc_brand_mix || [];
            registerChart('analytics-chart-cc-brands', donutConfig(
                bm.map(function(b) { return b.brand; }),
                bm.map(function(b) { return b.plays; }),
                palette(Math.max(1, bm.length), theme),
                theme,
                'plays'
            ));
        }

        // Value-mix donut (reader CC $ / points / bonus). Tech doesn't get
        // the canvas so the registerChart call no-ops on a missing node, but
        // skip the work entirely to keep intent explicit.
        if (App.canSeeMoney()) {
            var k = data.kpis || {};
            var revenueLabels = ['Reader CC', 'Points', 'Bonus points'];
            var revenueData   = [Math.round((k.cash || 0) * 100) / 100, Math.round(k.points || 0), Math.round(k.bonus_points || 0)];
            var revenueColors = [theme.success, theme.accent, theme.tickets];
            registerChart('analytics-chart-revenue', donutConfig(revenueLabels, revenueData, revenueColors, theme, '$/pts'));
        }

        // Top groups
        var tg = charts.top_groups_actions || [];
        registerChart('analytics-chart-top-groups', horizontalBarConfig(
            tg.map(function(g) { return g.name; }),
            tg.map(function(g) { return g.actions; }),
            'Pause / unpause actions',
            theme.accent,
            theme
        ));
    }

    /**
     * Deep-history charts: only the two the ledger rollup feeds accurately.
     *   • Activity over time — plays (bars) + tickets (line), from history.trend
     *     (per-month for Year-style ranges, else per-day).
     *   • Plays by day of week — from history.plays_by_dow.
     * All other chart cards are hidden (their recent-only sources are null).
     */
    function renderChartsDeep(data, theme) {
        var h = data.history || {};
        ALL_CHART_CANVASES.forEach(function(id) {
            setChartCardVisible(id, DEEP_VISIBLE_CANVASES.indexOf(id) !== -1);
        });

        var trend = h.trend || [];
        var isMonth = h.granularity === 'month';
        var labels = trend.map(function(pt) {
            return isMonth ? formatMonthLabel(pt.month) : formatShortDate(pt.date);
        });
        var plays = trend.map(function(pt) { return pt.plays || 0; });
        var tickets = trend.map(function(pt) { return Math.round(pt.tickets || 0); });

        setChartCardTitle('analytics-chart-daily',
            isMonth ? 'Activity by month' : 'Activity by day',
            isMonth ? 'Each bar is one month — taller means a busier month'
                    : 'Each bar is one day — taller means a busier day');
        registerChart('analytics-chart-daily', {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'bar', label: 'Plays', data: plays,
                        backgroundColor: theme.accentSubtle, borderColor: theme.accent,
                        borderWidth: 1, yAxisID: 'y', order: 2
                    },
                    {
                        type: 'line', label: 'Tickets', data: tickets,
                        borderColor: theme.tickets, backgroundColor: 'transparent',
                        tension: 0.3, pointRadius: 2, pointHoverRadius: 5, yAxisID: 'y1', order: 1
                    }
                ]
            },
            options: dualAxisOptions(theme, 'Plays', 'Tickets')
        });

        registerChart('analytics-chart-dow', {
            type: 'bar',
            data: {
                labels: DAYS_SHORT,
                datasets: [{
                    label: 'Plays', data: h.plays_by_dow || [],
                    backgroundColor: theme.accent, borderRadius: 3
                }]
            },
            options: simpleBarOptions(theme)
        });
    }

    function setChartCardTitle(canvasId, title, subtitle) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;
        var card = canvas.closest('.analytics-card');
        if (!card) return;
        var t = card.querySelector('.card-title');
        if (t) t.textContent = title;
        if (subtitle != null) {
            var sub = card.querySelector('.analytics-card-header .text-muted');
            if (sub) sub.textContent = subtitle;
        }
    }

    function repaintCharts() {
        if (state && state.overview) renderCharts(state.overview);
    }

    function destroyCharts() {
        if (!state || !state.charts) return;
        state.charts.forEach(function(c) {
            try { c.destroy(); } catch (e) {}
        });
        state.charts = [];
    }

    function registerChart(canvasId, config) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;
        var chart = new window.Chart(canvas, config);
        state.charts.push(chart);
    }

    // ------------------------------------------------------------------
    // Chart.js option helpers
    // ------------------------------------------------------------------
    function baseOptions(theme) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 350 },
            plugins: {
                legend: {
                    display: false,
                    labels: { color: theme.text }
                },
                tooltip: {
                    backgroundColor: theme.bgCard,
                    titleColor: theme.text,
                    bodyColor: theme.textSecondary,
                    borderColor: theme.border,
                    borderWidth: 1,
                    padding: 8
                }
            }
        };
    }

    function simpleBarOptions(theme) {
        var o = baseOptions(theme);
        o.scales = {
            x: { ticks: { color: theme.textSecondary }, grid: { display: false } },
            y: { ticks: { color: theme.textSecondary, precision: 0 }, grid: { color: theme.gridLine } }
        };
        return o;
    }

    function dualAxisOptions(theme, leftLabel, rightLabel) {
        var o = baseOptions(theme);
        o.plugins.legend.display = true;
        o.plugins.legend.position = 'bottom';
        o.interaction = { mode: 'index', intersect: false };
        o.scales = {
            x: { ticks: { color: theme.textSecondary, autoSkip: true, maxRotation: 0 }, grid: { display: false } },
            y: {
                position: 'left',
                title: { display: true, text: leftLabel, color: theme.textSecondary },
                ticks: { color: theme.textSecondary, precision: 0 },
                grid: { color: theme.gridLine },
                beginAtZero: true
            },
            y1: {
                position: 'right',
                title: { display: true, text: rightLabel, color: theme.textSecondary },
                ticks: { color: theme.textSecondary, precision: 0 },
                grid: { drawOnChartArea: false },
                beginAtZero: true
            }
        };
        return o;
    }

    function horizontalBarConfig(labels, data, label, color, theme) {
        var o = baseOptions(theme);
        o.indexAxis = 'y';
        o.scales = {
            x: { ticks: { color: theme.textSecondary, precision: 0 }, grid: { color: theme.gridLine }, beginAtZero: true },
            y: { ticks: { color: theme.textSecondary, autoSkip: false }, grid: { display: false } }
        };
        return {
            type: 'bar',
            data: {
                labels: labels && labels.length ? labels : ['No data'],
                datasets: [{
                    label: label,
                    data: data && data.length ? data : [0],
                    backgroundColor: color,
                    borderRadius: 3,
                    barPercentage: 0.85,
                    categoryPercentage: 0.9
                }]
            },
            options: o
        };
    }

    function donutConfig(labels, data, colors, theme, unitHint) {
        var o = baseOptions(theme);
        o.cutout = '62%';
        o.plugins.legend.display = true;
        o.plugins.legend.position = 'bottom';
        o.plugins.legend.labels = {
            color: theme.text,
            usePointStyle: true,
            boxWidth: 8,
            padding: 12
        };
        o.plugins.tooltip.callbacks = {
            label: function(ctx) {
                var v = ctx.parsed;
                var ds = ctx.dataset.data || [];
                var sum = ds.reduce(function(a, b) { return a + (Number(b) || 0); }, 0);
                var pct = sum > 0 ? Math.round((v / sum) * 100) : 0;
                var formatted = unitHint === '$/pts' && ctx.label === 'Reader CC' ? formatCurrency(v) : formatInt(Math.round(v));
                return ctx.label + ': ' + formatted + ' (' + pct + '%)';
            }
        };
        var hasData = (data || []).some(function(v) { return Number(v) > 0; });
        return {
            type: 'doughnut',
            data: {
                labels: hasData ? labels : ['No data'],
                datasets: [{
                    data: hasData ? data : [1],
                    backgroundColor: hasData ? colors : [theme.gridLine],
                    borderColor: theme.bgCard,
                    borderWidth: 2
                }]
            },
            options: o
        };
    }

    function palette(n, theme) {
        var base = [theme.accent, theme.success, theme.tickets, theme.warning, theme.danger, '#a26df0', '#4ec9c8'];
        var out = [];
        for (var i = 0; i < n; i++) out.push(base[i % base.length]);
        return out;
    }

    // ------------------------------------------------------------------
    // Recent failures table
    // ------------------------------------------------------------------
    function renderFailures(data) {
        var box = document.getElementById('analytics-failures');
        if (!box) return;
        box.innerHTML = '';
        var rows = (data.charts && data.charts.top_failures) || [];
        if (rows.length === 0) {
            box.appendChild(App.emptyState('✓', 'No automation failures recorded — fleet is healthy.'));
            return;
        }
        var table = App.el('table', { className: 'data-table analytics-failures-table' });
        var thead = App.el('thead', {}, [
            App.el('tr', {}, [
                App.el('th', { textContent: 'When' }),
                App.el('th', { textContent: 'Source' }),
                App.el('th', { textContent: 'Action' }),
                App.el('th', { textContent: 'Group' }),
                App.el('th', { textContent: 'Game' }),
                App.el('th', { textContent: 'Error' })
            ])
        ]);
        table.appendChild(thead);
        var tbody = App.el('tbody');
        rows.forEach(function(r) {
            // Each failure row drills into the action log filtered by the
            // failure's source so the operator sees the full sequence around
            // the error rather than just one cherry-picked entry.
            var row = App.el('tr', { className: 'clickable-row' }, [
                App.el('td', { textContent: App.formatDatetime(r.timestamp) }),
                App.el('td', { textContent: SOURCE_LABEL[r.source] || r.source || '-' }),
                App.el('td', { textContent: r.action || '-' }),
                App.el('td', { textContent: r.group_name || '-' }),
                App.el('td', { textContent: r.game_name || r.game_id || '-' }),
                App.el('td', { className: 'text-danger', textContent: truncate(r.error_message || '-', 140) })
            ]);
            var qs = 'success=0';
            if (r.source) qs += '&source=' + encodeURIComponent(r.source);
            if (r.action) qs += '&action=' + encodeURIComponent(r.action);
            App.makeCardLink(row, '#/logs?' + qs,
                { title: 'Open the action log filtered to this failure' });
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        box.appendChild(table);
    }

    // ------------------------------------------------------------------
    // Theme + formatting helpers
    // ------------------------------------------------------------------
    function readThemeColors() {
        var styles = getComputedStyle(document.documentElement);
        var get = function(name, fallback) {
            var v = styles.getPropertyValue(name);
            return (v && v.trim()) || fallback;
        };
        var isLight = document.documentElement.getAttribute('data-theme') === 'light';
        return {
            accent:        get('--accent', '#5b8def'),
            accentSubtle:  isLight ? 'rgba(53, 103, 204, 0.18)' : 'rgba(91, 141, 239, 0.32)',
            success:       get('--success', '#3dd68c'),
            warning:       get('--warning', '#f0a944'),
            danger:        get('--danger', '#e5534b'),
            tickets:       get('--tickets', '#f5b942'),
            text:          get('--text-primary', '#e1e4ed'),
            textSecondary: get('--text-secondary', '#8891a8'),
            border:        get('--border', '#1e2638'),
            bgCard:        get('--bg-card', '#151a28'),
            gridLine:      isLight ? 'rgba(20, 35, 66, 0.08)' : 'rgba(255, 255, 255, 0.06)'
        };
    }

    function formatInt(n) {
        n = Number(n) || 0;
        return n.toLocaleString();
    }

    function formatCurrency(n) {
        n = Number(n) || 0;
        return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function formatHour(h) {
        var ampm = h >= 12 ? 'p' : 'a';
        var h12 = h % 12 || 12;
        return h12 + ampm;
    }

    function formatShortDate(iso) {
        if (!iso) return '';
        // iso looks like "2026-04-30"
        var parts = iso.split('-');
        if (parts.length !== 3) return iso;
        var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    function formatMonthLabel(ym) {
        if (!ym) return '';
        // ym looks like "2026-04"
        var parts = String(ym).split('-');
        if (parts.length < 2) return ym;
        var d = new Date(Number(parts[0]), Number(parts[1]) - 1, 1);
        return d.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
    }

    function truncate(str, max) {
        str = String(str || '');
        if (str.length <= max) return str;
        return str.slice(0, max - 1) + '…';
    }
})();
