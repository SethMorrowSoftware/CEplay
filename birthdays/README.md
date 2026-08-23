# Employee Birthday Slack Bot

Posts a birthday greeting to Slack each morning for anyone on the CenterEdge
employee roster whose birthday is today **and** whose employment-status column
still says they work here — with an animated GIF and a rotating set of
arcade-flavoured one-liners, so it reads like the place you work rather than a
cron job.

It **does not change the CEplay app**. It borrows two things and writes nothing
back: the app's stored (encrypted) MSSQL connection, and its read-only
`MssqlClient`. Every query it runs is a plain `SELECT` behind the same
single-statement guard the Go-Kart Labor and Card Loads reports use.

```
birthdays/
├─ install.sh             One-command installer (also --uninstall)
├─ run.sh                 Run any command inside the MSSQL-enabled container
├─ slack-app-manifest.yml Paste into Slack to create the app with its scopes
├─ birthday_bot.php       The daily runner
├─ discover.php           Re-derive the roster query from the live database
├─ config.example.php     Copy to data/birthday_config.php and fill in
├─ EXPLORER-QUERIES.md    The schema discovery, as Explorer copy/paste SQL
├─ lib/                   Date matching, message building, GIFs, Slack
├─ systemd/               Daily timer units
└─ tests/                 310 assertions — run anywhere, no database needed
```

---

# Installing

Two commands.

## 1. Put the code on the server

The bot lives on the branch `claude/birthday-slackbot-employee-db-r69vnu`, so
point the source clone at it and deploy the normal way:

```bash
cd /var/persist/pause-groups-src
sudo git fetch origin
sudo git checkout claude/birthday-slackbot-employee-db-r69vnu
sudo bash update.sh
```

(Once you're happy with it, merge the branch to `main` and put the clone back
on `main` — after that the bot rides along with every routine `update.sh`.)

## 2. Run the installer

```bash
sudo bash /var/persist/pause-groups/birthdays/install.sh
```

That's it. It asks a handful of questions, then does everything else:

- checks podman, the MSSQL driver image and the app's database connection,
  and stops with the exact fix if any are missing;
- takes your Slack token and channel, and **verifies both before writing
  anything**;
- writes the config to the right place with the right owner and permissions;
- runs a full health check and offers a test post;
- installs and starts the daily timer at whatever time you choose.

It is safe to re-run — use it later to change the channel, the posting time or
the name style. `--uninstall` removes the timer and leaves your config alone.

### What it will ask you

