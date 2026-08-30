<?php
/**
 * CEplay work-anniversary Slack bot.
 *
 * Reads the current-employee roster out of the venue's CenterEdge MSSQL
 * database (read-only, one guarded SELECT you control) and posts a message to
 * Slack for anyone whose HIRE DATE anniversary is today AND whose
 * employment-status column still says they work here.
 *
 * The sibling of birthdays/birthday_bot.php. It shares that bot's Slack client
 * and GIF picker, and nothing else: its own config keys, its own state file,
 * its own heartbeat, its own lock and its own systemd timer, so the two can run
 * on the same morning without either one being able to swallow the other's
 * record.
 *
 * Changes nothing in the CEplay app: it borrows the app's stored MSSQL
 * connection and its read-only client, and writes only its own state file.
 *
 * Usage:
 *   php anniversaries/anniversary_bot.php                post today's message
 *   php anniversaries/anniversary_bot.php --dry-run      build it, post nothing
 *   php anniversaries/anniversary_bot.php --date=2026-09-14   pretend it's that day
 *   php anniversaries/anniversary_bot.php --list         next 60 days
 *   php anniversaries/anniversary_bot.php --list=14      next 14 days
 *   php anniversaries/anniversary_bot.php --test-slack   prove the token + channel
 *   php anniversaries/anniversary_bot.php --force        ignore "already posted"
 *   php anniversaries/anniversary_bot.php --roster-file=roster.json
 *                                                        read the roster from a
 *                                                        JSON file instead of
 *                                                        MSSQL, for testing the
 *                                                        wording off-site
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
require_once $root . '/lib/anniversary_config.php';
require_once __DIR__ . '/lib/anniv_lib.php';
// Shared with the birthday bot on purpose — see the header of each file.
require_once $root . '/birthdays/lib/slack_client.php';
require_once $root . '/birthdays/lib/gif_source.php';

/** Where an operator sets this bot's token, for SlackClient's "no token" error. */
const ANNIV_CONFIG_HINT = 'the Anniversaries page in CEplay, or in data/anniversary_config.php';

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

const ANNIV_USAGE = <<<TXT
Usage: php anniversaries/anniversary_bot.php [options]

  --dry-run              Build today's message and print it; post nothing.
  --date=YYYY-MM-DD      Treat that date as today (for testing).
  --list[=DAYS]          Print upcoming anniversaries (default 60 days); exit.
  --test-slack           Check the token and post a plain test message; exit.
  --demo[=N]             Post ONE full sample announcement (GIF and all); exit.
                         N = how many people share that one day (max 6).
                         Run it repeatedly for different wording and GIFs.
                         Add --dry-run to print it instead of posting.
  --test-gifs            Check every configured GIF URL resolves; exit.
  --check                Health-check everything and print a checklist; exit.
  --resolve-channel=X    Print the channel ID for a #name (or ID); exit.
  --print-timezone       Print the timezone the bot treats as local; exit.
  --is-configured        Exit 0 if a Slack token and channel are set, 1 if
                         not. Prints nothing, touches no network. For
                         deploy scripts deciding whether to enable the timer.
  --force                Post even if today's message already went out.
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
const ANNIV_SWITCHES = ['dry-run', 'force', 'test-slack', 'test-gifs', 'check', 'help', 'is-configured',
                        'print-timezone'];
const ANNIV_OPTIONAL_VALUE = ['list', 'demo'];
const ANNIV_REQUIRES_VALUE = ['date', 'roster-file', 'config', 'resolve-channel'];

$flags = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z][a-z-]*)(?:=(.*))?$/', $arg, $m)) {
        fwrite(STDERR, "Unrecognised argument: {$arg}\n\n" . ANNIV_USAGE);
        exit(1);
    }
    [$name, $value] = [$m[1], $m[2] ?? null];
    $known = array_merge(ANNIV_SWITCHES, ANNIV_OPTIONAL_VALUE, ANNIV_REQUIRES_VALUE);
    if (!in_array($name, $known, true)) {
        fwrite(STDERR, "Unknown option: --{$name}\n\n" . ANNIV_USAGE);
        exit(1);
    }
    if ($value !== null && in_array($name, ANNIV_SWITCHES, true)) {
        fwrite(STDERR, "--{$name} is a switch and takes no value — write it as --{$name}.\n\n"
            . ANNIV_USAGE);
        exit(1);
    }
    if ($value === null && in_array($name, ANNIV_REQUIRES_VALUE, true)) {
        fwrite(STDERR, "--{$name} needs a value, e.g. --{$name}=…\n\n" . ANNIV_USAGE);
        exit(1);
    }
    if ($value !== null && $value === '' && !in_array($name, ANNIV_OPTIONAL_VALUE, true)) {
        fwrite(STDERR, "--{$name} was given an empty value.\n\n" . ANNIV_USAGE);
        exit(1);
    }
    $flags[$name] = $value ?? true;
}
if (array_key_exists('help', $flags)) {
    echo ANNIV_USAGE;
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
 * Settings come from AnniversaryConfig, which layers built-in defaults, then
 * data/anniversary_config.php if it exists, then whatever the Anniversaries
 * page has saved into api_config. So a file-only install keeps working, and
 * anything edited on the page wins.
 *
 * --config=PATH replaces the file layer, for testing against a config that
 * isn't the live one.
 */
$configFile = $root . '/data/anniversary_config.php';
if (!empty($flags['config']) && is_string($flags['config'])) {
    $configFile = $flags['config'];
    if (!is_file($configFile)) {
        fwrite(STDERR, "--config: no such file: {$configFile}\n");
        exit(1);
    }
} elseif (!is_file($configFile)) {
    $configFile = null;
}

$cfg = AnniversaryConfig::load($configFile);
$configPath = $configFile ?? '(defaults + Anniversaries page)';

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
 * timer written as 09:00 fires at 05:00 local unless the zone is pinned onto
 * the calendar line. install.sh reads this to write the timer in the right
 * zone, and it answers the same question by hand.
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
function annivLog(string $msg): void
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
function annivChannelFor(SlackClient $slack, string $configured): array
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
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || annivParseDate($d) === null) {
        fwrite(STDERR, "--date must be a real calendar date in YYYY-MM-DD form.\n");
        exit(1);
    }
    $target = $d;
}

