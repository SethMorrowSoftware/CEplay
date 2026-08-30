<?php
/**
 * "Who is celebrating today?" — the memoisation behind the Command Center strip.
 *
 * Shared by the birthday bot and the work-anniversary bot, for the same reason
 * they share SlackClient and GifSource: there is nothing bot-specific in here,
 * and the caching CONTRACT is exactly the thing that must not drift between the
 * two. One copy, one set of tests.
 *
 * WHY IT EXISTS AT ALL. The dashboard is the busiest page in the app and polls
 * every 30 seconds. Both `today` endpoints sit on a 5000-row MSSQL query behind
 * a 30-second timeout, so answering one per request would stall the page an
 * operator watches while running the floor. Nothing on that strip is worth
 * that, and nothing on it changes more than once a day.
 *
 * Three properties, all deliberate:
 *
 *  - FAILURES ARE CACHED, and that is the point rather than an oversight.
 *    Without it, an unreachable database is retried by every open dashboard on
 *    every poll, each one waiting out the full connect timeout. They expire
 *    sooner than a good answer so a fixed connection comes back quickly.
 *  - A SIGNATURE, not just a clock. The caller hashes every setting that
 *    decides who counts; a change to any of them is a different QUESTION, not a
 *    stale answer to this one, so no TTL can rescue it and the entry is dropped
 *    outright. That is what stops a settings edit leaving a wrong chip up for
 *    the rest of the TTL.
 *  - The DATE is part of the key. A tab open across midnight, or a server whose
 *    day has rolled over, must not be served yesterday's names.
 *
 * See anniversaries/tests/test_today_cache.php.
 */

class TodayCache
{
    /** How long a good answer stands. The roster barely changes during a day. */
    public const TTL_OK = 1800;    // 30 minutes

    /** How long a failure stands — shorter, so a fixed connection recovers soon. */
    public const TTL_FAIL = 600;   // 10 minutes

    /**
     * The cached answer, or null when there isn't a usable one.
     *
     * @param string $path  the cache file
     * @param string $today the venue-local date the caller is asking about
     * @param string $sig   signature of every setting that changes the answer
     */
    public static function read(string $path, string $today, string $sig): ?array
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['payload']) || !is_array($data['payload'])) {
            return null;
        }
        // A different day, or settings that change who counts, means the stored
        // answer is about a different question — not merely an old answer to
        // this one, so no TTL can rescue it.
        if (($data['sig'] ?? '') !== $sig || ($data['payload']['date'] ?? '') !== $today) {
            return null;
        }
        $age = time() - (int)($data['at'] ?? 0);
        $ttl = !empty($data['payload']['available']) ? self::TTL_OK : self::TTL_FAIL;
        // A negative age means the clock went backwards. Treating that as
        // "younger than the TTL" would pin the entry until the clock caught up.
        return ($age >= 0 && $age < $ttl) ? $data['payload'] : null;
    }

    /**
     * Store the answer.
     *
     * Best-effort: a cache that cannot be written just costs a query, so
     * nothing here throws. Written atomically and owner-only — it names
     * employees, like the bots' own state files.
     */
    public static function write(string $path, string $sig, array $payload): void
    {
        if ($path === '') {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode(['at' => time(), 'sig' => $sig, 'payload' => $payload]);
        if ($json === false) {
            return;
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        @chmod($tmp, 0600);
        @rename($tmp, $path);
    }
}
