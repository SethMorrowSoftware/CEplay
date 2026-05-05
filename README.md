# Castle Fun Center - Pause Group Automation

A self-hosted automation tool for the CenterEdge Card System: pause groups,
schedule recurring active-hours, run one-off overrides, and now also pause
kiosks alongside games. Built with PHP and vanilla JavaScript — no frameworks,
no external dependencies.

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

- **`cron_watchdog.php`** (every minute) — executes missed actions, enforces desired game states, re-queues broken `at` jobs, writes a watchdog heartbeat.
- **`cron.php`** (daily at 00:05) — syncs the game list from CenterEdge, plans all actions for the day, queues `at` jobs, purges old data, rotates log files, writes a cron heartbeat.

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

  api/                     # API endpoint handlers (all require auth except /api/health)
    auth.php               #   Login, logout, session status
    settings.php           #   CenterEdge API config + timezone management
    games.php              #   Game list, categories, sync
    groups.php             #   Pause group CRUD, manual pause/unpause, state enforcement
    schedules.php          #   Recurring schedule CRUD (bulk creation supported)
    overrides.php          #   Temporary override CRUD with immediate execution
    logs.php               #   Paginated, filterable action log
    users.php              #   Admin user management

  lib/                     # Core libraries
    db.php                 #   SQLite singleton, schema initialization, query helpers
    auth.php               #   Session management (HttpOnly, SameSite=Strict, brute-force delay)
    csrf.php               #   CSRF token generation + timing-safe validation
    crypto.php             #   AES-256-CBC encrypt-then-MAC (HMAC-SHA256), backward-compatible
    validator.php          #   Input validation (strings, ints, dates, times, enums, arrays, URLs, pagination)
    centeredge_client.php  #   CenterEdge API client (SHA-1 auth, token caching, pagination, retry)
    scheduler.php          #   Scheduling engine (planning, execution, enforcement, purge)

  public/                  # Frontend assets
    css/style.css          #   Stylesheet (dark and light themes)
    js/
      api.js               #   HTTP client with CSRF header injection
      app.js               #   SPA router and navigation (hash-based)
      login.js             #   Login form
      dashboard.js         #   Dashboard with live group states, auto-refresh
      groups.js            #   Pause group management UI
      schedules.js         #   Schedule editor
      overrides.js         #   Override management
      logs.js              #   Action log viewer with filters
      settings.js          #   CenterEdge API config + admin user management

  data/                    # Runtime data (created by installer)
    pause_groups.db        #   SQLite database (+ WAL/SHM journal files)
    .scheduler.lock        #   Concurrency lock file
    .heartbeat_cron        #   Cron heartbeat (ISO 8601 timestamp)
    .heartbeat_watchdog    #   Watchdog heartbeat (ISO 8601 timestamp)
    .last_missed_check     #   Throttle file for API-level safety net (mtime-based, 15s cooldown)
    cron.log               #   Daily cron output (auto-rotated at 500KB)
    watchdog.log           #   Watchdog output (auto-rotated at 500KB)

  docs/                    # Internal documentation (not user-facing)
    AUDIT.md               #   Security audit notes and findings
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
5. **Data purge** — removes action log entries older than 90 days, executed scheduled actions older than 30 days, and expired overrides older than 90 days.
6. **Log rotation** — rotates `cron.log` and `watchdog.log` when they exceed 500KB (keeps last 256KB).
7. **Heartbeat** — writes an ISO 8601 timestamp to `.heartbeat_cron`.

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
| `admin_users` | Admin accounts (username, bcrypt hash, display name, active flag) |
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

### Users

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/users` | GET | List all admin users (id, username, display name, active flag, timestamps). |
| `/api/users` | POST | Create a new admin user (username, display name, password). |
| `/api/users/{id}` | PUT | Update user (display name, password, active flag). |

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

## Security

### Authentication & Sessions

- Passwords hashed with **bcrypt** (cost 12). Automatic rehash on login if cost parameter changes.
- Session cookies: **HttpOnly**, **SameSite=Strict**, **Secure** (when HTTPS detected). Strict session mode enabled.
- 2-hour session timeout with sliding window (activity refreshes the timer).
- Session ID regenerated on login to prevent session fixation.
- 1-second delay on failed login attempts (brute-force mitigation).

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
- CLI-only guards on `cron.php`, `cron_watchdog.php`, and `run_action.php` prevent web execution.
- Static file serving restricted to the `public/` directory with path traversal protection.
- `install.php` web mode locks itself after the first admin user is created.

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

Run the installer, configure CenterEdge API credentials through the Settings page, and trigger a game sync. The application uses hash-based routing (`#/dashboard`, `#/groups`, `#/schedules`, `#/overrides`, `#/logs`, `#/settings`).

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
4. **Party module edge cases**
   - Confirm booking create/update rejects `end_time <= start_time`.
   - Confirm `/api/parties?status=...` rejects unknown status values.
   - Confirm `/api/parties/calendar?month=YYYY-MM` rejects invalid months (for example `2026-13`).
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