| | |
|---|---|
| **Slack bot token** | `xoxb-…`. It points you at `slack-app-manifest.yml` — paste that into **Create New App → From an app manifest** and Slack sets the name and every permission for you, so there is no scope-hunting. |
| **Channel** | Just the name: `#birthday-test`. A pasted channel link or a raw `C0123456789` work too. It looks the name up and stores the ID, so the daily run never has to. |
| **How names appear** | Full name, first name only, or first + initial. |
| **Giphy API key** | Optional. A [free key](https://developers.giphy.com) gets a fresh GIF daily instead of a fixed list. Press Enter to skip. |
| **Reactions** | Whether the bot adds the first 🎉 to its own message. |
| **Posting time** | Defaults to 09:00. |

Nothing else needs editing. **`roster_sql` is already correct for this venue** —
`dbo.Employees`, `DateOfBirth`, `EmpStatus = 1`, all verified against the live
database.

### Seeing what it actually looks like

```bash
sudo bash birthdays/run.sh --demo      # one person
sudo bash birthdays/run.sh --demo=3    # three sharing a day
```

A shared birthday uses a different set of pools and joins the names, so it's
worth previewing separately. Posts a complete announcement to the channel — the quip, the GIF, the footer
and the reactions — under a placeholder name, labelled as a preview. Nothing is
recorded and no employee data is used, so it's safe to run whenever you want to
show someone the format. Run it again for a different quip and GIF.

To see a **real** upcoming one instead, pick a date from `--list` and rehearse it:

```bash
sudo bash birthdays/run.sh --list
sudo bash birthdays/run.sh --date=2026-09-08
```

A `--date` other than today is treated as a rehearsal: it always posts, and it
is deliberately **not** recorded — otherwise previewing a future birthday would
mark it done and the bot would say nothing on the real morning.

### Is it working?

```bash
sudo bash birthdays/run.sh --check
```

One command, one checklist: config, whether posting is switched on, **when it
last ran and what happened**, MSSQL driver, database connection, roster query
(with a headcount), Slack token, GIF source, today's birthdays and the next one
coming up. Anything broken is listed with its fix. Posts nothing.

The **Last run** row is the one to read first when the channel has gone quiet —
it is the only thing that separates "ran, and nobody had a birthday" from
"never ran at all".

### If you'd rather do it by hand

<details>
<summary>Manual install</summary>

```bash
cd /var/persist/pause-groups

# 1. Config — in data/, NOT in birthdays/. update.sh syncs the repo over the
#    install dir with `rsync --delete`, and this file is gitignored, so a copy
#    beside the code gets deleted by the next deploy.
sudo cp birthdays/config.example.php data/birthday_config.php
sudo chown 33:33 data/birthday_config.php    # the container runs as uid 33
sudo chmod 600 data/birthday_config.php      # it holds the bot token
sudo vi data/birthday_config.php             # set slack_bot_token + slack_channel
#    slack_channel takes '#birthday-test' or a C0123456789 ID.

# 2. Check it
sudo bash birthdays/run.sh --check
sudo bash birthdays/run.sh --list
sudo bash birthdays/run.sh --dry-run
sudo bash birthdays/run.sh --test-slack

# 3. Timer
sudo install -m 0644 birthdays/systemd/ceplay-birthdays.service /etc/systemd/system/
sudo install -m 0644 birthdays/systemd/ceplay-birthdays.timer   /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ceplay-birthdays.timer
```

The timer carries three `OnCalendar` lines — the posting time plus two
catch-up firings (see *Not failing quietly* below). Change them all together,
then `sudo systemctl daemon-reload`. Or re-run `install.sh`, which works the
catch-up times out for you.

Not on Fedora CoreOS? Plain cron works identically:

```cron
0 9 * * * cd /path/to/ceplay && /usr/bin/php birthdays/birthday_bot.php >> data/birthdays.log 2>&1
```

</details>

### Day to day

```bash
sudo bash birthdays/run.sh --check         # is everything still wired up?
sudo bash birthdays/run.sh --list          # upcoming birthdays
sudo bash birthdays/run.sh --dry-run       # today's message, posting nothing
sudo systemctl start ceplay-birthdays      # run it right now
journalctl -u ceplay-birthdays -n 50       # or: tail data/birthdays.log
sudo bash birthdays/install.sh             # change channel / time / options
sudo bash birthdays/install.sh --uninstall # stop it posting
```

`Persistent=true` on the timer means a machine that was off at 09:00 runs the
job when it boots, so an overnight outage doesn't cost anyone their message.
That's safe because of the state file: it will not post twice for the same day.
The same is what makes the catch-up firings free — see *Not failing quietly*.

---

# What it does

## The fun bits

**Rotating messages.** Each post is composed from two pools — a greeting and a
flavour line, drawn independently and joined — so **65 written lines produce 714
different messages** (and 200 more for shared birthdays). The jokes come from
this venue's own floor: the karts and the Spiral, Laser Tag, Free Fall, the
Dragon Coaster, the cages, the driving range, mini golf, the rock wall,
Ballocity, the zipline, skee-ball, Ice Ball, the cranes, Tin Can Alley and the
redemption counter.

> :sparkles: *Mason Quinones* has a birthday today! :birthday:
> Tin Can Alley doesn't stand a chance.

> :birthday: Raise a slice for *Mason Quinones*! :cake:
> Top of the leaderboard, and nobody beats it.

Add your own with `greetings` / `flavors` (and `multi_greetings` /
`multi_flavors`) — one new flavour line is fourteen new messages. A greeting
must contain `{names}`; a flavour line has to stand alone after *any* greeting,
so write complete sentences. `messages_single` / `messages_multi` replace
composition with whole templates, and `message_single` / `message_multi` pin one
exact wording.

**Channel by name.** `slack_channel` takes `#birthday-test` as happily as a
`C0123456789`. Names are resolved through `conversations.list`; the installer
does it once and stores the ID so the daily run never spends a lookup.

**Animated GIFs**, as a Slack image block. Two sources: a free Giphy key
(`giphy_api_key`) searches fresh every time and never goes stale; the curated
`gifs` list is the fallback for when there's no key or Giphy is down.

**Its own name.** Posts show the Slack app's name by default — awkward if you
reuse one shared app for several integrations and birthday wishes arrive from
"monitors". Set `bot_username => 'Birthday Bot'` and `bot_icon_emoji =>
':birthday:'` and the same token posts under that name instead. Needs
`chat:write.customize`; without it the greeting still goes out under the app's
own name, with a warning in the log.

**Reactions.** Set `add_reactions` and the bot drops the first 🎉 🎂 on its own
message so nobody has to break the ice.

**Everything random-looking is deterministic**, seeded from the date plus who's
celebrating. `--dry-run` shows *exactly* what will post, a re-run never swaps
it, and the same person gets something different next year.

**A dead GIF link can't break a birthday.** The bot HEAD-checks the GIF it's
about to use, walks down the list if it's gone, and posts without an image if
none work. `--test-gifs` checks the whole list up front — and if nothing
resolves, it probes slack.com first, so a firewalled network gets told to fix
the firewall rather than to prune good URLs.

## Not failing quietly

A birthday greeting cannot be delivered late, and every way this bot can break
looks exactly the same from the channel: nothing happens. A revoked token, a
timer that never came back after a rebuild, MSSQL still starting up, a dropped
connection at 09:00 — all of them are indistinguishable from a day when nobody
had a birthday, and by the time somebody notices, the birthday it cost was last
week's. Four things address that.

**It retries.** Three firings a day — the posting time, then roughly 30 and 90
minutes later. The extra two cost nothing and cannot double-post: the bot
records who it has greeted, so a run that finds the job done exits without a
word. If the first one worked, the others are no-ops. If it didn't, they are
the reason somebody still gets wished a happy birthday.

**Each Slack call retries too**, three attempts over a few seconds, honouring
Slack's own `Retry-After` on a rate limit. Only failures that could pass on a
second attempt are retried — a rate limit, a 5xx, a connection that never
opened. A revoked token or a channel the bot isn't in will fail identically
forever, so those are reported at once instead of three times more slowly.

**Every run leaves a record**, and `--check` reads it back as a **Last run**
row — as does the Birthdays page, from the same two files:

```
Last run           ok     posted 2 greetings at 09:00 today
Last run           -      ran at 09:00 today — nobody had a birthday
Last run           FAIL   today's run FAILED at 09:00: Could not read the roster
Last run           FAIL   last ran 3 days ago (Thu 20 Aug) — the timer is not firing
```

That last line is the one that matters: it is the only way to tell "ran, and
there was nobody" from "never ran", which are the same silence and completely
different problems. The app's `/api/health` endpoint reports the same
heartbeat beside cron's and the watchdog's, so external monitoring sees it too.
A greeting that goes out is also written to the app's own audit log (Logs →
source `birthdays`), as is a run that fails.

