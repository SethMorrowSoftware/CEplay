<?php
/**
 * Birthday bot — pure logic.
 *
 * Everything in this file is side-effect free apart from the small state-file
 * helpers at the bottom: no database, no network. That is deliberate — the
 * roster comes in as plain arrays, so the date matching, name formatting and
 * message building can be unit-tested on any machine (the sandbox has no MSSQL
 * driver; the venue server does). See tests/test_birthday_lib.php.
 *
 * PHP 7.4 compatible, matching the rest of this repo.
 */

/**
 * Placeholder birth dates that mean "unknown", not "born that day".
 *
 * Old POS/time-clock systems commonly stamp one of these when the field was
 * never filled in. Without this guard the bot would wish half the staff a
 * happy birthday on 1 January — the single most likely way this thing
 * embarrasses itself in public.
 */
const BDAY_SENTINEL_DATES = [
    '1900-01-01', '1899-12-30', '1899-12-31', '1753-01-01', '0001-01-01', '1970-01-01',
];

/** Birth years outside this range are treated as data-entry noise, not people. */
const BDAY_MIN_YEAR = 1901;

/** Column-name candidates, in preference order, for each field we need. */
const BDAY_COLUMN_ALIASES = [
    'emp_no'     => ['emp_no', 'empno', 'employee_no', 'employeeno', 'employeeid', 'employee_id', 'id', 'no'],
    'first_name' => ['first_name', 'firstname', 'fname', 'first', 'givenname'],
    'last_name'  => ['last_name', 'lastname', 'lname', 'last', 'surname'],
    'full_name'  => ['full_name', 'fullname', 'name', 'employee', 'employeename', 'description'],
    'birth_date' => ['birth_date', 'birthdate', 'birthday', 'bday', 'dob', 'dateofbirth', 'birth_dt'],
    'email'      => ['email', 'email_address', 'emailaddress', 'e_mail', 'mail'],
    'status'     => ['status', 'stus', 'active', 'employed', 'emp_status'],
];

/** Normalize a column name for matching: lowercase, strip non-alphanumerics. */
function bdayNormKey(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

/**
 * Pull one logical field out of a DB row, tolerating whatever the query
 * aliased it to. Returns null when no candidate column is present.
 */
function bdayPickCol(array $row, string $field)
{
    $aliases = BDAY_COLUMN_ALIASES[$field] ?? [$field];
    $byNorm = [];
    foreach ($row as $k => $v) {
        $byNorm[bdayNormKey((string)$k)] = $v;
    }
    foreach ($aliases as $alias) {
        $n = bdayNormKey($alias);
        if (array_key_exists($n, $byNorm)) {
            return $byNorm[$n];
        }
    }
    return null;
}

/**
 * Coerce whatever the driver handed back into an ISO 'YYYY-MM-DD', or null.
 *
 * The recommended roster SQL already does CONVERT(VARCHAR(10), BirthDate, 120)
 * so this normally sees a clean ISO string. It stays tolerant anyway because a
 * hand-edited query might not, and FreeTDS/dblib has its own DATETIME
 * rendering ("Mar  3 1985 12:00:00:000AM").
 */
function bdayParseBirthDate($v): ?string
{
    if ($v === null || $v === '' || $v === false) {
        return null;
    }
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d');
    }
    $s = trim((string)$v);
    if ($s === '') {
        return null;
    }

    // ISO first: 'YYYY-MM-DD', optionally followed by a time.
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
        return bdayMakeIso((int)$m[1], (int)$m[2], (int)$m[3]);
    }
    // US style: 'M/D/YYYY'.
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $m)) {
        return bdayMakeIso((int)$m[3], (int)$m[1], (int)$m[2]);
    }
    // 'YYYYMMDD'.
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
        return bdayMakeIso((int)$m[1], (int)$m[2], (int)$m[3]);
    }
    // Anything else: let PHP try (covers the dblib textual DATETIME form).
    // Strip the trailing ':000AM' millisecond form dblib emits, which
    // strtotime() does not understand.
    $cleaned = preg_replace('/(\d{1,2}:\d{2}(:\d{2})?):\d{3}\s*([AP]M)/i', '$1 $3', $s);
    $ts = strtotime($cleaned);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

