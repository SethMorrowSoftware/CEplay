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
    // Master switch
    // -----------------------------------------------------------------------

    // false stops the greetings without disabling the systemd timer, which is
    // what you want when something is wrong with a name, a person has just
    // left, or the channel is being reorganised.
    //
    // Only the POSTING stops: --list, --dry-run, --demo and --check all still
    // run, so you can see exactly what it would say before switching it back
    // on. --check says so in its own row, and refuses to report "everything
    // checks out" while it is off.
    'enabled' => true,

    // -----------------------------------------------------------------------
    // Slack
    // -----------------------------------------------------------------------

    // Bot User OAuth Token from https://api.slack.com/apps -> your app ->
    // "OAuth & Permissions". Starts with xoxb-. Required scope: chat:write.
    // Optional: chat:write.customize to post under a custom name (see
    // bot_username below), channels:read to use a #channel-name instead of an
    // ID, reactions:write for add_reactions, users:read.email for
    // mention_by_email.
    'slack_bot_token' => 'xoxb-your-bot-token-here',

    // Where to post. A channel NAME is fine — "#birthday-test" or
    // "birthday-test" — and the bot looks the ID up (that needs the
    // channels:read scope, which the shipped app manifest includes; a private
    // channel also needs groups:read and the bot invited).
    //
    // A raw ID (C0123456789) works too and is marginally faster, since it
    // skips that lookup on every run. install.sh resolves whatever you type
    // and stores the ID for you. To find one by hand: right-click the channel
    // in Slack -> View channel details -> the ID is at the bottom.
    'slack_channel' => '#your-channel',

    // Optional text prepended to the message, e.g. '<!here>' or '<!channel>'.
    // Leave empty for no ping.
    'mention' => '',

    // What the birthday posts appear as in Slack.
    //
    // By default they show the Slack APP's own name — which is a problem if you
    // are reusing one shared app for several integrations, since birthday
    // wishes would arrive from something like "monitors". Setting a name here
    // overrides it per message, so the same token can post as "Birthday Bot".
    //
    //     'bot_username'   => 'Birthday Bot',
    //     'bot_icon_emoji' => ':birthday:',
    //
    // Needs the chat:write.customize scope (add it to the app and REINSTALL —
    // an existing token does not gain a scope). If the scope is missing the
    // greeting is STILL posted, just under the app's own name, with a warning
    // in the log — a birthday message under the wrong name beats no message.
    // Leave both empty to use the app's name and icon.
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
    // The "still employed" filter belongs in this query's WHERE clause.
    //
    // VERIFIED against this venue's database (August 2026), so the query below
    // is not a guess:
    //   - the staff roster is `dbo.Employees` (NOT TimeClock_Employees, which
    //     does not exist here — the time-clock module is punches + scheduling);
    //   - the birthday column is `DateOfBirth` (datetime);
    //   - `EmpStatus` is an int keyed to the `dbo.EmployeeStatus` lookup table,
    //     which spells the codes out: 1 = Active, 2 = Suspended, 3 = Terminated.
    //     So `EmpStatus = 1` IS "still employed", straight from the database.
    //   - `DateOfTerminate` is the leaving date; it is included as a second,
    //     redundant condition. Run discover.php to confirm the two agree on
    //     your data — it measures that and tells you if they don't.
    //
    // Every OTHER birthday column in this schema belongs to the guest side of
    // the house (Customers, ChildCustomers, GroupChildren, the waiver tables,
    // TicketDetails). Don't point the bot at any of them.
    //
    // Note what else lives on this table: SSN, PasswordHash, PinHash,
    // FingerprintTemplate, Picture. Select the four columns below and nothing
    // more — there is no reason for the rest to leave the database.
    'roster_sql' => <<<SQL
SELECT EmpNo AS emp_no,
       FirstName AS first_name,
       LastName AS last_name,
       CONVERT(VARCHAR(10), DateOfBirth, 120) AS birth_date
FROM CenterEdge.dbo.Employees
WHERE EmpStatus = 1
  AND DateOfTerminate IS NULL
  AND DateOfBirth IS NOT NULL
  AND YEAR(DateOfBirth) >= 1901
