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

// ---------------------------------------------------------------------------
// Retry classification.
//
// Getting this wrong is expensive in both directions: retrying a revoked token
// burns the posting window three times over, and NOT retrying a rate limit
// costs the greeting outright. It is pure, so it is checked here rather than
// discovered at 09:00 on somebody's birthday.
echo "\nfailures worth retrying\n";
t('rate limited (429)',        SlackClient::isRetryable(429, 0), true);
t('server error (500)',        SlackClient::isRetryable(500, 0), true);
t('bad gateway (502)',         SlackClient::isRetryable(502, 0), true);
t('could not connect',         SlackClient::isRetryable(0, 7), true);
t('could not resolve host',    SlackClient::isRetryable(0, 6), true);
t('timed out',                 SlackClient::isRetryable(0, 28), true);
t('ok:false ratelimited',      SlackClient::isRetryable(200, 0, 'ratelimited'), true);
t('ok:false service_unavailable', SlackClient::isRetryable(200, 0, 'service_unavailable'), true);
t('ok:false internal_error',   SlackClient::isRetryable(200, 0, 'internal_error'), true);

echo "\nfailures that will fail again just as fast\n";
t('invalid_auth',      SlackClient::isRetryable(200, 0, 'invalid_auth'), false);
t('token_revoked',     SlackClient::isRetryable(200, 0, 'token_revoked'), false);
t('channel_not_found', SlackClient::isRetryable(200, 0, 'channel_not_found'), false);
t('not_in_channel',    SlackClient::isRetryable(200, 0, 'not_in_channel'), false);
t('missing_scope',     SlackClient::isRetryable(200, 0, 'missing_scope'), false);
t('msg_too_long',      SlackClient::isRetryable(200, 0, 'msg_too_long'), false);
t('users_not_found',   SlackClient::isRetryable(200, 0, 'users_not_found'), false);
t('not found (404)',   SlackClient::isRetryable(404, 0), false);

echo "\nbackoff\n";
$backoff = new ReflectionMethod('SlackClient', 'backoffFor');
$backoff->setAccessible(true);
t('first wait',                 $backoff->invoke(null, 1, 0), 2);
t('second wait is longer',      $backoff->invoke(null, 2, 0), 6);
t("Slack's Retry-After wins",   $backoff->invoke(null, 1, 20), 20);
t('a huge Retry-After is capped', $backoff->invoke(null, 1, 9999), 30);
t('a tiny Retry-After does not shorten the backoff', $backoff->invoke(null, 2, 1), 6);

echo "\n" . str_repeat('-', 50) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
