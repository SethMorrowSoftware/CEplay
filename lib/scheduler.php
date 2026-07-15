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

    // -----------------------------------------------
    // Global scheduler lock (re-entrant within a process)
    // -----------------------------------------------
    //
    // One lock serializes everything that mutates scheduling state or pushes
    // state changes to CenterEdge: cron.php, cron_watchdog.php,
    // run_action.php, AND the web entry points (manual pause/unpause,
    // override execution, the per-request enforcement safety nets).
    //
    // Re-entrancy matters: flock() locks are per open-file-description, so a
    // second fopen()+flock() in the SAME process blocks against the first —
    // the old replanToday() implementation assumed the opposite and would
    // silently skip replanning when called from a lock-holding script. The
    // static depth counter below makes nested acquire/release pairs safe.

    /** @var resource|null */
    private static $lockFh = null;
    /** @var int Nesting depth of acquireLock() in this process. */
    private static $lockDepth = 0;

    /**
     * Acquire the global scheduler lock, waiting up to $timeoutSeconds
     * (0 = single non-blocking attempt). Re-entrant: if this process already
     * holds the lock, the depth counter is bumped and true is returned
     * immediately. Every successful acquireLock() MUST be paired with a
     * releaseLock() (use try/finally).
     */
    public static function acquireLock(int $timeoutSeconds = 15): bool {
        if (self::$lockDepth > 0) {
            self::$lockDepth++;
            return true;
        }
        $fh = fopen(LOCK_FILE, 'c');
        if (!$fh) {
            error_log('Scheduler::acquireLock: could not open lock file ' . LOCK_FILE);
            return false;
        }
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                self::$lockFh = $fh;
                self::$lockDepth = 1;
                return true;
            }
            if ($timeoutSeconds <= 0) {
                break;
            }
            usleep(200000); // 200ms between attempts
        } while (microtime(true) < $deadline);
        fclose($fh);
        return false;
    }

    /**
     * Release one level of the global scheduler lock. The file lock is only
     * dropped when the outermost acquire is released.
     */
    public static function releaseLock(): void {
        if (self::$lockDepth === 0) {
            return;
        }
        self::$lockDepth--;
        if (self::$lockDepth === 0 && self::$lockFh) {
            flock(self::$lockFh, LOCK_UN);
            fclose(self::$lockFh);
            self::$lockFh = null;
        }
    }

    /**
     * Replan today: clear pending actions, recompute, requeue.
     * Serialized under the global scheduler lock; safe to call from contexts
     * that already hold it (the lock is re-entrant per process).
     */
    public static function replanToday(): void {
        $__prevTz = self::setTimezone();
        try {
        $today = date('Y-m-d');

        if (!self::acquireLock(30)) {
            error_log('replanToday: could not acquire scheduler lock after 30s, skipping to avoid race condition');
            return;
        }
        try {
            self::clearPendingActions($today);
            self::planDay($today);
            self::queueAtJobs($today);
        } finally {
            self::releaseLock();
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

        // Sync game states only if the cache is stale (older than 5 minutes).
        // The cache is also kept warm by every patchGames/patchKiosks call
        // (see executeStateChange), so a 5-minute drift-detection window is
        // plenty to catch external state changes while massively cutting
        // routine "just-in-case" full-list pulls.
        try {
            self::syncGameStatesIfStale(300);
        } catch (Exception $e) {
            error_log('Watchdog sync failed: ' . $e->getMessage());
        }
        // Same throttled freshness check for kiosks. Non-fatal if /kiosks
        // isn't supported (helper handles the exception internally).
        self::syncKioskStatesIfStale(300);

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

        // Mark action as executed. A batch that produced ANY per-game error is
        // a partial failure and must be recorded as status 2 (not fully
        // successful) so the watchdog's missed-action sweep and the operator
        // can see the group didn't fully transition — the failed games already
        // have retries queued by executeStateChange. Only an entirely
        // error-free run (whether it changed games or found them already in
        // the desired state) counts as success (status 1).
        $allSuccess = empty($results['errors']);
        DB::execute(
            'UPDATE scheduled_actions SET executed = :p0, executed_at = datetime(\'now\') WHERE id = :p1',
            [$allSuccess ? 1 : 2, $actionId]
        );

        return $results;
    }

    /**
     * Execute an immediate action (for overrides and manual actions).
     *
     * Serialized under the global scheduler lock so a button press can't
     * interleave with the watchdog's enforcement pass (both PATCH CenterEdge
     * and write the state cache). The watchdog only holds the lock for its
     * action phase (a few seconds), so the 10s wait below is almost always
     * instant; if something is genuinely wedged we fail with a clear,
     * retryable message instead of racing.
     */
    public static function executeImmediate(int $groupId, string $action, string $source = 'manual'): array {
        if (!self::acquireLock(10)) {
            throw new RuntimeException(
                'The scheduler is busy applying another change. Please try again in a few seconds.'
            );
        }
        try {
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
        } finally {
            self::releaseLock();
        }
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

            // Sync fresh states for both games and kiosks before deciding
            // what to patch. Use the staleness-aware helpers (60s TTL): if
            // the watchdog already refreshed the cache moments ago, we skip
            // a full re-pagination of every game/kiosk. Kiosk path is
            // non-fatal — the helper already swallows /kiosks 404s for
            // installs that don't expose the endpoint.
            if ($syncCache) {
                self::syncGameStatesIfStale(60);
                self::syncKioskStatesIfStale(60);
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
     * Recompute the permanent per-game daily rollup (game_daily_stats) from the
     * raw play feed for the most recent local days, then UPSERT it.
     *
     * This MUST run before purgeOldData() each night: the raw feed is only kept
     * for a short rolling window, so the rollup is the only place month/year
     * reporting history survives. We recompute a trailing window (default 28
     * days) rather than just "yesterday" so that late-arriving transactions
     * (e.g. after an outage/backlog catch-up) and same-day corrections are
     * reconciled. The recompute window is kept safely smaller than the raw
     * retention window so every recomputed day is fully present in the raw feed
     * (never half-purged, which would undercount).
     *
     * Bucketing is done in PHP using the venue timezone so a day boundary
     * matches the wall clock, regardless of whether transaction_time carries a
     * "Z" suffix or an explicit offset — the same approach api/analytics.php
     * uses. "Today" is intentionally included so an operator viewing the
     * reporting page mid-day sees fresh numbers without waiting for cron; the
     * report endpoints treat today as live and never double-count it.
     *
     * The same pass also recomputes the per-game HOURLY rollup
     * (game_hourly_stats) for the same authoritative dates — hour-of-day is
     * lost from game_daily_stats, and the Reader Groups staffing heatmaps
     * (day-of-week × hour) need it to survive past raw retention. One raw
     * scan feeds both tables inside one transaction, so they can never
     * disagree about a day.
     *
     * @param int $recomputeDays How many trailing local days to recompute.
     * @return array{days_recomputed:int, rows_written:int, hourly_rows_written:int}
     */
    public static function rollupDailyStats(int $recomputeDays = 28): array {
        $tzName = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Exception $e) {
            $tz = new DateTimeZone('UTC');
        }
        $utc = new DateTimeZone('UTC');

        // Window: [startLocal 00:00 of the oldest recomputed day, nowLocal].
        // We pull raw rows for the whole window in one query and bucket them by
        // local calendar date in PHP.
        $recomputeDays = max(1, min(400, $recomputeDays));
        $endLocal = new DateTime('now', $tz);
        $startLocal = (clone $endLocal)->modify('-' . ($recomputeDays - 1) . ' days')->setTime(0, 0, 0);

        $startUtc = (clone $startLocal)->setTimezone($utc);
        // Pad the upper bound by a day so an offset never clips the newest rows.
        $endUtc = (clone $endLocal)->setTimezone($utc)->modify('+1 day');
        $startIso = $startUtc->format('Y-m-d\TH:i:s\Z');
        $endIso   = $endUtc->format('Y-m-d\TH:i:s\Z');

        // Set of local dates we are authoritative for this run. Any existing
        // rollup row for one of these dates is replaced from scratch so a
        // re-run is idempotent and self-correcting.
        $targetDates = [];
        $cursor = (clone $startLocal);
        $todayStr = $endLocal->format('Y-m-d');
        while ($cursor->format('Y-m-d') <= $todayStr) {
            $targetDates[$cursor->format('Y-m-d')] = true;
            $cursor->modify('+1 day');
        }

        $rows = DB::query(
            'SELECT transaction_time, game_id, game_description, card_number,
                    redemption_tickets, (cash_amount + credit_card_amount) AS cash_amount,
                    regular_points, bonus_points, used_time_play
             FROM game_play_transactions
             WHERE transaction_time >= :p0 AND transaction_time < :p1',
            [$startIso, $endIso]
        );

        // Aggregate into [date][game_id] => totals, tracking distinct cards per
        // (date, game) with a set so unique_cards is exact for that day. The
        // hourly aggregate rides along keyed by (date, hour, game).
        $agg = [];
        $aggHourly = [];
        $names = [];
        $cardSets = [];
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
            if (!isset($targetDates[$date])) continue; // outside authoritative window
            $gid = (string)($r['game_id'] ?? '');
            if ($gid === '') continue;

            $key = $date . "\0" . $gid;
            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'date' => $date, 'game_id' => $gid,
                    'plays' => 0, 'tickets' => 0.0, 'cash' => 0.0,
                    'regular_points' => 0.0, 'bonus_points' => 0.0,
                    'time_plays' => 0,
                ];
                $cardSets[$key] = [];
            }
            $agg[$key]['plays']          += 1;
            $agg[$key]['tickets']        += (float)($r['redemption_tickets'] ?? 0);
            $agg[$key]['cash']           += (float)($r['cash_amount'] ?? 0);
            $agg[$key]['regular_points'] += (float)($r['regular_points'] ?? 0);
            $agg[$key]['bonus_points']   += (float)($r['bonus_points'] ?? 0);
            $agg[$key]['time_plays']     += ((int)($r['used_time_play'] ?? 0)) ? 1 : 0;

            $hour = (int)$d->format('G');
            $hKey = $date . "\0" . $hour . "\0" . $gid;
            if (!isset($aggHourly[$hKey])) {
                $aggHourly[$hKey] = [
                    'date' => $date, 'hour' => $hour, 'game_id' => $gid,
                    'plays' => 0, 'tickets' => 0.0, 'cash' => 0.0, 'time_plays' => 0,
                ];
            }
            $aggHourly[$hKey]['plays']      += 1;
            $aggHourly[$hKey]['tickets']    += (float)($r['redemption_tickets'] ?? 0);
            $aggHourly[$hKey]['cash']       += (float)($r['cash_amount'] ?? 0);
            $aggHourly[$hKey]['time_plays'] += ((int)($r['used_time_play'] ?? 0)) ? 1 : 0;

            $card = (string)($r['card_number'] ?? '');
            if ($card !== '' && $card !== '000000') {
                $cardSets[$key][$card] = true;
            }
            $desc = trim((string)($r['game_description'] ?? ''));
            if ($desc !== '') {
                $names[$gid] = $desc;
            }
        }

        // Fall back to the game cache for names the feed didn't carry.
        $cacheNames = [];
        foreach (DB::query('SELECT game_id, game_name FROM game_state_cache') as $c) {
            $cacheNames[(string)$c['game_id']] = (string)$c['game_name'];
        }

        $db = DB::getInstance();
        $db->exec('BEGIN');
        try {
            // Delete-then-insert the authoritative dates so games that dropped
            // to zero plays for a recomputed day don't leave stale rows behind.
            foreach (array_keys($targetDates) as $date) {
                DB::execute('DELETE FROM game_daily_stats WHERE stat_date = :p0', [$date]);
                DB::execute('DELETE FROM game_hourly_stats WHERE stat_date = :p0', [$date]);
            }
            $written = 0;
            foreach ($agg as $key => $a) {
                $gid = $a['game_id'];
                $name = $names[$gid] ?? ($cacheNames[$gid] ?? '');
                DB::execute(
                    'INSERT INTO game_daily_stats
                        (stat_date, game_id, game_name, plays, tickets, cash,
                         regular_points, bonus_points, unique_cards, time_plays, updated_at)
                     VALUES (:p0, :p1, :p2, :p3, :p4, :p5, :p6, :p7, :p8, :p9, datetime(\'now\'))',
                    [
                        $a['date'], $gid, $name, (int)$a['plays'],
                        (float)$a['tickets'], (float)$a['cash'],
                        (float)$a['regular_points'], (float)$a['bonus_points'],
                        count($cardSets[$key] ?? []),
                        (int)$a['time_plays'],
                    ]
                );
                $written++;
            }
            $writtenHourly = 0;
            foreach ($aggHourly as $h) {
                DB::execute(
                    'INSERT INTO game_hourly_stats
                        (stat_date, hour, game_id, plays, tickets, cash, time_plays, updated_at)
                     VALUES (:p0, :p1, :p2, :p3, :p4, :p5, :p6, datetime(\'now\'))',
                    [
                        $h['date'], (int)$h['hour'], $h['game_id'], (int)$h['plays'],
                        (float)$h['tickets'], (float)$h['cash'], (int)$h['time_plays'],
                    ]
                );
                $writtenHourly++;
            }
            $db->exec('COMMIT');
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }

        return [
            'days_recomputed'     => count($targetDates),
            'rows_written'        => $written,
            'hourly_rows_written' => $writtenHourly,
        ];
    }

    /**
     * Refresh the durable per-card activity ledger (card_activity) from the
     * raw play feed. Powers the Guest Insights "new vs returning" split, which
     * the 30-day raw feed alone cannot answer (a card seen today might be a
     * guest whose first visit was months ago, long since purged).
     *
     * Runs nightly alongside rollupDailyStats(), BEFORE purgeOldData(). Both
     * ledger columns are monotonic — first_seen_date only ever moves earlier
     * (MIN), last_seen_date only later (MAX) — so re-scanning the raw window
     * every night is idempotent and self-correcting: a card whose first visit
     * has already scrolled out of the raw window keeps the earlier
     * first_seen_date recorded on an earlier run. Because daily cron always
     * folds a card's first day while it is still inside the raw window, the
     * first-seen date is captured correctly without any cursor state.
     *
     * Credit-card / cardless plays (card_number "000000") and blank card
     * numbers are excluded — they are not guests.
     */
    public static function rollupCardActivity(): array {
        $tzName = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Exception $e) {
            $tz = new DateTimeZone('UTC');
        }

        // Per-card min/max local calendar date across the whole raw feed
        // (already bounded to ~30 days by the purge). Bucketed in PHP so the
        // date math uses the venue timezone, matching the rest of reporting.
        $seen = [];
        foreach (DB::query(
            'SELECT card_number, transaction_time
             FROM game_play_transactions
             WHERE card_number != \'\' AND card_number != \'000000\''
        ) as $r) {
            $tt = (string)($r['transaction_time'] ?? '');
            if ($tt === '') continue;
            try {
                $d = new DateTime($tt);
            } catch (Exception $e) {
                continue;
            }
            $d->setTimezone($tz);
            $date = $d->format('Y-m-d');
            $card = (string)$r['card_number'];
            if (!isset($seen[$card])) {
                $seen[$card] = ['first' => $date, 'last' => $date];
            } else {
                if ($date < $seen[$card]['first']) $seen[$card]['first'] = $date;
                if ($date > $seen[$card]['last'])  $seen[$card]['last']  = $date;
            }
        }

        if (empty($seen)) {
            return ['cards_seen' => 0, 'rows_written' => 0];
        }

        $db = DB::getInstance();
        $db->exec('BEGIN');
        try {
            $written = 0;
            foreach ($seen as $card => $range) {
                // Monotonic upsert: never widen inward. min()/max() are SQLite
                // scalar functions; on the date strings (YYYY-MM-DD) string
                // order is chronological order.
                DB::execute(
                    'INSERT INTO card_activity (card_number, first_seen_date, last_seen_date, updated_at)
                     VALUES (:p0, :p1, :p2, datetime(\'now\'))
                     ON CONFLICT(card_number) DO UPDATE SET
                        first_seen_date = min(first_seen_date, excluded.first_seen_date),
                        last_seen_date  = max(last_seen_date, excluded.last_seen_date),
                        updated_at = datetime(\'now\')',
                    [(string)$card, $range['first'], $range['last']]
                );
                $written++;
            }
            $db->exec('COMMIT');
        } catch (Exception $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }

        return ['cards_seen' => count($seen), 'rows_written' => $written];
    }

    /**
     * One-time (idempotent) historical seed of the card_activity guest ledger
     * from the CenterEdge MSSQL PlayerCardTrans table.
     *
     * rollupCardActivity() above can only see the ~30-day raw feed, so a guest
     * whose first-ever visit predates that window looks brand-new and returning
     * counts collapse near the window start. PlayerCardTrans holds every card
     * transaction back ~two decades, so MIN(TransDateTime) per card is the
     * guest's TRUE first visit. This reads that (batched by year so each GROUP
     * BY stays small) and widens the ledger through the SAME monotonic UPSERT
     * the nightly rollup uses — first-seen only moves earlier, last-seen only
     * later — so it can never narrow a range and is safe to re-run or to overlap
     * with the nightly rollup.
     *
     * Runs where MSSQL is reachable (the venue server). Returns
     * ['skipped' => true, 'reason' => ...] when the connection isn't configured
     * so the caller can leave its "done" flag unset and retry on a later run. A
     * per-year query/write failure propagates (rolled back), so a partial run
     * never sets the caller's flag — the next run simply redoes the (idempotent)
     * scan.
     *
     * @param int $fromYear earliest calendar year to scan
     * @param callable|null $log optional fn(string) for per-year progress
     * @return array{skipped?:bool,reason?:string,cards?:int,earliest?:?string,merged?:int}
     */
    public static function backfillCardActivityFromMssql(int $fromYear = 2005, ?callable $log = null): array {
        require_once __DIR__ . '/mssql_client.php';
        if (!MssqlClient::isConfigured()) {
            return ['skipped' => true, 'reason' => 'MSSQL not configured'];
        }
        $note = function (string $m) use ($log) { if ($log) $log($m); };

        if ($fromYear < 1990) $fromYear = 1990;
        $toYear = (int)date('Y');

        $client = new MssqlClient();
        $client->setTimeout(180); // one heavy GROUP BY per year over PlayerCardTrans

        // Verbatim the monotonic UPSERT rollupCardActivity() uses: on YYYY-MM-DD
        // strings SQLite's min()/max() are chronological, so first-seen only
        // moves earlier and last-seen only later — never inward.
        $upsertSql =
            'INSERT INTO card_activity (card_number, first_seen_date, last_seen_date, updated_at)
             VALUES (:p0, :p1, :p2, datetime(\'now\'))
             ON CONFLICT(card_number) DO UPDATE SET
                first_seen_date = min(first_seen_date, excluded.first_seen_date),
                last_seen_date  = max(last_seen_date, excluded.last_seen_date),
                updated_at = datetime(\'now\')';

        $merged = 0;
        for ($year = $fromYear; $year <= $toYear; $year++) {
            $from = sprintf('%04d-01-01', $year);
            $to   = sprintf('%04d-01-01', $year + 1);
            // TransDateTime is the POS's own venue-local clock — the same wall
            // time the app bins guest dates by — so the date part is taken
            // directly, with no timezone conversion. Card "000000" is the
            // credit-card/cardless sentinel and blank cards are junk; both are
            // excluded, matching rollupCardActivity(). Year bounds come from an
            // integer, so the inlined literals are injection-proof (and rows()
            // re-checks via MssqlClient::assertReadOnly).
            $sql = "SELECT CardNumber,"
                 . " CONVERT(VARCHAR(10), MIN(TransDateTime), 120) AS first_date,"
                 . " CONVERT(VARCHAR(10), MAX(TransDateTime), 120) AS last_date"
                 . " FROM dbo.PlayerCardTrans"
                 . " WHERE CardNumber <> '' AND CardNumber <> '000000'"
                 . "   AND TransDateTime >= '{$from}' AND TransDateTime < '{$to}'"
                 . " GROUP BY CardNumber";
            $rows = $client->rows($sql, 5000000);
            if (!$rows) { $note("  {$year}: no card transactions."); continue; }

            $db = DB::getInstance();
            $db->exec('BEGIN');
            try {
                $n = 0;
                foreach ($rows as $r) {
                    $card  = trim((string)($r['CardNumber'] ?? ''));
                    $first = substr((string)($r['first_date'] ?? ''), 0, 10);
                    $last  = substr((string)($r['last_date'] ?? ''), 0, 10);
                    if ($card === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $first)) continue;
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $last)) $last = $first;
                    DB::execute($upsertSql, [$card, $first, $last]);
                    $n++;
                }
                $db->exec('COMMIT');
            } catch (Exception $e) {
                $db->exec('ROLLBACK');
                throw $e;
            }
            $merged += $n;
            $note("  {$year}: " . count($rows) . " distinct cards → {$n} merged.");
        }

        $summary = DB::queryOne('SELECT COUNT(*) AS c, MIN(first_seen_date) AS earliest FROM card_activity') ?: [];
        return [
            'cards'    => (int)($summary['c'] ?? 0),
            'earliest' => $summary['earliest'] ?? null,
            'merged'   => $merged,
        ];
    }

    /**
     * One-time (idempotent) historical seed of the permanent per-game rollups
     * (game_daily_stats + game_hourly_stats) from MSSQL PlayerCardTrans plays.
     *
     * rollupDailyStats() only reaches back over the ~30-day raw feed, so month/
     * year Performance history and the Reader-Groups heatmaps are empty before
     * the app started. PlayerCardTrans TransType 1 (plays) records every reader
     * swipe back ~two decades with a real TransDateTime, rdrkey, Amount (card
     * value-units) and CardNumber — enough to reconstruct PLAYS, VALUE and
     * UNIQUE-CARDS per game/day/hour.
     *
     * What it can't reconstruct (left 0, exactly as the app's own pre-feature
     * days already read): TICKETS (every ValueNo-3 credit is booked with rdrkey
     * 0 — no per-game source exists anywhere in the POS DB), and the cash /
     * bonus / time-pass splits.
     *
     * Safety rails:
     *  - rdrkey → game_id via the app's game cache + the MSSQL reader master
     *    (ReaderDevices): direct reader-number == game_id first, then a
     *    normalized name match. Unmapped readers (retired/renamed) are skipped
     *    and counted.
     *  - Only days STRICTLY BEFORE the app's own rollup coverage are written, so
     *    the richer feed-derived rows are never overwritten and nothing double-
     *    counts. Expect a small seam at that boundary (two different sources).
     *  - game_hourly_stats is purge-bounded (~400 days), so hours are only
     *    written inside that window (older hours would be purged next run).
     *  - Batched by month (small buffers) and INSERT OR REPLACE on the rollup
     *    PKs ⇒ bounded memory and safe to re-run.
     *
     * Returns ['skipped'=>true,...] when MSSQL isn't configured or no reader
     * maps to a game, so the caller can leave its "done" flag unset and retry.
     */
    public static function backfillGameStatsFromMssql(int $fromYear = 2005, ?callable $log = null): array {
        require_once __DIR__ . '/mssql_client.php';
        if (!MssqlClient::isConfigured()) {
            return ['skipped' => true, 'reason' => 'MSSQL not configured'];
        }
        $note = function (string $m) use ($log) { if ($log) $log($m); };

        $tzName = DB::getConfig('timezone') ?: DEFAULT_TIMEZONE;
        try { $tz = new DateTimeZone($tzName); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }

        // Boundary: never touch days the nightly rollup already owns (its rows
        // carry tickets/cash/time-plays this backfill can't). game_daily_stats
        // grows forward from app start, so its earliest date is the cutoff;
        // empty ⇒ backfill everything up to today.
        $row = DB::queryOne('SELECT MIN(stat_date) AS earliest FROM game_daily_stats');
        $cutoff = ($row && !empty($row['earliest']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$row['earliest']))
            ? (string)$row['earliest']
            : (new DateTime('now', $tz))->format('Y-m-d');
        // Hourly rows older than the purge horizon (~400d) get deleted next
        // cron, so only write hours from here forward.
        $hourlyFrom = (new DateTime('now', $tz))->modify('-395 days')->format('Y-m-d');

        if ($fromYear < 1990) $fromYear = 1990;
        $cutoffYear = (int)substr($cutoff, 0, 4);
        if ($fromYear > $cutoffYear) {
            return ['readers_mapped' => 0, 'readers_unmapped' => 0, 'daily_rows' => 0, 'hourly_rows' => 0, 'cutoff' => $cutoff];
        }

        // ---- rdrkey → game_id map (direct number, then normalized name) ----
        $gameName = []; $byName = [];
        foreach (DB::query('SELECT game_id, game_name FROM game_state_cache') as $g) {
            $gid = (string)$g['game_id'];
            $gameName[$gid] = (string)$g['game_name'];
            $n = self::normReaderName((string)$g['game_name']);
            if ($n !== '' && !isset($byName[$n])) $byName[$n] = $gid;
        }
        $client = new MssqlClient();
        $client->setTimeout(180);
        $readerMap = []; $unmapped = 0;
        foreach ($client->rows('SELECT rdrKey, Description FROM dbo.ReaderDevices', 100000) as $rd) {
            $rk = (int)($rd['rdrKey'] ?? 0);
            if ($rk === 0) continue;
            $rks = (string)$rk;
            if (isset($gameName[$rks])) { $readerMap[$rk] = $rks; continue; } // reader number IS the game id
            $n = self::normReaderName((string)($rd['Description'] ?? ''));
            if ($n !== '' && isset($byName[$n])) { $readerMap[$rk] = $byName[$n]; }
            else { $unmapped++; }
        }
        if (!$readerMap) {
            return ['skipped' => true, 'reason' => 'no readers mapped to games (is game_state_cache populated?)'];
        }
        $note('Mapped ' . count($readerMap) . ' readers to games (' . $unmapped . ' unmapped); backfilling days before ' . $cutoff . '.');

        $db = DB::getInstance();
        $dailyWritten = 0; $hourlyWritten = 0;

        for ($year = $fromYear; $year <= $cutoffYear; $year++) {
            for ($m = 1; $m <= 12; $m++) {
                $from  = sprintf('%04d-%02d-01', $year, $m);
                $next  = ($m === 12) ? sprintf('%04d-01-01', $year + 1) : sprintf('%04d-%02d-01', $year, $m + 1);
                $upper = ($next > $cutoff) ? $cutoff : $next; // exclusive; never past the app's coverage
                if ($from >= $upper) continue; // month entirely at/after the cutoff

                // ---- Daily: plays / value / unique-cards per reader per local day ----
                // TransDateTime is the POS's venue-local clock, so CONVERT(...,120)
                // and DATEPART(HOUR) yield the same local day/hour the app bins by.
                $dailySql = "SELECT rdrkey, CONVERT(VARCHAR(10), TransDateTime, 120) AS d,"
                    . " COUNT(*) AS plays, SUM(Amount) AS val, COUNT(DISTINCT CardNumber) AS cards"
                    . " FROM dbo.PlayerCardTrans"
                    . " WHERE TransType = 1 AND rdrkey <> 0"
                    . "   AND TransDateTime >= '{$from}' AND TransDateTime < '{$upper}'"
                    . " GROUP BY rdrkey, CONVERT(VARCHAR(10), TransDateTime, 120)";
                $dayAgg = []; // [date][game_id] => [plays,val,cards]
                foreach ($client->rows($dailySql, 5000000) as $r) {
                    $rk = (int)($r['rdrkey'] ?? 0);
                    if (!isset($readerMap[$rk])) continue;
                    $gid = $readerMap[$rk];
                    $d = substr((string)($r['d'] ?? ''), 0, 10);
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d >= $cutoff) continue;
                    if (!isset($dayAgg[$d][$gid])) $dayAgg[$d][$gid] = ['plays' => 0, 'val' => 0.0, 'cards' => 0];
                    $dayAgg[$d][$gid]['plays'] += (int)($r['plays'] ?? 0);
                    $dayAgg[$d][$gid]['val']   += (float)($r['val'] ?? 0);
                    // Summed across a game's readers (rare — most games are one
                    // reader); slightly over-counts unique cards only when one
                    // card used two readers of the same game on the same day.
                    $dayAgg[$d][$gid]['cards'] += (int)($r['cards'] ?? 0);
                }
                if ($dayAgg) {
                    $db->exec('BEGIN');
                    try {
                        foreach ($dayAgg as $d => $byGid) {
                            foreach ($byGid as $gid => $a) {
                                DB::execute(
                                    'INSERT OR REPLACE INTO game_daily_stats
                                        (stat_date, game_id, game_name, plays, tickets, cash, regular_points, bonus_points, unique_cards, time_plays, updated_at)
                                     VALUES (:p0, :p1, :p2, :p3, 0, 0, :p4, 0, :p5, 0, datetime(\'now\'))',
                                    [$d, (string)$gid, (string)($gameName[$gid] ?? ''), $a['plays'], $a['val'], $a['cards']]
                                );
                                $dailyWritten++;
                            }
                        }
                        $db->exec('COMMIT');
                    } catch (Exception $e) { $db->exec('ROLLBACK'); throw $e; }
                }

                // ---- Hourly: plays per reader per local day+hour (retention window only) ----
                $hFrom = ($from < $hourlyFrom) ? $hourlyFrom : $from;
                if ($hFrom < $upper) {
                    $hourlySql = "SELECT rdrkey, CONVERT(VARCHAR(10), TransDateTime, 120) AS d, DATEPART(HOUR, TransDateTime) AS h,"
                        . " COUNT(*) AS plays"
                        . " FROM dbo.PlayerCardTrans"
                        . " WHERE TransType = 1 AND rdrkey <> 0"
                        . "   AND TransDateTime >= '{$hFrom}' AND TransDateTime < '{$upper}'"
                        . " GROUP BY rdrkey, CONVERT(VARCHAR(10), TransDateTime, 120), DATEPART(HOUR, TransDateTime)";
                    $hourAgg = []; // [date][hour][game_id] => plays
                    foreach ($client->rows($hourlySql, 5000000) as $r) {
                        $rk = (int)($r['rdrkey'] ?? 0);
                        if (!isset($readerMap[$rk])) continue;
                        $gid = $readerMap[$rk];
                        $d = substr((string)($r['d'] ?? ''), 0, 10);
                        $h = (int)($r['h'] ?? -1);
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d >= $cutoff || $h < 0 || $h > 23) continue;
                        $hourAgg[$d][$h][$gid] = ($hourAgg[$d][$h][$gid] ?? 0) + (int)($r['plays'] ?? 0);
                    }
                    if ($hourAgg) {
                        $db->exec('BEGIN');
                        try {
                            foreach ($hourAgg as $d => $byHour) {
                                foreach ($byHour as $h => $byGid) {
                                    foreach ($byGid as $gid => $plays) {
                                        DB::execute(
                                            'INSERT OR REPLACE INTO game_hourly_stats
                                                (stat_date, hour, game_id, plays, tickets, cash, time_plays, updated_at)
                                             VALUES (:p0, :p1, :p2, :p3, 0, 0, 0, datetime(\'now\'))',
                                            [$d, (int)$h, (string)$gid, $plays]
                                        );
                                        $hourlyWritten++;
                                    }
                                }
                            }
                            $db->exec('COMMIT');
                        } catch (Exception $e) { $db->exec('ROLLBACK'); throw $e; }
                    }
                }
            }
            $note("  {$year}: {$dailyWritten} game-day rows, {$hourlyWritten} game-hour rows (cumulative).");
        }

        return [
            'readers_mapped'   => count($readerMap),
            'readers_unmapped' => $unmapped,
            'daily_rows'       => $dailyWritten,
            'hourly_rows'      => $hourlyWritten,
            'cutoff'           => $cutoff,
        ];
    }

    /**
     * Normalize a reader/game name for cross-source matching (app game cache ↔
     * MSSQL ReaderDevices.Description). Lower-cases, strips a leading sort-order
     * digit prefix (e.g. "1Batting Cage 1"), and collapses whitespace.
     */
    private static function normReaderName(string $s): string {
        $s = function_exists('mb_strtolower') ? mb_strtolower(trim($s)) : strtolower(trim($s));
        $s = preg_replace('/^\d+\s*/', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim((string)$s);
    }

    /**
     * Purge old data to prevent unbounded database growth.
     * Called by the daily cron job. Keeps 90 days of action_log,
     * 30 days of executed scheduled_actions, and removes expired overrides
     * older than 90 days.
     *
     * The raw play feed (game_play_transactions) is kept for 30 days — long
     * enough for the live feed, per-game drill-downs, and hourly reporting,
     * while the permanent per-game daily rollup (game_daily_stats, written by
     * rollupDailyStats() which MUST run first) preserves month/year history
     * indefinitely. The 30-day raw window is deliberately larger than the
     * rollup recompute window so no day is ever purged before it is rolled up.
     */
    public static function purgeOldData(int $logRetentionDays = 90, int $actionRetentionDays = 30, int $overrideRetentionDays = 90, int $playFeedRetentionDays = 30): array {
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

        // Purge old game-play transactions. We only need a recent rolling
        // window for the live feed and top-games widget; longer-term reporting
        // is owned by CenterEdge itself. Cutoff must match the canonical UTC
        // "Z" format transaction_time is stored in — the comparison is lexical.
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', strtotime("-$playFeedRetentionDays days"));
        $deleted = DB::execute(
            'DELETE FROM game_play_transactions WHERE transaction_time < :p0',
            [$cutoff]
        );
        $summary['game_plays_purged'] = $deleted;

        // Purge very old system transactions (merges/expirations). Low
        // volume, but breakage reporting wants deep history, so keep 400
        // days — enough for full year-over-year comparisons.
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', strtotime('-400 days'));
        $deleted = DB::execute(
            'DELETE FROM system_transactions WHERE transaction_time < :p0',
            [$cutoff]
        );
        $summary['system_tx_purged'] = $deleted;

        // Trim the hourly rollup past 400 days. Day-of-week × hour staffing
        // heatmaps only need enough depth for year-over-year comparison;
        // game_daily_stats (kept forever) still answers anything older at
        // day grain. stat_date is a local YYYY-MM-DD string — lexical
        // comparison is chronological.
        $cutoff = date('Y-m-d', strtotime('-400 days'));
        $deleted = DB::execute(
            'DELETE FROM game_hourly_stats WHERE stat_date < :p0',
            [$cutoff]
        );
        $summary['hourly_stats_purged'] = $deleted;

        return $summary;
    }

    /**
     * Nightly on-disk database backup. game_daily_stats is irreplaceable
     * history (CenterEdge has no reporting API), and until now the only
     * snapshot happened when the operator ran update.sh. VACUUM INTO reads
     * a consistent view of the live WAL database — unlike a file copy, it
     * cannot miss the last commit — and writes a compact single-file copy
     * into data/backups/ (inside DATA_DIR so it's mounted, writable by the
     * app user, and gitignored). Keeps the newest $keep snapshots; the
     * timestamped names sort chronologically, so pruning is a name sort.
     *
     * Restore = stop services, copy the snapshot over data/pause_groups.db
     * (removing any -wal/-shm files), restart.
     *
     * @return array{path:string, bytes:int, kept:int, pruned:int}
     */
    public static function backupDatabase(int $keep = 14): array {
        $dir = dirname(DB_PATH) . '/backups';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create backup directory: $dir");
        }

        // VACUUM INTO refuses to overwrite an existing file, and two runs in
        // the same second (cron + a manual run) would collide on the
        // timestamp — suffix until the name is free.
        $base = $dir . '/nightly-' . gmdate('Ymd-His');
        $dest = $base . '.db';
        for ($n = 2; file_exists($dest); $n++) {
            $dest = $base . '-' . $n . '.db';
        }
        DB::getInstance()->exec("VACUUM INTO '" . SQLite3::escapeString($dest) . "'");
        if (!is_file($dest) || (int)filesize($dest) === 0) {
            throw new RuntimeException("Backup produced no file at $dest");
        }

        $files = glob($dir . '/nightly-*.db') ?: [];
        rsort($files);
        $pruned = 0;
        foreach (array_slice($files, max(1, $keep)) as $old) {
            if (@unlink($old)) $pruned++;
        }

        return [
            'path'   => $dest,
            'bytes'  => (int)filesize($dest),
            'kept'   => min(count($files), max(1, $keep)),
            'pruned' => $pruned,
        ];
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
