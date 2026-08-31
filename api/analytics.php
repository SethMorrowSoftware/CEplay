<?php
/**
 * API: Analytics overview.
 *
 * GET /api/analytics/overview?range=today|7d|30d|90d|all|custom&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   Returns a single payload powering the analytics page:
 *     - KPI totals (plays, tickets, cash, points, unique cards, payment mix)
 *     - Previous-period KPI snapshot for trend deltas
 *     - Fleet posture (game/kiosk/group/override counts)
 *     - Time-bucketed series (hour-of-day, day-of-week, daily)
 *     - Top-game leaderboards (plays, tickets, cash)
 *     - Pause-action breakdown by source/action/success and per-group
 *     - Most recent failures
 *
 * GET /api/analytics/reader-groups?range=…   — per reader group: totals, play
 *   averages, prior-period compare, busiest weekday/hour (staffing signal)
 * GET /api/analytics/reader-group?id=…&range=… — one group: KPIs, trend,
 *   day-of-week × hour heatmap, per-game breakdown
 *
 * GET /api/analytics/yoy — month-to-date and year-to-date ACTUALS against the
 *   identical stretch of the prior year (completed days only, no projection)
 *
 * Aggregations operate on the local cache (game_play_transactions, action_log,
 * pause_groups, schedule_overrides, *_state_cache, action_retries). No live
 * CenterEdge calls — the watchdog already keeps these caches warm.
 *
 * Time bucketing for hour-of-day / day-of-week / daily is performed in PHP
 * using the configured app timezone so chart bins match the venue's wall clock,
 * regardless of whether transaction_time was returned with a UTC "Z" suffix or
 * an offset (e.g. "-04:00").
 */

require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/reporting.php';

function handleAnalytics(string $method, array $parts, ?array $input): void {
    // Analytics is now open to admin / manager / tech, but the response is
    // scrubbed for tech: cash totals, avg-cash per play, and the cash
    // leaderboard are blanked out so tech only sees plays, tickets, fleet
    // posture, and automation activity.
    $currentUser = Auth::requireAccess('analytics');

    $action = $parts[0] ?? '';

    if ($method === 'GET' && $action === 'overview') {
        $hideMoney = !Auth::hasPermission('view_revenue');
        analyticsOverview($hideMoney);
        return;
    }

    // Game Performance reporting (searchable, Day/Week/Month/Year/Custom).
    // Backed by the permanent game_daily_stats rollup for history plus the raw
    // feed for the most recent window, so month/year reporting works even
    // though the raw feed is short-lived. Same 'analytics' gate + money scrub.
    if ($method === 'GET' && $action === 'games') {
        $hideMoney = !Auth::hasPermission('view_revenue');
        analyticsGamesLeaderboard($hideMoney);
        return;
    }
    if ($method === 'GET' && $action === 'game') {
        $hideMoney = !Auth::hasPermission('view_revenue');
        analyticsGameDetail($hideMoney);
        return;
    }

    // Reader Groups reporting: operator-defined groupings of games/readers
    // ("Redemption Wall", "Front Room", …) compared over the same resolved
    // windows, plus a per-group day-of-week × hour-of-day staffing heatmap
    // stitched from game_hourly_stats (history) and the raw feed (recent).
    if ($method === 'GET' && $action === 'reader-groups') {
        $hideMoney = !Auth::hasPermission('view_revenue');
        analyticsReaderGroupsList($hideMoney);
        return;
    }
    if ($method === 'GET' && $action === 'reader-group') {
        $hideMoney = !Auth::hasPermission('view_revenue');
        analyticsReaderGroupDetail($hideMoney);
        return;
    }

    // Month-to-date / year-to-date against the SAME stretch of the prior year.
    // Completed days only on both sides — actuals, never a projection.
    if ($method === 'GET' && $action === 'yoy') {
        $hideMoney = !Auth::hasPermission('view_revenue');
        analyticsYoy($hideMoney);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Unknown analytics endpoint']);
}

/**
 * Strip every cash / revenue field from the analytics overview payload so
 * the 'tech' role never sees monetary totals. We zero numeric scalars and
 * empty the cash leaderboard rather than removing the keys, so the frontend
 * (which is also role-aware) still lines up if it ever reads them.
 */
function analyticsScrubMoney(array &$payload): void {
    foreach (['kpis', 'previous_kpis'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            if (array_key_exists('cash', $payload[$key])) $payload[$key]['cash'] = 0.0;
            if (array_key_exists('avg_cash_per_play', $payload[$key])) $payload[$key]['avg_cash_per_play'] = 0.0;
            // Deep-history "value" (dollars spent at readers) is money too.
            if (array_key_exists('value', $payload[$key])) $payload[$key]['value'] = 0.0;
        }
    }
    // Deep-history block: zero every dollar figure (totals, prior, per-point
    // trend value, per-weekday value) so no $ slips out on long ranges.
    if (isset($payload['history']) && is_array($payload['history'])) {
        foreach (['value', 'prev_value'] as $mk) {
            if (array_key_exists($mk, $payload['history'])) $payload['history'][$mk] = 0.0;
        }
        if (isset($payload['history']['value_by_dow']) && is_array($payload['history']['value_by_dow'])) {
            $payload['history']['value_by_dow'] = array_map(function () { return 0.0; }, $payload['history']['value_by_dow']);
        }
        if (isset($payload['history']['trend']) && is_array($payload['history']['trend'])) {
            foreach ($payload['history']['trend'] as &$pt) {
                if (is_array($pt) && array_key_exists('value', $pt)) $pt['value'] = 0.0;
            }
            unset($pt);
        }
    }
    // Guest Insights carries per-visit / per-guest dollar figures — zero the
    // money, keep the counts and rates (new/returning, frequency, attach rate)
    // which are non-monetary and visible to all analytics roles.
    if (isset($payload['guests']) && is_array($payload['guests'])) {
        foreach (['total_spend', 'spend_per_visit', 'spend_per_guest'] as $moneyKey) {
            if (array_key_exists($moneyKey, $payload['guests'])) $payload['guests'][$moneyKey] = 0.0;
        }
    }
    if (isset($payload['charts']) && is_array($payload['charts'])) {
        // The cash leaderboard is monetary end-to-end — drop it entirely.
        $payload['charts']['top_games_cash'] = [];
        // Brand mix carries card brands + credit-card dollar amounts — same
        // policy as the live feed blanking brand/last-4: drop it entirely.
        $payload['charts']['cc_brand_mix'] = [];
        // Per-row cash also rides along in the daily series, the plays /
        // tickets leaderboards, and the category breakdown; zero it there so
        // no dollar figure slips out through a side channel (the previous
        // scrub missed these).
        foreach (['daily', 'top_games_plays', 'top_games_tickets', 'by_category'] as $seriesKey) {
            if (isset($payload['charts'][$seriesKey]) && is_array($payload['charts'][$seriesKey])) {
                foreach ($payload['charts'][$seriesKey] as &$row) {
                    if (is_array($row) && array_key_exists('cash', $row)) {
                        $row['cash'] = 0.0;
                    }
                }
                unset($row);
            }
        }
    }
}

/**
 * True when the request asks to exclude time-pass plays. On the overview
 * page the exclusion is exact and total (each transaction row carries the
 * flag, so plays AND their tickets/cash/points drop out together).
 */
function analyticsExcludeTimePlays(): bool {
    return isset($_GET['exclude_time_plays'])
        && in_array((string)$_GET['exclude_time_plays'], ['1', 'true', 'yes'], true);
}

function analyticsOverview(bool $hideMoney = false): void {
    $noTime = analyticsExcludeTimePlays();
    $noTimeSql = $noTime ? ' AND used_time_play = 0' : '';
    $tzName = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
        $tzName = 'UTC';
    }
    $utc = new DateTimeZone('UTC');

    // Resolve the requested window into [start, end] in UTC. We compare ISO 8601
    // strings lexically against transaction_time / action_log.timestamp, so we
    // emit explicit UTC strings here to keep the comparison unambiguous.
    //
    // Day / Week / Month / Year / Custom (+ offset) — the SAME period model the
    // Performance, Reader Groups, Labor and Card Loads pages use, so every
    // reporting page shares one top-bar picker. perfResolveWindow hands back the
    // local start and (exclusive) end as DateTime objects, plus the calendar-
    // previous period for the "vs previous" deltas.
    $win = perfResolveWindow($tz);
    $startLocal = clone $win['start'];
    $endLocal   = clone $win['endExcl'];

    $startUtc = clone $startLocal; $startUtc->setTimezone($utc);
    $endUtc   = clone $endLocal;   $endUtc->setTimezone($utc);

    // Format helpers
    $isoUtc = function (DateTime $d) { return $d->format('Y-m-d\TH:i:s\Z'); };
    $sqlUtc = function (DateTime $d) { return $d->format('Y-m-d H:i:s'); };

    $startIsoUtc = $isoUtc($startUtc);
    $endIsoUtc   = $isoUtc($endUtc);
    $startSql    = $sqlUtc($startUtc); // For action_log (DB stores "YYYY-MM-DD HH:MM:SS" UTC)
    $endSql      = $sqlUtc($endUtc);

    // Previous period: the calendar-previous window of the same kind (last
    // week / month / year, or the equal-length span before a custom range).
    $prevStartUtc = (clone $win['prev_start'])->setTimezone($utc);
    $prevEndUtc   = (clone $win['prev_endExcl'])->setTimezone($utc);
    $prevStartIso = $isoUtc($prevStartUtc);
    $prevEndIso   = $isoUtc($prevEndUtc);

    // ---- KPI: plays / tickets / cash / unique cards (current + prior) ----
    $kpis = analyticsKpis($startIsoUtc, $endIsoUtc, $noTime);
    $previousKpis = analyticsKpis($prevStartIso, $prevEndIso, $noTime);

    // How many plays the exclusion removed — the banner shows it so the
    // "without passes" view is explicit about what it set aside.
    $excludedTimePlays = null;
    if ($noTime) {
        $r = DB::queryOne(
            'SELECT COUNT(*) AS c FROM game_play_transactions
             WHERE transaction_time >= :p0 AND transaction_time < :p1 AND used_time_play = 1',
            [$startIsoUtc, $endIsoUtc]
        );
        $excludedTimePlays = (int)($r['c'] ?? 0);
    }

    // ---- Breakage: value expired off cards + card merges (system feed) ----
    // Points/tickets, not dollars — no view_revenue scrub needed.
    $kpis = array_merge($kpis, analyticsSystemTx($startIsoUtc, $endIsoUtc));
    $previousKpis = array_merge($previousKpis, analyticsSystemTx($prevStartIso, $prevEndIso));

    // ---- Guest Insights (new vs returning, frequency, spend/visit) ----
    $guests = analyticsGuests($startIsoUtc, $endIsoUtc, $tz, $startLocal, $noTime);

    // ---- Fleet posture (current state, not range-scoped) ----
    $fleet = analyticsFleet();

    // ---- Top games — three leaderboards, capped at 10 each ----
    $topGamesPlays   = analyticsTopGames($startIsoUtc, $endIsoUtc, 'plays', 10, $noTime);
    $topGamesTickets = analyticsTopGames($startIsoUtc, $endIsoUtc, 'tickets', 10, $noTime);
    $topGamesCash    = analyticsTopGames($startIsoUtc, $endIsoUtc, 'cash', 10, $noTime);

    // ---- Category breakdown (arcade vs rides vs batting cages, etc.) ----
    $byCategory = analyticsByCategory($startIsoUtc, $endIsoUtc, $noTime);

    // ---- Payment mix (plays by method) + credit-card brand mix ----
    // Method COUNTS are visible to all analytics roles (kpis already expose
    // time/privilege/credit-card play counts); the BRAND mix carries card
    // brands and dollar amounts, so analyticsScrubMoney drops it entirely
    // for roles without view_revenue — same policy as the live feed's
    // brand/last-4 blanking.
    $paymentMix = DB::queryOne(
        'SELECT
             SUM(CASE WHEN regular_points + bonus_points > 0 THEN 1 ELSE 0 END) AS points_plays,
             SUM(CASE WHEN cash_amount > 0 THEN 1 ELSE 0 END) AS cash_plays,
             SUM(CASE WHEN credit_card_amount > 0 THEN 1 ELSE 0 END) AS credit_card_plays,
             SUM(CASE WHEN used_time_play = 1 THEN 1 ELSE 0 END) AS time_plays,
             SUM(CASE WHEN used_play_privilege = 1 THEN 1 ELSE 0 END) AS privilege_plays
         FROM game_play_transactions
         WHERE transaction_time >= :p0 AND transaction_time < :p1' . $noTimeSql,
        [$startIsoUtc, $endIsoUtc]
    ) ?: [];
    $paymentMix = [
        'points_plays'      => (int)($paymentMix['points_plays'] ?? 0),
        'cash_plays'        => (int)($paymentMix['cash_plays'] ?? 0),
        'credit_card_plays' => (int)($paymentMix['credit_card_plays'] ?? 0),
        'time_plays'        => (int)($paymentMix['time_plays'] ?? 0),
        'privilege_plays'   => (int)($paymentMix['privilege_plays'] ?? 0),
    ];

    $brandMix = array_map(function ($r) {
        return [
            'brand'  => (string)$r['brand'],
            'plays'  => (int)$r['plays'],
            'amount' => round((float)$r['amount'], 2),
        ];
    }, DB::query(
        'SELECT cc_card_type AS brand, COUNT(*) AS plays, COALESCE(SUM(credit_card_amount), 0) AS amount
         FROM game_play_transactions
         WHERE cc_card_type != \'\'
           AND transaction_time >= :p0 AND transaction_time < :p1' . $noTimeSql . '
         GROUP BY cc_card_type
         ORDER BY plays DESC',
        [$startIsoUtc, $endIsoUtc]
    ));

    // ---- Time-bucketed series (hour-of-day, day-of-week, daily) ----
    // We pull just the columns we need for binning, and STREAM them (DB::each):
    // the output is 24 + 7 + one-per-day buckets, but the read behind it is
    // every play in the window. Narrowing the column list was not enough on its
    // own once daily volume grew — see DB::each().
    // The optimizer uses idx_gpt_time on transaction_time DESC.
    $hourBuckets = array_fill(0, 24, ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0]);
    $dowBuckets  = array_fill(0, 7,  ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0]);
    $dailyByKey  = [];

    DB::each(
        'SELECT transaction_time, redemption_tickets,
                (cash_amount + credit_card_amount) AS cash_amount
         FROM game_play_transactions
         WHERE transaction_time >= :p0 AND transaction_time < :p1' . $noTimeSql,
        [$startIsoUtc, $endIsoUtc],
        function (array $row) use (&$hourBuckets, &$dowBuckets, &$dailyByKey, $tz): void {
            $tt = $row['transaction_time'] ?? '';
            if ($tt === '') return;
            try {
                $d = new DateTime($tt);
            } catch (Exception $e) {
                return;
            }
            $d->setTimezone($tz);
            $h = (int)$d->format('G');
            $dow = (int)$d->format('w');
            $key = $d->format('Y-m-d');
            $tickets = (float)($row['redemption_tickets'] ?? 0);
            $cash    = (float)($row['cash_amount'] ?? 0);

            $hourBuckets[$h]['plays']   += 1;
            $hourBuckets[$h]['tickets'] += $tickets;
            $hourBuckets[$h]['cash']    += $cash;

            $dowBuckets[$dow]['plays']   += 1;
            $dowBuckets[$dow]['tickets'] += $tickets;
            $dowBuckets[$dow]['cash']    += $cash;

            if (!isset($dailyByKey[$key])) {
                $dailyByKey[$key] = ['date' => $key, 'plays' => 0, 'tickets' => 0.0, 'cash' => 0.0];
            }
            $dailyByKey[$key]['plays']   += 1;
            $dailyByKey[$key]['tickets'] += $tickets;
            $dailyByKey[$key]['cash']    += $cash;
        }
    );

    // Fill date gaps for the daily series so the trend line doesn't lie.
    $daily = analyticsFillDailyGaps($dailyByKey, $startLocal, $endLocal, $tz);

    // ---- Pause-action breakdown (action_log within range) ----
    $actionsBreakdown = analyticsActionsBreakdown($startSql, $endSql);

    // ---- Top groups by action count ----
    $topGroups = DB::query(
        'SELECT g.id, g.name, COUNT(l.id) AS actions
         FROM action_log l
         JOIN pause_groups g ON g.id = l.pause_group_id
         WHERE l.timestamp >= :p0 AND l.timestamp < :p1
           AND l.action IN (\'pause\', \'unpause\')
         GROUP BY g.id
         ORDER BY actions DESC
         LIMIT 8',
        [$startSql, $endSql]
    );
    foreach ($topGroups as &$g) {
        $g['actions'] = (int)$g['actions'];
        $g['id']      = (int)$g['id'];
    }
    unset($g);

    // ---- Recent card-system events (merges/expirations, last 10) ----
    $systemEvents = array_map(function ($r) {
        return [
            'type'             => (string)$r['type'],
            'transaction_time' => (string)$r['transaction_time'],
            'card_number'      => (string)$r['card_number'],
            'source_card'      => (string)$r['source_card_number'],
            'destination_card' => (string)$r['destination_card_number'],
            'expired_points'   => round((float)$r['expired_regular'] + (float)$r['expired_bonus'], 2),
            'expired_tickets'  => round((float)$r['expired_tickets'], 2),
            'is_wiped'         => (bool)$r['is_wiped'],
        ];
    }, DB::query(
        'SELECT type, transaction_time, card_number, source_card_number,
                destination_card_number, expired_regular, expired_bonus,
                expired_tickets, is_wiped
         FROM system_transactions
         ORDER BY transaction_id DESC
         LIMIT 10'
    ));

    // Whether the card system reports system transactions at all — read from
    // the cached capabilities payload directly (no live call; stale is fine
    // for a boolean that changes only on CenterEdge upgrades).
    $sysTxSupported = false;
    $rawCaps = DB::getConfig('cache_capabilities');
    if ($rawCaps) {
        $decodedCaps = json_decode($rawCaps, true);
        $sysTxSupported = !empty($decodedCaps['systemTransactionReporting']['isSupported']);
    }

    // ---- Recent failures (always last 10, regardless of window) ----
    $recentFailures = DB::query(
        'SELECT l.timestamp, l.source, l.action, l.error_message,
                l.game_id, l.game_name, g.name AS group_name
         FROM action_log l
         LEFT JOIN pause_groups g ON g.id = l.pause_group_id
         WHERE l.success = 0
         ORDER BY l.timestamp DESC
         LIMIT 10'
    );

    // ---- Feed metadata ----
    $feed = DB::queryOne(
        'SELECT COUNT(*) AS total,
                MIN(transaction_time) AS earliest,
                MAX(transaction_time) AS latest,
                MAX(fetched_at) AS last_poll
         FROM game_play_transactions'
    );

    // ---- Deep history: reach past the 30-day raw feed into venue_daily_stats ----
    // When the requested range starts before the raw feed's earliest day, source
    // the headline plays / value-played / tickets from the venue-wide daily
    // rollup (POS ledger, ~2 decades) instead. Single-source (no raw mix), so no
    // seam. The raw-only detail panels (payment mix, brand mix, guests, hour-of-
    // day, top games, category, unique-over-period) can't be rebuilt historically,
    // so they're cleared and the client hides them with a "last N days only" note.
    // If the rollup hasn't been backfilled yet, we fall through to the raw path.
    $startLocalDate   = $startLocal->format('Y-m-d');
    $endLocalDateExcl = $endLocal->format('Y-m-d');
    $rawFloorDate     = analyticsRawFloorDate($tz);
    $history = null;
    if ($startLocalDate < $rawFloorDate) {
        $gran = $win['granularity'] ?? 'day';
        $v = analyticsVenueDaily($startLocalDate, $endLocalDateExcl, $gran);
        // Calendar days the window ASKS for, never counting the future — the
        // denominator for "covering N of M days". A rollup with holes in it
        // returns a total that looks complete and is not; comparing these two
        // numbers is the only way the reader can tell.
        $expectedDays = analyticsWindowDays($startLocalDate, $endLocalDateExcl, $tz);
        // The newest day the rollup could legitimately hold for this window:
        // the window's last day, or yesterday if the window is still running.
        $yesterday = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');
        $windowLast = (new DateTime($endLocalDateExcl))->modify('-1 day')->format('Y-m-d');
        $expectedThrough = $windowLast < $yesterday ? $windowLast : $yesterday;
        if ($v['has_data']) {
            $pv = analyticsVenueDaily(
                (clone $win['prev_start'])->format('Y-m-d'),
                (clone $win['prev_endExcl'])->format('Y-m-d'),
                $gran
            );
            $history = [
                'active'               => true,
                'granularity'          => $v['granularity'],
                'since'                => $v['since'],
                'through'              => $v['through'],
                'recent_metrics_since' => $rawFloorDate,
                // Coverage, so a partial rollup cannot present itself as a full
                // period. covered_days counts days that CARRY ROWS (the same
                // definition the year-over-year card's `days` uses), so a
                // six-week hole in the middle of a year shows up as 196 of 242
                // rather than as a clean, confident, wrong total. Reported as
                // NEUTRAL context, never a warning: a closed day carries no
                // rows either, and a venue that shuts on winter Mondays would
                // otherwise cry wolf on every long range.
                'covered_days'         => $v['covered_days'],
                'expected_days'        => $expectedDays,
                // Staleness IS a warning, and it is the one signature a closed
                // day cannot explain: the rollup has simply stopped advancing.
                // Same definition and same 3-day tolerance as the
                // year-over-year card — 1 is normal (the nightly refresh runs
                // at 00:05 UTC, i.e. 20:05 the previous day here, and never
                // covers today), so anything under the tolerance must stay
                // silent. This is exactly the six-week freeze that went
                // unnoticed on this venue in 2026.
                'stale_days'           => analyticsYoyStaleDays($v['through'], $expectedThrough),
                'expected_through'     => $expectedThrough,
                'plays'                => $v['plays'],
                'value'                => $v['value'],
                'tickets'              => $v['tickets'],
                'prev_plays'           => $pv['plays'],
                'prev_value'           => $pv['value'],
                'prev_tickets'         => $pv['tickets'],
                'trend'                => $v['trend'],
                'plays_by_dow'         => $v['plays_by_dow'],
                'value_by_dow'         => $v['value_by_dow'],
                'tickets_by_dow'       => $v['tickets_by_dow'],
            ];
        } else {
            // The window reaches past the raw feed AND the ledger rollup has
            // nothing for it. Everything below is therefore computed from the
            // raw feed alone — i.e. from the last ~30 days, not the period the
            // user picked. Left unsaid, that reads as fact: a Year view quietly
            // reporting one month of plays, or a past month reporting a
            // confident ZERO, with every panel populated and no warning.
            // Report the shortfall instead of asserting the number.
            // active=false, so the client keeps the normal (non-deep) layout
            // and only adds a warning banner.
            $history = [
                'active'               => false,
                'reason'               => 'no_ledger_coverage',
                'recent_metrics_since' => $rawFloorDate,
                'covered_days'         => 0,
                'expected_days'        => $expectedDays,
            ];
        }
    }

    $payload = [
        // Canonical window meta for the shared top-bar picker; `label` drives
        // the ‹ prev / next › nav — same shape every reporting page returns.
        'window' => perfRangeMeta($win, $tzName),
        'range' => [
            // Back-compat alias — `window` is canonical now.
            'key'      => $win['range'],
            'timezone' => $tzName,
            'from'     => $win['from'],
            'to'       => $win['to'],
            'from_iso' => $startIsoUtc,
            'to_iso'   => $endIsoUtc,
        ],
        'kpis' => $kpis,
        'previous_kpis' => $previousKpis,
        'guests' => $guests,
        'fleet' => $fleet,
        'charts' => [
            'plays_by_hour'    => array_map(function ($b) { return $b['plays']; },   $hourBuckets),
            'tickets_by_hour'  => array_map(function ($b) { return $b['tickets']; }, $hourBuckets),
            'plays_by_dow'     => array_map(function ($b) { return $b['plays']; },   $dowBuckets),
            'tickets_by_dow'   => array_map(function ($b) { return $b['tickets']; }, $dowBuckets),
            'daily'            => $daily,
            'top_games_plays'  => $topGamesPlays,
            'top_games_tickets'=> $topGamesTickets,
            'top_games_cash'   => $topGamesCash,
            'by_category'      => $byCategory,
            'payment_mix'      => $paymentMix,
            'cc_brand_mix'     => $brandMix,
            'system_events'    => $systemEvents,
            'actions_by_source'  => $actionsBreakdown['by_source'],
            'actions_by_action'  => $actionsBreakdown['by_action'],
            'actions_success_fail' => $actionsBreakdown['success_fail'],
            'top_groups_actions' => $topGroups,
            'top_failures'     => $recentFailures,
        ],
        'feed' => [
            'total_cached_transactions' => (int)($feed['total'] ?? 0),
            'earliest_transaction_at'   => $feed['earliest'] ?? null,
            'latest_transaction_at'     => $feed['latest'] ?? null,
            'last_poll_at'              => $feed['last_poll'] ?? null,
        ],
        'system_tx_supported' => $sysTxSupported,
        'hide_money' => $hideMoney,
        'exclude_time_plays' => $noTime,
        'excluded_time_plays' => $excludedTimePlays,
        'generated_at' => (new DateTime('now', $utc))->format('Y-m-d\TH:i:s\Z'),
    ];

    // Deep-history override: swap the headline numbers + trend to the ledger
    // rollup and clear the raw-only detail panels (client hides them). Done
    // BEFORE the money scrub so `value` gets scrubbed for roles without
    // view_revenue like every other dollar figure.
    if ($history !== null) {
        $payload['history'] = $history;
    }
    // Only an ACTIVE history swaps the payload over to the ledger. A history
    // block with active=false is a disclosure that the range outruns both
    // sources — the raw-fed panels stay exactly as they were, and the client
    // adds a warning rather than switching layout.
    if ($history !== null && !empty($history['active'])) {
        // Headline KPIs from the ledger. `value` = $ played at readers (the deep
        // money metric); `cash` (walk-up only) and per-period-unique/points can't
        // go deep, so null them (client shows `value` and hides the rest).
        $payload['kpis']['plays']        = $history['plays'];
        $payload['kpis']['tickets']      = $history['tickets'];
        $payload['kpis']['value']        = $history['value'];
        $payload['kpis']['unique_cards'] = null;
        $payload['kpis']['cash']         = null;
        $payload['kpis']['points']       = null;
        $payload['kpis']['bonus_points'] = null;
        $payload['previous_kpis']['plays']   = $history['prev_plays'];
        $payload['previous_kpis']['tickets'] = $history['prev_tickets'];
        $payload['previous_kpis']['value']   = $history['prev_value'];
        // Recent-only panels → null so the client hides them on long ranges.
        foreach (['daily', 'plays_by_hour', 'tickets_by_hour', 'plays_by_dow',
                  'tickets_by_dow', 'top_games_plays', 'top_games_tickets',
                  'top_games_cash', 'by_category', 'payment_mix', 'cc_brand_mix',
                  'system_events'] as $k) {
            if (array_key_exists($k, $payload['charts'])) $payload['charts'][$k] = null;
        }
        $payload['guests'] = null; // new-vs-returning needs the per-transaction card list
    }

    if ($hideMoney) {
        analyticsScrubMoney($payload);
    }

    echo json_encode($payload);
}