**The timer knows which clock you meant.** systemd fires `OnCalendar` on the
*system* timezone, which is not necessarily the app's — a host on UTC and an
app on Eastern turn a 09:00 timer into a 05:00 greeting, and nothing says so.
`install.sh` compares the two and, on systemd 252+, writes
`OnCalendar=*-*-* 09:00:00 America/New_York` so the time means what you typed
(DST-safe, unlike a fixed UTC offset). Where it can't, it says so loudly rather
than installing a timer that quietly fires four hours early. `--check` prints
the app's zone in a **Clock** row, so putting it beside `systemctl list-timers`
shows any gap at a glance.

**Two runs can't both post.** A non-blocking lock covers the whole posting
path, so a hand-started run that overlaps a timer firing stands down instead of
posting a second greeting.

**What it will never do:** publish an age, a birth year, or a milestone
("21 today!"). The roster holds full dates of birth and about a fifth of this
staff are minors. The message gets the name and nothing more.

## How it decides who to greet

- **Employment status is your query's job.** The `WHERE` clause in `roster_sql`
  is the whole employment filter. If it has no `WHERE` at all, the bot logs a
  warning that it's about to greet everyone who ever worked there.
- **Date matching happens in PHP**, so one query a day covers today, `--list`
  and any `--date`.
- **29 February** birthdays get a substitute day in non-leap years
  (`leap_day_mode`: `feb28`, `mar1` or `skip`). In a leap year the 29th fires on
  itself — nobody is greeted twice.
- **Placeholder birth dates are dropped**: `1900-01-01`, anything before 1901,
  anything in the future, plus whatever you add to `ignore_birth_dates`.
- **People can opt out** via `exclude_emp_nos` / `exclude_names`.
- **A safety valve.** More than `max_celebrants` (12) sharing a birthday and the
  bot refuses to post — that many is a broken query, not a coincidence.
- **It won't post twice.** `data/birthday_state.json` records who was greeted on
  which day. `--force` overrides. A partial failure only records the messages
  that actually went out, so the rest are retried next run.

