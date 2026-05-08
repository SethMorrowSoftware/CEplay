# CEplay — Software Audit

**Date:** 2026-05-08
**Branch reviewed:** `claude/audit-and-update-docs-JZ3zL` (HEAD = `43c24e1`, merge of dashboard UI/UX refresh)
**Scope:** Full file-by-file review of the application. Reliability, security,
data-flow, and feature regressions noted. Replaces the 2026-03-21 audit,
which is preserved in git history.

---

## 1. Executive summary

CEplay is in good operational shape. Since the previous audit (2026-03-21)
the codebase has grown by roughly 60% with substantial functionality added:
- **Player card lookup** (`api/cards.php`, `public/js/cards.js`).
- **Live play-feed cache + analytics dashboard** with Chart.js
  (`api/games.php` — `transactions/*`, `analytics`; `public/js/games.js`).
- **Per-asset retry queue** with give-up semantics
  (`action_retries` table; `Scheduler::queueRetry()` /
  `processRetries()` / `hasGivenUp()`).
- **Tiered safety nets** on every authenticated API call
  (`Scheduler::enforceExpiredOverrides`,
  `executeMissedActions`, `enforceCurrentStates`).
- **Settings-driven operational tuning** (polling intervals, retention
  windows, retry caps, throttles).
- **Capabilities cache** (`api/capabilities.php`) so kiosks/cards UI
  doesn't re-probe on every page load.
- **Heartbeat-backed `/api/health` endpoint** for operator monitoring.
- **Hardened HTTP headers** — HSTS, CSP, Permissions-Policy now sent
  on every response from `index.php`.
- **Audit log enrichment** — dedicated `actor_user_id`,
  `actor_username`, and `ip_address` columns; CLI heartbeat tracking.
- **FCOS deploy automation** (`setup-fcos.sh`) auto-removes both
  `install.php` and `fresh_install.php` after first boot.

Three issues from the previous audit are now resolved:
1. AEAD-style integrity protection for stored credentials → done
   (`lib/crypto.php` encrypt-then-MAC with HMAC-SHA256, backward
   compatible).
2. Brute-force throttling beyond `sleep(1)` → done (progressive 1s/3s/5s
   delays + 10-attempt full lockout, `lib/auth.php`).
3. Unauthenticated installer hardening → done (`install.php` 403s once
   any admin exists; `fresh_install.php` refuses web execution when DB
   already exists; FCOS setup deletes both files).

One **new finding** stands out: **a brief role-based access-control
feature was merged then reverted**, and the post-revert state is _less_
restrictive than what the codebase looked like at that PR. Details in
§5.

---

## 2. Repository inventory

```
CEplay/
├── index.php                 SPA shell + API dispatcher + safety nets + /health
├── config.php                Constants (encryption key, DB path, timeouts)
├── cron.php                  Daily planner (00:05): sync, plan, queue, purge
├── cron_watchdog.php         Per-minute watchdog: missed actions, retries, tx poll
├── run_action.php            One-shot action runner invoked by `at` jobs
├── install.php               Guided first-run setup (CLI or web wizard)
├── fresh_install.php         Wipe-and-reinstall (CLI primary, web fail-closed)
├── setup-fcos.sh             Fedora CoreOS / Podman automated deploy
├── README.md                 ~1k-line user/operator manual
├── CLAUDE.md                 Development guide for AI assistants
├── DEPLOY-CEPLAY.md          Coexistence runbook (sub-path under existing nginx)
├── INSTALL-FCOS.md           FCOS step-by-step deployment runbook
├── api/                      11 PHP endpoint handlers (see §3.2)
├── lib/                      7 core libraries (see §3.1)
├── public/                   Vanilla JS SPA + CSS (see §3.3)
├── docs/                     This file + CenterEdge OpenAPI reference
└── data/                     Runtime: DB, locks, heartbeats, logs (gitignored)
```

`.gitignore` covers `data/`, editor cruft, and `vendor/` — no Composer
artifacts or `.env` references; the encryption key lives either in
`config.php` (set by `fresh_install.php`) or in the `PG_ENCRYPTION_KEY`
env var.

---

## 3. File-by-file review

