<?php
/**
 * Work-anniversary bot — pure logic.
 *
 * The sibling of birthdays/lib/birthday_lib.php, and deliberately built the
 * same way: everything here is side-effect free apart from the small state-file
 * helpers at the bottom — no database, no network. The roster arrives as plain
 * arrays, so the date matching, the years-of-service arithmetic and the message
 * building can be unit-tested on any machine (the sandbox has no MSSQL driver;
 * the venue server does). See tests/test_anniv_lib.php.
 *
 * WHAT IS DIFFERENT FROM THE BIRTHDAY BOT, and why it is not just a rename:
 *
 *   - The number is the point. A birthday message must never carry an age or a
 *     birth year; an anniversary message is ABOUT the count of years, so
 *     {years} and {ordinal} exist here and are used freely.
 *   - Year zero is not an anniversary. Somebody hired this morning matches
 *     today's month and day exactly, and "Happy 0 years!" is the single most
 *     likely way this thing embarrasses itself — see ANNIV_DEFAULT_MIN_YEARS.
 *   - Milestones get their own wording. A fifth or a twentieth year deserves
 *     more than the line a third year gets, so there is a separate pool for
 *     them and a mode that posts ONLY on milestones.
 *   - Several people sharing a date have DIFFERENT numbers, so the names
 *     themselves carry the years ("Robin (5 years) and Casey (1 year)") rather
 *     than the template trying to.
 *
 * PHP 7.4 compatible, matching the rest of this repo.
 */

/**
 * Placeholder hire dates that mean "unknown", not "started that day".
 *
 * Old POS/time-clock systems commonly stamp one of these when the field was
 * never filled in — and unlike a birthday, a sentinel hire date does not just
 * pick the wrong day: 1900-01-01 would post "Happy 126th anniversary" to a
 * public channel every New Year's Day.
 */
const ANNIV_SENTINEL_DATES = [
    '1900-01-01', '1899-12-30', '1899-12-31', '1753-01-01', '0001-01-01', '1970-01-01',
];

/** Hire years before this are treated as data-entry noise, not employment. */
const ANNIV_MIN_YEAR = 1901;

/**
 * Nobody is congratulated below this many years by default.
 *
 * One year, so the day somebody was hired is not read as an anniversary. A
 * venue that wants to mark the first day can lower it, but that is a start-date
 * announcement, not an anniversary, and it should be a deliberate choice.
 */
const ANNIV_DEFAULT_MIN_YEARS = 1;

/** The years that get the louder wording, unless the config replaces them. */
const ANNIV_DEFAULT_MILESTONES = [1, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50];

/** Column-name candidates, in preference order, for each field we need. */
const ANNIV_COLUMN_ALIASES = [
    'emp_no'     => ['emp_no', 'empno', 'employee_no', 'employeeno', 'employeeid', 'employee_id', 'id', 'no'],
    'first_name' => ['first_name', 'firstname', 'fname', 'first', 'givenname'],
    'last_name'  => ['last_name', 'lastname', 'lname', 'last', 'surname'],
    'full_name'  => ['full_name', 'fullname', 'name', 'employee', 'employeename', 'description'],
    // hire_date first: a query that aliases the column as instructed always
    // wins, whatever else the row happens to carry.
    'hire_date'  => ['hire_date', 'hiredate', 'dateofhire', 'datehired', 'date_hired',
                     'hired', 'start_date', 'startdate', 'employmentdate', 'dateemployed',
                     'seniority_date', 'senioritydate', 'anniversary_date', 'doh'],
    'email'      => ['email', 'email_address', 'emailaddress', 'e_mail', 'mail'],
    'status'     => ['status', 'stus', 'active', 'employed', 'emp_status'],
];

