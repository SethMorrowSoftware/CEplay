<?php
/**
 * Where the birthday GIF comes from.
 *
 * Two sources, in preference order:
 *
 *   1. The Giphy API, when a (free) API key is configured. Endless variety,
 *      searched fresh each time, and forced to rating=g — this venue employs
 *      teenagers and posts to a work channel, so the content filter is not
 *      optional.
 *   2. A curated list of GIF URLs in config.php. Works with no setup and no
 *      key, but a fixed list eventually goes stale.
 *
 * EVERY candidate is verified with a HEAD request before it is posted. That is
 * deliberate rather than defensive: a hotlinked GIF can 404 or move at any
 * time, and a broken image block in the birthday message is worse than no
 * image at all. One HEAD request a day costs nothing. If no candidate
 * verifies, the message still posts — just without a GIF.
 */

class GifSource
{
    /**
     * Fallback GIFs, used when no Giphy key is set.
     *
     * These are hotlinked third-party URLs and CANNOT be verified at build
     * time, so treat them as candidates rather than guarantees: the bot HEADs
     * one before using it and moves down the list if it has gone, and
     * `birthday_bot.php --test-gifs` checks the whole list in one go. Swap in
     * your own — any publicly reachable .gif URL works.
     */
    public const DEFAULT_GIFS = [
        'https://media.giphy.com/media/g9582DNuQppxC/giphy.gif',
        'https://media.giphy.com/media/Qvm2704d1Dqus/giphy.gif',
        'https://media.giphy.com/media/26tPplGWjN0xLybiU/giphy.gif',
        'https://media.giphy.com/media/l0MYIAUWRmVVzWLPq/giphy.gif',
        'https://media.giphy.com/media/3o7abKhOpu0NwenH3O/giphy.gif',
        'https://media.giphy.com/media/o75ajIFH0QnQC3nCeD/giphy.gif',
        'https://media.giphy.com/media/JnnpKN3Xw6clPXKfGE/giphy.gif',
        'https://media.giphy.com/media/xUOxfjsW9fWPqEWouI/giphy.gif',
    ];

    /** Search terms rotated through when the Giphy API is in use. */
    public const DEFAULT_SEARCH_TERMS = [
        'happy birthday',
        'birthday party',
        'birthday cake',
        'birthday celebration',
        'confetti celebration',
        'party time',
    ];

    /** How many candidates to try before giving up and posting without a GIF. */
    private const MAX_TRIES = 4;

    /**
     * Choose a GIF for this greeting.
     *
     * $seed makes the choice DETERMINISTIC for a given day and person, so
     * --dry-run shows the same GIF that will actually be posted, and a re-run
     * doesn't silently swap it. Different people on the same day, and the same
     * person next year, get different GIFs.
     *
     * @return array{url: string, source: string}|null null = post without one
     */
    public static function pick(array $cfg, string $seed): ?array
    {
        if (empty($cfg['gifs_enabled'])) {
            return null;
        }
        $verify = $cfg['gif_verify'] ?? true;
        $timeout = max(2, (int)($cfg['gif_timeout'] ?? 6));

        $key = trim((string)($cfg['giphy_api_key'] ?? ''));
        if ($key !== '') {
            $terms = $cfg['gif_search_terms'] ?? self::DEFAULT_SEARCH_TERMS;
            $terms = array_values(array_filter(array_map('strval', (array)$terms)));
            if ($terms) {
                $term = $terms[self::seedIndex($seed . '|term', count($terms))];
                try {
                    $found = self::giphySearch($key, $term, $timeout, (string)($cfg['giphy_rating'] ?? 'g'));
                    $url = self::firstWorking($found, $seed, $verify, $timeout);
                    if ($url !== null) {
                        return ['url' => $url, 'source' => 'giphy:' . $term];
                    }
                } catch (Exception $e) {
                    // Fall through to the curated list — a Giphy outage must
                    // never cost somebody their birthday message.
                }
            }
        }

        $list = $cfg['gifs'] ?? self::DEFAULT_GIFS;
        $list = array_values(array_filter(array_map('trim', array_map('strval', (array)$list))));
        if (!$list) {
            return null;
        }
        $url = self::firstWorking($list, $seed, $verify, $timeout);
        return $url === null ? null : ['url' => $url, 'source' => 'curated'];
    }

