<?php
/**
 * Anniversaries API — the Anniversaries page's backend.
 *
 * Reads and writes the work-anniversary bot's settings through
 * AnniversaryConfig (defaults <- the optional config file <- api_config), and
 * runs the same health check, preview and test actions the CLI offers,
 * in-process. The web container is the one carrying the MSSQL driver, so the
 * roster query runs here exactly as it does from the timer — a green check on
 * this page means the nightly run works.
 *
 * Secrets are write-only: a token can be replaced or cleared, never read back.
 *
 * The sibling of api/birthdays.php. The Slack client and the GIF picker are the
 * birthday bot's, shared deliberately (see the header of each file); everything
 * else here is this bot's own.
 */

require_once __DIR__ . '/../lib/anniversary_config.php';
require_once __DIR__ . '/../lib/mssql_client.php';
require_once __DIR__ . '/../lib/roster_guard.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/today_cache.php';
require_once __DIR__ . '/../anniversaries/lib/anniv_lib.php';
require_once __DIR__ . '/../birthdays/lib/slack_client.php';
require_once __DIR__ . '/../birthdays/lib/gif_source.php';

/** Where an operator sets this bot's token, for SlackClient's "no token" error. */
const ANNIV_API_CONFIG_HINT = 'the Anniversaries page in CEplay, or in data/anniversary_config.php';

/** The optional config file, or null when there isn't one. */
function annivConfigFile(): ?string
{
    $p = dirname(__DIR__) . '/data/anniversary_config.php';
    return is_file($p) ? $p : null;
}

function handleAnniversaries(string $method, array $parts, ?array $input): void
{
    Auth::requireAccess('view_anniversaries');
    $action = $parts[0] ?? '';

    if ($action === 'upcoming') {
        if ($method !== 'GET') { annivApi405(); return; }
        annivApiUpcoming();
        return;
    }
    if ($action === 'today') {
        if ($method !== 'GET') { annivApi405(); return; }
        annivApiToday();
        return;
    }
    if ($action === 'test') {
        if ($method !== 'POST') { annivApi405(); return; }
        Auth::requireAccess('anniversaries_manage');
        annivApiTest($input ?? []);
        return;
    }
    if ($action !== '') { http_response_code(404); echo json_encode(['error' => 'Not found']); return; }

    switch ($method) {
        case 'GET':
            annivApiGetConfig();
            break;
        case 'PUT':
            Auth::requireAccess('anniversaries_manage');
            annivApiPutConfig($input ?? []);
            break;
        default:
            annivApi405();
    }
}

function annivApi405(): void
{
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function annivApiAudit(string $action, string $detail): void
{
    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details) VALUES (:p0, :p1, 1, :p2)',
            ['manual', 'anniversary_' . $action,
             (Auth::check()['username'] ?? '?') . ': ' . substr($detail, 0, 300)]
        );
    } catch (Exception $e) {
        error_log('anniversary audit log failed: ' . $e->getMessage());
    }
}

/** How this bot picks celebrants — one place, so the page and the CLI agree. */
function annivApiPickOpts(array $cfg): array
{
    return [
        'leap_mode'       => (string)($cfg['leap_day_mode'] ?? 'feb28'),
        'min_years'       => max(1, (int)($cfg['min_years'] ?? ANNIV_DEFAULT_MIN_YEARS)),
        'mode'            => (string)($cfg['celebrate_years'] ?? 'all'),
        'milestone_years' => annivMilestoneYears($cfg['milestone_years'] ?? null),
    ];
}

