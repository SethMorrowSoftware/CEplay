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
├─ run.sh                 Run any command inside the MSSQL-enabled container
├─ birthday_bot.php       The daily runner
├─ discover.php           Re-derive the roster query from the live database
├─ config.example.php     Copy to data/birthday_config.php and fill in
├─ EXPLORER-QUERIES.md    The schema discovery, as Explorer copy/paste SQL
├─ lib/                   Date matching, message building, GIFs, Slack
├─ systemd/               Daily timer units
└─ tests/                 218 assertions — run anywhere, no database needed
```

---

# Installing

Five steps, about fifteen minutes. Everything runs on the venue server.

## 1. Get the code onto the server

The bot lives on the branch `claude/birthday-slackbot-employee-db-r69vnu`.
`update.sh` pulls whichever branch the source clone is on, then syncs it into
the live app directory.

**To try it before merging** — check the branch out in the source clone:

```bash
cd /var/persist/pause-groups-src
sudo git fetch origin
sudo git checkout claude/birthday-slackbot-employee-db-r69vnu
sudo bash /var/persist/pause-groups-src/update.sh
```

**Once you're happy with it**, merge the branch to `main`, put the source clone
back on `main`, and from then on the normal `sudo bash update.sh` carries the
bot along with every other update:

```bash
cd /var/persist/pause-groups-src
sudo git checkout main && sudo git pull
sudo bash update.sh
```

Either way, confirm the files landed:

```bash
ls /var/persist/pause-groups/birthdays/
```

> `update.sh` also rebuilds the `pdo_dblib` overlay image the bot needs to read
> the roster. If you've never run the Go-Kart Labor report, that build is what
> makes MSSQL work at all.

## 2. Create the Slack bot

1. <https://api.slack.com/apps> → **Create New App** → **From scratch**.
2. Name it (e.g. `Castle Birthdays`), pick the workspace.
3. **OAuth & Permissions → Bot Token Scopes** → add **`chat:write`**.
   - `chat:write.public` too, if you want it to post without being invited.
   - `reactions:write` if you want the bot to add the first 🎉 itself.
   - `chat:write.customize` only for `bot_username`/`bot_icon_emoji`.
   - `users:read.email` only for `mention_by_email`.
4. **Install to Workspace**, then copy the **Bot User OAuth Token** (`xoxb-…`).
5. In Slack, invite it to the channel: `/invite @Castle Birthdays`
6. Get the **channel ID**: channel name → *View channel details* → the ID is at
   the bottom (`C0123456789`). The bot needs the ID, not the name.

> Adding a scope later means **reinstalling** the app — an existing token does
> not gain it. The GIF needs no extra scope; it's a Block Kit image block, not
> an upload.

## 3. Configure

**The config goes in `data/`, not in `birthdays/`.** `update.sh` syncs the repo
over the install directory with `rsync --delete`, and the config is gitignored —
so a copy at `birthdays/config.php` would be deleted by the next deploy, taking
your Slack token with it. `data/` is excluded from that sync and gets the right
ownership automatically.

```bash
cd /var/persist/pause-groups
sudo cp birthdays/config.example.php data/birthday_config.php
sudo chown 33:33 data/birthday_config.php     # the container runs as uid 33
sudo chmod 600 data/birthday_config.php       # it holds the bot token
sudo vi data/birthday_config.php
```

Fill in two things:

```php
'slack_bot_token' => 'xoxb-…',        // from step 2
'slack_channel'   => 'C0123456789',   // the channel ID
```

**`roster_sql` is already correct for this venue** — `dbo.Employees`,
`DateOfBirth`, `EmpStatus = 1`, all verified against the live database. Leave it
alone unless the POS schema changes.

Optional but recommended: a free [Giphy API key](https://developers.giphy.com)
in `giphy_api_key` gets you a fresh GIF every day instead of a fixed list.

## 4. Test before switching it on

`run.sh` runs each command inside the MSSQL-enabled container for you. The
first three post **nothing**.

```bash
cd /var/persist/pause-groups

# Does the roster query return sensible people?
sudo bash birthdays/run.sh --list

# What would today's message say? (shows the exact quip and GIF)
sudo bash birthdays/run.sh --dry-run

# Do the GIF URLs resolve from this network?
sudo bash birthdays/run.sh --test-gifs

# Is the token good and the channel reachable? (posts ONE test message)
sudo bash birthdays/run.sh --test-slack
```

`--list` is the one that catches a problem fastest. You should see real staff
names on plausible dates. If it shows forty people on one date, something is
pointed at the wrong column.

To preview a specific day: `sudo bash birthdays/run.sh --dry-run --date=2026-12-26`

## 5. Schedule it

```bash
cd /var/persist/pause-groups
sudo install -m 0644 birthdays/systemd/ceplay-birthdays.service /etc/systemd/system/
sudo install -m 0644 birthdays/systemd/ceplay-birthdays.timer   /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ceplay-birthdays.timer

