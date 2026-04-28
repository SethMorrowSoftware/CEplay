<?php
/**
 * Core scheduling engine.
 * Handles day planning, action execution, at-job management, and conflict resolution.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/centeredge_client.php';

class Scheduler {
    /**
     * Set app timezone and return the previous timezone for restoration.
     */
    private static function setTimezone(): string {
        $previous = date_default_timezone_get();
        $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
        date_default_timezone_set($tz);
        return $previous;
    }

    /**
     * Restore a previously saved timezone.
     */
    private static function restoreTimezone(string $tz): void {
        date_default_timezone_set($tz);
    }
    /**
     * Plan all actions for a given date.
     * Computes transition points, resolves conflicts, writes to scheduled_actions, queues at jobs.
     * Returns array of planned actions.
     */
    public static function planDay(?string $date = null): array {
        $__prevTz = self::setTimezone();
        try {

        if ($date === null) {
            $date = date('Y-m-d');
        }
        $todayDow = (int)(new DateTime($date))->format('w'); // 0=Sunday

        // Clear existing pending actions for this date
        self::clearPendingActions($date);

        $actions = [];

        // Get all active pause groups
        $groups = DB::query('SELECT id, name FROM pause_groups WHERE is_active = 1');

        foreach ($groups as $group) {
            $groupId = $group['id'];

            // Get today's recurring schedules for this group
            $schedules = DB::query(
                'SELECT * FROM schedules WHERE pause_group_id = :p0 AND day_of_week = :p1 AND is_active = 1',
                [$groupId, $todayDow]
            );

            // Get overrides active on this date
            $overrides = DB::query(
                'SELECT * FROM schedule_overrides WHERE pause_group_id = :p0
                 AND DATE(start_datetime) <= :p1 AND DATE(end_datetime) >= :p1',
                [$groupId, $date]
            );

            // Build transition points from schedules.
            // Schedule windows define when games are ACTIVE (unpaused).
            // At start_time → unpause (games become active)
            // At end_time   → pause   (active window ends)
            $transitions = [];
            foreach ($schedules as $sched) {
                $transitions[] = [
                    'time'   => $sched['start_time'],
                    'action' => 'unpause',
                    'source' => 'schedule',
                    'priority' => 0,
                ];
                $transitions[] = [
                    'time'   => $sched['end_time'],
                    'action' => 'pause',
                    'source' => 'schedule',
                    'priority' => 0,
                ];
            }

            // Build transition points from overrides (higher priority)
            foreach ($overrides as $override) {
                $startDt = new DateTime($override['start_datetime']);
                $endDt = new DateTime($override['end_datetime']);
                $startDate = $startDt->format('Y-m-d');
                $endDate = $endDt->format('Y-m-d');

                // If override starts today, add start transition
                if ($startDate === $date) {
                    $transitions[] = [
                        'time'   => $startDt->format('H:i'),
                        'action' => $override['action'],
                        'source' => 'override',
                        'priority' => 1,
                    ];
                }

                // If override ends today, restore to the correct state.
                // Check other overrides first, then fall back to the recurring schedule.
                if ($endDate === $date) {
                    $endTime = $endDt->format('H:i');

                    // Check if another override is still active at the end time
                    $restoreAction = null;
                    foreach ($overrides as $other) {
                        if ($other['id'] === $override['id']) {
                            continue;
                        }
                        $otherStart = new DateTime($other['start_datetime']);
                        $otherEnd = new DateTime($other['end_datetime']);
                        $otherStartStr = $otherStart->format('Y-m-d H:i');
                        $otherEndStr = $otherEnd->format('Y-m-d H:i');
                        $endFullStr = $endDt->format('Y-m-d H:i');
                        if ($otherStartStr <= $endFullStr && $otherEndStr > $endFullStr) {
                            $restoreAction = $other['action'];
                            break;
                        }
                    }

                    // No other override active — fall back to the recurring schedule.
                    // Default is paused (outside schedule windows).
                    // If inside a schedule window, restore to unpause (active).
                    if ($restoreAction === null) {
                        $restoreAction = 'pause'; // default: paused outside schedule windows
                        foreach ($schedules as $sched) {
                            if ($sched['start_time'] <= $endTime && $sched['end_time'] > $endTime) {
                                $restoreAction = 'unpause'; // inside active window
                                break;
                            }
                        }
                    }

                    $transitions[] = [
                        'time'   => $endTime,
                        'action' => $restoreAction,
                        'source' => 'override',
                        'priority' => 1,
                    ];
                }
            }

            // Suppress schedule transitions that fall during an active override window.
            // Overrides take priority for their entire duration, not just at their
            // exact start/end times. Any recurring-schedule transition that would
            // fire while an override is active must be dropped so it cannot
            // contradict the override (e.g. an unpause from a schedule ending
            // while a pause-override is still active).
            $filtered = [];
            foreach ($transitions as $t) {
                if ($t['source'] === 'schedule') {
                    $checkTime = $date . ' ' . $t['time'];
                    foreach ($overrides as $override) {
                        if ($override['start_datetime'] <= $checkTime && $override['end_datetime'] > $checkTime) {
                            continue 2; // Skip this schedule transition
                        }
                    }
                }
                $filtered[] = $t;
            }
            $transitions = $filtered;

            // Sort by time, then by priority (override > schedule)
            usort($transitions, function ($a, $b) {
                $cmp = strcmp($a['time'], $b['time']);
                if ($cmp !== 0) return $cmp;
                return $b['priority'] - $a['priority']; // Higher priority first
            });

            // Deduplicate: at each time, highest priority wins
            $seen = [];
            foreach ($transitions as $t) {
                $key = $t['time'];
                if (!isset($seen[$key]) || $t['priority'] > $seen[$key]['priority']) {
                    $seen[$key] = $t;
                }
            }

            // Filter out past times
            $now = new DateTime('now', new DateTimeZone(date_default_timezone_get()));
            $nowTime = $now->format('H:i');
            $isToday = ($date === $now->format('Y-m-d'));

            foreach ($seen as $time => $t) {
                if ($isToday && $time < $nowTime) {
                    continue; // Skip past times (strict < so current-minute transitions are kept)
                }

                DB::execute(
                    'INSERT INTO scheduled_actions (pause_group_id, action, scheduled_time, scheduled_date, source)
                     VALUES (:p0, :p1, :p2, :p3, :p4)',
                    [$groupId, $t['action'], $time, $date, $t['source']]
                );
                $actionId = DB::lastInsertId();

                $actions[] = [
                    'id'       => $actionId,
                    'group_id' => $groupId,
                    'group_name' => $group['name'],
                    'action'   => $t['action'],
                    'time'     => $time,
                    'source'   => $t['source'],
                ];
            }
        }

        return $actions;

        } finally { self::restoreTimezone($__prevTz); }
    }

    /**
     * Clear pending (unexecuted) actions and cancel their at jobs.
     */
    public static function clearPendingActions(?string $date = null): void {
        $__prevTz = self::setTimezone();
        try {

        if ($date === null) {
            $date = date('Y-m-d');
        }

        // Get pending actions with at_job_id
        $pending = DB::query(
            'SELECT id, at_job_id FROM scheduled_actions WHERE scheduled_date = :p0 AND executed = 0',
            [$date]
        );

        // Cancel at jobs when scheduler support exists.
        if (self::hasAtScheduler()) {
            foreach ($pending as $action) {
                if (!empty($action['at_job_id'])) {
                    self::cancelAtJob($action['at_job_id']);
                }
            }
        }

        // Delete pending actions
        DB::execute(
            'DELETE FROM scheduled_actions WHERE scheduled_date = :p0 AND executed = 0',
            [$date]
        );

        } finally { self::restoreTimezone($__prevTz); }
    }

    /**
     * Queue pending scheduled actions as at jobs.
     */
    public static function queueAtJobs(?string $date = null): void {
        $__prevTz = self::setTimezone();
        try {

        if ($date === null) {
            $date = date('Y-m-d');
        }

        // Portable fallback: if `at` is unavailable (common on shared hosting),
        // actions remain in scheduled_actions and are executed by cron_watchdog
        // / API missed-action checks once their scheduled_time has passed.
        if (!self::hasAtScheduler()) {
            return;
        }

        $actions = DB::query(
            'SELECT id, scheduled_time FROM scheduled_actions WHERE scheduled_date = :p0 AND executed = 0 AND at_job_id IS NULL',
            [$date]
        );

        $scriptPath = realpath(__DIR__ . '/../run_action.php');
        if ($scriptPath === false) {
            error_log('queueAtJobs: run_action.php not found at ' . __DIR__ . '/../run_action.php');
            return;
        }

        // Use the full PHP CLI path so at jobs work regardless of PATH
        $phpBin = PHP_BINDIR . '/php';
        if (!file_exists($phpBin)) {
            $phpBin = PHP_BINARY;
        }

        foreach ($actions as $action) {
            // Build the command that `at` will execute via /bin/sh.
            // Use printf with %q (bash) or manual escaping to avoid nested
            // quoting issues with the old echo-in-double-quotes approach.
            $atCmd = sprintf('%s %s --id %d', $phpBin, $scriptPath, $action['id']);
            $cmd = sprintf(
                'echo %s | at %s 2>&1',
                escapeshellarg($atCmd),
                escapeshellarg($action['scheduled_time'])
            );

            $output = [];
            exec($cmd, $output, $exitCode);
            $outputStr = implode("\n", $output);

            $jobId = self::parseAtJobId($outputStr);
            if ($jobId) {
                DB::execute(
                    'UPDATE scheduled_actions SET at_job_id = :p0 WHERE id = :p1',
                    [$jobId, $action['id']]
                );
            } else {
                error_log("Failed to queue at job for action #{$action['id']} at {$action['scheduled_time']}: exit=$exitCode output=$outputStr");
            }
        }

        } finally { self::restoreTimezone($__prevTz); }
    }

    /**
     * Replan today: clear pending actions, recompute, requeue.
     * Acquires the scheduler lock to prevent races with concurrent at-jobs
     * and the watchdog.  When called from CLI scripts that already hold the
     * lock, the flock() is a no-op on the same process.
     */
    public static function replanToday(): void {
        $__prevTz = self::setTimezone();
        try {
        $today = date('Y-m-d');

        // Acquire the scheduler lock so we don't race with run_action.php
        // or cron_watchdog.php while clearing / recreating actions.
        $lockFh = fopen(LOCK_FILE, 'c');
        if (!$lockFh) {
            error_log('replanToday: could not open lock file');
            return;
        }

        $lockHeld = false;
        for ($i = 0; $i < 6; $i++) { // up to 30s
            if (flock($lockFh, LOCK_EX | LOCK_NB)) {
                $lockHeld = true;
                break;
            }
            usleep(5000000); // 5s
        }

        if (!$lockHeld) {
            fclose($lockFh);
            error_log('replanToday: could not acquire lock after 30s, skipping to avoid race condition');
            return;
        }

        try {
            self::clearPendingActions($today);
            self::planDay($today);
            self::queueAtJobs($today);
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }

        } finally { self::restoreTimezone($__prevTz); }
    }

    /**
     * Enforce the desired state for each active group at the current time.
     * This acts as a watchdog fallback when at jobs are delayed or unavailable.
     */
    public static function enforceCurrentStates(): array {
        $__prevTz = self::setTimezone();
        try {

        $now = new DateTime('now', new DateTimeZone(date_default_timezone_get()));
        $todayDow = (int)$now->format('w');
        $nowStr = $now->format('Y-m-d H:i');
        $nowTime = $now->format('H:i');

        // Sync game states only if the cache is stale (older than 2 minutes).
        // This avoids hammering the CenterEdge API every single minute while
        // still keeping cache reasonably fresh for state comparisons.
        try {
            self::syncGameStatesIfStale(120);
        } catch (Exception $e) {
            error_log('Watchdog sync failed: ' . $e->getMessage());
        }
        // Same throttled freshness check for kiosks. Non-fatal if /kiosks
        // isn't supported (helper handles the exception internally).
        self::syncKioskStatesIfStale(120);

        $summary = ['groups_checked' => 0, 'groups_enforced' => 0, 'results' => []];
        $groups = DB::query('SELECT id FROM pause_groups WHERE is_active = 1');

        foreach ($groups as $group) {
            $groupId = (int)$group['id'];
            $summary['groups_checked']++;

            // Highest-priority rule: manual override wins.
            // When an operator manually pauses/unpauses, that intent is
            // respected until the next scheduled transition fires.
            $manualOverride = DB::queryOne(
                'SELECT manual_override_action FROM pause_groups
                 WHERE id = :p0 AND manual_override_action IS NOT NULL',
                [$groupId]
            );

            if ($manualOverride) {
                // Manual override is active — skip enforcement for this group
                $summary['results'][$groupId] = ['skipped_reason' => 'manual_override'];
                continue;
            }

            // Second priority: active schedule override wins.
            $activeOverride = DB::queryOne(
                'SELECT action FROM schedule_overrides
                 WHERE pause_group_id = :p0 AND start_datetime <= :p1 AND end_datetime > :p1
                 ORDER BY start_datetime DESC, id DESC LIMIT 1',
                [$groupId, $nowStr]
            );

            // Default: paused (outside any schedule window).
            // Schedule windows define active (unpaused) hours.
            $desiredAction = 'pause';
            $source = 'watchdog';

            if ($activeOverride) {
                $desiredAction = $activeOverride['action'];
                $source = 'override';
            } else {
                $activeSchedule = DB::queryOne(
                    'SELECT id FROM schedules
                     WHERE pause_group_id = :p0 AND day_of_week = :p1 AND is_active = 1
                       AND start_time <= :p2 AND end_time > :p2
                     LIMIT 1',
                    [$groupId, $todayDow, $nowTime]
                );
                if ($activeSchedule) {
                    $desiredAction = 'unpause';
                    $source = 'schedule';
                }
            }

            $result = self::executeStateChange(
                $groupId,
                $desiredAction === 'pause' ? 'paused' : 'enabled',
                $source,
                false
            );

            if (!empty($result['changed'])) {
                $summary['groups_enforced']++;
            }

            $summary['results'][$groupId] = $result;
        }

        return $summary;

        } finally { self::restoreTimezone($__prevTz); }
    }

    /**
     * Enforce the desired state for a single group at the current time.
     * Used after override changes that require immediate state correction.
     *
     * When called from an override create/delete, the $clearManual flag
     * indicates that any active manual override should be superseded by
     * the new override intent.
     */
    public static function enforceGroupState(int $groupId, bool $clearManual = true): array {
        $__prevTz = self::setTimezone();
        try {

        $now = new DateTime('now', new DateTimeZone(date_default_timezone_get()));
        $todayDow = (int)$now->format('w');
        $nowStr = $now->format('Y-m-d H:i');
        $nowTime = $now->format('H:i');

        // If called from override management, clear any manual override
        // so the new override/schedule state takes effect.
        if ($clearManual) {
            self::clearManualOverride($groupId);
        }

        // Highest-priority rule: manual override wins (if still active).
        $manualOverride = DB::queryOne(
            'SELECT manual_override_action FROM pause_groups
             WHERE id = :p0 AND manual_override_action IS NOT NULL',
            [$groupId]
        );

        if ($manualOverride) {
            $desiredAction = $manualOverride['manual_override_action'];
            return self::executeStateChange(
                $groupId,
                $desiredAction === 'pause' ? 'paused' : 'enabled',
                'manual'
            );
        }

        // Second priority: active schedule override wins.
        $activeOverride = DB::queryOne(
            'SELECT action FROM schedule_overrides
             WHERE pause_group_id = :p0 AND start_datetime <= :p1 AND end_datetime > :p1
             ORDER BY start_datetime DESC, id DESC LIMIT 1',
            [$groupId, $nowStr]
        );

        // Default: paused (outside any schedule window).
        // Schedule windows define active (unpaused) hours.
        $desiredAction = 'pause';
        $source = 'schedule';

        if ($activeOverride) {
            $desiredAction = $activeOverride['action'];
            $source = 'override';
        } else {
            $activeSchedule = DB::queryOne(
                'SELECT id FROM schedules
                 WHERE pause_group_id = :p0 AND day_of_week = :p1 AND is_active = 1
                   AND start_time <= :p2 AND end_time > :p2
                 LIMIT 1',
                [$groupId, $todayDow, $nowTime]
            );
            if ($activeSchedule) {
                $desiredAction = 'unpause';
                $source = 'schedule';
            }
        }

        return self::executeStateChange(
            $groupId,
            $desiredAction === 'pause' ? 'paused' : 'enabled',
            $source
        );

        } finally { self::restoreTimezone($__prevTz); }
    }

    /**
     * Execute a single scheduled action by ID.
     * Returns array of results.
     */
    public static function executeAction(int $actionId): array {
        $action = DB::queryOne('SELECT * FROM scheduled_actions WHERE id = :p0', [$actionId]);
        if (!$action) {
            throw new RuntimeException("Scheduled action #$actionId not found.");
        }

        if ($action['executed'] !== null && $action['executed'] != 0) {
            return ['status' => 'already_executed', 'action_id' => $actionId];
        }

        $groupId = $action['pause_group_id'];
        $desiredAction = $action['action']; // 'pause' or 'unpause'
        $source = $action['source'];
        $desiredStatus = ($desiredAction === 'pause') ? 'paused' : 'enabled';

        // A scheduled transition firing supersedes any manual override.
        // The operator's manual action was a temporary hold; now the
        // regular schedule takes over again.
        self::clearManualOverride($groupId);

        $results = self::executeStateChange($groupId, $desiredStatus, $source);

        // Mark action as executed
        $allSuccess = !empty($results['changed']) || (empty($results['errors']));
        DB::execute(
            'UPDATE scheduled_actions SET executed = :p0, executed_at = datetime(\'now\') WHERE id = :p1',
            [$allSuccess ? 1 : 2, $actionId]
        );

        return $results;
    }

    /**
     * Execute an immediate action (for overrides and manual actions).
     */
    public static function executeImmediate(int $groupId, string $action, string $source = 'manual'): array {
        $desiredStatus = ($action === 'pause') ? 'paused' : 'enabled';
        // Skip the full CenterEdge sync for manual actions — the cache is
        // at most 30s stale from dashboard polling, and the patch call itself
        // will confirm the actual new state.  This avoids an expensive API
        // round-trip that adds ~5-10s of latency to every button press.
        $skipSync = ($source === 'manual');
        $result = self::executeStateChange($groupId, $desiredStatus, $source, !$skipSync);

        // Record manual override so the watchdog and enforcement logic
        // respect the operator's intent until the next scheduled transition.
        if ($source === 'manual') {
            self::setManualOverride($groupId, $action);
        }

        return $result;
    }

    /**
     * Set a manual override for a group.
     * This takes highest priority: the watchdog and enforcement logic will
     * respect it until a scheduled action fires or it is explicitly cleared.
     */
    public static function setManualOverride(int $groupId, string $action): void {
        $__prevTz = self::setTimezone();
        try {
        DB::execute(
            'UPDATE pause_groups SET manual_override_action = :p0, manual_override_at = :p1 WHERE id = :p2',
            [$action, date('Y-m-d H:i:s'), $groupId]
        );
        } finally { self::restoreTimezone($__prevTz); }
    }

    /**
     * Clear the manual override for a group (e.g. when a scheduled transition fires).
     */
    public static function clearManualOverride(int $groupId): void {
        DB::execute(
            'UPDATE pause_groups SET manual_override_action = NULL, manual_override_at = NULL WHERE id = :p0',
            [$groupId]
        );
    }

    // -----------------------------------------------
    // Retry Queue (games + kiosks)
    // -----------------------------------------------

    /** Default cap on retry attempts before giving up. */
    const RETRY_MAX_ATTEMPTS = 10;

    /**
     * Enqueue (or supersede) a pending retry for an asset whose pause/unpause
     * patch failed at the source — typically because the asset was in use.
     * The watchdog re-attempts up to max_attempts and then gives up.
     *
     * UPSERT semantics: if a retry already exists for this asset, the
     * desired_status / source / pause_group_id are replaced AND the attempts
     * counter is reset to 0 if the desired state changed (latest intent wins).
     * If the desired state matches the existing pending retry, attempts is
     * preserved (we just update last_error / last_attempted_at).
     */
    public static function queueRetry(
        string $assetType,
        string $assetId,
        string $desiredStatus,
        string $source,
        ?int $pauseGroupId,
        ?string $errorMessage
    ): void {
        if ($assetType !== 'game' && $assetType !== 'kiosk') {
            return; // unknown asset type; ignore
        }

        $existing = DB::queryOne(
            'SELECT desired_status, attempts FROM action_retries
             WHERE asset_type = :p0 AND asset_id = :p1',
            [$assetType, $assetId]
        );

        if ($existing && $existing['desired_status'] === $desiredStatus) {
            // Same intent — keep attempt count, just refresh error/timestamp.
            DB::execute(
                'UPDATE action_retries
                 SET source = :p0, pause_group_id = :p1, last_error = :p2,
                     updated_at = datetime(\'now\')
                 WHERE asset_type = :p3 AND asset_id = :p4',
                [$source, $pauseGroupId, $errorMessage, $assetType, $assetId]
            );
            return;
        }

        // New row, or desired_status changed — reset attempts.
        DB::execute(
            'INSERT INTO action_retries
                 (asset_type, asset_id, desired_status, source, pause_group_id,
                  attempts, max_attempts, last_error, created_at, updated_at)
             VALUES (:p0, :p1, :p2, :p3, :p4, 0, :p5, :p6, datetime(\'now\'), datetime(\'now\'))
             ON CONFLICT(asset_type, asset_id) DO UPDATE SET
                 desired_status = :p2,
                 source = :p3,
                 pause_group_id = :p4,
                 attempts = 0,
                 last_error = :p6,
                 updated_at = datetime(\'now\')',
            [$assetType, $assetId, $desiredStatus, $source, $pauseGroupId,
             self::RETRY_MAX_ATTEMPTS, $errorMessage]
        );
    }

    /**
     * Drop the pending retry (if any) for an asset.
     * Called whenever a fresh pause/unpause for that asset succeeds.
     */
    public static function clearRetry(string $assetType, string $assetId): void {
        DB::execute(
            'DELETE FROM action_retries WHERE asset_type = :p0 AND asset_id = :p1',
            [$assetType, $assetId]
        );
    }

    /**
     * Process all pending action retries: re-attempt each asset's stored
     * desired_status, increment attempts on failure, delete on success or
     * after max_attempts. Designed to be called once per watchdog cycle
     * (i.e. once per minute) so a 10-attempt cap means a 10-minute window.
     *
     * Returns a summary array for logging.
     */
    public static function processRetries(): array {
        $summary = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'gave_up' => 0];

        $pending = DB::query(
            'SELECT id, asset_type, asset_id, desired_status, source, pause_group_id,
                    attempts, max_attempts
             FROM action_retries
             WHERE attempts < max_attempts
             ORDER BY asset_type ASC, id ASC'
        );

        if (empty($pending)) {
            return $summary;
        }

        // Group by asset_type so we can issue one bulk PATCH per type.
        $byType = ['game' => [], 'kiosk' => []];
        foreach ($pending as $row) {
            $type = $row['asset_type'];
            if (!isset($byType[$type])) {
                continue;
            }
            $byType[$type][$row['asset_id']] = $row;
        }

        try {
            $client = new CenterEdgeClient();
        } catch (Exception $e) {
            error_log('processRetries: client init failed: ' . $e->getMessage());
            return $summary;
        }

        // ---- Games ----
        if (!empty($byType['game'])) {
            $changes = [];
            foreach ($byType['game'] as $row) {
                $changes[$row['asset_id']] = $row['desired_status'];
            }
            try {
                $result = $client->patchGames($changes);
                self::applyRetryResult('game', $byType['game'], $result, 'games', 'game_state_cache', 'game_id', $summary);
            } catch (Exception $e) {
                self::handleBulkRetryFailure('game', $byType['game'], $e->getMessage(), $summary);
            }
        }

        // ---- Kiosks ----
        if (!empty($byType['kiosk'])) {
            $changes = [];
            foreach ($byType['kiosk'] as $row) {
                $changes[$row['asset_id']] = $row['desired_status'];
            }
            try {
                $result = $client->patchKiosks($changes);
                self::applyRetryResult('kiosk', $byType['kiosk'], $result, 'kiosks', 'kiosk_state_cache', 'kiosk_id', $summary);
            } catch (Exception $e) {
                self::handleBulkRetryFailure('kiosk', $byType['kiosk'], $e->getMessage(), $summary);
            }
        }

        return $summary;
    }

    /**
     * Apply per-asset success/failure results from a bulk patch retry.
     * On success: update cache, clear retry row, log action_log success.
     * On failure: increment attempts; if at max, log give-up audit and delete row.
     */
    private static function applyRetryResult(
        string $assetType,
        array $rowsById,
        array $result,
        string $successKey,
        string $cacheTable,
        string $cacheIdColumn,
        array &$summary
    ): void {
        $now = date('Y-m-d H:i:s');

        foreach ($result[$successKey] ?? [] as $asset) {
            $aid = (string)($asset['id'] ?? '');
            if ($aid === '' || !isset($rowsById[$aid])) {
                continue;
            }
            $row = $rowsById[$aid];
            $summary['attempted']++;
            $summary['succeeded']++;

            DB::execute(
                "UPDATE $cacheTable SET operation_status = :p0, last_synced_at = datetime('now')
                 WHERE $cacheIdColumn = :p1",
                [$asset['operationStatus'] ?? $row['desired_status'], $aid]
            );

            $actionName = $row['desired_status'] === 'paused' ? 'pause' : 'unpause';
            self::logAction(
                'retry',
                $actionName,
                $row['pause_group_id'] !== null ? (int)$row['pause_group_id'] : null,
                $aid,
                $asset['name'] ?? '',
                true,
                null,
                ['asset' => $assetType, 'attempt' => (int)$row['attempts'] + 1]
            );

            self::clearRetry($assetType, $aid);
            unset($rowsById[$aid]);
        }

        // Anything left in $rowsById either errored or was silently dropped.
        // Treat both as failures so the counter advances.
        $errorById = [];
        foreach ($result['errors'] ?? [] as $eid => $err) {
            $errorById[(string)$eid] = is_array($err) ? ($err['message'] ?? json_encode($err)) : (string)$err;
        }

        foreach ($rowsById as $aid => $row) {
            $errorMsg = $errorById[$aid] ?? 'no response from server';
            self::recordRetryFailure($assetType, $aid, $row, $errorMsg, $now, $summary);
        }
    }

    /**
     * Bulk patch threw (e.g. /kiosks unsupported, network error). Record a
     * failure for every asset of that type so the attempt counter advances.
     */
    private static function handleBulkRetryFailure(string $assetType, array $rowsById, string $errorMsg, array &$summary): void {
        $now = date('Y-m-d H:i:s');
        foreach ($rowsById as $aid => $row) {
            self::recordRetryFailure($assetType, $aid, $row, $errorMsg, $now, $summary);
        }
    }

    /**
     * Increment a retry's attempts. If we've now reached max_attempts, log a
     * give-up audit entry and delete the row; otherwise update last_error /
     * last_attempted_at so the UI can surface progress.
     */
    private static function recordRetryFailure(
        string $assetType,
        string $assetId,
        array $row,
        string $errorMsg,
        string $now,
        array &$summary
    ): void {
        $summary['attempted']++;
        $newAttempts = (int)$row['attempts'] + 1;
        $maxAttempts = (int)$row['max_attempts'];

        $actionName = $row['desired_status'] === 'paused' ? 'pause' : 'unpause';

        if ($newAttempts >= $maxAttempts) {
            $summary['gave_up']++;
            self::logAction(
                'retry',
                $actionName,
                $row['pause_group_id'] !== null ? (int)$row['pause_group_id'] : null,
                $assetId,
                '',
                false,
                $errorMsg,
                [
                    'asset' => $assetType,
                    'attempt' => $newAttempts,
                    'max_attempts' => $maxAttempts,
                    'gave_up' => true,
                ]
            );
            DB::execute(
                'DELETE FROM action_retries WHERE asset_type = :p0 AND asset_id = :p1',
                [$assetType, $assetId]
            );
            return;
        }

        $summary['failed']++;
        DB::execute(
            'UPDATE action_retries
             SET attempts = :p0, last_attempted_at = :p1, last_error = :p2,
                 updated_at = datetime(\'now\')
             WHERE asset_type = :p3 AND asset_id = :p4',
            [$newAttempts, $now, $errorMsg, $assetType, $assetId]
        );

        self::logAction(
            'retry',
            $actionName,
            $row['pause_group_id'] !== null ? (int)$row['pause_group_id'] : null,
            $assetId,
            '',
            false,
            $errorMsg,
            ['asset' => $assetType, 'attempt' => $newAttempts, 'max_attempts' => $maxAttempts]
        );
    }

    /**
     * Look up the pending retry (if any) for a given asset, formatted for
     * inclusion in API responses.
     */
    public static function getPendingRetry(string $assetType, string $assetId): ?array {
        $row = DB::queryOne(
            'SELECT desired_status, source, attempts, max_attempts, last_attempted_at, last_error, created_at
             FROM action_retries
             WHERE asset_type = :p0 AND asset_id = :p1',
            [$assetType, $assetId]
        );
        if (!$row) {
            return null;
        }
        return [
            'desired_status'    => $row['desired_status'],
            'source'            => $row['source'],
            'attempts'          => (int)$row['attempts'],
            'max_attempts'      => (int)$row['max_attempts'],
            'last_attempted_at' => $row['last_attempted_at'],
            'last_error'        => $row['last_error'],
            'created_at'        => $row['created_at'],
        ];
    }

    /**
     * Bulk lookup of pending retries by asset_type, keyed by asset_id.
     * Avoids N+1 queries when listing kiosks or games.
     */
    public static function getPendingRetriesByType(string $assetType): array {
        $rows = DB::query(
            'SELECT asset_id, desired_status, source, attempts, max_attempts, last_attempted_at, last_error, created_at
             FROM action_retries WHERE asset_type = :p0',
            [$assetType]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['asset_id']] = [
                'desired_status'    => $r['desired_status'],
                'source'            => $r['source'],
                'attempts'          => (int)$r['attempts'],
                'max_attempts'      => (int)$r['max_attempts'],
                'last_attempted_at' => $r['last_attempted_at'],
                'last_error'        => $r['last_error'],
                'created_at'        => $r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * Core state change logic: resolve games + kiosks, check states, patch CenterEdge.
     */
    private static function executeStateChange(int $groupId, string $desiredStatus, string $source, bool $syncCache = true): array {
        $results = ['changed' => [], 'skipped' => [], 'errors' => []];

        try {
            $client = new CenterEdgeClient();

            // Sync fresh states for both games and kiosks. Kiosk sync is
            // non-fatal: if the card system doesn't expose /kiosks the call
            // throws, but games should still be controllable.
            if ($syncCache) {
                $client->syncGamesToCache();
                try {
                    $client->syncKiosksToCache();
                } catch (Exception $e) {
                    error_log('Kiosk sync skipped during state change: ' . $e->getMessage());
                }
            }

            // ---- Games ----
            $gameIds = self::resolveGroupGames($groupId);
            $gameChanges = [];
            foreach ($gameIds as $gameId) {
                $cached = DB::queryOne('SELECT * FROM game_state_cache WHERE game_id = :p0', [$gameId]);
                if (!$cached) {
                    continue;
                }
                if ($cached['operation_status'] === 'outOfService') {
                    $results['skipped'][] = ['game_id' => $gameId, 'game_name' => $cached['game_name'], 'reason' => 'outOfService'];
                    self::logAction($source, 'skip', $groupId, $gameId, $cached['game_name'], true, null, ['reason' => 'outOfService']);
                    continue;
                }
                if ($cached['operation_status'] === $desiredStatus) {
                    $results['skipped'][] = ['game_id' => $gameId, 'game_name' => $cached['game_name'], 'reason' => 'already_' . $desiredStatus];
                    continue;
                }
                $gameChanges[$gameId] = $desiredStatus;
            }

            if (!empty($gameChanges)) {
                $patchResult = $client->patchGames($gameChanges);

                foreach ($patchResult['games'] ?? [] as $game) {
                    $gid = (string)$game['id'];
                    $gname = $game['name'] ?? '';
                    $results['changed'][] = ['game_id' => $gid, 'game_name' => $gname, 'new_status' => $desiredStatus];

                    DB::execute(
                        'UPDATE game_state_cache SET operation_status = :p0, last_synced_at = datetime(\'now\') WHERE game_id = :p1',
                        [$game['operationStatus'] ?? $desiredStatus, $gid]
                    );

                    $actionName = $desiredStatus === 'paused' ? 'pause' : 'unpause';
                    self::logAction($source, $actionName, $groupId, $gid, $gname, true);

                    // Successful patch supersedes any pending retry for this game.
                    self::clearRetry('game', $gid);
                }

                foreach ($patchResult['errors'] ?? [] as $gid => $error) {
                    $gname = '';
                    $cached = DB::queryOne('SELECT game_name FROM game_state_cache WHERE game_id = :p0', [(string)$gid]);
                    if ($cached) $gname = $cached['game_name'];

                    $errorMsg = is_array($error) ? ($error['message'] ?? json_encode($error)) : (string)$error;
                    $results['errors'][] = ['game_id' => (string)$gid, 'game_name' => $gname, 'error' => $errorMsg];

                    $actionName = $desiredStatus === 'paused' ? 'pause' : 'unpause';
                    self::logAction($source, $actionName, $groupId, (string)$gid, $gname, false, $errorMsg);

                    // Queue a retry: game may have been in use; the watchdog
                    // will re-attempt once per minute up to max_attempts.
                    self::queueRetry('game', (string)$gid, $desiredStatus, $source, $groupId, $errorMsg);
                }
            }

            // ---- Kiosks ----
            // Same logic as games. A pause-group action targets every kiosk
            // attached to the group, with the same outOfService / already-in-state
            // skip rules. Kiosks reporting no operationStatus (treated as
            // "unknown" per the API spec) are also skipped.
            $kioskIds = self::resolveGroupKiosks($groupId);
            $kioskChanges = [];
            foreach ($kioskIds as $kioskId) {
                $cached = DB::queryOne('SELECT * FROM kiosk_state_cache WHERE kiosk_id = :p0', [$kioskId]);
                if (!$cached) {
                    continue;
                }
                $cachedStatus = $cached['operation_status'];
                if ($cachedStatus === '' || $cachedStatus === null) {
                    $results['skipped'][] = ['kiosk_id' => $kioskId, 'kiosk_name' => $cached['kiosk_name'], 'reason' => 'unknown_status'];
                    continue;
                }
                if ($cachedStatus === 'outOfService') {
                    $results['skipped'][] = ['kiosk_id' => $kioskId, 'kiosk_name' => $cached['kiosk_name'], 'reason' => 'outOfService'];
                    self::logAction($source, 'skip', $groupId, $kioskId, $cached['kiosk_name'], true, null, ['reason' => 'outOfService', 'asset' => 'kiosk']);
                    continue;
                }
                if ($cachedStatus === $desiredStatus) {
                    $results['skipped'][] = ['kiosk_id' => $kioskId, 'kiosk_name' => $cached['kiosk_name'], 'reason' => 'already_' . $desiredStatus];
                    continue;
                }
                $kioskChanges[$kioskId] = $desiredStatus;
            }

            if (!empty($kioskChanges)) {
                try {
                    $kioskPatchResult = $client->patchKiosks($kioskChanges);

                    foreach ($kioskPatchResult['kiosks'] ?? [] as $kiosk) {
                        $kid = (string)$kiosk['id'];
                        $kname = $kiosk['name'] ?? '';
                        $results['changed'][] = ['kiosk_id' => $kid, 'kiosk_name' => $kname, 'new_status' => $desiredStatus];

                        DB::execute(
                            'UPDATE kiosk_state_cache SET operation_status = :p0, last_synced_at = datetime(\'now\') WHERE kiosk_id = :p1',
                            [$kiosk['operationStatus'] ?? $desiredStatus, $kid]
                        );

                        $actionName = $desiredStatus === 'paused' ? 'pause' : 'unpause';
                        self::logAction($source, $actionName, $groupId, $kid, $kname, true, null, ['asset' => 'kiosk']);

                        // Successful patch supersedes any pending retry for this kiosk.
                        self::clearRetry('kiosk', $kid);
                    }

                    foreach ($kioskPatchResult['errors'] ?? [] as $kid => $error) {
                        $kname = '';
                        $cached = DB::queryOne('SELECT kiosk_name FROM kiosk_state_cache WHERE kiosk_id = :p0', [(string)$kid]);
                        if ($cached) $kname = $cached['kiosk_name'];

                        $errorMsg = is_array($error) ? ($error['message'] ?? json_encode($error)) : (string)$error;
                        $results['errors'][] = ['kiosk_id' => (string)$kid, 'kiosk_name' => $kname, 'error' => $errorMsg];

                        $actionName = $desiredStatus === 'paused' ? 'pause' : 'unpause';
                        self::logAction($source, $actionName, $groupId, (string)$kid, $kname, false, $errorMsg, ['asset' => 'kiosk']);

                        // Queue a retry: kiosk may have been in use; the watchdog
                        // will re-attempt once per minute up to max_attempts.
                        self::queueRetry('kiosk', (string)$kid, $desiredStatus, $source, $groupId, $errorMsg);
                    }
                } catch (Exception $e) {
                    // The card system may not support PATCH /kiosks. Don't fail
                    // the whole action — the games already got patched above.
                    $actionName = $desiredStatus === 'paused' ? 'pause' : 'unpause';
                    self::logAction($source, $actionName, $groupId, '', '', false, $e->getMessage(), ['asset' => 'kiosk']);
                    $results['errors'][] = ['asset' => 'kiosk', 'error' => $e->getMessage()];
                }
            }

            if (empty($gameIds) && empty($kioskIds)) {
                self::logAction($source, $desiredStatus === 'paused' ? 'pause' : 'unpause', $groupId, '', '', true, null, ['note' => 'No games or kiosks in group']);
            }
        } catch (Exception $e) {
            $actionName = $desiredStatus === 'paused' ? 'pause' : 'unpause';
            self::logAction($source, $actionName, $groupId, '', '', false, $e->getMessage());
            $results['errors'][] = ['error' => $e->getMessage()];
        }

        return $results;
    }

    /**
     * Resolve a pause group to a list of unique game IDs.
     * Combines category-based and individual game membership.
     */
    public static function resolveGroupGames(int $groupId): array {
        $gameIds = [];

        // Get categories linked to this group
        $categories = DB::query(
            'SELECT category_id FROM pause_group_categories WHERE pause_group_id = :p0',
            [$groupId]
        );
        $catIds = array_column($categories, 'category_id');

        // Find games belonging to those categories from cache
        if (!empty($catIds)) {
            $catIdSet = array_flip(array_map('intval', $catIds));
            $allCached = DB::query('SELECT game_id, categories FROM game_state_cache');
            foreach ($allCached as $row) {
                $gameCats = json_decode($row['categories'], true) ?: [];
                foreach ($gameCats as $gc) {
                    if (isset($catIdSet[(int)$gc])) {
                        $gameIds[$row['game_id']] = true;
                        break;
                    }
                }
            }
        }

        // Get individually linked games
        $individualGames = DB::query(
            'SELECT game_id FROM pause_group_games WHERE pause_group_id = :p0',
            [$groupId]
        );
        foreach ($individualGames as $row) {
            $gameIds[$row['game_id']] = true;
        }

        return array_keys($gameIds);
    }

    /**
     * Resolve a pause group to a list of unique kiosk IDs.
     * Membership is by individual kiosk only (no category-based assignment).
     */
    public static function resolveGroupKiosks(int $groupId): array {
        $rows = DB::query(
            'SELECT kiosk_id FROM pause_group_kiosks WHERE pause_group_id = :p0',
            [$groupId]
        );
        $ids = [];
        foreach ($rows as $row) {
            $ids[$row['kiosk_id']] = true;
        }
        return array_keys($ids);
    }

    /**
     * Sync game states from CenterEdge to cache.
     */
    public static function syncGameStates(): int {
        $client = new CenterEdgeClient();
        return $client->syncGamesToCache();
    }

    /**
     * Sync kiosk states from CenterEdge to cache.
     */
    public static function syncKioskStates(): int {
        $client = new CenterEdgeClient();
        return $client->syncKiosksToCache();
    }

    /**
     * Sync game states only if the cache is older than $maxAgeSeconds.
     * Returns the number of games synced, or 0 if the cache is still fresh.
     */
    public static function syncGameStatesIfStale(int $maxAgeSeconds = 120): int {
        $oldest = DB::queryOne('SELECT MIN(last_synced_at) as oldest FROM game_state_cache');
        if ($oldest && $oldest['oldest']) {
            $ts = strtotime($oldest['oldest'] . ' UTC');
            if ($ts === false) {
                error_log('syncGameStatesIfStale: invalid timestamp in cache: ' . $oldest['oldest']);
            } else {
                $age = time() - $ts;
                if ($age < $maxAgeSeconds) {
                    return 0; // Cache is fresh enough
                }
            }
        }
        return self::syncGameStates();
    }

    /**
     * Sync kiosk states only if the cache is older than $maxAgeSeconds.
     * Returns the number of kiosks synced, or 0 if the cache is still fresh.
     * Returns 0 (not throws) when the upstream /kiosks endpoint is unsupported,
     * so the watchdog stays alive on systems without kiosks.
     */
    public static function syncKioskStatesIfStale(int $maxAgeSeconds = 120): int {
        $oldest = DB::queryOne('SELECT MIN(last_synced_at) as oldest FROM kiosk_state_cache');
        if ($oldest && $oldest['oldest']) {
            $ts = strtotime($oldest['oldest'] . ' UTC');
            if ($ts !== false && (time() - $ts) < $maxAgeSeconds) {
                return 0;
            }
        }
        try {
            return self::syncKioskStates();
        } catch (Exception $e) {
            error_log('syncKioskStatesIfStale: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check for and execute missed actions (earlier today, not yet executed).
     *
     * Only the *latest* missed action per group is actually executed against
     * the API.  Earlier superseded actions for the same group are marked as
     * executed (status 3 = superseded) without making API calls.  This avoids
     * wasteful churn (e.g. pause then immediately unpause) and makes catch-up
     * much faster.
     */
    public static function executeMissedActions(?string $date = null): void {
        $__prevTz = self::setTimezone();
        try {

        if ($date === null) {
            $date = date('Y-m-d');
        }

        $now = new DateTime('now', new DateTimeZone(date_default_timezone_get()));
        $nowTime = $now->format('H:i');

        $missed = DB::query(
            'SELECT id, pause_group_id, action, scheduled_time FROM scheduled_actions
             WHERE scheduled_date = :p0 AND executed = 0 AND scheduled_time <= :p1
             ORDER BY scheduled_time ASC',
            [$date, $nowTime]
        );

        if (empty($missed)) {
            return;
        }

        // Determine the latest missed action per group (last one wins).
        $latestPerGroup = [];
        $superseded = [];
        foreach ($missed as $action) {
            $gid = $action['pause_group_id'];
            if (isset($latestPerGroup[$gid])) {
                // The previous "latest" is now superseded
                $superseded[] = $latestPerGroup[$gid]['id'];
            }
            $latestPerGroup[$gid] = $action;
        }

        // Mark superseded actions without executing them (status 3 = superseded)
        foreach ($superseded as $actionId) {
            DB::execute(
                'UPDATE scheduled_actions SET executed = 3, executed_at = datetime(\'now\') WHERE id = :p0',
                [$actionId]
            );
        }

        // Execute only the latest action per group
        foreach ($latestPerGroup as $action) {
            try {
                self::executeAction($action['id']);
            } catch (Exception $e) {
                error_log("Failed to execute missed action #{$action['id']}: " . $e->getMessage());
            }
        }

        } finally { self::restoreTimezone($__prevTz); }
    }

    /**
     * Enforce state for groups whose overrides expired recently.
     * This is a fast, targeted check designed to run on every API call.
     * Only queries the DB — no CenterEdge sync — so it adds minimal latency.
     * If an override expired within the last $lookbackSeconds and the group's
     * cached state doesn't match the desired state, it patches CenterEdge.
     */
    public static function enforceExpiredOverrides(int $lookbackSeconds = 300): array {
        $__prevTz = self::setTimezone();
        try {

        $now = new DateTime('now', new DateTimeZone(date_default_timezone_get()));
        $nowStr = $now->format('Y-m-d H:i');
        $nowTime = $now->format('H:i');
        $todayDow = (int)$now->format('w');

        $lookback = (clone $now)->modify("-{$lookbackSeconds} seconds")->format('Y-m-d H:i');

        // Find overrides that expired recently (end_datetime is in the past but within lookback)
        $expired = DB::query(
            'SELECT DISTINCT pause_group_id FROM schedule_overrides
             WHERE end_datetime <= :p0 AND end_datetime > :p1',
            [$nowStr, $lookback]
        );

        if (empty($expired)) {
            return ['groups_checked' => 0];
        }

        $summary = ['groups_checked' => 0, 'groups_enforced' => 0];

        foreach ($expired as $row) {
            $groupId = (int)$row['pause_group_id'];
            $summary['groups_checked']++;

            // Check if group is active and whether manual override is set
            $group = DB::queryOne(
                'SELECT id, manual_override_action FROM pause_groups WHERE id = :p0 AND is_active = 1',
                [$groupId]
            );
            if (!$group) continue;

            // If a manual override is active, skip — operator intent wins
            if ($group['manual_override_action'] !== null) {
                continue;
            }

            // Determine desired state (same logic as enforceGroupState)
            $activeOverride = DB::queryOne(
                'SELECT action FROM schedule_overrides
                 WHERE pause_group_id = :p0 AND start_datetime <= :p1 AND end_datetime > :p1
                 ORDER BY start_datetime DESC, id DESC LIMIT 1',
                [$groupId, $nowStr]
            );

            $desiredAction = 'pause';
            $source = 'expired_override';

            if ($activeOverride) {
                $desiredAction = $activeOverride['action'];
            } else {
                $activeSchedule = DB::queryOne(
                    'SELECT id FROM schedules
                     WHERE pause_group_id = :p0 AND day_of_week = :p1 AND is_active = 1
                       AND start_time <= :p2 AND end_time > :p2
                     LIMIT 1',
                    [$groupId, $todayDow, $nowTime]
                );
                if ($activeSchedule) {
                    $desiredAction = 'unpause';
                }
            }

            $desiredStatus = ($desiredAction === 'pause') ? 'paused' : 'enabled';

            // Quick check: are any games OR kiosks in the wrong state?
            // Cache-only — no CenterEdge calls — so this stays cheap.
            $gameIds = self::resolveGroupGames($groupId);
            $needsEnforcement = false;
            foreach ($gameIds as $gameId) {
                $cached = DB::queryOne(
                    'SELECT operation_status FROM game_state_cache WHERE game_id = :p0',
                    [$gameId]
                );
                if ($cached && $cached['operation_status'] !== $desiredStatus
                    && $cached['operation_status'] !== 'outOfService') {
                    $needsEnforcement = true;
                    break;
                }
            }
            if (!$needsEnforcement) {
                $kioskIds = self::resolveGroupKiosks($groupId);
                foreach ($kioskIds as $kid) {
                    $cached = DB::queryOne(
                        'SELECT operation_status FROM kiosk_state_cache WHERE kiosk_id = :p0',
                        [$kid]
                    );
                    if ($cached
                        && $cached['operation_status'] !== ''
                        && $cached['operation_status'] !== $desiredStatus
                        && $cached['operation_status'] !== 'outOfService') {
                        $needsEnforcement = true;
                        break;
                    }
                }
            }

            if ($needsEnforcement) {
                try {
                    self::executeStateChange($groupId, $desiredStatus, $source, true);
                    $summary['groups_enforced']++;
                } catch (Exception $e) {
                    error_log("enforceExpiredOverrides: failed for group #$groupId: " . $e->getMessage());
                }
            }
        }

        return $summary;

        } finally { self::restoreTimezone($__prevTz); }
    }

    // -----------------------------------------------
    // At Job Management
    // -----------------------------------------------

    /**
     * Detect whether system `at` scheduling is available.
     */
    private static function hasAtScheduler(): bool {
        static $hasAt = null;
        if ($hasAt !== null) {
            return $hasAt;
        }

        $at = [];
        $atrm = [];
        $atCode = 1;
        $atrmCode = 1;
        exec('command -v at 2>/dev/null', $at, $atCode);
        exec('command -v atrm 2>/dev/null', $atrm, $atrmCode);

        $hasAt = ($atCode === 0 && !empty($at) && $atrmCode === 0 && !empty($atrm));
        return $hasAt;
    }


    /**
     * Parse at job ID from at command output.
     * at outputs: "job 42 at Mon Feb 24 09:00:00 2026"
     */
    private static function parseAtJobId(string $output): ?string {
        if (preg_match('/job\s+(\d+)\s+at/', $output, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Cancel an at job by ID.
     */
    private static function cancelAtJob(string $jobId): bool {
        $output = [];
        exec('atrm ' . escapeshellarg($jobId) . ' 2>&1', $output, $exitCode);
        return $exitCode === 0;
    }

    // -----------------------------------------------
    // Data Maintenance
    // -----------------------------------------------

    /**
     * Purge old data to prevent unbounded database growth.
     * Called by the daily cron job. Keeps 90 days of action_log,
     * 30 days of executed scheduled_actions, and removes expired overrides
     * older than 90 days.
     */
    public static function purgeOldData(int $logRetentionDays = 90, int $actionRetentionDays = 30, int $overrideRetentionDays = 90): array {
        $summary = [];

        // Purge old action_log entries
        $cutoff = date('Y-m-d H:i:s', strtotime("-$logRetentionDays days"));
        $deleted = DB::execute(
            'DELETE FROM action_log WHERE timestamp < :p0',
            [$cutoff]
        );
        $summary['action_log_purged'] = $deleted;

        // Purge old executed scheduled_actions (keep pending ones regardless of age)
        $cutoff = date('Y-m-d', strtotime("-$actionRetentionDays days"));
        $deleted = DB::execute(
            'DELETE FROM scheduled_actions WHERE scheduled_date < :p0 AND executed != 0',
            [$cutoff]
        );
        $summary['scheduled_actions_purged'] = $deleted;

        // Purge very old expired overrides
        $cutoff = date('Y-m-d H:i', strtotime("-$overrideRetentionDays days"));
        $deleted = DB::execute(
            'DELETE FROM schedule_overrides WHERE end_datetime < :p0',
            [$cutoff]
        );
        $summary['overrides_purged'] = $deleted;

        return $summary;
    }

    /**
     * Write a heartbeat file so external monitoring can detect if cron is alive.
     * The file contains the last successful run timestamp in ISO 8601.
     */
    public static function writeHeartbeat(string $type = 'cron'): void {
        $heartbeatFile = dirname(LOCK_FILE) . "/.heartbeat_$type";
        $result = @file_put_contents($heartbeatFile, date('c'));
        if ($result === false) {
            error_log("Failed to write heartbeat file: $heartbeatFile");
        }
    }

    // -----------------------------------------------
    // Logging
    // -----------------------------------------------

    /**
     * Log an action to the action_log table.
     */
    private static function logAction(
        string $source,
        string $action,
        ?int $groupId,
        string $gameId,
        string $gameName,
        bool $success,
        ?string $errorMessage = null,
        ?array $details = null
    ): void {
        try {
            DB::execute(
                'INSERT INTO action_log (source, action, pause_group_id, game_id, game_name, success, error_message, details)
                 VALUES (:p0, :p1, :p2, :p3, :p4, :p5, :p6, :p7)',
                [
                    $source,
                    $action,
                    $groupId,
                    $gameId,
                    $gameName,
                    $success ? 1 : 0,
                    $errorMessage,
                    $details ? json_encode($details) : null,
                ]
            );
        } catch (Exception $e) {
            // Fall back to PHP error log if DB logging fails (disk full, locked, etc.)
            error_log("logAction failed ($source/$action group=$groupId game=$gameId): " . $e->getMessage());
        }
    }
}