/** Settings, the field schema the form renders from, and where they came from. */
function annivApiGetConfig(): void
{
    $file = annivConfigFile();
    $fields = [];
    foreach (AnniversaryConfig::FIELDS as $key => $spec) {
        $fields[$key] = [
            'type'    => $spec['type'],
            'label'   => $spec['label'] ?? $key,
            'help'    => $spec['help'] ?? '',
            'options' => $spec['options'] ?? null,
            'min'     => $spec['min'] ?? null,
            'max'     => $spec['max'] ?? null,
        ];
    }
    echo json_encode([
        'config'   => AnniversaryConfig::publicConfig($file),
        'fields'   => $fields,
        'defaults' => [
            'roster_sql'          => AnniversaryConfig::DEFAULT_ROSTER_SQL,
            'greetings'           => ANNIV_GREETINGS,
            'flavors'             => ANNIV_FLAVORS,
            'milestone_greetings' => ANNIV_MILESTONE_GREETINGS,
            'milestone_flavors'   => ANNIV_MILESTONE_FLAVORS,
            'multi_greetings'     => ANNIV_MULTI_GREETINGS,
            'multi_flavors'       => ANNIV_MULTI_FLAVORS,
            'milestone_years'     => array_map('strval', ANNIV_DEFAULT_MILESTONES),
            'gifs'                => ANNIV_DEFAULT_GIFS,
        ],
        'config_file' => $file,
        'can_manage'  => Auth::hasPermission('anniversaries_manage'),
    ]);
}

/** Save whatever the form sent, validating each field against its type. */
function annivApiPutConfig(array $input): void
{
    $saved = [];
    foreach (AnniversaryConfig::FIELDS as $key => $spec) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = $input[$key];

        switch ($spec['type']) {
            case 'bool':
                AnniversaryConfig::set($key, (bool)$value);
                break;
            case 'int':
                $n = (int)$value;
                $min = $spec['min'] ?? PHP_INT_MIN;
                $max = $spec['max'] ?? PHP_INT_MAX;
                if ($n < $min || $n > $max) {
                    throw new RuntimeException($spec['label'] . " must be between {$min} and {$max}.");
                }
                AnniversaryConfig::set($key, $n);
                break;
            case 'enum':
                if (!isset($spec['options'][(string)$value])) {
                    throw new RuntimeException('Invalid choice for ' . $spec['label'] . '.');
                }
                AnniversaryConfig::set($key, (string)$value);
                break;
            case 'list':
                $list = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string)$value);
                $list = array_values(array_filter(array_map('trim', array_map('strval', $list)),
                    function ($v) { return $v !== ''; }));
                if (count($list) > 500) {
                    throw new RuntimeException($spec['label'] . ': too many lines (max 500).');
                }
                annivApiValidateList($key, $list);
                AnniversaryConfig::set($key, $list);
                break;
            case 'secret':
                // '' means "leave it alone"; the explicit clear flag wipes it.
                if (!empty($input[$key . '_clear'])) {
                    AnniversaryConfig::clearSecret($key);
                } else {
                    AnniversaryConfig::set($key, (string)$value);
                }
                break;
            case 'text':
                $text = (string)$value;
                if (strlen($text) > 8000) {
                    throw new RuntimeException($spec['label'] . ' is too long (max 8000 characters).');
                }
                if ($key === 'roster_sql' && trim($text) !== '') {
                    // Same guards the CLI applies: one plain SELECT, and no
                    // discover.php marker left standing where the "still
                    // employed" filter belongs.
                    MssqlClient::assertReadOnly($text);
                    if (stripos($text, 'TODO_CONFIRM_EMPLOYMENT_FILTER') !== false) {
                        throw new RuntimeException(
                            'The roster query still has TODO_CONFIRM_EMPLOYMENT_FILTER in it — '
                            . 'replace that line with the condition that means "still employed".');
                    }
                }
                AnniversaryConfig::set($key, $text);
                break;
            default:
                $str = (string)$value;
                if (strlen($str) > 500) {
                    throw new RuntimeException($spec['label'] . ' is too long.');
                }
                AnniversaryConfig::set($key, $str);
        }
        $saved[] = $key;
    }

    annivApiAudit('settings', 'updated ' . (count($saved) ?: 0) . ' setting(s): ' . implode(', ', $saved));
    echo json_encode(['success' => true, 'saved' => $saved,
                      'config' => AnniversaryConfig::publicConfig(annivConfigFile())]);
}

