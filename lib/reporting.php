<?php
/**
 * Reporting helpers shared across the analytics/games endpoints.
 */

require_once __DIR__ . '/db.php';

class Reporting {
    /**
     * The set of game IDs that count as "redemption" for payout math
     * (map of game_id => true).
     *
     * Preferred source of truth: the venue's redemption grouping. Operators
     * classify their ticket-dispensing games either as a CenterEdge game
     * category or as a pause group; we match whichever is named
     * `redemption_group_name` (config, default "Redemption", case-insensitive)
     * and take every game in it. Non-redemption games (rides, cages,
     * attractions) are therefore excluded from the payout denominator even if
     * they occasionally dispense a courtesy ticket — which is exactly what was
     * dragging the venue payout % artificially low under the old heuristic.
     *
     * Fallback: if no such category/group exists (or it contains no games),
     * fall back to the data-driven rule — a game that has EVER dispensed a
     * ticket — so the metric still works on venues without the grouping.
     *
     * @return array<string,bool>
     */
    public static function redemptionGameIds(): array {
        $name = trim((string)(DB::getConfig('redemption_group_name') ?: 'Redemption'));
        $set = [];

        if ($name !== '') {
            $needle = self::lower($name);

            // Category IDs whose name matches, from every place names live:
            // the cached CenterEdge categories payload and any pause-group
            // category links (which store the name locally).
            $catIds = [];
            $rawCats = DB::getConfig('cache_categories');
            if ($rawCats) {
                $decoded = json_decode($rawCats, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $c) {
                        if (is_array($c) && isset($c['id'], $c['name'])
                            && self::lower((string)$c['name']) === $needle) {
                            $catIds[(string)$c['id']] = true;
                        }
                    }
                }
            }
            foreach (DB::query('SELECT DISTINCT category_id, category_name FROM pause_group_categories') as $pc) {
                if (self::lower((string)($pc['category_name'] ?? '')) === $needle) {
                    $catIds[(string)$pc['category_id']] = true;
                }
            }

            // Games belonging to any matching category.
            if (!empty($catIds)) {
                self::addGamesInCategories($set, $catIds);
            }

            // A pause group named to match: its explicitly-listed games plus
            // any games in the categories it targets.
            foreach (DB::query('SELECT id FROM pause_groups WHERE LOWER(name) = :p0', [$needle]) as $pg) {
                $gid = (int)$pg['id'];
                foreach (DB::query('SELECT game_id FROM pause_group_games WHERE pause_group_id = :p0', [$gid]) as $r) {
                    $g = (string)$r['game_id'];
                    if ($g !== '') $set[$g] = true;
                }
                $groupCats = [];
                foreach (DB::query('SELECT category_id FROM pause_group_categories WHERE pause_group_id = :p0', [$gid]) as $r) {
                    $groupCats[(string)$r['category_id']] = true;
                }
                if (!empty($groupCats)) {
                    self::addGamesInCategories($set, $groupCats);
                }
            }
        }

        if (!empty($set)) {
            return $set;
        }

