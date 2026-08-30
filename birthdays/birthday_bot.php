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
require_once $root . '/lib/roster_guard.php';
require_once $root . '/lib/birthday_config.php';
require_once __DIR__ . '/lib/birthday_lib.php';
require_once __DIR__ . '/lib/slack_client.php';
require_once __DIR__ . '/lib/gif_source.php';

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

const BDAY_USAGE = <<<TXT
Usage: php birthdays/birthday_bot.php [options]

  --dry-run              Build today's message and print it; post nothing.
  --date=YYYY-MM-DD      Treat that date as today (for testing).
  --list[=DAYS]          Print upcoming birthdays (default 60 days) and exit.
  --test-slack           Check the token and post a plain test message; exit.
  --demo[=N]             Post ONE full sample announcement (GIF and all); exit.
                         N = how many people share that one birthday (max 6).
                         Run it repeatedly for different quips and GIFs.
                         Add --dry-run to print it instead of posting.
  --test-gifs            Check every configured GIF URL resolves; exit.
  --check                Health-check everything and print a checklist; exit.
  --resolve-channel=X    Print the channel ID for a #name (or ID); exit.
  --print-timezone       Print the timezone the bot treats as local; exit.
  --is-configured        Exit 0 if a Slack token and channel are set, 1 if
                         not. Prints nothing, touches no network. For
                         deploy scripts deciding whether to enable the timer.
  --force                Post even if today's greeting already went out.
  --roster-file=PATH     Read the roster from a JSON file instead of MSSQL.
  --config=PATH          Use a specific config file.
  --help                 Show this.

TXT;

/**
 * Known flags, and whether each takes a value.
 *
 * An unknown flag is a hard error rather than something quietly ignored: a
 * typo'd "--dryrun" that silently fell through to a REAL post is exactly the
 * failure this bot must not have.
 *
 * Values are policed for the same reason. A switch given a value is rejected,
 * because "--dry-run=0" would otherwise be read as falsy and POST FOR REAL —
 * the opposite of what was typed. A value-taking flag given none is rejected
 * too, rather than silently doing something else.
 */
const BDAY_SWITCHES = ['dry-run', 'force', 'test-slack', 'test-gifs', 'check', 'help', 'is-configured',
                       'print-timezone'];
const BDAY_OPTIONAL_VALUE = ['list', 'demo'];
const BDAY_REQUIRES_VALUE = ['date', 'roster-file', 'config', 'resolve-channel'];

$flags = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z][a-z-]*)(?:=(.*))?$/', $arg, $m)) {
        fwrite(STDERR, "Unrecognised argument: {$arg}\n\n" . BDAY_USAGE);
        exit(1);
    }
    [$name, $value] = [$m[1], $m[2] ?? null];
    $known = array_merge(BDAY_SWITCHES, BDAY_OPTIONAL_VALUE, BDAY_REQUIRES_VALUE);
    if (!in_array($name, $known, true)) {
        fwrite(STDERR, "Unknown option: --{$name}\n\n" . BDAY_USAGE);
        exit(1);
    }
    if ($value !== null && in_array($name, BDAY_SWITCHES, true)) {
        fwrite(STDERR, "--{$name} is a switch and takes no value — write it as --{$name}.\n\n"
            . BDAY_USAGE);
        exit(1);
    }
    if ($value === null && in_array($name, BDAY_REQUIRES_VALUE, true)) {
        fwrite(STDERR, "--{$name} needs a value, e.g. --{$name}=…\n\n" . BDAY_USAGE);
        exit(1);
    }
    if ($value !== null && $value === '' && !in_array($name, BDAY_OPTIONAL_VALUE, true)) {
        fwrite(STDERR, "--{$name} was given an empty value.\n\n" . BDAY_USAGE);
        exit(1);
    }
    $flags[$name] = $value ?? true;
}
if (array_key_exists('help', $flags)) {
    echo BDAY_USAGE;
    exit(0);
}
$dryRun    = array_key_exists('dry-run', $flags);
$force     = array_key_exists('force', $flags);
$testSlack = array_key_exists('test-slack', $flags);
$testGifs  = array_key_exists('test-gifs', $flags);
$doCheck   = array_key_exists('check', $flags);
$doDemo    = array_key_exists('demo', $flags);
$doList    = array_key_exists('list', $flags);
$rosterFile = isset($flags['roster-file']) && is_string($flags['roster-file']) ? $flags['roster-file'] : '';
$listDays  = $doList && is_string($flags['list']) ? max(1, min(400, (int)$flags['list'])) : 60;

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

/**
 * Load the configuration.
 *
 * Settings come from BirthdayConfig, which layers built-in defaults, then
 * data/birthday_config.php if it exists, then whatever the Birthdays page has
 * saved into api_config. So a file-only install keeps working untouched, and
 * anything edited on the page wins.
 *
 * --config=PATH replaces the file layer, for testing against a config that
 * isn't the live one.
 */
$configFile = $root . '/data/birthday_config.php';
if (!empty($flags['config']) && is_string($flags['config'])) {
    $configFile = $flags['config'];
    if (!is_file($configFile)) {
        fwrite(STDERR, "--config: no such file: {$configFile}\n");
        exit(1);
    }
} elseif (!is_file($configFile)) {
    // The legacy location, for an install that predates the move to data/.
    $legacy = __DIR__ . '/config.php';
    $configFile = is_file($legacy) ? $legacy : null;
}

$cfg = BirthdayConfig::load($configFile);
$configPath = $configFile ?? '(defaults + Birthdays page)';