/**
 * Per-field rules that a type check alone can't express.
 *
 * The {ordinal} rule is the one worth reading: with several people sharing a
 * day there is no single ordinal that is true of the group, so annivBuildText()
 * resolves it to nothing. A shared-day line written with it in would silently
 * lose a word — better to say so at save time than to post "Happy  anniversary".
 */
function annivApiValidateList(string $key, array $list): void
{
    if (in_array($key, ['greetings', 'milestone_greetings', 'multi_greetings'], true)) {
        foreach ($list as $line) {
            if (strpos($line, '{names}') === false) {
                throw new RuntimeException('Every greeting must contain {names} — this one does not: "'
                    . mb_substr($line, 0, 60) . '"');
            }
        }
    }
    if (in_array($key, ['multi_greetings', 'multi_flavors'], true)) {
        foreach ($list as $line) {
            if (strpos($line, '{ordinal}') !== false) {
                throw new RuntimeException('{ordinal} cannot be used on a shared day — the people '
                    . 'have different year counts, so there is no single ordinal. Use {count} or '
                    . 'the combined {years} instead: "' . mb_substr($line, 0, 60) . '"');
            }
        }
    }
    if ($key === 'milestone_years') {
        foreach ($list as $y) {
            if (!preg_match('/^\d{1,2}$/', $y) || (int)$y < 1) {
                throw new RuntimeException('Milestone years must be whole numbers of 1 or more — got "'
                    . mb_substr($y, 0, 20) . '"');
            }
        }
    }
    if ($key === 'gifs') {
        foreach ($list as $url) {
            if (!GifSource::looksLikeUrl($url)) {
                throw new RuntimeException('Not a usable GIF URL (https:// only): "'
                    . mb_substr($url, 0, 60) . '"');
            }
        }
    }
    if ($key === 'ignore_hire_dates') {
        foreach ($list as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new RuntimeException('Dates must be YYYY-MM-DD — got "' . mb_substr($d, 0, 30) . '"');
            }
        }
    }
    if ($key === 'reactions') {
        foreach ($list as $e) {
            if (!preg_match('/^[a-z0-9_+\-]{1,60}$/i', trim($e, ':'))) {
                throw new RuntimeException('Not an emoji name: "' . mb_substr($e, 0, 30) . '"');
            }
        }
    }
}

/** Read the roster, normalised, or throw with a message worth showing. */
function annivApiRoster(array $cfg): array
{
    if (!MssqlClient::availableDrivers()) {
        throw new RuntimeException('No MSSQL driver in this PHP runtime — the app image needs pdo_dblib.');
    }
    if (!MssqlClient::isConfigured()) {
        throw new RuntimeException('MSSQL is not configured yet (Go-Kart Labor page -> Settings).');
    }
    $sql = trim((string)$cfg['roster_sql']);
    MssqlClient::assertReadOnly($sql);
    if (stripos($sql, 'TODO_CONFIRM_EMPLOYMENT_FILTER') !== false) {
        throw new RuntimeException('The roster query still has TODO_CONFIRM_EMPLOYMENT_FILTER '
            . 'where the "still employed" filter belongs. Until it is replaced the bot would '
            . 'congratulate everyone who ever worked here, so it refuses to run.');
    }

    $client = new MssqlClient();
    $client->setTimeout(max(5, (int)($cfg['query_timeout'] ?? 30)));
    try {
        $rows = $client->rows($sql, max(1, (int)($cfg['roster_max_rows'] ?? 5000)));
    } catch (Exception $e) {
        // A hire-date column that isn't called what the default query calls it
        // is the most likely failure on a fresh install, and MSSQL's own
        // wording doesn't say what to do about it.
        $msg = $e->getMessage();
        if (stripos($msg, 'invalid column') !== false) {
            throw new RuntimeException($msg . ' — the roster query names a column this database '
                . 'does not have. Run `php anniversaries/discover.php` on the venue server to find '
                . 'the real hire-date column, then paste its query in below.');
        }
        throw $e;
    }

    return annivNormalizeRoster($rows, [
        'today'             => date('Y-m-d'),
        'ignore_hire_dates' => (array)($cfg['ignore_hire_dates'] ?? []),
        'exclude_emp_nos'   => (array)($cfg['exclude_emp_nos'] ?? []),
        'exclude_names'     => (array)($cfg['exclude_names'] ?? []),
    ]);
}