        // Fallback: any game that has ever dispensed a ticket.
        foreach (DB::query(
            'SELECT game_id FROM game_play_transactions WHERE redemption_tickets > 0 AND game_id != \'\'
             UNION
             SELECT game_id FROM game_daily_stats WHERE tickets > 0'
        ) as $r) {
            $set[(string)$r['game_id']] = true;
        }
        return $set;
    }

    /** Add every cached game whose category list intersects $catIds. */
    private static function addGamesInCategories(array &$set, array $catIds): void {
        foreach (DB::query('SELECT game_id, categories FROM game_state_cache') as $g) {
            $cats = json_decode((string)($g['categories'] ?? '[]'), true);
            if (!is_array($cats)) continue;
            foreach ($cats as $cid) {
                if (isset($catIds[(string)$cid])) {
                    $set[(string)$g['game_id']] = true;
                    break;
                }
            }
        }
    }

    private static function lower(string $s): string {
        return function_exists('mb_strtolower') ? mb_strtolower(trim($s)) : strtolower(trim($s));
    }

    // =========================================================================
    //  ROLLUP FRESHNESS — did the nightly job RUN, or was the venue just shut?
    // =========================================================================
    //
    // Both permanent rollups write ONE ROW PER DAY THAT HAS ACTIVITY. A day the
    // venue was closed produces no row, exactly like a day the refresh never
    // covered — so MAX(stat_date) answers "newest day with business", NOT
    // "newest day covered", and the two are indistinguishable at the TRAILING
    // EDGE of the table.
    //
    // That distinction used to be inferred from MAX(stat_date) alone, and the
    // year-over-year card asserted the answer outright: "the nightly refresh
    // has not advanced them". Interior holes are safe to read that way (the
    // rollup demonstrably moved past them), but the newest day is not: four
    // quiet days at the end of a season produce the identical signature, and
    // the card then sends somebody to debug a systemd unit that is working
    // perfectly. The reverse mistake is worse — the six-week freeze this venue
    // actually had (nightly container with no MSSQL driver) is the same shape,
    // so the warning cannot simply be dropped.
    //
    // So don't infer it. Two pieces of evidence are available locally, with no
    // MSSQL round trip:
    //
    //   1. A WATERMARK the refresh writes itself (markRollupRefreshed) — when
    //      it last ran and the newest complete day it covered. "The job ran" is
    //      then a recorded fact rather than a guess about missing rows.
    //   2. The app's OWN raw play feed as an independent witness of whether the
    //      venue did business on the days the ledger has nothing for. It is
    //      polled every minute by cron_watchdog.php on the STOCK image, so it
    //      keeps advancing through precisely the MSSQL and nightly-cron
    //      failures that freeze these rollups.
    //
    // Together they separate "the job is stuck" (act on it) from "the venue was
    // closed" (say so plainly and warn about nothing).

    /** Days behind before a stale rollup is worth warning about. */
    const ROLLUP_STALE_TOLERANCE_DAYS = 3;

    /**
     * Config keys per rollup: when its refresh last ran, and the newest
     * complete local day that run covered.
     *
     * `ledger` = venue_daily_stats, advanced ONLY by
     * Scheduler::refreshVenueDailyStatsRecent() (nightly cron + run_backfills).
     * `app` = game_daily_stats, advanced ONLY by Scheduler::rollupDailyStats().
     */
    const ROLLUP_KEYS = [
        'ledger' => ['at' => 'venue_daily_refresh_at', 'through' => 'venue_daily_refresh_through'],
        'app'    => ['at' => 'game_daily_rollup_at',   'through' => 'game_daily_rollup_through'],
    ];

    /**
     * Record that $rollup's refresh completed, covering every day up to (not
     * including) $localToday — i.e. through $localToday - 1, since neither
     * rollup ever writes the running day.
     *
     * Called only after the write loop finishes, so a run that throws part way
     * leaves the previous watermark standing and still reads as behind.
     */
    public static function markRollupRefreshed(string $rollup, string $localToday): void {
        if (!isset(self::ROLLUP_KEYS[$rollup])) return;
        $through = self::shiftDate($localToday, -1);
        if ($through === null) return;
        DB::setConfig(self::ROLLUP_KEYS[$rollup]['at'], gmdate('Y-m-d\TH:i:s\Z'));
        DB::setConfig(self::ROLLUP_KEYS[$rollup]['through'], $through);
    }

    /**
     * Gather the freshness evidence for one rollup and classify it.
     *
     * @param string      $rollup          'ledger' | 'app'
     * @param string      $expectedThrough newest day the rollup should hold —
     *                                     yesterday venue-local, or the last
     *                                     day of a window that already ended
     * @param string|null $dataThrough     MAX(stat_date) the caller already read
     */
    public static function rollupHealth(
        string $rollup,
        string $expectedThrough,
        ?string $dataThrough,
        ?DateTimeZone $tz = null
    ): array {
        if (!isset(self::ROLLUP_KEYS[$rollup])) $rollup = 'ledger';
        if ($tz === null) {
            $tzName = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
            try { $tz = new DateTimeZone($tzName); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
        }

        $refreshAt      = DB::getConfig(self::ROLLUP_KEYS[$rollup]['at']);
        $refreshThrough = DB::getConfig(self::ROLLUP_KEYS[$rollup]['through']);
        if (!is_string($refreshThrough) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $refreshThrough)) {
            $refreshThrough = null;
        }

        // The days actually in question: after the newest day carrying rows, up
        // to the newest day the last run COVERED. Days past that were never in
        // scope for it — the routine one-day lag lives there and is not a hole.
        $gapFrom = $dataThrough !== null ? self::shiftDate($dataThrough, 1) : null;
        $gapTo   = $refreshThrough !== null && $refreshThrough < $expectedThrough
            ? $refreshThrough
            : $expectedThrough;

        // Only ask the feed when the answer can change the verdict — i.e. when
        // the rollup is past tolerance and the refresh did cover days that came
        // back empty. Everything else settles without touching it.
        $feed = ['plays' => null, 'covers' => false];
        $dataStale = self::daysBehind($dataThrough, $expectedThrough);
        if ($dataStale !== null && $dataStale > self::ROLLUP_STALE_TOLERANCE_DAYS
            && $gapFrom !== null && $gapFrom <= $gapTo) {
            $feed = self::feedActivity($gapFrom, $gapTo, $tz);
        }

        return self::classifyRollup([
            'rollup'           => $rollup,
            'expected_through' => $expectedThrough,
            'data_through'     => $dataThrough,
            'refresh_at'       => is_string($refreshAt) ? $refreshAt : null,
            'refresh_through'  => $refreshThrough,
            'gap_from'         => $gapFrom,
            'gap_to'           => $gapTo,
            'feed_plays'       => $feed['plays'],
            'feed_covers_gap'  => $feed['covers'],
        ]);
    }

    /**
     * PURE classifier — no DB, no clock. Turns the gathered facts into one
     * verdict, so the boundary cases can be pinned by tests
     * (tests/test_rollup_health.php) instead of only ever being exercised on a
     * venue at 4am.
     *
     * States:
     *   ok       within tolerance; nothing to say.
     *   stalled  the refresh itself has not run — the actionable fault, and the
     *            one the six-week freeze produced.
     *   gap      the refresh ran and covered these days, but the source has no
     *            rows for them while the app's own feed recorded plays: real
     *            business is missing from the rollup.
     *   quiet    the refresh ran and covered these days; neither the rollup nor
     *            the app's own feed saw anything. The venue was shut. NOT a
     *            warning — this is the false alarm this whole block exists for.
     *   unknown  the evidence does not settle it (no watermark yet, or the raw
     *            feed no longer reaches back over the gap). Warn, but say what
     *            is and isn't known rather than naming a cause.
     */
    public static function classifyRollup(array $f): array {
        $tol       = self::ROLLUP_STALE_TOLERANCE_DAYS;
        $expected  = (string)$f['expected_through'];
        $through   = $f['data_through'] ?? null;
        $refreshTh = $f['refresh_through'] ?? null;

        $dataStale    = self::daysBehind($through, $expected);
        $refreshStale = self::daysBehind($refreshTh, $expected);

        $out = [
            'rollup'             => $f['rollup'] ?? 'ledger',
            'expected_through'   => $expected,
            'data_through'       => $through,
            'data_stale_days'    => $dataStale,
            'refresh_at'         => $f['refresh_at'] ?? null,
            'refresh_through'    => $refreshTh,
            'refresh_stale_days' => $refreshStale,
            'gap_from'           => $f['gap_from'] ?? null,
            'gap_to'             => $f['gap_to'] ?? null,
            'feed_plays'         => $f['feed_plays'] ?? null,
            'feed_covers_gap'    => !empty($f['feed_covers_gap']),
        ];

        // Nothing recorded at all is the empty-rollup case the callers already
        // handle with their own "no history yet" message.
        if ($through === null) {
            return $out + ['state' => 'unknown', 'warn' => false, 'summary' => ''];
        }

        // Within tolerance. One day behind is the healthy steady state here:
        // the nightly job fires at 00:05 UTC = 20:05 the previous day Eastern,
        // so it covers days before a local date that has not ended yet.
        if ($dataStale !== null && $dataStale <= $tol) {
            return $out + ['state' => 'ok', 'warn' => false, 'summary' => ''];
        }

        $noun = $out['rollup'] === 'app' ? 'play rollup' : 'POS ledger rollup';

        if ($refreshTh === null) {
            return $out + [
                'state' => 'unknown',
                'warn'  => true,
                'summary' => 'The ' . $noun . ' has no completed day after ' . $through
                    . '. This install has not recorded when its nightly refresh last ran, so it is '
                    . 'not yet possible to tell a stopped job from days the venue was closed — the '
                    . 'next nightly run records it.',
            ];
        }

        if ($refreshStale !== null && $refreshStale > $tol) {
            // Usually the watermark and the newest row froze on the same day,
            // so naming both would just print one date twice.
            return $out + [
                'state' => 'stalled',
                'warn'  => true,
                'summary' => 'The ' . $noun . ' has stopped advancing: its nightly refresh has not '
                    . 'covered anything past ' . $refreshTh . ', leaving it ' . $refreshStale
                    . ' days behind.'
                    . ($through !== $refreshTh ? ' Totals stop at ' . $through . '.' : '')
                    . ' These totals read low until it runs again.',
            ];
        }

        // The refresh is current, so every day up to gap_to was looked at and
        // came back empty. Ask the app's own feed whether that is plausible.
        $plays = $out['feed_plays'];
        if (is_int($plays) && $plays > 0) {
            return $out + [
                'state' => 'gap',
                'warn'  => true,
                'summary' => 'The ' . $noun . ' has no rows after ' . $through . ', but the nightly '
                    . 'refresh did cover those days and this app recorded ' . number_format($plays)
                    . ' plays on them — so business is missing from the source, not from the job.',
            ];
        }
        if ($out['feed_covers_gap'] && $plays === 0) {
            return $out + [
                'state' => 'quiet',
                'warn'  => false,
                'summary' => 'No recorded activity since ' . $through . '. The nightly refresh is '
                    . 'up to date and this app\'s own play feed is empty for those days too, so '
                    . 'this reads as a closure rather than a stalled job.',
            ];
        }

        return $out + [
            'state' => 'unknown',
            'warn'  => true,
            'summary' => 'The ' . $noun . ' has no completed day after ' . $through . '. Its nightly '
                . 'refresh is up to date, and this app\'s own play feed no longer reaches back far '
                . 'enough to confirm whether the venue was open then.',
        ];
    }

    /**
     * Plays the app's own raw feed recorded across the local days
     * [$fromDate, $toDate], plus whether the feed still retains that far back.
     *
     * One aggregate, never a row fetch — the feed runs to thousands of plays a
     * day and this is called on an ordinary page load (see the DB::each note in
     * CLAUDE.md). Local days are converted to UTC instants the same way
     * Scheduler::rollupDailyStats() does, so "a day" means the same thing here.
     */
    private static function feedActivity(string $fromDate, string $toDate, DateTimeZone $tz): array {
        $out = ['plays' => null, 'covers' => false];
        try {
            $utc   = new DateTimeZone('UTC');
            $start = new DateTime($fromDate . ' 00:00:00', $tz);
            $end   = (new DateTime($toDate . ' 00:00:00', $tz))->modify('+1 day');
            $startIso = (clone $start)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
            $endIso   = (clone $end)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');

            $row = DB::queryOne(
                'SELECT COUNT(*) AS n FROM game_play_transactions
                 WHERE transaction_time >= :p0 AND transaction_time < :p1',
                [$startIso, $endIso]
            );
            $out['plays'] = (int)($row['n'] ?? 0);

            // The feed is a ~30-day rolling window; if it starts after the gap
            // does, an empty count proves nothing about the earlier days.
            $edge = DB::queryOne('SELECT MIN(transaction_time) AS a FROM game_play_transactions');
            $earliest = $edge['a'] ?? null;
            $out['covers'] = is_string($earliest) && $earliest !== '' && $earliest <= $startIso;
        } catch (Exception $e) {
            // A feed we can't read is evidence of nothing — leave it unknown.
        }
        return $out;
    }

    /** Whole days from $date to $expected; 0 when already caught up, null if unparseable. */
    private static function daysBehind(?string $date, string $expected): ?int {
        if ($date === null) return null;
        if ($date >= $expected) return 0;
        return self::daysBetween($date, $expected);
    }

    /** Whole days between two YYYY-MM-DD dates, or null if either won't parse. */
    private static function daysBetween(string $a, string $b): ?int {
        $utc = new DateTimeZone('UTC');
        $x = DateTime::createFromFormat('!Y-m-d', $a, $utc);
        $y = DateTime::createFromFormat('!Y-m-d', $b, $utc);
        return ($x && $y) ? (int)$x->diff($y)->days : null;
    }

    /** $date shifted by $days, or null if it won't parse. */
    private static function shiftDate(string $date, int $days): ?string {
        $d = DateTime::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if (!$d) return null;
        return $d->modify(($days >= 0 ? '+' : '-') . abs($days) . ' days')->format('Y-m-d');
    }
}
