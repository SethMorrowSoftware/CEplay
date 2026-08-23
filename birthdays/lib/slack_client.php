<?php
/**
 * Minimal Slack Web API client for the birthday bot.
 *
 * Bot token (`xoxb-…`) only, three endpoints, no dependencies — same spirit as
 * alerts/ceplay_game_alerts.py, which already posts to Slack from this repo.
 *
 * Slack answers HTTP 200 even when it refuses the call, putting the real
 * outcome in the JSON `ok` field, so every method here checks `ok` rather
 * than the status code.
 */

class SlackClient
{
    private const API_BASE = 'https://slack.com/api/';

    /**
     * How many times to try a call that failed in a way that might pass on a
     * second attempt.
     *
     * Kept small on purpose. The point is to survive a blip — a DNS hiccup, a
     * rate limit, Slack having a bad thirty seconds — not to sit in a loop
     * while a systemd job holds a lock. Three attempts with the backoff below
     * spends at most about ten seconds before giving up and saying so.
     */
    private const MAX_ATTEMPTS = 3;

    /** Seconds to wait before attempt 2 and attempt 3. */
    private const BACKOFF = [2, 6];

    /** Never sleep longer than this for a Retry-After Slack sends us. */
    private const MAX_RETRY_AFTER = 30;

    /** @var string */
    private $token;

    /** @var int */
    private $timeout;

    /** @var string[] What was retried and why, for the caller to log. */
    private $retryNotes = [];

    /** @var string Channel ID Slack reported for the last postMessage. */
    private $lastChannelId = '';

    /** @var string Why a display-name override was dropped, if it was. */
    private $customizeDropped = '';

