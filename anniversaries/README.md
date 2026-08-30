# Work Anniversary Slack Bot

Posts a message to Slack each morning for anyone on the CenterEdge employee
roster whose **hire-date anniversary** is today and whose employment-status
column still says they work here — with their years of service, an animated
GIF, and a rotating set of arcade-flavoured one-liners.

It is a sibling of `birthdays/`, built the same way and configured the same
way. If you have already installed the birthday bot, this will feel familiar:
same installer shape, same `run.sh`, same `--check`, and the **same Slack token
works for both**.

It **does not change the CEplay app**. It borrows three things and writes
nothing back: the app's stored (encrypted) MSSQL connection, its read-only
`MssqlClient`, and the birthday bot's Slack client and GIF picker (shared on
purpose — they contain nothing birthday-specific, so a fix to the retry
handling lands in both bots at once). Every query it runs is a plain `SELECT`
behind the same single-statement guard the Go-Kart Labor and Card Loads reports
use.

```
anniversaries/
├─ install.sh             One-command installer (also --uninstall)
├─ run.sh                 Run any command inside the MSSQL-enabled container
├─ slack-app-manifest.yml Paste into Slack to create the app with its scopes
├─ anniversary_bot.php    The daily runner
├─ discover.php           Re-derive the roster query from the live database
├─ config.example.php     Copy to data/anniversary_config.php (or use the page)
├─ lib/anniv_lib.php      Date matching, years of service, message building
└─ tests/                 225 assertions — run anywhere, no database needed
```

