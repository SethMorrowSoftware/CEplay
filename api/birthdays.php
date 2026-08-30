<?php
/**
 * Birthdays API — the Birthdays page's backend.
 *
 * Reads and writes the bot's settings through BirthdayConfig (defaults <- the
 * optional config file <- api_config), and runs the same health check, preview
 * and test actions the CLI offers, in-process. The web container is the one
 * carrying the MSSQL driver, so the roster query runs here exactly as it does
 * from the timer — a green check on this page means the nightly run works.
 *
 * Secrets are write-only: a token can be replaced or cleared, never read back.
 */

require_once __DIR__ . '/../lib/birthday_config.php';
require_once __DIR__ . '/../lib/mssql_client.php';
require_once __DIR__ . '/../lib/roster_guard.php';
require_once __DIR__ . '/../lib/validator.php';
require_once __DIR__ . '/../lib/today_cache.php';
require_once __DIR__ . '/../birthdays/lib/birthday_lib.php';
require_once __DIR__ . '/../birthdays/lib/slack_client.php';
require_once __DIR__ . '/../birthdays/lib/gif_source.php';

/** The optional legacy config file, or null when there isn't one. */
function bdayConfigFile(): ?string
{
    $root = dirname(__DIR__);
    foreach ([$root . '/data/birthday_config.php', $root . '/birthdays/config.php'] as $p) {
        if (is_file($p)) { return $p; }
    }
    return null;
}

function handleBirthdays(string $method, array $parts, ?array $input): void
{
    Auth::requireAccess('view_birthdays');
    $action = $parts[0] ?? '';

    if ($action === 'today') {
        if ($method !== 'GET') { bdayApi405(); return; }
        bdayApiToday();
        return;
    }
    if ($action === 'upcoming') {
        if ($method !== 'GET') { bdayApi405(); return; }
        bdayApiUpcoming();
        return;
    }
    if ($action === 'test') {
        if ($method !== 'POST') { bdayApi405(); return; }
        Auth::requireAccess('birthdays_manage');
        bdayApiTest($input ?? []);
        return;
    }
    if ($action !== '') { http_response_code(404); echo json_encode(['error' => 'Not found']); return; }

    switch ($method) {
        case 'GET':
            bdayApiGetConfig();
            break;
        case 'PUT':
            Auth::requireAccess('birthdays_manage');
            bdayApiPutConfig($input ?? []);
            break;
        default:
            bdayApi405();
    }
}

function bdayApi405(): void
{
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function bdayApiAudit(string $action, string $detail): void
{
    try {
        DB::execute(
            'INSERT INTO action_log (source, action, success, details) VALUES (:p0, :p1, 1, :p2)',
            ['manual', 'birthday_' . $action,
             (Auth::check()['username'] ?? '?') . ': ' . substr($detail, 0, 300)]
        );
    } catch (Exception $e) {
        error_log('birthday audit log failed: ' . $e->getMessage());
    }
}

/** Settings, the field schema the form renders from, and where they came from. */
function bdayApiGetConfig(): void
{
    $file = bdayConfigFile();
    $fields = [];
    foreach (BirthdayConfig::FIELDS as $key => $spec) {
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
        'config'       => BirthdayConfig::publicConfig($file),
        'fields'       => $fields,
        'defaults'     => [
            'roster_sql'      => BirthdayConfig::DEFAULT_ROSTER_SQL,
            'greetings'       => BDAY_GREETINGS,
            'flavors'         => BDAY_FLAVORS,
            'multi_greetings' => BDAY_MULTI_GREETINGS,
            'multi_flavors'   => BDAY_MULTI_FLAVORS,
            'gifs'            => GifSource::DEFAULT_GIFS,
        ],
        'config_file'  => $file,
        'can_manage'   => Auth::hasPermission('birthdays_manage'),
    ]);
}