/** Normalize a column name for matching: lowercase, strip non-alphanumerics. */
function annivNormKey(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

/**
 * Pull one logical field out of a DB row, tolerating whatever the query
 * aliased it to. Returns null when no candidate column is present.
 */
function annivPickCol(array $row, string $field)
{
    $aliases = ANNIV_COLUMN_ALIASES[$field] ?? [$field];
    $byNorm = [];
    foreach ($row as $k => $v) {
        $byNorm[annivNormKey((string)$k)] = $v;
    }
    foreach ($aliases as $alias) {
        $n = annivNormKey($alias);
        if (array_key_exists($n, $byNorm)) {
            return $byNorm[$n];
        }
    }
    return null;
}

/**
 * Coerce whatever the driver handed back into an ISO 'YYYY-MM-DD', or null.
 *
 * The recommended roster SQL already does CONVERT(VARCHAR(10), DateOfHire, 120)
 * so this normally sees a clean ISO string. It stays tolerant anyway because a
 * hand-edited query might not, and FreeTDS/dblib has its own DATETIME rendering
 * ("Mar  3 2015 12:00:00:000AM").
 */
function annivParseDate($v): ?string
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
        return annivMakeIso((int)$m[1], (int)$m[2], (int)$m[3]);
    }
    // US style: 'M/D/YYYY'.
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $m)) {
        return annivMakeIso((int)$m[3], (int)$m[1], (int)$m[2]);
    }
    // 'YYYYMMDD'.
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
        return annivMakeIso((int)$m[1], (int)$m[2], (int)$m[3]);
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
function annivMakeIso(int $y, int $m, int $d): ?string
{
    if (!checkdate($m, $d, $y)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/**
 * Is this a hire date we're willing to act on?
 *
 * Rejects the known sentinels and anything before ANNIV_MIN_YEAR (which covers
 * every 1900-xx-xx "unknown" stamp, not just 1900-01-01). A FUTURE date is
 * rejected too but reported separately by the normaliser: a start date that has
 * not arrived yet is perfectly good data about somebody who does not have an
 * anniversary, which is a different thing from a broken column.
 */
function annivIsUsableHireDate(?string $iso, array $extraSentinels = [], ?string $today = null): bool
{
    if ($iso === null) {
        return false;
    }
    if (in_array($iso, array_merge(ANNIV_SENTINEL_DATES, $extraSentinels), true)) {
        return false;
    }
    if ((int)substr($iso, 0, 4) < ANNIV_MIN_YEAR) {
        return false;
    }
    return $iso <= ($today ?? date('Y-m-d'));
}

/**
 * Split a roster row's name columns into first/last/full, tolerating a single
 * "Last, First" or "First Last" column where there is no separate pair.
 *
 * Pulled out of annivNormalizeRoster() so a row that is about to be DROPPED can
 * still be named: "12 rows had no usable hire date" tells you a column is
 * wrong, but only "Dana Reyes, Sam Cole, …" tells you whose record to fix.
 *
 * @return array{first: string, last: string, name: string}
 */
function annivRowNames(array $row): array
{
    $first = trim((string)(annivPickCol($row, 'first_name') ?? ''));
    $last  = trim((string)(annivPickCol($row, 'last_name') ?? ''));
    $full  = trim((string)(annivPickCol($row, 'full_name') ?? ''));
    if ($first === '' && $last === '' && $full !== '') {
        if (strpos($full, ',') !== false) {
            [$last, $first] = array_map('trim', array_pad(explode(',', $full, 2), 2, ''));
        } else {
            $bits  = preg_split('/\s+/', $full);
            $first = array_shift($bits) ?? '';
            $last  = implode(' ', $bits);
        }
    }
    return ['first' => $first, 'last' => $last, 'name' => trim($first . ' ' . $last)];
}

/** At most this many dropped rows are kept when $cfg['collect_dropped'] is on. */
const ANNIV_MAX_DROPPED = 400;

/**
 * Turn raw roster rows into people, reporting exactly what was dropped and why.
 *
 * The counts matter: "0 anniversaries today" is an unremarkable result, but
 * "0 anniversaries and 340 rows had no usable hire date" means the query is
 * pointed at the wrong column — most likely the birth date, which every one of
 * these tables also carries. The runner prints both.
 *
 * With $cfg['collect_dropped'] the dropped rows are also returned individually,
 * for the page's "not on the list" panel. It is OPT-IN because the daily bot run
 * has no use for them and should not hold 1,350 leavers in memory to throw away.
 *
 * WHAT A DROPPED RECORD MAY CARRY IS AN ALLOWLIST, and it has to stay one: this
 * roster query is operator-editable and nothing stops it being SELECT *, over a
 * table that also holds SSN, PasswordHash, PinHash and FingerprintTemplate. The
 * raw row must never be echoed back — only the name, the hire value that was
 * rejected, and the reason.
 *
 * @return array{people: array, skipped: array, sentinel_hits: array, dropped: array}
 */
function annivNormalizeRoster(array $rows, array $cfg = []): array
{
    $extraSentinels = $cfg['ignore_hire_dates'] ?? [];
    $today          = $cfg['today'] ?? date('Y-m-d');
    $excludeNos     = array_map('strval', $cfg['exclude_emp_nos'] ?? []);
    $excludeNames   = array_map(function ($n) { return annivNormKey((string)$n); }, $cfg['exclude_names'] ?? []);

    $people  = [];
    $skipped = [
        'no_hire_date' => 0,
        'unparsed'     => 0,
        'sentinel'     => 0,
        'future'       => 0,
        'no_name'      => 0,
        'excluded'     => 0,
        'duplicate'    => 0,
    ];
    $sentinelHits = [];
    $seen = [];
    $collect = !empty($cfg['collect_dropped']);
    $dropped = [];

    /** Record one rejected row under an allowlisted projection. See the docblock. */
    $drop = function (string $reason, array $row, string $value = '') use (&$dropped, $collect) {
        if (!$collect || count($dropped) >= ANNIV_MAX_DROPPED) {
            return;
        }
        $n = annivRowNames($row);
        $empNo = annivPickCol($row, 'emp_no');
        $dropped[] = [
            'reason' => $reason,
            'name'   => $n['name'],
            'first'  => $n['first'],
            'last'   => $n['last'],
            'emp_no' => $empNo === null ? '' : trim((string)$empNo),
            'value'  => mb_substr(trim($value), 0, 40),
        ];
    };

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rawHire = annivPickCol($row, 'hire_date');
        if ($rawHire === null || $rawHire === '') {
            $skipped['no_hire_date']++;
            $drop('no_hire_date', $row);
            continue;
        }
        $iso = annivParseDate($rawHire);
        if ($iso === null) {
            $skipped['unparsed']++;
            $drop('unparsed', $row, (string)$rawHire);
            continue;
        }
        if ($iso > $today) {
            // Hired, but not started. Counted on its own so a roster full of
            // future start dates doesn't read as a broken column.
            $skipped['future']++;
            $drop('future', $row, $iso);
            continue;
        }
        if (!annivIsUsableHireDate($iso, $extraSentinels, $today)) {
            $skipped['sentinel']++;
            $sentinelHits[$iso] = ($sentinelHits[$iso] ?? 0) + 1;
            $drop('sentinel', $row, $iso);
            continue;
        }

        $names = annivRowNames($row);
        $first = $names['first'];
        $last  = $names['last'];
        if ($first === '' && $last === '') {
            $skipped['no_name']++;
            $drop('no_name', $row, $iso);
            continue;
        }

        $empNo = annivPickCol($row, 'emp_no');
        $empNo = $empNo === null ? '' : trim((string)$empNo);
        $nameFull = $names['name'];

        if (($empNo !== '' && in_array($empNo, $excludeNos, true))
            || in_array(annivNormKey($nameFull), $excludeNames, true)) {
            $skipped['excluded']++;
            // Which rule matched, so a typo'd opt-out entry is visibly not
            // matching anybody rather than silently doing nothing.
            $drop('excluded', $row,
                ($empNo !== '' && in_array($empNo, $excludeNos, true)) ? 'employee number' : 'name');
            continue;
        }

        // Same person listed twice (multiple job codes is the usual cause).
        $dedupeKey = $empNo !== '' ? 'e:' . $empNo : 'n:' . annivNormKey($nameFull) . '|' . $iso;
        if (isset($seen[$dedupeKey])) {
            $skipped['duplicate']++;
            $drop('duplicate', $row, $iso);
            continue;
        }
        $seen[$dedupeKey] = true;

        $email = annivPickCol($row, 'email');
        $email = $email === null ? '' : trim((string)$email);

        $people[] = [
            'emp_no'    => $empNo,
            'first'     => $first,
            'last'      => $last,
            'name'      => $nameFull,
            'hire_date' => $iso,
            'year'      => (int)substr($iso, 0, 4),
            'month'     => (int)substr($iso, 5, 2),
            'day'       => (int)substr($iso, 8, 2),
            'email'     => $email,
            'slack_id'  => '',
        ];
    }

    arsort($sentinelHits);
    return ['people' => $people, 'skipped' => $skipped, 'sentinel_hits' => $sentinelHits,
            'dropped' => $dropped];
}

/** Is this a leap year? */
function annivIsLeapYear(int $year): bool
{
    return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
}

/**
 * Which (month, day) pairs should be celebrated on $date?
 *
 * Normally just $date's own month/day. The exception is 29 February: somebody
 * hired on a leap day needs a substitute in the other three years, chosen by
 * $leapMode ('feb28' | 'mar1' | 'skip'). In a leap year 29 Feb is a real date
 * and is matched directly, so it must NOT also fire on the 28th.
 */
function annivTargetMonthDays(string $date, string $leapMode = 'feb28'): array
{
    $y = (int)substr($date, 0, 4);
    $m = (int)substr($date, 5, 2);
    $d = (int)substr($date, 8, 2);
    $targets = [[$m, $d]];

    if (!annivIsLeapYear($y) && $leapMode !== 'skip') {
        $substitute = ($leapMode === 'mar1') ? [3, 1] : [2, 28];
        if ($m === $substitute[0] && $d === $substitute[1]) {
            $targets[] = [2, 29];
        }
    }
    return $targets;
}

/**
 * Completed years of service on $date for somebody hired on $hireIso.
 *
 * Only ever called on a date that already matches the hire month/day (or its
 * leap-day substitute), so this is a plain year subtraction — which is also
 * why a 29 Feb hire marked on 28 Feb still reads the right number.
 */
function annivYearsOfService(string $hireIso, string $date): int
{
    return (int)substr($date, 0, 4) - (int)substr($hireIso, 0, 4);
}

/**
 * The configured milestone years, cleaned to a sorted list of positive ints.
 *
 * An ABSENT value (null — the stored default) means "use the built-in list".
 * An EMPTY array means the operator deliberately cleared it, and is honoured:
 * no year gets the louder wording. That combination is only dangerous in
 * milestone-only mode, where it means nothing can ever post — which is why
 * --check and the page both call it out rather than quietly substituting a
 * list nobody asked for.
 */
function annivMilestoneYears($value): array
{
    if (!is_array($value)) {
        return ANNIV_DEFAULT_MILESTONES;
    }
    $out = [];
    foreach ($value as $v) {
        $n = (int)trim((string)$v);
        if ($n > 0 && !in_array($n, $out, true)) {
            $out[] = $n;
        }
    }
    sort($out);
    return $out;
}

/** Does this many years count as a milestone? */
function annivIsMilestone(int $years, array $milestones): bool
{
    return in_array($years, $milestones, true);
}

/** 1 -> "1st", 2 -> "2nd", 3 -> "3rd", 11 -> "11th", 21 -> "21st". */
function annivOrdinal(int $n): string
{
    $abs = abs($n);
    if ($abs % 100 >= 11 && $abs % 100 <= 13) {
        return $n . 'th';
    }
    switch ($abs % 10) {
        case 1:  return $n . 'st';
        case 2:  return $n . 'nd';
        case 3:  return $n . 'rd';
        default: return $n . 'th';
    }
}

/** "1 year" / "5 years". */
function annivYearLabel(int $years): string
{
    return $years . ' year' . ($years === 1 ? '' : 's');
}

/**
 * Everyone whose work anniversary falls on $date (ISO), with their years.
 *
 * Three filters beyond the date match, all of them things that would otherwise
 * put a wrong number in a public channel:
 *
 *   - min_years — year zero is a start date, not an anniversary.
 *   - mode 'milestones' — only the years in milestone_years are posted at all.
 *   - the leap-day rule, as above.
 *
 * @param array $opts leap_mode, min_years, mode ('all'|'milestones'), milestone_years
 */
function annivCelebrants(array $people, string $date, array $opts = []): array
{
    $leapMode   = (string)($opts['leap_mode'] ?? 'feb28');
    $minYears   = max(0, (int)($opts['min_years'] ?? ANNIV_DEFAULT_MIN_YEARS));
    $mode       = (string)($opts['mode'] ?? 'all');
    $milestones = annivMilestoneYears($opts['milestone_years'] ?? null);

    $targets = annivTargetMonthDays($date, $leapMode);
    $out = [];
    foreach ($people as $p) {
        $hit = false;
        foreach ($targets as $t) {
            if ((int)$p['month'] === $t[0] && (int)$p['day'] === $t[1]) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            continue;
        }
        $years = annivYearsOfService((string)$p['hire_date'], $date);
        if ($years < $minYears) {
            continue;
        }
        if ($mode === 'milestones' && !annivIsMilestone($years, $milestones)) {
            continue;
        }
        $p['years'] = $years;
        $p['milestone'] = annivIsMilestone($years, $milestones);
        $out[] = $p;
    }

    // Longest service first, then by name — the twentieth year leads the
    // sentence, which is the order anybody reading it would expect.
    usort($out, function ($a, $b) {
        if ($a['years'] !== $b['years']) {
            return $b['years'] <=> $a['years'];
        }
        return strcasecmp($a['name'], $b['name']);
    });
    return $out;
}

/**
 * Anniversaries in the next $days days starting at $from, as a date-keyed list.
 * Used by --list and the page to eyeball that the roster query returns sane
 * data before trusting it with a channel.
 */
function annivUpcoming(array $people, string $from, int $days, array $opts = []): array
{
    $out = [];
    $ts = strtotime($from . ' 12:00:00');
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("+{$i} day", $ts));
        $hits = annivCelebrants($people, $date, $opts);
        if ($hits) {
            $out[$date] = $hits;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Looking FORWARD from one person, rather than sweeping a range of days
//
// annivUpcoming() answers "who is coming up in the next N days" by walking one
// day at a time — the right shape for a Slack bot, which only ever cares about
// a short window. It is the wrong shape for a COMPLETE list: answering "when is
// everybody's next anniversary" that way means sweeping 366 days for every
// person, and it still cannot answer "when would the bot next post about this
// person" when the answer is four milestone years away.
//
// So these three go the other way round: given one person, compute the dates
// directly. They are the primitives the Anniversaries page's whole-roster list
// is built from, and they are deliberately here rather than in the API handler
// so that annivCelebrants() and this list can never drift apart about what
// somebody's year count is — the same rule that put annivMessageConfig() in
// one place.
// ---------------------------------------------------------------------------

/**
 * Which calendar date a hire month/day is OBSERVED on in $year, or null when
 * the leap rule says it is not observed in that year at all.
 *
 * Only 29 February is ever interesting: every other month/day exists in every
 * year. In a leap year 29 February is a real date and is used as-is, so the
 * substitute must NOT also fire — the same rule annivTargetMonthDays() applies
 * from the other direction, and these two have to agree or a person would be
 * congratulated twice (or listed on a day the bot stays quiet).
 */
function annivObservedDate(int $year, int $month, int $day, string $leapMode = 'feb28'): ?string
{
    if ($month === 2 && $day === 29 && !annivIsLeapYear($year)) {
        switch ($leapMode) {
            case 'mar1': return annivMakeIso($year, 3, 1);
            case 'skip': return null;
            default:     return annivMakeIso($year, 2, 28);
        }
    }
    return annivMakeIso($year, $month, $day);
}

/**
 * The next work anniversary falling on or after $from, ignoring every posting
 * rule — this is the calendar answer, not the bot's answer.
 *
 * $from itself counts: somebody whose anniversary is today gets today's date
 * back, not next year's, which is what makes a list sorted by this field put
 * today's celebrants at the top where they belong.
 *
 * `years` is the count they reach on that date, computed the same way
 * annivYearsOfService() computes it (a plain year subtraction against the
 * observance date), so a person listed here on date X shows exactly the number
 * annivCelebrants() would give them on X.
 *
 * Returns null only if no observance can be found inside the search bound,
 * which takes a leap-day hire under leap_mode 'skip' plus a broken calendar to
 * achieve. The bound is there so a future edit cannot turn this into a spin.
 *
 * @return array{date: string, years: int}|null
 */
function annivNextAnniversary(string $hireIso, string $from, string $leapMode = 'feb28'): ?array
{
    $from     = substr($from, 0, 10);   // tolerate a 'Y-m-d H:i:s' caller
    $hireYear = (int)substr($hireIso, 0, 4);
    $month    = (int)substr($hireIso, 5, 2);
    $day      = (int)substr($hireIso, 8, 2);
    $startY   = (int)substr($from, 0, 4);

    // Leap years are four apart EXCEPT across a non-leap century (2096 -> 2104),
    // so a leap-day hire under 'skip' can wait eight. Anything less is not slow,
    // it is wrong: it reports "no next anniversary" for somebody who has one.
    for ($y = $startY; $y <= $startY + 8; $y++) {
        $observed = annivObservedDate($y, $month, $day, $leapMode);
        if ($observed !== null && $observed >= $from) {
            return ['date' => $observed, 'years' => $y - $hireYear];
        }
    }
    return null;
}

/**
 * The most recent anniversary falling on or before $on, or null when there has
 * not been one yet.
 *
 * The mirror of annivNextAnniversary(), and it exists so a list can be asked
 * BACKWARD questions — "who have we already celebrated this year?", "who had an
 * anniversary while I was away?" — which a forward-only view cannot answer at
 * all. $on itself counts, so on somebody's anniversary this and the forward
 * version agree, as they should.
 *
 * Never returns a date before the hire date: the day somebody started is
 * reported (as year 0, the same as the forward version reports it), but the
 * years before they worked here are not anniversaries of anything.
 *
 * @return array{date: string, years: int}|null
 */
function annivPrevAnniversary(string $hireIso, string $on, string $leapMode = 'feb28'): ?array
{
    $on       = substr($on, 0, 10);
    $hireIso  = substr($hireIso, 0, 10);
    $hireYear = (int)substr($hireIso, 0, 4);
    $month    = (int)substr($hireIso, 5, 2);
    $day      = (int)substr($hireIso, 8, 2);
    $startY   = (int)substr($on, 0, 4);

    for ($y = $startY; $y >= $startY - 8 && $y >= $hireYear; $y--) {
        $observed = annivObservedDate($y, $month, $day, $leapMode);
        if ($observed !== null && $observed <= $on && $observed >= $hireIso) {
            return ['date' => $observed, 'years' => $y - $hireYear];
        }
    }
    return null;
}

/**
 * The next anniversary the bot would actually POST about, or null if there
 * isn't one.
 *
 * Different from annivNextAnniversary() in three ways, each of them a rule the
 * bot already applies in annivCelebrants():
 *
 *   - min_years — a first year below the threshold is skipped, not celebrated
 *     quietly. Somebody hired last month has a next anniversary; under the
 *     default they also have a next POSTED anniversary, on the same day.
 *   - mode 'milestones' — only the configured milestone years post at all, so
 *     the answer can be years out, and can be NULL: a person at 12 years with
 *     a milestone list ending at 10 will never be posted about again. Saying
 *     null out loud is the point — that silence is otherwise invisible.
 *   - leap_mode 'skip' — a leap-day hire is only observed in leap years, so
 *     an otherwise-eligible year can be passed over.
 *
 * The search is bounded by the largest configured milestone in milestone mode
 * (past it nothing can ever match) and by a handful of years otherwise, so it
 * always terminates.
 *
 * @param array $opts leap_mode, min_years, mode ('all'|'milestones'), milestone_years
 * @return array{date: string, years: int}|null
 */
function annivNextCelebrated(string $hireIso, string $from, array $opts = []): ?array
{
    $leapMode   = (string)($opts['leap_mode'] ?? 'feb28');
    $minYears   = max(0, (int)($opts['min_years'] ?? ANNIV_DEFAULT_MIN_YEARS));
    $mode       = (string)($opts['mode'] ?? 'all');
    $milestones = annivMilestoneYears($opts['milestone_years'] ?? null);

    $from = substr($from, 0, 10);
    $next = annivNextAnniversary($hireIso, $from, $leapMode);
    if ($next === null) {
        return null;
    }

    $hireYear = (int)substr($hireIso, 0, 4);
    $month    = (int)substr($hireIso, 5, 2);
    $day      = (int)substr($hireIso, 8, 2);
    $first    = (int)$next['years'];

    if ($mode === 'milestones') {
        if (!$milestones) {
            return null;   // milestone-only with no milestones can never post
        }
        $limit = max($milestones);
    } else {
        // +8, not +4: see annivNextAnniversary() on the non-leap century.
        $limit = max($minYears, $first) + 8;
    }

    for ($years = $first; $years <= $limit; $years++) {
        if ($years < $minYears) {
            continue;
        }
        if ($mode === 'milestones' && !annivIsMilestone($years, $milestones)) {
            continue;
        }
        $observed = annivObservedDate($hireYear + $years, $month, $day, $leapMode);
        if ($observed !== null && $observed >= $from) {
            return ['date' => $observed, 'years' => $years];
        }
    }
    return null;
}

/**
 * Completed years of service on $on — the HR number, not the next one.
 *
 * A plain calendar count, with one deliberate exception: on the day somebody's
 * anniversary is OBSERVED it reads as the number the bot is announcing that
 * morning. Without it, a 29 February hire congratulated on 28 February would
 * be shown on the same screen as one year less than the message says, because
 * the calendar has not reached their real date yet. Every other day of the
 * year the two definitions agree.
 */
function annivYearsCompleted(string $hireIso, string $on, string $leapMode = 'feb28'): int
{
    $on   = substr($on, 0, 10);
    $next = annivNextAnniversary($hireIso, $on, $leapMode);
    if ($next !== null && $next['date'] === $on) {
        return (int)$next['years'];
    }
    $years = (int)substr($on, 0, 4) - (int)substr($hireIso, 0, 4);
    if (substr($on, 5) < substr($hireIso, 5)) {
        $years--;
    }
    return max(0, $years);
}

/**
 * The whole roster as list rows — every person, every date, computed once.
 *
 * This is the complete-list counterpart to annivCelebrants()/annivUpcoming(),
 * and the reason it is a pure function taking `people + today + opts` is the
 * same reason revenueCompose() is one: the page's most important numbers should
 * be testable without a database, and the sandbox has no MSSQL driver.
 *
 * Two DIFFERENT dates per person, and keeping them apart is the whole point of
 * the panel this feeds:
 *
 *   next_date  — the calendar answer. When their anniversary falls, full stop.
 *   post_date  — the bot's answer. What Slack will actually say something on,
 *                after min_years, milestone-only mode and the leap rule have
 *                had their say. NULL when the bot will never mention them
 *                again, with `silent` naming which rule did it.
 *
 * Collapse those into one column and the page starts answering "why didn't the
 * bot mention Dana?" wrongly — the question the dashboard strip's same-selection
 * rule exists to prevent.
 *
 * `shared` counts how many people share that posting date, so the page can
 * predict the one day the bot refuses outright: a cohort bigger than
 * max_celebrants. A seasonal venue hires in cohorts (this roster has 24 people
 * on one spring date), so that day is a real, dated, foreseeable silence.
 *
 * It is counted by asking annivCelebrants() itself, per posting date, rather
 * than by grouping the rows: those two agree for any date inside the next
 * twelve months, but further out they do not. Milestone mode can put somebody's
 * FIFTH anniversary next spring and their TENTH on a date four years away that
 * another person reaches first — group the rows and the distant date reports
 * one person where the bot will find two, and the warning that matters silently
 * never appears. Only the candidates sharing that month and day are tested, so
 * the exact answer costs a few thousand comparisons, not a sweep.
 *
 * Names are left RAW here — annivDisplayName() is applied by the caller, which
 * knows the configured name_style.
 *
 * @param array $opts leap_mode, min_years, mode ('all'|'milestones'), milestone_years
 */
function annivRosterRows(array $people, string $today, array $opts = []): array
{
    $today      = substr($today, 0, 10);
    $leapMode   = (string)($opts['leap_mode'] ?? 'feb28');
    $minYears   = max(0, (int)($opts['min_years'] ?? ANNIV_DEFAULT_MIN_YEARS));
    $mode       = (string)($opts['mode'] ?? 'all');
    $milestones = annivMilestoneYears($opts['milestone_years'] ?? null);

    $rows = [];
    // Candidates bucketed by hire month/day, so counting a posting date's
    // celebrants only ever compares the handful of people who could be on it.
    $byMd = [];
    foreach ($people as $p) {
        $byMd[(int)$p['month'] . '-' . (int)$p['day']][] = $p;
    }

    foreach ($people as $p) {
        $hire = (string)$p['hire_date'];
        $next = annivNextAnniversary($hire, $today, $leapMode);
        $prev = annivPrevAnniversary($hire, $today, $leapMode);
        $post = annivNextCelebrated($hire, $today, $opts);

        // Why the bot will stay quiet on the next anniversary, if it will.
        //
        // When both rules could be blocking, the one that is REPORTED is the
        // one that is actually holding it up: if the next anniversary would
        // post but for the floor, the floor is the answer; if it would not post
        // even without the floor, the milestone list is. Naming the wrong one
        // sends somebody to change a setting that changes nothing.
        $silent = '';
        if ($post === null) {
            $silent = $mode === 'milestones'
                ? ($milestones ? 'no_milestone_left' : 'no_milestones')
                : 'never';
        } elseif ($next !== null && $post['date'] !== $next['date']) {
            $postableButForFloor = $mode !== 'milestones'
                || annivIsMilestone((int)$next['years'], $milestones);
            $silent = $postableButForFloor ? 'below_min' : 'milestones_only';
        }

        $row = [
            'emp_no'         => (string)$p['emp_no'],
            'first'          => (string)$p['first'],
            'last'           => (string)$p['last'],
            'name'           => (string)$p['name'],
            'hire_date'      => $hire,
            'years'          => annivYearsCompleted($hire, $today, $leapMode),
            'next_date'      => $next['date'] ?? null,
            'next_years'     => $next === null ? null : (int)$next['years'],
            'next_milestone' => $next !== null && annivIsMilestone((int)$next['years'], $milestones),
            'days_until'     => $next === null ? null : annivDaysBetween($today, $next['date']),
            'prev_date'      => $prev['date'] ?? null,
            'prev_years'     => $prev === null ? null : (int)$prev['years'],
            'post_date'      => $post['date'] ?? null,
            'post_years'     => $post === null ? null : (int)$post['years'],
            'post_is_next'   => $post !== null && $next !== null && $post['date'] === $next['date'],
            'silent'         => $silent,
            'shared'         => 0,
        ];
        $rows[] = $row;
    }

    // How many people the bot will find on each posting date — its own count,
    // from its own function, over only the candidates who could share the day.
    $sharedByDate = [];
    foreach ($rows as &$r) {
        $d = $r['post_date'];
        if ($d === null) {
            continue;
        }
        if (!isset($sharedByDate[$d])) {
            $candidates = [];
            foreach (annivTargetMonthDays($d, $leapMode) as $t) {
                foreach ($byMd[$t[0] . '-' . $t[1]] ?? [] as $c) {
                    $candidates[] = $c;
                }
            }
            $sharedByDate[$d] = count(annivCelebrants($candidates, $d, $opts));
        }
        $r['shared'] = $sharedByDate[$d];
    }
    unset($r);

    // Soonest first, then longest service, then by name — the order somebody
    // scanning for "who is next" reads in. The page can re-sort client-side;
    // this is only the order it arrives in.
    usort($rows, function ($a, $b) {
        $ad = $a['next_date'] ?? '9999-12-31';
        $bd = $b['next_date'] ?? '9999-12-31';
        if ($ad !== $bd) {
            return strcmp($ad, $bd);
        }
        if ($a['years'] !== $b['years']) {
            return $b['years'] <=> $a['years'];
        }
        return strcasecmp($a['name'], $b['name']);
    });
    return $rows;
}

/**
 * Whole days from $from to $to, both ISO dates.
 *
 * Built from UTC midnights rather than strtotime() on the local zone: across a
 * DST boundary a local-time difference is 23 or 25 hours, and integer-dividing
 * that by 86400 loses or gains a day — which would print "in 6 days" on the
 * morning of the seventh.
 */
function annivDaysBetween(string $from, string $to): int
{
    $a = strtotime(substr($from, 0, 10) . ' 00:00:00 UTC');
    $b = strtotime(substr($to, 0, 10) . ' 00:00:00 UTC');
    if ($a === false || $b === false) {
        return 0;
    }
    return (int)round(($b - $a) / 86400);
}

/** Render one person's name per the configured style. */
function annivDisplayName(array $p, string $style = 'full'): string
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
function annivMentionOrName(array $p, string $style, bool $boldNames = true): string
{
    if (!empty($p['slack_id'])) {
        return '<@' . $p['slack_id'] . '>';
    }
    $name = annivSlackEscape(annivDisplayName($p, $style));
    return $boldNames ? '*' . $name . '*' : $name;
}

/** Escape the three characters Slack treats as markup control characters. */
function annivSlackEscape(string $s): string
{
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
}

/** "A", "A and B", "A, B and C". */
function annivJoinNames(array $parts): string
{
    $n = count($parts);
    if ($n === 0) return '';
    if ($n === 1) return $parts[0];
    if ($n === 2) return $parts[0] . ' and ' . $parts[1];
    $last = array_pop($parts);
    return implode(', ', $parts) . ' and ' . $last;
}

/**
 * The {names} substitution.
 *
 * With one celebrant it is just the name — the template already carries the
 * number, as {years} or {ordinal}. With several it CANNOT: they have different
 * numbers, and a single {years} in a shared template would have to pick one and
 * be wrong about everybody else. So the years ride along with each name.
 */
function annivNameList(array $celebrants, string $style, bool $bold): string
{
    $multi = count($celebrants) > 1;
    $parts = [];
    foreach ($celebrants as $p) {
        $one = annivMentionOrName($p, $style, $bold);
        if ($multi && isset($p['years'])) {
            $one .= ' (' . annivYearLabel((int)$p['years']) . ')';
        }
        $parts[] = $one;
    }
    return annivJoinNames($parts);
}

/**
 * Message pools.
 *
 * A greeting and a flavour line are drawn SEPARATELY and joined, so the variety
 * is multiplicative rather than additive: 14 greetings x 44 flavours is 616
 * distinct single-person messages, where a flat list of whole templates would
 * have to be 616 entries long to match it. Adding one good flavour line adds
 * fourteen new messages.
 *
 * That only works if every flavour line stands on its own after ANY greeting —
 * so each is a complete sentence that never refers back to the line above it.
 * Keep that discipline when adding more.
 *
 * The jokes come from this venue's own floor: the karts and the Spiral, Laser
 * Tag, Free Fall, the Dragon Coaster, the batting cages, the driving range,
 * mini golf, the rock wall, Ballocity, the zipline, skee-ball and Ice Ball, the
 * crane machines, Tin Can Alley, and the redemption counter.
 *
 * Placeholders: {names} {count} {venue} {years} {year_label} {ordinal}.
 * {ordinal} and a per-person {years} only make sense with ONE celebrant — see
 * annivBuildText() for what each one resolves to when several share a date.
 *
 * Which pair gets used is DETERMINISTIC per day and per person (see
 * annivSeedFor), so --dry-run shows the message that will actually be posted
 * and a re-run never silently swaps it.
 */
const ANNIV_GREETINGS = [
    ":tada: {year_label} at {venue} for {names}! Happy work anniversary! :sparkles:",
    ":trophy: Happy {ordinal} anniversary, {names}! :tada:",
    ":sparkles: {names} has been with us {year_label} today! :tada:",
    ":confetti_ball: {year_label} of {names} at {venue}! :trophy:",
    ":star2: Happy work anniversary, {names} — {year_label} today! :tada:",
    ":tada: {names} clocked in for the first time {year_label} ago today! :alarm_clock:",
    ":medal: {ordinal} anniversary for {names}! :tada:",
    ":clap: {year_label} of {names} keeping this place running! :sparkles:",
    ":raised_hands: Happy anniversary, {names} — {year_label} on the crew! :tada:",
    ":tada: Today marks {year_label} for {names} at {venue}! :confetti_ball:",
    ":sparkles: {ordinal} lap around the sun on the clock for {names}! :racing_car:",
    ":partying_face: {names} has survived {year_label} of {venue}! :trophy:",
    ":balloon: Happy work anniversary to {names} — {year_label} and counting! :tada:",
    ":rocket: {year_label} ago today, {names} started here. Still here! :tada:",
];

const ANNIV_FLAVORS = [
    // Karts
    "That is a lot of laps on the Spiral.",
    "Enough kart starts to wear out a key.",
    "The good kart is yours today. No arguments.",
    "You have heard that engine noise in your sleep by now.",
    "Green flag on another year.",
    // Laser Tag / Free Fall / coaster / zipline / rock wall
    "You have re-racked more Laser Tag vests than anyone should have to count.",
    "Free Fall has dropped on your call more times than anybody can count.",
    "The Dragon Coaster has never once gotten the better of you.",
    "You know exactly which harness buckle sticks.",
    "You have talked more nervous climbers off that rock wall than anyone.",
    // Arcade / redemption
    "You have unjammed more crane machines than the manufacturer ever built.",
    "You know which skee-ball lane runs fast, and you are not telling.",
    "You have counted more tickets than the redemption counter has shelf space.",
    "Ice Ball has never beaten you and it never will.",
    "You have reset Tin Can Alley more times than you have blinked.",
    "Ms. Pac-Man remembers.",
    "You could restock the prize wall blindfolded.",
    "That is a genuinely absurd number of tokens.",
    // Cages / range / golf
    "You have picked more range balls than the driving range has grass.",
    "The batting cage still owes you for all those pitching-machine reloads.",
    "You have fished more balls out of the mini golf water than anyone alive.",
    // Working here
    "You have heard every radio call this building can make.",
    "Ballocity has not broken you yet, which is saying something.",
    "You have run more party rooms than most people have attended.",
    "You have closed this place more times than you have counted.",
    "You have seen every kind of Saturday this venue can produce.",
    "Somehow you still show up smiling.",
    "You have trained half the people reading this.",
    "You know where everything is, which is why we all ask you.",
    "You have been here through every reader, every card system, and every rebuild.",
    "You are the reason the good shifts run like they do.",
    "Nobody has to explain anything to you twice.",
    "You are one of the ones this place could not run without.",
    "Genuinely — thank you for every one of those shifts.",
    "The place would not be the same without you.",
    "Here's to the next one.",
    "Take the golf cart today. You have earned it.",
    "Cake would be appropriate. Somebody see to it.",
    "First in line for everything today.",
    "May today run like a slow Tuesday.",
    "Zero radio calls for you today. That is the gift.",
    "Somebody else can handle the ball pit today.",
    "Thanks for sticking with us.",
    "Glad you're here.",
];

/**
 * Wording for the years that deserve more than the usual line.
 *
 * Used ONLY when a single person is being congratulated and their year count is
 * in milestone_years — with several celebrants the numbers differ and a
 * milestone template would be shouting on behalf of whoever happens to be
 * listed first.
 */
const ANNIV_MILESTONE_GREETINGS = [
    ":trophy: *{year_label}.* Happy {ordinal} anniversary, {names}! :tada:",
    ":confetti_ball: Big one today — {names} hits {year_label} at {venue}! :trophy:",
    ":star2: {ordinal} anniversary for {names}. That is a proper milestone! :tada:",
    ":tada: {year_label} at {venue}. Everybody make some noise for {names}! :confetti_ball:",
    ":medal: {names} has been part of this place for {year_label} today. :trophy:",
    ":sparkles: Stop what you are doing: {names} is at {year_label}! :tada:",
    ":rocket: {ordinal} anniversary, {names}. What a run. :trophy:",
    ":clap: {year_label} of {names}. That deserves the whole channel's attention. :tada:",
    ":fire: {names} has been here {year_label}. Let that sink in. :trophy:",
    ":trophy: Milestone day — happy {ordinal} anniversary, {names}! :confetti_ball:",
];

/**
 * Every one of these has to be true at the SMALLEST milestone as well as the
 * largest — the default list starts at one year, and a first anniversary is
 * exactly when somebody is most likely to be reading closely. So no line here
 * claims decades ("that predates half the games on this floor", "you have
 * outlasted an entire card system"): they read wonderfully at twenty years and
 * absurdly at one, and the pool is picked by milestone-ness, not by size.
 */
const ANNIV_MILESTONE_FLAVORS = [
    "That is a lot of Saturdays.",
    "The floor has changed since you started, and you are still here.",
    "Every one of those shifts counted.",
    "Not many people make it this far. You did.",
    "The crew is better for having you on it.",
    "There is not much in this building you have not covered.",
    "You have earned every bit of this one.",
    "Take the whole day off the radio. Seriously.",
    "There had better be cake for this one.",
    "Thank you, genuinely, for all of it.",
    "Here's to whatever comes next.",
    "That is a milestone worth stopping the floor for.",
    "Whatever we are paying you, it is not enough.",
    "Glad you stuck around.",
];

/** Greetings for several anniversaries on one day. */
const ANNIV_MULTI_GREETINGS = [
    ":tada: {count} work anniversaries at {venue} today — congratulations {names}! :trophy:",
    ":confetti_ball: {count} of us are marking a year today: {names}! :tada:",
    ":trophy: Happy work anniversary to {names}! That is {year_label} between them. :tada:",
    ":sparkles: Busy anniversary board today — congratulations to {names}! :trophy:",
    ":clap: {count} anniversaries, {year_label} of service between them: {names}! :tada:",
    ":star2: Raise a glass for {names} — all celebrating today! :confetti_ball:",
    ":medal: {count} people, one very good day: congratulations {names}! :tada:",
    ":tada: The crew is celebrating {names} today! :sparkles:",
    ":partying_face: Anniversary co-op mode: {names}! :video_game:",
    ":balloon: Congratulations to {names} — all of you, today. :trophy:",
];

/** Flavour lines for a shared anniversary — every one has to read as plural. */
const ANNIV_MULTI_FLAVORS = [
    "That is a lot of shifts between them.",
    "Between them they have seen everything this floor can do.",
    "The good karts are theirs today.",
    "Nobody on that list is going near the ball pit today.",
    "First in line for everything, all of them.",
    "Between them they could run this place with the lights off.",
    "That is an absurd number of laps on the Spiral.",
    "Half of us were trained by somebody on this list.",
    "The prize counter is stocked for all of them.",
    "Zero radio calls for any of them today.",
    "Somebody is bringing cake. It had better be somebody.",
    "Thanks to every one of them.",
    "The place runs on people like these.",
    "Green lights the whole way round, for all of them.",
    "Take the good headsets, all of you.",
    "Here's to the next year for each of them.",
];

/**
 * A stable seed for one day's message.
 *
 * Built from the date plus the people in it, so: the same wording on every run
 * that day, a different one for a different person, and a different one for the
 * same person next year.
 */
function annivSeedFor(string $date, array $celebrants): string
{
    $keys = [];
    foreach ($celebrants as $p) {
        $keys[] = annivPersonKey($p);
    }
    sort($keys);
    return $date . '|' . implode(',', $keys);
}

/** Stable index from a seed — same seed, same pick, every run. */
function annivSeedIndex(string $seed, int $count): int
{
    if ($count <= 0) {
        return 0;
    }
    return (int)(sprintf('%u', crc32($seed)) % $count);
}

/** Normalise a configured pool to a clean list of non-empty strings. */
function annivCleanPool($pool): array
{
    if (!is_array($pool)) {
        return [];
    }
    return array_values(array_filter(array_map(function ($v) {
        return trim((string)$v);
    }, $pool), function ($v) {
        return $v !== '';
    }));
}

/**
 * Choose the wording for this message.
 *
 * Precedence: one fixed template in config wins outright (somebody who has
 * settled on exact wording keeps it), then a custom pool of whole templates,
 * then a greeting and a flavour line composed from the pools.
 *
 * $milestone picks the louder pools. It is only ever true for a single
 * celebrant — see annivBuildText().
 */
function annivPickTemplate(int $count, bool $milestone, array $cfg, string $seed): string
{
    $isMulti = $count > 1;

    $fixed = $isMulti ? ($cfg['message_multi'] ?? '') : ($cfg['message_single'] ?? '');
    if (is_string($fixed) && $fixed !== '') {
        return $fixed;
    }

    $pool = $isMulti ? ($cfg['messages_multi'] ?? null) : ($cfg['messages_single'] ?? null);
    $pool = annivCleanPool($pool);
    if ($pool) {
        return $pool[annivSeedIndex($seed, count($pool))];
    }

    if ($isMulti) {
        $greetKey = 'multi_greetings';   $greetDefault = ANNIV_MULTI_GREETINGS;
        $flavKey  = 'multi_flavors';     $flavDefault  = ANNIV_MULTI_FLAVORS;
    } elseif ($milestone) {
        $greetKey = 'milestone_greetings'; $greetDefault = ANNIV_MILESTONE_GREETINGS;
        $flavKey  = 'milestone_flavors';   $flavDefault  = ANNIV_MILESTONE_FLAVORS;
    } else {
        $greetKey = 'greetings';         $greetDefault = ANNIV_GREETINGS;
        $flavKey  = 'flavors';           $flavDefault  = ANNIV_FLAVORS;
    }

    $greetings = annivCleanPool($cfg[$greetKey] ?? null) ?: $greetDefault;

    // An EMPTY configured flavour list means "greeting only"; an ABSENT one
    // means "use the built-in pool". The stored default is null, which has to
    // land on the second reading.
    $flavors = annivCleanPool($cfg[$flavKey] ?? null);
    if ($flavors === [] && !array_key_exists($flavKey, $cfg)) {
        $flavors = $flavDefault;
    }

    // The two halves are seeded independently, so the greeting and the flavour
    // vary against each other instead of moving in lockstep.
    $greeting = $greetings[annivSeedIndex($seed . '|greet', count($greetings))];
    if (!$flavors) {
        return $greeting;
    }
    return $greeting . "\n" . $flavors[annivSeedIndex($seed . '|flavor', count($flavors))];
}

/**
 * The subset of the configuration that shapes the message text.
 *
 * Every caller that builds a message goes through this — the daily run, --demo,
 * and the Anniversaries page's preview — so a preview can never show wording
 * the real post won't use.
 *
 * That is the whole reason it exists. The birthday bot learned this the hard
 * way: the same subset was assembled by hand at each call site, the daily run's
 * copy left out the pool keys, and a custom greeting saved on the page was
 * shown by the preview, shown by --demo, and then silently NOT used by the post
 * that actually went out. Anything that shapes wording belongs here, in one
 * place, or that drift comes straight back.
 *
 * Presence matters as much as value: a pool is passed on only when it is
 * genuinely an array, because annivPickTemplate() reads an ABSENT key as "use
 * the built-in pool" and an EMPTY one as "no flavour line at all".
 *
 * @param array $cfg       the full AnniversaryConfig::load() result
 * @param array $overrides applied last (--demo blanks the ping prefix, so a
 *                         sample announcement can never @-here a channel)
 */
function annivMessageConfig(array $cfg, array $overrides = []): array
{
    $out = [
        'name_style'      => (string)($cfg['name_style'] ?? 'full'),
        'bold_names'      => (bool)($cfg['bold_names'] ?? true),
        'venue_label'     => (string)($cfg['venue_label'] ?? 'The Castle Fun Center'),
        'mention'         => (string)($cfg['mention'] ?? ''),
        'milestone_years' => annivMilestoneYears($cfg['milestone_years'] ?? null),
    ];
    // One pinned wording wins outright; an empty string means "not set".
    foreach (['message_single', 'message_multi'] as $k) {
        $v = trim((string)($cfg[$k] ?? ''));
        if ($v !== '') {
            $out[$k] = $v;
        }
    }
    foreach (['messages_single', 'messages_multi', 'greetings', 'flavors',
              'milestone_greetings', 'milestone_flavors',
              'multi_greetings', 'multi_flavors'] as $k) {
        if (isset($cfg[$k]) && is_array($cfg[$k])) {
            $out[$k] = $cfg[$k];
        }
    }
    return array_merge($out, $overrides);
}

/**
 * Build the message text.
 *
 * Placeholder semantics, and the multi case is the one worth reading twice:
 *
 *   {names}      one name, or every name each carrying its own year count.
 *   {count}      how many people are being congratulated.
 *   {venue}      the venue label.
 *   {years}      SINGLE: that person's years. MULTI: the COMBINED total, which
 *                is the only number that is true of the group as a whole.
 *   {year_label} the same number written out ("1 year" / "12 years").
 *   {ordinal}    SINGLE: "5th". MULTI: empty — an ordinal of a combined total
 *                would be a number nobody has, so it is deliberately dropped
 *                rather than made up. The API rejects it in the multi pools.
 *   {s}          "" or "s", so a greeting can write "{count} year{s}".
 */
function annivBuildText(array $celebrants, array $cfg, string $seed = ''): string
{
    if (!$celebrants) {
        return '';
    }
    $style = $cfg['name_style'] ?? 'full';
    $bold  = $cfg['bold_names'] ?? true;
    $count = count($celebrants);
    $names = annivNameList($celebrants, $style, $bold);

    $milestones = annivMilestoneYears($cfg['milestone_years'] ?? null);
    $isMilestone = $count === 1 && annivIsMilestone((int)($celebrants[0]['years'] ?? 0), $milestones);

    if ($count === 1) {
        $years = (int)($celebrants[0]['years'] ?? 0);
        $ordinal = annivOrdinal($years);
    } else {
        $years = 0;
        foreach ($celebrants as $p) {
            $years += (int)($p['years'] ?? 0);
        }
        $ordinal = '';
    }

    $template = annivPickTemplate($count, $isMilestone, $cfg, $seed);

    $text = strtr($template, [
        '{names}'      => $names,
        '{count}'      => (string)$count,
        '{years}'      => (string)$years,
        '{year_label}' => annivYearLabel($years),
        '{ordinal}'    => $ordinal,
        '{s}'          => $years === 1 ? '' : 's',
        '{venue}'      => annivSlackEscape((string)($cfg['venue_label'] ?? 'The Castle Fun Center')),
    ]);

    $prefix = trim((string)($cfg['mention'] ?? ''));
    return $prefix !== '' ? $prefix . ' ' . $text : $text;
}

/**
 * Assemble the Slack Block Kit payload: the message, the GIF, and a small
 * footer line.
 *
 * `text` is still sent alongside these as the notification/accessibility
 * fallback — a blocks-only message shows up blank in a push notification.
 */
function annivBuildBlocks(string $text, ?string $gifUrl, array $cfg): array
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
            'alt_text'  => (string)($cfg['gif_alt_text'] ?? 'A celebration GIF'),
        ];
    }

    $footer = $cfg['footer_text'] ?? null;
    if ($footer === null) {
        $footer = ':trophy: from everyone at '
            . annivSlackEscape((string)($cfg['venue_label'] ?? 'The Castle Fun Center'));
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
// State file — "who have we already congratulated this year?"
//
// The timer is Persistent=true, so a box that was off at 09:00 fires the job on
// boot; a re-run, a manual test, or two timer firings must not produce two
// messages. State is keyed by date + employee, so it also survives someone
// being added to the roster mid-day.
//
// Deliberately a SEPARATE file from the birthday bot's: the two bots run from
// two timers and can both be firing at once, and sharing one JSON file would
// mean each read-modify-write could drop the other's record.
// ---------------------------------------------------------------------------

/** Load state, tolerating a missing or corrupt file (start clean rather than die). */
function annivStateLoad(string $path): array
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
function annivStateSave(string $path, array $state): bool
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
function annivPersonKey(array $p): string
{
    return $p['emp_no'] !== '' ? 'e:' . $p['emp_no'] : 'n:' . annivNormKey($p['name']);
}

/** Has this person already been congratulated on this date? */
function annivStateHas(array $state, string $date, array $p): bool
{
    $list = $state['posted'][$date] ?? [];
    return in_array(annivPersonKey($p), $list, true);
}

/** Record that these people were congratulated on $date. */
function annivStateMark(array $state, string $date, array $people): array
{
    $list = $state['posted'][$date] ?? [];
    foreach ($people as $p) {
        $key = annivPersonKey($p);
        if (!in_array($key, $list, true)) {
            $list[] = $key;
        }
    }
    $state['posted'][$date] = $list;
    return $state;
}

/** Drop entries older than $keepDays so the file can't grow without bound. */
function annivStatePrune(array $state, string $today, int $keepDays = 45): array
{
    $cutoff = date('Y-m-d', strtotime($today . ' 12:00:00 -' . max(1, $keepDays) . ' days'));
    foreach (array_keys($state['posted']) as $date) {
        if ((string)$date < $cutoff) {
            unset($state['posted'][$date]);
        }
    }
    return $state;
}

// ---------------------------------------------------------------------------
// Run record — "did this morning's message actually happen?"
//
// The bot's failure mode is SILENCE. A revoked token, a timer that never came
// back after a rebuild, MSSQL down at 09:00, a host that was off past the
// Persistent catch-up — every one of them looks exactly like a day when nobody
// had an anniversary, and the only trace was a line in a log file nobody reads.
// By the time somebody notices, the anniversary it cost is last week's.
//
// So every real run leaves two marks: a heartbeat file (the same convention
// Scheduler::writeHeartbeat uses, so /health and any external monitor can read
// it) saying the timer fired at all, and a last_run block in the state file
// saying what happened when it did.
// ---------------------------------------------------------------------------

/** Older than this and the daily timer has plainly missed a firing. */
const ANNIV_RUN_STALE_WARN = 93600;    // 26 hours
/** Older than this and it is not a blip — two firings have gone. */
const ANNIV_RUN_STALE_FAIL = 180000;   // 50 hours

/** Stamp "the bot ran". Matches Scheduler::writeHeartbeat's plain-ISO format. */
function annivHeartbeatWrite(string $path, ?int $now = null): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return false;
    }
    return @file_put_contents($path, date('c', $now ?? time()), LOCK_EX) !== false;
}