systemctl list-timers ceplay-birthdays.timer     # confirm the next firing
```

Posts at **09:00** local. To move it, edit `OnCalendar` in
`/etc/systemd/system/ceplay-birthdays.timer` and
`sudo systemctl daemon-reload`.

`Persistent=true` means a machine that was off at 09:00 runs the job when it
boots, so an overnight outage doesn't cost anyone their message. That's safe
because of the state file: it will not post twice for the same day.

Watch it work:

```bash
sudo systemctl start ceplay-birthdays.service   # run it right now
journalctl -u ceplay-birthdays -n 50            # or: tail data/birthdays.log
```

Not on Fedora CoreOS? Plain cron works identically:

```cron
0 9 * * * cd /path/to/ceplay && /usr/bin/php birthdays/birthday_bot.php >> data/birthdays.log 2>&1
```

---

# What it does

## The fun bits

**Rotating messages.** A pool of birthday lines written around this venue's own
attractions — the go-karts, Laser Tag, Free Fall, the skee-ball lanes, the
redemption counter — plus a separate pool for days when several people are
celebrating. Write your own with `messages_single` / `messages_multi`, or pin
one fixed wording with `message_single` / `message_multi`.

**Animated GIFs**, as a Slack image block. Two sources: a free Giphy key
(`giphy_api_key`) searches fresh every time and never goes stale; the curated
`gifs` list is the fallback for when there's no key or Giphy is down.

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
| `--list[=DAYS]` | Upcoming birthdays (default 60 days). Posts nothing |
| `--dry-run` | Build today's message and print it. Posts nothing |
| `--date=YYYY-MM-DD` | Treat that date as today |
| `--test-gifs` | Check every GIF URL resolves |
| `--test-slack` | Check the token and post one test message |
| `--force` | Post even if today's greeting already went out |
| `--config=PATH` | Use a specific config file |
| `--roster-file=PATH` | Read the roster from JSON instead of MSSQL (testing) |
| `--help` | Show usage |

## Configuration

In `data/birthday_config.php`; see `config.example.php` for the annotated form.

| Key | Default | What it does |
|---|---|---|
| `slack_bot_token` | — | `xoxb-…` bot token |
| `slack_channel` | — | Channel **ID** (`C…`), not the name |
| `mention` | `''` | Prefix such as `<!here>` |
| `roster_sql` | verified | The one SELECT defining "current employee" |
| `name_style` | `full` | `full` / `first` / `first_initial` |
| `venue_label` | The Castle Fun Center | Fills `{venue}` |
| `messages_single` / `messages_multi` | built-in pools | Template pools; `{names}` `{count}` `{venue}` |
| `message_single` / `message_multi` | unset | Pin ONE wording, overriding the pool |
| `footer_text` | `null` | Context line; `''` to drop it |
| `post_separately` | `false` | One message each instead of one combined |
| `gifs_enabled` | `true` | Attach an animated GIF |
| `giphy_api_key` | `''` | Free key → a fresh GIF every time |
| `giphy_rating` | `g` | `g` or `pg` only; anything else forced to `g` |
| `gifs` | built-in list | Fallback URLs when there's no Giphy key |
| `gif_verify` | `true` | HEAD-check before posting; skip dead links |
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
| `channel_not_found` | You used the channel name; it needs the ID (`C…`) |
| `missing_scope` | Add the scope **and reinstall the app** |
| `invalid_auth` / `token_revoked` | Reinstall and copy the new `xoxb-` token |
| Rows returned but nobody had a usable birthday | `roster_sql` isn't aliasing the birthday column `birth_date` |
| `Refusing to post: N people share a birthday` | `roster_sql` is reading a hire date — run `discover` |
| Message posts but no GIF | `--test-gifs`; it tells you whether it's link rot or no network |
| `Giphy returned HTTP 401/403` | Wrong or unactivated `giphy_api_key` |
| Reactions missing | Add `reactions:write` **and reinstall the app** |
| Said "Already wished" and posted nothing | Working as intended; `--force` to repost |
| Nothing at 09:00 | `systemctl status ceplay-birthdays.service`; `journalctl -u ceplay-birthdays` |
| Config vanished after an update | It was at `birthdays/config.php` — move it to `data/` (step 3) |

## Development

The date, message, GIF-selection and state logic has no database or network in
it, so the tests run anywhere:

```bash
php birthdays/tests/test_birthday_lib.php        # 121 — matching, messages, blocks, state
php birthdays/tests/test_gif_source.php          #  30 — GIF selection, Giphy parsing
php birthdays/tests/test_discover_helpers.php    #  67 — column/table/status-label classification
```

You can exercise the whole runner without MSSQL by feeding it a JSON roster
shaped like the query result — handy for settling on the wording:

```bash
echo '[{"EmpNo":"1","FirstName":"Alex","LastName":"Rivera","BirthDate":"1990-08-21"}]' > /tmp/roster.json
php birthdays/birthday_bot.php --roster-file=/tmp/roster.json --date=2026-08-21 --dry-run
```