    public function __construct(string $token, int $timeout = 20)
    {
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('No Slack bot token configured — set one on the '
                . 'Birthdays page in CEplay, or in data/birthday_config.php.');
        }
        if (strpos($token, 'xoxb-') !== 0) {
            throw new RuntimeException('Slack token does not look like a bot token (expected it to start with "xoxb-").');
        }
        $this->token = $token;
        $this->timeout = max(5, $timeout);
    }

    /**
     * Verify the token and return the bot's identity.
     * @return array{team: string, user: string, bot_id: string}
     */
    public function authTest(): array
    {
        $r = $this->call('auth.test', []);
        return [
            'team'   => (string)($r['team'] ?? ''),
            'user'   => (string)($r['user'] ?? ''),
            'bot_id' => (string)($r['bot_id'] ?? ''),
        ];
    }

    /**
     * Post a message. $channel should be a channel ID (C…/G…), not a name —
     * names only resolve for some token types and fail confusingly otherwise.
     *
     * $text is ALWAYS sent, even when blocks are supplied: it is what push
     * notifications and screen readers use, and a blocks-only message arrives
     * on a phone as an empty notification.
     *
     * @param array $opts blocks (array), username / icon_emoji (both need the
     *                    chat:write.customize scope), thread_ts
     * @return string The posted message timestamp.
     */
    public function postMessage(string $channel, string $text, array $opts = []): string
    {
        $payload = [
            'channel'      => $channel,
            'text'         => $text,
            // The GIF is an image BLOCK, so link previews stay off: unfurling
            // would add a second, redundant copy of it under the message.
            'unfurl_links' => false,
            'unfurl_media' => false,
        ];
        if (!empty($opts['blocks']) && is_array($opts['blocks'])) {
            $payload['blocks'] = $opts['blocks'];
        }
        foreach (['username', 'icon_emoji', 'thread_ts'] as $k) {
            if (!empty($opts[$k])) {
                $payload[$k] = $opts[$k];
            }
        }
        $this->customizeDropped = '';
        try {
            $r = $this->call('chat.postMessage', $payload);
        } catch (RuntimeException $e) {
            // Overriding the display name needs chat:write.customize. If that
            // scope is missing, send the message WITHOUT the override rather
            // than not at all — a greeting under the wrong bot name still beats
            // silence on somebody's birthday. The caller logs why.
            $customised = isset($payload['username']) || isset($payload['icon_emoji']);
            if ($customised && strpos($e->getMessage(), 'missing_scope') !== false) {
                $this->customizeDropped = 'The custom display name needs the chat:write.customize'
                    . ' scope, which this token lacks — posted under the app\'s own name instead.'
                    . ' Add the scope and REINSTALL the app, or clear bot_username.';
                unset($payload['username'], $payload['icon_emoji']);
                $r = $this->call('chat.postMessage', $payload);
            } else {
                throw $e;
            }
        }
        // Slack echoes the channel it actually delivered to. When a NAME was
        // passed, that response is the cheapest way to learn the ID.
        $this->lastChannelId = (string)($r['channel'] ?? '');
        return (string)($r['ts'] ?? '');
    }

    /**
     * Empty unless the last postMessage had to drop its display-name override;
     * otherwise the reason, ready to log.
     */
    public function customizeDropped(): string
    {
        return $this->customizeDropped;
    }

    /** The channel ID Slack resolved for the most recent postMessage. */
    public function lastChannelId(): string
    {
        return $this->lastChannelId;
    }

    /**
     * Add an emoji reaction to a message. Needs the reactions:write scope.
     *
     * Returns false rather than throwing on the everyday failures (already
     * reacted, unknown emoji name, scope not granted) — a missing party popper
     * is not worth failing a birthday post over.
     */
    public function addReaction(string $channel, string $ts, string $emoji): bool
    {
        $emoji = trim($emoji, ": \t\n");
        if ($emoji === '' || $ts === '') {
            return false;
        }
        try {
            $this->call('reactions.add', [
                'channel'   => $channel,
                'timestamp' => $ts,
                'name'      => $emoji,
            ]);
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * Resolve an email address to a Slack user ID, or null when there is no
     * match. Needs the users:read.email scope; a missing scope is a hard error
     * (the operator asked for mentions and should hear that it isn't working),
     * but "no such user" is just null — plenty of staff won't have Slack.
     */
    public function lookupByEmail(string $email): ?string
    {
        $email = trim($email);
        if ($email === '' || strpos($email, '@') === false) {
            return null;
        }
        try {
            $r = $this->callGet('users.lookupByEmail', ['email' => $email]);
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'users_not_found') !== false) {
                return null;
            }
            throw $e;
        }
        $id = $r['user']['id'] ?? '';
        return $id !== '' ? (string)$id : null;
    }

    /**
     * Turn whatever the operator typed into a real channel ID.
     *
     * Accepts an ID (`C0123456789` — C/G/D plus 8+ characters, which is what
     * makes a short all-caps word like "GENERAL" a NAME to look up rather than
     * an ID to use verbatim), a name (`#birthday-test` or
     * `birthday-test`), or a channel link copied out of Slack. Only a name
     * costs an API call, and the caller is expected to store the ID it gets
     * back — the daily run should never depend on a lookup, or on the
     * channels:read scope, just to post a greeting.
     *
     * @return array{id: string, name: string, resolved: bool}
     */
    public function resolveChannel(string $input): array
    {
        $raw = trim($input);
        if ($raw === '') {
            throw new RuntimeException('No channel given.');
        }

        // A pasted channel link: .../client/T01ABCDE/C07XYZ1234
        // Delimiter is ~ deliberately: the pattern contains a literal # (the
        // fragment marker in a URL), which would end a #-delimited pattern.
        if (preg_match('~/([CGD][A-Z0-9]{8,})(?:[/?#]|$)~', $raw, $m)) {
            return ['id' => $m[1], 'name' => '', 'resolved' => false];
        }
        // A bare ID.
        if (preg_match('/^[CGD][A-Z0-9]{8,}$/', $raw)) {
            return ['id' => $raw, 'name' => '', 'resolved' => false];
        }

        $want = ltrim($raw, '#@');
        // Slack channel names are lowercase; typing #Birthday-Test should work.
        $wantKey = strtolower($want);
        if ($wantKey === '') {
            throw new RuntimeException('No channel given.');
        }

        $channels = $this->listChannels();
        foreach ($channels as $c) {
            if (strtolower($c['name']) === $wantKey) {
                return ['id' => $c['id'], 'name' => $c['name'], 'resolved' => true];
            }
        }

        // Not found — say what IS there rather than just "no".
        $near = [];
        foreach ($channels as $c) {
            similar_text($wantKey, strtolower($c['name']), $pct);
            if ($pct >= 55) {
                $near[$c['name']] = $pct;
            }
        }
        arsort($near);
        $near = array_slice(array_keys($near), 0, 4);

        $msg = 'No channel called "#' . $want . '" that this bot can see';
        if ($near) {
            $msg .= '. Did you mean: #' . implode(', #', $near) . '?';
        } elseif (!$channels) {
            $msg .= ' — and the bot cannot see any channels at all, which usually'
                . ' means the channels:read scope is missing.';
        } else {
            $msg .= ' (it can see ' . count($channels) . ' channel(s)).'
                . ' For a PRIVATE channel the bot must be invited first: /invite @your-bot-name';
        }
        throw new RuntimeException($msg);
    }

    /**
     * Every channel this token can see. Public channels need channels:read;
     * private ones need groups:read AND the bot to be a member.
     *
     * A missing scope returns an empty list rather than throwing, so the caller
     * can fall back to asking for an ID instead of dead-ending.
     *
     * @return array<int, array{id: string, name: string, private: bool}>
     */
    public function listChannels(int $maxPages = 10): array
    {
        $out = [];
        $cursor = '';
        for ($page = 0; $page < $maxPages; $page++) {
            $args = [
                'exclude_archived' => 'true',
                'limit'            => 1000,
                'types'            => 'public_channel,private_channel',
            ];
            if ($cursor !== '') {
                $args['cursor'] = $cursor;
            }
            try {
                $r = $this->callGet('conversations.list', $args);
            } catch (RuntimeException $e) {
                $m = $e->getMessage();
                if (strpos($m, 'missing_scope') !== false || strpos($m, 'not_allowed_token_type') !== false) {
                    return $out; // caller falls back to asking for an ID
                }
                throw $e;
            }
            foreach (($r['channels'] ?? []) as $c) {
                if (!empty($c['id']) && !empty($c['name'])) {
                    $out[] = [
                        'id'      => (string)$c['id'],
                        'name'    => (string)$c['name'],
                        'private' => !empty($c['is_private']),
                    ];
                }
            }
            $cursor = (string)($r['response_metadata']['next_cursor'] ?? '');
            if ($cursor === '') {
                break;
            }
        }
        return $out;
    }

    /**
     * GET a Slack method with query parameters.
     *
     * Slack's read methods (conversations.list, users.lookupByEmail) are
     * documented as accepting application/x-www-form-urlencoded, NOT JSON —
     * posting a JSON body to them is not reliable. The write methods
     * (chat.postMessage, reactions.add) do take JSON, and keep using call().
     */
    private function callGet(string $method, array $args): array
    {
        return $this->request($method, null, $args);
    }

    /** POST to a Slack method and return the decoded payload, or throw. */
    private function call(string $method, array $payload): array
    {
        return $this->request($method, $payload, null);
    }

    /**
     * Run one Slack call, retrying the failures that are worth retrying.
     *
     * This exists because the bot gets ONE shot a day at a thing that cannot
     * be done late. A single dropped connection at 09:00 used to mean the
     * greeting simply did not happen, and the only sign of it was a line in a
     * log file. Three attempts over a few seconds turn the overwhelmingly
     * common failures — a blip, a rate limit — into a delay nobody notices.
     */
    private function request(string $method, ?array $jsonPayload, ?array $queryArgs): array
    {
        $this->retryNotes = [];
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            [$raw, $err, $errno, $code, $retryAfter] =
                $this->transport($method, $jsonPayload, $queryArgs);

            // A Slack-level refusal arrives as HTTP 200 with ok:false, so the
            // body has to be read before deciding whether this is retryable.
            $slackError = '';
            if ($raw !== false && $errno === 0) {
                $data = json_decode((string)$raw, true);
                if (is_array($data) && empty($data['ok'])) {
                    $slackError = (string)($data['error'] ?? '');
                }
            }

            $failed = ($raw === false || $errno !== 0 || $code < 200 || $code >= 300 || $slackError !== '');
            if (!$failed) {
                return $this->decode($method, $raw, $err, $code);
            }

            $why = $slackError !== '' ? $slackError : ($errno !== 0 ? $err : 'HTTP ' . $code);
            $lastError = [$raw, $err, $code];

            if ($attempt >= self::MAX_ATTEMPTS || !self::isRetryable($code, $errno, $slackError)) {
                break;
            }
            $wait = self::backoffFor($attempt, $retryAfter);
            $this->retryNotes[] = $method . ' failed (' . $why . '); retrying in '
                . $wait . 's (attempt ' . ($attempt + 1) . ' of ' . self::MAX_ATTEMPTS . ')';
            sleep($wait);
        }

        // Out of attempts: hand the last response to decode(), which turns it
        // into the same message — with the same fix-it hint — it always did.
        return $this->decode($method, $lastError[0], (string)$lastError[1], (int)$lastError[2]);
    }

    /**
     * One HTTP attempt.
     *
     * @return array{0: string|false, 1: string, 2: int, 3: int, 4: int}
     *         [body, curl error, curl errno, http code, Retry-After seconds]
     */
    private function transport(string $method, ?array $jsonPayload, ?array $queryArgs): array
    {
        $isPost = $jsonPayload !== null;
        $url = self::API_BASE . $method
             . ($queryArgs !== null ? '?' . http_build_query($queryArgs) : '');

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: ' . ($isPost
                    ? 'application/json; charset=utf-8'
                    : 'application/x-www-form-urlencoded; charset=utf-8'),
                'Authorization: Bearer ' . $this->token,
            ],
        ];
        if ($isPost) {
            $body = json_encode($jsonPayload);
            if ($body === false) {
                throw new RuntimeException('Could not encode the Slack request payload.');
            }
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        // Slack says how long a rate limit lasts; guessing is worse.
        $retryAfter = 0;
        $opts[CURLOPT_HEADERFUNCTION] = function ($ch, $header) use (&$retryAfter) {
            if (stripos($header, 'retry-after:') === 0) {
                $retryAfter = (int)trim(substr($header, 12));
            }
            return strlen($header);
        };

        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $raw   = curl_exec($ch);
        $err   = curl_error($ch);
        $errno = (int)curl_errno($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$raw, $err, $errno, $code, $retryAfter];
    }

    /**
     * Is this failure worth another attempt?
     *
     * Pure and static so it can be unit-tested without a network — the
     * classification is the whole substance of the retry, and getting it wrong
     * in either direction is expensive: retrying a bad token wastes the
     * window, and not retrying a rate limit costs the greeting.
     *
     * Retryable means "Slack demonstrably did not accept this": a rate limit,
     * a 5xx, or a connection that never got established. Deterministic
     * rejections — a revoked token, a channel the bot isn't in, a message over
     * the length limit — will fail identically forever, so they are reported
     * at once rather than three times more slowly.
     *
     * ON DUPLICATES: a read timeout after the request was sent is genuinely
     * ambiguous — Slack may have posted the message and we simply never heard
     * back. It is retried anyway, deliberately. This bot already accepts that
     * trade in the same direction (a partial failure is retried on the next
     * firing), and for a birthday greeting a rare double post is a much smaller
     * failure than a silent miss.
     *
     * @param int    $code       HTTP status, 0 when the request never completed
     * @param int    $errno      curl error number, 0 when curl was happy
     * @param string $slackError the `error` field from an ok:false response
     */
    public static function isRetryable(int $code, int $errno, string $slackError = ''): bool
    {
        if ($errno !== 0) {
            // Transport-level: DNS, connect, TLS, timeouts, dropped
            // connections. All of these are "try again in a moment".
            return true;
        }
        if ($code === 429 || $code >= 500) {
            return true;
        }
        // Slack sometimes answers 200 with a transient error in the body.
        return in_array($slackError, [
            'ratelimited', 'rate_limited', 'service_unavailable',
            'internal_error', 'fatal_error', 'request_timeout',
        ], true);
    }

    /** How long to wait before the next attempt, honouring Retry-After. */
    private static function backoffFor(int $attempt, int $retryAfter): int
    {
        // Copied to a local first: end() takes its argument by reference, so
        // it cannot be handed a class constant.
        $steps = self::BACKOFF;
        $wait = $steps[$attempt - 1] ?? $steps[count($steps) - 1];
        if ($retryAfter > 0) {
            // Slack's own number wins when it gives one — it knows when the
            // window reopens and guessing shorter just burns an attempt.
            $wait = max($wait, min($retryAfter, self::MAX_RETRY_AFTER));
        }
        return $wait;
    }

    /**
     * Anything that had to be retried on the last successful call, ready to
     * log. Empty when everything worked first time.
     */
    public function retryNotes(): array
    {
        return $this->retryNotes;
    }

    /** Shared response handling for both transports. */
    private function decode(string $method, $raw, string $err, int $code): array
    {
        if ($raw === false) {
            throw new RuntimeException('Could not reach Slack (' . $method . '): ' . $err);
        }
        if ($code === 429) {
            throw new RuntimeException('Slack rate-limited the request (' . $method . '). Try again in a minute.');
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Slack returned an unreadable response to ' . $method
                . ' (HTTP ' . $code . '): ' . substr((string)$raw, 0, 200));
        }
        if (empty($data['ok'])) {
            throw new RuntimeException('Slack rejected ' . $method . ': '
                . ($data['error'] ?? 'unknown error') . self::hint((string)($data['error'] ?? '')));
        }
        return $data;
    }

    /** Turn the common Slack error codes into the fix, inline. */
    private static function hint(string $error): string
    {
        $hints = [
            'not_in_channel'      => ' — invite the bot to the channel: /invite @your-bot-name',
            'channel_not_found'   => ' — use the channel ID (C0123456789), not the channel name',
            'invalid_auth'        => ' — the bot token is wrong or has been revoked',
            'account_inactive'    => ' — the Slack app was uninstalled or the token was revoked',
            'token_revoked'       => ' — reinstall the app and copy the new xoxb- token',
            'missing_scope'       => ' — add the missing bot-token scope, then REINSTALL the app'
                                     . ' (channel names need channels:read; private channels also need groups:read)',
            'not_allowed_token_type' => ' — this needs a bot token (xoxb-), not a user token',
            'restricted_action'   => ' — workspace settings block the bot from posting here',
            'is_archived'         => ' — the channel is archived',
            'invalid_blocks'      => ' — Slack rejected the Block Kit payload (check the GIF URL is https)',
            'invalid_blocks_format' => ' — the blocks payload is malformed',
            'msg_too_long'        => ' — the message text is over Slack\'s limit; shorten the template',
        ];
        return $hints[$error] ?? '';
    }
}