### 3.1 Core libraries (`lib/`)

| File | LoC | Purpose | Notes |
|------|----:|---------|-------|
| `lib/db.php` | 501 | SQLite singleton + schema bootstrap + audit-log helper | WAL mode, 30 s busy timeout, FK on. Schema is idempotent (`CREATE TABLE IF NOT EXISTS` + try/catch `ALTER TABLE` migrations). `auditLog()` resolves the actor from `$_SESSION` automatically and captures the client IP. Drops obsolete tables (`attractions`, `maintenance_tickets`, `party_bookings`, `party_packages`, `announcements`) on every boot — safe but the `try/catch` hides any unexpected error. |
| `lib/auth.php` | 238 | Session lifecycle + bcrypt + login rate limiting | Session uses `SameSite=Strict`, `HttpOnly`, secure when behind HTTPS. `login()` runs a dummy `password_verify` on the disabled-user path so response timing doesn't leak existence. Progressive delays: 1 s (3-5 fails), 3 s (6-8), 5 s (9), full 429 lockout at 10 fails within 15 minutes. `clearLoginAttempts()` runs on success. **No role/permission logic** — every authenticated user has full admin rights. |
| `lib/csrf.php` | 42 | CSRF token generation + timing-safe validation | Tokens are 256-bit; expected as `X-CSRF-Token`. Validates with `hash_equals()`. Works because every state-changing endpoint runs through the dispatcher in `index.php`, which checks CSRF before routing. |
| `lib/crypto.php` | 135 | Authenticated AES-256-CBC for stored credentials | Encrypt-then-MAC: `base64(hmac‖iv‖ciphertext)`. Sub-keys for `enc` and `mac` derived via `hash_hmac('sha256', purpose, master)`. Legacy (pre-HMAC) decrypt fallback emits an `error_log` notice prompting operators to re-save credentials. **Note:** the sub-key derivation isn't formal HKDF (no salt or info) but is acceptable for a single rotating master key. |
| `lib/validator.php` | 198 | Field-level input validation | Throws `RuntimeException` (mapped to 422 by the dispatcher). Helpers: `requireString`, `optionalString`, `requireInt`, `requireTime`, `requireDatetime`, `requireDate`, `requireDayOfWeek`, `requireEnum`, `requireUrl`, `requireIntArray`, `optionalStringArray`, `pagination`. No surprises. |
| `lib/centeredge_client.php` | 823 | CenterEdge API client | SHA-1 login flow with token cache (`TOKEN_MAX_AGE` = 30 min). Pagination helpers with a 1000-page safety cap. New since prior audit: kiosk endpoints, single-game lookup, RPC `/performAction`, `/cards`, `/cards/.../transactions`, `/cards/.../pin`, `/games/transactions` polling with checkpoint, `getCategoriesCached()` (1-hour cache with stale-on-failure fallback), and `pruneCacheViaStaging()` (TEMP-table + 200-row chunks) to avoid SQLite's bound-variable cap when a venue exceeds ~999 games/kiosks. Retry policy: 3 attempts with exponential 2/4/8s backoff for 5xx/408/429/network; 401 triggers a single re-authenticate. |
| `lib/scheduler.php` | 1719 | Core scheduling engine | The biggest module. Day-planning logic, override conflict resolution, missed-action detection (only the latest per group is executed; earlier ones marked `executed=3` "superseded"), retry queue with give-up markers, manual-override priority gate, kiosk-aware `executeStateChange()`, atomic heartbeat writer (tmp+rename), `purgeOldData()` with TZ-correct cutoffs (UTC for `datetime('now')` columns, local for operator-entered datetimes). All public methods set/restore the venue timezone via `setTimezone()/restoreTimezone()` to avoid leaking it into the rest of the request. |

### 3.2 API handlers (`api/`)

