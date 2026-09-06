<?php
/**
 * Print the timezone the APP runs on, and nothing else.
 *
 * Exists for the deploy scripts. systemd fires `OnCalendar` on the SYSTEM
 * clock, which at this venue is UTC while the app runs America/New_York — so a
 * timer written as "00:05" actually fires at 20:05 the PREVIOUS local evening,
 * and every nightly job then computes its dates against a day that has not
 * happened yet. deploy/write-daily-unit.sh asks this script which zone the
 * operator's times are meant in, exactly as the two Slack bots' installers ask
 * their runners `--print-timezone`.
 *
 * Resolution matches every other entry point: the stored `timezone` setting,
 * else DEFAULT_TIMEZONE. Prints a bare zone name with no trailing newline noise
 * the callers have to strip, and falls back to DEFAULT_TIMEZONE rather than
 * failing — a deploy must never break because the database was briefly
 * unreadable.
 *
 *     php print_timezone.php        # -> America/New_York
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/config.php';

$tz = DEFAULT_TIMEZONE;
try {
    require_once __DIR__ . '/lib/db.php';
    require_once __DIR__ . '/lib/crypto.php';
    $stored = DB::getConfig('timezone');
    if (is_string($stored) && $stored !== '') {
        // Validate before printing: a junk value would produce a timer systemd
        // silently refuses to load, which is the failure this whole mechanism
        // exists to prevent.
        try {
            new DateTimeZone($stored);
            $tz = $stored;
        } catch (Exception $e) {
            // keep the default
        }
    }
} catch (Exception $e) {
    // keep the default
}

echo $tz;
