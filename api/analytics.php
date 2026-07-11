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
        }
    }
    if (isset($payload['charts']) && is_array($payload['charts'])) {
        // The cash leaderboard is monetary end-to-end — drop it entirely.
        $payload['charts']['top_games_cash'] = [];
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

function analyticsOverview(bool $hideMoney = false): void {
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
    $rangeKey = isset($_GET['range']) ? (string)$_GET['range'] : '7d';
    $allowed = ['today', '7d', '30d', '90d', 'all', 'custom'];
    if (!in_array($rangeKey, $allowed, true)) {
        $rangeKey = '7d';
    }

    list($startLocal, $endLocal) = analyticsResolveRange($rangeKey, $tz);

    $startUtc = clone $startLocal; $startUtc->setTimezone($utc);
    $endUtc   = clone $endLocal;   $endUtc->setTimezone($utc);

    // Format helpers
    $isoUtc = function (DateTime $d) { return $d->format('Y-m-d\TH:i:s\Z'); };
    $sqlUtc = function (DateTime $d) { return $d->format('Y-m-d H:i:s'); };

    $startIsoUtc = $isoUtc($startUtc);
    $endIsoUtc   = $isoUtc($endUtc);
    $startSql    = $sqlUtc($startUtc); // For action_log (DB stores "YYYY-MM-DD HH:MM:SS" UTC)
    $endSql      = $sqlUtc($endUtc);

    // Previous period of equal length, immediately preceding the selected range.
    $rangeSeconds = $endUtc->getTimestamp() - $startUtc->getTimestamp();
    $prevEndUtc   = (clone $startUtc);
    $prevStartUtc = (clone $startUtc)->modify('-' . max(1, $rangeSeconds) . ' seconds');
    $prevStartIso = $isoUtc($prevStartUtc);
    $prevEndIso   = $isoUtc($prevEndUtc);

    // ---- KPI: plays / tickets / cash / unique cards (current + prior) ----
    $kpis = analyticsKpis($startIsoUtc, $endIsoUtc);
    $previousKpis = analyticsKpis($prevStartIso, $prevEndIso);

    // ---- Fleet posture (current state, not range-scoped) ----
    $fleet = analyticsFleet();

    // ---- Top games — three leaderboards, capped at 10 each ----
    $topGamesPlays   = analyticsTopGames($startIsoUtc, $endIsoUtc, 'plays', 10);
    $topGamesTickets = analyticsTopGames($startIsoUtc, $endIsoUtc, 'tickets', 10);
    $topGamesCash    = analyticsTopGames($startIsoUtc, $endIsoUtc, 'cash', 10);

    // ---- Category breakdown (arcade vs rides vs batting cages, etc.) ----
    $byCategory = analyticsByCategory($startIsoUtc, $endIsoUtc);

    // ---- Time-bucketed series (hour-of-day, day-of-week, daily) ----
    // We pull just the columns we need for binning so memory stays sane.
    // The optimizer uses idx_gpt_time on transaction_time DESC.
    $bucketRows = DB::query(
        'SELECT transaction_time, redemption_tickets,
                (cash_amount + credit_card_amount) AS cash_amount
         FROM game_play_transactions
         WHERE transaction_time >= :p0 AND transaction_time < :p1',
        [$startIsoUtc, $endIsoUtc]
    );

    $hourBuckets = array_fill(0, 24, ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0]);
    $dowBuckets  = array_fill(0, 7,  ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0]);
    $dailyByKey  = [];

    foreach ($bucketRows as $row) {
        $tt = $row['transaction_time'] ?? '';
        if ($tt === '') continue;
        try {
            $d = new DateTime($tt);
        } catch (Exception $e) {
            continue;
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

    $payload = [
        'range' => [
            'key'      => $rangeKey,
            'timezone' => $tzName,
            'from'     => $startLocal->format('Y-m-d'),
            'to'       => $endLocal->format('Y-m-d'),
            'from_iso' => $startIsoUtc,
            'to_iso'   => $endIsoUtc,
        ],
        'kpis' => $kpis,
        'previous_kpis' => $previousKpis,
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
        'hide_money' => $hideMoney,
        'generated_at' => (new DateTime('now', $utc))->format('Y-m-d\TH:i:s\Z'),
    ];

    if ($hideMoney) {
        analyticsScrubMoney($payload);
    }

    echo json_encode($payload);
}

/**
 * Resolve a range key (or custom from/to) into [startLocal, endLocal] DateTime
 * objects in the app timezone. End is exclusive (next-second boundary).
 */
function analyticsResolveRange(string $key, DateTimeZone $tz): array {
    $now = new DateTime('now', $tz);

    if ($key === 'custom') {
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
        $end->modify('+1 day'); // make end exclusive at next-day midnight
        if ($end <= $start) {
            throw new RuntimeException('Custom range "to" must be on or after "from".');
        }
        return [$start, $end];
    }

    $end = (clone $now);

    switch ($key) {
        case 'today':
            $start = (clone $now)->setTime(0, 0, 0);
            break;
        case '30d':
            $start = (clone $now)->modify('-30 days');
            break;
        case '90d':
            $start = (clone $now)->modify('-90 days');
            break;
        case 'all':
            // Use the earliest record timestamp, falling back to 1 year ago.
            $row = DB::queryOne('SELECT MIN(transaction_time) AS earliest FROM game_play_transactions');
            $earliest = $row['earliest'] ?? null;
            if ($earliest) {
                try {
                    $start = new DateTime($earliest);
                    $start->setTimezone($tz);
                } catch (Exception $e) {
                    $start = (clone $now)->modify('-1 year');
                }
            } else {
                $start = (clone $now)->modify('-1 year');
            }
            break;
        case '7d':
        default:
            $start = (clone $now)->modify('-7 days');
            break;
    }

    return [$start, $end];
}

/**
 * KPI totals for the [startIso, endIso) window. Uses lexical ISO comparison
 * which works for all valid ISO 8601 timestamps with timezone designators.
 */
function analyticsKpis(string $startIso, string $endIso): array {
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
         WHERE transaction_time >= :p0 AND transaction_time < :p1',
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
function analyticsByCategory(string $startIso, string $endIso): array {
    $perGame = DB::query(
        'SELECT game_id,
                COUNT(*) AS plays,
                COALESCE(SUM(redemption_tickets), 0) AS tickets,
                COALESCE(SUM(cash_amount + credit_card_amount), 0) AS cash
         FROM game_play_transactions
         WHERE transaction_time >= :p0 AND transaction_time < :p1
           AND game_id != \'\'
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

    $nowUtc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $activeOverrides = (int)(DB::queryOne(
        'SELECT COUNT(*) AS c FROM schedule_overrides WHERE start_datetime <= :p0 AND end_datetime > :p0',
        [$nowUtc]
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
function analyticsTopGames(string $startIso, string $endIso, string $metric, int $limit = 10): array {
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
           AND t.game_id != \'\'
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
 * Per-game daily totals read from the RAW feed for [$fromDate, $toDate]
 * (inclusive local dates), bucketed by local calendar date in PHP so day
 * boundaries are correct regardless of the timestamp's "Z"/offset format.
 * Returns ['byDate' => [date => [gid => totals]], 'names' => [gid => name]].
 */
function perfRawDailyPerGame(string $fromDate, string $toDate, DateTimeZone $tz): array {
    if ($fromDate > $toDate) return ['byDate' => [], 'names' => []];
    $utc = new DateTimeZone('UTC');
    $start   = DateTime::createFromFormat('!Y-m-d', $fromDate, $tz);
    $endExcl = DateTime::createFromFormat('!Y-m-d', $toDate, $tz)->modify('+1 day');
    // Pad ±1 day in UTC so an offset never clips boundary rows; we re-filter by
    // exact local date below.
    $lo = (clone $start)->setTimezone($utc)->modify('-1 day')->format('Y-m-d\TH:i:s\Z');
    $hi = (clone $endExcl)->setTimezone($utc)->modify('+1 day')->format('Y-m-d\TH:i:s\Z');

    $rows = DB::query(
        'SELECT transaction_time, game_id, game_description, card_number,
                redemption_tickets, (cash_amount + credit_card_amount) AS cash_amount,
                regular_points, bonus_points
         FROM game_play_transactions
         WHERE transaction_time >= :p0 AND transaction_time < :p1',
        [$lo, $hi]
    );

    $byDate = [];
    $names = [];
    foreach ($rows as $r) {
        $tt = $r['transaction_time'] ?? '';
        if ($tt === '') continue;
        try {
            $d = new DateTime($tt);
        } catch (Exception $e) {
            continue;
        }
        $d->setTimezone($tz);
        $date = $d->format('Y-m-d');
        if ($date < $fromDate || $date > $toDate) continue;
        $gid = (string)($r['game_id'] ?? '');
        if ($gid === '') continue;

        if (!isset($byDate[$date][$gid])) {
            $byDate[$date][$gid] = ['plays' => 0, 'tickets' => 0.0, 'cash' => 0.0, 'regular_points' => 0.0, 'bonus_points' => 0.0];
        }
        $byDate[$date][$gid]['plays']          += 1;
        $byDate[$date][$gid]['tickets']        += (float)($r['redemption_tickets'] ?? 0);
        $byDate[$date][$gid]['cash']           += (float)($r['cash_amount'] ?? 0);
        $byDate[$date][$gid]['regular_points'] += (float)($r['regular_points'] ?? 0);
        $byDate[$date][$gid]['bonus_points']   += (float)($r['bonus_points'] ?? 0);

        $desc = trim((string)($r['game_description'] ?? ''));
        if ($desc !== '') $names[$gid] = $desc;
    }
    return ['byDate' => $byDate, 'names' => $names];
}

/** Per-game daily totals read from the permanent rollup for [$fromDate,$toDate]. */
function perfRollupDailyPerGame(string $fromDate, string $toDate): array {
    if ($fromDate > $toDate) return ['byDate' => [], 'names' => []];
    $rows = DB::query(
        'SELECT stat_date, game_id, game_name, plays, tickets, cash, regular_points, bonus_points
         FROM game_daily_stats
         WHERE stat_date >= :p0 AND stat_date <= :p1',
        [$fromDate, $toDate]
    );
    $byDate = [];
    $names = [];
    foreach ($rows as $r) {
        $date = (string)$r['stat_date'];
        $gid  = (string)$r['game_id'];
        $byDate[$date][$gid] = [
            'plays'          => (int)$r['plays'],
            'tickets'        => (float)$r['tickets'],
            'cash'           => (float)$r['cash'],
            'regular_points' => (float)$r['regular_points'],
            'bonus_points'   => (float)$r['bonus_points'],
        ];
        $nm = trim((string)$r['game_name']);
        if ($nm !== '') $names[$gid] = $nm;
    }
    return ['byDate' => $byDate, 'names' => $names];
}

/**
 * Combined per-game daily totals for [$fromDate,$toDate], stitching the rollup
 * (older days) and the raw feed (recent days) at a split point safely inside
 * raw retention. toDate is clamped to today (no future data).
 */
function perfDailyPerGame(string $fromDate, string $toDate, DateTimeZone $tz): array {
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
        $r = perfRollupDailyPerGame($fromDate, $rollupTo);
        foreach ($r['byDate'] as $date => $games) $byDate[$date] = $games;
        $names += $r['names'];
    }

    $rawFrom = ($fromDate > $rawCoverStart) ? $fromDate : $rawCoverStart;
    if ($rawFrom <= $toDate) {
        $r = perfRawDailyPerGame($rawFrom, $toDate, $tz);
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
    if (!in_array($sort, ['tickets', 'plays', 'cash', 'name'], true)) $sort = 'tickets';
    if ($hideMoney && $sort === 'cash') $sort = 'tickets';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = max(1, min(200, (int)($_GET['page_size'] ?? 25)));

    $dailyCur  = perfDailyPerGame($win['from'], $win['to'], $tz);
    $dailyPrev = perfDailyPerGame($win['prev_from'], $win['prev_to'], $tz);
    $curTot  = perfSumPerGame($dailyCur);
    $prevTot = perfSumPerGame($dailyPrev);

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
        $rows[] = [
            'game_id'              => $gid,
            'game_name'            => $name,
            'status'               => $cache[$gid]['status'] ?? null,
            'plays'                => (int)$c['plays'],
            'tickets'              => round((float)$c['tickets'], 2),
            'cash'                 => round((float)$c['cash'], 2),
            'points'               => round((float)$c['regular_points'] + (float)$c['bonus_points'], 2),
            'avg_tickets_per_play' => $c['plays'] > 0 ? round((float)$c['tickets'] / $c['plays'], 2) : 0,
            'prev_plays'           => (int)$p['plays'],
            'prev_tickets'         => round((float)$p['tickets'], 2),
            'prev_cash'            => round((float)$p['cash'], 2),
        ];
    }

    if ($search !== '') {
        $needle = mb_strtolower($search);
        $rows = array_values(array_filter($rows, function ($r) use ($needle) {
            return mb_strpos(mb_strtolower((string)$r['game_name']), $needle) !== false
                || mb_strpos(mb_strtolower((string)$r['game_id']), $needle) !== false;
        }));
    }

    usort($rows, function ($a, $b) use ($sort) {
        if ($sort === 'name') return strcasecmp((string)$a['game_name'], (string)$b['game_name']);
        $key = $sort === 'plays' ? 'plays' : ($sort === 'cash' ? 'cash' : 'tickets');
        if ($a[$key] == $b[$key]) return $b['plays'] <=> $a['plays'];
        return ($b[$key] <=> $a[$key]);
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
        'search'          => $search,
        'pagination'      => [
            'page'        => $page,
            'page_size'   => $pageSize,
            'total'       => $total,
            'total_pages' => (int)ceil(max(1, $total) / $pageSize),
        ],
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