## What this venue's database says

Confirmed August 2026, and verified end to end against the live roster:

| | |
|---|---|
| Staff roster | **`dbo.Employees`** (one row per person) |
| Birthday | **`DateOfBirth`** (`datetime`) |
| Still employed | **`EmpStatus = 1`** — `dbo.EmployeeStatus` says: 1 = Active, 2 = Suspended, 3 = Terminated |
| Leaving date | `DateOfTerminate`, used as a redundant second check |
| Does not exist | `TimeClock_Employees` — the time-clock module is punches and scheduling only |

Every other birthday column in the schema is the guest side — `Customers`,
`ChildCustomers`, `GroupChildren`, the waiver tables, `TicketDetails`.

A 100-row sample of the real query was run through the bot: no placeholder
dates at all, birthdays spread across all 12 months, at most 3 people sharing a
day, and names with apostrophes render correctly.

> ⚠️ `dbo.Employees` also holds `SSN`, `PasswordHash`, `PinHash`,
> `FingerprintTemplate` and `Picture`. The bot's query selects four columns and
> nothing else. Don't `SELECT *` from this table while poking around.

**Re-deriving it** (after a POS upgrade, or at another venue):

```bash
sudo bash birthdays/run.sh discover
```

It finds the roster table, decodes the status codes from their lookup table,
checks for placeholder dates, and prints a finished `roster_sql`. Birth years
are masked unless you pass `--show-years`. `EXPLORER-QUERIES.md` has the same
probes as copy/paste SQL for the Database Explorer.

---

# Reference

## Commands

| | |
|---|---|
| `--check` | Health-check everything and print a checklist. Posts nothing |
| `--resolve-channel=X` | Print the channel ID for a `#name`. Posts nothing |
| `--list[=DAYS]` | Upcoming birthdays (default 60 days). Posts nothing |
| `--dry-run` | Build today's message and print it. Posts nothing |
| `--date=YYYY-MM-DD` | Treat that date as today |
| `--test-gifs` | Check every GIF URL resolves |
| `--test-slack` | Check the token and post one plain test message |
| `--demo[=N]` | Post a full sample announcement; `N` = people sharing it (max 6) |
| `--force` | Post even if today's greeting already went out |
| `--config=PATH` | Use a specific config file |
| `--roster-file=PATH` | Read the roster from JSON instead of MSSQL (testing) |
| `--print-timezone` | Print the zone the bot treats as local |
| `--help` | Show usage |

## Configuration

**The easiest way is the Birthdays page in CEplay** (`#/birthdays`) — every
setting below has a field there, plus upcoming birthdays, a health check, a
wording preview and a "post a sample" button. Saving there writes to the app
database and takes effect on the next morning's post.

Settings resolve in three layers, each overriding the one before: built-in
defaults, then `data/birthday_config.php` if it exists, then whatever the page
saved. So a file-only install keeps working untouched, and anything edited on
the page wins. `config.example.php` is the annotated form of the file layer.

