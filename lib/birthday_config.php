<?php
/**
 * Birthday bot configuration — the single source both the CLI runner and the
 * Birthdays page read from.
 *
 * Values resolve in three layers, each overriding the one before:
 *
 *   1. DEFAULTS below — the bot works with no configuration at all except a
 *      Slack token and channel.
 *   2. data/birthday_config.php, if it exists — how the bot was configured
 *      before this page existed, and still valid for a file-only install.
 *   3. api_config rows keyed `birthday_*` — what the page writes.
 *
 * The page therefore never has to rewrite a PHP file (generating PHP from a web
 * form is a bad idea), and an existing file install keeps working untouched
 * until somebody saves from the page.
 *
 * Secrets (the Slack token, the Giphy key) are encrypted at rest with the same
 * Crypto helper the CenterEdge and MSSQL credentials use, and are never
 * returned to the browser — see publicConfig().
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';

class BirthdayConfig
{
    /** api_config keys all carry this prefix. */
    private const PREFIX = 'birthday_';

    /** @var array<string,string> Non-fatal load problems, keyed by field. */
    private static $warnings = [];

    /**
     * Every editable setting: its type, its default, and how it is stored.
     *
     * The API validates against this and the page renders from it, so a new
     * setting is added in exactly one place.
     *
     * type: bool | int | string | text | enum | list | secret
     *   list   — an array of strings, stored as JSON
     *   text   — multi-line
     *   secret — encrypted at rest, never sent to the browser
     */
    public const FIELDS = [
        'enabled' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Post birthday greetings',
            'help'  => 'Turn off to stop posting without removing the timer.',
        ],
        'slack_bot_token' => [
            'type' => 'secret', 'default' => '',
            'label' => 'Slack bot token',
            'help'  => 'Starts with xoxb-. Needs chat:write.',
        ],
        'slack_channel' => [
            'type' => 'string', 'default' => '',
            'label' => 'Channel',
            'help'  => 'A #name or a channel ID. A name needs the channels:read scope.',
        ],
        'bot_username' => [
            'type' => 'string', 'default' => '',
            'label' => 'Post as',
            'help'  => 'Leave blank to use the Slack app\'s own name. Needs chat:write.customize.',
        ],
        'bot_icon_emoji' => [
            'type' => 'string', 'default' => '',
            'label' => 'Icon emoji',
            'help'  => 'e.g. :birthday: — only used with a custom name.',
        ],
        'mention' => [
            'type' => 'string', 'default' => '',
            'label' => 'Ping prefix',
            'help'  => 'e.g. <!here>. Leave blank for no ping.',
        ],
        'name_style' => [
            'type' => 'enum', 'default' => 'full',
            'options' => ['full' => 'Full name', 'first' => 'First name only', 'first_initial' => 'First name + initial'],
            'label' => 'How names appear',
            'help'  => 'Never an age or a birth year, whichever you pick.',
        ],
        'venue_label' => [
            'type' => 'string', 'default' => 'The Castle Fun Center',
            'label' => 'Venue name',
            'help'  => 'Fills the {venue} placeholder.',
        ],
        'footer_text' => [
            'type' => 'string', 'default' => null,
            'label' => 'Footer line',
            'help'  => 'Blank uses ":balloon: from everyone at {venue}". A single space removes it.',
        ],
        'post_separately' => [
            'type' => 'bool', 'default' => false,
            'label' => 'One message per person',
            'help'  => 'Off means several birthdays on one day share a single post.',
        ],
        'greetings' => [
            'type' => 'list', 'default' => null,
            'label' => 'Greeting lines',
            'help'  => 'One per line, each containing {names}. Blank uses the built-in set.',
        ],
        'flavors' => [
            'type' => 'list', 'default' => null,
            'label' => 'Flavour lines',
            'help'  => 'One per line. Each must stand alone after any greeting.',
        ],
        'multi_greetings' => [
            'type' => 'list', 'default' => null,
            'label' => 'Greeting lines (shared birthday)',
            'help'  => 'Must contain {names}; {count} is available.',
        ],
        'multi_flavors' => [
            'type' => 'list', 'default' => null,
            'label' => 'Flavour lines (shared birthday)',
            'help'  => 'Must read as plural.',
        ],
        'gifs_enabled' => [
            'type' => 'bool', 'default' => true,
            'label' => 'Attach an animated GIF',
        ],
        'giphy_api_key' => [
            'type' => 'secret', 'default' => '',
            'label' => 'Giphy API key',
            'help'  => 'Free, and gives a fresh GIF every day instead of a fixed list.',
        ],
        'gifs' => [
            'type' => 'list', 'default' => null,
            'label' => 'GIF URLs',
            'help'  => 'Used when there is no Giphy key. Blank uses the built-in list.',
        ],
        'add_reactions' => [
            'type' => 'bool', 'default' => false,
            'label' => 'Add the first reactions',
            'help'  => 'Needs the reactions:write scope.',
        ],
        'reactions' => [
            'type' => 'list', 'default' => ['tada', 'birthday'],
            'label' => 'Reaction emoji',
            'help'  => 'Names without colons, one per line.',
        ],
        'leap_day_mode' => [
            'type' => 'enum', 'default' => 'feb28',
            'options' => ['feb28' => 'Wish them on 28 February', 'mar1' => 'Wish them on 1 March', 'skip' => 'Say nothing'],
            'label' => '29 February birthdays, in a non-leap year',
        ],
        'exclude_emp_nos' => [
            'type' => 'list', 'default' => [],
            'label' => 'Opt out by employee number',
            'help'  => 'One per line.',
        ],
        'exclude_names' => [
            'type' => 'list', 'default' => [],
            'label' => 'Opt out by name',
            'help'  => 'One per line, as they appear on the roster.',
        ],
        'ignore_birth_dates' => [
            'type' => 'list', 'default' => [],
            'label' => 'Ignore these birth dates',
            'help'  => 'YYYY-MM-DD, one per line. Placeholder dates the POS stamped in.',
        ],
        'max_celebrants' => [
            'type' => 'int', 'default' => 12, 'min' => 1, 'max' => 100,
            'label' => 'Refuse to post above',
            'help'  => 'More than this many on one day means the query is wrong, not a coincidence.',
        ],
        'roster_sql' => [
            'type' => 'text', 'default' => null,
            'label' => 'Roster query',
            'help'  => 'One read-only SELECT returning current employees. Blank uses the verified default.',
        ],
    ];

    /** The roster query verified against this venue's database. */
    public const DEFAULT_ROSTER_SQL = <<<SQL
