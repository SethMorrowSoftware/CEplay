<?php
/**
 * Tests for SlackClient::resolveChannel's offline paths.
 *
 * Resolving a NAME needs a live conversations.list call, so what's covered here
 * is everything that must work WITHOUT one: recognising an ID, pulling an ID
 * out of a pasted Slack link, and rejecting junk. Those are the paths the daily
 * run takes, so they must never depend on the network or on channels:read.
 *
 *   php birthdays/tests/test_slack_channel.php
 */

require_once dirname(__DIR__) . '/lib/slack_client.php';

$pass = 0; $fail = 0;
function t(string $what, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "  ok   {$what}\n"; }
    else {
        $fail++;
        echo "  FAIL {$what}\n         expected: " . var_export($expected, true)
            . "\n         actual:   " . var_export($actual, true) . "\n";
    }
}

$c = new SlackClient('xoxb-not-a-real-token');

echo "\nchannel IDs pass straight through (no API call)\n";
foreach (['C0123456789', 'C07XYZ1234', 'G01ABCDEFGH', 'D01ABCDEFGH'] as $id) {
    $r = $c->resolveChannel($id);
    t("{$id}", $r['id'], $id);
    t("{$id} not marked as looked-up", $r['resolved'], false);
}

echo "\nIDs are pulled out of pasted Slack links\n";
$links = [
    'https://app.slack.com/client/T01ABCDE/C07XYZ1234'            => 'C07XYZ1234',
    'https://app.slack.com/client/T01ABCDE/C07XYZ1234/thread/x'   => 'C07XYZ1234',
    'https://myteam.slack.com/archives/C0123456789'               => 'C0123456789',
    'https://myteam.slack.com/archives/C0123456789?x=1'           => 'C0123456789',
];
foreach ($links as $url => $want) {
    t(substr($url, 0, 46), $c->resolveChannel($url)['id'], $want);
}

echo "\nempty input is rejected before any network call\n";
foreach (['', '   ', '#', '#  '] as $bad) {
    $threw = false;
    try { $c->resolveChannel($bad); } catch (RuntimeException $e) { $threw = true; }
    t('rejects ' . var_export($bad, true), $threw, true);
}

echo "\nnames are NOT mistaken for IDs — they must trigger a lookup\n";
// Asserted through the real method, not a copy of its regex. Without network
// the lookup throws; what matters is that it TRIED, rather than handing the
// name back as though it were an ID. "GENERAL" is the trap: 7 uppercase chars
// starting with G, which a loose ID pattern would happily accept.
foreach (['#birthday-test', 'birthday-test', 'general', 'GENERAL', '#GENERAL', 'CHANNEL'] as $name) {
    $treatedAsId = false;
    try {
        $r = $c->resolveChannel($name);
        $treatedAsId = ($r['resolved'] === false);
    } catch (RuntimeException $e) {
        $treatedAsId = false; // it went looking, which is correct
    }
    t("'{$name}' is looked up, not used verbatim", $treatedAsId, false);
}

echo "\nreal Slack IDs are still recognised\n";
foreach (['C0123456789', 'C07XYZ1234', 'G01ABCDEFGH', 'CABCDEFGH'] as $id) {
    t("{$id} used verbatim", $c->resolveChannel($id)['resolved'], false);
}

echo "\n" . str_repeat('-', 50) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