/** Build an ISO date string, or null if the parts aren't a real calendar date. */
function bdayMakeIso(int $y, int $m, int $d): ?string
{
    if (!checkdate($m, $d, $y)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/**
 * Is this a birth date we're willing to act on?
 *
 * Rejects the known sentinels, anything before BDAY_MIN_YEAR (which covers
 * every 1900-xx-xx "unknown" stamp, not just 1900-01-01), and future dates.
 */
function bdayIsUsableBirthDate(?string $iso, array $extraSentinels = [], ?string $today = null): bool
{
    if ($iso === null) {
        return false;
    }
    $sentinels = array_merge(BDAY_SENTINEL_DATES, $extraSentinels);
    if (in_array($iso, $sentinels, true)) {
        return false;
    }
    $year = (int)substr($iso, 0, 4);
    if ($year < BDAY_MIN_YEAR) {
        return false;
    }
    $today = $today ?? date('Y-m-d');
    return $iso <= $today;
}

/**
 * Turn raw roster rows into people, reporting exactly what was dropped and why.
 *
 * The counts matter: "0 celebrants today" is an unremarkable result, but
 * "0 celebrants and 340 rows had no usable birth date" means the query is
 * pointed at the wrong column. The runner prints both.
 *
 * @return array{people: array, skipped: array, sentinel_hits: array}
 */
function bdayNormalizeRoster(array $rows, array $cfg = []): array
{
    $extraSentinels = $cfg['ignore_birth_dates'] ?? [];
    $today          = $cfg['today'] ?? date('Y-m-d');
    $excludeNos     = array_map('strval', $cfg['exclude_emp_nos'] ?? []);
    $excludeNames   = array_map(function ($n) { return bdayNormKey((string)$n); }, $cfg['exclude_names'] ?? []);

    $people  = [];
    $skipped = [
        'no_birth_date' => 0,
        'unparsed'      => 0,
        'sentinel'      => 0,
        'no_name'       => 0,
        'excluded'      => 0,
        'duplicate'     => 0,
    ];
    $sentinelHits = [];
    $seen = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rawBirth = bdayPickCol($row, 'birth_date');
        if ($rawBirth === null || $rawBirth === '') {
            $skipped['no_birth_date']++;
            continue;
        }
        $iso = bdayParseBirthDate($rawBirth);
        if ($iso === null) {
            $skipped['unparsed']++;
            continue;
        }
        if (!bdayIsUsableBirthDate($iso, $extraSentinels, $today)) {
            $skipped['sentinel']++;
            $sentinelHits[$iso] = ($sentinelHits[$iso] ?? 0) + 1;
            continue;
        }

        $first = trim((string)(bdayPickCol($row, 'first_name') ?? ''));
        $last  = trim((string)(bdayPickCol($row, 'last_name') ?? ''));
        $full  = trim((string)(bdayPickCol($row, 'full_name') ?? ''));
        if ($first === '' && $last === '' && $full !== '') {
            // A single "Last, First" or "First Last" column.
            if (strpos($full, ',') !== false) {
                [$last, $first] = array_map('trim', array_pad(explode(',', $full, 2), 2, ''));
            } else {
                $bits  = preg_split('/\s+/', $full);
                $first = array_shift($bits) ?? '';
                $last  = implode(' ', $bits);
            }
        }
        if ($first === '' && $last === '') {
            $skipped['no_name']++;
            continue;
        }

        $empNo = bdayPickCol($row, 'emp_no');
        $empNo = $empNo === null ? '' : trim((string)$empNo);
        $nameFull = trim($first . ' ' . $last);

        if (($empNo !== '' && in_array($empNo, $excludeNos, true))
            || in_array(bdayNormKey($nameFull), $excludeNames, true)) {
            $skipped['excluded']++;
            continue;
        }

        // Same person listed twice (multiple job codes is the usual cause).
        $dedupeKey = $empNo !== '' ? 'e:' . $empNo : 'n:' . bdayNormKey($nameFull) . '|' . $iso;
        if (isset($seen[$dedupeKey])) {
            $skipped['duplicate']++;
            continue;
        }
        $seen[$dedupeKey] = true;

        $email = bdayPickCol($row, 'email');
        $email = $email === null ? '' : trim((string)$email);

        $people[] = [
            'emp_no'     => $empNo,
            'first'      => $first,
            'last'       => $last,
            'name'       => $nameFull,
            'birth_date' => $iso,
            'month'      => (int)substr($iso, 5, 2),
            'day'        => (int)substr($iso, 8, 2),
            'email'      => $email,
            'slack_id'   => '',
        ];
    }

    arsort($sentinelHits);
    return ['people' => $people, 'skipped' => $skipped, 'sentinel_hits' => $sentinelHits];
}

/** Is this a leap year? */
function bdayIsLeapYear(int $year): bool
{
    return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
}

/**
 * Which (month, day) pairs should be celebrated on $date?
 *
 * Normally just $date's own month/day. The exception is 29 February: in a
 * non-leap year those people need a substitute day, chosen by $leapMode
 * ('feb28' | 'mar1' | 'skip'). In a leap year 29 Feb is a real date and is
 * matched directly, so it must NOT also fire on the 28th.
 */
function bdayTargetMonthDays(string $date, string $leapMode = 'feb28'): array
{
    $y = (int)substr($date, 0, 4);
    $m = (int)substr($date, 5, 2);
    $d = (int)substr($date, 8, 2);
    $targets = [[$m, $d]];

    if (!bdayIsLeapYear($y) && $leapMode !== 'skip') {
        $substitute = ($leapMode === 'mar1') ? [3, 1] : [2, 28];
        if ($m === $substitute[0] && $d === $substitute[1]) {
            $targets[] = [2, 29];
        }
    }
    return $targets;
}

/** Everyone whose birthday falls on $date (ISO), honouring the leap-day rule. */
function bdayCelebrants(array $people, string $date, string $leapMode = 'feb28'): array
{
    $targets = bdayTargetMonthDays($date, $leapMode);
    $out = [];
    foreach ($people as $p) {
        foreach ($targets as $t) {
            if ((int)$p['month'] === $t[0] && (int)$p['day'] === $t[1]) {
                $out[] = $p;
                break;
            }
        }
    }
    usort($out, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $out;
}

/**
 * Birthdays in the next $days days starting at $from, as a date-keyed list.
 * Used by --list to eyeball that the roster query returns sane data.
 */
function bdayUpcoming(array $people, string $from, int $days, string $leapMode = 'feb28'): array
{
    $out = [];
    $ts = strtotime($from . ' 12:00:00');
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("+{$i} day", $ts));
        $hits = bdayCelebrants($people, $date, $leapMode);
        if ($hits) {
            $out[$date] = $hits;
        }
    }
    return $out;
}