/**
 * A --date that isn't today is a REHEARSAL.
 *
 * It always posts and is deliberately not recorded — neither in the
 * already-posted list nor in the run record. Recording it would mark that date
 * "already done", and on the real morning the bot would find the job finished
 * and say nothing: the test would silently cancel the very message it was
 * checking.
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
$mode = (string)($cfg['celebrate_years'] ?? 'all');
if (!in_array($mode, ['all', 'milestones'], true)) {
    fwrite(STDERR, "celebrate_years must be one of: all, milestones.\n");
    exit(1);
}
$milestones = annivMilestoneYears($cfg['milestone_years'] ?? null);

/** Everything that decides WHO is congratulated on a given day. */
$pickOpts = [
    'leap_mode'       => $leapMode,
    'min_years'       => max(1, (int)($cfg['min_years'] ?? ANNIV_DEFAULT_MIN_YEARS)),
    'mode'            => $mode,
    'milestone_years' => $milestones,
];

/**
 * The master switch: "Post anniversary messages" on the Anniversaries page, or
 * `enabled` in the config file.
 *
 * The guard itself sits before the roster read (below), not here, so --demo and
 * --check still work with it off: --check is exactly how you find out WHY the
 * channel went quiet, and it cannot answer that if the switch stops it running.
 */
$enabled = !array_key_exists('enabled', $cfg) || (bool)$cfg['enabled'];

// ---------------------------------------------------------------------------
// Recording what this run did
// ---------------------------------------------------------------------------

$statePath     = (string)($cfg['state_file'] ?? ($root . '/data/anniversary_state.json'));
$heartbeatPath = (string)($cfg['heartbeat_file'] ?? ($root . '/data/.heartbeat_anniversaries'));
$lockPath      = (string)($cfg['lock_file'] ?? ($root . '/data/anniversary.lock'));

/**
 * Leave the two marks that say this run happened, and what it did.
 *
 * Called from EVERY exit on the real path — including the failures. That is the
 * point: a run that could not reach MSSQL is the run most worth reporting, and
 * it is exactly the one that would otherwise leave nothing behind but a log
 * line.
 *
 * The heartbeat is written even for a failure, because it answers a different
 * question ("did the timer fire") from the outcome ("did it work"). Losing that
 * distinction would make a broken token look identical to a dead timer.
 *
 * @param string $outcome posted | idle | disabled | failed
 */
function annivRecordRun(string $outcome, string $detail = '', int $count = 0): void
{
    global $willRecord, $statePath, $heartbeatPath, $target;
    if (!$willRecord) {
        return;   // a dry run, a listing or a rehearsal is not this morning's firing
    }
    annivHeartbeatWrite($heartbeatPath);
    $state = annivStateRecordRun(annivStateLoad($statePath), $target, $outcome, $detail, $count);
    if (!annivStateSave($statePath, $state)) {
        annivLog('WARNING: could not write the run record at ' . $statePath . '.');
    }
}

/**
 * Put the run in the app's own audit trail, beside every other action.
 *
 * Only real events land here — a posted message or a failure. A row for each of
 * the ~300 days a year when nobody has an anniversary would bury them; the
 * heartbeat already covers "it ran and there was nothing to do".
 *
 * Best-effort by design: the message has already gone out by the time this is
 * called, and a locked database must not turn a delivered post into a failed
 * run.
 */
