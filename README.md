# Castle Fun Center - Pause Group Automation

A self-hosted automation **and reporting** tool for the CenterEdge Card System.
Two halves:

- **Automation** — pause groups, recurring active-hour schedules, one-off
  overrides, and kiosk pausing alongside games, enforced by a multi-tier
  scheduler (`at` jobs + per-minute watchdog + per-request safety nets).
- **Reporting** — a suite of analytics pages built on the local play feed and a
  READ-ONLY connection to the venue's CenterEdge **MSSQL / SQL Server** database:
  Analytics, Performance, Reader Groups, Go-Kart Labor, Card Loads, Ticket
  Trends, Revenue Mix, and a Database Explorer. History from before the app was
  installed is one-time backfilled from the POS `PlayerCardTrans` ledger.

Built with PHP and vanilla JavaScript — no frameworks, no build step, no
external dependencies.

## Requirements

| Requirement | Purpose |
|-------------|---------|
| PHP 7.4+ | Runtime (CLI and web) |
| SQLite3 extension | Embedded database |
| OpenSSL extension | AES-256-CBC credential encryption with HMAC-SHA256 integrity |
| mbstring extension | String handling in installer and runtime |
| cURL extension | CenterEdge API communication |
| Linux `at` + `atrm` (optional) | Native per-action job queueing; fallback mode works without these |
| Apache or Nginx | Web server (with PHP-FPM or mod_php) |
| Cron daemon (recommended) | Daily planning + per-minute watchdog execution |
| MSSQL PDO driver — `pdo_sqlsrv`, `pdo_dblib`, or ODBC (optional) | Only for the MSSQL reporting pages (Go-Kart Labor, Card Loads, Ticket Trends, Revenue Mix, Redemption, Promotional Cards, Item Watch, Database Explorer) and the historical backfills. The venue server needs one; the rest of the app runs without it. See `docs/MSSQL_DRIVER.md`. |

No Composer, no npm, no external PHP packages. Everything uses the PHP standard library.

## Feature Modules

### Pause Groups (`#/groups`)
- Define named groups of games and/or kiosks (by category and/or individual ID).
- Recurring weekly schedules define active (unpaused) hours.
- Override windows for one-off events (parties, maintenance) take priority over schedules.
- Manual pause/unpause buttons on the dashboard with optimistic UI updates.
- Watchdog cron + per-API-call safety nets enforce the desired state continuously.

### Kiosks (`#/kiosks`)
- Standalone page listing every kiosk reported by the CenterEdge `/kiosks` endpoint.
- Per-kiosk Pause / Unpause / Out-of-service controls plus any RPC actions the
  kiosk advertises in its `supportedActions` list (e.g. `reboot`).
- Capabilities-aware: when the card system reports `kiosks.operationStatus = false`,
  the pause/unpause controls are hidden and a banner explains why.
- Kiosks can also be added to a pause group, where they'll be paused/unpaused
  alongside the group's games via the same schedules and overrides.

### Analytics (`#/analytics`)
- Venue-wide KPI dashboard: plays, tickets, revenue, points, unique cards,
  payment mix, with previous-period trend deltas.
- Time-bucketed charts (hourly, day-of-week, daily), top-game leaderboards,
  and automation-activity breakdowns (actions by source / outcome / group).
- Range presets: Day / Week / Month / Year / Custom, with period navigation.
- **Deep money history:** pick a range older than the ~30-day detailed feed
  (a past year, a multi-year custom span) and the headline plays, tickets and
  **play value** reach back ~2 decades — sourced from a venue-wide daily rollup
  (`venue_daily_stats`) aggregated straight from the POS card ledger, so money
  and tickets are real that far back. A "Historical view" banner names the exact
  coverage window; the detailed breakdowns (by hour, by game, category, payment
  mix, guest insights) only exist for the recent window, so they're hidden on
  long ranges instead of shown partial. "Play value" is the dollars spent at the
  game readers (broader than the recent "Reader CC payments" figure) and is
  hidden from roles without `view_revenue` like every other dollar.

### Performance (`#/performance`)
- Searchable per-game performance reporting over **Day / Week / Month / Year**
  or a custom range, with period navigation and prior-period comparison.
- Backed by the permanent `game_daily_stats` rollup (written nightly before
  the raw-feed purge), stitched with the live raw feed for recent days —
  month/year history survives indefinitely while "today" stays live.
- Per-game drill-down modal with an hourly/daily/monthly trend chart.

### Card Lookup (`#/cards`)
- Read-only proxy for floor staff: card balance, time plays, privileges,
  transaction history, and PIN probe/validate (rate-limited and audit-logged).

### Reader Groups (`#/readers`)
- Analytics-only groupings of games/readers into "areas" (a game may be in many
  groups; groups never pause anything). Compares every area — totals, plays per
  day, busiest weekday/hour, prior-period deltas — plus a per-group day-of-week ×
  hour heatmap, trend, and per-game breakdown for staffing.

### Go-Kart Labor (`#/labor`)
- Go-kart sales vs staff wages by Day/Week/Month/Year/Custom, from the venue's
  CenterEdge **MSSQL** database. Sales = the POS "Go Kart Readers" division
  (DivNo 808); labor = wages computed from the time-clock punches. Includes
  swipes-by-hour, a real money-in-vs-wages-out hourly curve, and a weekday×hour
  heatmap (Money / Wages / Rate).

### Card Loads (`#/cardloads`)
- How much money guests ADD to their cards, by Day/Week/Month/Year/Custom, plus
  a money-loaded-by-hour curve and a weekday×hour heatmap. This venue defers card
  value, so a load is stored value (not a POS sale) and lives only in the card
  ledger — source is MSSQL `PlayerCardTrans` add-value transactions. Paid loads
  and comped/bonus value are shown separately.

### Ticket Trends (`#/tickets`)
- Redemption tickets earned by AREA (division) over Day/Week/Month/Year/Custom,
  with a tickets-by-day trend + per-division breakdown + prior-period delta.
  Tickets attribute to a division but never a game (the POS records no reader on
  a ticket credit). Source: MSSQL `PlayerCardTrans` ticket credits.

### Revenue Mix (`#/revenue`)
- The P&L-lite roll-up: sales dollars by CATEGORY (attractions vs food vs groups
  vs card fees) over Day/Week/Month/Year/Custom, with the mix shift over time,
  discount rate per category, and prior-period delta. Source: MSSQL `Sales`
  grouped by category. Day grain only (ShiftDate is midnight-stamped).

