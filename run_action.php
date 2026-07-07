<?php
/**
 * Execute a single scheduled action. Called by `at` at the scheduled time.
 *
 * Usage: php run_action.php --id <action_id>
 */

// CLI-only guard
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/crypto.php';
require_once __DIR__ . '/lib/centeredge_client.php';
require_once __DIR__ . '/lib/scheduler.php';

// Parse --id argument
$opts = getopt('', ['id:']);
if (!isset($opts['id']) || !is_numeric($opts['id'])) {
    echo "Usage: php run_action.php --id <action_id>\n";
    exit(1);
}
$actionId = (int)$opts['id'];

// Ensure data directory exists
$dataDir = dirname(DB_PATH);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0770, true);
}

// Acquire the global scheduler lock (blocking with a 60s timeout — an `at`
// job may legitimately need to wait out a watchdog action phase). Uses the
// shared re-entrant helper so nested Scheduler calls don't deadlock.
if (!Scheduler::acquireLock(60)) {
    echo "[" . date('c') . "] Could not acquire scheduler lock after 60 seconds. Exiting.\n";
    exit(1);
}

try {
    // Load timezone
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    date_default_timezone_set($tz);

    echo "[" . date('c') . "] Executing action #$actionId\n";

    // Execute the action
    $result = Scheduler::executeAction($actionId);

    $changed = count($result['changed'] ?? []);
    $skipped = count($result['skipped'] ?? []);
    $errors = count($result['errors'] ?? []);
    echo "  Changed: $changed, Skipped: $skipped, Errors: $errors\n";

    // Check for any missed earlier actions
    Scheduler::executeMissedActions();

    echo "[" . date('c') . "] Action #$actionId complete.\n";

} catch (Exception $e) {
    $msg = "[" . date('c') . "] ERROR executing action #$actionId: " . $e->getMessage() . "\n";
    echo $msg;
    error_log($msg);

    // Mark action as failed
    try {
        DB::execute(
            'UPDATE scheduled_actions SET executed = 2, executed_at = datetime(\'now\') WHERE id = :p0',
            [$actionId]
        );
        DB::execute(
            'INSERT INTO action_log (source, action, success, error_message) VALUES (:p0, :p1, :p2, :p3)',
            ['cron', 'execute_action', 0, "Action #$actionId: " . $e->getMessage()]
        );
    } catch (Exception $logE) {
        echo "Failed to log error: " . $logE->getMessage() . "\n";
    }

    exit(1);
} finally {
    Scheduler::releaseLock();
}