function annivAuditLog(string $action, bool $success, string $details, string $error = ''): void
{
    global $willRecord;
    if (!$willRecord) {
        return;
    }
    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details, error_message)
             VALUES (:p0, :p1, :p2, :p3, :p4)',
            ['anniversaries', $action, $success ? 1 : 0,
             mb_substr($details, 0, 500), $error === '' ? null : mb_substr($error, 0, 500)]
        );
    } catch (Exception $e) {
        annivLog('NOTE: could not write the audit row (' . $e->getMessage() . ').');
    }
}

/**
 * A wrong hire-date COLUMN is the one failure this bot has that the birthday
 * bot doesn't, and MSSQL's own wording ("Invalid column name 'DateOfHire'")
 * doesn't say what to do about it. Point at the tool that answers it.
 */
function annivColumnHint(string $error): string
{
    if (stripos($error, 'invalid column') === false) {
        return '';
    }
    return ' The roster query names a column this database does not have. Run '
        . '`php anniversaries/discover.php` (or birthdays/run.sh-style: '
        . '`bash anniversaries/run.sh discover`) — it finds the real hire-date '
        . 'column and prints a ready-to-paste query.';
}

/** Record a failure, say why on stderr, and stop. */
function annivFail(string $detail, int $code = 2): void
{
    annivRecordRun('failed', $detail);
    annivAuditLog('anniversary_failed', false, 'The daily run could not complete.', $detail);
    fwrite(STDERR, $detail . "\n");
    exit($code);
}

// ---------------------------------------------------------------------------
// --demo: post a complete sample announcement
//
// --test-slack proves delivery with a plain line of text. This shows the real
// thing — the wording, the GIF, the footer, the reactions — so the format can
// be reviewed before anybody's actual anniversary. It uses placeholder names
// and says it's a preview, and it touches neither the roster nor the
// already-posted record.
// ---------------------------------------------------------------------------

/** Placeholder celebrants. A shared surname reads as obviously synthetic. */
function annivSamplePeople(int $count, array $milestones): array
{
    $firstNames = ['Robin', 'Casey', 'Jordan', 'Riley', 'Avery', 'Quinn'];
    // The first sample is a milestone year, so a single-person demo shows the
    // wording the big years actually get — the half of the pools that is
    // otherwise invisible until somebody's tenth anniversary comes round.
    $years = [$milestones ? $milestones[min(1, count($milestones) - 1)] : 5, 3, 1, 7, 2, 12];
    $out = [];
    for ($i = 0; $i < max(1, min(6, $count)); $i++) {
        $out[] = [
            'emp_no' => '', 'first' => $firstNames[$i], 'last' => 'Sample',
            'name' => $firstNames[$i] . ' Sample', 'hire_date' => '2020-01-01',
            'year' => 2020, 'month' => 1, 'day' => 1, 'email' => '', 'slack_id' => '',
            'years' => $years[$i], 'milestone' => annivIsMilestone($years[$i], $milestones),
        ];
    }
    return $out;
}