| File | LoC | Endpoints | Notes |
|------|----:|-----------|-------|
| `api/auth.php` | 103 | `POST /auth/login`, `POST /auth/logout`, `GET /auth/status` | Login is the only endpoint exempt from CSRF. Uses `getClientIp()` which trusts `X-Forwarded-For` only when `REMOTE_ADDR` is loopback. Session is regenerated on logout *before* destruction so a stale cookie can't reuse cached data. |
| `api/users.php` | 140 | `GET/POST /users`, `PUT /users/{id}` | **CRUD restricted only to "any authenticated user"** — see §5.1. Bcrypt cost 12, minimum 8-char passwords, prevents self-deactivation. |
| `api/settings.php` | 208 | `GET /settings`, `PUT /settings`, `POST /settings/test` | Test connection accepts ad-hoc credential overrides without persisting them, via `applyCredentialsOverride()`. Persisted credentials are AES+HMAC encrypted; password+API-key responses are masked as `********`. Tunables: tx poll interval, Tier 2 throttle, state-sync staleness, six UI poll intervals, four retention periods, retry-max-attempts, dashboard-top-games-limit. |
| `api/groups.php` | 602 | `GET/POST /groups`, `GET/PUT/DELETE /groups/{id}`, `POST /groups/{id}/{pause\|unpause\|enforce\|clear-manual-override}` | Listing enriches each group with combined game+kiosk effective state, next transition, active override, and manual-override metadata. Names for kiosks/games/categories are resolved through cached lookups; category cache TTL is 1 hour. |
| `api/games.php` | 901 | Cached `/games`, `/games/{id}` (live), `/games/categories`, `PATCH /games`, `POST /games/sync`, `POST /games/{id}/action`, full `/games/transactions/*` family, `/games/analytics` | The largest endpoint module. The `analytics` handler returns totals, status breakdown, three top-N leaderboards, and a gap-filled time-series with appropriate bucket size (hour/day/month). Cutoffs use the venue's local-offset ISO-8601 string so they compare correctly against `transaction_time`, which CenterEdge emits in the same format. The `by-category` handler uses SQLite `json_each()` on the cached `categories` array and resolves names from the `categories_cache` config blob. |
| `api/kiosks.php` | 396 | `GET /kiosks`, `GET /kiosks/{id}` (live), `POST /kiosks/sync`, `POST /kiosks/{id}/{pause\|unpause\|out-of-service\|action}`, `PATCH /kiosks` | Per the OpenAPI spec, kiosks reporting no `operationStatus` are excluded from pause control everywhere. Manual mutations clear any pending retry first (so a fresh intent gets a clean retry window) and enqueue a new retry on per-asset PATCH failure. |
| `api/cards.php` | 134 | `GET /cards/{n}`, `GET /cards/{n}/transactions`, `GET /cards/{n}/pin?validate=` | Read-only proxy. Card numbers are validated as 1-32 alphanumerics. **Every read is audit-logged with the looked-up card number** because card lookups are a known accountability requirement at the venue. Distinguishes "card not found" from "PIN not found" (404 → friendly `code` field). |
| `api/schedules.php` | 237 | `GET /schedules`, `POST /schedules` (bulk-day), `PUT /schedules/{id}`, `DELETE /schedules/{id}` | Bulk creation accepts a `days_of_week[]` array. Forbids overnight-crossing (operator must create two windows). Mutating any schedule that touches today triggers `Scheduler::replanToday()` and `Scheduler::enforceGroupState()`. |
| `api/overrides.php` | 264 | `GET /overrides`, `POST /overrides`, `DELETE /overrides/{id}` | Lists active/upcoming/expired (last 30 days). Overrides are stored as venue-local "YYYY-MM-DD HH:MM" because that's what `<input type="datetime-local">` posts; `overrideToApi()` converts to ISO-8601 with the venue's offset on the way out so the SPA's `new Date()` interprets them correctly. POST executes immediately if the override is currently active, then replans, then verifies the end-time action was queued. |
| `api/logs.php` | 191 | `GET /logs`, `GET /logs/options` | Source/action/group/success/actor/IP/date filters, all parameterised. The whitelists `LOG_FILTER_SOURCES` / `LOG_FILTER_ACTIONS` are exposed via `/options` so the SPA's filter dropdowns stay in sync without hard-coding. Falls back to legacy `details.actor_user_id` for entries written before the schema migration. |
| `api/capabilities.php` | 87 | `GET /capabilities` | One-hour server-side cache with stale-on-failure fallback. Cleanly degrades when CenterEdge briefly disappears. |

