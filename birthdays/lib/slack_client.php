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

    /** @var string */
    private $token;

    /** @var int */
    private $timeout;

    public function __construct(string $token, int $timeout = 20)
    {
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Slack bot token is empty — set slack_bot_token in config.php.');
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
        $r = $this->call('chat.postMessage', $payload);
        return (string)($r['ts'] ?? '');
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
            $r = $this->call('users.lookupByEmail', ['email' => $email]);
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'users_not_found') !== false) {
                return null;
            }
            throw $e;
        }
        $id = $r['user']['id'] ?? '';
        return $id !== '' ? (string)$id : null;
    }

    /** POST to a Slack method and return the decoded payload, or throw. */
    private function call(string $method, array $payload): array
    {
        $url = self::API_BASE . $method;
        $body = json_encode($payload);
        if ($body === false) {
            throw new RuntimeException('Could not encode the Slack request payload.');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Bearer ' . $this->token,
            ],
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
            'missing_scope'       => ' — add the missing bot-token scope, then REINSTALL the app',
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