/** Who's coming up, and what today's message would say. */
function annivApiUpcoming(): void
{
    $cfg = AnniversaryConfig::load(annivConfigFile());
    $days = Validator::optionalInt(['d' => $_GET['days'] ?? null], 'd', 1, 400) ?? 60;
    $tz = trim((string)($cfg['timezone'] ?? '')) ?: (defined('DEFAULT_TIMEZONE') ? DEFAULT_TIMEZONE : 'UTC');
    $prev = date_default_timezone_get();
    @date_default_timezone_set($tz);
    $today = date('Y-m-d');

    try {
        $norm = annivApiRoster($cfg);
    } catch (Exception $e) {
        @date_default_timezone_set($prev);
        echo json_encode(['error' => $e->getMessage(), 'roster_ok' => false]);
        return;
    }

    $people = $norm['people'];
    $opts  = annivApiPickOpts($cfg);
    $style = (string)($cfg['name_style'] ?? 'full');

    $upcoming = [];
    foreach (annivUpcoming($people, $today, $days, $opts) as $date => $hits) {
        $rows = [];
        foreach ($hits as $h) {
            $rows[] = [
                'name'      => annivDisplayName($h, $style),
                'years'     => (int)$h['years'],
                'milestone' => !empty($h['milestone']),
            ];
        }
        $upcoming[] = [
            'date'     => $date,
            'weekday'  => date('D', strtotime($date . ' 12:00:00')),
            'people'   => $rows,
            'is_today' => $date === $today,
        ];
    }

    // Exactly what would go out today, built the same way the timer builds it.
    $todayHits = annivCelebrants($people, $today, $opts);
    $preview = null;
    if ($todayHits) {
        $seed = annivSeedFor($today, $todayHits);
        $preview = annivBuildText($todayHits, annivMessageConfig($cfg), $seed);
    }

    @date_default_timezone_set($prev);
    echo json_encode([
        'roster_ok'     => true,
        'today'         => $today,
        'timezone'      => $tz,
        'people_count'  => count($people),
        'skipped'       => $norm['skipped'],
        'upcoming'      => $upcoming,
        'today_preview' => $preview,
        'days'          => $days,
        'mode'          => $opts['mode'],
        'min_years'     => $opts['min_years'],
    ]);
}

// ---------------------------------------------------------------------------
// Today's celebrants, for the Command Center chip
//
// The dashboard polls every 30 seconds and is the busiest page in the app, so
// this endpoint must NEVER cost a roster read per request: that is a 5000-row
// MSSQL query behind a 30-second timeout, and on a venue where MSSQL is slow or
// down it would stall the one page an operator watches while running the floor.
//
// So the answer is memoised to a small file in data/ and recomputed only when
// it is genuinely out of date. FAILURES are cached too, and that is the point
// rather than an oversight: without it, an unreachable database would be
// retried on every poll from every open dashboard, each one waiting out the
// full connect timeout.
//
// The cache carries a SIGNATURE of every setting that changes the answer, so
// editing the roster query or the milestone rules on the Anniversaries page
// shows up on the dashboard immediately instead of at the end of a TTL — the
// stale-chip bug that a plain time-based cache would have.
// ---------------------------------------------------------------------------

