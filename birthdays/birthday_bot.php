<?php
/**
 * CEplay birthday Slack bot.
 *
 * Reads the current-employee roster out of the venue's CenterEdge MSSQL
 * database (read-only, one guarded SELECT you control in config.php) and posts
 * a birthday greeting to Slack for anyone whose birthday is today AND whose
 * employment-status column still says they work here.
 *
 * Changes nothing in the CEplay app: it borrows the app's stored MSSQL
 * connection and its read-only client, and writes only its own state file.
 *
 * Usage:
 *   php birthdays/birthday_bot.php                  post today's greeting
 *   php birthdays/birthday_bot.php --dry-run        build it, post nothing
 *   php birthdays/birthday_bot.php --date=2026-09-14  pretend it's that day
 *   php birthdays/birthday_bot.php --list           next 60 days of birthdays
 *   php birthdays/birthday_bot.php --list=14        next 14 days
 *   php birthdays/birthday_bot.php --test-slack     prove the token + channel
 *   php birthdays/birthday_bot.php --force          ignore "already posted"
 *   php birthdays/birthday_bot.php --roster-file=roster.json
 *                                                  read the roster from a JSON
 *                                                  file instead of MSSQL, for
 *                                                  testing the wording off-site
 *
 * Exit codes: 0 ok / nothing to do, 1 configuration or data problem,
 * 2 could not reach MSSQL or Slack.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/lib/db.php';
require_once $root . '/lib/crypto.php';
require_once $root . '/lib/mssql_client.php';
require_once __DIR__ . '/lib/birthday_lib.php';
require_once __DIR__ . '/lib/slack_client.php';

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

const BDAY_USAGE = <<<TXT
Usage: php birthdays/birthday_bot.php [options]

  --dry-run              Build today's message and print it; post nothing.
  --date=YYYY-MM-DD      Treat that date as today (for testing).
  --list[=DAYS]          Print upcoming birthdays (default 60 days) and exit.
  --test-slack           Check the token and post a test message; exit.
  --force                Post even if today's greeting already went out.
  --roster-file=PATH     Read the roster from a JSON file instead of MSSQL.
  --help                 Show this.

TXT;

/**
 * Known flags. An unknown one is a hard error rather than something quietly
 * ignored: a typo'd "--dryrun" that silently fell through to a REAL post is
 * exactly the failure this bot must not have.
 */
const BDAY_FLAGS = ['dry-run', 'force', 'test-slack', 'list', 'date', 'roster-file', 'help'];

$flags = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z][a-z-]*)(?:=(.*))?$/', $arg, $m)) {
        fwrite(STDERR, "Unrecognised argument: {$arg}\n\n" . BDAY_USAGE);
        exit(1);
    }
    if (!in_array($m[1], BDAY_FLAGS, true)) {
        fwrite(STDERR, "Unknown option: --{$m[1]}\n\n" . BDAY_USAGE);
        exit(1);
    }
    $flags[$m[1]] = $m[2] ?? true;
}
if (!empty($flags['help'])) {
    echo BDAY_USAGE;
    exit(0);
}
$dryRun    = !empty($flags['dry-run']);
$force     = !empty($flags['force']);
$testSlack = !empty($flags['test-slack']);
$doList    = array_key_exists('list', $flags);
$rosterFile = isset($flags['roster-file']) && is_string($flags['roster-file']) ? $flags['roster-file'] : '';
$listDays  = $doList && is_string($flags['list']) ? max(1, min(400, (int)$flags['list'])) : 60;

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR,
        "No birthdays/config.php yet.\n\n"
        . "  cp birthdays/config.example.php birthdays/config.php\n"
        . "  chmod 600 birthdays/config.php\n\n"
        . "Then fill in the Slack token, the channel ID and roster_sql.\n"
        . "Run `php birthdays/discover.php` first to find the roster table.\n");
    exit(1);
}
$cfg = require $configPath;
if (!is_array($cfg)) {
    fwrite(STDERR, "birthdays/config.php must return an array.\n");
    exit(1);
}

$tz = trim((string)($cfg['timezone'] ?? ''));
if ($tz === '' && defined('DEFAULT_TIMEZONE')) {
    $tz = DEFAULT_TIMEZONE;
}
if ($tz !== '') {
    @date_default_timezone_set($tz);
}