$tz = trim((string)($cfg['timezone'] ?? ''));
if ($tz === '' && defined('DEFAULT_TIMEZONE')) {
    $tz = DEFAULT_TIMEZONE;
}
if ($tz !== '') {
    @date_default_timezone_set($tz);
}

/**
 * --print-timezone: which clock does this bot call "local"?
 *
 * systemd fires OnCalendar on the SYSTEM timezone, which is not necessarily
 * the app's. On this venue the host runs UTC and the app runs Eastern, so a
 * timer written as 09:00 posted at 05:00 local for months without anything
 * saying so. install.sh reads this to write the timer in the right zone, and
 * it answers the same question by hand.
 *
 * Deliberately the FIRST thing after the timezone is resolved: it must work
 * with no Slack token, no MSSQL and no channel configured.
 */
if (array_key_exists('print-timezone', $flags)) {
    echo ($tz !== '' ? $tz : date_default_timezone_get()) . "\n";
    exit(0);
}

/**
 * --is-configured: has anybody actually set this bot up?
 *
 * Silent, and deliberately cheap — no Slack call, no MSSQL connection, no
 * roster read. It answers only the question a deploy script needs before
 * enabling a systemd timer: would a firing have somewhere to post? Enabling a
 * timer for a bot with no token would put a failed run, and an audit row, in
 * front of the operator every morning for a bot they never asked for.
 *
 * Sits beside --print-timezone because it has the same requirement: it must
 * work when nothing at all is configured, which is precisely when it is asked.
 */
if (array_key_exists('is-configured', $flags)) {
    $hasToken   = trim((string)($cfg['slack_bot_token'] ?? '')) !== '';
    $hasChannel = trim((string)($cfg['slack_channel'] ?? '')) !== '';
    exit(($hasToken && $hasChannel) ? 0 : 1);
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

/**
 * Which channel do we actually post to?
 *
 * A channel ID goes straight through — the daily run should never spend an API
 * call, or need the channels:read scope, just to find a channel it already
 * knows. A #name is looked up once. If the lookup is impossible (no
 * channels:read), the name is handed to chat.postMessage anyway: Slack accepts
 * a name for some tokens, and if it doesn't, its own error is clearer than a
 * guess would be.
 *
 * @return array{0: string, 1: string} [channel to use, note for the log]
 */
function bdayChannelFor(SlackClient $slack, string $configured): array
{
    $configured = trim($configured);
    if ($configured === '' || $configured === 'C0123456789' || $configured === '#your-channel') {
        throw new RuntimeException('slack_channel is not set in the config.');
    }
    if (preg_match('/^[CGD][A-Z0-9]{8,}$/', $configured)) {
        return [$configured, ''];
    }
    try {
        $r = $slack->resolveChannel($configured);
        $note = $r['resolved']
            ? 'Resolved #' . $r['name'] . ' to ' . $r['id']
                . ' (put that ID in slack_channel to skip this lookup each run).'
            : '';
        return [$r['id'], $note];
    } catch (RuntimeException $e) {
        return [ltrim($configured, '#'),
            'Could not look the channel name up (' . $e->getMessage()
            . ') — passing it to Slack as-is.'];
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

/**
 * A --date that isn't today is a REHEARSAL.
 *
 * It always posts and is deliberately not recorded — neither in the
 * already-greeted list nor in the run record. Recording it would mark that
 * date "already done", and on the real morning the bot would find the job
 * finished and say nothing: the test would silently cancel the very greeting
 * it was checking.
 */
$isRehearsal = ($target !== $today);

/** Runs that put something in the channel, and so need the run lock. */
$willPost = !$dryRun && !$doList;
/** Runs that are today's real firing, and so leave a run record behind. */
$willRecord = $willPost && !$isRehearsal;

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

/**
 * The master switch: "Post birthday greetings" on the Birthdays page, or
 * `enabled` in the config file.
 *
 * It is the one setting somebody reaches for in a hurry — a name is wrong, a
 * person has just left, the channel is being reorganised — and the daily run
 * never read it, so turning it off changed nothing and the greetings kept
 * going out. Absent means on, so an install that predates the switch is
 * unaffected.
 *
 * The guard itself sits before the roster read (below), not here, so --demo
 * and --check still work with it off: --check is exactly how you find out WHY
 * the channel went quiet, and it cannot answer that if the switch stops it
 * running.
 */
$enabled = !array_key_exists('enabled', $cfg) || (bool)$cfg['enabled'];

// ---------------------------------------------------------------------------
// Recording what this run did
// ---------------------------------------------------------------------------

$statePath     = (string)($cfg['state_file'] ?? ($root . '/data/birthday_state.json'));
$heartbeatPath = (string)($cfg['heartbeat_file'] ?? ($root . '/data/.heartbeat_birthdays'));
$lockPath      = (string)($cfg['lock_file'] ?? ($root . '/data/birthday.lock'));

/**
 * Leave the two marks that say this run happened, and what it did.
 *
 * Called from EVERY exit on the real path — including the failures that used
 * to just write to stderr and quit. That is the point: a run that could not
 * reach MSSQL is the run most worth reporting, and it is exactly the one that
 * previously left nothing behind but a log line.
 *
 * The heartbeat is written even for a failure, because it answers a different
 * question ("did the timer fire") from the outcome ("did it work"). Losing
 * that distinction would make a broken token look identical to a dead timer.
 *
 * @param string $outcome posted | idle | disabled | failed
 */
function bdayRecordRun(string $outcome, string $detail = '', int $count = 0): void
{
    global $willRecord, $statePath, $heartbeatPath, $target;
    if (!$willRecord) {
        return;   // a dry run, a listing or a rehearsal is not this morning's firing
    }
    bdayHeartbeatWrite($heartbeatPath);
    $state = bdayStateRecordRun(bdayStateLoad($statePath), $target, $outcome, $detail, $count);
    if (!bdayStateSave($statePath, $state)) {
        bdayLog('WARNING: could not write the run record at ' . $statePath . '.');
    }
}

/**
 * Put the run in the app's own audit trail, beside every other action.
 *
 * Only real events land here — a posted greeting or a failure. A row for each
 * of the ~300 days a year when nobody has a birthday would bury them; the
 * heartbeat already covers "it ran and there was nothing to do".
 *
 * Best-effort by design: the greeting has already gone out by the time this
 * is called, and a locked database must not turn a delivered message into a
 * failed run.
 */
function bdayAuditLog(string $action, bool $success, string $details, string $error = ''): void
{
    global $willRecord;
    if (!$willRecord) {
        return;
    }
    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details, error_message)
             VALUES (:p0, :p1, :p2, :p3, :p4)',
            ['birthdays', $action, $success ? 1 : 0,
             mb_substr($details, 0, 500), $error === '' ? null : mb_substr($error, 0, 500)]
        );
    } catch (Exception $e) {
        bdayLog('NOTE: could not write the audit row (' . $e->getMessage() . ').');
    }
}