/** The last heartbeat, or null when the bot has never run here. */
function annivHeartbeatRead(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    return trim($raw);
}

/**
 * Record what this run did, for the page and --check to report.
 *
 * $outcome is one of: posted | idle (nobody today) | disabled (the switch is
 * off) | failed.
 */
function annivStateRecordRun(array $state, string $date, string $outcome,
                             string $detail = '', int $count = 0, ?int $now = null): array
{
    $state['last_run'] = [
        'at'      => date('c', $now ?? time()),
        'date'    => $date,
        'outcome' => $outcome,
        'count'   => $count,
        'detail'  => mb_substr(trim($detail), 0, 300),
    ];
    return $state;
}

/**
 * Turn the heartbeat and the last run into one verdict.
 *
 * Shared by the CLI's --check and the Anniversaries page, so the two can never
 * disagree about whether the bot is alive — the same reason
 * annivMessageConfig() exists.
 *
 * Precedence, and it matters:
 *
 *  1. A last_run stamped with TODAY'S date wins outright. The run writes it
 *     itself, so it is proof the bot ran — including when the heartbeat is
 *     missing or stale, which is exactly what a failed heartbeat write looks
 *     like. Reporting "the timer is not firing" at a bot that plainly just ran
 *     would send somebody to debug systemd over a file-permissions problem.
 *  2. Otherwise the heartbeat's AGE decides. A successful post three days ago
 *     is not evidence about this morning, so an old last_run never gets to
 *     colour the verdict green.
 *
 * @return array{status: string, age: ?int, detail: string}
 *         status: ok | idle | off | warn | fail
 */