$logFile = trim((string)($cfg['log_file'] ?? ''));
/** Log to stdout (the journal picks it up) and, if configured, to a file. */
function bdayLog(string $msg): void
{
    global $logFile;
    $line = '[' . date('c') . '] ' . $msg;
    echo $line . "\n";
    if ($logFile !== '') {
        @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

$today = date('Y-m-d');
$target = $today;
if (!empty($flags['date'])) {
    $d = (string)$flags['date'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || bdayParseBirthDate($d) === null) {
        fwrite(STDERR, "--date must be a real calendar date in YYYY-MM-DD form.\n");
        exit(1);
    }
    $target = $d;
}

$leapMode = (string)($cfg['leap_day_mode'] ?? 'feb28');
if (!in_array($leapMode, ['feb28', 'mar1', 'skip'], true)) {
    fwrite(STDERR, "leap_day_mode must be one of: feb28, mar1, skip.\n");
    exit(1);
}
$nameStyle = (string)($cfg['name_style'] ?? 'full');
if (!in_array($nameStyle, ['full', 'first', 'first_initial'], true)) {
    fwrite(STDERR, "name_style must be one of: full, first, first_initial.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// --test-slack: prove the token and the channel before trusting the timer
// ---------------------------------------------------------------------------

if ($testSlack) {
    try {
        $slack = new SlackClient((string)($cfg['slack_bot_token'] ?? ''));
        $who = $slack->authTest();
        bdayLog("Slack auth OK — workspace '{$who['team']}', bot '{$who['user']}'.");
        $channel = trim((string)($cfg['slack_channel'] ?? ''));
        if ($channel === '' || $channel === 'C0123456789') {
            fwrite(STDERR, "Set slack_channel to a real channel ID before testing delivery.\n");
            exit(1);
        }
        $slack->postMessage($channel, ':wave: Birthday bot test — this channel is wired up correctly.', [
            'username'   => (string)($cfg['bot_username'] ?? ''),
            'icon_emoji' => (string)($cfg['bot_icon_emoji'] ?? ''),
        ]);
        bdayLog("Test message delivered to {$channel}.");
        exit(0);
    } catch (Exception $e) {
        fwrite(STDERR, 'Slack test failed: ' . $e->getMessage() . "\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------------
// Roster
// ---------------------------------------------------------------------------

if ($rosterFile !== '') {
    // Testing aid: a JSON array of row objects shaped exactly like the SQL
    // result. Lets the message wording, the leap-day rule and the exclusion
    // list be checked on a laptop, where there is no MSSQL driver at all.
    if (!is_file($rosterFile)) {
        fwrite(STDERR, "--roster-file: no such file: {$rosterFile}\n");
        exit(1);
    }
    $decoded = json_decode((string)file_get_contents($rosterFile), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "--roster-file must contain a JSON array of row objects.\n");
        exit(1);
    }
    $rows = $decoded;
    bdayLog('Roster read from ' . $rosterFile . ' (test mode — MSSQL not used).');
} else {
    $rosterSql = trim((string)($cfg['roster_sql'] ?? ''));
    if ($rosterSql === '') {
        fwrite(STDERR, "roster_sql is empty — run `php birthdays/discover.php` to generate one.\n");
        exit(1);
    }
    if (stripos($rosterSql, 'where') === false) {
        bdayLog('WARNING: roster_sql has no WHERE clause, so it has no "still employed" filter. '
            . 'Everyone who ever worked here will be wished a happy birthday.');
    }

    try {
        MssqlClient::assertReadOnly($rosterSql);
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'roster_sql was rejected by the read-only guard: ' . $e->getMessage() . "\n");
        exit(1);
    }

    if (!MssqlClient::availableDrivers()) {
        fwrite(STDERR,
            "No MSSQL PDO driver in this PHP runtime. Run the bot inside the pdo_dblib\n"
            . "overlay image the app already uses (see birthdays/README.md).\n");
        exit(2);
    }
    if (!MssqlClient::isConfigured()) {
        fwrite(STDERR, "MSSQL is not configured in the app yet (Go-Kart Labor page -> Settings).\n");
        exit(2);
    }

    try {
        $client = new MssqlClient();
        $client->setTimeout(max(5, (int)($cfg['query_timeout'] ?? 30)));
        $rows = $client->rows($rosterSql, max(1, (int)($cfg['roster_max_rows'] ?? 5000)));
    } catch (Exception $e) {
        fwrite(STDERR, 'Could not read the roster: ' . $e->getMessage() . "\n");
        exit(2);
    }
}

$norm = bdayNormalizeRoster($rows, [
    'today'              => $today,
    'ignore_birth_dates' => (array)($cfg['ignore_birth_dates'] ?? []),
    'exclude_emp_nos'    => (array)($cfg['exclude_emp_nos'] ?? []),
    'exclude_names'      => (array)($cfg['exclude_names'] ?? []),
]);
$people  = $norm['people'];
$skipped = $norm['skipped'];

bdayLog(sprintf(
    'Roster: %d rows -> %d people with a usable birthday (skipped: %d no date, %d unreadable, '
    . '%d placeholder date, %d no name, %d opted out, %d duplicate).',
    count($rows), count($people), $skipped['no_birth_date'], $skipped['unparsed'],
    $skipped['sentinel'], $skipped['no_name'], $skipped['excluded'], $skipped['duplicate']
));

if ($rows && !$people) {
    fwrite(STDERR,
        "The roster query returned rows but none had a usable birth date.\n"
        . "Check that roster_sql selects the birthday column and aliases it `birth_date`.\n");
    exit(1);
}
if ($norm['sentinel_hits']) {
    $top = [];
    foreach (array_slice($norm['sentinel_hits'], 0, 3, true) as $date => $n) {
        $top[] = "{$date} x{$n}";
    }
    bdayLog('Ignored placeholder birth dates: ' . implode(', ', $top) . '.');
}

// ---------------------------------------------------------------------------
// --list: eyeball the data without posting anything
// ---------------------------------------------------------------------------

if ($doList) {
    $upcoming = bdayUpcoming($people, $target, $listDays, $leapMode);
    echo "\nBirthdays in the next {$listDays} days from {$target}:\n\n";
    if (!$upcoming) {
        echo "  (none)\n\n";
        exit(0);
    }
    foreach ($upcoming as $date => $hits) {
        $names = [];
        foreach ($hits as $p) {
            $label = bdayDisplayName($p, $nameStyle);
            if ($p['month'] === 2 && $p['day'] === 29 && substr($date, 5) !== '02-29') {
                $label .= ' (29 Feb)';
            }
            $names[] = $label;
        }
        printf("  %s  %s  %s\n", $date, date('D', strtotime($date . ' 12:00:00')), implode(', ', $names));
    }
    echo "\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Today's celebrants
// ---------------------------------------------------------------------------

$celebrants = bdayCelebrants($people, $target, $leapMode);
if (!$celebrants) {
    bdayLog("No birthdays on {$target}. Nothing to post.");
    exit(0);
}

$max = max(1, (int)($cfg['max_celebrants'] ?? 12));
if (count($celebrants) > $max) {
    fwrite(STDERR, sprintf(
        "Refusing to post: %d people share a birthday on %s, which is over the max_celebrants\n"
        . "limit of %d. That usually means roster_sql is reading the wrong column (a hire date,\n"
        . "say) rather than a genuine coincidence. Run `php birthdays/discover.php` to check.\n",
        count($celebrants), $target, $max
    ));
    exit(1);
}

$statePath = (string)($cfg['state_file'] ?? (__DIR__ . '/../data/birthday_state.json'));
$state = bdayStateLoad($statePath);

if (!$force) {
    $pending = [];
    foreach ($celebrants as $p) {
        if (!bdayStateHas($state, $target, $p)) {
            $pending[] = $p;
        }
    }
    if (!$pending) {
        bdayLog(sprintf('Already wished all %d birthday(s) on %s. Nothing to do (use --force to repost).',
            count($celebrants), $target));
        exit(0);
    }
    $celebrants = $pending;
}

$names = [];
foreach ($celebrants as $p) {
    $names[] = bdayDisplayName($p, $nameStyle) . ($p['emp_no'] !== '' ? " (#{$p['emp_no']})" : '');
}
bdayLog('Birthday(s) on ' . $target . ': ' . implode(', ', $names) . '.');

// ---------------------------------------------------------------------------
// Optional @-mentions
// ---------------------------------------------------------------------------

$slack = null;
if (!$dryRun || !empty($cfg['mention_by_email'])) {
    try {
        $slack = new SlackClient((string)($cfg['slack_bot_token'] ?? ''));
    } catch (RuntimeException $e) {
        if (!$dryRun) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        bdayLog('Note: ' . $e->getMessage() . ' (dry run continues without Slack.)');
    }
}

if (!empty($cfg['mention_by_email']) && $slack !== null) {
    $resolved = 0;
    foreach ($celebrants as $i => $p) {
        if ($p['email'] === '') {
            continue;
        }
        try {
            $id = $slack->lookupByEmail($p['email']);
            if ($id !== null) {
                $celebrants[$i]['slack_id'] = $id;
                $resolved++;
            }
        } catch (Exception $e) {
            // Never let a lookup problem cost someone their birthday message —
            // fall back to plain names and make the reason loud in the log.
            bdayLog('WARNING: Slack email lookup failed (' . $e->getMessage()
                . '). Posting with plain names instead. The users:read.email scope is '
                . 'required for mention_by_email, and the app must be REINSTALLED after adding it.');
            break;
        }
    }
    bdayLog("Resolved {$resolved} of " . count($celebrants) . ' celebrant(s) to Slack users.');
}

// ---------------------------------------------------------------------------
// Build and post
// ---------------------------------------------------------------------------

$msgCfg = [
    'name_style'     => $nameStyle,
    'bold_names'     => (bool)($cfg['bold_names'] ?? true),
    'venue_label'    => (string)($cfg['venue_label'] ?? 'The Castle Fun Center'),
    'mention'        => (string)($cfg['mention'] ?? ''),
    'message_single' => (string)($cfg['message_single'] ?? ''),
    'message_multi'  => (string)($cfg['message_multi'] ?? ''),
];
foreach (['message_single', 'message_multi'] as $k) {
    if ($msgCfg[$k] === '') {
        unset($msgCfg[$k]); // fall back to the library defaults
    }
}

$batches = !empty($cfg['post_separately'])
    ? array_map(function ($p) { return [$p]; }, $celebrants)
    : [$celebrants];

$messages = [];
foreach ($batches as $batch) {
    $messages[] = ['people' => $batch, 'text' => bdayBuildText($batch, $msgCfg)];
}

if ($dryRun) {
    echo "\n--- DRY RUN — nothing was posted ---\n";
    foreach ($messages as $m) {
        echo "\nchannel: " . (string)($cfg['slack_channel'] ?? '(unset)') . "\n";
        echo "-----------------------------------------------------------------\n";
        echo $m['text'] . "\n";
        echo "-----------------------------------------------------------------\n";
    }
    echo "\n";
    exit(0);
}

$channel = trim((string)($cfg['slack_channel'] ?? ''));
if ($channel === '' || $channel === 'C0123456789') {
    fwrite(STDERR, "slack_channel is not set to a real channel ID.\n");
    exit(1);
}

$posted = [];
$failed = 0;
foreach ($messages as $idx => $m) {
    try {
        $ts = $slack->postMessage($channel, $m['text'], [
            'username'   => (string)($cfg['bot_username'] ?? ''),
            'icon_emoji' => (string)($cfg['bot_icon_emoji'] ?? ''),
        ]);
        $posted = array_merge($posted, $m['people']);
        bdayLog('Posted to ' . $channel . ' (ts ' . $ts . ').');
    } catch (Exception $e) {
        $failed++;
        bdayLog('ERROR: ' . $e->getMessage());
    }
    if ($idx < count($messages) - 1) {
        usleep(1200000); // chat.postMessage is ~1/sec per channel
    }
}

// Record only the people whose message actually went out, so a partial
// failure is retried on the next run instead of being silently swallowed.
if ($posted) {
    $state = bdayStateMark($state, $target, $posted);
    $state = bdayStatePrune($state, $today, 45);
    if (!bdayStateSave($statePath, $state)) {
        bdayLog('WARNING: could not write the state file at ' . $statePath
            . ' — a re-run today would post again.');
    }
}

if ($failed > 0) {
    fwrite(STDERR, "{$failed} message(s) failed to post.\n");
    exit(2);
}
bdayLog('Done.');
exit(0);
