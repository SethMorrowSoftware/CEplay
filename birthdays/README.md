# Employee Birthday Slack Bot

Posts a birthday greeting to Slack each morning for anyone on the CenterEdge
employee roster whose birthday is today **and** whose employment-status column
still says they work here.

It **does not change the CEplay app**. It borrows two things and writes
nothing back: the app's stored (encrypted) MSSQL connection, and its read-only
`MssqlClient`. Every query it runs is a plain `SELECT` behind the same
single-statement guard the Go-Kart Labor and Card Loads reports use.

```
birthdays/
├─ discover.php            Find the roster table / birthday / status columns
├─ birthday_bot.php        The daily runner
├─ config.example.php      Copy to config.php and fill in
├─ EXPLORER-QUERIES.md     The same discovery, as Database Explorer copy/paste
├─ lib/birthday_lib.php    Date matching, message building, state (no I/O)
├─ lib/slack_client.php    Slack Web API (auth.test, chat.postMessage, lookup)
├─ systemd/                Daily timer units
└─ tests/                  Unit tests — run anywhere, no database needed
```

---

## 1. Find the roster in the POS database

The birthday column, the roster table and the meaning of the status column are
all things only the live database can tell you. Run the probe **on the venue
server**, inside the `pdo_dblib` overlay image the app already uses:

```bash
sudo podman run --rm --network host \
    --env-file /var/persist/pause-groups/.env \
    -v /var/persist/pause-groups:/var/persist/pause-groups:z \
    -w /var/persist/pause-groups -u 33:33 \
    localhost/pause-groups-fpm-mssql:latest \
    php birthdays/discover.php
```

It prints:

1. every birthday-ish column in the database (flagging the guest/party ones —
   `Customers` and `GroupBirthdays` have birthdays too, and they are not staff);
2. the candidate roster tables, ranked, with the columns it recognised;
3. for the best candidate: **what each status value actually contains**, how
   many placeholder birth dates there are, the spread by month, and a masked
   sample;
4. a finished `roster_sql` to paste into `config.php`.

Birth years are masked (`••••-03-14`) unless you pass `--show-years`. Force a
particular table with `--table=Name`.

Prefer the web UI? `EXPLORER-QUERIES.md` has the same probes as copy/paste SQL
for the Database Explorer (`#/explorer`, needs the `data_explorer` permission).

### What this venue's database says

Confirmed August 2026 via the Explorer — the roster query is settled, not a guess:

| | |
|---|---|
| Staff roster | **`dbo.Employees`** (one row per person) |
| Birthday | **`DateOfBirth`** (`datetime`) |
| Still employed | **`EmpStatus = 1`** — `dbo.EmployeeStatus` spells the codes out: 1 = Active, 2 = Suspended, 3 = Terminated |
| Leaving date | `DateOfTerminate`, used as a redundant second check |
| Does not exist | `TimeClock_Employees` — the time-clock module is punches and scheduling only |

`config.example.php` ships exactly that query, so on this venue there is nothing
left to work out — go to step 2. Run `discover.php` anyway if you want the
data-quality checks, or after a POS upgrade.

Every other birthday column in the schema is the guest side — `Customers`,
`ChildCustomers`, `GroupChildren`, the waiver tables, `TicketDetails`. Don't
point the bot at any of them.

> ⚠️ `dbo.Employees` also holds `SSN`, `PasswordHash`, `PinHash`,
> `FingerprintTemplate` and `Picture`. The bot's query selects four columns and
> nothing else, and `discover.php` masks birth years unless you ask for them.
> Don't `SELECT *` from this table while poking around.

**Two checks still worth running on your own data** (`discover.php` does both):

- **Does the Active count look like your actual team?** If the status field has
  gone stale, records say "Active" for people who left and no query can tell.
  Compare it against who has actually clocked into `TimeClock_Weekly` recently.
- **Do `EmpStatus` and `DateOfTerminate` agree?** If any Active row carries a
  leaving date the two contradict each other; `discover.php` measures this and
  only includes both conditions when they agree, because a stale leaving date on
  a current employee would silently cost them their birthday every year.

One thing only you can confirm: that the most-repeated birth dates are
placeholders rather than real birthdays. Anything with a double-digit count is a
default the POS stamped in — add it to `ignore_birth_dates`.

### Verified against the live roster, August 2026

A 100-row sample of the real query was run end to end through the bot:

- **No placeholder dates at all** — not one birth date repeats exactly, so
  `ignore_birth_dates` can stay empty here.