It also puts today's anniversaries — alongside today's birthdays — on the
**Command Center**; see [On the dashboard](#on-the-dashboard) below.

---

## The roster query — already verified

`DateOfHire` on `dbo.Employees` was **confirmed against the live venue database
in August 2026**, so the shipped default query is correct as-is and needs no
edit. What the probe measured:

| | |
|---|---|
| populated | 1,532 of 1,547 rows |
| range | 1993 – 2026, none future-dated |
| **equal to the birth date** | **0 rows** |
| employment filter | `EmpStatus = 1` = "Active" (193 people), decoded from `dbo.EmployeeStatus` |
| `DateOfTerminate` | agrees — no active row carries one |

That third line is the one that mattered. A hire date and a date of birth are
both datetimes on the same table, and picking the wrong one would put "Happy
41st anniversary" in a public channel — so any column whose values agree with
the birth date is rejected outright.

If you ever need to re-derive it (a different install, a schema change), one
command prints every candidate, measures each, and emits a ready-to-paste
query:

```bash
sudo bash /var/persist/pause-groups/anniversaries/run.sh discover
```

A wrong column fails loudly (`Invalid column name`) rather than posting
anything incorrect, and both the CLI and the page turn that error into a
pointer at the probe.

### Set "Refuse to post above" from the cohort size

`max_celebrants` defaults to **25**, not the birthday bot's 12, and the
difference is deliberate. Twelve people sharing a *birthday* means a broken
query; twelve sharing a *hire date* is just how a seasonal venue staffs up —
this roster has cohorts of 24, 13, 13, 12 and 11 on single spring dates. Set
the guard below your biggest cohort and the bot will refuse to post on the
busiest anniversary of the year, every year.

What the guard is actually for is a placeholder date that never made it into
"Ignore these hire dates". `discover.php` measures the largest **current-staff**
cohort and recommends a number, so you can set it from evidence.

---

## Installing

### The normal deploy does it

```bash
sudo bash /var/persist/pause-groups/update.sh
```

`update.sh` writes and refreshes both bots' systemd units on every deploy, and
enables a bot's timer once that bot has a Slack token and a channel. So the
order is:

1. **Deploy.** `update.sh` lands the code. The anniversary bot isn't configured
   yet, so it says so and enables nothing.
2. **Configure on the Work Anniversaries page** (`#/anniversaries`) — paste a
   Slack token (the birthday bot's works; the two want the same scopes) and a
   channel, and save. Everything else has a working default.
3. **Deploy again**, or just `sudo systemctl enable --now ceplay-anniversaries.timer`.
   The timer starts on the default schedule.

Nothing in that path can lose a setting. `update.sh` syncs the code with
`rsync --delete --exclude data/`, so the app database, both bots' config files
and `.env` are untouched; and the unit writer **never overwrites an existing
timer** — see below.

### What a deploy touches, and what it leaves alone

| | |
|---|---|
| `ceplay-anniversaries.service` | **rewritten every deploy** — it carries the install path and the container image, so it goes stale |
| `ceplay-anniversaries.timer` | **never touched once it exists** — it carries only the schedule, which is your choice |
| `data/anniversary_config.php` | never touched by `update.sh` |
| Settings saved on the page | in the app database, never touched |

That split matters. Rewriting the timer on every update would quietly move your
posting time back to the shipped default, which is exactly the kind of silent
settings loss this codebase has been bitten by before.

### install.sh — the guided route, and how to change the time

```bash
sudo bash /var/persist/pause-groups/anniversaries/install.sh
```

Use it when you want to be walked through Slack setup, or when you want to
**change the posting time** — that is the one thing the page cannot do, because
it lives in a systemd unit. It asks a handful of questions, then:

- checks podman, the MSSQL driver image and the app's database connection, and
  stops with the exact fix if any are missing;
- offers the birthday bot's Slack token if one is already configured, and
  otherwise takes a new one — **verifying it and the channel before writing
  anything**;
- writes `data/anniversary_config.php` (backing up any existing one) with the
  right owner and permissions;
- runs a full health check and offers a test post;
- installs the timer at the time you choose, **in the app's timezone rather
  than the machine's** (see below).

It is safe to re-run, and it touches nothing belonging to the app or the
birthday bot. `--uninstall` removes the timer and leaves your config alone.

Both routes write the units through the same script — `deploy/write-bot-units.sh`
— so the unit this installer produces and the one a later deploy refreshes
cannot drift apart.

---

## What it says

Messages are **composed**, not picked from a list of whole templates: a
greeting line and a flavour line are drawn from separate pools with independent
seeds, so 14 greetings × 44 flavours make 616 distinct single-person messages.
Adding one good flavour line adds fourteen new messages.

That only works if every flavour line stands on its own after **any** greeting,
so each is a complete sentence that never refers back to the line above it. A
test enforces the capitalisation and terminal punctuation that implies.

The pick is deterministic from the date plus the people in it, which is what
makes `--dry-run` an honest preview: it shows exactly the message that will
post, and re-running never silently swaps it.

### Placeholders

| | |
|---|---|
| `{names}` | one name, or every name each carrying its own year count |
| `{count}` | how many people are being congratulated |
| `{venue}` | the venue label |
| `{years}` | one person: their years. Several: the **combined** total |
| `{year_label}` | the same number written out — "1 year" / "12 years" |
| `{ordinal}` | one person: "5th". Several: **empty**, deliberately |
| `{s}` | "" or "s", so a line can write `{years} year{s}` |

`{ordinal}` is dropped on a shared day because the people have different year
counts and there is no single ordinal that is true of the group. The page
rejects it in the shared-day pools rather than letting a message lose a word.

### Milestones

A milestone year (1, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50 by default) gets its
own greeting and flavour pools, so a fifth year reads louder than a third. That
only applies to a message about **one** person: with several people sharing a
day the numbers differ, and a milestone template would be shouting on behalf of
whoever happened to be listed first. Turning on "One message per person" gives
everybody their own post — and therefore their own milestone wording.

Every milestone flavour line has to be true at the **smallest** milestone as
well as the largest. Lines like "that predates half the games on this floor"
read wonderfully at twenty years and absurdly at one, so they are not in the
pool.

### Year zero is not an anniversary

Somebody hired this morning matches today's month and day exactly. "Happy 0
years" is the single most likely way this bot embarrasses itself, so the floor
is one year and the setting will not go below it. A start-date announcement is
a different thing from an anniversary.

---

## On the dashboard

Today's anniversaries appear as a strip under the Command Center header,
sharing the row with today's **birthdays**: one group per kind, one chip per
person, each group linking to its own page. Anniversary chips carry the years
of service, with milestone years ringed and starred.

Five things about it are deliberate:

- **Nothing to say, nothing on screen.** On the days when nobody is
  celebrating the strip is not rendered at all, and each group disappears
  independently. It sits above the fold on the page an operator watches while
  running the floor, so it has to earn its space rather than reserve it with an
  empty state.
- **It never reports its own failures there.** If the POS roster can't be read,
  the group simply doesn't appear — an optional accessory must not put a red
  banner on the floor's main screen. `--check`, or the check row on this page,
  is where that gets diagnosed.
- **It is the same selection each bot posts** — same `min_years`, milestone
  mode and opt-outs here; same leap-day rule and opt-outs for birthdays. A
  dashboard listing people Slack said nothing about would only raise "why
  didn't the bot mention Dana?". Note the corollary: in milestone-only mode, an
  ordinary third year appears in neither place.
- **A birthday chip carries a name and nothing else.** No age, no birth year,
  not even a field for one in the payload — the same rule the birthday greeting
  follows, and a dashboard anyone can walk past is a worse place to publish
  either than a channel would be. Years of service are the opposite case: they
  are the whole point of an anniversary, so only those chips carry a number.
- **It does not cost a roster read per poll.** The dashboard refreshes every 30
  seconds; both `today` endpoints sit on top of a 5000-row MSSQL query, so the
  browser asks at most every ten minutes and the server memoises the answers on
  top of that (`lib/today_cache.php`, shared with the birthday bot — 30 minutes
  for a good answer, 10 for a failure). A cached failure is the point, not an
  oversight: without it an unreachable database is retried by every open
  dashboard on every poll, each one waiting out the connect timeout. Each entry
  carries a signature of every setting that decides who counts, so editing the
  roster query or the milestone rules shows up immediately instead of at the
  end of a TTL.

Visibility follows `view_anniversaries` and `view_birthdays` independently: a
role holding one key sees only that group, and one holding neither never makes
the call.

---

## Commands

All of these go through `run.sh`, which runs the bot inside the pdo_dblib
container the app already builds (the host PHP has no MSSQL driver):

```bash
sudo bash anniversaries/run.sh --check       # is everything wired up?
sudo bash anniversaries/run.sh --list        # the next 60 days, with years
sudo bash anniversaries/run.sh --list=400    # the next year
sudo bash anniversaries/run.sh --dry-run     # today's message, posting nothing
sudo bash anniversaries/run.sh --demo        # post a full sample announcement
sudo bash anniversaries/run.sh --demo=3      # ...as a shared day
sudo bash anniversaries/run.sh --test-slack  # prove the token and channel
sudo bash anniversaries/run.sh --test-gifs   # do the GIF URLs still resolve?
sudo bash anniversaries/run.sh discover      # find the hire-date column
sudo bash anniversaries/run.sh --date=2026-12-01 --dry-run   # rehearse a day
```

`--check` is the first thing to reach for when the channel goes quiet. It walks
every dependency in order and prints the specific fix beside anything broken —
including the one question the others cannot answer: **did the bot actually
run this morning?**

A `--date` other than today is a **rehearsal**. It posts, and it is
deliberately not recorded — recording it would mark that date "already done",
and the real message on the day would never go out.

---

## When it goes wrong

The failure mode of this bot is silence, and a quiet channel looks identical
whether nobody had an anniversary, the token was revoked, MSSQL was down, or
the timer never came back after a rebuild. Four things exist to close that gap,
and none of them should be weakened:

- **A run record.** Every real firing writes `data/.heartbeat_anniversaries`
  and a `last_run` block in the state file (`posted` / `idle` / `disabled` /
  `failed`). `--check` and the page share one function to read them, so they
  can never disagree. `/api/health` reports the heartbeat under
  `anniversaries` — but it never moves the top-level `status`, which means "is
  the pause-group system working" and must stay trustworthy.
- **Retries.** The timer fires three times a day; the state file makes the
  repeats no-ops. Slack calls retry three times with backoff, honouring
  `Retry-After`.
- **A run lock** (`data/anniversary.lock`) around the whole posting path, so
  two overlapping runs cannot both decide nobody has been congratulated yet. A
  lock file that cannot be opened at all is deliberately not the same as one
  somebody else holds: the run continues unlocked rather than costing somebody
  their anniversary.
- **Its own everything.** Separate state file, heartbeat, lock and log from the
  birthday bot. The two timers can fire in the same minute, and a shared JSON
  file would mean each read-modify-write could drop the other's record.

### Timezones

systemd fires `OnCalendar` on the **system** timezone, which is not necessarily
the app's. At this venue the host runs UTC and the app runs `America/New_York`,
so a timer written as `09:00` fires at 05:00 local. `install.sh` compares the
two (`--print-timezone` reports the app's) and pins the zone onto the calendar
line when they differ and systemd is new enough to parse it. Never "fix" this
by converting to a fixed UTC offset — that breaks at every DST changeover.

---

## Permissions

Two keys, both granted once by a migration to every role that already holds
`birthdays_manage`:

| key | what it opens |
|---|---|
| `view_anniversaries` | the page: upcoming anniversaries and today's message |
| `anniversaries_manage` | changing settings and posting test messages to Slack |

---

## Tests

```bash
php anniversaries/tests/test_anniv_lib.php     # 186 — the bot's own logic
php anniversaries/tests/test_today_cache.php   # 39  — the dashboard strip's cache
```

186 assertions, no database and no network — the date matching, the
years-of-service arithmetic, the milestone rules and the message building are
all pure functions for exactly that reason. They cover the leap-day rule, year
zero, the ordinal edge cases (11th, 21st, 111th), the placeholder semantics on
a shared day, and the run-health verdicts. Two of them are worth knowing about:
one renders every built-in combination and fails if any placeholder is left
unreplaced, and one fails if any flavour line would not stand alone after an
arbitrary greeting.

The second file covers `lib/today_cache.php`, which this bot and the birthday
bot share: that a failure is cached (and for less time than a success), that a
backwards clock jump can't pin a stale entry forever, and that every setting
deciding who counts changes the signature — for BOTH bots — while a wording or
Slack change does not.
