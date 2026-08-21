<?php
/**
 * Birthday Slack bot — configuration.
 *
 *   cp birthdays/config.example.php birthdays/config.php
 *   chmod 600 birthdays/config.php        # it holds your Slack bot token
 *
 * config.php is gitignored. Only 'slack_bot_token', 'slack_channel' and
 * 'roster_sql' really need your attention; everything else has a working
 * default.
 *
 * The MSSQL connection is NOT configured here — the bot reuses the one the
 * app already stores (encrypted) for the Go-Kart Labor / Card Loads reports.
 */

return [

    // -----------------------------------------------------------------------
    // Slack
    // -----------------------------------------------------------------------

    // Bot User OAuth Token from https://api.slack.com/apps -> your app ->
    // "OAuth & Permissions". Starts with xoxb-. Required scope: chat:write
    // (add chat:write.customize only if you set bot_username/bot_icon_emoji
    // below, and users:read.email only if you turn on mention_by_email).
    'slack_bot_token' => 'xoxb-your-bot-token-here',

    // Channel ID — NOT the name. In Slack: right-click the channel ->
    // "View channel details" -> the ID is at the bottom (C0123456789).
    // Invite the bot first: /invite @your-bot-name
    'slack_channel' => 'C0123456789',

    // Optional text prepended to the message, e.g. '<!here>' or '<!channel>'.
    // Leave empty for no ping.
    'mention' => '',

    // Optional display overrides (each needs the chat:write.customize scope).
    'bot_username'   => '',
    'bot_icon_emoji' => '',

    // -----------------------------------------------------------------------
    // Where the roster comes from
    // -----------------------------------------------------------------------

    // A single read-only SELECT returning ONE ROW PER CURRENT EMPLOYEE.
    //
    // Run `php birthdays/discover.php` on the venue server first — it finds
    // the right table, shows what the status column actually contains, and
    // prints a ready-made query to paste in here.
    //
    // Expected output columns (aliases are matched case-insensitively):
    //   emp_no      — employee number. Used to de-duplicate and to remember
    //                 who has already been wished. Optional but recommended.
    //   first_name  — given name       \  or a single full_name column
    //   last_name   — surname          /  ("First Last" or "Last, First")
    //   birth_date  — the date of birth. CONVERT(VARCHAR(10), col, 120) gives
    //                 the clean ISO form the bot prefers.
    //   email       — OPTIONAL. Only needed if mention_by_email is true.
    //
    // The "still employed" filter belongs in this query's WHERE clause. The
    // value below is a STARTING POINT from a typical CenterEdge time-clock
    // schema — confirm the table name and the status value with discover.php
    // before you trust it.
    'roster_sql' => <<<SQL
SELECT EmpNo AS emp_no,
       FirstName AS first_name,
       LastName AS last_name,
       CONVERT(VARCHAR(10), BirthDate, 120) AS birth_date
FROM CenterEdge.dbo.TimeClock_Employees
WHERE Stus = 1
  AND BirthDate IS NOT NULL
  AND YEAR(BirthDate) >= 1901
SQL,

    // Safety cap on how many roster rows to read (a roster is hundreds, not
    // thousands — a much bigger number means the query is pointed at the
    // wrong table).
    'roster_max_rows' => 5000,

    // Seconds before the roster query gives up.
    'query_timeout' => 30,

    // -----------------------------------------------------------------------
    // Message
    // -----------------------------------------------------------------------

    // 'full' (First Last) | 'first' (First) | 'first_initial' (First L.)
    'name_style' => 'full',

    // Wrap names in *bold*. Ignored for names rendered as @-mentions.
    'bold_names' => true,

    // Used by the {venue} placeholder.
    'venue_label' => 'The Castle Fun Center',

    // Placeholders: {names} {count} {venue}
    // No {age} and no birth year, deliberately — the roster holds full dates
    // of birth and a channel is not the place to publish them.
    'message_single' => ":birthday: Happy Birthday, {names}! :tada:\nFrom everyone at {venue} — have a great one!",
    'message_multi'  => ":birthday: {count} birthdays today — Happy Birthday, {names}! :tada:\nFrom everyone at {venue}!",

    // Post one message per person instead of one combined message.
    'post_separately' => false,

    // Turn names into real @-mentions by looking each person up in Slack by
    // email address. Requires the users:read.email scope AND an `email`
    // column in roster_sql. Anyone without a match falls back to their name.
    'mention_by_email' => false,

    // -----------------------------------------------------------------------
    // Behaviour
    // -----------------------------------------------------------------------

    // What to do with 29 February birthdays in a non-leap year:
    //   'feb28' — wish them on 28 February (default)
    //   'mar1'  — wish them on 1 March
    //   'skip'  — say nothing until the next leap year
    'leap_day_mode' => 'feb28',

    // Employee numbers / names that never get a public birthday message.
    // Somebody will ask to be left out; put them here.
    'exclude_emp_nos' => [],
    'exclude_names'   => [],

    // Extra placeholder dates to treat as "birthday unknown". 1900-01-01 and
    // similar are already ignored, as is any year before 1901 — add whatever
    // discover.php flags as suspiciously repeated.
    'ignore_birth_dates' => [],

    // Refuse to post if more than this many people share today's birthday.
    // A real venue has one or two; twenty means the query broke, and the bot
    // should stay quiet and complain rather than spam the channel.
    'max_celebrants' => 12,

    // -----------------------------------------------------------------------
    // Runtime
    // -----------------------------------------------------------------------

    // Which day "today" is. Leave empty to use the app's DEFAULT_TIMEZONE.
    'timezone' => '',

    // Remembers who has already been wished, so a re-run, a manual test or a
    // catch-up timer firing can't post twice.
    'state_file' => __DIR__ . '/../data/birthday_state.json',

    // Optional append-only log. Empty = log to stdout only (systemd captures
    // that in the journal anyway).
    'log_file' => __DIR__ . '/../data/birthdays.log',
];