SQL,

    // Want real @-mentions? Set mention_by_email below AND add an email column
    // to the query above. This table has two, so prefer the work address and
    // fall back to the personal one:
    //     COALESCE(WorkEMailAddress, EMailAddress) AS email,
    // Leave it out while mention_by_email is false — no point moving staff
    // email addresses around for a feature that isn't switched on.

    // Safety cap on how many roster rows to read (a roster is hundreds, not
    // thousands — a much bigger number means the query is pointed at the
    // wrong table).
    'roster_max_rows' => 5000,

    // Seconds before the roster query gives up.
    'query_timeout' => 30,

    // -----------------------------------------------------------------------
    // Message — the fun part
    // -----------------------------------------------------------------------

    // 'full' (First Last) | 'first' (First) | 'first_initial' (First L.)
    //
    // Worth a thought here: this roster runs from teens to sixties. The bot
    // never publishes an age or a birth year, but if the channel is wide or
    // has guests in it, 'first' reads better than naming a 15-year-old in full.
    'name_style' => 'full',

    // Wrap names in *bold*. Ignored for names rendered as @-mentions.
    'bold_names' => true,

    // Used by the {venue} placeholder.
    'venue_label' => 'The Castle Fun Center',

    // The message is composed from TWO pools: a greeting line and a flavour
    // line, drawn independently and joined. That is 14 x 51 = 714 different
    // single-person messages out of 65 written lines — adding one good flavour
    // line adds fourteen new messages. See BDAY_GREETINGS / BDAY_FLAVORS in
    // lib/birthday_lib.php (and the BDAY_MULTI_* pools for shared birthdays).
    //
    // The pick is deterministic from the date and the people celebrating, so
    // --dry-run shows exactly what will post and a re-run never swaps it.
    //
    // To add your own lines, uncomment these. A greeting MUST contain {names};
    // a flavour line must stand on its own after any greeting, so write it as a
    // complete sentence that never refers back to the line above. Placeholders:
    // {names} {count} {venue}. Never add an age or a birth year.
    //
    // 'greetings' => [
    //     ":birthday: Happy Birthday, {names}! :tada:",
    // ],
    // 'flavors' => [
    //     "Hope the karts are fast and the tickets plentiful.",
    //     "Somebody put a candle in the pizza.",
    // ],
    // 'multi_greetings' => [...],  // must contain {names}
    // 'multi_flavors'   => [...],  // must read as plural
    //
    // An empty 'flavors' => [] posts the greeting alone.
    //
    // Or replace composition entirely with whole templates:
    // 'messages_single' => [":birthday: Happy Birthday, {names}!"],
    // 'messages_multi'  => [":birthday: Happy Birthday, {names}!"],
    //
    // Or pin ONE exact wording, which overrides everything above:
    // 'message_single' => ":birthday: Happy Birthday, {names}!",
    // 'message_multi'  => ":birthday: Happy Birthday, {names}!",

    // Small line under the message. null = ":balloon: from everyone at {venue}".
    // Empty string = no footer.
    'footer_text' => null,

    // Post one message per person instead of one combined message. Each gets
    // its own quip and its own GIF.
    'post_separately' => false,

    // -----------------------------------------------------------------------
    // Animated GIFs
    // -----------------------------------------------------------------------

    // Attach an animated GIF to each greeting (a Slack image block, which
    // animates). Set false for text only.
    'gifs_enabled' => true,

    // BEST OPTION: a free Giphy API key (https://developers.giphy.com — takes
    // about two minutes). With one set, the bot searches for a fresh GIF every
    // time instead of cycling a fixed list, and never goes stale. Without one
    // it falls back to the curated list below.
    'giphy_api_key' => '',

    // Content rating for Giphy results. 'g' or 'pg' only — anything else is
    // forced back to 'g'. This is a work channel at a family venue with minors
    // on staff; leave it at 'g'.
    'giphy_rating' => 'g',

    // Rotated through when searching Giphy, so the results stay varied.
    // 'gif_search_terms' => ['happy birthday', 'birthday cake', 'confetti celebration'],

    // Fallback list, used when there's no Giphy key (or Giphy is down).
    // Defaults to GifSource::DEFAULT_GIFS. These are hotlinked third-party
    // URLs and CAN rot, so run `php birthdays/birthday_bot.php --test-gifs` to
    // see which still resolve, and paste your own here to replace them —
    // any public https .gif URL works.
    // 'gifs' => [
    //     'https://media.giphy.com/media/xxxxxxxx/giphy.gif',
    // ],

    // HEAD-check the chosen GIF before posting, and move to the next candidate
    // if it has gone. Costs one request a day and means a dead link degrades to
    // "no image" instead of a broken one in the channel. Leave this on.
    'gif_verify' => true,

    // Seconds to allow for the GIF check / Giphy search.
    'gif_timeout' => 6,

    // What screen readers announce for the image.
    'gif_alt_text' => 'A birthday celebration GIF',

    // -----------------------------------------------------------------------
    // Reactions
    // -----------------------------------------------------------------------

    // Have the bot add the first reactions to its own message, so nobody has
    // to break the ice. Needs the reactions:write scope — add it and REINSTALL
    // the app. Best-effort: if it fails, the greeting still posts.
    'add_reactions' => false,
    'reactions' => ['tada', 'birthday'],

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