### 3.3 Frontend (`public/`)

Vanilla JavaScript with no build step. All scripts are loaded `defer`
from the SPA shell in `index.php`. Each page module self-registers via
`App.registerRoute()`.

| File | LoC | Route(s) | Purpose |
|------|----:|----------|---------|
| `js/api.js` | 100 | n/a | HTTP wrapper. Injects `X-CSRF-Token`, retries 502/503/504 with exponential backoff, redirects to `#/login` on 401. Custom `ApiError` class. |
| `js/app.js` | 667 | n/a | Hash-based router, modal/toast helpers, theme toggle (persisted in `localStorage` as `pause-groups-theme`), `App.el()` DOM builder, visibility-aware polling helpers, shared formatters. Reads operator-configured polling values from inline `window.APP_CONFIG`. |
| `js/login.js` | 93 | `#/login` | Username/password form with timeout protection. |
| `js/dashboard.js` | 1413 | `#/dashboard` | Operations command center. Adaptive polling: 30 s default, 10 s when overrides active, 5 s when a transition is < 2 min away. Schedules precise timers for override expiry. Shows security warnings from `/health`. Optimistic UI for pause/unpause. Responsive games table + grid with search/sort/filter and pagination. |
| `js/games.js` | 1347 | `#/games` | Analytics dashboard. KPI grid, time-series chart, status doughnut, three leaderboards, live play feed, searchable directory with per-row controls. Loads Chart.js from a pinned CDN URL with a graceful degrade path if the script fails. No money amounts shown. |
| `js/cards.js` | 444 | `#/cards` | Card lookup + transaction history pagination + PIN probe/validate UI. Read-only. |
| `js/groups.js` | 504 | `#/groups`, `#/groups/new`, `#/groups/:id` | List + member editor. The game picker has search, status filter, pagination, and bulk select-visible — necessary because a real venue has hundreds of games. |
| `js/kiosks.js` | 218 | `#/kiosks` | List + per-kiosk pause/unpause/OOS + RPC actions. Probes `/capabilities` so the page hides controls cleanly when the upstream system doesn't support kiosk operation status. |
| `js/schedules.js` | 247 | `#/schedules` | Weekly grid + per-row CRUD with day-of-week multi-select for bulk create. |
| `js/overrides.js` | 228 | `#/overrides` | Active / upcoming / expired tabs. 15 s visibility-aware polling when active overrides exist. Schedules a precise enforce call at expiry. |
| `js/logs.js` | 365 | `#/logs` | Paginated audit log with filters populated from `/logs/options`. |
| `js/settings.js` | 675 | `#/settings` | All operator-tunable settings UI plus admin user CRUD. |
| `css/style.css` | 3235 | n/a | Single stylesheet, dark/light theme via CSS custom properties. |

No `import`/`export` anywhere; every module is wrapped in an IIFE. The
only third-party runtime asset is Chart.js, pinned to `4.4.7` and
allowed by the CSP `script-src` for `https://cdn.jsdelivr.net`.

### 3.4 Operational scripts

- **`cron.php`** — daily at 00:05. Steps: sync games, sync kiosks
  (best-effort), refresh categories cache, run any missed actions,
  plan today, queue `at` jobs, purge old data using operator-tuned
  retention windows, rotate `cron.log` / `watchdog.log` to ~256 KB
  when they exceed 512 KB, write `data/.heartbeat_cron`. Acquires
  `LOCK_FILE` non-blocking and exits cleanly if contended.
- **`cron_watchdog.php`** — every minute. Runs missed-action catch-up,
  state enforcement, `at`-job re-queueing, retry queue, and
  rate-limited play-feed polling (`tx_poll_interval_seconds`, default
  60 s, configurable up to 15 min). Acquires `LOCK_FILE` with 15 s
  blocking retry so it doesn't silently skip cycles.
- **`run_action.php`** — invoked by an `at` job with `--id <id>`.
  Acquires `LOCK_FILE` with up to 60 s blocking retry, executes the
  action, then opportunistically runs `executeMissedActions()` to
  catch up on any earlier missed actions during the same cycle.
