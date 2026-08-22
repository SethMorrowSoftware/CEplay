<?php
/**
 * Unit tests for the pure parts of birthdays/lib/gif_source.php.
 *
 * The network-touching methods (urlResolves, giphySearch) can't be tested
 * without internet, so what's covered here is everything that decides WHICH
 * url gets used and whether it's shaped correctly — plus extractUrls, which
 * parses a real Giphy response body without making the request.
 *
 *   php birthdays/tests/test_gif_source.php
 */

require_once dirname(__DIR__) . '/lib/gif_source.php';

$pass = 0;
$fail = 0;

function t(string $what, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "  ok   {$what}\n"; }
    else {
        $fail++;
        echo "  FAIL {$what}\n";
        echo "         expected: " . var_export($expected, true) . "\n";
        echo "         actual:   " . var_export($actual, true) . "\n";
    }
}
function ok(string $what, bool $c): void { t($what, $c, true); }

echo "\nURL shape\n";
ok('https gif',        GifSource::looksLikeUrl('https://media.giphy.com/media/abc/giphy.gif'));
ok('https anything',   GifSource::looksLikeUrl('https://example.com/a.gif?x=1'));
// Slack silently refuses to render a plain-http image block.
ok('http is rejected', !GifSource::looksLikeUrl('http://example.com/a.gif'));
ok('empty rejected',   !GifSource::looksLikeUrl(''));
ok('spaces rejected',  !GifSource::looksLikeUrl('https://example.com/a b.gif'));
ok('quotes rejected',  !GifSource::looksLikeUrl('https://example.com/"a.gif'));
ok('not-a-url',        !GifSource::looksLikeUrl('media.giphy.com/a.gif'));

echo "\nseeded selection\n";
t('index in range',       GifSource::seedIndex('abc', 8) < 8, true);
t('index non-negative',   GifSource::seedIndex('abc', 8) >= 0, true);
t('stable for one seed',  GifSource::seedIndex('abc', 8), GifSource::seedIndex('abc', 8));
t('empty pool -> 0',      GifSource::seedIndex('abc', 0), 0);
t('negative pool -> 0',   GifSource::seedIndex('abc', -3), 0);
// crc32 is unsigned-cast before the modulo; on a 32-bit build a signed value
// would land negative here and index out of the array.
$neg = 0;
foreach (range(1, 500) as $n) {
    if (GifSource::seedIndex('seed-' . $n, 8) < 0) { $neg++; }
}
t('never negative across many seeds', $neg, 0);
$spread = [];
foreach (range(1, 300) as $n) { $spread[GifSource::seedIndex('s' . $n, 8)] = true; }
ok('seeds spread across the pool', count($spread) >= 6);

echo "\nshipped fallback list\n";
$badUrls = [];
foreach (GifSource::DEFAULT_GIFS as $i => $u) {
    if (!GifSource::looksLikeUrl($u)) { $badUrls[] = $i; }
}
t('every default GIF url is well-formed', $badUrls, []);
t('no duplicates in the default list',
    count(GifSource::DEFAULT_GIFS), count(array_unique(GifSource::DEFAULT_GIFS)));
ok('there are several to rotate through', count(GifSource::DEFAULT_GIFS) >= 4);
ok('search terms are provided', count(GifSource::DEFAULT_SEARCH_TERMS) >= 3);

echo "\nGiphy response parsing\n";
$body = [
    // Prefers `downsized` over `original` — a 15MB original loads slowly for
    // everyone in the channel.
    ['images' => [
        'original'  => ['url' => 'https://media.giphy.com/media/AAA/giphy.gif?cid=abc&rid=giphy.gif'],
        'downsized' => ['url' => 'https://media.giphy.com/media/AAA/giphy-downsized.gif?cid=xyz'],
    ]],
    // Falls through the size preferences when downsized is absent.
    ['images' => ['original' => ['url' => 'https://media.giphy.com/media/BBB/giphy.gif']]],
    // Junk entries must not produce junk URLs.
    ['images' => ['original' => ['url' => '']]],
    ['images' => []],
    ['nope' => true],
    'not even an array',
];
$urls = GifSource::extractUrls($body);
t('two usable urls', count($urls), 2);
t('downsized preferred', $urls[0], 'https://media.giphy.com/media/AAA/giphy-downsized.gif');
t('query string stripped', strpos($urls[0], '?'), false);
t('original used as fallback', $urls[1], 'https://media.giphy.com/media/BBB/giphy.gif');
t('empty response -> empty list', GifSource::extractUrls([]), []);

$dupes = GifSource::extractUrls([
    ['images' => ['original' => ['url' => 'https://x/a.gif']]],
    ['images' => ['original' => ['url' => 'https://x/a.gif?cid=1']]],
]);
t('same gif twice is de-duplicated', count($dupes), 1);

echo "\npick() short-circuits\n";
t('disabled -> no gif', GifSource::pick(['gifs_enabled' => false], 'seed'), null);
t('enabled but empty list -> no gif',
    GifSource::pick(['gifs_enabled' => true, 'gifs' => []], 'seed'), null);
// With verification off nothing here touches the network, so the choice is
// pure and testable: it must land on the one URL provided.
$one = GifSource::pick([
    'gifs_enabled' => true, 'gif_verify' => false,
    'gifs' => ['https://example.com/only.gif'],
], 'seed');
t('single-entry list is chosen', $one['url'] ?? null, 'https://example.com/only.gif');
t('source is reported', $one['source'] ?? null, 'curated');
$a = GifSource::pick(['gifs_enabled' => true, 'gif_verify' => false,
    'gifs' => ['https://e/1.gif', 'https://e/2.gif', 'https://e/3.gif']], 'same-seed');
$b = GifSource::pick(['gifs_enabled' => true, 'gif_verify' => false,
    'gifs' => ['https://e/1.gif', 'https://e/2.gif', 'https://e/3.gif']], 'same-seed');
t('same seed -> same gif', $a['url'], $b['url']);
$malformed = GifSource::pick(['gifs_enabled' => true, 'gif_verify' => false,
    'gifs' => ['not-a-url', 'also bad']], 'seed');
t('a list of malformed urls yields no gif', $malformed, null);

echo "\n" . str_repeat('-', 50) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