function annivRunHealth(array $state, ?string $heartbeat, ?int $now = null): array
{
    $now = $now ?? time();
    $stamp = ($heartbeat !== null && trim($heartbeat) !== '') ? strtotime(trim($heartbeat)) : false;
    $age = $stamp !== false ? max(0, $now - $stamp) : null;
    $run = $state['last_run'] ?? null;

    if (is_array($run) && ($run['date'] ?? '') === date('Y-m-d', $now)) {
        $at     = !empty($run['at']) ? date('H:i', (int)strtotime((string)$run['at'])) : '?';
        $n      = (int)($run['count'] ?? 0);
        $detail = trim((string)($run['detail'] ?? ''));
        switch ((string)($run['outcome'] ?? '')) {
            case 'posted':
                return ['status' => 'ok', 'age' => $age, 'detail' =>
                    'posted ' . $n . ' message' . ($n === 1 ? '' : 's') . ' at ' . $at . ' today'
                    . ($detail !== '' ? ' — ' . $detail : '')];
            case 'idle':
                return ['status' => 'idle', 'age' => $age, 'detail' =>
                    'ran at ' . $at . ' today — no anniversaries'];
            case 'disabled':
                return ['status' => 'off', 'age' => $age, 'detail' =>
                    'ran at ' . $at . ' today, but posting is switched off'];
            case 'failed':
                return ['status' => 'fail', 'age' => $age, 'detail' =>
                    "today's run FAILED at " . $at . ($detail !== '' ? ': ' . $detail : '')];
        }
        // An outcome this version doesn't know: fall through to the age
        // reading rather than inventing a verdict for it.
    }

    if ($stamp === false) {
        return ['status' => 'fail', 'age' => null, 'detail' =>
            'the bot has never run here — check the timer is installed and enabled '
            . '(systemctl status ceplay-anniversaries.timer)'];
    }
    $when = annivRelativeTime($stamp, $now);

    if ($age <= ANNIV_RUN_STALE_WARN) {
        // Normal for most of the day: the timer fires once, so before this
        // morning's firing the newest run is yesterday's.
        return ['status' => 'ok', 'age' => $age, 'detail' => 'last ran ' . $when];
    }
    if ($age <= ANNIV_RUN_STALE_FAIL) {
        return ['status' => 'warn', 'age' => $age, 'detail' =>
            'last ran ' . $when . ' — a daily firing has been missed'];
    }
    return ['status' => 'fail', 'age' => $age, 'detail' =>
        'last ran ' . $when . ' — the timer is not firing '
        . '(systemctl status ceplay-anniversaries.timer)'];
}