/**
 * Everything that decides WHO shows up today.
 *
 * Any change to one of these has to invalidate the cache, so they are hashed
 * rather than listed: a new setting added to the list below is the only thing
 * needed to keep the chip honest.
 */
function annivTodaySignature(array $cfg): string
{
    return substr(hash('sha256', json_encode([
        (string)($cfg['roster_sql'] ?? ''),
        (string)($cfg['leap_day_mode'] ?? ''),
        (string)($cfg['celebrate_years'] ?? ''),
        (int)($cfg['min_years'] ?? 0),
        annivMilestoneYears($cfg['milestone_years'] ?? null),
        (string)($cfg['name_style'] ?? ''),
        (array)($cfg['exclude_emp_nos'] ?? []),
        (array)($cfg['exclude_names'] ?? []),
        (array)($cfg['ignore_hire_dates'] ?? []),
        (string)($cfg['timezone'] ?? ''),
    ])), 0, 16);
}

/**
 * Who is celebrating today — a small, cacheable payload for the dashboard.
 *
 * Deliberately the SAME selection the bot would post: same min_years, same
 * milestone mode, same exclusions. A dashboard that listed people Slack said
 * nothing about would just raise "why didn't the bot mention Dana?".
 *
 * `available` false means the roster could not be read (no driver, not
 * configured, query broken). The dashboard renders nothing at all in that case
 * — an accessory that cannot answer must not put an error on the floor's main
 * screen. The Anniversaries page is where that gets diagnosed.
 */
function annivApiToday(): void
{
    $cfg = AnniversaryConfig::load(annivConfigFile());
    $tz = trim((string)($cfg['timezone'] ?? '')) ?: (defined('DEFAULT_TIMEZONE') ? DEFAULT_TIMEZONE : 'UTC');
    $prev = date_default_timezone_get();
    @date_default_timezone_set($tz);
    $today = date('Y-m-d');

    $path = (string)($cfg['today_cache_file'] ?? '');
    $sig  = annivTodaySignature($cfg);
    $force = !empty($_GET['refresh']);

    $cached = $force ? null : TodayCache::read($path, $today, $sig);
    if ($cached !== null) {
        @date_default_timezone_set($prev);
        $cached['cached'] = true;
        echo json_encode($cached);
        return;
    }

    $out = [
        'date'      => $today,
        'timezone'  => $tz,
        'available' => true,
        'reason'    => '',
        'people'    => [],
        'count'     => 0,
        'mode'      => (string)($cfg['celebrate_years'] ?? 'all'),
        'checked_at' => date('c'),
    ];
    try {
        $norm = annivApiRoster($cfg);
        $style = (string)($cfg['name_style'] ?? 'full');
        foreach (annivCelebrants($norm['people'], $today, annivApiPickOpts($cfg)) as $p) {
            $out['people'][] = [
                'name'      => annivDisplayName($p, $style),
                'years'     => (int)$p['years'],
                'milestone' => !empty($p['milestone']),
            ];
        }
        $out['count'] = count($out['people']);
    } catch (Exception $e) {
        $out['available'] = false;
        $out['reason'] = $e->getMessage();
    }

    TodayCache::write($path, $sig, $out);
    @date_default_timezone_set($prev);
    $out['cached'] = false;
    echo json_encode($out);
}

/** Health check, message preview, and the two live Slack tests. */
function annivApiTest(array $input): void
{
    $what = Validator::requireEnum($input, 'action', ['check', 'preview', 'demo', 'test_slack', 'test_gifs']);
    $cfg = AnniversaryConfig::load(annivConfigFile());

    switch ($what) {
        case 'check':      annivApiCheck($cfg); return;
        case 'preview':    annivApiPreview($cfg, $input); return;
        case 'test_gifs':  annivApiTestGifs($cfg); return;
        case 'demo':       annivApiDemo($cfg, $input, true); return;
        case 'test_slack': annivApiDemo($cfg, $input, false); return;
    }
}