- **`index.php` safety nets** — Tier 1 (cache-only expired-override
  enforcement, every authenticated request) and Tier 2 (full missed
  action + state enforcement, throttled by `tier2_throttle_seconds`,
  default 60 s, governed by an exclusive `flock` on
  `data/.last_missed_check`). Both wrap the system timezone so the
  scheduler doesn't pollute it for the rest of the request.

### 3.5 Installers and deploy

- **`install.php`** — guided CLI/web setup. Checks PHP extensions
  (sqlite3, openssl, mbstring, curl), warns when `at`/`atrm` are
  missing, creates schema, prompts for the first admin user, optional
  CenterEdge credentials, and timezone. **Web mode 403s as soon as
  any admin user exists** — re-running setup over the network is
  impossible.
- **`fresh_install.php`** — wipe-and-reinitialise script. **Web mode
  is fail-closed**: if the SQLite DB exists at all, it refuses to
  proceed. CLI mode prints a randomly-generated 64-hex encryption
  key, writes it into `config.php`, recreates the schema, and prints
  a randomly-generated initial admin password to stdout exactly once.
- **`setup-fcos.sh`** — Fedora CoreOS automated deploy. Generates
  a random encryption key into `/var/persist/pause-groups/.env`
  (mode 600), pulls `php:8.3-fpm`, installs systemd services for
  PHP-FPM + watchdog (per-minute) + daily planner (00:05), and
  **deletes both `install.php` and `fresh_install.php` from the
  install location after first run** (commit `87047d3`). Re-runnable;
  preserves DB on subsequent runs. Does not auto-edit Nginx (operators
  add the snippet themselves to coexist with Grafana / Tailscale /
  Certbot).

---

## 4. Data model snapshot

Schema is initialised on first DB access. Tables present in this
revision (in addition to the `admin_users`, `api_config`, `pause_groups`,
`pause_group_categories`, `pause_group_games`, `schedules`,
`schedule_overrides`, `action_log`, `scheduled_actions`,
`game_state_cache`, and `login_attempts` tables that already existed at
the time of the prior audit):

| Table | Added | Why |
|-------|-------|-----|
| `pause_group_kiosks` | Late Apr 2026 | Pause groups now mix games and kiosks. Kiosk patches are best-effort and never roll back game patches. |
| `kiosk_state_cache` | Late Apr 2026 | Mirror of CenterEdge `/kiosks` so dashboards don't re-probe upstream. Empty `operation_status` means "unknown" per spec — pause control is hidden for those. |
| `action_retries` | Apr 2026 | Per-asset retry queue for in-use / transiently failed PATCHes. UNIQUE on `(asset_type, asset_id)` + UPSERT semantics. `gave_up_at` was added in commit `e803514` so the watchdog actually stops retrying after `max_attempts`. |
| `game_play_transactions` | Apr 2026 | Local cache of `/games/transactions` for the live feed and analytics. PK is `(feed_name, transaction_id)`; checkpoint stored in `api_config["game_tx_last_id_<feedName>"]`. Default retention is 395 days (configurable 30-3650). |

Migrations: every table includes `CREATE TABLE IF NOT EXISTS` plus
defensive `try/catch ALTER TABLE` for new columns. Recent additions
include `pause_groups.manual_override_action`/`_at`,
`action_log.actor_user_id` / `actor_username` / `ip_address`, and
`action_retries.gave_up_at`. Obsolete tables from the (since-removed)
parties / maintenance / announcements modules are dropped on every boot.

---

## 5. Outstanding findings (prioritised)

### 5.1 Reverted role-based access control (Medium / behavioural regression — NEW)

**Issue.** Pull request #7 (commit `68f0612`, *Add tiered user roles
(admin/tech/manager) with idempotent migration*) added a `role` column
to `admin_users` and an `Auth::requireRole()` gate. The very next
commit, `f5548bc` ("Add files via upload"), removed the `role` column
and every `Auth::requireRole()` check in a single drop-in, and that
state has shipped through every release since.

**Impact.** As a result, **every authenticated user has full admin
rights**, including:
- Creating, deactivating, and changing the password of any other admin
  account (`api/users.php`).