/** "3 hours ago", "yesterday at 09:00", "6 days ago (Mon 17 Aug)". */
function annivRelativeTime(int $then, int $now): string
{
    $d = max(0, $now - $then);
    if ($d < 90) {
        return 'just now';
    }
    if ($d < 3600) {
        $m = (int)round($d / 60);
        return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    }
    if ($d < 86400) {
        $h = (int)round($d / 3600);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    $days = (int)floor($d / 86400);
    if ($days === 1) {
        return 'yesterday at ' . date('H:i', $then);
    }
    if ($days < 14) {
        return $days . ' days ago (' . date('D j M', $then) . ')';
    }
    return 'on ' . date('j M Y', $then);
}

// ---------------------------------------------------------------------------
// Run lock
//
// The timer is Persistent, install.sh adds catch-up firings, and somebody can
// always run the bot by hand — so two runs CAN overlap. Both would read the
// same "not yet congratulated" state and both would post, which is a duplicate
// message in a public channel.
// ---------------------------------------------------------------------------

/**
 * Take the run lock.
 *
 * @return resource|false|null  resource = held; false = somebody else has it;
 *         null = the lock file could not be opened at all. Null is deliberately
 *         NOT the same as false: a permissions problem with a lock file must
 *         not cost anybody their anniversary, so the caller carries on unlocked
 *         rather than skipping the post.
 */
function annivLockAcquire(string $path)
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return null;
    }
    $fh = @fopen($path, 'c');
    if ($fh === false) {
        return null;
    }
    if (!@flock($fh, LOCK_EX | LOCK_NB)) {
        @fclose($fh);
        return false;
    }
    return $fh;
}