/** One pass over every dependency, each with its own verdict. */
function annivApiCheck(array $cfg): void
{
    // Which day is "today" depends on the VENUE's clock, not the web
    // container's — index.php restores the PHP default before dispatching, and
    // on this venue the host runs UTC while the app runs Eastern. Without this,
    // between 20:00 and midnight local the Today and Next-up rows below would
    // be about TOMORROW, disagreeing with /upcoming and /today, which both set
    // the zone. Every handler in this file that reasons about a date has to do
    // this; there is no global one.
    $tz = trim((string)($cfg['timezone'] ?? '')) ?: (defined('DEFAULT_TIMEZONE') ? DEFAULT_TIMEZONE : 'UTC');
    $prevTz = date_default_timezone_get();
    @date_default_timezone_set($tz);

    $checks = [];
    $add = function (string $label, string $status, string $detail = '') use (&$checks) {
        $checks[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
    };

    foreach (AnniversaryConfig::warnings() as $w) {
        $add('Stored value', 'fail', $w);
    }
    $add('Posting', !empty($cfg['enabled']) ? 'ok' : 'off',
        !empty($cfg['enabled']) ? 'enabled' : 'anniversary messages are turned off');

    // Which anniversaries would post at all. A milestone-only bot with an empty
    // milestone list is perfectly configured and can never say anything, which
    // is exactly the sort of silence this check exists to catch.
    $opts = annivApiPickOpts($cfg);
    if ($opts['mode'] === 'milestones') {
        $add('Which years', $opts['milestone_years'] ? 'ok' : 'fail',
            $opts['milestone_years']
                ? 'milestones only: ' . implode(', ', $opts['milestone_years'])
                : 'set to milestone years only, but no milestone years are listed — nothing can ever post');
    } else {
        $add('Which years', 'ok', 'every year from ' . $opts['min_years']
            . ($opts['milestone_years']
                ? '; louder at ' . implode(', ', $opts['milestone_years'])
                : '; no milestone years set'));
    }

    // The one check that looks BACKWARDS. Everything else here asks whether the
    // bot could work if it ran; this asks whether it did. They are not the same
    // question — a timer that stopped firing leaves every other row green while
    // the channel goes quiet, and nobody finds out until an anniversary has
    // already been missed. The timer writes these two marks from its own
    // container; the paths come from AnniversaryConfig so both sides agree.
    $health = annivRunHealth(
        annivStateLoad((string)($cfg['state_file'] ?? '')),
        annivHeartbeatRead((string)($cfg['heartbeat_file'] ?? ''))
    );
    $add('Last run', $health['status'], $health['detail']);

    $drivers = MssqlClient::availableDrivers();
    $add('MSSQL driver', $drivers ? 'ok' : 'fail',
        $drivers ? implode(', ', $drivers) : 'not installed in this PHP image');

    try {
        $norm = annivApiRoster($cfg);
        $n = count($norm['people']);
        $add('Roster query', $n > 0 ? 'ok' : 'fail',
            $n > 0 ? $n . ' current employees with a hire date on file'
                   : 'returned rows, but none had a usable hire date');
        // What actually makes those people "current". The headcount alone
        // cannot show whether leavers are excluded.
        $emp = RosterGuard::employmentFilter((string)$cfg['roster_sql']);
        $add('Still employed', $emp['ok'] ? 'ok' : 'warn', $emp['summary']);
        if ($n > 0) {
            $today = date('Y-m-d');
            $hits = annivCelebrants($norm['people'], $today, $opts);
            $up = annivUpcoming($norm['people'], $today, 60, $opts);
            $next = $up ? array_key_first($up) : null;
            $add('Today', $hits ? 'ok' : 'idle',
                $hits ? count($hits) . ' anniversary/ies' : 'nobody today');
            $add('Next up', $next ? 'ok' : 'idle', $next ?: 'none in the next 60 days');
        }
    } catch (Exception $e) {
        $add('Roster query', 'fail', $e->getMessage());
    }

    try {
        $slack = new SlackClient((string)$cfg['slack_bot_token'], 20, ANNIV_API_CONFIG_HINT);
        $who = $slack->authTest();
        $add('Slack token', 'ok', 'workspace "' . $who['team'] . '", bot "' . $who['user'] . '"');
        try {
            $rc = $slack->resolveChannel((string)$cfg['slack_channel']);
            $add('Channel', 'ok', ($rc['name'] !== '' ? '#' . $rc['name'] . ' = ' : '') . $rc['id']);
        } catch (Exception $e) {
            $add('Channel', 'fail', $e->getMessage());
        }
    } catch (Exception $e) {
        $add('Slack token', 'fail', $e->getMessage());
    }

    $name = trim((string)($cfg['bot_username'] ?? ''));
    $add('Posts as', $name !== '' ? 'ok' : 'idle',
        $name !== '' ? $name . ' (needs chat:write.customize)' : "the Slack app's own name");

    if (empty($cfg['gifs_enabled'])) {
        $add('GIFs', 'idle', 'turned off');
    } else {
        $pick = GifSource::pick($cfg, 'health-check');
        if ($pick !== null) {
            $add('GIFs', 'ok', $pick['source']);
        } elseif (!GifSource::internetReachable((int)($cfg['gif_timeout'] ?? 6))) {
            $add('GIFs', 'fail', 'this server cannot reach the internet');
        } else {
            $add('GIFs', 'warn', 'none of the configured URLs resolved');
        }
    }

    @date_default_timezone_set($prevTz);
    echo json_encode(['checks' => $checks]);
}

/**
 * Placeholder celebrants — a shared surname reads as obviously synthetic.
 *
 * The first one is deliberately on a milestone year, so a one-person preview
 * shows the milestone wording as well as the ordinary kind. That half of the
 * pools is otherwise invisible until somebody's tenth anniversary comes round.
 */
function annivApiSamples(int $count, array $milestones): array
{
    $names = ['Robin', 'Casey', 'Jordan', 'Riley', 'Avery', 'Quinn'];
    $years = [$milestones ? $milestones[min(1, count($milestones) - 1)] : 5, 3, 1, 7, 2, 12];
    $out = [];
    for ($i = 0; $i < max(1, min(6, $count)); $i++) {
        $out[] = [
            'emp_no' => '', 'first' => $names[$i], 'last' => 'Sample',
            'name' => $names[$i] . ' Sample', 'hire_date' => '2020-01-01',
            'year' => 2020, 'month' => 1, 'day' => 1, 'email' => '', 'slack_id' => '',
            'years' => $years[$i], 'milestone' => annivIsMilestone($years[$i], $milestones),
        ];
    }
    return $out;
}

/** Build sample messages without touching Slack. */
function annivApiPreview(array $cfg, array $input): void
{
    $count = Validator::optionalInt($input, 'people', 1, 6) ?? 1;
    $milestones = annivMilestoneYears($cfg['milestone_years'] ?? null);
    $samples = annivApiSamples($count, $milestones);
    $out = [];
    for ($i = 0; $i < 4; $i++) {
        $out[] = annivBuildText($samples, annivMessageConfig($cfg),
            'preview|' . $i . '|' . microtime(true) . bin2hex(random_bytes(2)));
    }
    echo json_encode([
        'messages'  => $out,
        'people'    => $count,
        // The preview only shows the milestone pools when the sample happens to
        // land on one, so say which case is on screen rather than leaving the
        // reader to guess why the wording changed.
        'milestone' => $count === 1 && !empty($samples[0]['milestone']),
        'years'     => array_column($samples, 'years'),
    ]);
}

/** Check the configured GIF URLs, distinguishing rot from no connectivity. */
function annivApiTestGifs(array $cfg): void
{
    $timeout = max(2, (int)($cfg['gif_timeout'] ?? 6));
    $list = !empty($cfg['gifs']) && is_array($cfg['gifs']) ? $cfg['gifs'] : ANNIV_DEFAULT_GIFS;
    $results = [];
    $good = 0;
    foreach (array_slice($list, 0, 30) as $url) {
        $ok = GifSource::looksLikeUrl($url) && GifSource::urlResolves($url, $timeout);
        if ($ok) { $good++; }
        $results[] = ['url' => $url, 'ok' => $ok];
    }
    $note = '';
    if ($good === 0) {
        $note = GifSource::internetReachable($timeout)
            ? 'This server can reach the internet, so those URLs really have gone.'
            : 'This server cannot reach the internet at all — that is the problem, not the list.';
    }
    $key = trim((string)($cfg['giphy_api_key'] ?? ''));
    $giphy = null;
    if ($key !== '') {
        try {
            $found = GifSource::giphySearch($key, 'work anniversary', $timeout,
                (string)($cfg['giphy_rating'] ?? 'g'));
            $giphy = ['ok' => true, 'count' => count($found), 'sample' => $found[0] ?? ''];
        } catch (Exception $e) {
            $giphy = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    echo json_encode(['results' => $results, 'working' => $good, 'note' => $note, 'giphy' => $giphy]);
}

/**
 * Post to Slack for real: a full sample announcement, or a plain one-liner.
 * Never touches the already-posted state, so it can't suppress a real
 * anniversary.
 */
function annivApiDemo(array $cfg, array $input, bool $full): void
{
    $slack = new SlackClient((string)$cfg['slack_bot_token'], 20, ANNIV_API_CONFIG_HINT);
    $rc = $slack->resolveChannel((string)$cfg['slack_channel']);
    $channel = $rc['id'];

    if (!$full) {
        $slack->postMessage($channel,
            ':wave: Work-anniversary bot test — this channel is wired up correctly.', [
                'username'   => (string)($cfg['bot_username'] ?? ''),
                'icon_emoji' => (string)($cfg['bot_icon_emoji'] ?? ''),
            ]);
        annivApiAudit('test_slack', 'posted a test message to ' . $channel);
        echo json_encode(['success' => true, 'channel' => $channel,
                          'note' => $slack->customizeDropped()]);
        return;
    }

    $count = Validator::optionalInt($input, 'people', 1, 6) ?? 1;
    $samples = annivApiSamples($count, annivMilestoneYears($cfg['milestone_years'] ?? null));
    $seed = 'demo|' . microtime(true) . '|' . bin2hex(random_bytes(4));
    $text = annivBuildText($samples, annivMessageConfig($cfg), $seed);
    $gif  = GifSource::pick($cfg, $seed);

    $blocks = annivBuildBlocks($text, $gif['url'] ?? null, $cfg);
    $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn',
        'text' => ':wrench: _Preview — this is what the daily post looks like. '
            . ($count > 1 ? 'These names are placeholders' : '"Robin Sample" is a placeholder')
            . ', not a real anniversary._']]];

    $ts = $slack->postMessage($channel, $text, [
        'blocks'     => $blocks,
        'username'   => (string)($cfg['bot_username'] ?? ''),
        'icon_emoji' => (string)($cfg['bot_icon_emoji'] ?? ''),
    ]);

    $reacted = 0;
    if (!empty($cfg['add_reactions']) && $ts !== '') {
        foreach ((array)($cfg['reactions'] ?? []) as $e) {
            if ($slack->addReaction($channel, $ts, (string)$e)) { $reacted++; }
        }
    }
    annivApiAudit('demo', 'posted a sample announcement (' . $count . ' celebrant(s)) to ' . $channel);
    echo json_encode([
        'success'   => true,
        'channel'   => $channel,
        'text'      => $text,
        'gif'       => $gif,
        'reactions' => $reacted,
        'note'      => $slack->customizeDropped(),
    ]);
}
