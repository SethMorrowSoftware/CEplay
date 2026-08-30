<?php
/**
 * Work-anniversary Slack bot — configuration file.
 *
 *   cp anniversaries/config.example.php data/anniversary_config.php
 *   chmod 600 data/anniversary_config.php      # it holds your Slack bot token
 *
 * You do NOT have to use this file at all. Everything in it can be set on the
 * Work Anniversaries page in CEplay instead, which stores the values in the
 * app database (encrypting the token) and needs no shell access. The file
 * exists for two cases: an install that predates the page, and
 * anniversaries/install.sh, which writes it so the bot can be checked and
 * scheduled before anybody opens a browser.
 *
 * Precedence, if both exist: the page wins. See lib/anniversary_config.php.
 *
 * The MSSQL connection is NOT configured here — the bot reuses the one the app
 * already stores (encrypted) for the Go-Kart Labor / Card Loads reports.
 */

return [

    // -----------------------------------------------------------------------
    // Master switch
    // -----------------------------------------------------------------------

    // false stops the messages without disabling the systemd timer, which is
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
    // The birthday bot's token works here too — the two want the same scopes.
    'slack_bot_token' => 'xoxb-your-bot-token-here',

    // Where to post. A channel NAME is fine — "#general" or "general" — and the
    // bot looks the ID up (that needs the channels:read scope, which the
    // shipped app manifest includes; a private channel also needs groups:read
    // and the bot invited). A raw ID (C0123456789) skips that lookup.
    'slack_channel' => '#your-channel',

    // Optional text prepended to the message, e.g. '<!here>' or '<!channel>'.
    // Leave empty for no ping.
    'mention' => '',

    // What the anniversary posts appear as in Slack. Leave both empty to use
    // the Slack app's own name and icon. Setting a name needs the
    // chat:write.customize scope (add it to the app and REINSTALL — an existing
    // token does not gain a scope). Without the scope the message is STILL
    // posted, just under the app's own name, with a warning in the log.
    'bot_username'   => '',
    'bot_icon_emoji' => '',

    // -----------------------------------------------------------------------
    // Who gets a message
    // -----------------------------------------------------------------------

    // Years of service to start at. 1 means the first anniversary.
    //
    // Do NOT set this to 0. Somebody hired this morning matches today's month
    // and day exactly, and the message would read "0 years" — a start date is
    // not an anniversary.
    'min_years' => 1,

    // 'all'        — every year from min_years up.
    // 'milestones' — ONLY the years in milestone_years below. Much quieter, but
    //                a third year then passes in silence. Note that with this
    //                set and an empty milestone list, nothing can ever post;
    //                --check reports that rather than leaving you to find out.
    'celebrate_years' => 'all',

    // The years that get the louder wording (and, in 'milestones' mode, the
    // only years that post at all). null uses 1, 5, 10, 15, 20, 25, 30, 35,
    // 40, 45, 50.
    'milestone_years' => null,

    // What to do about a 29 February hire date in a non-leap year:
    // 'feb28' | 'mar1' | 'skip'.
    'leap_day_mode' => 'feb28',

    // Opt-outs. Employee numbers and names as they appear on the roster.
    'exclude_emp_nos' => [],
    'exclude_names'   => [],

    // Placeholder hire dates the POS stamped in, as YYYY-MM-DD. The obvious
    // ones (1900-01-01 and friends) are already ignored; add any this venue
    // uses. Getting this wrong is loud here: a 1900 hire date would post a
    // "126 years" message.
    'ignore_hire_dates' => [],

    // If more than this many people share one anniversary date, the bot refuses
    // to post and says so. That many is a wrong query, not a coincidence.
    'max_celebrants' => 12,

    // -----------------------------------------------------------------------
    // The message
    // -----------------------------------------------------------------------

    // 'full' | 'first' | 'first_initial'
    'name_style' => 'full',

    // Fills the {venue} placeholder.
    'venue_label' => 'The Castle Fun Center',

    // The small line under the message. null uses ":trophy: from everyone at
    // {venue}"; a single space removes it entirely.
    'footer_text' => null,

    // true gives each person their own post — and their own milestone wording.
    // false (the default) puts several anniversaries on one day in one message,
    // each name carrying its own year count.
    'post_separately' => false,

    // Wording pools. null uses the built-in sets in lib/anniv_lib.php; the
    // Anniversaries page can load those in for editing rather than making you
    // retype them. A greeting and a flavour line are drawn separately and
    // joined, so every flavour line has to stand on its own after ANY greeting.
    //
    // Placeholders: {names} {count} {venue} {years} {year_label} {ordinal} {s}
    // On a SHARED day {years} is the combined total and {ordinal} is dropped —
    // several people have different numbers, so there is no single ordinal.
    'greetings'           => null,
    'flavors'             => null,
    'milestone_greetings' => null,
    'milestone_flavors'   => null,
    'multi_greetings'     => null,
    'multi_flavors'       => null,

    // -----------------------------------------------------------------------
    // GIFs and reactions
    // -----------------------------------------------------------------------

    'gifs_enabled' => true,

    // A free key from https://developers.giphy.com gives a fresh GIF every
    // time instead of cycling a fixed list. Results are forced to rating=g.
    'giphy_api_key' => '',

    // Used when there is no Giphy key. null uses the built-in list. Any public
    // https .gif works. `--test-gifs` checks whichever list is in use.
    'gifs' => null,

    // Let the bot add the first reactions to its own message, so nobody has to
    // be first. Needs the reactions:write scope.
    'add_reactions' => false,
    'reactions'     => ['tada', 'clap'],

    // -----------------------------------------------------------------------
    // Where the roster comes from
    // -----------------------------------------------------------------------

    // One read-only SELECT returning current employees and their hire date.
    // null uses the default in lib/anniversary_config.php.
    //
    // The columns it must produce (alias them exactly): hire_date, plus either
    // first_name/last_name or a single full-name column. emp_no is optional but
    // makes the "already posted" record and the opt-out list exact.
    //
    // THE HIRE-DATE COLUMN IS THE ONE THING TO CHECK. The default query guesses
    // `DateOfHire` on dbo.Employees, following the naming of the DateOfBirth
    // and DateOfTerminate columns the birthday bot already uses. If this venue
    // calls it something else, MSSQL says so ("Invalid column name") rather
    // than posting anything wrong — and:
    //
    //     sudo bash anniversaries/run.sh discover
    //
    // finds the real column and prints a ready-to-paste query.
    'roster_sql' => null,

];