/** Release the run lock. Harmless if it was never held. */
function annivLockRelease($handle): void
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

// ---------------------------------------------------------------------------
// GIFs
//
// The picker itself (HEAD verification, Giphy search, seeded rotation) is
// shared with the birthday bot — birthdays/lib/gif_source.php — because it is
// pure transport with no birthday in it. Only the CANDIDATES differ, and they
// are passed in through the config so GifSource's own birthday defaults never
// surface here. AnniversaryConfig::load() fills both keys in.
// ---------------------------------------------------------------------------

/** Fallback GIFs for when no Giphy key is set. Any public https .gif works. */
const ANNIV_DEFAULT_GIFS = [
    'https://media.giphy.com/media/g9582DNuQppxC/giphy.gif',
    'https://media.giphy.com/media/26tPplGWjN0xLybiU/giphy.gif',
    'https://media.giphy.com/media/l0MYIAUWRmVVzWLPq/giphy.gif',
    'https://media.giphy.com/media/3o7abKhOpu0NwenH3O/giphy.gif',
    'https://media.giphy.com/media/o75ajIFH0QnQC3nCeD/giphy.gif',
    'https://media.giphy.com/media/xUOxfjsW9fWPqEWouI/giphy.gif',
];

/** Search terms rotated through when the Giphy API is in use. */
const ANNIV_DEFAULT_SEARCH_TERMS = [
    'work anniversary',
    'congratulations',
    'celebration',
    'confetti celebration',
    'thank you team',
    'great job',
];