/** Render one person's name per the configured style. */
function bdayDisplayName(array $p, string $style = 'full'): string
{
    $first = trim((string)$p['first']);
    $last  = trim((string)$p['last']);
    switch ($style) {
        case 'first':
            return $first !== '' ? $first : $last;
        case 'first_initial':
            $initial = $last !== '' ? strtoupper(substr($last, 0, 1)) . '.' : '';
            return trim($first . ' ' . $initial);
        case 'full':
        default:
            return trim($first . ' ' . $last);
    }
}

/**
 * How a person appears in the message: an @-mention when we resolved a Slack
 * user for them, otherwise their bolded name.
 */
function bdayMentionOrName(array $p, string $style, bool $boldNames = true): string
{
    if (!empty($p['slack_id'])) {
        return '<@' . $p['slack_id'] . '>';
    }
    $name = bdaySlackEscape(bdayDisplayName($p, $style));
    return $boldNames ? '*' . $name . '*' : $name;
}

/** Escape the three characters Slack treats as markup control characters. */
function bdaySlackEscape(string $s): string
{
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
}

/** "A", "A and B", "A, B and C". */
function bdayJoinNames(array $parts): string
{
    $n = count($parts);
    if ($n === 0) return '';
    if ($n === 1) return $parts[0];
    if ($n === 2) return $parts[0] . ' and ' . $parts[1];
    $last = array_pop($parts);
    return implode(', ', $parts) . ' and ' . $last;
}

/**
 * Fun message pools.
 *
 * The venue is an arcade / go-kart / laser-tag park, so the jokes are drawn
 * from its own attractions — the names come straight out of the card system
 * (Go-Karts, Laser Tag, Free Fall, Dragon Coaster, Ballocity, the batting
 * cages, mini golf, the redemption counter). A generic "Happy Birthday!" is
 * fine; sounding like the place you actually work is better.
 *
 * Placeholders: {names} {count} {venue}. Deliberately no {age} and no birth
 * year — the roster holds full dates of birth, a fifth of this staff are
 * minors, and a channel is not the place to publish either.
 *
 * Which one gets used is DETERMINISTIC per day and per person (see
 * bdaySeedFor), so --dry-run shows the message that will actually be posted
 * and a re-run never silently swaps it.
 */