/** Record a failure, say why on stderr, and stop. */
function bdayFail(string $detail, int $code = 2): void
{
    bdayRecordRun('failed', $detail);
    bdayAuditLog('birthday_failed', false, 'The daily run could not complete.', $detail);
    fwrite(STDERR, $detail . "\n");
    exit($code);
}

// ---------------------------------------------------------------------------
// --demo: post a complete sample announcement
//
// --test-slack proves delivery with a plain line of text. This shows the real
// thing — the quip, the GIF, the footer, the reactions — so the format can be
// reviewed before anyone's actual birthday. It uses a placeholder name and says
// it's a preview, because putting a real employee's name up out of season is
// confusing, and it touches neither the roster nor the already-greeted record.
// ---------------------------------------------------------------------------

if ($doDemo) {
    try {
        $slack = null;
        $channel = trim((string)($cfg['slack_channel'] ?? ''));
        if (!$dryRun) {
            $slack = new SlackClient((string)($cfg['slack_bot_token'] ?? ''));
            [$channel, $note] = bdayChannelFor($slack, (string)($cfg['slack_channel'] ?? ''));
            if ($note !== '') { bdayLog($note); }
        }

        // --demo=3 previews a shared birthday, which uses a different pool
        // and different name-joining ("A, B and C") than the single case.
        $howMany = is_string($flags['demo']) ? (int)$flags['demo'] : 1;
        $howMany = max(1, min(6, $howMany));

        // A shared surname makes these read as obviously synthetic, so nobody
        // in the channel goes looking for an employee who doesn't exist.
        $firstNames = ['Robin', 'Casey', 'Jordan', 'Riley', 'Avery', 'Quinn'];
        $sample = [];
        for ($i = 0; $i < $howMany; $i++) {
            $sample[] = [
                'emp_no' => '', 'first' => $firstNames[$i], 'last' => 'Sample',
                'name' => $firstNames[$i] . ' Sample', 'birth_date' => '1990-01-01',
                'month' => 1, 'day' => 1, 'email' => '', 'slack_id' => '',
            ];
        }

        // Same wording rules as the real post — see bdayMessageConfig(). The
        // ping prefix is the one deliberate difference: a sample must never
        // @-here a channel.
        $msgCfg = bdayMessageConfig($cfg, ['mention' => '']);

        // Vary the seed per run so repeated demos show different quips and GIFs.
        // Microtime plus randomness, not the second: two demos fired back to
        // back would otherwise land in the same second, pick the same pair, and
        // look like the variety isn't working.
        $seed = 'demo|' . microtime(true) . '|' . bin2hex(random_bytes(4));
        $text = bdayBuildText($sample, $msgCfg, $seed);
        $gif  = GifSource::pick($cfg, $seed);
        bdayLog($gif !== null
            ? 'GIF from ' . $gif['source'] . ': ' . $gif['url']
            : 'No GIF resolved — posting without one. Run --test-gifs to see why.');

        $blocks = bdayBuildBlocks($text, $gif['url'] ?? null, $cfg);
        $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn',
            'text' => ':wrench: _Preview — this is what the daily post looks like. '
                . ($howMany > 1 ? 'These names are placeholders' : '"Robin Sample" is a placeholder')
                . ', not a real birthday._']]];

        // --demo --dry-run prints it instead of posting, so the wording can be
        // checked without putting anything in the channel.
        if ($dryRun) {
            echo "\n--- DRY RUN — nothing was posted ---\n";
            echo "\nchannel: " . $channel . "  |  " . $howMany . " celebrant(s)\n";
            echo "-----------------------------------------------------------------\n";
            echo $text . "\n";
            if ($gif !== null) { echo "\n[GIF · " . $gif['source'] . "]\n" . $gif['url'] . "\n"; }
            foreach (array_slice($blocks, -2) as $b) {
                if (($b['type'] ?? '') === 'context') { echo "\n" . $b['elements'][0]['text'] . "\n"; }
            }
            echo "-----------------------------------------------------------------\n\n";
            exit(0);
        }

        $ts = $slack->postMessage($channel, $text, [
            'blocks'     => $blocks,
            'username'   => (string)($cfg['bot_username'] ?? ''),
            'icon_emoji' => (string)($cfg['bot_icon_emoji'] ?? ''),
        ]);
        bdayLog('Posted the sample announcement to ' . $channel . ' (ts ' . $ts . ').');
        if ($slack->customizeDropped() !== '') { bdayLog('WARNING: ' . $slack->customizeDropped()); }

        if (!empty($cfg['add_reactions']) && $ts !== '') {
            $added = 0;
            $emojis = (array)($cfg['reactions'] ?? ['tada', 'birthday']);
            foreach ($emojis as $e) {
                if ($slack->addReaction($channel, $ts, (string)$e)) { $added++; }
            }
            bdayLog($added > 0
                ? "Added {$added} reaction(s)."
                : 'Reactions were NOT added — the token is probably missing reactions:write '
                  . '(add it and REINSTALL the app, or set add_reactions to false).');
        }
        echo "\nThat is exactly what a real birthday post looks like.\n"
           . "Nothing was recorded, and no employee data was used.\n\n";
        exit(0);
    } catch (Exception $e) {
        fwrite(STDERR, 'Demo failed: ' . $e->getMessage() . "\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------------
// --resolve-channel: print the ID for a name, for scripts to capture
// ---------------------------------------------------------------------------

if (array_key_exists('resolve-channel', $flags)) {
    $want = (string)$flags['resolve-channel'];
    try {
        $slack = new SlackClient((string)($cfg['slack_bot_token'] ?? ''));
        $r = $slack->resolveChannel($want);
        if ($r['resolved']) {
            fwrite(STDERR, '#' . $r['name'] . ' -> ' . $r['id'] . "\n");
        }
        echo $r['id'] . "\n";   // stdout stays clean for $(...) capture
        exit(0);
    } catch (Exception $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// --check: one command that answers "is this thing actually wired up?"
//
// Every dependency, in order, with the specific fix beside anything broken.
// Posts nothing. The installer runs this, and it is the first thing to reach
// for when the channel goes quiet.
// ---------------------------------------------------------------------------

if ($doCheck) {
    $problems = [];
    $row = function (string $label, string $status, string $detail = '') {
        printf("  %-18s %-6s %s\n", $label, $status, $detail);
    };
    echo "\nBirthday bot health check\n" . str_repeat('=', 62) . "\n";
    $row('Config', 'ok', $configPath);
    // The zone the bot calls local. systemd fires on the SYSTEM zone, which
    // can be a different one — printing this is how the gap becomes visible
    // when you put it beside `systemctl list-timers`.
    $row('Clock', 'ok', ($tz !== '' ? $tz : date_default_timezone_get())
        . ' — ' . date('H:i') . ' now (systemd fires on the system zone: '
        . 'compare with systemctl list-timers)');
    // First row after the config, because when this is off nothing below it
    // matters — every other line can be green and the channel still silent.
    $row('Posting', $enabled ? 'ok' : 'OFF',
        $enabled ? 'greetings are on' : 'greetings are switched off — nothing will post');

    // Everything below this line checks whether the bot COULD work. This one
    // checks whether it actually has been — the question the others can't
    // answer, and the one that matters when a birthday has already been missed.
    $health = bdayRunHealth(bdayStateLoad($statePath), bdayHeartbeatRead($heartbeatPath));
    $row('Last run', in_array($health['status'], ['ok', 'idle', 'off'], true)
        ? ($health['status'] === 'ok' ? 'ok' : '-') : strtoupper($health['status']),
        $health['detail']);
    if ($health['status'] === 'fail') {
        $problems[] = 'Last run: ' . $health['detail'];
    } elseif ($health['status'] === 'warn') {
        $problems[] = 'The bot has missed a daily firing — ' . $health['detail'];
    }
    foreach (BirthdayConfig::warnings() as $w) {
        $row('Stored value', 'FAIL', substr($w, 0, 70));
        $problems[] = $w;
    }

    // -- MSSQL ------------------------------------------------------------
    $drivers = MssqlClient::availableDrivers();
    if (!$drivers) {
        $row('MSSQL driver', 'FAIL', 'none installed — run via birthdays/run.sh');
        $problems[] = 'No MSSQL driver. Run the bot through birthdays/run.sh so it uses the pdo_dblib image.';
    } else {
        $row('MSSQL driver', 'ok', implode(', ', $drivers));
        if (!MssqlClient::isConfigured()) {
            $row('MSSQL config', 'FAIL', 'not set up');
            $problems[] = 'MSSQL is not configured. Set it on the Go-Kart Labor page -> Settings.';
        } else {
            $st = MssqlClient::settings();
            $row('MSSQL config', 'ok', $st['host'] . ':' . $st['port'] . '/' . $st['database']);
            $rosterSql = trim((string)($cfg['roster_sql'] ?? ''));
            if (stripos($rosterSql, 'TODO_CONFIRM_EMPLOYMENT_FILTER') !== false) {
                $row('Roster query', 'FAIL', 'employment filter not filled in');
                $problems[] = 'roster_sql still has TODO_CONFIRM_EMPLOYMENT_FILTER in it.';
            } else {
                try {
                    $c = new MssqlClient();
                    $c->setTimeout(max(5, (int)($cfg['query_timeout'] ?? 30)));
                    $t0 = microtime(true);
                    $rr = $c->rows($rosterSql, max(1, (int)($cfg['roster_max_rows'] ?? 5000)));
                    $ms = (int)round((microtime(true) - $t0) * 1000);
                    $nn = bdayNormalizeRoster($rr, [
                        'today'              => $today,
                        'ignore_birth_dates' => (array)($cfg['ignore_birth_dates'] ?? []),
                        'exclude_emp_nos'    => (array)($cfg['exclude_emp_nos'] ?? []),
                        'exclude_names'      => (array)($cfg['exclude_names'] ?? []),
                    ]);
                    $n = count($nn['people']);
                    if ($n === 0) {
                        $row('Roster query', 'FAIL', count($rr) . ' rows, but 0 usable birthdays');
                        $problems[] = 'The roster query returns rows but no usable birth dates — check the birth_date alias.';
                    } else {
                        $row('Roster query', 'ok', $n . ' current employees with a birthday (' . $ms . 'ms)');
                        // The headcount above only means something if you can see WHAT made
                        // those people 'current'. Without this row an operator has to read
                        // the SQL to find out whether leavers are excluded at all.
                        $emp = RosterGuard::employmentFilter($rosterSql);
                        $row('Still employed', $emp['ok'] ? 'ok' : 'WARN', $emp['summary']);
                        if (!$emp['ok']) {
                            $problems[] = 'Roster query: ' . $emp['summary'] . '.';
                        }
                        $up = bdayUpcoming($nn['people'], $today, 60, $leapMode);
                        $next = $up ? array_key_first($up) : null;
                        $today_hits = bdayCelebrants($nn['people'], $today, $leapMode);
                        $row('Today', $today_hits ? 'ok' : '-',
                            $today_hits ? count($today_hits) . ' birthday(s)' : 'nobody today');
                        $row('Next birthday', $next ? 'ok' : '-',
                            $next ? $next . ' (' . count($up[$next]) . ' person/people)' : 'none in 60 days');
                    }
                } catch (Exception $e) {
                    $row('Roster query', 'FAIL', substr($e->getMessage(), 0, 60));
                    $problems[] = 'Could not run the roster query: ' . $e->getMessage();
                }
            }
        }
    }

    // -- Slack ------------------------------------------------------------
    try {
        $sc = new SlackClient((string)($cfg['slack_bot_token'] ?? ''));
        $who = $sc->authTest();
        $row('Slack token', 'ok', 'workspace "' . $who['team'] . '", bot "' . $who['user'] . '"');
    } catch (Exception $e) {
        $row('Slack token', 'FAIL', substr($e->getMessage(), 0, 60));
        $problems[] = 'Slack: ' . $e->getMessage();
    }
    $chan = trim((string)($cfg['slack_channel'] ?? ''));
    if ($chan === '' || $chan === 'C0123456789') {
        $row('Slack channel', 'FAIL', 'not set');
        $problems[] = 'slack_channel is not set in the config.';
    } elseif (isset($sc)) {
        try {
            $rc = $sc->resolveChannel($chan);
            $row('Slack channel', 'ok',
                ($rc['name'] !== '' ? '#' . $rc['name'] . ' = ' : '') . $rc['id']
                . ' (use --test-slack to prove delivery)');
        } catch (Exception $e) {
            $row('Slack channel', 'FAIL', substr($e->getMessage(), 0, 70));
            $problems[] = 'Channel: ' . $e->getMessage();
        }
    } else {
        $row('Slack channel', '-', $chan . ' (not checked — the token failed)');
    }

    $botName = trim((string)($cfg['bot_username'] ?? ''));
    if ($botName === '') {
        $row('Posts as', '-', 'your Slack app\'s own name');
    } else {
        $row('Posts as', 'ok', $botName
            . (($cfg['bot_icon_emoji'] ?? '') !== '' ? ' ' . $cfg['bot_icon_emoji'] : '')
            . ' (needs chat:write.customize — --demo confirms it)');
    }

    // -- GIFs -------------------------------------------------------------
    if (empty($cfg['gifs_enabled'])) {
        $row('GIFs', '-', 'disabled');
    } else {
        $gt = max(2, (int)($cfg['gif_timeout'] ?? 6));
        $key = trim((string)($cfg['giphy_api_key'] ?? ''));
        $pick = GifSource::pick($cfg, 'health-check');
        if ($pick !== null) {
            $row('GIFs', 'ok', $pick['source']);
        } elseif (!GifSource::internetReachable($gt)) {
            $row('GIFs', 'WARN', 'no outbound network from this host');
            $problems[] = 'This host cannot reach the internet, so GIFs (and Slack) will not work.';
        } else {
            $row('GIFs', 'WARN', 'none resolved — run --test-gifs'
                . ($key === '' ? ' (or set giphy_api_key)' : ''));
        }
    }

    echo str_repeat('=', 62) . "\n";
    if (!$problems) {
        // "Everything checks out" would be a wrong answer while the switch is
        // off — nothing is broken, but nothing is going to post either.
        echo $enabled
            ? "Everything checks out.\n\n"
            : "Everything is wired up correctly, BUT greetings are switched OFF, so\n"
              . "nothing will post. Turn \"Post birthday greetings\" back on to resume.\n\n";
        exit(0);
    }
    echo count($problems) . " problem(s) to fix:\n";
    foreach ($problems as $i => $pr) {
        echo '  ' . ($i + 1) . '. ' . $pr . "\n";
    }
    echo "\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// --test-gifs: confirm the GIF URLs actually resolve
//
// The curated list is hotlinked from a third party and can rot at any time, so
// this is the check that says whether it still works. The bot also verifies
// the one GIF it is about to use at post time, so a dead entry degrades to
// "no image" rather than a broken one — this just tells you up front.
// ---------------------------------------------------------------------------

if ($testGifs) {
    $timeout = max(2, (int)($cfg['gif_timeout'] ?? 6));
    $list = $cfg['gifs'] ?? GifSource::DEFAULT_GIFS;
    $list = array_values(array_filter(array_map('trim', array_map('strval', (array)$list))));

    echo "\nGIFs are " . (empty($cfg['gifs_enabled']) ? "OFF (gifs_enabled is false)" : "ON") . ".\n";

    $key = trim((string)($cfg['giphy_api_key'] ?? ''));
    if ($key !== '') {
        $terms = $cfg['gif_search_terms'] ?? GifSource::DEFAULT_SEARCH_TERMS;
        $terms = array_values(array_filter(array_map('strval', (array)$terms)));
        $term = $terms[0] ?? 'happy birthday';
        echo "\nGiphy API (rating=" . ($cfg['giphy_rating'] ?? 'g') . "), searching \"{$term}\":\n";
        try {
            $found = GifSource::giphySearch($key, $term, $timeout, (string)($cfg['giphy_rating'] ?? 'g'));
            echo '  ' . count($found) . " result(s).\n";
            if ($found) {
                $ok = GifSource::urlResolves($found[0], $timeout);
                echo '  first result ' . ($ok ? 'resolves OK' : 'DID NOT resolve') . ": {$found[0]}\n";
                echo "  Giphy is working — the curated list below is only the fallback.\n";
            }
        } catch (Exception $e) {
            echo '  FAILED: ' . $e->getMessage() . "\n";
            echo "  The bot will fall back to the curated list below.\n";
        }
    } else {
        echo "\nNo giphy_api_key set, so the curated list is the only source.\n"
           . "A free key at https://developers.giphy.com gives a fresh GIF every time.\n";
    }

    echo "\nCurated list (" . count($list) . " entries):\n";
    $good = 0;
    foreach ($list as $url) {
        if (!GifSource::looksLikeUrl($url)) {
            echo "  BAD URL   {$url}\n";
            continue;
        }
        $ok = GifSource::urlResolves($url, $timeout);
        if ($ok) { $good++; }
        echo '  ' . ($ok ? 'ok       ' : 'DEAD     ') . $url . "\n";
    }
    echo "\n{$good} of " . count($list) . " resolve.\n";
    if ($good === 0) {
        // Before blaming the list, check this host can reach anything at all —
        // a firewalled venue network makes every URL look rotten, and telling
        // someone to prune a perfectly good list would be a wrong answer.
        if (!GifSource::internetReachable($timeout)) {
            echo "\nBUT this host can't reach slack.com either, so that is a CONNECTIVITY\n"
               . "problem, not a dead list — don't prune anything on the strength of it.\n"
               . "Check the firewall/proxy, then re-run. (Note the bot needs to reach\n"
               . "slack.com regardless, so this has to be fixed anyway.)\n\n";
            exit(1);
        }
        echo "This host CAN reach slack.com, so the URLs really have gone. Either set\n"
           . "giphy_api_key, or replace the `gifs` list in config.php with URLs of your\n"
           . "own (any public https .gif). Birthday messages still post without an image.\n\n";
        exit(1);
    }
    if ($good < count($list)) {
        echo "Prune the dead ones from `gifs` in config.php to keep the rotation varied.\n";
    }
    echo "\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// --test-slack: prove the token and the channel before trusting the timer
// ---------------------------------------------------------------------------

if ($testSlack) {
    try {
        $slack = new SlackClient((string)($cfg['slack_bot_token'] ?? ''));
        $who = $slack->authTest();
        bdayLog("Slack auth OK — workspace '{$who['team']}', bot '{$who['user']}'.");
        [$channel, $note] = bdayChannelFor($slack, (string)($cfg['slack_channel'] ?? ''));
        if ($note !== '') { bdayLog($note); }
        $slack->postMessage($channel, ':wave: Birthday bot test — this channel is wired up correctly.', [
            'username'   => (string)($cfg['bot_username'] ?? ''),
            'icon_emoji' => (string)($cfg['bot_icon_emoji'] ?? ''),
        ]);
        bdayLog("Test message delivered to {$channel}.");
        if ($slack->customizeDropped() !== '') { bdayLog('WARNING: ' . $slack->customizeDropped()); }
        exit(0);
    } catch (Exception $e) {
        fwrite(STDERR, 'Slack test failed: ' . $e->getMessage() . "\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------------
// The master switch
//
// Checked BEFORE the roster is read, so a switched-off bot needs neither MSSQL
// nor Slack to be working — someone who turned the greetings off because the
// database was down should not get a database error out of the timer every
// morning afterwards.
//
// --list and --dry-run deliberately still run: both only report, and being
// shown what WOULD go out is precisely what somebody about to switch it back
// on wants to see.
// ---------------------------------------------------------------------------

if (!$enabled) {
    $why = 'Birthday greetings are turned OFF ("Post birthday greetings" on the '
         . 'Birthdays page, or `enabled` in the config file).';
    if (!$doList && !$dryRun) {
        // Recorded, not silent: the page should say "ran, but posting is off"
        // rather than leaving somebody to wonder whether the timer is dead.
        bdayRecordRun('disabled', 'The greetings are switched off.');
        bdayLog($why . ' Nothing was posted.');
        exit(0);
    }
    bdayLog('NOTE: ' . $why . ' This run only reports — nothing would post.');
}

// ---------------------------------------------------------------------------
// The run lock
//
// Taken before the roster read so two overlapping runs cannot both decide
// nobody has been greeted yet and both post. The timer is Persistent, it can
// carry catch-up firings, and somebody can always start a run by hand, so the
// overlap is real rather than theoretical.
//
// A second run exits quietly and successfully: whoever holds the lock is
// already doing this morning's job, and reporting a failure for that would be
// a false alarm. It leaves no run record either — the holder writes it.
// ---------------------------------------------------------------------------

$lock = null;
if ($willPost) {
    $lock = bdayLockAcquire($lockPath);
    if ($lock === false) {
        bdayLog('Another birthday-bot run is already in progress — leaving it to that one.');
        exit(0);
    }
    if ($lock === null) {
        // Could not open the lock file at all. Carrying on unlocked risks a
        // duplicate; stopping guarantees a missed birthday. Take the lesser.
        bdayLog('WARNING: could not open the lock file at ' . $lockPath
            . ' — continuing without a lock. Two runs at once could double-post.');
    }
}
register_shutdown_function(function () {
    global $lock;
    bdayLockRelease($lock);
});

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
        bdayFail('roster_sql is empty — run `php birthdays/discover.php` to generate one.', 1);
    }
    // The shipped default deliberately carries a marker where the employment
    // filter belongs. Refusing to run while it is still there is the one check
    // that cannot be satisfied by accident: a greeting sent to somebody who
    // left last year is worse than sending nothing at all.
    if (stripos($rosterSql, 'TODO_CONFIRM_EMPLOYMENT_FILTER') !== false) {
        fwrite(STDERR,
            "That marker stands where the \"still employed\" filter belongs. Run\n"
            . "`php birthdays/discover.php` (or the EmployeeStatus queries in\n"
            . "EXPLORER-QUERIES.md) to find which column and value mean current staff,\n"
            . "then replace the marker line in the roster query.\n");
        bdayFail('roster_sql still contains TODO_CONFIRM_EMPLOYMENT_FILTER, '
            . 'so the "still employed" filter has never been filled in.', 1);
    }
    // The employment filter is the only thing between "today's celebrants" and
    // "everyone who ever worked here", and it lives in an editable query — so
    // say out loud, every run, what is actually enforcing it.
    $employment = RosterGuard::employmentFilter($rosterSql);
    if ($employment['ok']) {
        bdayLog('Employment filter: ' . $employment['summary'] . '.');
    } else {
        bdayLog('WARNING: ' . $employment['summary'] . '. People who have left may be wished a happy birthday.');
    }

    try {
        MssqlClient::assertReadOnly($rosterSql);
    } catch (RuntimeException $e) {
        bdayFail('roster_sql was rejected by the read-only guard: ' . $e->getMessage(), 1);
    }

    if (!MssqlClient::availableDrivers()) {
        bdayFail('No MSSQL PDO driver in this PHP runtime — run the bot inside the '
            . 'pdo_dblib overlay image the app already uses (see birthdays/README.md).');
    }
    if (!MssqlClient::isConfigured()) {
        bdayFail('MSSQL is not configured in the app yet (Go-Kart Labor page -> Settings).');
    }

    try {
        $client = new MssqlClient();
        $client->setTimeout(max(5, (int)($cfg['query_timeout'] ?? 30)));
        $rows = $client->rows($rosterSql, max(1, (int)($cfg['roster_max_rows'] ?? 5000)));
    } catch (Exception $e) {
        bdayFail('Could not read the roster: ' . $e->getMessage());
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
    bdayFail('The roster query returned ' . count($rows) . ' rows but none had a usable '
        . 'birth date — check it selects the birthday column and aliases it `birth_date`.', 1);
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
    // Recorded, because "ran and there was nobody" and "never ran" look
    // identical in the channel and are completely different problems.
    bdayRecordRun('idle', 'Nobody had a birthday.');
    bdayLog("No birthdays on {$target}. Nothing to post.");
    exit(0);
}

$max = max(1, (int)($cfg['max_celebrants'] ?? 12));
if (count($celebrants) > $max) {
    fwrite(STDERR,
        "That usually means the roster query is reading the wrong column (a hire date,\n"
        . "say) rather than a genuine coincidence. Run `php birthdays/discover.php`.\n");
    bdayFail(sprintf(
        'Refused to post: %d people share a birthday on %s, over the max_celebrants limit of %d.',
        count($celebrants), $target, $max
    ), 1);
}

$state = bdayStateLoad($statePath);

if ($isRehearsal) {
    bdayLog('Rehearsing ' . $target . ' — this run will NOT be recorded, so the real '
        . 'greeting on that day still goes out.');
}

if (!$force && !$isRehearsal) {
    $pending = [];
    foreach ($celebrants as $p) {
        if (!bdayStateHas($state, $target, $p)) {
            $pending[] = $p;
        }
    }
    if (!$pending) {
        // The heartbeat moves (a catch-up firing that found nothing to do is
        // still the timer proving it is alive), but the OUTCOME deliberately
        // does not: an earlier run today already recorded 'posted', and
        // overwriting that would turn a delivered greeting into a blander
        // record of the run that found it already done.
        if ($willRecord) {
            bdayHeartbeatWrite($heartbeatPath);
        }
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
            bdayFail($e->getMessage(), 1);
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

// Every wording setting, assembled in the one place that knows the full list,
// so what --demo and the Birthdays page preview show is what actually posts.
$msgCfg = bdayMessageConfig($cfg);

$batches = !empty($cfg['post_separately'])
    ? array_map(function ($p) { return [$p]; }, $celebrants)
    : [$celebrants];

$messages = [];
foreach ($batches as $batch) {
    // One seed per message, from the date and the people in it. Everything
    // random-looking downstream (which quip, which GIF) derives from this, so
    // a dry run previews EXACTLY what the real run will post, and re-running
    // never quietly changes the message.
    $seed = bdaySeedFor($target, $batch);
    $text = bdayBuildText($batch, $msgCfg, $seed);
    $gif  = GifSource::pick($cfg, $seed);
    $messages[] = [
        'people' => $batch,
        'text'   => $text,
        'gif'    => $gif,
        'blocks' => bdayBuildBlocks($text, $gif['url'] ?? null, $cfg),
    ];
}

if ($dryRun) {
    echo "\n--- DRY RUN — nothing was posted ---\n";
    foreach ($messages as $m) {
        echo "\nchannel: " . (string)($cfg['slack_channel'] ?? '(unset)') . "\n";
        echo "-----------------------------------------------------------------\n";
        echo $m['text'] . "\n";
        if ($m['gif'] !== null) {
            echo "\n[GIF · " . $m['gif']['source'] . "]\n" . $m['gif']['url'] . "\n";
        } elseif (!empty($cfg['gifs_enabled'])) {
            echo "\n[no GIF — nothing resolved; run --test-gifs]\n";
        }
        $footer = end($m['blocks']);
        if (is_array($footer) && ($footer['type'] ?? '') === 'context') {
            echo "\n" . $footer['elements'][0]['text'] . "\n";
        }
        echo "-----------------------------------------------------------------\n";
    }
    echo "\n";
    exit(0);
}

try {
    [$channel, $chanNote] = bdayChannelFor($slack, (string)($cfg['slack_channel'] ?? ''));
} catch (RuntimeException $e) {
    bdayFail($e->getMessage(), 1);
}
if ($chanNote !== '') { bdayLog($chanNote); }

$posted = [];
$failed = 0;
$errors = [];
foreach ($messages as $idx => $m) {
    try {
        $ts = $slack->postMessage($channel, $m['text'], [
            'blocks'     => $m['blocks'],
            'username'   => (string)($cfg['bot_username'] ?? ''),
            'icon_emoji' => (string)($cfg['bot_icon_emoji'] ?? ''),
        ]);
        $posted = array_merge($posted, $m['people']);
        bdayLog('Posted to ' . $channel . ' (ts ' . $ts . ')'
            . ($m['gif'] !== null ? ' with a GIF from ' . $m['gif']['source'] : ' without a GIF') . '.');
        // A greeting that only landed on the second attempt still landed, but
        // it says something about the network at 09:00 and is worth the line.
        foreach ($slack->retryNotes() as $note) { bdayLog('NOTE: ' . $note); }
        if ($slack->customizeDropped() !== '') { bdayLog('WARNING: ' . $slack->customizeDropped()); }

        // Seed the reactions so nobody has to be first. Best-effort: a missing
        // reactions:write scope must not turn a delivered greeting into a
        // failure, so addReaction() reports rather than throws.
        if (!empty($cfg['add_reactions']) && $ts !== '') {
            $emojis = (array)($cfg['reactions'] ?? ['tada', 'birthday']);
            $added = 0;
            foreach ($emojis as $emoji) {
                if ($slack->addReaction($channel, $ts, (string)$emoji)) { $added++; }
            }
            if ($added < count($emojis)) {
                bdayLog('Note: ' . (count($emojis) - $added) . ' reaction(s) were not added '
                    . '(reactions:write scope missing, or an unknown emoji name).');
            }
        }
    } catch (Exception $e) {
        $failed++;
        $errors[] = $e->getMessage();
        foreach ($slack->retryNotes() as $note) { bdayLog('NOTE: ' . $note); }
        bdayLog('ERROR: ' . $e->getMessage());
    }
    if ($idx < count($messages) - 1) {
        usleep(1200000); // chat.postMessage is ~1/sec per channel
    }
}

$postedNames = [];
foreach ($posted as $p) {
    $postedNames[] = bdayDisplayName($p, $nameStyle);
}

// Record only the people whose message actually went out, so a partial
// failure is retried on the next run instead of being silently swallowed.
if ($posted && $isRehearsal) {
    bdayLog('Rehearsal — not recorded.');
} elseif ($posted) {
    $state = bdayStateMark($state, $target, $posted);
    $state = bdayStatePrune($state, $today, 45);
    $state = bdayStateRecordRun(
        $state, $target,
        $failed > 0 ? 'failed' : 'posted',
        $failed > 0
            ? sprintf('%d of %d message(s) failed: %s', $failed, count($messages),
                      implode('; ', $errors))
            : 'to ' . $channel,
        count($posted)
    );
    if (!bdayStateSave($statePath, $state)) {
        bdayLog('WARNING: could not write the state file at ' . $statePath
            . ' — a re-run today would post again.');
    }
    // Unconditional: reaching here means something was delivered on the real
    // path, which is by definition this morning's firing.
    bdayHeartbeatWrite($heartbeatPath);
    bdayAuditLog(
        $failed > 0 ? 'birthday_failed' : 'birthday_posted',
        $failed === 0,
        'Greeted ' . count($posted) . ' in ' . $channel . ': ' . implode(', ', $postedNames),
        $failed > 0 ? implode('; ', $errors) : ''
    );
}

if ($failed > 0) {
    // Nothing at all got through: the run record has to say so, since the
    // branch above only fires when SOMETHING was delivered.
    if (!$posted) {
        bdayFail(sprintf('All %d birthday message(s) failed to post: %s',
            $failed, implode('; ', $errors)));
    }
    fwrite(STDERR, "{$failed} message(s) failed to post.\n");
    exit(2);
}
bdayLog('Done.');
exit(0);