    /**
     * Walk the candidate list from a seeded starting point and return the
     * first URL that actually resolves.
     */
    private static function firstWorking(array $urls, string $seed, bool $verify, int $timeout): ?string
    {
        $n = count($urls);
        if ($n === 0) {
            return null;
        }
        $start = self::seedIndex($seed, $n);
        $tries = $verify ? min(self::MAX_TRIES, $n) : 1;
        for ($i = 0; $i < $tries; $i++) {
            $url = $urls[($start + $i) % $n];
            if (!self::looksLikeUrl($url)) {
                continue;
            }
            if (!$verify || self::urlResolves($url, $timeout)) {
                return $url;
            }
        }
        return null;
    }

    /** Stable index from a seed — same seed, same pick, on every run. */
    public static function seedIndex(string $seed, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }
        return (int)(sprintf('%u', crc32($seed)) % $count);
    }

    /** Only https URLs are usable — Slack will not render an http image. */
    public static function looksLikeUrl(string $url): bool
    {
        return (bool)preg_match('#^https://[^\s<>"]+$#i', $url);
    }

    /**
     * Does this URL return an image?
     *
     * HEAD first (cheap); some CDNs refuse HEAD, so fall back to a ranged GET
     * of the first bytes rather than declaring a good URL dead.
     */
    public static function urlResolves(string $url, int $timeout = 6): bool
    {
        [$code, $type] = self::probe($url, $timeout, true);
        if ($code === 405 || $code === 403 || $code === 0) {
            [$code, $type] = self::probe($url, $timeout, false);
        }
        if ($code < 200 || $code >= 300) {
            return false;
        }
        // An empty content-type is odd but not disqualifying; an HTML one means
        // we've been handed a landing page instead of the image.
        return $type === '' || stripos($type, 'image/') === 0;
    }

    /**
     * Can this host reach the open internet at all?
     *
     * Used as a CONTROL before reporting that a whole GIF list is dead. A
     * locked-down venue network, or a proxy that denies the image CDNs, makes
     * every URL look rotten — and "your list is dead, prune it" would then be
     * a wrong answer that costs a perfectly good list. Slack is the right
     * control host: the bot cannot work without it either way.
     */
    public static function internetReachable(int $timeout = 6): bool
    {
        [$code] = self::probe('https://slack.com/api/api.test', $timeout, false);
        return $code >= 200 && $code < 500;
    }

    /** @return array{0:int,1:string} [http code, content-type] */
    private static function probe(string $url, int $timeout, bool $head): array
    {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT      => 'CEplay-birthday-bot/1.0',
        ];
        if ($head) {
            $opts[CURLOPT_NOBODY] = true;
        } else {
            $opts[CURLOPT_RANGE] = '0-1023';
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        $type = trim(explode(';', $type)[0]);
        return [$code, $type];
    }

    /**
     * Search Giphy. Rating is clamped to g/pg — this is a work channel at a
     * family venue, and a config typo must not be able to widen it.
     *
     * @return string[] candidate GIF URLs, best size first
     */
    public static function giphySearch(string $apiKey, string $term, int $timeout = 6, string $rating = 'g'): array
    {
        $rating = in_array(strtolower($rating), ['g', 'pg'], true) ? strtolower($rating) : 'g';
        $url = 'https://api.giphy.com/v1/gifs/search?' . http_build_query([
            'api_key' => $apiKey,
            'q'       => $term,
            'limit'   => 25,
            'rating'  => $rating,
            'bundle'  => 'messaging_non_clips',
            'lang'    => 'en',
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT      => 'CEplay-birthday-bot/1.0',
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Could not reach Giphy: ' . $err);
        }
        if ($code !== 200) {
            throw new RuntimeException('Giphy returned HTTP ' . $code
                . ($code === 401 || $code === 403 ? ' — check giphy_api_key' : ''));
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            throw new RuntimeException('Giphy returned an unreadable response.');
        }
        return self::extractUrls($data['data']);
    }

    /**
     * Pull usable URLs out of a Giphy result set.
     *
     * Prefers `downsized` (capped at 2MB) over `original`: Slack renders both,
     * but a 15MB original is slow to load in the channel for everyone.
     * Pure — unit-tested without touching the network.
     */
    public static function extractUrls(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $images = $item['images'] ?? [];
            foreach (['downsized', 'fixed_height', 'downsized_medium', 'original'] as $size) {
                $u = $images[$size]['url'] ?? '';
                if (is_string($u) && $u !== '') {
                    // Strip Giphy's cache-busting query so the same GIF is a
                    // stable URL from one run to the next.
                    $u = explode('?', $u)[0];
                    if (self::looksLikeUrl($u)) {
                        $out[] = $u;
                        break;
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }
}