const BDAY_FUN_SINGLE = [
    ":birthday: Happy Birthday, {names}! :tada:\nHope today's an all-time high score.",
    ":tada: It's {names}'s birthday! :birthday:\nUnlimited continues today — no quarters needed. :joystick:",
    ":birthday: Happy Birthday, {names}! :racing_car:\nMay every light be green and may you get the fast kart.",
    ":cake: Happy Birthday, {names}! :sparkles:\nMay the claw be generous and the tickets rain down. :teddy_bear:",
    ":birthday: Happy Birthday, {names}! :bowling:\nYou get the good skee-ball lane today. House rules.",
    ":tada: {names} is celebrating today! :birthday:\nDirect hits only in Laser Tag. :dart:",
    ":balloon: Happy Birthday, {names}! :birthday:\nEvery putt a hole in one, every swipe a jackpot. :golf:",
    ":birthday: Happy Birthday to {names}! :star2:\nTop of the leaderboard today, no argument. :trophy:",
    ":cake: It's {names}'s birthday! :confetti_ball:\nZipline's yours, the line's empty, and the pizza's hot.",
    ":birthday: Happy Birthday, {names}! :dizzy:\nFree Fall, Dragon Coaster, cake. In that order.",
    ":tada: Happy Birthday, {names}! :birthday:\nSlow pitches in the cage and top-shelf prizes at the counter.",
    ":confetti_ball: Big day for {names}! :birthday:\nEveryone at {venue} hopes it's a good one.",
];

const BDAY_FUN_MULTI = [
    ":birthday: {count} birthdays at {venue} today! :tada:\nHappy Birthday, {names}!",
    ":tada: {count}-player birthday mode: {names}! :birthday:\nHappy Birthday from all of us!",
    ":confetti_ball: {count} of us are celebrating today — Happy Birthday, {names}! :birthday:\nHigh scores all round.",
    ":cake: Co-op birthday unlocked: {names}! :video_game:\nThat's {count} good reasons for cake.",
    ":birthday: Happy Birthday, {names}! :tada:\n{count} celebrations, one very good day at {venue}.",
    ":balloon: Busy day at the party table — Happy Birthday to {names}! :birthday:\nAll {count} of you. :confetti_ball:",
];

/**
 * A stable seed for one day's greeting.
 *
 * Built from the date plus the people in it, so: the same greeting on every
 * run that day, a different one for a different person, and a different one
 * for the same person next year.
 */
function bdaySeedFor(string $date, array $celebrants): string
{
    $keys = [];
    foreach ($celebrants as $p) {
        $keys[] = bdayPersonKey($p);
    }
    sort($keys);
    return $date . '|' . implode(',', $keys);
}

/** Stable index from a seed — same seed, same pick, every run. */
function bdaySeedIndex(string $seed, int $count): int
{
    if ($count <= 0) {
        return 0;
    }
    return (int)(sprintf('%u', crc32($seed)) % $count);
}

/**
 * Choose the template for this greeting.
 *
 * Precedence: a single fixed template in config wins (someone who has settled
 * on exact wording keeps it), then a custom pool, then the built-in pool.
 */
function bdayPickTemplate(int $count, array $cfg, string $seed): string
{
    $isMulti = $count > 1;

    $fixed = $isMulti ? ($cfg['message_multi'] ?? '') : ($cfg['message_single'] ?? '');
    if (is_string($fixed) && $fixed !== '') {
        return $fixed;
    }

    $pool = $isMulti ? ($cfg['messages_multi'] ?? null) : ($cfg['messages_single'] ?? null);
    $pool = is_array($pool) ? array_values(array_filter(array_map('strval', $pool))) : [];
    if (!$pool) {
        $pool = $isMulti ? BDAY_FUN_MULTI : BDAY_FUN_SINGLE;
    }
    return $pool[bdaySeedIndex($seed, count($pool))];
}

/**
 * Build the message text.
 *
 * Templates support {names}, {count} and {venue}. Deliberately no {age} and no
 * birth year anywhere: the roster carries dates of birth, and a public channel
 * is not the place to publish them.
 */