- **Birthdays spread across all 12 months** (5–14 each), which is what real data
  looks like; a spike would have meant a hire date.
- **At most 3 people share a day** in that sample, well under `max_celebrants`.
- **No 29 February birthdays** currently on staff, so the leap-day rule won't
  fire — it stays correct for when it does.
- Names with apostrophes and multi-word first/last names render correctly and
  survive JSON encoding into the Slack payload.

⚠️ **That sample was exactly 100 rows, which is the Explorer's default limit —
so it was almost certainly truncated.** Get the true figure with a count rather
than assuming (the bot itself reads up to `roster_max_rows`, 5000, so it is not
affected):

```sql
SELECT COUNT(*) AS active_with_birthday
FROM CenterEdge.dbo.Employees
WHERE EmpStatus = 1 AND DateOfTerminate IS NULL
  AND DateOfBirth IS NOT NULL AND YEAR(DateOfBirth) >= 1901
```

**A staffing note worth a decision, not a default:** this roster runs from teens
to sixties — about a fifth of the sample were under 18. The bot never publishes
an age or a birth year, which matters more here than at an all-adult venue. If
the channel is wide or has guests in it, consider `'name_style' => 'first'` or
`'first_initial'` so posts read "Happy Birthday, Mason!" rather than naming a
minor in full. Your call; `full` is the default.

## 2. Create the Slack bot

1. <https://api.slack.com/apps> → **Create New App** → **From scratch**.
2. Name it (e.g. `Castle Birthdays`), pick the workspace.
3. **OAuth & Permissions → Bot Token Scopes** → add **`chat:write`**.
   - `chat:write.public` too, if you want it to post without being invited.
   - `chat:write.customize` only if you set `bot_username`/`bot_icon_emoji`.
   - `users:read.email` only if you turn on `mention_by_email`.
4. **Install to Workspace**, then copy the **Bot User OAuth Token** (`xoxb-…`).
5. In Slack, invite it: `/invite @Castle Birthdays`.
6. Get the **channel ID** (channel name → *View channel details* → bottom of the
   panel, `C0123456789`). The bot needs the ID, not the name.

> Adding a scope later means **reinstalling** the app — the existing token does
> not gain it.

## 3. Configure

```bash
cd /var/persist/pause-groups
cp birthdays/config.example.php birthdays/config.php
chmod 600 birthdays/config.php          # it holds the bot token
$EDITOR birthdays/config.php
```

Fill in `slack_bot_token`, `slack_channel`, and the `roster_sql` that
`discover.php` printed. Everything else has a working default. `config.php` is
gitignored.

## 4. Test before you switch it on

All of these are safe — the first three post nothing at all.

```bash
# Does the roster query return sensible people?
php birthdays/birthday_bot.php --list

# What would today's message say?
php birthdays/birthday_bot.php --dry-run

# What about a day you know has a birthday on it?
php birthdays/birthday_bot.php --date=2026-09-14 --dry-run

# Is the token good and the channel reachable? (posts one test message)
php birthdays/birthday_bot.php --test-slack
```

Run them through the same `podman run …` wrapper as step 1 — the roster ones
need the MSSQL driver.

`--list` is the one that catches a wrong column fastest: if it shows forty
people sharing one date, `roster_sql` is reading a hire date.

## 5. Schedule it

```bash
sudo install -m 0644 birthdays/systemd/ceplay-birthdays.service /etc/systemd/system/
sudo install -m 0644 birthdays/systemd/ceplay-birthdays.timer   /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ceplay-birthdays.timer

systemctl list-timers ceplay-birthdays.timer     # confirm the next firing
```

Posts at **09:00** local by default — change `OnCalendar` in the timer to move
it. The units assume the standard `/var/persist/pause-groups` install; edit the
paths if yours differs.

`Persistent=true` means a machine that was off at 09:00 runs the job when it
boots, so an overnight outage doesn't cost anyone their birthday message. That's
safe because of the state file below.

Not on Fedora CoreOS? Plain cron works identically:

```cron
0 9 * * * cd /path/to/ceplay && /usr/bin/php birthdays/birthday_bot.php >> data/birthdays.log 2>&1
```

---

## How it decides who to greet

- **Employment status is your query's job.** The bot doesn't guess — the
  `WHERE` clause in `roster_sql` is the whole employment filter, which is why
  step 1 spends so long on what the status column means. If `roster_sql` has no
  `WHERE` at all, the bot logs a warning that it is about to greet everyone who
  ever worked there.