/**
 * KPI totals for the [startIso, endIso) window. Uses lexical ISO comparison
 * which works for all valid ISO 8601 timestamps with timezone designators.
 */
function analyticsKpis(string $startIso, string $endIso, bool $excludeTimePlays = false): array {
    $row = DB::queryOne(
        'SELECT
             COUNT(*) AS plays,
             COUNT(DISTINCT CASE WHEN card_number != \'\' AND card_number != \'000000\' THEN card_number END) AS unique_cards,
             COALESCE(SUM(redemption_tickets), 0) AS tickets,
             COALESCE(SUM(cash_amount + credit_card_amount), 0) AS cash,
             COALESCE(SUM(regular_points), 0) AS points,
             COALESCE(SUM(bonus_points), 0) AS bonus_points,
             SUM(CASE WHEN card_number = \'000000\' THEN 1 ELSE 0 END) AS credit_card_plays,
             SUM(CASE WHEN card_number != \'\' AND card_number != \'000000\' THEN 1 ELSE 0 END) AS card_plays,
             SUM(CASE WHEN used_time_play = 1 THEN 1 ELSE 0 END) AS time_plays,
             SUM(CASE WHEN used_play_privilege = 1 THEN 1 ELSE 0 END) AS privilege_plays
         FROM game_play_transactions
         WHERE transaction_time >= :p0 AND transaction_time < :p1'
            . ($excludeTimePlays ? ' AND used_time_play = 0' : ''),
        [$startIso, $endIso]
    );

    $plays = (int)($row['plays'] ?? 0);
    $tickets = (float)($row['tickets'] ?? 0);
    $cash    = (float)($row['cash'] ?? 0);

    return [
        'plays'                => $plays,
        'unique_cards'         => (int)($row['unique_cards'] ?? 0),
        'tickets'              => $tickets,
        'cash'                 => $cash,
        'points'               => (float)($row['points'] ?? 0),
        'bonus_points'         => (float)($row['bonus_points'] ?? 0),
        'credit_card_plays'    => (int)($row['credit_card_plays'] ?? 0),
        'card_plays'           => (int)($row['card_plays'] ?? 0),
        'time_plays'           => (int)($row['time_plays'] ?? 0),
        'privilege_plays'      => (int)($row['privilege_plays'] ?? 0),
        'avg_tickets_per_play' => $plays > 0 ? round($tickets / $plays, 2) : 0,
        'avg_cash_per_play'    => $plays > 0 ? round($cash / $plays, 2) : 0,
    ];
}

/**
 * The local date the raw play feed's coverage begins (earliest transaction),
 * or today when the feed is empty. The overview reaches beyond this into the
 * venue_daily_stats rollup for deep history.
 */
function analyticsRawFloorDate(DateTimeZone $tz): string {
    $row = DB::queryOne("SELECT MIN(transaction_time) AS t FROM game_play_transactions WHERE transaction_time != ''");
    $min = $row['t'] ?? '';
    if ($min === '' || $min === null) {
        return (new DateTime('now', $tz))->format('Y-m-d');
    }
    try {
        $d = new DateTime((string)$min);
        $d->setTimezone($tz);
        return $d->format('Y-m-d');
    } catch (Exception $e) {
        return (new DateTime('now', $tz))->format('Y-m-d');
    }
}

/**
 * Calendar days a window actually asks about: [$fromDate, $toDateExcl), with
 * the future clipped off (a Year view opened in August asks about December,
 * which no source can ever cover and which must not count as "missing").
 *
 * This is the denominator for the deep-history coverage disclosure — see the
 * `covered_days` / `expected_days` pair in analyticsOverview().
 */
function analyticsWindowDays(string $fromDate, string $toDateExcl, DateTimeZone $tz): int {
    $utc = new DateTimeZone('UTC');
    $from = DateTime::createFromFormat('!Y-m-d', $fromDate, $utc);
    $to   = DateTime::createFromFormat('!Y-m-d', $toDateExcl, $utc);
    if (!$from || !$to) return 0;
    // Tomorrow, venue-local: today is partial but a source may already carry
    // part of it, so it counts; anything past it cannot.
    $capStr = (new DateTime('now', $tz))->modify('+1 day')->format('Y-m-d');
    $cap = DateTime::createFromFormat('!Y-m-d', $capStr, $utc);
    if ($cap && $to > $cap) $to = $cap;
    if ($to <= $from) return 0;
    return (int)round(($to->getTimestamp() - $from->getTimestamp()) / 86400);
}

/**
 * DEEP-history venue totals + trend from the venue_daily_stats rollup
 * (POS-ledger sourced, ~2 decades). SINGLE-SOURCE — never mixes the raw feed —
 * so there is no definitional seam and no misleading partial "today" (today is
 * never in venue_daily_stats, so a range ending today naturally covers through
 * yesterday). Money here is VALUE PLAYED ($ spent at readers, TransType 1),
 * and tickets are ALL earned (ValueNo 3) — the ledger definitions, which differ
 * from the raw feed's walk-up-cash/won-at-play view (that's why we only use this
 * once a range reaches past the raw window, and label it distinctly). Inherently
 * bounded: one row/day, trend capped/aggregated per granularity.
 *
 * @param string $fromDate    inclusive local date YYYY-MM-DD
 * @param string $toDateExcl  exclusive local date YYYY-MM-DD
 * @param string $granularity 'month' → per-month trend; else per-day (cap 370)
 * @return array{has_data:bool, plays:int, value:float, tickets:float,
 *   covered_days:int, since:?string, through:?string, granularity:string,
 *   trend:array, plays_by_dow:array, value_by_dow:array, tickets_by_dow:array}
 */
function analyticsVenueDaily(string $fromDate, string $toDateExcl, string $granularity): array {
    $rows = DB::query(
        'SELECT stat_date, plays, value, tickets
         FROM venue_daily_stats
         WHERE stat_date >= :p0 AND stat_date < :p1
         ORDER BY stat_date',
        [$fromDate, $toDateExcl]
    );

    $byDate = [];
    $plays = 0; $value = 0.0; $tickets = 0.0; $since = null; $through = null;
    $dowPlays = array_fill(0, 7, 0);
    $dowValue = array_fill(0, 7, 0.0);
    $dowTickets = array_fill(0, 7, 0.0);
    $utc = new DateTimeZone('UTC');
    foreach ($rows as $r) {
        $d = (string)$r['stat_date'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        $p = (int)$r['plays']; $v = (float)$r['value']; $t = (float)$r['tickets'];
        $byDate[$d] = ['date' => $d, 'plays' => $p, 'value' => round($v, 2), 'tickets' => round($t, 2)];
        $plays += $p; $value += $v; $tickets += $t;
        if ($since === null || $d < $since) $since = $d;
        if ($through === null || $d > $through) $through = $d;
        $wd = DateTime::createFromFormat('!Y-m-d', $d, $utc);
        $w = $wd ? (int)$wd->format('w') : 0;
        $dowPlays[$w] += $p; $dowValue[$w] += $v; $dowTickets[$w] += $t;
    }

    // Trend: per-month for long/Year-style ranges (bounded even over decades),
    // else per-day gap-filled (cap 370 like the raw daily series).
    $trend = [];
    if ($granularity === 'month') {
        $byMonth = [];
        foreach ($byDate as $d => $a) {
            $ym = substr($d, 0, 7);
            if (!isset($byMonth[$ym])) $byMonth[$ym] = ['month' => $ym, 'plays' => 0, 'value' => 0.0, 'tickets' => 0.0];
            $byMonth[$ym]['plays'] += $a['plays']; $byMonth[$ym]['value'] += $a['value']; $byMonth[$ym]['tickets'] += $a['tickets'];
        }
        ksort($byMonth);
        foreach ($byMonth as $m) {
            $m['value'] = round($m['value'], 2); $m['tickets'] = round($m['tickets'], 2);
            $trend[] = $m;
        }
    } else {
        $cursor = DateTime::createFromFormat('!Y-m-d', $fromDate, $utc);
        $stop   = DateTime::createFromFormat('!Y-m-d', $toDateExcl, $utc);
        $limit = 370;
        if ($cursor && $stop) {
            while ($cursor < $stop && $limit-- > 0) {
                $k = $cursor->format('Y-m-d');
                $trend[] = $byDate[$k] ?? ['date' => $k, 'plays' => 0, 'value' => 0.0, 'tickets' => 0.0];
                $cursor->modify('+1 day');
            }
        }
    }

    return [
        'has_data'     => !empty($byDate),
        'plays'        => $plays,
        'value'        => round($value, 2),
        'tickets'      => round($tickets, 2),
        'covered_days' => count($byDate),
        'since'        => $since,
        'through'      => $through,
        'granularity'  => ($granularity === 'month') ? 'month' : 'day',
        'trend'        => $trend,
        'plays_by_dow'   => array_values($dowPlays),
        'value_by_dow'   => array_map(function ($x) { return round($x, 2); }, array_values($dowValue)),
        'tickets_by_dow' => array_map(function ($x) { return round($x, 2); }, array_values($dowTickets)),
    ];
}

// ---------------------------------------------------------------------------
// Year over year — month-to-date and year-to-date ACTUALS vs the prior year
// ---------------------------------------------------------------------------

/**
 * Which permanent rollup backs the year-over-year card.
 *
 *   'ledger' — venue_daily_stats, the POS card-ledger rollup. Reaches back ~2
 *              decades, so prior-year comparisons are real. Money = value
 *              played at the readers.
 *   'app'    — game_daily_stats, the app's own per-game rollup. Only as deep as
 *              this app's history (plus its pre-2013 per-game backfill), so the
 *              prior-year side is often empty. Money = cash taken at readers.
 *
 * The ledger wins whenever it holds any row: one source powers ALL FOUR windows
 * so the two sides of a comparison always share a definition.
 */
function analyticsYoySource(): string {
    // EXISTS, not COUNT(*): the dashboard re-polls this every 30 s and the
    // ledger rollup is ~20 years of daily rows.
    $row = DB::queryOne('SELECT 1 AS n FROM venue_daily_stats LIMIT 1');
    return ($row && (int)($row['n'] ?? 0) === 1) ? 'ledger' : 'app';
}

/**
 * Newest COMPLETE local day the source covers. Never today: today is partial by
 * definition (and the ledger rollup deliberately never writes it), so including
 * it would compare a half day against a whole one.
 */
function analyticsYoyThrough(string $source, string $today): ?string {
    $row = DB::queryOne(
        $source === 'ledger'
            ? 'SELECT MAX(stat_date) AS d FROM venue_daily_stats WHERE stat_date < :p0'
            : 'SELECT MAX(stat_date) AS d FROM game_daily_stats WHERE stat_date < :p0',
        [$today]
    );
    $d = $row['d'] ?? null;
    return (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
}

/**
 * How many days BEHIND the rollup is: from its newest complete day to the day
 * that should be its newest complete day (yesterday, venue-local).
 *
 * This exists because a frozen rollup is invisible otherwise. Every figure on
 * the card is honestly labelled with the window it covers, so a source that
 * stopped updating six weeks ago still renders a clean, plausible-looking
 * "month to date" — of the wrong month. The refresh runs from cron and only
 * from cron, so anything that breaks it (an MSSQL driver missing from the
 * nightly container, the POS unreachable, a cron that stopped firing) fails
 * exactly this quietly. Reporting the gap makes the card say so.
 *
 * 0 is normal: the nightly refresh can land while the venue-local day it would
 * cover is still running, so being one day back is routine, not a fault — the
 * UI only warns past a few days.
 */
function analyticsYoyStaleDays(?string $through, string $expected): ?int {
    if ($through === null) return null;
    if ($through >= $expected) return 0;
    $a = DateTime::createFromFormat('!Y-m-d', $through, new DateTimeZone('UTC'));
    $b = DateTime::createFromFormat('!Y-m-d', $expected, new DateTimeZone('UTC'));
    if (!$a || !$b) return null;
    return (int)$a->diff($b)->days;
}

/** "July" from a YYYY-MM stamp; the bare stamp back if it can't be parsed. */
function analyticsYoyMonthName(string $ym): string {
    $d = DateTime::createFromFormat('!Y-m-d', $ym . '-01', new DateTimeZone('UTC'));
    return $d ? $d->format('F') : $ym;
}

/** Earliest day the source covers — lets the UI explain a thin prior year. */
function analyticsYoyHistorySince(string $source): ?string {
    $row = DB::queryOne(
        $source === 'ledger'
            ? 'SELECT MIN(stat_date) AS d FROM venue_daily_stats'
            : 'SELECT MIN(stat_date) AS d FROM game_daily_stats'
    );
    $d = $row['d'] ?? null;
    return (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
}

/**
 * The same month/day one year earlier, clamped to the prior month's length so
 * Feb 29 lands on Feb 28 instead of rolling into March.
 */
function analyticsYoyPriorDate(string $date): string {
    $y = (int)substr($date, 0, 4) - 1;
    $m = (int)substr($date, 5, 2);
    $d = (int)substr($date, 8, 2);
    $first = DateTime::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $y, $m), new DateTimeZone('UTC'));
    $maxDay = $first ? (int)$first->format('t') : 28;
    if ($d > $maxDay) $d = $maxDay;
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/**
 * Totals for one inclusive [from, toIncl] day range out of the chosen rollup.
 * `days` counts days that actually carry rows — a period whose prior-year half
 * has zero covered days is "no history", not "a real zero".
 */
function analyticsYoyWindow(string $source, string $from, string $toIncl): array {
    $row = DB::queryOne(
        $source === 'ledger'
            ? 'SELECT COALESCE(SUM(plays),0) AS plays, COALESCE(SUM(tickets),0) AS tickets,
                      COALESCE(SUM(value),0) AS value, COUNT(*) AS days
               FROM venue_daily_stats WHERE stat_date >= :p0 AND stat_date <= :p1'
            : 'SELECT COALESCE(SUM(plays),0) AS plays, COALESCE(SUM(tickets),0) AS tickets,
                      COALESCE(SUM(cash),0) AS value, COUNT(DISTINCT stat_date) AS days
               FROM game_daily_stats WHERE stat_date >= :p0 AND stat_date <= :p1',
        [$from, $toIncl]
    );
    return [
        'from'    => $from,
        'to'      => $toIncl,
        'plays'   => (int)($row['plays'] ?? 0),
        'tickets' => round((float)($row['tickets'] ?? 0), 1),
        'value'   => round((float)($row['value'] ?? 0), 2),
        'days'    => (int)($row['days'] ?? 0),
    ];
}

/**
 * Per-metric change from prior to current. `pct` is null when the prior year
 * has nothing to divide by — the UI shows "—" rather than a fake +100%.
 */
function analyticsYoyDelta(array $current, array $prior): array {
    $out = [];
    foreach (['plays', 'tickets', 'value'] as $k) {
        $c = (float)$current[$k];
        $p = (float)$prior[$k];
        $out[$k] = [
            'abs' => round($c - $p, 2),
            'pct' => $p > 0 ? round((($c - $p) / $p) * 100, 1) : null,
        ];
    }
    return $out;
}

/**
 * Month-to-date and year-to-date ACTUALS this year vs the identical stretch of
 * the prior year. Deliberately projection-free: both sides are completed days
 * pulled from the permanent rollups and cut at the same calendar point, so the
 * comparison is apples-to-apples without extrapolating today or averaging a
 * baseline.
 *
 * Money is scrubbed to 0 for roles without view_revenue, like every other
 * dollar figure in this file.
 */
function analyticsYoy(bool $hideMoney = false): void {
    list($tz, $tzName) = perfTimezone();
    $today = (new DateTime('now', $tz))->format('Y-m-d');

    $source  = analyticsYoySource();
    $through = analyticsYoyThrough($source, $today);

    // The newest day the source COULD have: yesterday, venue-local. Today is
    // partial by definition and never counted.
    $expected = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');

    $base = [
        'source'      => $source,
        'money_label' => $source === 'ledger' ? 'Play value' : 'Cash at readers',
        'money_hint'  => $source === 'ledger'
            ? 'Dollars spent at the readers (POS card ledger)'
            : 'Cash and card taken at the readers',
        'timezone'         => $tzName,
        'today'            => $today,
        'through'          => $through,
        'expected_through' => $expected,
        'stale_days'       => analyticsYoyStaleDays($through, $expected),
        'history_since'    => analyticsYoyHistorySince($source),
        'hide_money'       => $hideMoney,
    ];

    if ($through === null) {
        echo json_encode($base + ['has_data' => false, 'periods' => []]);
        return;
    }

    $priorThrough = analyticsYoyPriorDate($through);
    $year         = (int)substr($through, 0, 4);

    // Both windows are cut at $through, NOT at today — so when the source is
    // behind, "Month to date" can mean a month that ended weeks ago. Name the
    // month outright in that case ("July to date"): a card that reads "Month to
    // date · Jul 1 – Jul 16" on August 26th is the single most misleading thing
    // this widget can print, because every number under it is correct.
    $mtd  = substr($through, 0, 7);
    $mLbl = $mtd === substr($today, 0, 7)
        ? 'Month to date'
        : analyticsYoyMonthName($mtd) . ' to date';
    $yLbl = $year === (int)substr($today, 0, 4)
        ? 'Year to date'
        : $year . ' to date';

    $defs = [
        'mtd' => [
            'label'      => $mLbl,
            'from'       => $mtd . '-01',
            'prior_from' => substr($priorThrough, 0, 7) . '-01',
        ],
        'ytd' => [
            'label'      => $yLbl,
            'from'       => sprintf('%04d-01-01', $year),
            'prior_from' => sprintf('%04d-01-01', $year - 1),
        ],
    ];

    $periods = [];
    foreach ($defs as $key => $def) {
        $current = analyticsYoyWindow($source, $def['from'], $through);
        $prior   = analyticsYoyWindow($source, $def['prior_from'], $priorThrough);
        if ($hideMoney) {
            $current['value'] = 0.0;
            $prior['value']   = 0.0;
        }
        $delta = analyticsYoyDelta($current, $prior);
        if ($hideMoney) {
            $delta['value'] = ['abs' => 0.0, 'pct' => null];
        }
        $periods[] = [
            'key'             => $key,
            'label'           => $def['label'],
            'current'         => $current,
            'prior'           => $prior,
            'delta'           => $delta,
            'prior_has_data'  => $prior['days'] > 0,
        ];
    }

    echo json_encode($base + [
        'has_data'    => true,
        'current_year' => $year,
        'prior_year'   => $year - 1,
        'prior_through' => $priorThrough,
        'periods'     => $periods,
    ]);
}

/**
 * Breakage KPIs from the system-transaction feed for [startIso, endIso):
 * value expired off cards (points + tickets) and merge/expiration counts.
 * Zeroes when the card system doesn't report system transactions.
 */
function analyticsSystemTx(string $startIso, string $endIso): array {
    $row = DB::queryOne(
        'SELECT
             COALESCE(SUM(expired_regular + expired_bonus), 0) AS expired_points,
             COALESCE(SUM(expired_tickets), 0) AS expired_tickets,
             SUM(CASE WHEN type = \'expiration\' THEN 1 ELSE 0 END) AS expirations,
             SUM(CASE WHEN type = \'merge\' THEN 1 ELSE 0 END) AS merges
         FROM system_transactions
         WHERE transaction_time >= :p0 AND transaction_time < :p1',
        [$startIso, $endIso]
    );
    return [
        'expired_points'  => (float)($row['expired_points'] ?? 0),
        'expired_tickets' => (float)($row['expired_tickets'] ?? 0),
        'expirations'     => (int)($row['expirations'] ?? 0),
        'merges'          => (int)($row['merges'] ?? 0),
    ];
}

/**
 * Guest Insights for [startIso, endIso): who is actually walking in the door.
 *
 * A "guest" is a distinct card number (credit-card / cardless plays, card
 * "000000", and blanks are excluded — they aren't guests). A "visit" is a
 * distinct local calendar day a card was active, the standard FEC read of
 * frequency (one guest playing 40 games across the afternoon = one visit).
 *
 *  - new vs returning: a guest is NEW when their first-ever visit falls
 *    inside this window, RETURNING when it predates it. The durable
 *    card_activity ledger (Scheduler::rollupCardActivity) supplies the
 *    first-ever date; the 30-day raw feed alone can't reach back far enough.
 *    A card active in the window but not yet in the ledger (e.g. brand new
 *    today, before tonight's refresh) is classified from its earliest
 *    appearance in the window — which makes it correctly NEW.
 *  - frequency distribution: how many guests visited 1 / 2 / 3–4 / 5+ times.
 *  - repeat-visit rate: share of guests who came more than once.
 *  - spend per visit / per guest: cash + credit-card dollars on carded plays
 *    over the window, divided by visits / guests. Money — zeroed for roles
 *    without view_revenue by analyticsScrubMoney.
 *  - attach rate: share of visits with any spend. A rate, not a dollar
 *    figure (consistent with payment-mix counts being visible to all roles).
 *
 * @param DateTime $fromLocal window start in venue tz (for the new/returning cutoff date)
 */
function analyticsGuests(string $startIso, string $endIso, DateTimeZone $tz, DateTime $fromLocal, bool $excludeTimePlays = false): array {
    $fromDate = $fromLocal->format('Y-m-d');

    // Per-card facts within the window: distinct active days (visits),
    // earliest active day (new/returning fallback), and carded spend.
    // STREAMED (DB::each): $cards is bounded by distinct cards, but the read
    // behind it is every play in the window — a month of this venue's feed
    // outgrew the container's 128M limit, and blowing that is a fatal, so the
    // page died with no JSON body at all. Same trap the card_activity read
    // below already had to be rescued from.
    $cards = [];        // card => ['days' => set, 'earliest' => date, 'spend' => float]
    DB::each(
        'SELECT card_number, transaction_time,
                (cash_amount + credit_card_amount) AS spend
         FROM game_play_transactions
         WHERE transaction_time >= :p0 AND transaction_time < :p1
           AND card_number != \'\' AND card_number != \'000000\''
            . ($excludeTimePlays ? ' AND used_time_play = 0' : ''),
        [$startIso, $endIso],
        function (array $r) use (&$cards, $tz): void {
            $tt = (string)($r['transaction_time'] ?? '');
            if ($tt === '') return;
            try {
                $d = new DateTime($tt);
            } catch (Exception $e) {
                return;
            }
            $d->setTimezone($tz);
            $date = $d->format('Y-m-d');
            $card = (string)$r['card_number'];
            if (!isset($cards[$card])) {
                $cards[$card] = ['days' => [], 'earliest' => $date, 'spend' => 0.0];
            }
            $cards[$card]['days'][$date] = true;
            if ($date < $cards[$card]['earliest']) $cards[$card]['earliest'] = $date;
            $cards[$card]['spend'] += (float)($r['spend'] ?? 0);
        }
    );

    $totalGuests = count($cards);

    // Returning-eligible set: of the cards ACTIVE this window, which ones had a
    // first-ever visit BEFORE the window. We query the ledger ONLY for those
    // few-hundred active cards (chunked IN-lists), NOT the whole table: once
    // historical guest data is backfilled from the POS, card_activity can hold
    // hundreds of thousands to millions of rows, and loading them all into a PHP
    // array here exhausted memory and 500'd the page. Intersecting in SQL yields
    // the identical result at bounded cost.
    $priorCards = [];
    foreach (array_chunk(array_keys($cards), 400) as $chunk) {
        $ph = [];
        $params = [];
        foreach ($chunk as $i => $cn) { $ph[] = ':p' . $i; $params[] = (string)$cn; }
        $params[] = $fromDate;
        foreach (DB::query(
            'SELECT card_number FROM card_activity
             WHERE card_number IN (' . implode(',', $ph) . ')
               AND first_seen_date < :p' . count($chunk),
            $params
        ) as $r) {
            $priorCards[(string)$r['card_number']] = true;
        }
    }

    // New-vs-returning is only trustworthy when we have SUBSTANTIAL visit
    // history reaching back BEFORE the window starts. Otherwise returning guests
    // are invisible — a guest who really visited before the window but whose
    // earliest recorded visit falls inside it looks "new", collapsing the
    // returning count. A window barely past the history floor is the worst case:
    // e.g. a 30-day window with only 3 days of prior history counts almost
    // nobody as returning, so it can show FEWER returning guests than a 7-day
    // window (which had weeks of prior history). We therefore require the
    // pre-window history (fromDate − historyFloor) to be at least as long as the
    // window itself before trusting the split; shorter than that, the UI
    // suppresses it. As the ledger ages, longer windows qualify on their own.
    $floorRow = DB::queryOne('SELECT MIN(first_seen_date) AS f FROM card_activity');
    $trackingFloor = ($floorRow && !empty($floorRow['f'])) ? (string)$floorRow['f'] : null;

    $todayStr   = (new DateTime('now', $tz))->format('Y-m-d');
    $dFrom      = DateTime::createFromFormat('!Y-m-d', $fromDate, $tz);
    $dToday     = DateTime::createFromFormat('!Y-m-d', $todayStr, $tz);
    $windowDays = ($dFrom && $dToday) ? (int)$dFrom->diff($dToday)->days : 0;

    $lookbackDays = -1;
    if ($trackingFloor !== null && $trackingFloor < $fromDate) {
        $dFloor = DateTime::createFromFormat('!Y-m-d', $trackingFloor, $tz);
        if ($dFloor && $dFrom) $lookbackDays = (int)$dFloor->diff($dFrom)->days;
    }
    $classificationCovered = ($lookbackDays >= max(1, $windowDays));

    // The per-card metrics (guests, frequency, spend) come from the raw feed,
    // which only retains ~30 days — so on longer ranges they reflect just the
    // retained window, not the full range. Report the effective start so the UI
    // can say so instead of implying full-range coverage.
    $feedFloorRow = DB::queryOne(
        'SELECT MIN(transaction_time) AS t FROM game_play_transactions WHERE transaction_time != \'\''
    );
    $metricsSince = $fromDate;
    if ($feedFloorRow && !empty($feedFloorRow['t'])) {
        try {
            $fd = new DateTime((string)$feedFloorRow['t']);
            $fd->setTimezone($tz);
            $feedFloorDate = $fd->format('Y-m-d');
            if ($feedFloorDate > $fromDate) $metricsSince = $feedFloorDate;
        } catch (Exception $e) {
            // leave metricsSince = fromDate
        }
    }

    $newGuests = 0;
    $returningGuests = 0;
    $totalVisits = 0;
    $guestsWithSpend = 0;
    $repeatGuests = 0;
    $totalSpend = 0.0;
    $freq = ['one' => 0, 'two' => 0, 'three_four' => 0, 'five_plus' => 0];

    foreach ($cards as $card => $c) {
        $visits = count($c['days']);
        $totalVisits += $visits;
        if ($visits >= 2) $repeatGuests++;
        if ($visits == 1)        $freq['one']++;
        elseif ($visits == 2)    $freq['two']++;
        elseif ($visits <= 4)    $freq['three_four']++;
        else                     $freq['five_plus']++;

        // Returning when the ledger shows a first-ever visit before the
        // window; new otherwise (including cards not yet in the ledger, whose
        // first visit is by definition inside this window).
        if (isset($priorCards[$card])) {
            $returningGuests++;
        } else {
            $newGuests++;
        }

        if ($c['spend'] > 0) $guestsWithSpend++;
        $totalSpend += (float)$c['spend'];
    }

    return [
        'total_guests'      => $totalGuests,
        'new_guests'        => $newGuests,
        'returning_guests'  => $returningGuests,
        'new_pct'           => $totalGuests > 0 ? round($newGuests / $totalGuests * 100, 1) : null,
        'returning_pct'     => $totalGuests > 0 ? round($returningGuests / $totalGuests * 100, 1) : null,
        // Coverage: whether new/returning is reliable for this window, the
        // history floor, and the effective start of the raw-feed metrics.
        'classification_covered' => $classificationCovered,
        'history_since'     => $trackingFloor,
        'metrics_since'     => $metricsSince,
        'window_from'       => $fromDate,
        'total_visits'      => $totalVisits,
        'avg_visits'        => $totalGuests > 0 ? round($totalVisits / $totalGuests, 2) : null,
        'repeat_rate'       => $totalGuests > 0 ? round($repeatGuests / $totalGuests * 100, 1) : null,
        'frequency'         => $freq,
        'guests_with_spend' => $guestsWithSpend,
        'attach_rate'       => $totalGuests > 0 ? round($guestsWithSpend / $totalGuests * 100, 1) : null,
        // Money — scrubbed for non-view_revenue roles.
        'total_spend'       => round($totalSpend, 2),
        'spend_per_visit'   => $totalVisits > 0 ? round($totalSpend / $totalVisits, 2) : 0.0,
        'spend_per_guest'   => $totalGuests > 0 ? round($totalSpend / $totalGuests, 2) : 0.0,
    ];
}

/**
 * Plays / tickets / cash bucketed by game category for the range.
 *
 * Purely local: per-game aggregates from the raw feed joined against the
 * category memberships cached in game_state_cache, with names resolved from
 * the cache_categories payload in api_config (read directly, ignoring the
 * TTL — stale names beat "Category 7", and the daily sync refreshes them).
 * A game in multiple categories counts toward each (documented in the UI),
 * and games with no category land in an "Uncategorized" bucket, so nothing
 * silently disappears. Cash here is money and is zeroed for non-view_revenue
 * roles by analyticsScrubMoney.
 */
function analyticsByCategory(string $startIso, string $endIso, bool $excludeTimePlays = false): array {
    $perGame = DB::query(
        'SELECT game_id,
                COUNT(*) AS plays,
                COALESCE(SUM(redemption_tickets), 0) AS tickets,
                COALESCE(SUM(cash_amount + credit_card_amount), 0) AS cash
         FROM game_play_transactions
         WHERE transaction_time >= :p0 AND transaction_time < :p1
           AND game_id != \'\''
            . ($excludeTimePlays ? ' AND used_time_play = 0' : '') . '
         GROUP BY game_id',
        [$startIso, $endIso]
    );
    if (empty($perGame)) {
        return [];
    }

    // game_id -> [category ids]
    $memberships = [];
    foreach (DB::query('SELECT game_id, categories FROM game_state_cache') as $r) {
        $cats = json_decode((string)($r['categories'] ?? '[]'), true);
        $memberships[(string)$r['game_id']] = is_array($cats) ? $cats : [];
    }

    // category id -> name (best-effort from the cached /games/categories payload)
    $names = [];
    $rawCats = DB::getConfig('cache_categories');
    if ($rawCats) {
        $decoded = json_decode($rawCats, true);
        if (is_array($decoded)) {
            foreach ($decoded as $c) {
                if (is_array($c) && isset($c['id'])) {
                    $names[(string)$c['id']] = trim((string)($c['name'] ?? ''));
                }
            }
        }
    }

    $buckets = [];
    foreach ($perGame as $g) {
        $gid = (string)$g['game_id'];
        $cats = $memberships[$gid] ?? [];
        $keys = [];
        foreach ($cats as $cid) {
            if (is_int($cid) || is_string($cid) || is_float($cid)) {
                $keys[] = (string)$cid;
            }
        }
        if (empty($keys)) {
            $keys = ['__none__'];
        }
        foreach ($keys as $key) {
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'category_id' => $key === '__none__' ? null : $key,
                    'name'        => $key === '__none__'
                        ? 'Uncategorized'
                        : (($names[$key] ?? '') !== '' ? $names[$key] : 'Category ' . $key),
                    'plays'   => 0,
                    'tickets' => 0.0,
                    'cash'    => 0.0,
                    'games'   => 0,
                ];
            }
            $buckets[$key]['plays']   += (int)$g['plays'];
            $buckets[$key]['tickets'] += (float)$g['tickets'];
            $buckets[$key]['cash']    += (float)$g['cash'];
            $buckets[$key]['games']   += 1;
        }
    }

    $out = array_values($buckets);
    usort($out, function ($a, $b) {
        return $b['plays'] <=> $a['plays'];
    });
    foreach ($out as &$row) {
        $row['tickets'] = round($row['tickets'], 2);
        $row['cash']    = round($row['cash'], 2);
    }
    unset($row);
    return $out;
}

/**
 * Snapshot of fleet posture (games, kiosks, groups, overrides, retries).
 */
function analyticsFleet(): array {
    // Game state mix.
    $gameStates = DB::query('SELECT operation_status, COUNT(*) AS c FROM game_state_cache GROUP BY operation_status');
    $games = ['enabled' => 0, 'paused' => 0, 'outOfService' => 0];
    foreach ($gameStates as $r) {
        $s = (string)$r['operation_status'];
        if (isset($games[$s])) $games[$s] = (int)$r['c'];
    }
    $totalGames = array_sum($games);

    // Kiosk state mix — kiosks with empty operation_status are "unknown" per spec.
    $kioskStates = DB::query('SELECT operation_status, COUNT(*) AS c FROM kiosk_state_cache GROUP BY operation_status');
    $kiosks = ['enabled' => 0, 'paused' => 0, 'outOfService' => 0, 'unknown' => 0];
    foreach ($kioskStates as $r) {
        $s = (string)$r['operation_status'];
        if ($s === '') {
            $kiosks['unknown'] += (int)$r['c'];
        } elseif (isset($kiosks[$s])) {
            $kiosks[$s] = (int)$r['c'];
        }
    }
    $totalKiosks = array_sum($kiosks);

    $activeGroups = (int)(DB::queryOne('SELECT COUNT(*) AS c FROM pause_groups WHERE is_active = 1')['c'] ?? 0);
    $manualOverrideGroups = (int)(DB::queryOne('SELECT COUNT(*) AS c FROM pause_groups WHERE manual_override_action IS NOT NULL')['c'] ?? 0);

    // schedule_overrides stores venue-local 'Y-m-d H:i' strings (see
    // api/overrides.php) — compare in the same format. A UTC ISO string here
    // silently dropped every override still running the same UTC day.
    $tzName = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    $nowLocal = (new DateTime('now', new DateTimeZone($tzName)))->format('Y-m-d H:i');
    $activeOverrides = (int)(DB::queryOne(
        'SELECT COUNT(*) AS c FROM schedule_overrides WHERE start_datetime <= :p0 AND end_datetime > :p0',
        [$nowLocal]
    )['c'] ?? 0);

    $pendingRetries = (int)(DB::queryOne(
        'SELECT COUNT(*) AS c FROM action_retries WHERE attempts < max_attempts'
    )['c'] ?? 0);

    return [
        'total_games'          => $totalGames,
        'enabled_games'        => $games['enabled'],
        'paused_games'         => $games['paused'],
        'out_of_service_games' => $games['outOfService'],
        'total_kiosks'         => $totalKiosks,
        'enabled_kiosks'       => $kiosks['enabled'],
        'paused_kiosks'        => $kiosks['paused'],
        'out_of_service_kiosks'=> $kiosks['outOfService'],
        'unknown_kiosks'       => $kiosks['unknown'],
        'active_groups'        => $activeGroups,
        'groups_with_manual_override' => $manualOverrideGroups,
        'active_overrides'     => $activeOverrides,
        'pending_retries'      => $pendingRetries,
    ];
}

/**
 * Top-N games leaderboard for the given metric.
 * metric: 'plays' | 'tickets' | 'cash'
 */
function analyticsTopGames(string $startIso, string $endIso, string $metric, int $limit = 10, bool $excludeTimePlays = false): array {
    $orderExpr = 'plays';
    if ($metric === 'tickets') $orderExpr = 'tickets';
    elseif ($metric === 'cash') $orderExpr = 'cash';

    $rows = DB::query(
        'SELECT t.game_id,
                COALESCE(NULLIF(MAX(t.game_description), \'\'), MAX(c.game_name), t.game_id) AS game_name,
                COUNT(*) AS plays,
                COALESCE(SUM(t.redemption_tickets), 0) AS tickets,
                COALESCE(SUM(t.cash_amount + t.credit_card_amount), 0) AS cash
         FROM game_play_transactions t
         LEFT JOIN game_state_cache c ON c.game_id = t.game_id
         WHERE t.transaction_time >= :p0 AND t.transaction_time < :p1
           AND t.game_id != \'\''
            . ($excludeTimePlays ? ' AND t.used_time_play = 0' : '') . '
         GROUP BY t.game_id
         ORDER BY ' . $orderExpr . ' DESC
         LIMIT ' . max(1, (int)$limit),
        [$startIso, $endIso]
    );

    return array_map(function ($r) {
        return [
            'game_id'   => (string)$r['game_id'],
            'game_name' => (string)($r['game_name'] ?? $r['game_id']),
            'plays'     => (int)$r['plays'],
            'tickets'   => (float)$r['tickets'],
            'cash'      => (float)$r['cash'],
        ];
    }, $rows);
}

/**
 * action_log breakdowns within [startSql, endSql). action_log.timestamp is
 * stored as 'YYYY-MM-DD HH:MM:SS' in UTC.
 */
function analyticsActionsBreakdown(string $startSql, string $endSql): array {
    $bySource = [
        'cron' => 0, 'manual' => 0, 'override' => 0,
        'schedule' => 0, 'watchdog' => 0, 'expired_override' => 0,
    ];
    $rows = DB::query(
        'SELECT source, COUNT(*) AS c
         FROM action_log
         WHERE timestamp >= :p0 AND timestamp < :p1
           AND action IN (\'pause\', \'unpause\')
         GROUP BY source',
        [$startSql, $endSql]
    );
    foreach ($rows as $r) {
        $s = (string)$r['source'];
        if (!array_key_exists($s, $bySource)) $bySource[$s] = 0;
        $bySource[$s] = (int)$r['c'];
    }

    $byAction = ['pause' => 0, 'unpause' => 0];
    $rows = DB::query(
        'SELECT action, COUNT(*) AS c
         FROM action_log
         WHERE timestamp >= :p0 AND timestamp < :p1
           AND action IN (\'pause\', \'unpause\')
         GROUP BY action',
        [$startSql, $endSql]
    );
    foreach ($rows as $r) {
        $byAction[$r['action']] = (int)$r['c'];
    }

    $successFail = ['success' => 0, 'fail' => 0];
    $rows = DB::query(
        'SELECT success, COUNT(*) AS c
         FROM action_log
         WHERE timestamp >= :p0 AND timestamp < :p1
           AND action IN (\'pause\', \'unpause\')
         GROUP BY success',
        [$startSql, $endSql]
    );
    foreach ($rows as $r) {
        if ((int)$r['success'] === 1) $successFail['success'] = (int)$r['c'];
        else $successFail['fail'] = (int)$r['c'];
    }

    return [
        'by_source'    => $bySource,
        'by_action'    => $byAction,
        'success_fail' => $successFail,
    ];
}

/**
 * Iterate every calendar day from $start through $end-1 in $tz, filling any
 * missing days with zero so the trend chart renders a true continuous line.
 */
function analyticsFillDailyGaps(array $dailyByKey, DateTime $start, DateTime $end, DateTimeZone $tz): array {
    $cursor = (clone $start)->setTime(0, 0, 0);
    $stop   = (clone $end);
    $out = [];
    // Cap at ~370 days to keep the response bounded for the "all" range.
    $limit = 370;
    while ($cursor < $stop && $limit-- > 0) {
        $key = $cursor->format('Y-m-d');
        if (isset($dailyByKey[$key])) {
            $out[] = $dailyByKey[$key];
        } else {
            $out[] = ['date' => $key, 'plays' => 0, 'tickets' => 0.0, 'cash' => 0.0];
        }
        $cursor->modify('+1 day');
    }
    return $out;
}

// =====================================================================
// Game Performance reporting engine
//
// Two endpoints share this machinery:
//   GET /api/analytics/games  → searchable/sortable per-game leaderboard for a
//                               period, plus venue KPIs and a trend series.
//   GET /api/analytics/game   → one game's KPIs + trend + prior-period compare.
//
// Time model: every period is expressed as venue-local calendar dates. Totals
// come from two disjoint sources stitched together so they're always correct
// and always live:
//   - Days within the last ~28 days are read from the RAW feed
//     (game_play_transactions), so "today" and the recent window never depend
//     on the nightly rollup having run yet.
//   - Older days are read from the permanent game_daily_stats ROLLUP.
// The split point is safely inside the raw-retention window, so the two
// sources never overlap (no double-count) and never gap.
// =====================================================================

/** Resolve the venue timezone into [DateTimeZone, name]. */
function perfTimezone(): array {
    $tzName = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
        $tzName = 'UTC';
    }
    return [$tz, $tzName];
}

/**
 * Resolve the requested reporting window from query params into calendar
 * boundaries, granularity, a display label, and the comparable previous period.
 *
 * Params:
 *   range  = day | week | month | year | custom   (default week)
 *   offset = integer number of whole periods to step from the anchor (0 = current,
 *            -1 = previous, +1 = next). Ignored for custom.
 *   anchor = YYYY-MM-DD to base presets on (default: today).
 *   from,to = YYYY-MM-DD (custom only).
 *
 * Weeks are Sunday-start. Month/year are calendar-aligned. The previous period
 * is the immediately preceding comparable span.
 */
function perfResolveWindow(DateTimeZone $tz): array {
    $now   = new DateTime('now', $tz);
    $today = (clone $now)->setTime(0, 0, 0);

    $range = isset($_GET['range']) ? (string)$_GET['range'] : 'week';
    if (!in_array($range, ['day', 'week', 'month', 'year', 'custom'], true)) {
        $range = 'week';
    }
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    if ($offset < -1200) $offset = -1200;
    if ($offset >  1200) $offset =  1200;

    $anchor = clone $today;
    if (isset($_GET['anchor']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['anchor'])) {
        $a = DateTime::createFromFormat('!Y-m-d', (string)$_GET['anchor'], $tz);
        if ($a) $anchor = $a;
    }

    $step = function (DateTime $d, string $unit, int $n): DateTime {
        $d = clone $d;
        if ($n !== 0) $d->modify(($n > 0 ? '+' : '') . $n . ' ' . $unit);
        return $d;
    };

    if ($range === 'custom') {
        $from = isset($_GET['from']) ? (string)$_GET['from'] : '';
        $to   = isset($_GET['to'])   ? (string)$_GET['to']   : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new RuntimeException('Custom range requires from/to in YYYY-MM-DD format.');
        }
        $start = DateTime::createFromFormat('!Y-m-d', $from, $tz);
        $end   = DateTime::createFromFormat('!Y-m-d', $to, $tz);
        if (!$start || !$end) {
            throw new RuntimeException('Invalid custom range dates.');
        }
        $endExcl = (clone $end)->modify('+1 day');
        if ($endExcl <= $start) {
            throw new RuntimeException('Custom range "to" must be on or after "from".');
        }
        $spanDays = (int)round(($endExcl->getTimestamp() - $start->getTimestamp()) / 86400);
        if ($spanDays > 1830) {
            throw new RuntimeException('Custom range is too large (max ~5 years).');
        }
        $gran = $spanDays <= 1 ? 'hour' : ($spanDays <= 92 ? 'day' : 'month');
        $label = $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
        $prevStart   = (clone $start)->modify('-' . $spanDays . ' days');
        $prevEndExcl = clone $start;
    } elseif ($range === 'day') {
        $d = $step($anchor, 'day', $offset);
        $start = clone $d;
        $endExcl = (clone $d)->modify('+1 day');
        $gran = 'hour';
        $label = $d->format('l, M j, Y');
        $prevStart   = (clone $start)->modify('-1 day');
        $prevEndExcl = clone $start;
    } elseif ($range === 'week') {
        $dow = (int)$anchor->format('w'); // 0 = Sunday
        $ws = $step((clone $anchor)->modify('-' . $dow . ' days'), 'day', $offset * 7);
        $start = $ws;
        $endExcl = (clone $ws)->modify('+7 days');
        $gran = 'day';
        $label = $start->format('M j') . ' – ' . (clone $endExcl)->modify('-1 day')->format('M j, Y');
        $prevStart   = (clone $start)->modify('-7 days');
        $prevEndExcl = clone $start;
    } elseif ($range === 'month') {
        $ms = $step(DateTime::createFromFormat('!Y-m-d', $anchor->format('Y-m') . '-01', $tz), 'month', $offset);
        $start = $ms;
        $endExcl = (clone $ms)->modify('+1 month');
        $gran = 'day';
        $label = $start->format('F Y');
        $prevStart   = (clone $ms)->modify('-1 month');
        $prevEndExcl = clone $ms;
    } else { // year
        $ys = $step(DateTime::createFromFormat('!Y-m-d', $anchor->format('Y') . '-01-01', $tz), 'year', $offset);
        $start = $ys;
        $endExcl = (clone $ys)->modify('+1 year');
        $gran = 'month';
        $label = $start->format('Y');
        $prevStart   = (clone $ys)->modify('-1 year');
        $prevEndExcl = clone $ys;
    }

    return [
        'range'        => $range,
        'offset'       => $offset,
        'granularity'  => $gran,
        'label'        => $label,
        'start'        => $start,
        'endExcl'      => $endExcl,
        'from'         => $start->format('Y-m-d'),
        'to'           => (clone $endExcl)->modify('-1 day')->format('Y-m-d'),
        'prev_start'   => $prevStart,
        'prev_endExcl' => $prevEndExcl,
        'prev_from'    => $prevStart->format('Y-m-d'),
        'prev_to'      => (clone $prevEndExcl)->modify('-1 day')->format('Y-m-d'),
    ];
}

/** Compact metadata block describing the resolved window for the client. */
function perfRangeMeta(array $win, string $tzName): array {
    return [
        'range'       => $win['range'],
        'offset'      => $win['offset'],
        'granularity' => $win['granularity'],
        'label'       => $win['label'],
        'from'        => $win['from'],
        'to'          => $win['to'],
        'prev_from'   => $win['prev_from'],
        'prev_to'     => $win['prev_to'],
        'timezone'    => $tzName,
    ];
}

/**
 * Chunked `game_id IN (...)` fragments for a member filter, as [sql, params]
 * pairs to append to a query whose own bindings start at :p{count($chunk)}.
 * One pair per chunk; an empty/absent filter yields a single unfiltered pair.
 *
 * @param ?array<string,mixed> $onlyGames game_id => anything, or null for all
 * @return array<int,array{0:string,1:array}> [clause, leading params]
 */
function perfGameFilterChunks(?array $onlyGames): array {
    if ($onlyGames === null || !$onlyGames) return [['', []]];
    $out = [];
    foreach (array_chunk(array_keys($onlyGames), 400) as $chunk) {
        $ph = [];
        $params = [];
        foreach ($chunk as $i => $gid) { $ph[] = ':p' . $i; $params[] = (string)$gid; }
        $out[] = ['game_id IN (' . implode(',', $ph) . ') AND ', $params];
    }
    return $out;
}

/**
 * Per-game daily totals read from the RAW feed for [$fromDate, $toDate]
 * (inclusive local dates), bucketed by local calendar date in PHP so day
 * boundaries are correct regardless of the timestamp's "Z"/offset format.
 * Returns ['byDate' => [date => [gid => totals]], 'names' => [gid => name]].
 *
 * Rows are STREAMED (DB::each) rather than fetched into an array: the output is
 * a few hundred day/game buckets, but a month of this venue's feed is ~150k
 * rows and materializing it costs well over the container's 128M limit. See
 * DB::each().
 *
 * $onlyGames narrows the read to a set of game_ids IN SQL (chunked IN-lists),
 * for a caller that only reports on one area — otherwise a request about eight
 * go-kart readers decodes a timestamp for every swipe in the building.
 */
function perfRawDailyPerGame(string $fromDate, string $toDate, DateTimeZone $tz, ?array $onlyGames = null): array {
    if ($fromDate > $toDate) return ['byDate' => [], 'names' => []];
    if ($onlyGames !== null && !$onlyGames) return ['byDate' => [], 'names' => []];
    $utc = new DateTimeZone('UTC');
    $start   = DateTime::createFromFormat('!Y-m-d', $fromDate, $tz);
    $endExcl = DateTime::createFromFormat('!Y-m-d', $toDate, $tz)->modify('+1 day');
    // Pad ±1 day in UTC so an offset never clips boundary rows; we re-filter by
    // exact local date below.
    $lo = (clone $start)->setTimezone($utc)->modify('-1 day')->format('Y-m-d\TH:i:s\Z');
    $hi = (clone $endExcl)->setTimezone($utc)->modify('+1 day')->format('Y-m-d\TH:i:s\Z');

    $byDate = [];
    $names = [];
    $accumulate = function (array $r) use (&$byDate, &$names, $tz, $fromDate, $toDate): void {
        $tt = $r['transaction_time'] ?? '';
        if ($tt === '') return;
        try {
            $d = new DateTime($tt);
        } catch (Exception $e) {
            return;
        }
        $d->setTimezone($tz);
        $date = $d->format('Y-m-d');
        if ($date < $fromDate || $date > $toDate) return;
        $gid = (string)($r['game_id'] ?? '');
        if ($gid === '') return;

        if (!isset($byDate[$date][$gid])) {
            $byDate[$date][$gid] = ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0, 'regular_points' => 0.0, 'bonus_points' => 0.0, 'time_plays' => 0];
        }
        $byDate[$date][$gid]['plays']          += 1;
        $byDate[$date][$gid]['tickets']        += (float)($r['redemption_tickets'] ?? 0);
        $byDate[$date][$gid]['cash']           += (float)($r['cash_amount'] ?? 0);
        $byDate[$date][$gid]['regular_points'] += (float)($r['regular_points'] ?? 0);
        $byDate[$date][$gid]['bonus_points']   += (float)($r['bonus_points'] ?? 0);
        $byDate[$date][$gid]['time_plays']     += ((int)($r['used_time_play'] ?? 0)) ? 1 : 0;

        $desc = trim((string)($r['game_description'] ?? ''));
        if ($desc !== '') $names[$gid] = $desc;
    };

    foreach (perfGameFilterChunks($onlyGames) as [$clause, $params]) {
        $n = count($params);
        $params[] = $lo;
        $params[] = $hi;
        DB::each(
            'SELECT transaction_time, game_id, game_description,
                    redemption_tickets, (cash_amount + credit_card_amount) AS cash_amount,
                    regular_points, bonus_points, used_time_play
             FROM game_play_transactions
             WHERE ' . $clause . 'transaction_time >= :p' . $n . ' AND transaction_time < :p' . ($n + 1),
            $params,
            $accumulate
        );
    }
    return ['byDate' => $byDate, 'names' => $names];
}

/**
 * Per-game daily totals read from the permanent rollup for [$fromDate,$toDate].
 * Streamed and optionally game-filtered for the same reasons as the raw reader
 * above — a Year view spans ~365 days × every game the venue has ever run.
 */
function perfRollupDailyPerGame(string $fromDate, string $toDate, ?array $onlyGames = null): array {
    if ($fromDate > $toDate) return ['byDate' => [], 'names' => []];
    if ($onlyGames !== null && !$onlyGames) return ['byDate' => [], 'names' => []];
    $byDate = [];
    $names = [];
    $accumulate = function (array $r) use (&$byDate, &$names): void {
        $date = (string)$r['stat_date'];
        $gid  = (string)$r['game_id'];
        $byDate[$date][$gid] = [
            'plays'          => (int)$r['plays'],
            'tickets'        => (float)$r['tickets'],
            'cash'           => (float)$r['cash'],
            'regular_points' => (float)$r['regular_points'],
            'bonus_points'   => (float)$r['bonus_points'],
            'time_plays'     => (int)($r['time_plays'] ?? 0),
        ];
        $nm = trim((string)$r['game_name']);
        if ($nm !== '') $names[$gid] = $nm;
    };

    foreach (perfGameFilterChunks($onlyGames) as [$clause, $params]) {
        $n = count($params);
        $params[] = $fromDate;
        $params[] = $toDate;
        DB::each(
            'SELECT stat_date, game_id, game_name, plays, tickets, cash, regular_points, bonus_points, time_plays
             FROM game_daily_stats
             WHERE ' . $clause . 'stat_date >= :p' . $n . ' AND stat_date <= :p' . ($n + 1),
            $params,
            $accumulate
        );
    }
    return ['byDate' => $byDate, 'names' => $names];
}

/**
 * Combined per-game daily totals for [$fromDate,$toDate], stitching the rollup
 * (older days) and the raw feed (recent days) at a split point safely inside
 * raw retention. toDate is clamped to today (no future data).
 *
 * $onlyGames (game_id => anything) narrows both sides to that set in SQL, for a
 * caller reporting on one area rather than the whole venue. Null = every game,
 * which is what the venue-wide Analytics/Performance endpoints want.
 */
function perfDailyPerGame(string $fromDate, string $toDate, DateTimeZone $tz, ?array $onlyGames = null): array {
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    if ($toDate > $today) $toDate = $today;
    if ($fromDate > $toDate) return ['byDate' => [], 'names' => []];

    // Oldest local date we trust the raw feed to fully cover. Kept in lockstep
    // with Scheduler::rollupDailyStats()'s recompute window and comfortably
    // inside the 30-day raw purge boundary.
    $rawCoverStart = (new DateTime('now', $tz))->modify('-28 days')->format('Y-m-d');
    $rawBefore = (new DateTime($rawCoverStart))->modify('-1 day')->format('Y-m-d');

    $byDate = [];
    $names  = [];

    $rollupTo = ($toDate < $rawBefore) ? $toDate : $rawBefore;
    if ($fromDate <= $rollupTo) {
        $r = perfRollupDailyPerGame($fromDate, $rollupTo, $onlyGames);
        foreach ($r['byDate'] as $date => $games) $byDate[$date] = $games;
        $names += $r['names'];
    }

    $rawFrom = ($fromDate > $rawCoverStart) ? $fromDate : $rawCoverStart;
    if ($rawFrom <= $toDate) {
        $r = perfRawDailyPerGame($rawFrom, $toDate, $tz, $onlyGames);
        foreach ($r['byDate'] as $date => $games) $byDate[$date] = $games;
        $names = $r['names'] + $names;
    }

    return ['byDate' => $byDate, 'names' => $names];
}

/** Collapse a byDate structure into per-game period totals. */
function perfSumPerGame(array $daily): array {
    $tot = [];
    foreach ($daily['byDate'] as $games) {
        foreach ($games as $gid => $b) {
            if (!isset($tot[$gid])) {
                $tot[$gid] = ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0, 'regular_points' => 0.0, 'bonus_points' => 0.0];
            }
            $tot[$gid]['plays']          += (int)$b['plays'];
            $tot[$gid]['tickets']        += (float)$b['tickets'];
            $tot[$gid]['cash']           += (float)$b['cash'];
            $tot[$gid]['regular_points'] += (float)$b['regular_points'];
            $tot[$gid]['bonus_points']   += (float)$b['bonus_points'];
        }
    }
    return $tot;
}

/** Venue-wide KPI totals from a per-game totals map. */
function perfVenueTotals(array $perGame): array {
    $plays = 0; $tickets = 0.0; $cash = 0.0; $reg = 0.0; $bon = 0.0;
    foreach ($perGame as $t) {
        $plays   += (int)$t['plays'];
        $tickets += (float)$t['tickets'];
        $cash    += (float)$t['cash'];
        $reg     += (float)$t['regular_points'];
        $bon     += (float)$t['bonus_points'];
    }
    return [
        'plays'                => $plays,
        'tickets'              => round($tickets, 2),
        'cash'                 => round($cash, 2),
        'points'               => round($reg + $bon, 2),
        'active_games'         => count($perGame),
        'avg_tickets_per_play' => $plays > 0 ? round($tickets / $plays, 2) : 0,
        'avg_cash_per_play'    => $plays > 0 ? round($cash / $plays, 2) : 0,
    ];
}

/** One game's KPI totals. */
function perfGameKpi(array $t): array {
    $plays = (int)$t['plays'];
    $tickets = (float)$t['tickets'];
    $cash = (float)$t['cash'];
    return [
        'plays'                => $plays,
        'tickets'              => round($tickets, 2),
        'cash'                 => round($cash, 2),
        'points'               => round((float)$t['regular_points'] + (float)$t['bonus_points'], 2),
        'avg_tickets_per_play' => $plays > 0 ? round($tickets / $plays, 2) : 0,
        'avg_cash_per_play'    => $plays > 0 ? round($cash / $plays, 2) : 0,
    ];
}

/** 12a / 1a … 11p hour labels for the hourly series. */
function perfHourLabel(int $h): string {
    $suffix = $h < 12 ? 'a' : 'p';
    $hour12 = $h % 12;
    if ($hour12 === 0) $hour12 = 12;
    return $hour12 . $suffix;
}

/**
 * 24-bucket hourly series for a single local day, optionally filtered to one
 * game. Only meaningful for days still in the raw feed; older days return zeros
 * (the daily total is still available via the KPIs).
 */
function perfHourlySeries(?string $gid, string $date, DateTimeZone $tz): array {
    $utc = new DateTimeZone('UTC');
    $start = DateTime::createFromFormat('!Y-m-d', $date, $tz);
    if (!$start) return ['granularity' => 'hour', 'points' => []];
    $endExcl = (clone $start)->modify('+1 day');
    $lo = (clone $start)->setTimezone($utc)->modify('-1 day')->format('Y-m-d\TH:i:s\Z');
    $hi = (clone $endExcl)->setTimezone($utc)->modify('+1 day')->format('Y-m-d\TH:i:s\Z');

    $params = [$lo, $hi];
    $sql = 'SELECT transaction_time, redemption_tickets,
                   (cash_amount + credit_card_amount) AS cash_amount
            FROM game_play_transactions
            WHERE transaction_time >= :p0 AND transaction_time < :p1';
    if ($gid !== null && $gid !== '') {
        $sql .= ' AND game_id = :p2';
        $params[] = $gid;
    }
    $rows = DB::query($sql, $params);

    $buckets = array_fill(0, 24, ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0]);
    foreach ($rows as $r) {
        $tt = $r['transaction_time'] ?? '';
        if ($tt === '') continue;
        try {
            $d = new DateTime($tt);
        } catch (Exception $e) {
            continue;
        }
        $d->setTimezone($tz);
        if ($d->format('Y-m-d') !== $date) continue;
        $h = (int)$d->format('G');
        $buckets[$h]['plays']   += 1;
        $buckets[$h]['tickets'] += (float)($r['redemption_tickets'] ?? 0);
        $buckets[$h]['cash']    += (float)($r['cash_amount'] ?? 0);
    }

    $points = [];
    for ($h = 0; $h < 24; $h++) {
        $points[] = [
            'label'   => perfHourLabel($h),
            'plays'   => $buckets[$h]['plays'],
            'tickets' => round($buckets[$h]['tickets'], 2),
            'cash'    => round($buckets[$h]['cash'], 2),
        ];
    }
    return ['granularity' => 'hour', 'points' => $points];
}

/**
 * Day- or month-bucketed trend series over the resolved window, optionally
 * filtered to one game. Buckets beyond today are omitted so the current period
 * doesn't render a run of trailing zeros.
 */
function perfBucketSeries(array $win, DateTimeZone $tz, array $byDate, ?string $gid): array {
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $gran  = $win['granularity'];

    $sumForDate = function (string $date) use ($byDate, $gid) {
        $games = $byDate[$date] ?? [];
        $p = 0; $t = 0.0; $c = 0.0;
        if ($gid !== null && $gid !== '') {
            if (isset($games[$gid])) {
                $p = (int)$games[$gid]['plays'];
                $t = (float)$games[$gid]['tickets'];
                $c = (float)$games[$gid]['cash'];
            }
        } else {
            foreach ($games as $b) {
                $p += (int)$b['plays'];
                $t += (float)$b['tickets'];
                $c += (float)$b['cash'];
            }
        }
        return [$p, $t, $c];
    };

    $points = [];
    if ($gran === 'day') {
        $cur = clone $win['start'];
        $endExcl = clone $win['endExcl'];
        while ($cur < $endExcl) {
            $date = $cur->format('Y-m-d');
            if ($date <= $today) {
                list($p, $t, $c) = $sumForDate($date);
                $points[] = ['label' => $cur->format('M j'), 'date' => $date, 'plays' => $p, 'tickets' => round($t, 2), 'cash' => round($c, 2)];
            }
            $cur->modify('+1 day');
        }
    } else { // month
        $cur = DateTime::createFromFormat('!Y-m-d', $win['start']->format('Y-m') . '-01', $tz);
        $endExcl = clone $win['endExcl'];
        while ($cur < $endExcl) {
            $mStart = clone $cur;
            $mEnd   = (clone $cur)->modify('+1 month');
            if ($mStart->format('Y-m-d') <= $today) {
                $p = 0; $t = 0.0; $c = 0.0;
                $d = clone $mStart;
                while ($d < $mEnd) {
                    $date = $d->format('Y-m-d');
                    if ($date <= $today) {
                        list($dp, $dt, $dc) = $sumForDate($date);
                        $p += $dp; $t += $dt; $c += $dc;
                    }
                    $d->modify('+1 day');
                }
                $points[] = ['label' => $cur->format('M'), 'month' => $cur->format('Y-m'), 'plays' => $p, 'tickets' => round($t, 2), 'cash' => round($c, 2)];
            }
            $cur->modify('+1 month');
        }
    }
    return ['granularity' => $gran, 'points' => $points];
}

/** Zero out every monetary field so the 'tech' role never receives cash data. */
function perfScrubMoney(array &$payload): void {
    foreach (['totals', 'previous_totals'] as $k) {
        if (isset($payload[$k]) && is_array($payload[$k])) {
            if (array_key_exists('cash', $payload[$k])) $payload[$k]['cash'] = 0.0;
            if (array_key_exists('avg_cash_per_play', $payload[$k])) $payload[$k]['avg_cash_per_play'] = 0.0;
        }
    }
    if (isset($payload['games']) && is_array($payload['games'])) {
        foreach ($payload['games'] as &$g) {
            if (array_key_exists('cash', $g)) $g['cash'] = 0.0;
            if (array_key_exists('prev_cash', $g)) $g['prev_cash'] = 0.0;
        }
        unset($g);
    }
    if (isset($payload['series']['points']) && is_array($payload['series']['points'])) {
        foreach ($payload['series']['points'] as &$pt) {
            if (array_key_exists('cash', $pt)) $pt['cash'] = 0.0;
        }
        unset($pt);
    }
}

/**
 * GET /api/analytics/games — searchable, sortable, paginated per-game
 * leaderboard for the resolved window, plus venue KPIs (with prior-period
 * comparison) and a venue-wide trend series.
 */
function analyticsGamesLeaderboard(bool $hideMoney): void {
    list($tz, $tzName) = perfTimezone();
    $win = perfResolveWindow($tz);

    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'tickets';
    if (!in_array($sort, ['tickets', 'plays', 'cash', 'name', 'status', 'payout', 'points_share', 'active_days', 'avg_tickets_per_play'], true)) $sort = 'tickets';
    if ($hideMoney && $sort === 'cash') $sort = 'tickets';
    // Direction is optional; the default preserves each column's historical
    // order (name A→Z, metrics biggest-first).
    $dir = isset($_GET['dir']) ? (string)$_GET['dir'] : '';
    if (!in_array($dir, ['asc', 'desc'], true)) {
        $dir = ($sort === 'name' || $sort === 'status') ? 'asc' : 'desc';
    }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = max(1, min(200, (int)($_GET['page_size'] ?? 25)));

    $dailyCur  = perfDailyPerGame($win['from'], $win['to'], $tz);
    $dailyPrev = perfDailyPerGame($win['prev_from'], $win['prev_to'], $tz);
    $curTot  = perfSumPerGame($dailyCur);
    $prevTot = perfSumPerGame($dailyPrev);

    // Redemption classification from the venue's "Redemption" grouping
    // (category/pause group), shared with the payout gauge. Powers the
    // per-game payout %, the share of the venue's redemption-point
    // denominator, and keeps rides/cages out of the ratio.
    $redemption = Reporting::redemptionGameIds();

    // Utilization: active days (days with >=1 play in the window) + last active
    // date, straight from the stitched daily buckets.
    $activeDays = [];
    $lastActive = [];
    foreach ($dailyCur['byDate'] as $date => $games) {
        foreach ($games as $gid => $b) {
            if ((int)$b['plays'] > 0) {
                $activeDays[$gid] = ($activeDays[$gid] ?? 0) + 1;
                if (!isset($lastActive[$gid]) || $date > $lastActive[$gid]) $lastActive[$gid] = (string)$date;
            }
        }
    }
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $rangeTo = ($win['to'] < $today) ? $win['to'] : $today;
    try {
        $ds = DateTime::createFromFormat('!Y-m-d', $win['from'], $tz);
        $de = DateTime::createFromFormat('!Y-m-d', $rangeTo, $tz);
        $daysInRange = ($ds && $de && $de >= $ds) ? ((int)$ds->diff($de)->days + 1) : 1;
    } catch (Exception $e) {
        $daysInRange = 1;
    }

    // Venue redemption denominator (points) + numerator (tickets) for the
    // per-game points-share and the summary payout figure.
    $redPointsTotal = 0.0;
    $redTicketsTotal = 0.0;
    foreach ($curTot as $gid => $c) {
        if (!isset($redemption[$gid])) continue;
        $redPointsTotal  += (float)$c['regular_points'] + (float)$c['bonus_points'];
        $redTicketsTotal += (float)$c['tickets'];
    }

    // Authoritative current names + status come from the game cache; include
    // every known game so search can find zero-play games too.
    $cache = [];
    foreach (DB::query('SELECT game_id, game_name, operation_status FROM game_state_cache') as $g) {
        $cache[(string)$g['game_id']] = ['name' => (string)$g['game_name'], 'status' => (string)$g['operation_status']];
    }

    $ids = array_keys($cache);
    $seen = array_flip($ids);
    foreach (array_keys($curTot) as $gid)  { if (!isset($seen[$gid])) { $ids[] = $gid; $seen[$gid] = true; } }
    foreach (array_keys($prevTot) as $gid) { if (!isset($seen[$gid])) { $ids[] = $gid; $seen[$gid] = true; } }

    $zero = ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0, 'regular_points' => 0.0, 'bonus_points' => 0.0];
    $rows = [];
    foreach ($ids as $gid) {
        $c = $curTot[$gid] ?? $zero;
        $p = $prevTot[$gid] ?? $zero;
        $name = $cache[$gid]['name'] ?? ($dailyCur['names'][$gid] ?? ($dailyPrev['names'][$gid] ?? $gid));
        if ($name === '') $name = $gid;
        $pts = (float)$c['regular_points'] + (float)$c['bonus_points'];
        $isRed = isset($redemption[$gid]);
        $rows[] = [
            'game_id'              => $gid,
            'game_name'            => $name,
            'status'               => $cache[$gid]['status'] ?? null,
            'plays'                => (int)$c['plays'],
            'tickets'              => round((float)$c['tickets'], 2),
            'cash'                 => round((float)$c['cash'], 2),
            'points'               => round($pts, 2),
            'avg_tickets_per_play' => $c['plays'] > 0 ? round((float)$c['tickets'] / $c['plays'], 2) : 0,
            // Drill-down: per-game payout % and its share of the venue's
            // redemption-point denominator (null for non-redemption games —
            // rides/cages aren't in the ratio). Utilization: active days.
            'is_redemption'        => $isRed,
            'payout_pct'           => ($isRed && $pts > 0) ? round((float)$c['tickets'] / $pts * 100, 1) : null,
            'points_share'         => ($isRed && $redPointsTotal > 0) ? round($pts / $redPointsTotal * 100, 1) : null,
            'active_days'          => $activeDays[$gid] ?? 0,
            'last_active_date'     => $lastActive[$gid] ?? null,
            'prev_plays'           => (int)$p['plays'],
            'prev_tickets'         => round((float)$p['tickets'], 2),
            'prev_cash'            => round((float)$p['cash'], 2),
        ];
    }

    // Plain-language headliners for the page's insight cards — computed over
    // the WHOLE venue before any search filter, so the headline never quietly
    // shrinks to "best match of your search".
    $mostPlayed = null;
    $topTickets = null;
    foreach ($rows as $r) {
        if ($r['plays'] > 0 && ($mostPlayed === null || $r['plays'] > $mostPlayed['plays'])) {
            $mostPlayed = ['game_id' => $r['game_id'], 'game_name' => $r['game_name'], 'plays' => $r['plays']];
        }
        if ($r['tickets'] > 0 && ($topTickets === null || $r['tickets'] > $topTickets['tickets'])) {
            $topTickets = ['game_id' => $r['game_id'], 'game_name' => $r['game_name'], 'tickets' => $r['tickets'], 'plays' => $r['plays']];
        }
    }

    if ($search !== '') {
        $needle = mb_strtolower($search);
        $rows = array_values(array_filter($rows, function ($r) use ($needle) {
            return mb_strpos(mb_strtolower((string)$r['game_name']), $needle) !== false
                || mb_strpos(mb_strtolower((string)$r['game_id']), $needle) !== false;
        }));
    }

    $mul = $dir === 'desc' ? -1 : 1;
    usort($rows, function ($a, $b) use ($sort, $mul) {
        if ($sort === 'name') {
            $cmp = strcasecmp((string)$a['game_name'], (string)$b['game_name']);
            return $cmp !== 0 ? $mul * $cmp : ($b['plays'] <=> $a['plays']);
        }
        if ($sort === 'status') {
            $rank = ['enabled' => 0, 'paused' => 1, 'outOfService' => 2];
            $cmp = ($rank[$a['status']] ?? 3) <=> ($rank[$b['status']] ?? 3);
            return $cmp !== 0 ? $mul * $cmp : ($b['plays'] <=> $a['plays']);
        }
        // Nullable metric columns (payout %, points share): games the metric
        // doesn't apply to sink below those that have a value in BOTH
        // directions, then by plays.
        if ($sort === 'payout' || $sort === 'points_share') {
            $key = $sort === 'payout' ? 'payout_pct' : 'points_share';
            $av = $a[$key]; $bv = $b[$key];
            if ($av === null && $bv === null) return $b['plays'] <=> $a['plays'];
            if ($av === null) return 1;
            if ($bv === null) return -1;
            if ($av == $bv) return $b['plays'] <=> $a['plays'];
            return $mul * ($av <=> $bv);
        }
        $key = $sort === 'plays' ? 'plays'
             : ($sort === 'cash' ? 'cash'
             : ($sort === 'avg_tickets_per_play' ? 'avg_tickets_per_play'
             : ($sort === 'active_days' ? 'active_days' : 'tickets')));
        if ($a[$key] == $b[$key]) return $b['plays'] <=> $a['plays'];
        return $mul * ($a[$key] <=> $b[$key]);
    });

    $total = count($rows);
    $pageRows = array_slice($rows, ($page - 1) * $pageSize, $pageSize);

    $series = $win['granularity'] === 'hour'
        ? perfHourlySeries(null, $win['from'], $tz)
        : perfBucketSeries($win, $tz, $dailyCur['byDate'], null);

    $payload = [
        'range'           => perfRangeMeta($win, $tzName),
        'totals'          => perfVenueTotals($curTot),
        'previous_totals' => perfVenueTotals($prevTot),
        'series'          => $series,
        'games'           => $pageRows,
        'sort'            => $sort,
        'dir'             => $dir,
        'search'          => $search,
        'pagination'      => [
            'page'        => $page,
            'page_size'   => $pageSize,
            'total'       => $total,
            'total_pages' => (int)ceil(max(1, $total) / $pageSize),
        ],
        // Payout drill-down context.
        'payout_target_pct' => (float)(DB::getConfig('payout_target_pct') ?: 33),
        'days_in_range'   => $daysInRange,
        'venue_payout_pct'=> $redPointsTotal > 0 ? round($redTicketsTotal / $redPointsTotal * 100, 1) : null,
        'redemption_games'=> count($redemption),
        'headliners'      => ['most_played' => $mostPlayed, 'top_tickets' => $topTickets],
        'hide_money'      => $hideMoney,
        'generated_at'    => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
    ];
    if ($hideMoney) perfScrubMoney($payload);
    echo json_encode($payload);
}

/**
 * GET /api/analytics/game?game_id=… — one game's KPIs, prior-period comparison,
 * and a trend series bucketed to match the window (hour/day/month).
 */
function analyticsGameDetail(bool $hideMoney): void {
    $gid = isset($_GET['game_id']) ? trim((string)$_GET['game_id']) : '';
    if ($gid === '') {
        throw new RuntimeException('game_id is required.');
    }
    list($tz, $tzName) = perfTimezone();
    $win = perfResolveWindow($tz);

    $dailyCur  = perfDailyPerGame($win['from'], $win['to'], $tz);
    $dailyPrev = perfDailyPerGame($win['prev_from'], $win['prev_to'], $tz);
    $curTot  = perfSumPerGame($dailyCur);
    $prevTot = perfSumPerGame($dailyPrev);

    $zero = ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0, 'regular_points' => 0.0, 'bonus_points' => 0.0];
    $c = $curTot[$gid] ?? $zero;
    $p = $prevTot[$gid] ?? $zero;

    $cacheRow = DB::queryOne('SELECT game_name, operation_status FROM game_state_cache WHERE game_id = :p0', [$gid]);
    $name = ($cacheRow['game_name'] ?? '') !== '' ? $cacheRow['game_name'] : ($dailyCur['names'][$gid] ?? $gid);
    $status = $cacheRow['operation_status'] ?? null;

    $series = $win['granularity'] === 'hour'
        ? perfHourlySeries($gid, $win['from'], $tz)
        : perfBucketSeries($win, $tz, $dailyCur['byDate'], $gid);

    $payload = [
        'game'            => ['game_id' => $gid, 'game_name' => $name, 'status' => $status],
        'range'           => perfRangeMeta($win, $tzName),
        'totals'          => perfGameKpi($c),
        'previous_totals' => perfGameKpi($p),
        'series'          => $series,
        'hide_money'      => $hideMoney,
        'generated_at'    => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
    ];
    if ($hideMoney) perfScrubMoney($payload);
    echo json_encode($payload);
}

// =====================================================================
// Reader Groups reporting engine
//
// Reader groups are operator-defined groupings of games/readers (created on
// the Reader Groups page, stored in reader_groups / reader_group_games) used
// purely for analytics: comparing areas of the venue and finding when each
// is busiest so staffing can follow demand.
//
//   GET /api/analytics/reader-groups → every group's period totals, play
//       averages, prior-period comparison, and busiest weekday/hour.
//   GET /api/analytics/reader-group?id=… → one group's KPIs, trend series,
//       day-of-week × hour-of-day heatmap, and per-game breakdown.
//
// Day-grain numbers reuse the Performance stitch (game_daily_stats + raw
// feed) so they cover the app's full history. Hour-grain numbers stitch the
// game_hourly_stats rollup (written nightly alongside the daily rollup) with
// the raw feed; hourly history only accumulates from the day this feature
// ships, so heatmap payloads carry their actual coverage window and the UI
// says so instead of implying full-range coverage.
// =====================================================================

/** Sunday-first weekday names matching PHP's date('w') indexing. */
const READER_DOW_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/**
 * Earliest venue-local date hour-grain data exists for: the hourly rollup's
 * floor, or the raw feed's trusted window when the rollup is younger (or
 * empty — e.g. before the first nightly cron after this feature shipped).
 */
function readerHourlyCoverageStart(DateTimeZone $tz): string {
    $rawCoverStart = (new DateTime('now', $tz))->modify('-28 days')->format('Y-m-d');
    $row = DB::queryOne('SELECT MIN(stat_date) AS d FROM game_hourly_stats');
    $min = ($row && !empty($row['d'])) ? (string)$row['d'] : null;
    return ($min !== null && $min < $rawCoverStart) ? $min : $rawCoverStart;
}

/**
 * Resolve the hour-covered slice of a reporting window and count weekday
 * occurrences inside it (the denominator for "average plays on a Saturday
 * at 2 PM"). Calendar occurrences, not just days with plays — a dead
 * Monday should drag Monday's average down, that's the staffing signal.
 *
 * @return array{from:?string, to:?string, full:bool, dow_counts:int[]}
 */
function readerHourlyCoverage(string $fromDate, string $toDate, DateTimeZone $tz): array {
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $to = ($toDate > $today) ? $today : $toDate;
    $coverStart = readerHourlyCoverageStart($tz);
    $from = ($fromDate > $coverStart) ? $fromDate : $coverStart;

    $dowCounts = array_fill(0, 7, 0);
    if ($from > $to) {
        return ['from' => null, 'to' => null, 'full' => false, 'dow_counts' => $dowCounts];
    }

    $cursor = DateTime::createFromFormat('!Y-m-d', $from, $tz);
    $stop   = DateTime::createFromFormat('!Y-m-d', $to, $tz);
    // Coverage is bounded by hourly retention (~400 days) + the raw window,
    // so this loop is short; the guard is belt-and-braces.
    $guard = 800;
    while ($cursor && $stop && $cursor <= $stop && $guard-- > 0) {
        $dowCounts[(int)$cursor->format('w')]++;
        $cursor->modify('+1 day');
    }

    return [
        'from' => $from,
        'to'   => $to,
        'full' => ($from <= $fromDate),
        'dow_counts' => $dowCounts,
    ];
}

/**
 * Hour-grain totals for a set of games over [$fromDate, $toDate] (inclusive
 * local dates), one row per (date, hour, game): recent days from the raw
 * feed, older days from the game_hourly_stats rollup — the same disjoint
 * stitch perfDailyPerGame() uses, so nothing is double-counted.
 *
 * When $excludeTimePlays is set, each row's play count drops its time-pass
 * plays (raw rows filter exactly; rollup rows subtract the stored counter).
 * Tickets/cash stay whole — the hourly rollup can't attribute value to the
 * excluded plays, and the raw side matches so the stitch never disagrees
 * with itself. time_plays keeps reporting the excluded count either way.
 *
 * @param array<string,bool> $memberSet game_id => true
 * @return array<int,array{date:string,hour:int,game_id:string,plays:int,tickets:float,cash:float,time_plays:int}>
 */
function readerHourlyRows(string $fromDate, string $toDate, DateTimeZone $tz, array $memberSet, bool $excludeTimePlays = false): array {
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    if ($toDate > $today) $toDate = $today;
    if ($fromDate > $toDate || empty($memberSet)) return [];

    $rawCoverStart = (new DateTime('now', $tz))->modify('-28 days')->format('Y-m-d');
    $rawBefore = (new DateTime($rawCoverStart))->modify('-1 day')->format('Y-m-d');

    $rows = [];
    $memberIds = array_keys($memberSet);

    $rollupTo = ($toDate < $rawBefore) ? $toDate : $rawBefore;
    if ($fromDate <= $rollupTo) {
        // Filter to member games IN SQL (chunked IN-lists) rather than loading
        // the whole venue's hourly rows and discarding non-members in PHP.
        // game_hourly_stats is retained ~400 days, so a Year view over every
        // game would materialize on the order of 10^6 rows here and exhaust
        // memory — the same failure mode as the guest-insights read above.
        // idx_ghs_game_date makes each chunk a game_id seek + date range-scan.
        foreach (array_chunk($memberIds, 400) as $chunk) {
            $ph = [];
            $params = [];
            foreach ($chunk as $i => $gid) { $ph[] = ':p' . $i; $params[] = (string)$gid; }
            $n = count($chunk);
            $params[] = $fromDate;
            $params[] = $rollupTo;
            DB::each(
                'SELECT stat_date, hour, game_id, plays, tickets, cash, time_plays
                 FROM game_hourly_stats
                 WHERE game_id IN (' . implode(',', $ph) . ')
                   AND stat_date >= :p' . $n . ' AND stat_date <= :p' . ($n + 1),
                $params,
                function (array $r) use (&$rows, $excludeTimePlays): void {
                    $plays = (int)$r['plays'];
                    $timePlays = (int)$r['time_plays'];
                    $rows[] = [
                        'date'       => (string)$r['stat_date'],
                        'hour'       => (int)$r['hour'],
                        'game_id'    => (string)$r['game_id'],
                        'plays'      => $excludeTimePlays ? max(0, $plays - $timePlays) : $plays,
                        'tickets'    => (float)$r['tickets'],
                        'cash'       => (float)$r['cash'],
                        'time_plays' => $timePlays,
                    ];
                }
            );
        }
    }

    $rawFrom = ($fromDate > $rawCoverStart) ? $fromDate : $rawCoverStart;
    if ($rawFrom <= $toDate) {
        $utc = new DateTimeZone('UTC');
        $start   = DateTime::createFromFormat('!Y-m-d', $rawFrom, $tz);
        $endExcl = DateTime::createFromFormat('!Y-m-d', $toDate, $tz)->modify('+1 day');
        // Pad ±1 day in UTC so an offset never clips boundary rows; re-filter
        // by exact local date below.
        $lo = (clone $start)->setTimezone($utc)->modify('-1 day')->format('Y-m-d\TH:i:s\Z');
        $hi = (clone $endExcl)->setTimezone($utc)->modify('+1 day')->format('Y-m-d\TH:i:s\Z');

        $agg = [];
        foreach (array_chunk($memberIds, 400) as $chunk) {
            $ph = [];
            $params = [];
            foreach ($chunk as $i => $gid) { $ph[] = ':p' . $i; $params[] = (string)$gid; }
            $n = count($chunk);
            $params[] = $lo;
            $params[] = $hi;
            DB::each(
                'SELECT transaction_time, game_id, redemption_tickets,
                        (cash_amount + credit_card_amount) AS cash_amount, used_time_play
                 FROM game_play_transactions
                 WHERE game_id IN (' . implode(',', $ph) . ')
                   AND transaction_time >= :p' . $n . ' AND transaction_time < :p' . ($n + 1),
                $params,
                function (array $r) use (&$agg, $tz, $rawFrom, $toDate, $excludeTimePlays): void {
                    $gid = (string)($r['game_id'] ?? '');
                    if ($gid === '') return;
                    $tt = $r['transaction_time'] ?? '';
                    if ($tt === '') return;
                    try {
                        $d = new DateTime($tt);
                    } catch (Exception $e) {
                        return;
                    }
                    $d->setTimezone($tz);
                    $date = $d->format('Y-m-d');
                    if ($date < $rawFrom || $date > $toDate) return;
                    $hour = (int)$d->format('G');
                    $k = $date . "\0" . $hour . "\0" . $gid;
                    if (!isset($agg[$k])) {
                        $agg[$k] = ['date' => $date, 'hour' => $hour, 'game_id' => $gid,
                                    'plays' => 0, 'tickets' => 0.0, 'cash' => 0.0, 'time_plays' => 0];
                    }
                    $isTimePlay = ((int)($r['used_time_play'] ?? 0)) ? 1 : 0;
                    if (!($excludeTimePlays && $isTimePlay)) {
                        $agg[$k]['plays'] += 1;
                    }
                    $agg[$k]['tickets']    += (float)($r['redemption_tickets'] ?? 0);
                    $agg[$k]['cash']       += (float)($r['cash_amount'] ?? 0);
                    $agg[$k]['time_plays'] += $isTimePlay;
                }
            );
        }
        foreach ($agg as $a) $rows[] = $a;
    }

    return $rows;
}

/**
 * Apply the time-pass exclusion to a per-game byDate structure: each day
 * bucket's play count drops its recorded time-pass plays. Value fields
 * (tickets/cash/points) stay whole — day buckets rolled up before the
 * time_plays column existed carry 0 and are left unchanged, which the
 * endpoints disclose via time_split_since.
 */
function readerApplyTimeExclusion(array $byDate): array {
    foreach ($byDate as &$games) {
        foreach ($games as &$b) {
            $b['plays'] = max(0, (int)$b['plays'] - (int)($b['time_plays'] ?? 0));
        }
        unset($b);
    }
    unset($games);
    return $byDate;
}

/** Local date where trustworthy day-grain time-pass splits begin (or null). */
function readerTimeSplitSince(): ?string {
    $v = DB::getConfig('time_plays_daily_since');
    return ($v !== null && $v !== '') ? (string)$v : null;
}

/**
 * Fold hour-grain rows into a 7×24 day-of-week × hour matrix with both
 * period totals and per-occurrence averages, plus the top busiest cells.
 *
 * @param array $rows readerHourlyRows() output (any game filtering already done)
 * @param array $coverage readerHourlyCoverage() output for the same window
 */
function readerHeatmap(array $rows, array $coverage, DateTimeZone $tz): array {
    $totals = [];
    for ($d = 0; $d < 7; $d++) $totals[$d] = array_fill(0, 24, 0);

    $dowByDate = [];
    $timePlays = 0;
    foreach ($rows as $r) {
        $date = $r['date'];
        if (!isset($dowByDate[$date])) {
            $dt = DateTime::createFromFormat('!Y-m-d', $date, $tz);
            $dowByDate[$date] = $dt ? (int)$dt->format('w') : 0;
        }
        $totals[$dowByDate[$date]][$r['hour']] += (int)$r['plays'];
        $timePlays += (int)$r['time_plays'];
    }

    $avg = [];
    $maxAvg = 0.0;
    $maxTotal = 0;
    $cells = [];
    for ($d = 0; $d < 7; $d++) {
        $avg[$d] = array_fill(0, 24, 0.0);
        $n = (int)$coverage['dow_counts'][$d];
        for ($h = 0; $h < 24; $h++) {
            $t = $totals[$d][$h];
            $a = $n > 0 ? round($t / $n, 1) : 0.0;
            $avg[$d][$h] = $a;
            if ($a > $maxAvg) $maxAvg = $a;
            if ($t > $maxTotal) $maxTotal = $t;
            if ($t > 0) {
                $cells[] = ['dow' => $d, 'hour' => $h, 'plays' => $t, 'avg_plays' => $a];
            }
        }
    }

    usort($cells, function ($a, $b) {
        if ($a['avg_plays'] == $b['avg_plays']) return $b['plays'] <=> $a['plays'];
        return $b['avg_plays'] <=> $a['avg_plays'];
    });
    $busiest = array_map(function ($c) {
        $c['label'] = READER_DOW_NAMES[$c['dow']] . ' ' . perfHourLabel($c['hour']);
        return $c;
    }, array_slice($cells, 0, 5));

    return [
        'heatmap' => [
            'totals'     => $totals,
            'avg'        => $avg,
            'max_avg'    => $maxAvg,
            'max_total'  => $maxTotal,
            'dow_counts' => $coverage['dow_counts'],
            'covered_from' => $coverage['from'],
            'covered_to'   => $coverage['to'],
            'full_coverage'=> (bool)$coverage['full'],
        ],
        'busiest'    => $busiest,
        'time_plays' => $timePlays,
    ];
}

/** Sum a per-game totals map over one group's member set. */
function readerSumMembers(array $perGameTotals, array $memberSet): array {
    $sum = ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0, 'regular_points' => 0.0, 'bonus_points' => 0.0, 'active_games' => 0];
    foreach ($memberSet as $gid => $_) {
        if (!isset($perGameTotals[$gid])) continue;
        $t = $perGameTotals[$gid];
        $sum['plays']          += (int)$t['plays'];
        $sum['tickets']        += (float)$t['tickets'];
        $sum['cash']           += (float)$t['cash'];
        $sum['regular_points'] += (float)$t['regular_points'];
        $sum['bonus_points']   += (float)$t['bonus_points'];
        if ((int)$t['plays'] > 0) $sum['active_games']++;
    }
    return $sum;
}

/** Days of the window that have elapsed (from → min(to, today)), inclusive. */
function readerDaysElapsed(array $win, DateTimeZone $tz): int {
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $to = ($win['to'] < $today) ? $win['to'] : $today;
    if ($win['from'] > $to) return 0;
    try {
        $ds = DateTime::createFromFormat('!Y-m-d', $win['from'], $tz);
        $de = DateTime::createFromFormat('!Y-m-d', $to, $tz);
        return ($ds && $de && $de >= $ds) ? ((int)$ds->diff($de)->days + 1) : 1;
    } catch (Exception $e) {
        return 1;
    }
}

/** Zero every monetary field in a reader-group payload (no view_revenue). */
function readerScrubMoney(array &$payload): void {
    foreach (['totals', 'previous_totals'] as $k) {
        if (isset($payload[$k]) && is_array($payload[$k])) {
            if (array_key_exists('cash', $payload[$k])) $payload[$k]['cash'] = 0.0;
            if (array_key_exists('avg_cash_per_play', $payload[$k])) $payload[$k]['avg_cash_per_play'] = 0.0;
        }
    }
    foreach (['groups', 'games'] as $k) {
        if (isset($payload[$k]) && is_array($payload[$k])) {
            foreach ($payload[$k] as &$row) {
                if (is_array($row)) {
                    if (array_key_exists('cash', $row)) $row['cash'] = 0.0;
                    if (array_key_exists('prev_cash', $row)) $row['prev_cash'] = 0.0;
                }
            }
            unset($row);
        }
    }
    if (isset($payload['series']['points']) && is_array($payload['series']['points'])) {
        foreach ($payload['series']['points'] as &$pt) {
            if (is_array($pt) && array_key_exists('cash', $pt)) $pt['cash'] = 0.0;
        }
        unset($pt);
    }
}

/**
 * GET /api/analytics/reader-groups — every reader group's totals, play
 * averages, prior-period comparison, and busiest weekday/hour for the
 * resolved window. Powers the comparison table on the Reader Groups page.
 */
function analyticsReaderGroupsList(bool $hideMoney): void {
    $noTime = analyticsExcludeTimePlays();
    list($tz, $tzName) = perfTimezone();
    $win = perfResolveWindow($tz);

    $groups = DB::query('SELECT id, name, description FROM reader_groups ORDER BY name ASC');

    // Memberships: per-group sets, a reverse game → groups index, and the
    // union set, so both the daily and hourly passes run once for all groups.
    $members = [];
    $groupsByGame = [];
    $union = [];
    foreach (DB::query('SELECT reader_group_id, game_id FROM reader_group_games') as $r) {
        $gid = (int)$r['reader_group_id'];
        $g = (string)$r['game_id'];
        $members[$gid][$g] = true;
        $groupsByGame[$g][] = $gid;
        $union[$g] = true;
    }

    $daysElapsed = readerDaysElapsed($win, $tz);
    $coverage = readerHourlyCoverage($win['from'], $win['to'], $tz);

    $payload = [
        'range'        => perfRangeMeta($win, $tzName),
        'groups'       => [],
        'venue_plays'  => 0,
        'days_in_range'=> $daysElapsed,
        'dow_counts'   => $coverage['dow_counts'],
        'hourly_covered_from' => $coverage['from'],
        'hourly_full_coverage'=> (bool)$coverage['full'],
        'exclude_time_plays'  => $noTime,
        'time_split_since'    => readerTimeSplitSince(),
        'hide_money'   => $hideMoney,
        'generated_at' => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
    ];

    if (empty($groups)) {
        echo json_encode($payload);
        return;
    }

    $dailyCur  = perfDailyPerGame($win['from'], $win['to'], $tz);
    $dailyPrev = perfDailyPerGame($win['prev_from'], $win['prev_to'], $tz);
    if ($noTime) {
        $dailyCur['byDate']  = readerApplyTimeExclusion($dailyCur['byDate']);
        $dailyPrev['byDate'] = readerApplyTimeExclusion($dailyPrev['byDate']);
    }
    $curTot  = perfSumPerGame($dailyCur);
    $prevTot = perfSumPerGame($dailyPrev);

    $venueTotals = perfVenueTotals($curTot);
    $payload['venue_plays'] = (int)$venueTotals['plays'];

    // One hourly pass over the union of all member games, aggregated into a
    // per-group 7×24 plays matrix (plus time-pass plays) via the reverse index.
    $hourlyRows = readerHourlyRows($win['from'], $win['to'], $tz, $union, $noTime);
    $cellsByGroup = [];
    $dowPlaysByGroup = [];
    $timeByGroup = [];
    $dowByDate = [];
    foreach ($hourlyRows as $row) {
        $date = $row['date'];
        if (!isset($dowByDate[$date])) {
            $dt = DateTime::createFromFormat('!Y-m-d', $date, $tz);
            $dowByDate[$date] = $dt ? (int)$dt->format('w') : 0;
        }
        $dow = $dowByDate[$date];
        foreach ($groupsByGame[$row['game_id']] ?? [] as $gid) {
            if (!isset($cellsByGroup[$gid])) {
                $cellsByGroup[$gid] = [];
                for ($d = 0; $d < 7; $d++) $cellsByGroup[$gid][$d] = array_fill(0, 24, 0);
                $dowPlaysByGroup[$gid] = array_fill(0, 7, 0);
                $timeByGroup[$gid] = 0;
            }
            $cellsByGroup[$gid][$dow][$row['hour']] += (int)$row['plays'];
            $dowPlaysByGroup[$gid][$dow] += (int)$row['plays'];
            $timeByGroup[$gid] += (int)$row['time_plays'];
        }
    }

    $out = [];
    foreach ($groups as $g) {
        $gid = (int)$g['id'];
        $memberSet = $members[$gid] ?? [];
        $cur  = readerSumMembers($curTot, $memberSet);
        $prev = readerSumMembers($prevTot, $memberSet);
        $gameCount = count($memberSet);
        $plays = (int)$cur['plays'];

        // Busiest cell by per-occurrence average so a window spanning weeks
        // ranks "typical Saturday 2 PM" fairly against "typical Tuesday 6 PM".
        $busiest = null;
        if (isset($cellsByGroup[$gid])) {
            $bestAvg = 0.0; $bestTotal = 0;
            for ($d = 0; $d < 7; $d++) {
                $n = (int)$coverage['dow_counts'][$d];
                if ($n < 1) continue;
                for ($h = 0; $h < 24; $h++) {
                    $t = $cellsByGroup[$gid][$d][$h];
                    if ($t < 1) continue;
                    $a = $t / $n;
                    if ($a > $bestAvg || ($a == $bestAvg && $t > $bestTotal)) {
                        $bestAvg = $a; $bestTotal = $t;
                        $busiest = [
                            'dow'   => $d,
                            'hour'  => $h,
                            'label' => READER_DOW_NAMES[$d] . ' ' . perfHourLabel($h),
                            'avg_plays' => round($a, 1),
                            'plays' => $t,
                        ];
                    }
                }
            }
        }

        $out[] = [
            'id'          => $gid,
            'name'        => (string)$g['name'],
            'description' => (string)$g['description'],
            'game_count'  => $gameCount,
            'plays'       => $plays,
            'tickets'     => round((float)$cur['tickets'], 2),
            'cash'        => round((float)$cur['cash'], 2),
            'time_plays'  => (int)($timeByGroup[$gid] ?? 0),
            'active_games'=> (int)$cur['active_games'],
            'avg_plays_per_day' => $daysElapsed > 0 ? round($plays / $daysElapsed, 1) : 0,
            'avg_plays_per_game_per_day' => ($daysElapsed > 0 && $gameCount > 0)
                ? round($plays / $gameCount / $daysElapsed, 1) : 0,
            'share_pct'   => $venueTotals['plays'] > 0 ? round($plays / $venueTotals['plays'] * 100, 1) : null,
            'busiest'     => $busiest,
            // Hour-covered plays per weekday (Sun..Sat) — powers the "week
            // rhythm" mini-bars; divide by dow_counts for per-occurrence avgs.
            'dow_plays'   => $dowPlaysByGroup[$gid] ?? array_fill(0, 7, 0),
            'prev_plays'  => (int)$prev['plays'],
            'prev_tickets'=> round((float)$prev['tickets'], 2),
            'prev_cash'   => round((float)$prev['cash'], 2),
        ];
    }

    // Busiest areas first — plays is the staffing signal.
    usort($out, function ($a, $b) {
        if ($a['plays'] == $b['plays']) return strcasecmp($a['name'], $b['name']);
        return $b['plays'] <=> $a['plays'];
    });

    $payload['groups'] = $out;
    if ($hideMoney) readerScrubMoney($payload);
    echo json_encode($payload);
}

/**
 * GET /api/analytics/reader-group?id=… — one reader group's KPIs (with
 * prior-period comparison), trend series, day-of-week × hour heatmap, and
 * per-game breakdown for the resolved window.
 */
function analyticsReaderGroupDetail(bool $hideMoney): void {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id < 1) {
        throw new RuntimeException('id is required.');
    }
    $group = DB::queryOne('SELECT id, name, description FROM reader_groups WHERE id = :p0', [$id]);
    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Reader group not found']);
        return;
    }

    $noTime = analyticsExcludeTimePlays();
    list($tz, $tzName) = perfTimezone();
    $win = perfResolveWindow($tz);

    $memberRows = DB::query(
        'SELECT m.game_id,
                COALESCE(NULLIF(c.game_name, \'\'), NULLIF(m.game_name, \'\'), m.game_id) AS game_name,
                c.operation_status
         FROM reader_group_games m
         LEFT JOIN game_state_cache c ON c.game_id = m.game_id
         WHERE m.reader_group_id = :p0
         ORDER BY game_name ASC',
        [$id]
    );
    $memberSet = [];
    foreach ($memberRows as $m) {
        $memberSet[(string)$m['game_id']] = true;
    }

    $dailyCur  = perfDailyPerGame($win['from'], $win['to'], $tz);
    $dailyPrev = perfDailyPerGame($win['prev_from'], $win['prev_to'], $tz);

    // Restrict the stitched day-grain data to member games once; everything
    // downstream (KPIs, trend, per-game rows) reads the filtered view.
    $filterByDate = function (array $byDate) use ($memberSet): array {
        $out = [];
        foreach ($byDate as $date => $games) {
            $f = array_intersect_key($games, $memberSet);
            if (!empty($f)) $out[$date] = $f;
        }
        return $out;
    };
    $curByDate  = $filterByDate($dailyCur['byDate']);
    $prevByDate = $filterByDate($dailyPrev['byDate']);
    if ($noTime) {
        $curByDate  = readerApplyTimeExclusion($curByDate);
        $prevByDate = readerApplyTimeExclusion($prevByDate);
    }
    $curTot  = perfSumPerGame(['byDate' => $curByDate]);
    $prevTot = perfSumPerGame(['byDate' => $prevByDate]);

    $daysElapsed = readerDaysElapsed($win, $tz);
    $gameCount = count($memberSet);

    // Hour-grain: heatmap + busiest cells + time-pass plays (coverage-bound).
    $coverage = readerHourlyCoverage($win['from'], $win['to'], $tz);
    $hourlyRows = readerHourlyRows($win['from'], $win['to'], $tz, $memberSet, $noTime);
    $heat = readerHeatmap($hourlyRows, $coverage, $tz);

    $kpis = function (array $sum, int $days, int $nGames): array {
        $plays = (int)$sum['plays'];
        $tickets = (float)$sum['tickets'];
        $cash = (float)$sum['cash'];
        return [
            'plays'   => $plays,
            'tickets' => round($tickets, 2),
            'cash'    => round($cash, 2),
            'points'  => round((float)$sum['regular_points'] + (float)$sum['bonus_points'], 2),
            'active_games' => (int)$sum['active_games'],
            'avg_tickets_per_play' => $plays > 0 ? round($tickets / $plays, 2) : 0,
            'avg_cash_per_play'    => $plays > 0 ? round($cash / $plays, 2) : 0,
            'avg_plays_per_day'    => $days > 0 ? round($plays / $days, 1) : 0,
            'avg_plays_per_game_per_day' => ($days > 0 && $nGames > 0) ? round($plays / $nGames / $days, 1) : 0,
        ];
    };
    $totals = $kpis(readerSumMembers($curTot, $memberSet), $daysElapsed, $gameCount);
    $totals['time_plays'] = (int)$heat['time_plays'];
    // Prior period is a full span by construction; normalize per its own length.
    $prevDays = 0;
    try {
        $pds = DateTime::createFromFormat('!Y-m-d', $win['prev_from'], $tz);
        $pde = DateTime::createFromFormat('!Y-m-d', $win['prev_to'], $tz);
        if ($pds && $pde && $pde >= $pds) $prevDays = (int)$pds->diff($pde)->days + 1;
    } catch (Exception $e) {
        $prevDays = 0;
    }
    $previousTotals = $kpis(readerSumMembers($prevTot, $memberSet), $prevDays, $gameCount);

    // Trend series: hour buckets for a single-day window (from the stitched
    // hourly rows), otherwise the shared day/month bucketing over the
    // member-filtered daily data.
    if ($win['granularity'] === 'hour') {
        $buckets = array_fill(0, 24, ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0]);
        foreach ($hourlyRows as $r) {
            if ($r['date'] !== $win['from']) continue;
            $buckets[$r['hour']]['plays']   += (int)$r['plays'];
            $buckets[$r['hour']]['tickets'] += (float)$r['tickets'];
            $buckets[$r['hour']]['cash']    += (float)$r['cash'];
        }
        $points = [];
        for ($h = 0; $h < 24; $h++) {
            $points[] = [
                'label'   => perfHourLabel($h),
                'plays'   => $buckets[$h]['plays'],
                'tickets' => round($buckets[$h]['tickets'], 2),
                'cash'    => round($buckets[$h]['cash'], 2),
            ];
        }
        $series = ['granularity' => 'hour', 'points' => $points];
    } else {
        $series = perfBucketSeries($win, $tz, $curByDate, null);
    }

    // Per-game breakdown inside the group.
    $zero = ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0, 'regular_points' => 0.0, 'bonus_points' => 0.0];
    $groupPlays = (int)$totals['plays'];
    $gamesOut = [];
    foreach ($memberRows as $m) {
        $g = (string)$m['game_id'];
        $c = $curTot[$g] ?? $zero;
        $p = $prevTot[$g] ?? $zero;
        $plays = (int)$c['plays'];
        $gamesOut[] = [
            'game_id'   => $g,
            'game_name' => (string)$m['game_name'],
            'status'    => $m['operation_status'] ?? null,
            'plays'     => $plays,
            'tickets'   => round((float)$c['tickets'], 2),
            'cash'      => round((float)$c['cash'], 2),
            'avg_plays_per_day' => $daysElapsed > 0 ? round($plays / $daysElapsed, 1) : 0,
            'share_pct' => $groupPlays > 0 ? round($plays / $groupPlays * 100, 1) : null,
            'prev_plays'=> (int)$p['plays'],
        ];
    }
    usort($gamesOut, function ($a, $b) {
        if ($a['plays'] == $b['plays']) return strcasecmp($a['game_name'], $b['game_name']);
        return $b['plays'] <=> $a['plays'];
    });

    $payload = [
        'group' => [
            'id'          => (int)$group['id'],
            'name'        => (string)$group['name'],
            'description' => (string)$group['description'],
            'game_count'  => $gameCount,
        ],
        'range'           => perfRangeMeta($win, $tzName),
        'totals'          => $totals,
        'previous_totals' => $previousTotals,
        'series'          => $series,
        'heatmap'         => $heat['heatmap'],
        'busiest'         => $heat['busiest'],
        'games'           => $gamesOut,
        'days_in_range'   => $daysElapsed,
        'exclude_time_plays' => $noTime,
        'time_split_since'   => readerTimeSplitSince(),
        'hide_money'      => $hideMoney,
        'generated_at'    => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
    ];
    if ($hideMoney) readerScrubMoney($payload);
    echo json_encode($payload);
}
