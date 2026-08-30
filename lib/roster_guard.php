<?php
/**
 * "Does this roster query only return people who still work here?"
 *
 * Shared by the birthday bot and the work-anniversary bot — the same reason
 * they share SlackClient, GifSource and TodayCache: the check must not drift
 * between them, because it guards the worst failure either one has.
 *
 * THE FAILURE IT GUARDS. Both bots put names in a public Slack channel, and the
 * ONLY thing standing between "today's celebrants" and "everyone who ever
 * worked here" is the WHERE clause of an operator-editable SQL query. This
 * venue's roster is 1,547 rows of which 193 are current staff: get the filter
 * wrong and the bot congratulates 1,350 people who left, by name, in front of
 * the ones who stayed.
 *
 * WHY THIS IS A WARNING AND NOT A REFUSAL. The check is a heuristic — it reads
 * the SQL looking for a column that means "employed", and a venue can express
 * that in ways no word list will catch (a join to a view, a bespoke flag). A
 * false alarm costs a log line; refusing to run would cost a real greeting. The
 * one thing that IS refused is discover.php's TODO_CONFIRM_EMPLOYMENT_FILTER
 * marker, because that is not a guess: it is the probe saying outright that it
 * could not find a filter.
 *
 * So this exists to make the filter VISIBLE — in the log, in `--check`, and on
 * both pages — rather than to enforce it. An operator who can see
 * "EmpStatus, DateOfTerminate" next to a headcount can tell at a glance that
 * the guard is there; before this, they had to read the SQL.
 */

class RosterGuard
{
    /**
     * Column-name fragments that mean "still employed" one way or another.
     *
     * Substrings, not exact names: this database writes CamelCase with no
     * separators, so `EmpStus` has to match as readily as `emp_status`.
     */
    private const STATUS_HINTS = [
        'status', 'stus', 'active', 'inactive', 'employed', 'employment',
        'terminated', 'retired', 'disabled', 'deleted', 'archived', 'current',
    ];

    /** The same, for a date that marks the END of employment. */
    private const TERM_DATE_HINTS = [
        'termdate', 'dateofterminate', 'terminationdate', 'termination',
        'dateterminated', 'separationdate', 'quitdate', 'enddate', 'dateleft',
        'leavedate', 'exitdate',
    ];

    /**
     * Inspect a roster query's WHERE clause.
     *
     * @return array{ok: bool, found: string[], summary: string}
     *         ok      false when nothing in the query looks like it limits the
     *                 result to current staff
     *         found   the fragments that matched, for showing the operator
     *         summary one line fit for a log or a check row
     */
    public static function employmentFilter(string $sql): array
    {
        // Comments out first. discover.php annotates the query it generates
        // ("EmpStatus = 1 /* 'Active' */"), and without this the word inside
        // the comment gets reported as though it were a column.
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql);
        $sql = preg_replace('/--[^\r\n]*/', ' ', $sql);

        $where = self::whereClause($sql);
        if ($where === null) {
            return [
                'ok' => false,
                'found' => [],
                'summary' => 'no WHERE clause at all — every person who ever worked here '
                           . 'would be greeted',
            ];
        }

        // Only the WHERE clause counts. A SELECT list naming DateOfTerminate,
        // or a table called EmployeeStatus, would otherwise read as a filter
        // that isn't there.
        $needle = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $where));

        $found = [];
        foreach (self::STATUS_HINTS as $hint) {
            if (strpos($needle, $hint) !== false) { $found[] = $hint; break; }
        }
        foreach (self::TERM_DATE_HINTS as $hint) {
            if (strpos($needle, $hint) !== false) { $found[] = $hint; break; }
        }

        if (!$found) {
            return [
                'ok' => false,
                'found' => [],
                'summary' => 'the WHERE clause has no employment status or termination date '
                           . 'in it — check it really excludes people who have left',
            ];
        }

        // Report the real column names rather than the fragments that matched:
        // "EmpStatus, DateOfTerminate" is something an operator can recognise;
        // "status, dateofterminate" is not.
        $columns = self::matchedColumns($where);
        return [
            'ok' => true,
            'found' => $columns ?: $found,
            'summary' => 'filtered on ' . implode(', ', $columns ?: $found),
        ];
    }

    /**
     * Everything after the outermost WHERE, or null when there isn't one.
     *
     * Deliberately naive — it does not parse SQL, it just finds where the
     * filtering starts. Anything before WHERE is the SELECT list and the FROM,
     * neither of which can limit the rows.
     */
    private static function whereClause(string $sql): ?string
    {
        if (!preg_match('/\bWHERE\b/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        return substr($sql, $m[0][1]);
    }

    /** Identifier-shaped tokens in the WHERE clause that matched a hint. */
    private static function matchedColumns(string $where): array
    {
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $where, $tokens);
        $out = [];
        foreach ($tokens[0] as $token) {
            $norm = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $token));
            foreach (array_merge(self::STATUS_HINTS, self::TERM_DATE_HINTS) as $hint) {
                if (strpos($norm, $hint) !== false) {
                    if (!in_array($token, $out, true)) { $out[] = $token; }
                    break;
                }
            }
        }
        return $out;
    }
}