- **Date matching happens in PHP**, not SQL, so one query a day covers today,
  `--list`, and any `--date` you want to test.
- **29 February** birthdays get a substitute day in non-leap years, set by
  `leap_day_mode` (`feb28` by default, or `mar1`, or `skip`). In a leap year the
  29th is a real date and fires on itself — nobody gets greeted twice.
- **Placeholder birth dates are dropped**: `1900-01-01`, `1899-12-30`,
  `1970-01-01`, anything before 1901, anything in the future, plus whatever you
  add to `ignore_birth_dates`.
- **People can opt out** via `exclude_emp_nos` / `exclude_names`. Somebody will
  ask.
- **A safety valve.** If more than `max_celebrants` (12) people share today's
  birthday, the bot refuses to post and tells you why — that many is a broken
  query, not a coincidence, and a wrong message is worse than no message.
- **It won't post twice.** `data/birthday_state.json` records who was greeted on
  which day, so a re-run, a manual test or a catch-up timer firing is a no-op.
  Use `--force` to override. A partial failure only records the people whose
  message actually went out, so the rest are retried next run.
- **No ages, no birth years.** The roster carries full dates of birth; the
  message never does, and neither does the log.

## Configuration reference

Everything lives in `birthdays/config.php`; see `config.example.php` for the
annotated version.

| Key | Default | What it does |
|---|---|---|
| `slack_bot_token` | — | `xoxb-…` bot token |
| `slack_channel` | — | Channel **ID** (`C…`), not the name |
| `mention` | `''` | Prefix such as `<!here>` |
| `roster_sql` | see example | The one SELECT that defines "current employee" |
| `name_style` | `full` | `full` / `first` / `first_initial` |
| `venue_label` | The Castle Fun Center | Fills `{venue}` |
| `message_single` / `message_multi` | see example | `{names}` `{count}` `{venue}` |
| `post_separately` | `false` | One message each instead of one combined |
| `mention_by_email` | `false` | Real @-mentions; needs `users:read.email` + an `email` column |
| `leap_day_mode` | `feb28` | `feb28` / `mar1` / `skip` |
| `exclude_emp_nos` / `exclude_names` | `[]` | Opt-outs |
| `ignore_birth_dates` | `[]` | Extra placeholder dates |
| `max_celebrants` | `12` | Refuse to post above this |
| `timezone` | app default | Which day "today" is |
| `state_file` | `data/birthday_state.json` | Already-greeted record |
| `log_file` | `data/birthdays.log` | Append-only log; empty = stdout only |

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `No MSSQL PDO driver in this PHP runtime` | Run it inside the `pdo_dblib` overlay image (step 1), not the stock PHP image |
| `MSSQL is not configured in the app yet` | Set the connection on the Go-Kart Labor page → Settings |
| `Slack rejected chat.postMessage: not_in_channel` | `/invite @your-bot-name` into the channel |
| `channel_not_found` | You used the channel name; it needs the ID (`C…`) |
| `missing_scope` | Add the scope **and reinstall the app** — the old token doesn't gain it |
| `invalid_auth` / `token_revoked` | Reinstall and copy the new `xoxb-` token |
| Query returned rows but nobody had a usable birthday | `roster_sql` isn't selecting the birthday column, or isn't aliasing it `birth_date` |
| `Refusing to post: N people share a birthday` | `roster_sql` is reading a hire date or similar — re-run `discover.php` |
| It posted nothing and said "Already wished" | Working as intended; `--force` to repost |
| Nothing at all in the channel at 09:00 | `systemctl status ceplay-birthdays.service` and `journalctl -u ceplay-birthdays` |

## Development

The date, message and state logic has no database or network in it, so the
tests run anywhere:

```bash
php birthdays/tests/test_birthday_lib.php        # 91 assertions — matching, messages, state
php birthdays/tests/test_discover_helpers.php    # 67 assertions — column/table/status-label classification
```

The second one evaluates the helper block straight out of `discover.php`
rather than keeping a copy, so it always tests the current source.

You can also exercise the whole runner without MSSQL by feeding it a JSON
roster shaped like the query result — handy for settling on the wording:

```bash
echo '[{"EmpNo":"1","FirstName":"Alex","LastName":"Rivera","BirthDate":"1990-08-21"}]' > /tmp/roster.json
php birthdays/birthday_bot.php --roster-file=/tmp/roster.json --date=2026-08-21 --dry-run
```