SELECT EmpNo AS emp_no,
       FirstName AS first_name,
       LastName AS last_name,
       CONVERT(VARCHAR(10), DateOfBirth, 120) AS birth_date
FROM CenterEdge.dbo.Employees
WHERE EmpStatus = 1
  AND DateOfTerminate IS NULL
  AND DateOfBirth IS NOT NULL
  AND YEAR(DateOfBirth) >= 1901
SQL;

    /** Settings the bot reads that are not editable from the page. */
    private const RUNTIME_DEFAULTS = [
        'roster_max_rows' => 5000,
        'query_timeout'   => 30,
        'gif_verify'      => true,
        'gif_timeout'     => 6,
        'gif_alt_text'    => 'A birthday celebration GIF',
        'giphy_rating'    => 'g',
        'bold_names'      => true,
        'mention_by_email' => false,
        'timezone'        => '',
    ];

    /**
     * The merged configuration, in the shape birthday_bot.php expects.
     *
     * @param string|null $filePath data/birthday_config.php, or null to skip it
     */
    public static function load(?string $filePath = null): array
    {
        $cfg = self::RUNTIME_DEFAULTS;
        foreach (self::FIELDS as $key => $spec) {
            $cfg[$key] = $spec['default'];
        }
        $cfg['roster_sql'] = self::DEFAULT_ROSTER_SQL;

        // Layer 2: the file, if there is one.
        if ($filePath !== null && is_file($filePath)) {
            $fromFile = @include $filePath;
            if (is_array($fromFile)) {
                foreach ($fromFile as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $cfg[$k] = $v;
                    }
                }
            }
        }

        // Layer 3: what the page saved.
        foreach (self::FIELDS as $key => $spec) {
            $stored = self::rawGet($key);
            if ($stored === null) {
                continue;
            }
            $cfg[$key] = self::decode($stored, $spec);
        }

        // state_file / log_file stay derived, never configurable from a form:
        // pointing them anywhere else is a way to write files as the app user.
        $root = dirname(__DIR__);
        $cfg['state_file'] = $root . '/data/birthday_state.json';
        $cfg['log_file']   = $root . '/data/birthdays.log';

        if (!is_string($cfg['roster_sql']) || trim($cfg['roster_sql']) === '') {
            $cfg['roster_sql'] = self::DEFAULT_ROSTER_SQL;
        }
        return $cfg;
    }

    /**
     * The configuration as the browser may see it: secrets replaced by a
     * has-a-value flag, so a token can be changed but never read back out.
     */
    public static function publicConfig(?string $filePath = null): array
    {
        $cfg = self::load($filePath);
        $out = [];
        foreach (self::FIELDS as $key => $spec) {
            if ($spec['type'] === 'secret') {
                $out[$key] = '';
                $out[$key . '_set'] = trim((string)($cfg[$key] ?? '')) !== '';
                continue;
            }
            $out[$key] = $cfg[$key] ?? $spec['default'];
        }
        return $out;
    }

    /** Persist one setting. A null or empty secret leaves the stored one alone. */
    public static function set(string $key, $value): void
    {
        if (!isset(self::FIELDS[$key])) {
            throw new RuntimeException('Unknown setting: ' . $key);
        }
        $spec = self::FIELDS[$key];
        if ($spec['type'] === 'secret') {
            $value = trim((string)$value);
            if ($value === '') {
                return; // "leave as-is", not "clear"
            }
            DB::setConfig(self::PREFIX . $key, $value, true);
            return;
        }
        DB::setConfig(self::PREFIX . $key, self::encode($value, $spec), false);
    }

    /** Explicitly blank a secret (the page's "clear" action). */
    public static function clearSecret(string $key): void
    {
        if (!isset(self::FIELDS[$key]) || self::FIELDS[$key]['type'] !== 'secret') {
            throw new RuntimeException('Not a secret: ' . $key);
        }
        DB::setConfig(self::PREFIX . $key, '', true);
    }

    /**
     * Stored value for a key, or null when it was never saved.
     *
     * DB::getConfig already decrypts anything written with encrypt=true, so
     * there is deliberately no second decrypt here — doing it twice throws,
     * and the catch would turn a perfectly good Slack token into "not set".
     */
    private static function rawGet(string $key): ?string
    {
        try {
            return DB::getConfig(self::PREFIX . $key);
        } catch (Exception $e) {
            // A secret that cannot be decrypted (missing or rotated
            // ENCRYPTION_KEY) must not take the whole bot down — --check exists
            // precisely to diagnose this, and it cannot run if loading the
            // config is fatal. Report it as a warning and read as unset.
            if (self::FIELDS[$key]['type'] === 'secret') {
                self::$warnings[$key] = 'The stored ' . (self::FIELDS[$key]['label'] ?? $key)
                    . ' could not be decrypted (' . $e->getMessage()
                    . '). Re-enter it on the Birthdays page.';
                return null;
            }
            throw $e;
        }
    }

    /** Problems hit while loading, for the health check to surface. */
    public static function warnings(): array
    {
        return array_values(self::$warnings);
    }

    /** Storage form for a value. */
    private static function encode($value, array $spec): string
    {
        switch ($spec['type']) {
            case 'bool':
                return $value ? '1' : '0';
            case 'int':
                return (string)(int)$value;
            case 'list':
                $list = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string)$value);
                $list = array_values(array_filter(array_map('trim', $list), function ($v) {
                    return $v !== '';
                }));
                return (string)json_encode($list);
            default:
                return (string)$value;
        }
    }

    /** Runtime form for a stored value. */
    private static function decode(string $stored, array $spec)
    {
        switch ($spec['type']) {
            case 'bool':
                return $stored === '1';
            case 'int':
                return (int)$stored;
            case 'list':
                $list = json_decode($stored, true);
                return is_array($list) ? $list : [];
            default:
                return $stored;
        }
    }
}