if ($doDemo) {
    try {
        $slack = null;
        $channel = trim((string)($cfg['slack_channel'] ?? ''));
        if (!$dryRun) {
            $slack = new SlackClient((string)($cfg['slack_bot_token'] ?? ''), 20, ANNIV_CONFIG_HINT);
            [$channel, $note] = annivChannelFor($slack, (string)($cfg['slack_channel'] ?? ''));
            if ($note !== '') { annivLog($note); }
        }

        // --demo=3 previews a shared day, which uses a different pool and a
        // different name list (each name carrying its own year count).
        $howMany = is_string($flags['demo']) ? (int)$flags['demo'] : 1;
        $sample = annivSamplePeople(max(1, min(6, $howMany)), $milestones);

        // Same wording rules as the real post — see annivMessageConfig(). The
        // ping prefix is the one deliberate difference: a sample must never
        // @-here a channel.
        $msgCfg = annivMessageConfig($cfg, ['mention' => '']);

        // Vary the seed per run so repeated demos show different wording and
        // GIFs. Microtime plus randomness, not the second: two demos fired back
        // to back would otherwise land in the same second, pick the same pair,
        // and look like the variety isn't working.
        $seed = 'demo|' . microtime(true) . '|' . bin2hex(random_bytes(4));
        $text = annivBuildText($sample, $msgCfg, $seed);
        $gif  = GifSource::pick($cfg, $seed);
        annivLog($gif !== null
            ? 'GIF from ' . $gif['source'] . ': ' . $gif['url']
            : 'No GIF resolved — posting without one. Run --test-gifs to see why.');

        $blocks = annivBuildBlocks($text, $gif['url'] ?? null, $cfg);
        $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn',
            'text' => ':wrench: _Preview — this is what the daily post looks like. '
                . ($howMany > 1 ? 'These names are placeholders' : '"Robin Sample" is a placeholder')
                . ', not a real anniversary._']]];

        // --demo --dry-run prints it instead of posting, so the wording can be
        // checked without putting anything in the channel.
        if ($dryRun) {
            echo "\n--- DRY RUN — nothing was posted ---\n";
            echo "\nchannel: " . $channel . "  |  " . count($sample) . " celebrant(s)\n";
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
        annivLog('Posted the sample announcement to ' . $channel . ' (ts ' . $ts . ').');
        if ($slack->customizeDropped() !== '') { annivLog('WARNING: ' . $slack->customizeDropped()); }

        if (!empty($cfg['add_reactions']) && $ts !== '') {
            $added = 0;
            foreach ((array)($cfg['reactions'] ?? ['tada', 'clap']) as $e) {
                if ($slack->addReaction($channel, $ts, (string)$e)) { $added++; }
            }
            annivLog($added > 0
                ? "Added {$added} reaction(s)."
                : 'Reactions were NOT added — the token is probably missing reactions:write '
                  . '(add it and REINSTALL the app, or set add_reactions to false).');
        }
        echo "\nThat is exactly what a real anniversary post looks like.\n"
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
        $slack = new SlackClient((string)($cfg['slack_bot_token'] ?? ''), 20, ANNIV_CONFIG_HINT);
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
    echo "\nWork-anniversary bot health check\n" . str_repeat('=', 62) . "\n";
    $row('Config', 'ok', $configPath);
    // The zone the bot calls local. systemd fires on the SYSTEM zone, which can
    // be a different one — printing this is how the gap becomes visible when
    // you put it beside `systemctl list-timers`.
    $row('Clock', 'ok', ($tz !== '' ? $tz : date_default_timezone_get())
        . ' — ' . date('H:i') . ' now (systemd fires on the system zone: '
        . 'compare with systemctl list-timers)');
    // First row after the config, because when this is off nothing below it
    // matters — every other line can be green and the channel still silent.
    $row('Posting', $enabled ? 'ok' : 'OFF',
        $enabled ? 'messages are on' : 'posting is switched off — nothing will post');

    // Which anniversaries would be posted at all. A milestone-only bot with an
    // empty milestone list is perfectly configured and can never say anything,
    // which is exactly the sort of silence this check exists to catch.
    if ($mode === 'milestones') {
        if (!$milestones) {
            $row('Which years', 'FAIL', 'milestone-only, but no milestone years are set');
            $problems[] = 'Posting is set to milestone years only, and the milestone list is empty, '
                . 'so nothing can ever post. Add years, or switch to "Every year".';
        } else {
            $row('Which years', 'ok', 'milestones only: ' . implode(', ', $milestones));
        }
    } else {
        $row('Which years', 'ok', 'every year from ' . $pickOpts['min_years']
            . ($milestones ? '; louder at ' . implode(', ', $milestones) : '; no milestone years set'));
    }

    // Everything below this line checks whether the bot COULD work. This one
    // checks whether it actually has been — the question the others can't
    // answer, and the one that matters when an anniversary has already been
    // missed.
    $health = annivRunHealth(annivStateLoad($statePath), annivHeartbeatRead($heartbeatPath));
    $row('Last run', in_array($health['status'], ['ok', 'idle', 'off'], true)
        ? ($health['status'] === 'ok' ? 'ok' : '-') : strtoupper($health['status']),
        $health['detail']);
    if ($health['status'] === 'fail') {
        $problems[] = 'Last run: ' . $health['detail'];
    } elseif ($health['status'] === 'warn') {
        $problems[] = 'The bot has missed a daily firing — ' . $health['detail'];
    }
    foreach (AnniversaryConfig::warnings() as $w) {
        $row('Stored value', 'FAIL', substr($w, 0, 70));
        $problems[] = $w;
    }

    // -- MSSQL ------------------------------------------------------------
    $drivers = MssqlClient::availableDrivers();
    if (!$drivers) {
        $row('MSSQL driver', 'FAIL', 'none installed — run via anniversaries/run.sh');
        $problems[] = 'No MSSQL driver. Run the bot through anniversaries/run.sh so it uses the pdo_dblib image.';
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
                $problems[] = 'roster_sql still has TODO_CONFIRM_EMPLOYMENT_FILTER in it — '
                    . 'run `bash anniversaries/run.sh discover` and replace that line.';
            } else {
                try {
                    $c = new MssqlClient();
                    $c->setTimeout(max(5, (int)($cfg['query_timeout'] ?? 30)));
                    $t0 = microtime(true);
                    $rr = $c->rows($rosterSql, max(1, (int)($cfg['roster_max_rows'] ?? 5000)));
                    $ms = (int)round((microtime(true) - $t0) * 1000);
                    $nn = annivNormalizeRoster($rr, [
                        'today'             => $today,
                        'ignore_hire_dates' => (array)($cfg['ignore_hire_dates'] ?? []),
                        'exclude_emp_nos'   => (array)($cfg['exclude_emp_nos'] ?? []),
                        'exclude_names'     => (array)($cfg['exclude_names'] ?? []),
                    ]);
                    $n = count($nn['people']);
                    if ($n === 0) {
                        $row('Roster query', 'FAIL', count($rr) . ' rows, but 0 usable hire dates');
                        $problems[] = 'The roster query returns rows but no usable hire dates — check it '
                            . 'selects the hire-date column and aliases it `hire_date` '
                            . '(php anniversaries/discover.php finds it).';
                    } else {
                        $row('Roster query', 'ok', $n . ' current employees with a hire date (' . $ms . 'ms)');
                    // The headcount above only means something if you can see WHAT made
                    // those people 'current'. Without this row an operator has to read
                    // the SQL to find out whether leavers are excluded at all.
                    $emp = RosterGuard::employmentFilter($rosterSql);
                    $row('Still employed', $emp['ok'] ? 'ok' : 'WARN', $emp['summary']);
                    if (!$emp['ok']) {
                        $problems[] = 'Roster query: ' . $emp['summary'] . '.';
                    }
                        $up = annivUpcoming($nn['people'], $today, 60, $pickOpts);
                        $next = $up ? array_key_first($up) : null;
                        $todayHits = annivCelebrants($nn['people'], $today, $pickOpts);
                        $row('Today', $todayHits ? 'ok' : '-',
                            $todayHits ? count($todayHits) . ' anniversary/ies' : 'nobody today');
                        $row('Next up', $next ? 'ok' : '-',
                            $next ? $next . ' (' . count($up[$next]) . ' person/people)' : 'none in 60 days');
                    }
                } catch (Exception $e) {
                    $row('Roster query', 'FAIL', substr($e->getMessage(), 0, 60));
                    $problems[] = 'Could not run the roster query: ' . $e->getMessage()
                        . annivColumnHint($e->getMessage());
                }
            }
        }
    }

    // -- Slack ------------------------------------------------------------
    try {
        $sc = new SlackClient((string)($cfg['slack_bot_token'] ?? ''), 20, ANNIV_CONFIG_HINT);
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
            : "Everything is wired up correctly, BUT posting is switched OFF, so\n"
              . "nothing will post. Turn \"Post anniversary messages\" back on to resume.\n\n";
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
// this is the check that says whether it still works. The bot also verifies the
// one GIF it is about to use at post time, so a dead entry degrades to "no
// image" rather than a broken one — this just tells you up front.
// ---------------------------------------------------------------------------

if ($testGifs) {
    $timeout = max(2, (int)($cfg['gif_timeout'] ?? 6));
    $list = array_values(array_filter(array_map('trim', array_map('strval', (array)$cfg['gifs']))));

    echo "\nGIFs are " . (empty($cfg['gifs_enabled']) ? "OFF (gifs_enabled is false)" : "ON") . ".\n";

    $key = trim((string)($cfg['giphy_api_key'] ?? ''));
    if ($key !== '') {
        $terms = array_values(array_filter(array_map('strval', (array)$cfg['gif_search_terms'])));
        $term = $terms[0] ?? 'work anniversary';
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
           . "giphy_api_key, or replace the GIF list on the Anniversaries page with URLs\n"
           . "of your own (any public https .gif). Messages still post without an image.\n\n";
        exit(1);
    }
    if ($good < count($list)) {
        echo "Prune the dead ones from the GIF list to keep the rotation varied.\n";
    }
    echo "\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// --test-slack: prove the token and the channel before trusting the timer
// ---------------------------------------------------------------------------

if ($testSlack) {
    try {
        $slack = new SlackClient((string)($cfg['slack_bot_token'] ?? ''), 20, ANNIV_CONFIG_HINT);
        $who = $slack->authTest();
        annivLog("Slack auth OK — workspace '{$who['team']}', bot '{$who['user']}'.");
        [$channel, $note] = annivChannelFor($slack, (string)($cfg['slack_channel'] ?? ''));
        if ($note !== '') { annivLog($note); }
        $slack->postMessage($channel, ':wave: Work-anniversary bot test — this channel is wired up correctly.', [
            'username'   => (string)($cfg['bot_username'] ?? ''),
            'icon_emoji' => (string)($cfg['bot_icon_emoji'] ?? ''),
        ]);
        annivLog("Test message delivered to {$channel}.");
        if ($slack->customizeDropped() !== '') { annivLog('WARNING: ' . $slack->customizeDropped()); }
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
// nor Slack to be working. --list and --dry-run deliberately still run: both
// only report, and being shown what WOULD go out is precisely what somebody
// about to switch it back on wants to see.
// ---------------------------------------------------------------------------

if (!$enabled) {
    $why = 'Anniversary messages are turned OFF ("Post anniversary messages" on the '
         . 'Anniversaries page, or `enabled` in the config file).';
    if (!$doList && !$dryRun) {
        annivRecordRun('disabled', 'Posting is switched off.');
        annivLog($why . ' Nothing was posted.');
        exit(0);
    }
    annivLog('NOTE: ' . $why . ' This run only reports — nothing would post.');
}

// ---------------------------------------------------------------------------
// The run lock
//
// Taken before the roster read so two overlapping runs cannot both decide
// nobody has been congratulated yet and both post. The timer is Persistent, it
// carries catch-up firings, and somebody can always start a run by hand, so the
// overlap is real rather than theoretical.
//
// A second run exits quietly and successfully: whoever holds the lock is
// already doing this morning's job, and reporting a failure for that would be a
// false alarm. It leaves no run record either — the holder writes it.
// ---------------------------------------------------------------------------

$lock = null;
if ($willPost) {
    $lock = annivLockAcquire($lockPath);
    if ($lock === false) {
        annivLog('Another anniversary-bot run is already in progress — leaving it to that one.');
        exit(0);
    }
    if ($lock === null) {
        // Could not open the lock file at all. Carrying on unlocked risks a
        // duplicate; stopping guarantees a missed anniversary. Take the lesser.
        annivLog('WARNING: could not open the lock file at ' . $lockPath
            . ' — continuing without a lock. Two runs at once could double-post.');
    }
}
register_shutdown_function(function () {
    global $lock;
    annivLockRelease($lock);
});

// ---------------------------------------------------------------------------
// Roster
// ---------------------------------------------------------------------------

if ($rosterFile !== '') {
    // Testing aid: a JSON array of row objects shaped exactly like the SQL
    // result. Lets the message wording, the milestone rules and the exclusion
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
    annivLog('Roster read from ' . $rosterFile . ' (test mode — MSSQL not used).');
} else {
    $rosterSql = trim((string)($cfg['roster_sql'] ?? ''));
    if ($rosterSql === '') {
        annivFail('roster_sql is empty — run `php anniversaries/discover.php` to generate one.', 1);
    }
    // discover.php emits this marker where the employment filter belongs when
    // it cannot find one to decode. Refusing to run while it is still there is
    // the one check that cannot be satisfied by accident: congratulating
    // somebody who left last year on their years of service is worse than
    // saying nothing at all.
    if (stripos($rosterSql, 'TODO_CONFIRM_EMPLOYMENT_FILTER') !== false) {
        fwrite(STDERR,
            "That marker stands where the \"still employed\" filter belongs. Run\n"
            . "`bash anniversaries/run.sh discover` to find which column and value mean\n"
            . "current staff, then replace the marker line in the roster query.\n");
        annivFail('roster_sql still contains TODO_CONFIRM_EMPLOYMENT_FILTER, '
            . 'so the "still employed" filter has never been filled in.', 1);
    }
    // The employment filter is the only thing between "today's celebrants" and
    // "everyone who ever worked here", and it lives in an editable query — so
    // say out loud, every run, what is actually enforcing it.
    $employment = RosterGuard::employmentFilter($rosterSql);
    if ($employment['ok']) {
        annivLog('Employment filter: ' . $employment['summary'] . '.');
    } else {
        annivLog('WARNING: ' . $employment['summary'] . '. People who have left may be congratulated.');
    }

    try {
        MssqlClient::assertReadOnly($rosterSql);
    } catch (RuntimeException $e) {
        annivFail('roster_sql was rejected by the read-only guard: ' . $e->getMessage(), 1);
    }

    if (!MssqlClient::availableDrivers()) {
        annivFail('No MSSQL PDO driver in this PHP runtime — run the bot inside the '
            . 'pdo_dblib overlay image the app already uses (see anniversaries/README.md).');
    }
    if (!MssqlClient::isConfigured()) {
        annivFail('MSSQL is not configured in the app yet (Go-Kart Labor page -> Settings).');
    }

    try {
        $client = new MssqlClient();
        $client->setTimeout(max(5, (int)($cfg['query_timeout'] ?? 30)));
        $rows = $client->rows($rosterSql, max(1, (int)($cfg['roster_max_rows'] ?? 5000)));
    } catch (Exception $e) {
        annivFail('Could not read the roster: ' . $e->getMessage() . annivColumnHint($e->getMessage()));
    }
}

$norm = annivNormalizeRoster($rows, [
    'today'             => $today,
    'ignore_hire_dates' => (array)($cfg['ignore_hire_dates'] ?? []),
    'exclude_emp_nos'   => (array)($cfg['exclude_emp_nos'] ?? []),
    'exclude_names'     => (array)($cfg['exclude_names'] ?? []),
]);
$people  = $norm['people'];
$skipped = $norm['skipped'];

annivLog(sprintf(
    'Roster: %d rows -> %d people with a usable hire date (skipped: %d no date, %d unreadable, '
    . '%d placeholder date, %d not started yet, %d no name, %d opted out, %d duplicate).',
    count($rows), count($people), $skipped['no_hire_date'], $skipped['unparsed'],
    $skipped['sentinel'], $skipped['future'], $skipped['no_name'], $skipped['excluded'],
    $skipped['duplicate']
));

if ($rows && !$people) {
    annivFail('The roster query returned ' . count($rows) . ' rows but none had a usable '
        . 'hire date — check it selects the hire-date column and aliases it `hire_date` '
        . '(php anniversaries/discover.php finds it).', 1);
}
if ($norm['sentinel_hits']) {
    $top = [];
    foreach (array_slice($norm['sentinel_hits'], 0, 3, true) as $date => $n) {
        $top[] = "{$date} x{$n}";
    }
    annivLog('Ignored placeholder hire dates: ' . implode(', ', $top) . '.');
}

// ---------------------------------------------------------------------------
// --list: eyeball the data without posting anything
// ---------------------------------------------------------------------------

if ($doList) {
    $upcoming = annivUpcoming($people, $target, $listDays, $pickOpts);
    echo "\nWork anniversaries in the next {$listDays} days from {$target}:\n\n";
    if (!$upcoming) {
        echo "  (none)\n\n";
        exit(0);
    }
    foreach ($upcoming as $date => $hits) {
        $names = [];
        foreach ($hits as $p) {
            $label = annivDisplayName($p, $nameStyle) . ' (' . annivYearLabel((int)$p['years']) . ')';
            if (!empty($p['milestone'])) {
                $label .= ' *';
            }
            if ($p['month'] === 2 && $p['day'] === 29 && substr($date, 5) !== '02-29') {
                $label .= ' [29 Feb]';
            }
            $names[] = $label;
        }
        printf("  %s  %s  %s\n", $date, date('D', strtotime($date . ' 12:00:00')), implode(', ', $names));
    }
    echo "\n  * = a milestone year\n\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Today's celebrants
// ---------------------------------------------------------------------------

$celebrants = annivCelebrants($people, $target, $pickOpts);
if (!$celebrants) {
    // Recorded, because "ran and there was nobody" and "never ran" look
    // identical in the channel and are completely different problems.
    annivRecordRun('idle', 'Nobody had an anniversary.');
    annivLog("No work anniversaries on {$target}. Nothing to post.");
    exit(0);
}

$max = max(1, (int)($cfg['max_celebrants'] ?? 25));
if (count($celebrants) > $max) {
    fwrite(STDERR,
        "Two things this can be, and they need opposite fixes:\n"
        . "  - A HIRING COHORT. A seasonal venue onboards in waves, so a dozen people\n"
        . "    sharing an anniversary is normal. Raise \"Refuse to post above\"\n"
        . "    (max_celebrants) past your biggest cohort — `run.sh discover` measures it.\n"
        . "  - A PLACEHOLDER DATE the POS stamped on everyone, or the query reading the\n"
        . "    wrong column. Add the date to \"Ignore these hire dates\", or fix the query.\n"
        . "`bash anniversaries/run.sh discover` tells you which of the two it is.\n");
    annivFail(sprintf(
        'Refused to post: %d people share an anniversary on %s, over the max_celebrants limit of %d.',
        count($celebrants), $target, $max
    ), 1);
}

$state = annivStateLoad($statePath);

if ($isRehearsal) {
    annivLog('Rehearsing ' . $target . ' — this run will NOT be recorded, so the real '
        . 'message on that day still goes out.');
}

if (!$force && !$isRehearsal) {
    $pending = [];
    foreach ($celebrants as $p) {
        if (!annivStateHas($state, $target, $p)) {
            $pending[] = $p;
        }
    }
    if (!$pending) {
        // The heartbeat moves (a catch-up firing that found nothing to do is
        // still the timer proving it is alive), but the OUTCOME deliberately
        // does not: an earlier run today already recorded 'posted', and
        // overwriting that would turn a delivered message into a blander record
        // of the run that found it already done.
        if ($willRecord) {
            annivHeartbeatWrite($heartbeatPath);
        }
        annivLog(sprintf('Already posted all %d anniversary message(s) for %s. Nothing to do (use --force to repost).',
            count($celebrants), $target));
        exit(0);
    }
    $celebrants = $pending;
}

$names = [];
foreach ($celebrants as $p) {
    $names[] = annivDisplayName($p, $nameStyle) . ' — ' . annivYearLabel((int)$p['years'])
        . ($p['emp_no'] !== '' ? " (#{$p['emp_no']})" : '');
}
annivLog('Anniversary/ies on ' . $target . ': ' . implode(', ', $names) . '.');

// ---------------------------------------------------------------------------
// Optional @-mentions
// ---------------------------------------------------------------------------

$slack = null;
if (!$dryRun || !empty($cfg['mention_by_email'])) {
    try {
        $slack = new SlackClient((string)($cfg['slack_bot_token'] ?? ''), 20, ANNIV_CONFIG_HINT);
    } catch (RuntimeException $e) {
        if (!$dryRun) {
            annivFail($e->getMessage(), 1);
        }
        annivLog('Note: ' . $e->getMessage() . ' (dry run continues without Slack.)');
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
            // Never let a lookup problem cost someone their message — fall back
            // to plain names and make the reason loud in the log.
            annivLog('WARNING: Slack email lookup failed (' . $e->getMessage()
                . '). Posting with plain names instead. The users:read.email scope is '
                . 'required for mention_by_email, and the app must be REINSTALLED after adding it.');
            break;
        }
    }
    annivLog("Resolved {$resolved} of " . count($celebrants) . ' celebrant(s) to Slack users.');
}

// ---------------------------------------------------------------------------
// Build and post
// ---------------------------------------------------------------------------

// Every wording setting, assembled in the one place that knows the full list,
// so what --demo and the Anniversaries page preview show is what actually posts.
$msgCfg = annivMessageConfig($cfg);

$batches = !empty($cfg['post_separately'])
    ? array_map(function ($p) { return [$p]; }, $celebrants)
    : [$celebrants];

$messages = [];
foreach ($batches as $batch) {
    // One seed per message, from the date and the people in it. Everything
    // random-looking downstream (which wording, which GIF) derives from this,
    // so a dry run previews EXACTLY what the real run will post, and re-running
    // never quietly changes the message.
    $seed = annivSeedFor($target, $batch);
    $text = annivBuildText($batch, $msgCfg, $seed);
    $gif  = GifSource::pick($cfg, $seed);
    $messages[] = [
        'people' => $batch,
        'text'   => $text,
        'gif'    => $gif,
        'blocks' => annivBuildBlocks($text, $gif['url'] ?? null, $cfg),
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
    [$channel, $chanNote] = annivChannelFor($slack, (string)($cfg['slack_channel'] ?? ''));
} catch (RuntimeException $e) {
    annivFail($e->getMessage(), 1);
}
if ($chanNote !== '') { annivLog($chanNote); }

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
        annivLog('Posted to ' . $channel . ' (ts ' . $ts . ')'
            . ($m['gif'] !== null ? ' with a GIF from ' . $m['gif']['source'] : ' without a GIF') . '.');
        // A message that only landed on the second attempt still landed, but it
        // says something about the network at 09:00 and is worth the line.
        foreach ($slack->retryNotes() as $note) { annivLog('NOTE: ' . $note); }
        if ($slack->customizeDropped() !== '') { annivLog('WARNING: ' . $slack->customizeDropped()); }

        // Seed the reactions so nobody has to be first. Best-effort: a missing
        // reactions:write scope must not turn a delivered message into a
        // failure, so addReaction() reports rather than throws.
        if (!empty($cfg['add_reactions']) && $ts !== '') {
            $emojis = (array)($cfg['reactions'] ?? ['tada', 'clap']);
            $added = 0;
            foreach ($emojis as $emoji) {
                if ($slack->addReaction($channel, $ts, (string)$emoji)) { $added++; }
            }
            if ($added < count($emojis)) {
                annivLog('Note: ' . (count($emojis) - $added) . ' reaction(s) were not added '
                    . '(reactions:write scope missing, or an unknown emoji name).');
            }
        }
    } catch (Exception $e) {
        $failed++;
        $errors[] = $e->getMessage();
        foreach ($slack->retryNotes() as $note) { annivLog('NOTE: ' . $note); }
        annivLog('ERROR: ' . $e->getMessage());
    }
    if ($idx < count($messages) - 1) {
        usleep(1200000); // chat.postMessage is ~1/sec per channel
    }
}

$postedNames = [];
foreach ($posted as $p) {
    $postedNames[] = annivDisplayName($p, $nameStyle) . ' (' . annivYearLabel((int)$p['years']) . ')';
}

// Record only the people whose message actually went out, so a partial failure
// is retried on the next run instead of being silently swallowed.
if ($posted && $isRehearsal) {
    annivLog('Rehearsal — not recorded.');
} elseif ($posted) {
    $state = annivStateMark($state, $target, $posted);
    $state = annivStatePrune($state, $today, 45);
    $state = annivStateRecordRun(
        $state, $target,
        $failed > 0 ? 'failed' : 'posted',
        $failed > 0
            ? sprintf('%d of %d message(s) failed: %s', $failed, count($messages),
                      implode('; ', $errors))
            : 'to ' . $channel,
        count($posted)
    );
    if (!annivStateSave($statePath, $state)) {
        annivLog('WARNING: could not write the state file at ' . $statePath
            . ' — a re-run today would post again.');
    }
    // Unconditional: reaching here means something was delivered on the real
    // path, which is by definition this morning's firing.
    annivHeartbeatWrite($heartbeatPath);
    annivAuditLog(
        $failed > 0 ? 'anniversary_failed' : 'anniversary_posted',
        $failed === 0,
        'Congratulated ' . count($posted) . ' in ' . $channel . ': ' . implode(', ', $postedNames),
        $failed > 0 ? implode('; ', $errors) : ''
    );
}

if ($failed > 0) {
    // Nothing at all got through: the run record has to say so, since the
    // branch above only fires when SOMETHING was delivered.
    if (!$posted) {
        annivFail(sprintf('All %d anniversary message(s) failed to post: %s',
            $failed, implode('; ', $errors)));
    }
    fwrite(STDERR, "{$failed} message(s) failed to post.\n");
    exit(2);
}
annivLog('Done.');
exit(0);