### Promotional Cards (`#/promotions`)
- Track **blocks of giveaway cards** by card-number range (e.g. "K104 on-air
  giveaway, cards 100000–100499, $30 each") and see how they performed: how many
  came back (activation rate), reloads and **average additional money loaded**,
  plays, tickets earned, value spent, and a per-card drill-down. Works like
  Reader Groups — a managed list of ranges plus a click-through detail view.
- Batch definitions live locally; every performance number is computed live from
  the POS card ledger (`PlayerCardTrans`) by card number. Money figures are
  hidden from roles without `view_revenue` (activation/plays/tickets stay
  visible). Create/edit/delete needs `promotions_manage`. Batches can be defined
  before the MSSQL connection is set up — their stats fill in automatically.

### Item Watch (`#/items`)
- Pin the **specific items and deals you care about** and watch how they sell:
  units, dollars, a 7-to-366-point trend sparkline, and the change vs the
  previous period — over Day/Week/Month/Year/Custom. Click a card for the full
  breakdown (revenue, avg price, discounts, gross margin, days sold, best day,
  the units-by-day trend, and a since-launch total).
- **A card can be one item or a whole deal.** An entry holds a SET of inventory
  numbers (`InvNo`), so "Go Kart 3-Ride Deal = 7157, 7158, 7159" is a single
  card whose figures are the union — with a per-InvNo table on the detail view
  showing which member is actually moving.
- A **best sellers** leaderboard for the same period ranks every item by
  revenue, so you can find what's selling without knowing an inventory number
  up front — one click turns any row into a watched card.
- Definitions live locally; every number is computed live from the POS `Sales`
  table (`QtySold`, `AmtSold`, `Discounts`, `CostSold`) grouped by `InvNo`.
  This is the only report with a cost source, so it's the only one showing
  gross margin — and it hides the margin columns entirely if this install
  leaves `CostSold` at 0, rather than reporting a fake 100%.
- Day grain only (`ShiftDate` is midnight-stamped), so there is no hour-of-day
  here. Money is hidden from roles without `view_revenue` (units, trends and
  days-sold stay visible); the revenue leaderboard needs `view_revenue`
  outright. Create/edit/delete needs `items_manage`. Items can be pinned before
  the MSSQL connection is set up — their numbers fill in automatically.

### Database Explorer (`#/explorer`)
- A READ-ONLY window into the CenterEdge MSSQL database for finding where metrics
  live: table browser (columns/types, date-column freshness, sample rows), a
  "find a metric" grouped-totals builder, and a guarded free-form `SELECT` with
  CSV export. Every query is single-`SELECT` guarded and audit-logged.

> The MSSQL reporting pages (Go-Kart Labor, Card Loads, Ticket Trends, Revenue
> Mix, Redemption, Promotional Cards, Item Watch, Database Explorer) all share **one**
> encrypted MSSQL connection, configured on the Go-Kart Labor page. Each report
> runs admin-editable, read-only queries. See
> [MSSQL Reporting](#mssql-reporting-centeredge-sql-server) below.

### Roles & Permissions (custom RBAC)
Access is controlled by **roles**, which are data — create and edit them in
**Settings → Roles & Permissions**. Each role grants a set of permissions
from a fixed catalog:

The catalog has **18** keys. The six `view_*` keys control page/section
VISIBILITY (unticking one hides the nav item, bounces the route, and closes its
read APIs); the rest gate features and data.

| Permission | Grants |
|------------|--------|
| `view_dashboard` | See the Dashboard page |
| `view_games` | See the Games page |
| `view_groups` | See the Pause Groups page |
| `view_kiosks` | See the Kiosks page |
| `view_schedules` | See the Schedules page |
| `view_overrides` | See the Overrides page |
| `analytics` | Analytics, Performance, and Reader Groups pages (plays/tickets) |
| `view_revenue` | Cash/revenue figures in reporting AND visibility of the four MSSQL money pages (Labor, Card Loads, Ticket Trends, Revenue Mix) |
| `cards` | Card lookup: balances, transactions, PIN checks |
| `manual_control` | Pause/unpause groups, kiosk & game actions, force syncs |
| `overrides_manage` | Create/delete schedule overrides |
| `groups_manage` | Create/edit/delete pause groups |
| `reader_groups_manage` | Create/edit/delete reader groups (analytics areas) |
| `promotions_manage` | Create/edit/delete promotional card batches (card-range tracking) |
| `items_manage` | Create/edit/delete watched items / deals on the Item Watch page |
| `schedules_manage` | Create/edit/delete schedules |
| `settings` | System settings: CenterEdge API credentials, timezone |
| `data_explorer` | Database Explorer + the MSSQL report **Test** buttons (raw POS data, incl. dollar & card figures) — separate from `settings`, admin-only by default |
| `users` | User management (admin accounts always excluded) |
| `view_logs` | View the Action Log / audit trail |

Four **seeded roles** ship: `admin` (everything — locked, cannot be edited or
deleted; holds every permission incl. `data_explorer`), `manager` (all floor +
reporting incl. `view_revenue`, no settings/users/`data_explorer`), `tech`
(operations + `settings`/`users`, but **no** `view_revenue`, `cards`,
`view_logs`, or `data_explorer` — so a technician can configure CenterEdge
credentials/timezone yet cannot reach money/card data or the DB Explorer), and
**`viewer`** (a read-only role — all pages + analytics + `view_revenue` + cards
+ logs, no operate/manage/settings keys — seeded once as a normal, fully
editable/deletable custom role). Every role's permissions can be tuned, and
custom roles can be added freely.

Safety rails: only admins manage roles and admin accounts; the last active
admin can never be demoted, deactivated, or deleted; non-admin users can only
assign roles whose permissions are a **subset of their own** (no
self-escalation); roles in use can't be deleted; unknown/corrupt role slugs
resolve to zero permissions. Sessions re-validate every ~60s, so role and
permission changes apply within a minute without re-login.

## Hosting Compatibility

Designed to run across common environments:

- **cPanel / shared hosting** — PHP + SQLite + cron. If `at` is unavailable, schedules still execute through `cron_watchdog.php` (every minute) and the API-level missed-action safety net.
- **VPS / dedicated Linux** — cron jobs plus `at`/`atrm` for precise native queueing.
- **Raspberry Pi (Ubuntu Server)** — same as VPS; install `at` if desired.

`at` improves execution precision to the exact scheduled minute. Without it, actions fire within 60 seconds via the watchdog cron or on the next API request via the built-in safety net.

## Deployment Guides

- `INSTALL-FCOS.md` — full Fedora CoreOS installation walkthrough.
- `DEPLOY-CEPLAY.md` — coexistence runbook for hosting pause-groups at `/ceplay` alongside an existing Grafana/reverse-proxy setup, plus DNS/TLS steps for `ceplay.thecastlefuncenter.com`.

## Installation

### Option 1: Interactive Installer (recommended)

Run from the command line:

```bash
php install.php
```

This walks through:
1. PHP extension preflight checks
2. `at`/`atrm` availability warning
3. Data directory creation and permission verification
4. SQLite database initialization (WAL mode, foreign keys)
5. Admin user creation (username, display name, password)
6. Timezone configuration
7. Optional CenterEdge API credential setup with connection test
8. Cron setup guidance and file permission instructions

The installer also runs in web mode — navigate to `/install.php` in a browser to create the first admin account through a web form. The web installer locks itself after the first admin user is created.

To wipe and start over:

```bash
php install.php --reset
```

### Option 2: Fresh Install (automated)

For a fully automated setup (useful for development or redeployment):

```bash
php fresh_install.php
```

This script:
1. Removes any existing database files
2. Generates a random AES-256 encryption key and writes it into `config.php`
3. Initializes a fresh database with all tables
4. Creates a default admin user (`admin` / `admin123!`)
5. Sets timezone to `America/New_York`
6. Verifies encryption round-trip

**Delete `fresh_install.php` after use** — it creates a known default password and is a security risk if left accessible.

### Environment Variables

| Variable | Purpose |
|----------|---------|
| `PG_ENCRYPTION_KEY` | 32-byte hex key (64 hex chars) for AES-256-CBC encryption of stored API credentials. Overrides the fallback key in `config.php`. |
| `PG_APP_DEBUG` | Set to `true` to include internal error details in API 500 responses. Do not enable in production. |

### Cron Setup

Add the following to your crontab (`crontab -e`), replacing the path:

```
* * * * * /usr/bin/php /path/to/pause-groups/cron_watchdog.php >> /path/to/pause-groups/data/watchdog.log 2>&1
5 0 * * * /usr/bin/php /path/to/pause-groups/cron.php >> /path/to/pause-groups/data/cron.log 2>&1
```

- **`cron_watchdog.php`** (every minute) — executes missed actions, enforces desired game states, re-queues broken `at` jobs, polls the CenterEdge play/system transaction feeds, writes a watchdog heartbeat.
- **`cron.php`** (daily at 00:05) — syncs the game list from CenterEdge, plans all actions for the day, queues `at` jobs, rolls up the raw feed into the permanent per-game daily **and hourly** stats, refreshes the venue-wide daily rollup (`venue_daily_stats`), backs up the SQLite DB (`data/backups/`, `VACUUM INTO`, keep 14), purges old data, rotates log files, runs any pending one-time MSSQL backfills (guest history + per-game play history + venue-wide daily), and writes a cron heartbeat.

Nothing about the MSSQL reporting or the backfills needs a CLI step — `cron.php` runs the backfills automatically (once, flag-guarded) as soon as it runs with an MSSQL connection configured, and `update.sh` runs them on deploy so history appears immediately.

If `at`/`atrm` are not installed, keep the watchdog cron running every minute. In that mode, due actions are picked up and executed by the watchdog within one minute of their scheduled time.

## Architecture

PHP backend with a vanilla JavaScript single-page application frontend. SQLite database (WAL mode). No frameworks.

```
pause-groups/
  index.php                # Router: SPA shell, API dispatch, static file serving,
                           #   tiered safety net (expired-override + missed-action enforcement)
  config.php               # Timezone, encryption key, session lifetime, API timeouts
  install.php              # Interactive first-run setup (CLI and web modes)
  fresh_install.php        # Automated wipe-and-rebuild (dev/redeployment)
  cron.php                 # Daily cron: game sync, day planning, at-job queueing,
                           #   data purge, log rotation, heartbeat
  cron_watchdog.php        # Per-minute watchdog: missed actions, state enforcement,
                           #   at-job requeue, heartbeat
  run_action.php           # Single-action executor invoked by at jobs
  run_backfills.php        # Runs any pending one-time MSSQL backfills on demand
  backfill_card_activity.php # Optional manual runner for the guest-ledger backfill
  update.sh                # Deploy script (pull, rebuild, run pending backfills)

  api/                     # API endpoint handlers (all require auth except /api/health)
    auth.php               #   Login, logout, session status
    settings.php           #   CenterEdge API config + timezone management
    games.php              #   Game list, categories, sync
    cards.php              #   Card lookup proxy (balance, history, PIN checks)
    groups.php             #   Pause group CRUD, manual pause/unpause, state enforcement
    kiosks.php             #   Kiosk list, pause/unpause/out-of-service, RPC actions
    schedules.php          #   Recurring schedule CRUD (bulk creation supported)
    overrides.php          #   Temporary override CRUD with immediate execution
    reader_groups.php      #   Analytics-area (reader group) CRUD
    analytics.php          #   Analytics/Performance/Reader-Group reporting + the perf window model
    labor.php              #   Go-Kart Labor (MSSQL: sales vs wages)
    cardloads.php          #   Card Loads (MSSQL: money added to cards)
    tickets.php            #   Ticket Trends (MSSQL: tickets earned by division)
    revenue.php            #   Revenue Mix (MSSQL: sales by category)
    explorer.php           #   Database Explorer (read-only MSSQL window)
    logs.php               #   Paginated, filterable action log
    users.php              #   Admin user management
    roles.php              #   Role CRUD + permission catalog
    capabilities.php       #   CenterEdge capability flags

  lib/                     # Core libraries
    db.php                 #   SQLite singleton, schema initialization, query helpers
    auth.php               #   Session management (HttpOnly, SameSite=Strict, brute-force delay, RBAC)
    csrf.php               #   CSRF token generation + timing-safe validation
    crypto.php             #   AES-256-CBC encrypt-then-MAC (HMAC-SHA256), backward-compatible
    validator.php          #   Input validation (strings, ints, dates, times, enums, arrays, URLs, pagination)
    centeredge_client.php  #   CenterEdge REST API client (SHA-1 auth, token caching, pagination, retry)
    mssql_client.php       #   READ-ONLY CenterEdge MSSQL client (single-SELECT guard, range binding, driver detection)
    reporting.php          #   Shared reporting helpers (redemption game-id set)
    scheduler.php          #   Scheduling engine (planning, execution, enforcement, rollups, backfills, purge)

  public/                  # Frontend assets
    css/style.css          #   Stylesheet entry (modular @imports; page styles under css/pages/)
    js/
      api.js               #   HTTP client with CSRF header injection
      app.js               #   SPA router and navigation (hash-based)
      login.js             #   Login form
      dashboard.js         #   Dashboard with live group states, auto-refresh
      games.js  cards.js  kiosks.js        #   Games / Card Lookup / Kiosks pages
      groups.js  schedules.js  overrides.js #   Automation editors
      analytics.js  performance.js  readers.js #   Analytics / Performance / Reader Groups
      labor.js  cardloads.js  tickets.js  revenue.js #   MSSQL report pages
      explorer.js            #   Database Explorer
      logs.js  settings.js    #   Action log / Settings (API config + users + roles)

  data/                    # Runtime data (created by installer)
    pause_groups.db        #   SQLite database (+ WAL/SHM journal files)
    backups/               #   Nightly VACUUM INTO snapshots (keep 14)
    .scheduler.lock        #   Concurrency lock file
    .heartbeat_cron        #   Cron heartbeat (ISO 8601 timestamp)
    .heartbeat_watchdog    #   Watchdog heartbeat (ISO 8601 timestamp)
    .last_missed_check     #   Throttle file for API-level safety net (mtime-based, 15s cooldown)
    cron.log               #   Daily cron output (auto-rotated at 500KB)
    watchdog.log           #   Watchdog output (auto-rotated at 500KB)

  docs/                    # Internal documentation (not user-facing)
    AUDIT.md               #   Security audit notes and findings
    CENTEREDGE_API.md      #   CenterEdge REST API reference + which endpoints the app uses
    MSSQL_DRIVER.md        #   Installing an MSSQL driver on the venue server
    INCIDENT-*.md          #   Post-incident write-ups
    api-reference/         #   CenterEdge Card System API specification (OpenAPI 3.0)
```

## Core Concepts

### Pause Groups

A named collection of arcade games. Games can be added to a group in two ways:
- **By CenterEdge category** — all games in the category are dynamically included (resolved at execution time from the game state cache).
- **By individual game ID** — specific games pinned to the group.

A single group can contain both category-based and individual game memberships. Game resolution is deduplicated.

### Schedules (Active Windows)

Recurring weekly time windows attached to a group. Each schedule defines a **day of week** (0=Sunday through 6=Saturday), a **start time**, and an **end time** (HH:MM format, no midnight crossing).

Schedule windows define when games are **active (unpaused)**:
- At `start_time` the scheduler generates an **unpause** action (games become active).
- At `end_time` the scheduler generates a **pause** action (active window ends).
- Outside all schedule windows, games default to **paused**.

Bulk creation is supported: a single API call can create schedules for multiple days with the same time window via the `days_of_week` array field.

When a schedule is created, updated, or deleted through the API, the system automatically replans the remainder of the day and immediately enforces the correct state for affected groups.

### Overrides

Temporary, date-bounded pause or unpause periods that take precedence over recurring schedules. Each override specifies:
- A **pause group**
- An **action** (`pause` or `unpause`)
- A **start datetime** and **end datetime** (YYYY-MM-DD HH:MM format)
- A **name** (descriptive label)
- The **creating user** (tracked automatically)

Override conflict resolution:
1. When an override's time range overlaps with a recurring schedule, the **override wins** — the schedule's transitions are suppressed for the duration.
2. When multiple overrides overlap, the **most recently started** override takes precedence.
3. When an override ends, the system restores the correct state by checking for other active overrides first, then falling back to the recurring schedule.

Overrides that are active at creation time execute immediately via the CenterEdge API. Deleting an active override immediately enforces the correct post-deletion state.

### Daily Planning

The daily cron job (`cron.php`, recommended at 00:05) performs:

1. **Game sync** — fetches the full game list from CenterEdge into the local cache.
2. **Missed-action catch-up** — executes any overdue actions from earlier.
3. **Day planning** — merges recurring schedules with active overrides to compute all transition points for the day. Override transitions suppress conflicting schedule transitions. At each time slot, the highest-priority source wins. Past times are skipped.
4. **At-job queueing** — queues each planned action as a Linux `at` job (or skips if `at` is unavailable).
5. **Stats rollup** — aggregates the raw play feed into the permanent per-game, per-day `game_daily_stats` **and** per-game, per-local-hour `game_hourly_stats` tables (recomputing a trailing ~28-day window; hourly retained ~400 days). Runs BEFORE the purge; if it fails, the raw-feed purge is skipped so no un-rolled-up data is ever lost. The guest ledger (`card_activity`, new-vs-returning) is rolled up here too. When MSSQL is configured, the trailing 40 days of the venue-wide daily rollup (`venue_daily_stats`, the Analytics overview's deep-history source) are refreshed here as well.
6. **DB backup** — `VACUUM INTO` a compact snapshot in `data/backups/`, keeping the last 14.
7. **Data purge** — removes action log entries older than 90 days, executed scheduled actions older than 30 days, expired overrides older than 90 days, and raw game-play transactions older than 30 days (long-range reporting is preserved by the rollups, which are never purged).
8. **One-time MSSQL backfills** — if an MSSQL connection is configured, seeds the deep history once (flag-guarded): the guest ledger, per-game daily/hourly stats, and the venue-wide daily rollup (`venue_daily_stats`) from the POS `PlayerCardTrans` ledger, reaching back ~2 decades. Shared runner `Scheduler::runPendingBackfills()` (also invoked by `run_backfills.php` / `update.sh`).
9. **Log rotation** — rotates `cron.log` and `watchdog.log` when they exceed 500KB (keeps last 256KB).
10. **Heartbeat** — writes an ISO 8601 timestamp to `.heartbeat_cron`.

### Execution Model

Actions are executed through multiple complementary mechanisms, providing defense in depth:

| Layer | Trigger | Precision | Description |
|-------|---------|-----------|-------------|
| **`at` jobs** | Exact scheduled time | To the minute | Each action queued as a Linux `at` job invoking `run_action.php`. Best precision. |
| **Watchdog cron** | Every minute | Within 60s | `cron_watchdog.php` catches missed actions, enforces desired states, re-queues broken `at` jobs. |
| **API safety net (Tier 1)** | Every API request | On demand | `index.php` checks for recently-expired overrides (5-minute lookback) and enforces correct state. Fast, cache-only check. |
| **API safety net (Tier 2)** | Every 15 seconds (throttled) | On demand | Full missed-action execution and state enforcement, including a CenterEdge cache sync. Triggered by API traffic. |
| **Immediate enforcement** | Schedule/override CRUD | Instant | Creating, updating, or deleting schedules/overrides triggers an immediate replan and state enforcement for affected groups. |

#### Action Execution Flow

When an action executes (via any mechanism):

1. Resolves the pause group to a deduplicated list of game IDs (categories + individual games).
2. Reads current game states from the local cache.
3. Skips games already in the target state.
4. Skips games marked `outOfService` (never touched by automation).
5. Sends a PATCH request to the CenterEdge API using JSON Patch format.
6. Updates the local cache with the API response.
7. Logs each game state change (or skip/error) to `action_log`.

#### Missed-Action Optimization

When catching up on multiple missed actions for the same group, only the **latest** action per group is executed against the API. Earlier superseded actions are marked with status 3 (superseded) without making API calls, avoiding wasteful churn (e.g., pause then immediately unpause).

### Game Sync Behavior

Game and status data is refreshed from CenterEdge through multiple paths:

1. **Daily cron** — full sync before planning.
2. **Watchdog cron** — syncs if cache is stale (older than 2 minutes).
3. **Dashboard "Sync Now" button** — `POST /api/games/sync` for immediate refresh.
4. **`GET /api/games` auto-primes cache** — runs a sync if the cache is empty.
5. **State enforcement** — syncs before each enforcement cycle (with staleness check).

The dashboard uses adaptive polling: 30 seconds by default, 10 seconds when an override is active, and 5 seconds when a transition or override expiry is imminent (< 2 minutes away). Override expiry and scheduled transitions trigger immediate enforcement and refresh.

### Concurrency Control

All CLI scripts (`cron.php`, `cron_watchdog.php`, `run_action.php`) and the `replanToday()` method acquire an exclusive file lock (`data/.scheduler.lock`) before executing. Lock behavior varies by context:

| Script | Lock Behavior |
|--------|--------------|
| `cron.php` | Non-blocking — skips if another instance is running |
| `cron_watchdog.php` | Retries for up to 15 seconds (1s intervals), then skips |
| `run_action.php` | Retries for up to 60 seconds (5s intervals), then fails |
| `replanToday()` | Retries for up to 30 seconds (5s intervals), then skips |

## Beta Readiness Smoke Checklist

Run this checklist before each beta push:

1. **Syntax + environment sanity**
   - Run `php -l` across all `*.php` files.
   - Run `php install.php` in a disposable environment and confirm all prerequisite checks pass.
2. **Scheduler health**
   - Run `php cron.php` and verify action planning completes without errors.
   - Run `php cron_watchdog.php` and verify watchdog heartbeat/log updates.
3. **UI/API flow**
   - Log in as admin and trigger **Sync Now** from the dashboard.
   - Create and delete a temporary override and verify immediate enforcement + action-log entries.
4. **Operational safety**
   - Confirm `install.php` and `fresh_install.php` are blocked from web access in beta/prod.
   - Confirm both cron entries exist and append to `data/cron.log` and `data/watchdog.log`.

## Database Schema

SQLite with WAL journaling, foreign keys enabled, 30-second busy timeout.

| Table | Purpose |
|-------|---------|
| `admin_users` | Admin accounts (username, bcrypt hash, display name, role slug, active flag). The `role` is a slug into the `roles` table (admin/manager/tech/viewer or any custom role) — not a fixed enum. |
| `api_config` | Key-value config store (base URL, credentials, timezone, bearer token). Sensitive values stored encrypted. |
| `pause_groups` | Named game collections with active/inactive flag |
| `pause_group_categories` | Category memberships for groups |
| `pause_group_games` | Individual game memberships for groups |
| `schedules` | Recurring weekly time windows (group, day of week, start/end time) |
| `schedule_overrides` | Temporary overrides (group, action, start/end datetime, creator) |
| `scheduled_actions` | Planned actions for the day (group, action, time, date, source, at_job_id, execution status) |
| `action_log` | Audit trail of all actions (timestamp, source, action, game, success/error) |
| `game_state_cache` | Local mirror of CenterEdge game data (ID, name, operation status, categories, sync time) |
| `kiosk_state_cache` | Local mirror of CenterEdge kiosks (ID, name, operation status, categories, supported actions, sync time) |
| `pause_group_kiosks` | Kiosk memberships for pause groups (paused/unpaused alongside the group's games) |
| `login_attempts` | Login rate-limiting state by IP (failed attempt counter + lockout window). |
| `game_play_transactions` | Rolling ~30-day cache of the CenterEdge play feed (per-play card, game, tickets, points, cash). Powers the live feed, hourly reporting, and recent-day stats. |
| `system_transactions` | ~400-day cache of the CenterEdge system-transaction feed (deferred-value events: expirations, merges), polled by the watchdog. |
| `roles` | Role definitions (slug, name, description, JSON permission list, system flag). Seeded with admin/manager/tech + a read-only `viewer` role. |
| `user_permission_overrides` | Per-user grant/deny overrides layered on top of the user's role (edited in Settings). |
| `game_daily_stats` | Permanent per-game, per-day rollup (plays, tickets, cash, points, unique cards). Written nightly before the purge; powers month/year reporting. Never purged. |
| `game_hourly_stats` | Permanent per-game, per-local-hour rollup (~400-day retention) so hour-of-day history outlives the raw feed. |
| `card_activity` | Guest ledger — first_seen/last_seen per card (monotonic UPSERT). Powers new-vs-returning; backfilled ~2 decades from MSSQL `PlayerCardTrans`. |
| `reader_groups` / `reader_group_games` | Analytics-only "area" groupings of games/readers (never pause anything; a game may be in many groups). |
| `action_retries` | Pending pause/unpause retries for games/kiosks that failed transiently (e.g. in use); re-attempted by the watchdog up to a cap. |

**Execution status codes** in `scheduled_actions.executed`:
- `0` — pending (not yet executed)
- `1` — executed successfully
- `2` — executed with errors
- `3` — superseded (skipped during catch-up because a later action for the same group replaced it)

## API Reference

All endpoints return JSON. State-changing requests (POST, PUT, PATCH, DELETE) require a valid `X-CSRF-Token` header (except `/api/auth/login`). Authentication is session-based via HttpOnly cookies.

### Authentication

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/auth/login` | POST | No | Authenticate. Returns user object + CSRF token. |
| `/api/auth/logout` | POST | Yes | Destroy session. |
| `/api/auth/status` | GET | No | Check session validity. Returns auth status + CSRF token. |

### Settings

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/settings` | GET | Read API config (passwords masked as `********`). |
| `/api/settings` | PUT | Update API config and/or timezone. Changing password clears cached bearer token. |
| `/api/settings/test` | POST | Test CenterEdge connection: authenticates, checks capabilities, counts games and categories. |

### Games

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/games` | GET | List cached games with categories and sync timestamp. Auto-syncs if cache is empty. |
| `/api/games/categories` | GET | Fetch categories live from CenterEdge (not cached). |
| `/api/games/sync` | POST | Force full game cache refresh from CenterEdge. |

### Pause Groups

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/groups` | GET | List all groups with member counts, game and kiosk stats (enabled/paused/outOfService), next transition, and active override. |
| `/api/groups` | POST | Create a group with optional `category_ids`, `game_ids`, and `kiosk_ids`. |
| `/api/groups/{id}` | GET | Single group with categories, games, kiosks, and schedules. |
| `/api/groups/{id}` | PUT | Update group name, description, active flag, categories, games, and kiosks. |
| `/api/groups/{id}` | DELETE | Delete group (cascades to schedules, categories, games, kiosks). |
| `/api/groups/{id}/pause` | POST | Immediately pause all games and kiosks in the group (manual action). |
| `/api/groups/{id}/unpause` | POST | Immediately unpause all games and kiosks in the group (manual action). |
| `/api/groups/{id}/enforce` | POST | Immediately enforce the correct state based on current schedules and overrides. |

### Kiosks

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/kiosks` | GET | List cached kiosks. Auto-syncs if cache is empty. |
| `/api/kiosks/{id}` | GET | Live single-kiosk lookup (bypasses cache). |
| `/api/kiosks/sync` | POST | Force resync from CenterEdge. |
| `/api/kiosks/{id}/pause` | POST | Set `operationStatus = paused`. |
| `/api/kiosks/{id}/unpause` | POST | Set `operationStatus = enabled`. |
| `/api/kiosks/{id}/out-of-service` | POST | Set `operationStatus = outOfService`. |
| `/api/kiosks/{id}/action` | POST | RPC perform-action passthrough (e.g. `{ "actionId": "reboot" }`). |
| `/api/kiosks` | PATCH | Bulk JSON Patch passthrough (multi-kiosk operationStatus update). |

### Schedules

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/schedules` | GET | List schedules. Supports `?group_id=` filter. |
| `/api/schedules` | POST | Create schedule(s). Supports bulk via `days_of_week` array or single `day_of_week`. Triggers replan + enforcement if today is affected. |
| `/api/schedules/{id}` | PUT | Update schedule. Triggers replan + enforcement. |
| `/api/schedules/{id}` | DELETE | Delete schedule. Triggers replan + enforcement. |

### Overrides

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/overrides` | GET | List overrides grouped as `active`, `upcoming`, and `expired` (last 30 days, max 50). Supports `?group_id=` filter. |
| `/api/overrides` | POST | Create override. If active now, executes immediately. Triggers replan. Tracks creating user. |
| `/api/overrides/{id}` | DELETE | Delete override. If it was active, immediately enforces the correct post-deletion state. |

### Card Lookup

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/cards/{number}` | GET | Card balance, time plays, privileges (named via cached lookups), and recent transactions. Gate: `cards`. |
| `/api/cards/{number}/pin` | POST | PIN probe/validate (rate-limited 15/10min per user, audit-logged). Gate: `cards`. |

### Analytics & Reporting (local play feed)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/analytics/overview` | GET | Venue KPIs, trend deltas, time-bucket series, leaderboards, automation-activity breakdown. `range`/`from`/`to`. Gate: `analytics` (dollars scrubbed without `view_revenue`). |
| `/api/analytics/games` | GET | Per-game leaderboard over Day/Week/Month/Year/Custom (rollup + raw stitch). Powers Performance. |
| `/api/analytics/game` | GET | Single-game drill-down (trend + hourly). |
| `/api/analytics/reader-groups` | GET | Per-area comparison (totals, averages, busiest weekday/hour, deltas). |
| `/api/analytics/reader-group` | GET | One area: heatmap, trend, per-game breakdown. |
| `/api/reader-groups` | GET/POST/PUT/DELETE | Reader-group CRUD. Read gate `analytics`; write gate `reader_groups_manage`. |
| `/api/promotions` | GET/POST/PUT/DELETE | Promotional card-batch CRUD + `/analyze` (per-batch MSSQL analysis), `/settings`, `/test`. Read gate `analytics` (money scrubbed without `view_revenue`); write gate `promotions_manage`; test gate `data_explorer`. |
| `/api/items` | GET/POST/PUT/DELETE | Item Watch CRUD + `/detail` (one entry: trend, per-InvNo breakdown, since-launch), `/top` (best sellers), `/settings`, `/test`. Read gate `analytics` (money scrubbed without `view_revenue`); `/top` additionally requires `view_revenue`; write gate `items_manage`; test gate `data_explorer`. |
| `/api/capabilities` | GET | CenterEdge capability flags (drives kiosk controls, card-admin UI). |

### MSSQL Reports (Labor / Card Loads / Ticket Trends / Revenue Mix)

All four share one shape (`{resource}` ∈ `labor`, `cardloads`, `tickets`, `revenue`) and one shared, encrypted MSSQL connection configured on the Labor page.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/{resource}/data` | GET | The report payload over Day/Week/Month/Year/Custom. Gate: `analytics` + `view_revenue`. |
| `/api/{resource}/settings` | GET/PUT | Read/save the admin-editable range SQL + connection info. Gate: `settings`. |
| `/api/{resource}/test` | POST | Run the query for a probe range and reconcile. Gate: `data_explorer`. |

### Database Explorer

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/explorer/tables`, `/table`, `/search`, `/aggregate`, `/query` | GET/POST | Read-only MSSQL browse + guarded free-form `SELECT` (single-SELECT enforced, rows capped, audit-logged). Gate: `data_explorer` (admin-only by default). |

### Users

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/users` | GET | List all admin users (id, username, display name, role, active flag, timestamps) plus the role catalog. |
| `/api/users` | POST | Create a new admin user (username, display name, role, password). Only admins can mint admins. |
| `/api/users/{id}` | PUT | Update user (display name, role, password, active flag). Admin accounts can only be modified by admins; last-admin demotion/deactivation is blocked. |
| `/api/users/{id}` | DELETE | Permanently delete a user (admin only; self-delete and last-admin deletion are blocked; override history is preserved). |

### Roles

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/roles` | GET | Role list (with per-role user counts and caller capabilities) plus the permission catalog. |
| `/api/roles` | POST | Create a custom role (admin only; name, description, permissions). |
| `/api/roles/{slug}` | PUT | Update a role (admin only; the `admin` role is locked; system manager/tech keep their slug). |
| `/api/roles/{slug}` | DELETE | Delete a custom role (admin only; blocked while any user holds it). |

### Logs

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/logs` | GET | Paginated action log. Filters: `from`, `to` (dates), `source`, `group_id`, `action`, `success`. Pagination: `page`, `per_page` (max 200). |

### Health

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/health` | GET | No | Health check. Reports database connectivity, cron heartbeat (healthy if <25 hours old), watchdog heartbeat (healthy if <3 minutes old). Returns HTTP 200 if all OK, 503 if degraded. |

## CenterEdge Integration

### Authentication Flow

The application authenticates with the CenterEdge Card System API using a SHA-1 hash-based flow:

1. Generate a UTC timestamp with millisecond precision (`YYYY-MM-DDTHH:MM:SS.mmmZ`).
2. Concatenate `username + password + timestamp`.
3. Compute `SHA-1` of the concatenation, then `base64` encode the raw hash.
4. POST to `/login` with `username`, `passwordHash`, `password`, and `requestTimestamp`.
5. Receive and cache a `bearerToken`.

### Token Management

- Tokens are cached encrypted in the database with a timestamp.
- Proactive refresh after 30 minutes (`TOKEN_MAX_AGE`).
- Automatic re-authentication on HTTP 401 responses.
- Token cache is cleared when API credentials are changed via settings.

### API Communication

- All requests use cURL with a 30-second timeout and 10-second connect timeout.
- Supports optional `X-Api-Key` header when an API key is configured.
- Game lists and categories are fetched with pagination (500 items per page, safety limit of 1000 pages).
- Game state changes use JSON Patch format: `[{"op": "replace", "path": "/operationStatus", "value": "paused"}]`.
- **Retry logic**: transient errors (network failures, 5xx, 408, 429) are retried up to 3 times with exponential backoff (2s, 4s, 8s). Client errors (4xx) other than 401/408/429 fail immediately.

### Game States

CenterEdge games have three `operationStatus` values:

| Status | Meaning | Automation Behavior |
|--------|---------|-------------------|
| `enabled` | Active, accepting play | Set by unpause actions |
| `paused` | Temporarily disabled | Set by pause actions |
| `outOfService` | Permanently offline | **Never touched** by automation (always skipped) |

### MSSQL Reporting (CenterEdge SQL Server)

CenterEdge exposes **no reporting API**, so the reporting pages read the venue's
CenterEdge **MSSQL / SQL Server** database directly, READ-ONLY, through
`lib/mssql_client.php`:

- **One shared connection**, configured (and connection-tested) on the Go-Kart
  Labor page and stored encrypted in `api_config`. Labor, Card Loads, Ticket
  Trends, Revenue Mix, and the Database Explorer all use it.
- **Driver:** PDO `sqlsrv` → `dblib` → ODBC (first available). The venue server
  needs one installed (see `docs/MSSQL_DRIVER.md`); the rest of the app runs
  without it, and the pages degrade to a "not connected" state.
- **Read-only guard:** every query is a single `SELECT`
  (`MssqlClient::assertReadOnly` — no `;`, no DML/DDL keywords), with
  regex-validated `:from`/`:to`/`:date` binding. Each report ships an
  admin-editable default range query; the Database Explorer's free-form SQL runs
  through the same guard, caps rows, and is audit-logged.
- **Key data sources** (all `[CenterEdge].[dbo].*`): `PlayerCardTrans` (the card
  ledger — plays/deductions, card loads, and tickets-earned, with a real clock
  time), `Sales` (POS sales by category/division, business-day grain), and
  `ReaderDevices` (reader→game mapping). This venue **defers card value**, so
  card loads are stored value in the ledger, not `Sales` rows. See `CLAUDE.md`
  for the full schema reference.

### Historical Backfills (one-time)

The local rollups only start accumulating once the app is running. To get deep
history immediately, two one-time backfills seed it from the POS ledger:

- **Guest ledger** — MIN/MAX transaction time per card from `PlayerCardTrans`
  into `card_activity`, so "new vs returning" reaches back ~2 decades.
- **Per-game history** — plays/value/unique-cards per game/day/hour from
  `PlayerCardTrans` (mapped `rdrkey`→game via `ReaderDevices`) into
  `game_daily_stats` / `game_hourly_stats`, for days before the live rollup's
  coverage.

Both are **idempotent and flag-guarded**. They run automatically — `cron.php`
(nightly) and `update.sh` (on deploy, via the `pdo_dblib` overlay image) both
call `Scheduler::runPendingBackfills()`; `run_backfills.php` runs them on demand.
No CLI step is required.

## Security

### Authentication & Sessions

- Passwords hashed with **bcrypt** (cost 12). Automatic rehash on login if cost parameter changes.
- Session cookies: **HttpOnly**, **SameSite=Strict**, **Secure** (when HTTPS detected). Strict session mode enabled.
- 2-hour session timeout with sliding window (activity refreshes the timer).
- Session ID regenerated on login to prevent session fixation.
- Failed logins: progressive delay + a per-IP lockout (10 tries). The client IP
  is derived by `getClientIp()`, which trusts `X-Forwarded-For` only from a
  loopback reverse proxy and then takes the **rightmost** (proxy-appended) hop —
  the client-supplied leftmost entries are never trusted, so the lockout can't be
  bypassed by rotating a spoofed IP.

### CSRF Protection

- 256-bit random token generated per session, stored server-side.
- Required via `X-CSRF-Token` header on all state-changing requests (POST, PUT, PATCH, DELETE).
- Validated with timing-safe `hash_equals()`.
- Login endpoint is exempt (pre-authentication).

### Encryption at Rest

- API credentials (username, password, API key, bearer token) encrypted with **AES-256-CBC**.
- **Encrypt-then-MAC** scheme: HMAC-SHA256 integrity verification before decryption.
- Separate encryption and MAC sub-keys derived from the master key via HKDF-like HMAC derivation.
- Backward-compatible: gracefully decrypts legacy data encrypted without HMAC (logs a notice).
- Master key sourced from `PG_ENCRYPTION_KEY` environment variable with fallback to `config.php`.

### Request Security

- All SQL queries use parameterized statements (`:p0`, `:p1`, ... positional binding).
- Input validation on all API endpoints via the `Validator` class (type checking, length limits, format validation, enum enforcement).
- Security headers on all responses: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`.
- CLI-only guards on `cron.php`, `cron_watchdog.php`, `run_action.php`, `run_backfills.php`, and `backfill_card_activity.php` prevent web execution.
- Static file serving restricted to the `public/` directory with path traversal protection.
- `install.php` web mode locks itself after the first admin user is created.
- RBAC is server-enforced on every request (client hides what it can't use, server re-checks). Raw POS data — the Database Explorer and the MSSQL report **Test** buttons — sits behind its own `data_explorer` permission (admin-only by default), separate from the `settings` permission a technician holds for CenterEdge/timezone config, so `tech` cannot reach money/card data. All MSSQL queries are single-`SELECT` guarded (`assertReadOnly`).

### Production Deployment Checklist

1. **Block `install.php` and `fresh_install.php`** via web server configuration or delete after setup.
2. **Set `PG_ENCRYPTION_KEY`** as an environment variable (don't rely on the hardcoded fallback).
3. **Enforce HTTPS** — session cookies are marked Secure only when HTTPS is detected.
4. **Restrict `data/` directory** — ensure it's not web-accessible (or use `.htaccess` / nginx location block). Permissions should be `770` owned by the web server user.
5. **Monitor health** — poll `GET /api/health` for degraded status. Alert if cron heartbeat is >25 hours old or watchdog heartbeat is >3 minutes old.
6. **Review logs** — check `data/cron.log` and `data/watchdog.log` for errors. Logs auto-rotate at 500KB.

## Configuration Reference

Constants defined in `config.php`:

| Constant | Default | Description |
|----------|---------|-------------|
| `ENCRYPTION_KEY` | From `PG_ENCRYPTION_KEY` env var | 64 hex chars (32 bytes) for AES-256-CBC |
| `DB_PATH` | `__DIR__ . '/data/pause_groups.db'` | SQLite database file path |
| `DEFAULT_TIMEZONE` | `America/New_York` | Fallback timezone (overridden by DB config) |
| `SESSION_LIFETIME` | `7200` (2 hours) | Session timeout in seconds |
| `APP_DEBUG` | `false` (from `PG_APP_DEBUG` env) | Verbose error output in API responses |
| `LOCK_FILE` | `__DIR__ . '/data/.scheduler.lock'` | Concurrency lock file path |
| `API_TIMEOUT` | `30` | CenterEdge API request timeout (seconds) |
| `TOKEN_MAX_AGE` | `1800` (30 min) | Bearer token refresh interval (seconds) |
| `GAMES_PAGE_SIZE` | `500` | Games per page when paginating CenterEdge API |

## Development

Start a local server:

```bash
php -S localhost:8000
```

Run the installer, configure CenterEdge API credentials through the Settings page, and trigger a game sync. The application uses hash-based routing: `#/dashboard`, `#/games`, `#/performance`, `#/cards`, `#/groups`, `#/kiosks`, `#/schedules`, `#/overrides`, `#/analytics`, `#/readers`, `#/labor`, `#/cardloads`, `#/tickets`, `#/revenue`, `#/explorer`, `#/logs`, `#/settings`. (Each page's visibility follows the role's permissions — hidden sections are removed from the nav and their routes bounce.)

The frontend is a SPA with dark and light themes (toggled via a button in the navigation bar, persisted to localStorage) using the Inter font family. All JavaScript modules are loaded as plain `<script>` tags (no bundler). The `api.js` module handles all HTTP communication and automatically injects the CSRF token header.

Manual pause/unpause actions use optimistic UI updates for instant visual feedback, skipping redundant API syncs.

There is no automated test suite.

## Beta Readiness Regression Checks

Before each beta release, run this minimum regression pass:

1. **PHP lint pass**
   ```bash
   find . -name '*.php' -print0 | xargs -0 -n1 php -l
   ```
2. **Installer sanity**
   - Run `php install.php` in a disposable environment and confirm admin creation + DB bootstrap.
   - Run `php install.php --reset` and confirm a clean rebuild.
3. **Scheduler sanity**
   - Create a schedule and verify both pause/unpause actions appear in logs.
   - If `at` is not installed, verify `cron_watchdog.php` executes actions within one minute.
4. **Reporting & roles**
   - Confirm the Performance page returns data for Day/Week/Month/Year and that `game_daily_stats` grows after a cron run.
   - Confirm a `tech` login sees no cash/revenue figures and no Card Lookup nav item.
   - Confirm group/schedule create/edit/delete is rejected (403) for a `tech` session.
5. **Manual UI sweep**
   - Validate each navigation route renders without console errors.
   - Validate dark/light theme toggle works on login + authenticated layouts.

## Known Limitations

- **No automated tests** — verification relies on the smoke checklist above and manual UI testing.
- **No overnight schedules** — a schedule cannot cross midnight (e.g., 23:00–01:00). Create two separate entries instead.
- **Single-server only** — SQLite does not support multi-server deployments. Use a single application server.
- **`at` scheduler optional** — without `at`/`atrm`, actions execute within 60 seconds via the watchdog cron rather than at the exact scheduled minute.
- **PHP 7.4 minimum** — uses positional unpacking and arrow functions available from PHP 7.4. Not tested on PHP 9+.
- **No email/SMS alerts** — operational monitoring relies on polling `/api/health` and reviewing log files.
