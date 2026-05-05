# Castle Fun Center — CEplay Management Console

A self-hosted, framework-free PHP application for the Castle Fun Center
arcade. CEplay sits between the venue's CenterEdge Card System API and a
small admin team, giving floor staff a single dashboard to:

- Pause and unpause **games** and **kiosks**, individually or in groups.
- Run recurring weekly schedules and one-off date-bounded overrides.
- Look up **player cards** (balance, transaction history, PIN probe).
- Watch live **swipe activity** and per-game / per-category analytics.
- Audit every administrative and scheduling action.

The whole stack is PHP 7.4+ with vanilla JavaScript on the front end. No
Composer, no npm, no build step — drop the files on a host with PHP and
SQLite and it runs.

---

## Table of contents

- [Requirements](#requirements)
- [Quick start](#quick-start)
- [How CEplay talks to CenterEdge](#how-ceplay-talks-to-centeredge)
- [Feature tour](#feature-tour)
- [Architecture](#architecture)
- [Configurable settings](#configurable-settings)
- [Scheduling and enforcement](#scheduling-and-enforcement)
- [Retry queue](#retry-queue)
- [Database schema](#database-schema)
- [API reference](#api-reference)
- [CenterEdge integration](#centeredge-integration)
- [Security](#security)
- [Operations](#operations)
- [Smoke testing checklist](#smoke-testing-checklist)
- [Known limitations](#known-limitations)

---

## Requirements

| Component | Why |
|-----------|-----|
| PHP 7.4 or newer (CLI + web SAPI) | Application runtime |
| SQLite3 PHP extension | Embedded database with WAL journaling |
| OpenSSL extension | AES-256-CBC + HMAC-SHA256 credential encryption |
| cURL extension | CenterEdge HTTPS calls |
| mbstring extension | UTF-8 input handling |
| Apache, nginx, or `php -S` | Web server with PHP-FPM or built-in CLI server |
| Cron daemon | Daily planner + per-minute watchdog (recommended) |
| Linux `at` and `atrm` *(optional)* | Native per-action job queueing; see [Scheduling](#scheduling-and-enforcement) for the fallback |

Tested on PHP 7.4 / 8.x. There is no Composer manifest — every dependency
is in the PHP standard library.

---

## Quick start

### 1. Lay down the files

```bash
git clone https://github.com/morroware/ceplay.git /var/www/ceplay
cd /var/www/ceplay
mkdir -p data
chown -R www-data:www-data data
chmod 770 data
```

### 2. Choose an install path

| Option | When to use |
|--------|-------------|
| `php install.php` (interactive CLI or web wizard) | Production / new venues. Walks you through PHP extension checks, admin user creation, timezone, and CenterEdge credential entry. |
| `php install.php --reset` | You're starting over and want every setting wiped. |
| `php install.php --migrate` | Existing DB — only run schema migrations. |
| `php fresh_install.php` | Disposable demo / dev box. Generates a random encryption key, creates `admin` / `admin123!`, and primes the schema. **Delete the file afterwards** — the password is well known. |

The CLI installer prompts in order:

1. Verify PHP extensions are loaded.
2. Warn if `at`/`atrm` are missing (CEplay still works without them).
3. Create the `data/` directory and confirm it's writable.
4. Initialise the SQLite schema (idempotent — safe to re-run).
5. Create the first admin account (bcrypt cost 12).
6. Set the venue timezone.
7. Optionally enter CenterEdge API credentials and run a live connection test.
8. Print the recommended cron entries.

### 3. Configure the cron jobs

Add to `crontab -e` (replace `/var/www/ceplay` with your actual path):

```cron
* * * * * /usr/bin/php /var/www/ceplay/cron_watchdog.php >> /var/www/ceplay/data/watchdog.log 2>&1
5 0 * * * /usr/bin/php /var/www/ceplay/cron.php           >> /var/www/ceplay/data/cron.log     2>&1
```

- **Watchdog (every minute)** — re-runs missed actions, enforces game and
  kiosk states against the local cache, retries failed pauses, and polls
  the CenterEdge play-transaction feed.
- **Daily planner (00:05)** — refreshes the game directory, plans every
  scheduled transition for the new day, queues `at` jobs, refreshes the
  game-categories cache, purges old data, and rotates log files.

### 4. Sign in and configure the API

Open the app in a browser, sign in with the admin account, and go to
**Settings**. Enter:

- API base URL (e.g. `https://yoursite.centeredge.io/api/v1`)
- Username + password
- Optional `X-Api-Key` if your CenterEdge tenant requires one

Click **Test Connection**. CEplay authenticates, fetches `/capabilities`,
counts games and categories, and reports back without persisting anything.
On success, click **Save Configuration** to write the credentials. They
are stored AES-256-CBC encrypted with HMAC-SHA256 integrity verification.

### 5. (Optional) lock the door

After the first admin is created, **delete `install.php` and
`fresh_install.php`**, or block them in your web server config. The
`/api/health` endpoint will warn in its `warnings[]` array if either file
is still web-accessible.

The Fedora CoreOS bootstrap script (`setup-fcos.sh`) removes
`install.php` automatically on first boot.

### Environment variables

| Variable | Purpose |
|----------|---------|
| `PG_ENCRYPTION_KEY` | 64 hex chars (32 random bytes) — master key for encrypting CenterEdge credentials at rest. Generate with `php -r 'echo bin2hex(random_bytes(32));'`. Falls back to `ENCRYPTION_KEY` defined in `config.php` if unset. |
| `PG_APP_DEBUG` | `true` to include internal error details in HTTP 500 responses. **Never** enable in production. |

---

## How CEplay talks to CenterEdge

CEplay is **read-mostly** against the CenterEdge API. Most browser
interactions hit the local SQLite cache instead of the upstream API.
This section enumerates **every** path that triggers an outbound CE call,
when it fires, and the expected volume.

### Background traffic (cron-driven)

| Trigger | Endpoint(s) | Frequency |
|---------|-------------|-----------|
| Daily cron (`cron.php`, 00:05 by default) | `POST /login`, `GET /games` (paginated), `GET /kiosks` (paginated), `GET /games/categories` (paginated) | Once per day |
| Watchdog cron (`cron_watchdog.php`, every minute) — game-play feed | `POST /login` (if token expired), `GET /games/transactions?sinceId=…` (up to 20 pages of 200 rows per cycle) | Once per `tx_poll_interval_seconds` (60 s default; 1, 2, 5, 10, or 15-minute presets in **Settings → CenterEdge API Polling**) |
| Watchdog cron — state enforcement | `GET /games`, `GET /kiosks` only when the local cache is older than `state_sync_stale_seconds` (300 s default), then `PATCH /games` / `PATCH /kiosks` only if a desired state needs correcting | Up to once per minute, but typically zero calls when no schedule transitions are happening |
| Watchdog cron — retry queue | `PATCH /games`, `PATCH /kiosks` for assets whose pause/unpause failed earlier (up to `retry_max_attempts`, default 10) | Once per minute, only when retries are pending |

### Foreground traffic (request-driven)

| Trigger | Endpoint(s) | Frequency |
|---------|-------------|-----------|
| User clicks **Sync Now** on the dashboard | `GET /games` (full, paginated) | On demand |
| User clicks **Sync** on the kiosks page | `GET /kiosks` (full, paginated) | On demand |
| User opens **Settings → Test Connection** with override credentials | `POST /login`, `GET /capabilities`, `GET /games`, `GET /games/categories` | On demand |
| User saves a CenterEdge schedule action (e.g. clicks Pause Group) | `GET /games` and `GET /kiosks` only if the cache is older than 30 s (`STATE_CHANGE_CACHE_FRESHNESS`), then `PATCH /games` and/or `PATCH /kiosks` for the affected assets | On demand |
| User opens the **Cards** page and looks up a card | `GET /cards/{cardNumber}`, optionally `GET /cards/{cardNumber}/transactions`, `GET /cards/{cardNumber}/pin?validate=…` | On demand |
| User opens the **Games** page and clicks a per-game action (e.g. Reboot) | `POST /games/{gameId}/performAction` | On demand |
| User opens the **Kiosks** page and runs an RPC action | `POST /kiosks/{kioskId}/performAction` | On demand |
| Tier 1 enforcement (every authenticated API request) | **No CenterEdge calls**. Reads the local cache only and only patches if a recently-expired override has not been honoured. | On demand |
| Tier 2 enforcement (gated by `tier2_throttle_seconds`, default 60 s, `index.php`) | May trigger a `GET /games` / `GET /kiosks` if the cache is older than `state_sync_stale_seconds`, plus `PATCH /games` / `PATCH /kiosks` if drift is detected | At most once per minute *across all browser tabs combined* |

### Server-side caches

CEplay caches everything aggressive enough to skip redundant CE traffic:

| Cache | Backed by | Refresh |
|-------|-----------|---------|
| Bearer token | `api_config.bearer_token` (encrypted) | Proactive re-auth after `TOKEN_MAX_AGE` (1800 s); automatic re-auth on any HTTP 401. |
| Game directory | `game_state_cache` table | Daily cron, `Sync Now`, and any state-change call that observes a stale cache. |
| Kiosk directory | `kiosk_state_cache` table | Same triggers as games. |
| Capabilities (`/capabilities`) | `api_config.capabilities_cache` (1-hour TTL) | First request after expiry, or when an admin appends `?refresh=1`. Stale copy served if upstream is unreachable. |
| Categories (`/games/categories`) | `api_config.categories_cache` (1-hour TTL) | Daily cron, on-demand via `?refresh=1`, or first request after expiry. Stale fallback on upstream failure. |
| Game-play transactions | `game_play_transactions` table (default 395 days) | Watchdog cron polls forward by transaction ID; rows are append-only with `INSERT OR IGNORE` so duplicate fetches are safe. |

**Net effect**: at default settings a venue running 24/7 makes roughly
60 CenterEdge calls per hour during normal browsing — almost all from
the watchdog's transaction-feed poll. Bumping the poll interval to
5 minutes drops that to ~12 calls per hour.

---

## Feature tour

### Dashboard (`#/dashboard`)

The command centre. Adaptive polling speeds up to 5 s when an override is
about to expire or a scheduled transition is imminent, and slows back to
the configured default rate (30 s by default) the rest of the time.

Widgets, top to bottom:

- **Stats grid** — total / running / paused / out-of-service game counts.
- **Swipe Activity** — totals and unique-card counts for last hour, today,
  and last 7 days.
- **Swipe by Category** — per-category breakdown of swipes and unique
  cards, with Last Hour / Today / Last 7 Days tabs. Category names are
  resolved from the cached `/games/categories` blob.
- **Top games today** — the busiest games right now (1–20 rows,
  configurable in Settings).
- **Group Controls** — one card per active pause group with current
  effective state (running / paused / mixed / empty), member breakdown,
  the next scheduled transition, the active override (if any), and Pause /
  Unpause buttons. Manual overrides are surfaced with a "Resume Schedule"
  button to release them.
- **Game Status** — a searchable, sortable, paginated table or grid of
  every game in the directory.
- **Active Overrides** — countdown view of every currently-running
  schedule override.

Everything except the **Sync Now** button reads from local caches.

### Games (`#/games`)

A full analytics dashboard powered by the cached play-transaction feed:

- KPI grid (plays, points, tickets, unique cards, time/privilege plays).
- Plays-over-time line chart (hour/day/month buckets per window).
- Status doughnut.
- Three top-N leaderboards (plays, tickets, points).
- Recent-play live feed.
- Searchable game directory with per-row pause / unpause / out-of-service
  buttons and any RPC actions the game advertises (e.g. `reboot`).

Window selector: Today / Week / Month / Year / All. Auto-refreshes
analytics on `ui_poll_games_analytics_ms` (30 s default) and the live feed
on `ui_poll_games_feed_ms` (15 s default).

### Cards (`#/cards`)

Read-only proxy to the CenterEdge `/cards` endpoints. Floor staff can:

- Look up a card by number → balance, time plays, privileges.
- Page through the card's transaction history.
- Probe whether a card has a PIN, or validate one against the API.

Every lookup is audit-logged (`card_viewed`, `card_pin_probed`, etc.) so
the manager can review who looked up which card.

### Pause Groups (`#/groups`)

A pause group is a named bucket of games and/or kiosks. Membership is
defined two ways and unioned:

- **By category** — every game in one or more CenterEdge categories
  (resolved at execution time against the game cache).
- **By individual ID** — specific games or kiosks pinned to the group.

Each group has a recurring schedule, an optional active override, and
optional manual override. The dashboard treats them as one unit even when
mixing games and kiosks.

### Schedules (`#/schedules`)

Recurring weekly time windows. Each row defines an active (unpaused)
window for a group on one day of week. Bulk creation via
`days_of_week: [1, 2, 3, 4, 5]` is supported in the API. Schedules cannot
cross midnight — split into two rows for overnight windows.

### Overrides (`#/overrides`)

One-off pause or unpause windows that take precedence over the recurring
schedule:

- Active overrides are listed on the dashboard with a countdown.
- Active overrides at boundaries are honoured for their entire duration —
  schedule transitions inside the override window are suppressed during
  planning.
- When an override expires the system enforces the correct
  schedule-derived state within seconds (Tier 1 safety net + watchdog).

### Kiosks (`#/kiosks`)

Standalone view of every CenterEdge kiosk. Per-kiosk Pause / Unpause /
Out-of-service controls plus any `supportedActions` (e.g. `reboot`). When
the API reports `kiosks.operationStatus = false`, the pause controls are
hidden and a banner explains why. Kiosks that report no operation status
("unknown") are also hidden from pause controls per the spec.

### Action Log (`#/logs`)

Searchable, paginated audit trail. Filters: source (cron / manual /
override / schedule / watchdog / admin / auth / card-lookup / etc.),
action, group, success flag, and date range. The log captures the actor
ID, username, and IP for every state-change so manager review is trivial.

### Settings (`#/settings`)

Sectioned editor for everything an admin would otherwise have to edit
config files for. See the [Configurable settings](#configurable-settings)
section.

---

## Architecture

```
ceplay/
├── index.php               Front controller — SPA shell, API dispatcher,
│                           Tier 1 + Tier 2 safety nets, security headers
├── config.php              Constants (paths, timeouts, encryption key)
├── install.php             Interactive installer (CLI + web)
├── fresh_install.php       Wipe-and-rebuild dev installer (delete after use)
├── cron.php                Daily planner — game/kiosk/category sync, plan
│                           today, queue at-jobs, purge, log rotation
├── cron_watchdog.php       Per-minute watchdog — missed actions, state
│                           enforcement, retry queue, transaction feed poll
├── run_action.php          Single-action executor invoked by `at` jobs
├── setup-fcos.sh           Fedora CoreOS bootstrap (auto-removes install.php)
│
├── api/                    API endpoint handlers (loaded on demand)
│   ├── auth.php            POST /login, /logout, GET /status
│   ├── settings.php        GET/PUT /settings, POST /settings/test
│   ├── games.php           Game directory, analytics, transactions, RPC actions
│   ├── cards.php           /cards/{id}, /transactions, /pin
│   ├── groups.php          Pause group CRUD + manual actions
│   ├── kiosks.php          Kiosk directory + pause / unpause / RPC
│   ├── schedules.php       Recurring schedule CRUD (bulk supported)
│   ├── overrides.php       Override CRUD with immediate execution
│   ├── logs.php            Filterable, paginated audit log
│   ├── users.php           Admin user CRUD
│   └── capabilities.php    /capabilities passthrough (cached, with
│                           ?refresh=1 escape hatch and stale-fallback)
│
├── lib/                    Core libraries
│   ├── db.php              SQLite singleton, schema init, query helpers
│   ├── auth.php            Session lifecycle, bcrypt, brute-force guard
│   ├── csrf.php            CSRF token generation + timing-safe validation
│   ├── crypto.php          AES-256-CBC encrypt-then-MAC (HMAC-SHA256)
│   ├── validator.php       Input validation throwing RuntimeException
│   ├── centeredge_client.php  HTTP client (login, retries, pagination,
│   │                          token cache, kiosks/games sync, tx polling)
│   └── scheduler.php       Planning, execution, enforcement, retries,
│                           purge, heartbeat
│
├── public/
│   ├── css/style.css       Dark + light themes
│   └── js/                 Vanilla JS modules (loaded as plain <script defer>)
│       ├── api.js          fetch() wrapper with CSRF + base path injection
│       ├── app.js          SPA router, navigation, App.config init
│       ├── login.js        Login page
│       ├── dashboard.js    Command Center
│       ├── games.js        Games analytics + directory
│       ├── cards.js        Card lookup
│       ├── groups.js       Pause group editor
│       ├── kiosks.js       Kiosk directory
│       ├── schedules.js    Schedule editor
│       ├── overrides.js    Override management
│       ├── logs.js         Action log viewer
│       └── settings.js     Settings editor
│
├── data/                   Runtime data (gitignored, created by installer)
│   ├── pause_groups.db     SQLite database (+ -wal, -shm journals)
│   ├── .scheduler.lock     flock() target for cron / run_action / replan
│   ├── .heartbeat_cron     Daily cron heartbeat (ISO 8601)
│   ├── .heartbeat_watchdog Watchdog heartbeat (ISO 8601)
│   ├── .last_missed_check  Tier 2 throttle marker
│   ├── cron.log            Daily cron output (auto-rotated at 512 KB)
│   └── watchdog.log        Watchdog output (auto-rotated at 512 KB)
│
├── docs/
│   ├── AUDIT.md            Internal security review notes
│   └── api-reference/      CenterEdge OpenAPI spec (HTML + YAML)
│
├── INSTALL-FCOS.md         Fedora CoreOS deployment walkthrough
├── DEPLOY-CEPLAY.md        Coexistence runbook (proxy + DNS + TLS)
└── README.md               (this file)
```

### Request lifecycle

1. **Apache / nginx / `php -S`** routes every request to `index.php`.
2. `index.php` boots `config.php`, the DB layer, the CSRF helper, and
   `Auth::initSession()`. Security headers are emitted on every response.
3. If the path begins with `api/`, `index.php` dispatches to the matching
   handler in `api/`. Before dispatch:
   - Display errors are silenced so warnings can't corrupt JSON output.
   - For authenticated requests, **Tier 1** (cache-only expired-override
     check) and **Tier 2** (throttled missed-action + state enforcement)
     run as safety nets.
   - CSRF is validated for `POST`/`PUT`/`PATCH`/`DELETE` (login is
     exempt — there's no session yet).
4. Static assets in `public/` are served directly with `readfile()` and a
   1-hour cache header. Path traversal is blocked via `realpath()` checks.
5. Anything else is the **SPA shell** — `index.php` emits HTML containing
   a `window.APP_CONFIG = { … }` block (CSRF token, basePath, current
   user, timezone, all UI poll intervals) and the `<script defer>` tags
   for the vanilla JS modules.
6. `app.js` registers a `DOMContentLoaded` handler that copies
   `APP_CONFIG` values into `App.config`, then routes by `window.location.hash`.

### Frontend conventions

- Routes are registered with `App.registerRoute('#/path', { render: fn })`.
- Modules are IIFEs that close over module-level state.
- DOM construction goes through `App.el(tag, attrs, children)` — there is
  no `innerHTML` injection of user data anywhere.
- HTTP calls go through `API.get / .post / .put / .patch / .del`. `del` is
  used because `delete` is a reserved word in JavaScript.

### Backend conventions

- API handlers are functions named `handleX($method, $parts, $input)`.
- `$parts` is the URL segments after `api/<resource>`.
- `$input` is the parsed JSON body for `POST`/`PUT`/`PATCH`.
- `RuntimeException` → HTTP 422 with the message; any other exception →
  HTTP 500 (sanitised in production, full text with `PG_APP_DEBUG=true`).
- Database access goes through the `DB` singleton with positional
  parameters (`:p0, :p1, …`) only. No string concatenation of values.

---

## Configurable settings

Everything below is editable from **Settings** in the UI and persisted in
the `api_config` SQLite table. The values are read live, so changes take
effect on the next request (or the next page load for browser-side
intervals).

### CenterEdge API configuration

- **API Base URL**, **Username**, **Password**, optional **API Key**.
- **Test Connection** lets you verify candidate credentials without
  saving.
- Saving with a non-masked password rotates the cached bearer token.

### Timezone

- IANA name (e.g. `America/New_York`).
- All schedules, overrides, and log timestamps render in this zone.

### CenterEdge API Polling

| Setting | DB key | Range | Default |
|---------|--------|-------|---------|
| Transaction-feed poll interval | `tx_poll_interval_seconds` | 60 / 120 / 300 / 600 / 900 | 60 s |

This drives the only cron-driven contact with CenterEdge other than the
daily sync. Lower = fresher swipe analytics; higher = fewer outbound
calls.

### Safety Nets & State Sync

| Setting | DB key | Range | Default |
|---------|--------|-------|---------|
| Tier 2 safety-net throttle | `tier2_throttle_seconds` | 15–3600 s | 60 s |
| Game/Kiosk state-sync staleness | `state_sync_stale_seconds` | 30–3600 s | 300 s |

The watchdog's full state-sync only re-fetches `/games` and `/kiosks`
when the local cache is older than `state_sync_stale_seconds`. State-change
actions (manual button presses, etc.) always use a tight 30-second
freshness window regardless.

### Browser Polling Intervals

These never contact CenterEdge — they only pull from the local cache.

| Setting | DB key | Default |
|---------|--------|---------|
| Dashboard normal poll | `ui_poll_default_ms` | 30 000 ms |
| Dashboard active-override poll | `ui_poll_override_active_ms` | 10 000 ms |
| Dashboard imminent-transition poll | `ui_poll_imminent_ms` | 5 000 ms |
| Games analytics refresh | `ui_poll_games_analytics_ms` | 30 000 ms |
| Games live-feed refresh | `ui_poll_games_feed_ms` | 15 000 ms |
| Overrides page refresh | `ui_poll_overrides_ms` | 15 000 ms |
| Top-games widget size | `dashboard_top_games_limit` | 5 (1–20) |

### Data Retention (applied by the daily cron)

| Setting | DB key | Range | Default |
|---------|--------|-------|---------|
| Action-log retention | `retention_action_log_days` | 7–3650 | 90 d |
| Scheduled-action retention | `retention_scheduled_actions_days` | 1–365 | 30 d |
| Override retention | `retention_overrides_days` | 7–3650 | 90 d |
| Transaction retention | `retention_transactions_days` | 30–3650 | 395 d |

The 395-day default keeps a full year of swipe data for the analytics
dashboard with a small buffer.

### Scheduler Behaviour

| Setting | DB key | Range | Default |
|---------|--------|-------|---------|
| Max retry attempts | `retry_max_attempts` | 1–50 | 10 |

Each retry is one watchdog cycle (one minute), so 10 attempts ≈ a
10-minute catch-up window.

---

## Scheduling and enforcement

### Concepts

- **Schedule windows** define **active (unpaused)** hours.
  - At `start_time` → unpause (group becomes active).
  - At `end_time` → pause (group goes back to paused).
  - Outside any schedule window the default is **paused**.
- **Overrides** force a specific action for a date/time range. They take
  precedence over recurring schedules. Schedule transitions that fall
  inside an override window are dropped during planning.
- **Manual overrides** (the Pause / Unpause buttons on the dashboard)
  take precedence over both. They stick until the next scheduled
  transition fires, or until an admin clicks **Resume Schedule**.

### Daily planning (`cron.php` at 00:05)

1. **Sync games** — `GET /games` (paginated). Updates `game_state_cache`,
   prunes rows for games no longer in the directory.
2. **Sync kiosks** — same, `GET /kiosks`. Best-effort: a 4xx (kiosks
   unsupported) is logged and skipped.
3. **Refresh categories cache** — `GET /games/categories`.
4. **Catch up missed actions** for the new calendar day.
5. **Plan today** — for each active group:
   - Build transition points from today's recurring schedules.
   - Add transitions for any override that starts or ends today.
   - Suppress any schedule transition that falls inside an active
     override window.
   - Sort, deduplicate by time (highest-priority source wins), drop past
     times, and write to `scheduled_actions`.
6. **Queue `at` jobs** for each pending action. If `at`/`atrm` aren't
   installed, this step is a no-op — actions are picked up by the
   watchdog instead.
7. **Purge old data** per the retention settings.
8. **Rotate logs** when they exceed 512 KB.
9. **Write heartbeat** to `data/.heartbeat_cron`.

### Per-minute watchdog (`cron_watchdog.php`)

Runs after the daily cron, and again every minute around the clock. It:

1. Acquires the scheduler `flock()` (15-second blocking retry).
2. Executes any missed actions for today.
3. Calls `Scheduler::enforceCurrentStates()` — for every active group,
   computes the desired state from manual override > active override >
   schedule, and `PATCH`es the upstream API only if the cache disagrees.
4. Re-queues any pending `scheduled_actions` rows whose `at_job_id` is
   missing (i.e. the `at` job vanished or was never queued).
5. Drains the **retry queue** (see next section).
6. Polls the CenterEdge transaction feed if the configured interval has
   elapsed since the last poll. Walks forward from the last processed
   transaction ID up to 20 pages of 200 rows per cycle.
7. Writes heartbeat to `data/.heartbeat_watchdog`.

### Per-API-call safety nets (`index.php`)

Every authenticated API request runs two checks before dispatching to the
handler:

- **Tier 1** — `Scheduler::enforceExpiredOverrides(300)`. Cheap, cache-
  only. Looks at any override that ended within the last 5 minutes and
  enforces the correct post-expiry state if the cached state still shows
  the override's action.
- **Tier 2** — gated by a file-lock + mtime throttle. At most once every
  `tier2_throttle_seconds` (60 s default) it runs
  `Scheduler::executeMissedActions()` and `Scheduler::enforceCurrentStates()`.

This means the system self-corrects to the right state within 60 seconds
of the next browser interaction, even if both crons are silenced.

### `at` job execution (`run_action.php`)

`at` triggers `php run_action.php --id <scheduled_action_id>`. The
script:

1. Acquires the scheduler `flock()` (60 s blocking retry).
2. Calls `Scheduler::executeAction($id)`.
3. Releases the lock and exits.

The action's `executed` column is set to `1` on success, `2` on partial
errors, or `3` if a later action superseded it during catch-up.

---

## Retry queue

When a `PATCH /games` or `PATCH /kiosks` call comes back with a per-asset
error (typically the asset is in active play, or upstream is throttling),
CEplay queues a retry instead of dropping the request:

- A row is upserted into `action_retries` keyed by `(asset_type, asset_id)`.
- The watchdog drains the queue once per cycle, retrying every pending
  asset with `attempts < max_attempts`.
- After `retry_max_attempts` failures (default 10 ≈ 10 minutes), the row
  is marked `gave_up_at` and **skipped** on subsequent cycles. This stops
  the watchdog from spinning forever against a permanently-busy game.
- A **fresh intent** clears the give-up state automatically:
  - Clicking Pause / Unpause from the dashboard.
  - A scheduled transition firing.
  - An override starting or ending.
  - Calling `groups/{id}/enforce` from the API.
- Once the cache observes that an asset reached the desired state on its
  own (e.g. another operator acted), the retry row is also cleared.

This mechanism is what makes group operations resilient on busy
weekends — a single stuck game in a 30-game group no longer prevents the
remaining 29 from being pause/unpaused, and the system retries the stuck
one in the background instead of silently dropping the intent.

---

## Database schema

SQLite with WAL journaling, foreign keys enabled, 30-second busy timeout.
Schema initialisation is **idempotent** — `CREATE TABLE IF NOT EXISTS`
plus `try { ALTER TABLE ADD COLUMN }` migrations on every boot.

| Table | Purpose |
|-------|---------|
| `admin_users` | Admin accounts (username, bcrypt hash, display name, active flag) |
| `api_config` | Key-value store (encrypted CenterEdge credentials, timezone, every UI/scheduler/retention setting, capabilities cache, categories cache, transaction-feed checkpoints) |
| `pause_groups` | Named groups + manual override columns |
| `pause_group_categories` | Category-based group memberships |
| `pause_group_games` | Individual game memberships |
| `pause_group_kiosks` | Individual kiosk memberships |
| `schedules` | Recurring weekly windows (group, day, start, end, active flag) |
| `schedule_overrides` | One-off pause/unpause windows |
| `scheduled_actions` | Planned transitions for a date (id, group, action, time, source, at_job_id, executed) |
| `action_log` | Audit trail with actor_user_id, actor_username, ip_address |
| `action_retries` | Pending retries with attempts, max_attempts, gave_up_at |
| `game_state_cache` | Local mirror of `/games` |
| `kiosk_state_cache` | Local mirror of `/kiosks` |
| `game_play_transactions` | Append-only mirror of `/games/transactions` |
| `login_attempts` | Brute-force rate-limit state, keyed by IP |

### `scheduled_actions.executed` codes

| Code | Meaning |
|------|---------|
| `0` | Pending |
| `1` | Executed successfully |
| `2` | Executed with errors |
| `3` | Superseded — skipped during catch-up because a later action for the same group made it redundant |

---

## API reference

All responses are JSON. State-changing methods require a valid
`X-CSRF-Token` header (except `/api/auth/login`). Authentication is
session-based via HttpOnly, SameSite=Strict cookies.

### Auth

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/api/auth/login` | No | `{ username, password }` → `{ user, csrf_token }` |
| POST | `/api/auth/logout` | Yes | Destroys session and regenerates ID |
| GET  | `/api/auth/status` | No | `{ authenticated, user, csrf_token }` |

### Settings

| Method | Path | Description |
|--------|------|-------------|
| GET  | `/api/settings` | All current settings (passwords masked as `********`) |
| PUT  | `/api/settings` | Update API config, timezone, polling, retention, scheduler |
| POST | `/api/settings/test` | Test connection. Pass overrides in the body to verify candidate credentials without persisting them |

### Games

| Method | Path | Description |
|--------|------|-------------|
| GET   | `/api/games` | Cached game directory + per-game `pending_retry` |
| GET   | `/api/games/categories` | Cached categories (1-hour TTL); `?refresh=1` to bypass |
| POST  | `/api/games/sync` | Force re-sync from CenterEdge |
| GET   | `/api/games/analytics` | Aggregated KPIs / leaderboards / time-series for `?window=day\|week\|month\|year\|all` |
| GET   | `/api/games/transactions/recent` | Most recent plays from cache. `?limit=50` (max 500), `?since=ISO8601` |
| GET   | `/api/games/transactions/top` | Top games by plays. `?window=hour\|today\|week\|all`, `?limit=10` (max 100) |
| GET   | `/api/games/transactions/summary` | Total plays + unique cards for last hour / today / last 7 days in a single call |
| GET   | `/api/games/transactions/by-category` | Per-category plays + unique cards. `?window=hour\|today\|week` |
| POST  | `/api/games/transactions/poll` | Manual catch-up: poll the upstream feed now |
| GET   | `/api/games/{id}` | Live single-game lookup (bypasses cache) |
| POST  | `/api/games/{id}/action` | RPC perform-action (`{ actionId, operator? }`). The operator object falls back to the logged-in admin |
| PATCH | `/api/games` | Bulk JSON-Patch passthrough — `{ games: { gameId: [{op:"replace", path:"/operationStatus", value:"paused"}] } }` |

### Cards (read-only proxy)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/cards/{cardNumber}` | Card record (balance, time plays, privileges) |
| GET | `/api/cards/{cardNumber}/transactions` | History (`?skip=0`, `?take=50`, max 200) |
| GET | `/api/cards/{cardNumber}/pin` | Probe/validate. With `?validate=<pin>`, validates; otherwise just probes whether the card has a PIN |

### Pause Groups

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/groups` | All groups with combined game+kiosk stats, next transition, active override, manual override, and resolved game/kiosk ID lists |
| POST   | `/api/groups` | Create with optional `category_ids[]`, `game_ids[]`, `kiosk_ids[]` |
| GET    | `/api/groups/{id}` | One group with its categories, games, kiosks, and schedules |
| PUT    | `/api/groups/{id}` | Replace name/description/active flag and all membership lists |
| DELETE | `/api/groups/{id}` | Delete group (cascades to schedules + memberships) |
| POST   | `/api/groups/{id}/pause` | Manual pause (sets manual override) |
| POST   | `/api/groups/{id}/unpause` | Manual unpause (sets manual override) |
| POST   | `/api/groups/{id}/enforce` | Force the correct state from current schedule + overrides |
| POST   | `/api/groups/{id}/clear-manual-override` | Resume the schedule |

### Schedules

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/schedules` | List, optionally filtered by `?group_id=` |
| POST   | `/api/schedules` | Create one (`day_of_week`) or many (`days_of_week: [1,2,3]`) |
| PUT    | `/api/schedules/{id}` | Update one row |
| DELETE | `/api/schedules/{id}` | Delete one row |

Schedule changes trigger `Scheduler::replanToday()` + state enforcement.

### Overrides

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/overrides` | `{ active, upcoming, expired }` (last 30 days, max 50). Optional `?group_id=` filter |
| POST   | `/api/overrides` | Create override; if active right now, executes immediately; replans the day |
| DELETE | `/api/overrides/{id}` | Delete override; if it was active, immediately enforces the post-deletion state |

### Kiosks

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/kiosks` | Cached kiosk list with `pending_retry` per kiosk |
| GET    | `/api/kiosks/{id}` | Live single-kiosk lookup |
| POST   | `/api/kiosks/sync` | Force re-sync |
| POST   | `/api/kiosks/{id}/pause` | `operationStatus = paused` (queues a retry on failure) |
| POST   | `/api/kiosks/{id}/unpause` | `operationStatus = enabled` |
| POST   | `/api/kiosks/{id}/out-of-service` | `operationStatus = outOfService` |
| POST   | `/api/kiosks/{id}/action` | RPC perform-action (`{ actionId, operator? }`) |
| PATCH  | `/api/kiosks` | Bulk JSON-Patch passthrough (only `replace /operationStatus` is allowed) |

### Capabilities

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/capabilities` | Cached for 1 hour. `?refresh=1` to force a live fetch. Stale fallback if upstream is unreachable |

### Logs

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/logs` | Paginated log. Filters: `from`, `to`, `source`, `group_id`, `action`, `success`. `?per_page` max 200 |
| GET | `/api/logs/options` | The canonical lists of valid `source` and `action` filter values |

### Users

| Method | Path | Description |
|--------|------|-------------|
| GET    | `/api/users` | All admin accounts |
| POST   | `/api/users` | Create (username, display name, password ≥ 8 chars) |
| PUT    | `/api/users/{id}` | Update display name / password / active flag (cannot deactivate self) |

### Health

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/health` | No | `{ status, database, cron, watchdog, warnings? }`. HTTP 200 healthy / 503 degraded. Reports the cron heartbeat (healthy if < 25 h old) and watchdog heartbeat (healthy if < 3 min old). `warnings[]` flags `install.php` or `fresh_install.php` still being web-accessible, or `APP_DEBUG` left on. |

---

## CenterEdge integration

### Authentication flow

```
1. dt    = current UTC instant, format Y-m-d\TH:i:s.v\Z
2. hash  = base64( SHA1(username + password + dt, raw=true) )
3. POST /login with { username, passwordHash:hash, password, requestTimestamp:dt }
4. Response → bearerToken; cached encrypted in api_config.bearer_token
```

`TOKEN_MAX_AGE` (1800 s) drives proactive re-authentication. Any HTTP 401
on a real call also triggers re-auth and one retry. Saving new
credentials in Settings clears `bearer_token` and `token_fetched_at` so
the next call re-authenticates.

### Pagination

`GET /games`, `GET /kiosks`, and `GET /games/categories` are paginated
with `skip` / `take`. CEplay defaults to `take = GAMES_PAGE_SIZE` (500)
with a hard ceiling of 1000 pages per call as a runaway-loop guard.

### Retries and timeouts

The HTTP layer wraps every call with:

- 30-second total timeout (`API_TIMEOUT`).
- 10-second connect timeout.
- Up to 3 transient retries with exponential backoff (2 s → 4 s → 8 s)
  for network errors, HTTP 5xx, 408, and 429.
- 401 → one re-auth attempt, then retry once.
- All other 4xx → fail immediately with the upstream message.

### State patches

`PATCH /games` and `PATCH /kiosks` use JSON-Patch:

```json
{ "games": { "<id>": [ { "op": "replace", "path": "/operationStatus", "value": "paused" } ] } }
```

Allowed `operationStatus` values: `enabled`, `paused`, `outOfService`.
CEplay never patches `outOfService` to anything else — out-of-service
games are treated as permanently offline by automation.

### Operator object

RPC actions (`/games/{id}/performAction`, `/kiosks/{id}/performAction`)
require an `operator` per the spec:

```json
{ "actionId": "reboot", "operator": { "employeeName": "Floor Lead", "employeeNumber": 42, "stationName": "CEplay Web" } }
```

If the request omits `operator`, CEplay synthesises one from the
authenticated admin user.

---

## Security

### Authentication & sessions

- bcrypt (cost 12) with automatic rehash if cost is bumped.
- Session ID regenerated on login (and on logout).
- Cookies are HttpOnly + SameSite=Strict, plus Secure when HTTPS is
  detected (forwarded proto-aware).
- 2-hour sliding-window timeout (`SESSION_LIFETIME`).
- Brute-force defences: progressive per-IP delay (1 / 3 / 5 s) plus a
  hard lockout at 10 failures in 15 minutes. The `password_verify` call
  always runs against a real bcrypt hash to avoid timing-based user
  enumeration.

### CSRF

- 256-bit per-session token, validated with `hash_equals()`.
- Required on every `POST` / `PUT` / `PATCH` / `DELETE` except login.

### Encryption at rest

- AES-256-CBC with HMAC-SHA256 (encrypt-then-MAC).
- Distinct encryption + MAC sub-keys derived from the master key.
- Backward-compatible with the legacy "no MAC" format (logs a warning).
- The master key comes from `PG_ENCRYPTION_KEY` (env) and falls back to
  `ENCRYPTION_KEY` defined in `config.php`.

### HTTP hardening

- Headers on every response: `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `X-Permitted-Cross-Domain-Policies: none`,
  `Permissions-Policy: geolocation=(), camera=(), microphone=()`,
  `Strict-Transport-Security: max-age=31536000; includeSubDomains`
  (only when HTTPS is detected),
  and a strict `Content-Security-Policy` allowing only `'self'`,
  `'unsafe-inline'` (for the `APP_CONFIG` bootstrap), Google Fonts, and
  the pinned Chart.js CDN URL.
- All SQL parameterised with `:p0, :p1, …` positional binding.
- `Validator` class checks lengths, types, enums, dates, times, URLs, IDs,
  and array elements before any input reaches business logic.
- `cron.php`, `cron_watchdog.php`, and `run_action.php` reject web SAPI.
- Static file serving is locked to `realpath()` inside `public/`.
- `install.php` self-locks once an admin account exists.
- Sensitive API errors are sanitised on the way out (URLs and file paths
  redacted) unless `PG_APP_DEBUG=true`.

### Production hardening checklist

1. Set `PG_ENCRYPTION_KEY` in the web server's environment.
2. Force HTTPS at the load balancer / reverse proxy.
3. Block direct access to `data/`, `install.php`, and `fresh_install.php`
   in your nginx/Apache config (or delete the latter two).
4. Confirm `data/` is `0770` owned by the web user and not symlinked
   into the document root.
5. Wire `/api/health` into your monitoring / alerting (Uptime Kuma,
   Statuscake, etc.). Alert on HTTP 503, missing watchdog heartbeat
   > 3 min, or `warnings[]` non-empty.
6. Ensure `PG_APP_DEBUG` is unset.
7. Make a daily SQLite backup (`sqlite3 data/pause_groups.db ".backup …"`).

---

## Operations

### Health checks

```
GET /api/health
{
  "status":   "ok" | "degraded",
  "database": true | false,
  "cron":     { "last_run": "...", "age_seconds": 12345, "healthy": true|false },
  "watchdog": { "last_run": "...", "age_seconds": 42,    "healthy": true|false },
  "warnings": [ ... ]   // only present when something looks insecure
}
```

HTTP 200 → all OK. HTTP 503 → at least one degraded indicator.

### Common log destinations

- `data/cron.log` — daily planner output.
- `data/watchdog.log` — per-minute watchdog cycle output.
- PHP error log (e.g. `/var/log/apache2/error.log`) — request-time
  exceptions, swallowed errors from background jobs.
- The action log table (`/api/logs`) — every state-changing event the
  admin team needs to audit.

### Purging

`Scheduler::purgeOldData()` runs nightly during the daily cron with the
retention windows from Settings. It removes:

- `action_log` rows older than `retention_action_log_days`.
- `scheduled_actions` rows whose `scheduled_date` is older than
  `retention_scheduled_actions_days` and which are no longer pending.
- `schedule_overrides` rows whose `end_datetime` is older than
  `retention_overrides_days`.
- `game_play_transactions` rows older than `retention_transactions_days`.

### Database backup

```bash
sqlite3 /var/www/ceplay/data/pause_groups.db ".backup '/backup/ceplay-$(date +%F).db'"
```

The backup is consistent (SQLite uses internal locking) so it works
without stopping CEplay.

### Resetting the bearer token

Saving any password through Settings clears `bearer_token` and
`token_fetched_at`. To force a full re-auth without changing credentials,
simply re-save the same password.

### Concurrency lock summary

| Script | Lock behaviour |
|--------|---------------|
| `cron.php` | Non-blocking — exits cleanly if another instance is running |
| `cron_watchdog.php` | 15-second blocking retry, then skips this cycle |
| `run_action.php` | 60-second blocking retry, then exits with an error log |
| `Scheduler::replanToday()` | 30-second blocking retry, then logs and skips |

All four use `data/.scheduler.lock` via `flock(LOCK_EX | LOCK_NB)`.

---

## Smoke testing checklist

Run before every deploy:

1. **PHP lint**

   ```bash
   find . -name '*.php' -not -path './data/*' -not -path './.git/*' \
        | xargs -L1 php -l
   ```

2. **JS lint** (optional, requires Node)

   ```bash
   for f in public/js/*.js; do node --check "$f"; done
   ```

3. **Installer**

   ```bash
   php install.php           # interactive
   php install.php --migrate # idempotent migrations
   ```

4. **Cron sanity**

   ```bash
   php cron.php           # should print a clean daily plan
   php cron_watchdog.php  # should write a heartbeat
   tail -n 50 data/cron.log data/watchdog.log
   ```

5. **UI smoke** (sign in as admin)

   - Dashboard renders without console errors.
   - Stats grid + Swipe Activity + Swipe by Category + Top games today
     all render (the latter two may say "no data" until the watchdog has
     polled for a couple of minutes).
   - Click **Sync Now** — last-synced timestamp updates.
   - Create a quick override that ends in 2 minutes; watch it execute and
     then auto-revert.
   - Pause one game from the Games page; confirm the action appears in
     the Action Log.
   - Look up a known card on the Cards page.
   - Deactivate / reactivate a non-self admin user.
   - Toggle the dark / light theme.

6. **Health probe**

   ```bash
   curl -fsS http://localhost:8080/api/health | jq
   ```

   `status` must be `"ok"` and `warnings` must be absent (or only contain
   the expected dev warnings).

---

## Known limitations

- **No automated tests.** Verification is the smoke checklist above plus
  manual UI testing.
- **Schedules cannot cross midnight.** Split a 23:00–01:00 window into
  two rows (23:00–23:59 + 00:00–01:00).
- **Single-server SQLite.** WAL allows readers and one writer; this is
  fine for one venue. For multi-site you'd need to migrate to PostgreSQL.
- **`at` is optional but recommended.** Without it, scheduled actions
  fire within 60 s of their target time via the watchdog.
- **Kiosk endpoints are best-effort.** If your tenant doesn't expose
  `/kiosks` or `/kiosks` PATCHes, CEplay logs and continues — kiosk
  controls disappear from the UI per the capabilities response.
- **Card module is read-only.** Adjustments, wipes, combines, and bulk
  issue all need a different audit-capture flow that hasn't been wired
  up yet.
- **No email/SMS alerting.** Plug `/api/health` into your existing
  monitor instead.