/** Save whatever the form sent, validating each field against its type. */
function bdayApiPutConfig(array $input): void
{
    $saved = [];
    foreach (BirthdayConfig::FIELDS as $key => $spec) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = $input[$key];

        switch ($spec['type']) {
            case 'bool':
                BirthdayConfig::set($key, (bool)$value);
                break;
            case 'int':
                $n = (int)$value;
                $min = $spec['min'] ?? PHP_INT_MIN;
                $max = $spec['max'] ?? PHP_INT_MAX;
                if ($n < $min || $n > $max) {
                    throw new RuntimeException($spec['label'] . " must be between {$min} and {$max}.");
                }
                BirthdayConfig::set($key, $n);
                break;
            case 'enum':
                if (!isset($spec['options'][(string)$value])) {
                    throw new RuntimeException('Invalid choice for ' . $spec['label'] . '.');
                }
                BirthdayConfig::set($key, (string)$value);
                break;
            case 'list':
                $list = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string)$value);
                $list = array_values(array_filter(array_map('trim', array_map('strval', $list)),
                    function ($v) { return $v !== ''; }));
                if (count($list) > 500) {
                    throw new RuntimeException($spec['label'] . ': too many lines (max 500).');
                }
                bdayApiValidateList($key, $list);
                BirthdayConfig::set($key, $list);
                break;
            case 'secret':
                // '' means "leave it alone"; the explicit clear flag wipes it.
                if (!empty($input[$key . '_clear'])) {
                    BirthdayConfig::clearSecret($key);
                } else {
                    BirthdayConfig::set($key, (string)$value);
                }
                break;
            case 'text':
                $text = (string)$value;
                if (strlen($text) > 8000) {
                    throw new RuntimeException($spec['label'] . ' is too long (max 8000 characters).');
                }
                if ($key === 'roster_sql' && trim($text) !== '') {
                    // Same guard the CLI applies: one plain SELECT, nothing else.
                    MssqlClient::assertReadOnly($text);
                    if (stripos($text, 'TODO_CONFIRM_EMPLOYMENT_FILTER') !== false) {
                        throw new RuntimeException(
                            'The roster query still has TODO_CONFIRM_EMPLOYMENT_FILTER in it.');
                    }
                }
                BirthdayConfig::set($key, $text);
                break;
            default:
                $str = (string)$value;
                if (strlen($str) > 500) {
                    throw new RuntimeException($spec['label'] . ' is too long.');
                }
                BirthdayConfig::set($key, $str);
        }
        $saved[] = $key;
    }

    bdayApiAudit('settings', 'updated ' . (count($saved) ?: 0) . ' setting(s): ' . implode(', ', $saved));
    echo json_encode(['success' => true, 'saved' => $saved,
                      'config' => BirthdayConfig::publicConfig(bdayConfigFile())]);
}

/**
 * Per-field rules that a type check alone can't express. A greeting without
 * {names} would post a birthday message naming nobody.
 */