function bdayBuildText(array $celebrants, array $cfg, string $seed = ''): string
{
    if (!$celebrants) {
        return '';
    }
    $style = $cfg['name_style'] ?? 'full';
    $bold  = $cfg['bold_names'] ?? true;
    $names = [];
    foreach ($celebrants as $p) {
        $names[] = bdayMentionOrName($p, $style, $bold);
    }
    $joined = bdayJoinNames($names);

    $template = bdayPickTemplate(count($celebrants), $cfg, $seed);

    $text = strtr($template, [
        '{names}' => $joined,
        '{count}' => (string)count($celebrants),
        '{venue}' => bdaySlackEscape((string)($cfg['venue_label'] ?? 'The Castle Fun Center')),
    ]);

    $prefix = trim((string)($cfg['mention'] ?? ''));
    return $prefix !== '' ? $prefix . ' ' . $text : $text;
}

/**
 * Assemble the Slack Block Kit payload: the greeting, the GIF, and a small
 * footer line.
 *
 * `text` is still sent alongside these as the notification/accessibility
 * fallback — a blocks-only message shows up blank in a push notification.
 */
function bdayBuildBlocks(string $text, ?string $gifUrl, array $cfg): array
{
    $blocks = [[
        'type' => 'section',
        'text' => ['type' => 'mrkdwn', 'text' => $text],
    ]];

    if ($gifUrl !== null && $gifUrl !== '') {
        $blocks[] = [
            'type'      => 'image',
            'image_url' => $gifUrl,
            // Required by Slack, and it is what screen readers announce.
            'alt_text'  => (string)($cfg['gif_alt_text'] ?? 'A birthday celebration GIF'),
        ];
    }

    $footer = $cfg['footer_text'] ?? null;
    if ($footer === null) {
        $footer = ':balloon: from everyone at '
            . bdaySlackEscape((string)($cfg['venue_label'] ?? 'The Castle Fun Center'));
    }
    $footer = trim((string)$footer);
    if ($footer !== '') {
        $blocks[] = [
            'type'     => 'context',
            'elements' => [['type' => 'mrkdwn', 'text' => $footer]],
        ];
    }

    return $blocks;
}

// ---------------------------------------------------------------------------
// State file — "who have we already wished this year?"
//
// The timer is Persistent=true, so a box that was off at 09:00 fires the job
// on boot; a re-run, a manual test, or two timer firings must not produce two
// messages. State is keyed by date + employee, so it also survives someone
// being added to the roster mid-day.
// ---------------------------------------------------------------------------

/** Load state, tolerating a missing or corrupt file (start clean rather than die). */
function bdayStateLoad(string $path): array
{
    if (!is_file($path)) {
        return ['posted' => []];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return ['posted' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['posted']) || !is_array($data['posted'])) {
        return ['posted' => []];
    }
    return $data;
}

/** Atomically write state, readable only by the owner (it names employees). */
function bdayStateSave(string $path, array $state): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $path . '.tmp';
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json . "\n") === false) {
        return false;
    }
    @chmod($tmp, 0600);
    return @rename($tmp, $path);
}

/** Stable per-person key: employee number when we have one, else the name. */
function bdayPersonKey(array $p): string
{
    return $p['emp_no'] !== '' ? 'e:' . $p['emp_no'] : 'n:' . bdayNormKey($p['name']);
}

/** Has this person already been wished on this date? */
function bdayStateHas(array $state, string $date, array $p): bool
{
    $list = $state['posted'][$date] ?? [];
    return in_array(bdayPersonKey($p), $list, true);
}

/** Record that these people were wished on $date. */
function bdayStateMark(array $state, string $date, array $people): array
{
    $list = $state['posted'][$date] ?? [];
    foreach ($people as $p) {
        $key = bdayPersonKey($p);
        if (!in_array($key, $list, true)) {
            $list[] = $key;
        }
    }
    $state['posted'][$date] = $list;
    return $state;
}

/** Drop entries older than $keepDays so the file can't grow without bound. */
function bdayStatePrune(array $state, string $today, int $keepDays = 45): array
{
    $cutoff = date('Y-m-d', strtotime($today . ' 12:00:00 -' . max(1, $keepDays) . ' days'));
    foreach (array_keys($state['posted']) as $date) {
        if ((string)$date < $cutoff) {
            unset($state['posted'][$date]);
        }
    }
    return $state;
}