| Key | Default | What it does |
|---|---|---|
| `enabled` | `true` | Master switch. `false` stops the greetings without touching the timer — `--list`, `--dry-run`, `--demo` and `--check` all still work, so you can see what it *would* post |
| `slack_bot_token` | — | `xoxb-…` bot token |
| `slack_channel` | — | `#birthday-test`, or a channel ID (an ID skips a lookup each run) |
| `mention` | `''` | Prefix such as `<!here>` |
| `roster_sql` | verified | The one SELECT defining "current employee" |
| `name_style` | `full` | `full` / `first` / `first_initial` |
| `venue_label` | The Castle Fun Center | Fills `{venue}` |
| `greetings` / `flavors` | built-in pools | The two halves composed into a single-person message; `null` = built-ins, `[]` flavours = greeting only |
| `multi_greetings` / `multi_flavors` | built-in pools | The same two halves for a shared birthday |
| `messages_single` / `messages_multi` | unset | Whole-template pools, replacing composition; `{names}` `{count}` `{venue}` |
| `message_single` / `message_multi` | unset | Pin ONE wording, overriding the pool |
| `footer_text` | `null` | Context line; `''` to drop it |
| `post_separately` | `false` | One message each instead of one combined |
| `gifs_enabled` | `true` | Attach an animated GIF |
| `giphy_api_key` | `''` | Free key → a fresh GIF every time |
| `giphy_rating` | `g` | `g` or `pg` only; anything else forced to `g` |
| `gifs` | built-in list | Fallback URLs when there's no Giphy key |
| `gif_verify` | `true` | HEAD-check before posting; skip dead links |
| `bot_username` | `''` | Post as this name, e.g. `Birthday Bot` (`chat:write.customize`) |
| `bot_icon_emoji` | `''` | Icon to post with, e.g. `:birthday:` |
| `add_reactions` | `false` | Bot adds the first reactions (`reactions:write`) |
| `mention_by_email` | `false` | Real @-mentions; needs `users:read.email` |
| `leap_day_mode` | `feb28` | `feb28` / `mar1` / `skip` |
| `exclude_emp_nos` / `exclude_names` | `[]` | Opt-outs |
| `ignore_birth_dates` | `[]` | Extra placeholder dates |
| `max_celebrants` | `12` | Refuse to post above this |
| `timezone` | app default | Which day "today" is |
| `state_file` | `data/birthday_state.json` | Already-greeted record |
| `log_file` | `data/birthdays.log` | Append-only log |

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `No config file yet` | Copy the example to `data/birthday_config.php` (step 3) |
| `Permission denied` reading the config | `sudo chown 33:33 data/birthday_config.php` |
| `The MSSQL overlay image isn't available` | Re-run `sudo bash update.sh` to build it |
| `No MSSQL PDO driver in this PHP runtime` | You ran `php` directly — use `birthdays/run.sh` |
| `MSSQL is not configured in the app yet` | Set the connection on the Go-Kart Labor page → Settings |
| `not_in_channel` | `/invite @your-bot-name` into the channel |
| `channel_not_found` | The name doesn't match a channel the bot can see — `--resolve-channel='#name'` lists near matches |
| Channel name won't resolve | Needs `channels:read` (and `groups:read` + an invite for a private channel). Add them **and reinstall the app**, or just use the ID |
| `missing_scope` | Add the scope **and reinstall the app** |
| `invalid_auth` / `token_revoked` | Reinstall and copy the new `xoxb-` token |
| Rows returned but nobody had a usable birthday | `roster_sql` isn't aliasing the birthday column `birth_date` |
| `Refusing to post: N people share a birthday` | `roster_sql` is reading a hire date — run `discover` |
| Message posts but no GIF | `--test-gifs`; it tells you whether it's link rot or no network |
| `Giphy returned HTTP 401/403` | Wrong or unactivated `giphy_api_key` |
| Reactions missing | Add `reactions:write` **and reinstall the app** |
| Posts show the wrong bot name | Add `chat:write.customize` **and reinstall**, then set `bot_username`. The log says when the override was dropped |
| Said "Already wished" and posted nothing | Working as intended; `--force` to repost |
| Nothing at 09:00 | Run `--check` first — the **Last run** row says whether the bot ran at all, which decides where to look next |
| It posts, but hours off the time you set | The machine's clock zone isn't the app's. Compare `--check`'s **Clock** row with `systemctl list-timers` — systemd fires on the SYSTEM zone. Fix by pinning the zone onto `OnCalendar` (systemd 252+), or re-run `install.sh` |
| `Last run: the bot has never run here` | The timer isn't installed or isn't enabled: `systemctl status ceplay-birthdays.timer` |
| `Last run: the timer is not firing` | It ran once and then stopped. `journalctl -u ceplay-birthdays` has the reason |
| `Last run: today's run FAILED` | The run happened and the row says why — usually MSSQL or the Slack token |
| `Another birthday-bot run is already in progress` | Two runs overlapped and the second stood down. Normal; nothing to fix |
| Config vanished after an update | It was at `birthdays/config.php` — move it to `data/` (step 3) |

## Development

The date, message, GIF-selection and state logic has no database or network in
it, so the tests run anywhere:

```bash
php birthdays/tests/test_birthday_lib.php        # 165 — matching, messages, blocks, state, run record, lock
php birthdays/tests/test_gif_source.php          #  30 — GIF selection, Giphy parsing
php birthdays/tests/test_slack_channel.php       #  48 — channel resolution, retry classification
php birthdays/tests/test_discover_helpers.php    #  67 — column/table/status-label classification
```

You can exercise the whole runner without MSSQL by feeding it a JSON roster
shaped like the query result — handy for settling on the wording:

```bash
echo '[{"EmpNo":"1","FirstName":"Alex","LastName":"Rivera","BirthDate":"1990-08-21"}]' > /tmp/roster.json
php birthdays/birthday_bot.php --roster-file=/tmp/roster.json --date=2026-08-21 --dry-run
```