function bdayApiValidateList(string $key, array $list): void
{
    if (in_array($key, ['greetings', 'multi_greetings'], true)) {
        foreach ($list as $line) {
            if (strpos($line, '{names}') === false) {
                throw new RuntimeException('Every greeting must contain {names} — this one does not: "'
                    . mb_substr($line, 0, 60) . '"');
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
    if ($key === 'ignore_birth_dates') {
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
function bdayApiRoster(array $cfg): array
{
    if (!MssqlClient::availableDrivers()) {
        throw new RuntimeException('No MSSQL driver in this PHP runtime — the app image needs pdo_dblib.');
    }
    if (!MssqlClient::isConfigured()) {
        throw new RuntimeException('MSSQL is not configured yet (Go-Kart Labor page -> Settings).');
    }
    $sql = trim((string)$cfg['roster_sql']);
    MssqlClient::assertReadOnly($sql);

    $client = new MssqlClient();
    $client->setTimeout(max(5, (int)($cfg['query_timeout'] ?? 30)));
    $rows = $client->rows($sql, max(1, (int)($cfg['roster_max_rows'] ?? 5000)));

    return bdayNormalizeRoster($rows, [
        'today'              => date('Y-m-d'),
        'ignore_birth_dates' => (array)($cfg['ignore_birth_dates'] ?? []),
        'exclude_emp_nos'    => (array)($cfg['exclude_emp_nos'] ?? []),
        'exclude_names'      => (array)($cfg['exclude_names'] ?? []),
    ]);
}

/** Who's coming up, and what today's message would say. */
function bdayApiUpcoming(): void
{
    $cfg = BirthdayConfig::load(bdayConfigFile());
    $days = Validator::optionalInt(['d' => $_GET['days'] ?? null], 'd', 1, 400) ?? 60;
    $tz = trim((string)($cfg['timezone'] ?? '')) ?: (defined('DEFAULT_TIMEZONE') ? DEFAULT_TIMEZONE : 'UTC');
    $prev = date_default_timezone_get();
    @date_default_timezone_set($tz);
    $today = date('Y-m-d');

    try {
        $norm = bdayApiRoster($cfg);
    } catch (Exception $e) {
        @date_default_timezone_set($prev);
        echo json_encode(['error' => $e->getMessage(), 'roster_ok' => false]);
        return;
    }

    $people = $norm['people'];
    $leap = (string)($cfg['leap_day_mode'] ?? 'feb28');
    $style = (string)($cfg['name_style'] ?? 'full');

    $upcoming = [];
    foreach (bdayUpcoming($people, $today, $days, $leap) as $date => $hits) {
        $names = [];
        foreach ($hits as $h) { $names[] = bdayDisplayName($h, $style); }
        $upcoming[] = [
            'date'    => $date,
            'weekday' => date('D', strtotime($date . ' 12:00:00')),
            'names'   => $names,
            'is_today' => $date === $today,
        ];
    }

    // Exactly what would go out today, built the same way the timer builds it.
    $todayHits = bdayCelebrants($people, $today, $leap);
    $preview = null;
    if ($todayHits) {
        $seed = bdaySeedFor($today, $todayHits);
        $preview = bdayBuildText($todayHits, bdayApiMsgCfg($cfg), $seed);
    }

    @date_default_timezone_set($prev);
    echo json_encode([
        'roster_ok'    => true,
        'today'        => $today,
        'timezone'     => $tz,
        'people_count' => count($people),
        'skipped'      => $norm['skipped'],
        'upcoming'     => $upcoming,
        'today_preview' => $preview,
        'days'         => $days,
    ]);
}

// ---------------------------------------------------------------------------
// Today's celebrants, for the Command Center strip
//
// The dashboard polls every 30 seconds and is the busiest page in the app, so
// this endpoint must NEVER cost a roster read per request: that is a 5000-row
// MSSQL query behind a 30-second timeout. The memoisation in front of it lives
// in lib/today_cache.php and is shared with the anniversary bot — see that file
// for why failures are cached too, and why the entry is keyed by a signature
// rather than a clock alone.
// ---------------------------------------------------------------------------

/**
 * Everything that decides WHO shows up today.
 *
 * Any change to one of these has to invalidate the cache, so they are hashed
 * rather than listed: adding a new setting here is the only thing needed to
 * keep the strip honest.
 */
function bdayTodaySignature(array $cfg): string
{
    return substr(hash('sha256', json_encode([
        (string)($cfg['roster_sql'] ?? ''),
        (string)($cfg['leap_day_mode'] ?? ''),
        (string)($cfg['name_style'] ?? ''),
        (array)($cfg['exclude_emp_nos'] ?? []),
        (array)($cfg['exclude_names'] ?? []),
        (array)($cfg['ignore_birth_dates'] ?? []),
        (string)($cfg['timezone'] ?? ''),
    ])), 0, 16);
}

/**
 * Whose birthday is today — a small, cacheable payload for the dashboard.
 *
 * NAMES ONLY. No age, no birth year, not even a field for one: about a fifth of
 * this roster are minors, the greeting itself is forbidden from carrying either
 * (see birthdays/lib/birthday_lib.php), and a dashboard on a screen anyone can
 * walk past is a worse place to publish them than a channel would be.
 *
 * Deliberately the SAME selection the bot posts — same leap-day rule, same
 * opt-outs — so nobody has to wonder why Slack stayed quiet about a name
 * listed here.
 *
 * `available` false means the roster could not be read (no driver, not
 * configured, query broken). The dashboard renders nothing at all in that case;
 * the Birthdays page is where that gets diagnosed.
 */
function bdayApiToday(): void
{
    $cfg = BirthdayConfig::load(bdayConfigFile());
    $tz = trim((string)($cfg['timezone'] ?? '')) ?: (defined('DEFAULT_TIMEZONE') ? DEFAULT_TIMEZONE : 'UTC');
    $prev = date_default_timezone_get();
    @date_default_timezone_set($tz);
    $today = date('Y-m-d');

    $path = (string)($cfg['today_cache_file'] ?? '');
    $sig  = bdayTodaySignature($cfg);
    $cached = empty($_GET['refresh']) ? TodayCache::read($path, $today, $sig) : null;
    if ($cached !== null) {
        @date_default_timezone_set($prev);
        $cached['cached'] = true;
        echo json_encode($cached);
        return;
    }

    $out = [
        'date'       => $today,
        'timezone'   => $tz,
        'available'  => true,
        'reason'     => '',
        'people'     => [],
        'count'      => 0,
        'checked_at' => date('c'),
    ];
    try {
        $norm = bdayApiRoster($cfg);
        $style = (string)($cfg['name_style'] ?? 'full');
        $leap  = (string)($cfg['leap_day_mode'] ?? 'feb28');
        foreach (bdayCelebrants($norm['people'], $today, $leap) as $p) {
            $out['people'][] = ['name' => bdayDisplayName($p, $style)];
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

/**
 * The subset of config bdayBuildText() reads.
 *
 * Delegates to the shared builder rather than keeping its own list: this page
 * exists to show what the timer will post, so the two must not be able to
 * disagree about which settings shape a message.
 */
function bdayApiMsgCfg(array $cfg): array
{
    return bdayMessageConfig($cfg);
}

/** Health check, message preview, and the two live Slack tests. */
function bdayApiTest(array $input): void
{
    $what = Validator::requireEnum($input, 'action', ['check', 'preview', 'demo', 'test_slack', 'test_gifs']);
    $cfg = BirthdayConfig::load(bdayConfigFile());

    switch ($what) {
        case 'check':      bdayApiCheck($cfg); return;
        case 'preview':    bdayApiPreview($cfg, $input); return;
        case 'test_gifs':  bdayApiTestGifs($cfg); return;
        case 'demo':       bdayApiDemo($cfg, $input, true); return;
        case 'test_slack': bdayApiDemo($cfg, $input, false); return;
    }
}

/** One pass over every dependency, each with its own verdict. */
function bdayApiCheck(array $cfg): void
{
    // Which day is "today" depends on the VENUE's clock, not the web
    // container's — index.php restores the PHP default before dispatching, and
    // on this venue the host runs UTC while the app runs Eastern. Without this,
    // between 20:00 and midnight local the Today and Next-birthday rows below
    // would be about TOMORROW, disagreeing with /upcoming and /today, which
    // both set the zone. Every handler here that reasons about a date has to do
    // this; there is no global one.
    $tz = trim((string)($cfg['timezone'] ?? '')) ?: (defined('DEFAULT_TIMEZONE') ? DEFAULT_TIMEZONE : 'UTC');
    $prevTz = date_default_timezone_get();
    @date_default_timezone_set($tz);

    $checks = [];
    $add = function (string $label, string $status, string $detail = '') use (&$checks) {
        $checks[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
    };

    foreach (BirthdayConfig::warnings() as $w) {
        $add('Stored value', 'fail', $w);
    }
    $add('Posting', !empty($cfg['enabled']) ? 'ok' : 'off',
        !empty($cfg['enabled']) ? 'enabled' : 'greetings are turned off');

    // The one check that looks BACKWARDS. Everything else here asks whether
    // the bot could work if it ran; this asks whether it did. They are not the
    // same question — a timer that stopped firing leaves every other row green
    // while the channel goes quiet, and nobody finds out until a birthday has
    // already been missed. The timer writes these two marks from its own
    // container; the paths come from BirthdayConfig so both sides agree.
    $health = bdayRunHealth(
        bdayStateLoad((string)($cfg['state_file'] ?? '')),
        bdayHeartbeatRead((string)($cfg['heartbeat_file'] ?? ''))
    );
    $add('Last run', $health['status'], $health['detail']);

    $drivers = MssqlClient::availableDrivers();
    if (!$drivers) {
        $add('MSSQL driver', 'fail', 'not installed in this PHP image');
    } else {
        $add('MSSQL driver', 'ok', implode(', ', $drivers));
    }

    try {
        $norm = bdayApiRoster($cfg);
        $n = count($norm['people']);
        $add('Roster query', $n > 0 ? 'ok' : 'fail',
            $n > 0 ? $n . ' current employees with a birthday on file'
                   : 'returned rows, but none had a usable birth date');
        // What actually makes those people "current". The headcount alone
        // cannot show whether leavers are excluded.
        $emp = RosterGuard::employmentFilter((string)$cfg['roster_sql']);
        $add('Still employed', $emp['ok'] ? 'ok' : 'warn', $emp['summary']);
        if ($n > 0) {
            $today = date('Y-m-d');
            $leap = (string)($cfg['leap_day_mode'] ?? 'feb28');
            $hits = bdayCelebrants($norm['people'], $today, $leap);
            $up = bdayUpcoming($norm['people'], $today, 60, $leap);
            $next = $up ? array_key_first($up) : null;
            $add('Today', $hits ? 'ok' : 'idle',
                $hits ? count($hits) . ' birthday(s)' : 'nobody today');
            $add('Next birthday', $next ? 'ok' : 'idle', $next ?: 'none in the next 60 days');
        }
    } catch (Exception $e) {
        $add('Roster query', 'fail', $e->getMessage());
    }

    try {
        $slack = new SlackClient((string)$cfg['slack_bot_token']);
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

/** Build a sample message without touching Slack. */
function bdayApiPreview(array $cfg, array $input): void
{
    $count = Validator::optionalInt($input, 'people', 1, 6) ?? 1;
    $samples = bdayApiSamples($count);
    $out = [];
    for ($i = 0; $i < 4; $i++) {
        $out[] = bdayBuildText($samples, bdayApiMsgCfg($cfg),
            'preview|' . $i . '|' . microtime(true) . bin2hex(random_bytes(2)));
    }
    echo json_encode(['messages' => $out, 'people' => $count]);
}

/** Placeholder celebrants — a shared surname reads as obviously synthetic. */
function bdayApiSamples(int $count): array
{
    $names = ['Robin', 'Casey', 'Jordan', 'Riley', 'Avery', 'Quinn'];
    $out = [];
    for ($i = 0; $i < max(1, min(6, $count)); $i++) {
        $out[] = [
            'emp_no' => '', 'first' => $names[$i], 'last' => 'Sample',
            'name' => $names[$i] . ' Sample', 'birth_date' => '1990-01-01',
            'month' => 1, 'day' => 1, 'email' => '', 'slack_id' => '',
        ];
    }
    return $out;
}

/** Check the configured GIF URLs, distinguishing rot from no connectivity. */
function bdayApiTestGifs(array $cfg): void
{
    $timeout = max(2, (int)($cfg['gif_timeout'] ?? 6));
    $list = !empty($cfg['gifs']) && is_array($cfg['gifs']) ? $cfg['gifs'] : GifSource::DEFAULT_GIFS;
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
            $found = GifSource::giphySearch($key, 'happy birthday', $timeout,
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
 * Never touches the already-greeted state, so it can't suppress a real
 * birthday.
 */
function bdayApiDemo(array $cfg, array $input, bool $full): void
{
    $slack = new SlackClient((string)$cfg['slack_bot_token']);
    $rc = $slack->resolveChannel((string)$cfg['slack_channel']);
    $channel = $rc['id'];

    if (!$full) {
        $slack->postMessage($channel, ':wave: Birthday bot test — this channel is wired up correctly.', [
            'username'   => (string)($cfg['bot_username'] ?? ''),
            'icon_emoji' => (string)($cfg['bot_icon_emoji'] ?? ''),
        ]);
        bdayApiAudit('test_slack', 'posted a test message to ' . $channel);
        echo json_encode(['success' => true, 'channel' => $channel,
                          'note' => $slack->customizeDropped()]);
        return;
    }

    $count = Validator::optionalInt($input, 'people', 1, 6) ?? 1;
    $samples = bdayApiSamples($count);
    $seed = 'demo|' . microtime(true) . '|' . bin2hex(random_bytes(4));
    $text = bdayBuildText($samples, bdayApiMsgCfg($cfg), $seed);
    $gif  = GifSource::pick($cfg, $seed);

    $blocks = bdayBuildBlocks($text, $gif['url'] ?? null, $cfg);
    $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn',
        'text' => ':wrench: _Preview — this is what the daily post looks like. '
            . ($count > 1 ? 'These names are placeholders' : '"Robin Sample" is a placeholder')
            . ', not a real birthday._']]];

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
    bdayApiAudit('demo', 'posted a sample announcement (' . $count . ' celebrant(s)) to ' . $channel);
    echo json_encode([
        'success'   => true,
        'channel'   => $channel,
        'text'      => $text,
        'gif'       => $gif,
        'reactions' => $reacted,
        'note'      => $slack->customizeDropped(),
    ]);
}