- Editing CenterEdge credentials and re-running `/settings/test`.
- Deleting pause groups, schedules, and overrides.
- Looking up arbitrary cards (which is audit-logged but not gated).

This isn't a vulnerability per se — the surface is still
authentication-gated — but it's a documented intent that has been
quietly reversed. The `users.php` doc-comment that said "Restricted
to users with the 'admin' role" was deleted at the same time.

**Recommendation.** Either re-introduce roles (admin / tech / manager,
or a simpler admin / staff split), or remove the user-management UI
from non-primary accounts by some other mechanism, or document
explicitly in `README.md` that all accounts are full administrators.
Pick one of those three intentionally rather than leaving the
implementation in its current ambiguous state.

### 5.2 Lock-file granularity (Low — pre-existing)

**Issue.** `cron.php`, `cron_watchdog.php`, and `run_action.php` all
share `data/.scheduler.lock`. When `cron_watchdog.php` waits 15 s for
the lock and a long-running `run_action.php` is in flight, the
watchdog cycle is skipped. Tier 2 enforcement (throttled by 60 s)
plus the next watchdog tick make this benign, but it's worth
documenting.

**Recommendation.** None right now — separating per-script locks
would be a larger refactor than the impact warrants.

### 5.3 Legacy non-HMAC decrypt path (Low — pre-existing)

**Issue.** `Crypto::decryptLegacy()` is still called when stored data
lacks the HMAC prefix. Operators are nudged via `error_log` to
re-save credentials, but there is no UI signal.

**Recommendation.** Add a Settings-page banner that lights up when
`api_config` rows still match the legacy length signature, prompting
the operator to re-save once. Trivial to implement.

### 5.4 Capabilities probe assumes 1-hour staleness is acceptable (Low)

`api/capabilities.php` falls back to a stale cache on upstream
failure. That's right for `kiosks.html` but means the operator might
not learn of capability changes (e.g. CenterEdge upgrade enabling
`kiosks.operationStatus` for the first time) for up to an hour. Not
a problem in practice because such upgrades are rare; flagging only
for completeness.

### 5.5 No automated tests (Low — pre-existing)

Only `php -l` syntax checks run today. Manual smoke checklist in
`README.md` is the canonical pre-release verification.

**Recommendation.** A small PHPUnit-less harness against the
scheduler's pure functions (`planDay`, `enforceCurrentStates` with a
mocked CenterEdgeClient, retry queue state machine) would catch the
overwhelming majority of regressions without adding a build step.

---

## 6. Resolved since the previous audit

| Prior finding | Status | Where |
|--------------|--------|-------|
| AEAD-style integrity protection for stored credentials | **Resolved** | `lib/crypto.php` (encrypt-then-MAC, HMAC-SHA256) |
| Login brute-force protection beyond fixed `sleep(1)` | **Resolved** | `lib/auth.php` progressive delay tiers + 10-attempt lockout + `Retry-After: 900` |
| Unauthenticated installer hardening | **Resolved** | `install.php` 403 once admin exists; `fresh_install.php` web fail-closed; `setup-fcos.sh` deletes both files after first run |
| Stronger HTTP response headers (CSP, HSTS, Permissions-Policy) | **Resolved** | `index.php` lines 41-67 |
| Runbook docs (backup/restore, key rotation, incident response) | **Resolved** | §8 of this document and `README.md` "Operations" section |

---

## 7. Reliability notes

- **Heartbeats.** `data/.heartbeat_cron` (≤ 25 h) and
  `data/.heartbeat_watchdog` (≤ 3 min) are written atomically via
  `tmp+rename`, surfaced through `GET /api/health`, and shown by
  the dashboard when stale.
- **Retry queue.** Every per-asset PATCH failure enqueues an
  `action_retries` row; the watchdog re-attempts once per cycle up to
  `retry_max_attempts` (default 10, configurable 1-50). When the cap
  hits, `gave_up_at` is set and subsequent cycles skip the row until
  a fresh intent (manual button, schedule transition firing, or
  override boundary) clears the marker.
- **Missed-action collapsing.** When several missed transitions for
  the same group are catching up, only the latest is executed; the
  rest are marked `executed=3` ("superseded") with no API calls.
- **Cache pruning.** `pruneCacheViaStaging()` writes seen IDs to a
  TEMP table in 200-row chunks before the `DELETE … NOT IN`. This
  avoids SQLite's bound-variable cap (default 999 in some builds)
  for venues with > 999 games or kiosks.
- **Timezone handling.** `Scheduler::setTimezone()` /
  `restoreTimezone()` save and restore `date_default_timezone`
  around every public method. `purgeOldData()` mixes UTC cutoffs
  (for `datetime('now')` columns) and local cutoffs (for operator-
  entered datetimes) intentionally.

---

## 8. Operator runbooks

### 8.1 Backup (recommended: daily, automated)

```bash
# Hot backup (safe while app is running)
sqlite3 /path/to/data/pause_groups.db \
    ".backup '/path/to/backups/pause_groups_$(date +%Y%m%d_%H%M%S).db'"
# or
sqlite3 /path/to/data/pause_groups.db \
    "VACUUM INTO '/path/to/backups/pause_groups_$(date +%Y%m%d).db';"
```

```cron
30 2 * * * sqlite3 /path/to/data/pause_groups.db ".backup '/path/to/backups/pause_groups_$(date +\%Y\%m\%d).db'" \
    && find /path/to/backups -name 'pause_groups_*.db' -mtime +30 -delete
```

What to preserve:
- `data/pause_groups.db` — all application state.
- `config.php` (or `PG_ENCRYPTION_KEY` env var) — required to decrypt
  CenterEdge credentials at rest.
- Cron entries / systemd timer units.

**Never `cp` a live SQLite database.** Use `.backup` or `VACUUM INTO`
so the WAL is consistently checkpointed.

### 8.2 Restore

```bash
sudo systemctl stop php-fpm                  # or apache2 / nginx
cp /path/to/backups/pause_groups_YYYYMMDD.db /path/to/data/pause_groups.db
chown www-data:www-data /path/to/data/pause_groups.db
chmod 660 /path/to/data/pause_groups.db
sudo systemctl start php-fpm
sudo -u www-data php /path/to/cron.php       # re-plan today
```

### 8.3 Encryption key rotation

1. Backup the database.
2. Re-save the API credentials via Settings (this keeps a plaintext
   copy in memory for the rotation).
3. Replace `PG_ENCRYPTION_KEY` (env or `config.php`).
4. Re-save the API credentials again — they will be re-encrypted with
   the new key.
5. Verify by clicking *Test connection* in Settings.

### 8.4 Missing cron heartbeat

If `/api/health` reports `cron.healthy: false` or
`watchdog.healthy: false`:

```bash
crontab -l | grep pause                                 # confirm scheduling
tail -50 /path/to/data/cron.log
tail -50 /path/to/data/watchdog.log
ls -la /path/to/data/.scheduler.lock                   # check for stale lock
sudo -u www-data php /path/to/cron.php                 # force replan
rm /path/to/data/.scheduler.lock                       # only if confirmed stale
```

### 8.5 Production hardening checklist

- [ ] `install.php` and `fresh_install.php` removed (or 403'd at the
      web server). On FCOS this is automatic.
- [ ] `PG_ENCRYPTION_KEY` set to a unique 64-hex value, rotated via
      §8.3 on a documented cadence.
- [ ] HTTPS enforced end-to-end. CSP/HSTS/Permissions-Policy already
      sent by the app; verify upstream proxy doesn't strip them.
- [ ] `data/` writable only by the web user, not group-writable to
      world. WAL/SHM journals share the same permissions.
- [ ] Cron + watchdog timer / crontab installed; logs monitored.
- [ ] Backup job in place and tested against a non-production copy.
- [ ] `APP_DEBUG` unset (`PG_APP_DEBUG=false`).
- [ ] Retention policy confirmed in *Settings → Data Retention*. The
      defaults (90 d action log, 30 d scheduled actions, 90 d
      overrides, 395 d transactions) are fine for most venues but
      should be revisited if the DB grows unexpectedly.
- [ ] Decide and document the role/permission story (§5.1).
