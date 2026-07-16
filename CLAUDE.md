# Castle Fun Center - Pause Group Automation

## Project Overview
Self-hosted, framework-free pause-group automation for Castle Fun Center (arcade/entertainment venue). Automates pausing/unpausing of CenterEdge games AND kiosks via recurring time windows, overrides, manual actions, and multi-tier enforcement. Pause groups can mix games and kiosks; kiosks also have a standalone management page.

## Architecture
- **Backend:** Pure PHP 7.4+ (no Composer, no frameworks). SQLite database with WAL mode.
- **Frontend:** Vanilla JavaScript SPA with hash-based routing. No build step, no npm.
- **External API:** CenterEdge Card System API (REST, SHA-1 auth, bearer token caching).

## Key Files
- `index.php` — Main router: SPA shell, API dispatch, safety nets (Tier 1/2 enforcement)
- `config.php` — Constants: encryption key, DB path, session lifetime, API timeouts
- `cron.php` — Daily cron (00:05): game sync, plan day, queue `at` jobs, nightly DB backup (`data/backups/`, VACUUM INTO, keep 14), rollup, purge old data, one-time MSSQL backfills (guest history + per-game play history)
- `cron_watchdog.php` — Per-minute watchdog: missed actions, state enforcement, re-queue
- `run_action.php` — Single-action executor invoked by `at` jobs
- `backfill_card_activity.php` — OPTIONAL manual runner (thin wrapper over `Scheduler::backfillCardActivityFromMssql()`). The nightly `cron.php` runs this backfill **automatically, once** (guarded by config flag `card_activity_backfill_done`, lock-free after the main plan) as soon as it runs with MSSQL configured — no CLI needed. It seeds the guest ledger (`card_activity`) from MSSQL `PlayerCardTrans` (MIN/MAX `TransDateTime` per card) so "new vs returning" reaches back ~2 decades instead of only the 30-day feed. Batched by year; idempotent (reuses the nightly rollup's monotonic UPSERT — only widens); venue server only
- `run_backfills.php` — Runs whichever one-time MSSQL backfills are still pending (guest ledger + per-game play history) on demand via `Scheduler::runPendingBackfills()` — the single home for the flag-guard logic, shared with `cron.php`. `update.sh` invokes it after a deploy (best-effort, using the pdo_dblib overlay image) so the deep history appears immediately instead of at the next nightly cron; also runnable by hand. Idempotent/flag-guarded, so running early just means cron finds it done
- `lib/scheduler.php` — Core scheduling engine (plan, execute, enforce, resolve conflicts)
- `lib/centeredge_client.php` — CenterEdge API client (auth, games, kiosks, capabilities, pagination, retry)
- `lib/db.php` — SQLite singleton, schema init, query helpers (`:p0` positional params)
- `lib/auth.php` — Session management (bcrypt, HttpOnly, SameSite, 2h timeout, rate limiting)
- `lib/csrf.php` — CSRF token generation + timing-safe validation
- `lib/crypto.php` — AES-256-CBC encrypt-then-MAC (HMAC-SHA256, backward-compatible)
- `lib/validator.php` — Input validation (strings, ints, dates, times, enums, arrays)

## Directory Layout
```
api/          — API endpoint handlers (auth, settings, games, cards, groups, reader_groups, kiosks, schedules, overrides, analytics, labor, explorer, logs, users, capabilities)
lib/          — 7 core libraries
public/js/    — Vanilla JS modules (api, app, login, dashboard, games, cards, groups, kiosks, schedules, overrides, analytics, performance, readers, logs, settings)
public/css/   — Dark/light theme stylesheet
data/         — Runtime: SQLite DB, locks, heartbeats, logs (gitignored)
docs/         — Internal docs: security audit, CenterEdge API reference (HTML + OpenAPI YAML)
```

## Development Notes

### Database
- SQLite with WAL mode, foreign keys enabled, 30s busy timeout
- Parameterized queries use positional `:p0, :p1, ...` placeholders
- Schema auto-initializes on first DB access (CREATE TABLE IF NOT EXISTS)
- Migrations via ALTER TABLE in try/catch for backward compat

### Reporting & Analytics
- Raw play feed (`game_play_transactions`) is a short rolling window (30 days)
  for the live feed, per-game drill-downs, and hourly reporting.
- `Scheduler::rollupDailyStats()` (run nightly by `cron.php` BEFORE the purge)
  aggregates the raw feed into the permanent per-game, per-day `game_daily_stats`
  table, so month/year performance history survives indefinitely. CenterEdge has
  no reporting API — all aggregation is done locally. History from BEFORE the app
  started is one-time backfilled from the MSSQL `PlayerCardTrans` ledger
  (`Scheduler::backfillGameStatsFromMssql()`, run automatically by `cron.php`,
  flag `game_stats_backfill_done`): plays/value/unique-cards per game/day/hour,
  mapped `rdrkey`→`game_id` via `ReaderDevices`. Tickets/cash/time-plays stay 0
  on backfilled rows (no per-game source — every ticket credit has `rdrkey` 0),
  and only days BEFORE the live rollup's coverage are written, so nothing
  double-counts (expect a small seam at that boundary between the two sources).
- Reporting endpoints (`GET /api/analytics/games`, `GET /api/analytics/game`)
  stitch the rollup (older days) with the raw feed (recent days) at a split
  point safely inside raw retention, so totals are correct AND live. Same
  `analytics` role gate + cash/revenue scrub the Analytics page uses (tech sees
  plays/tickets, never dollars). Powers the Performance page
  (Day/Week/Month/Year/Custom, searchable, with prior-period comparison).
- The same nightly pass also writes `game_hourly_stats` (per-game, per-local-
  hour; ~400-day retention) so hour-of-day history outlives the raw feed.
- The Analytics overview and both reader-group endpoints accept
  `exclude_time_plays=1` (a UI toggle on those pages). The overview filters
  whole transactions (exact — excluded plays' tickets/points/payments drop
  too); the reader endpoints subtract play COUNTS only, keeping value fields
  whole so the raw/rollup stitch never disagrees with itself. Day-grain
  splits rely on `game_daily_stats.time_plays`, tracked since the
  `time_plays_daily_since` config stamp (older rollup rows can't be split).
- Go-Kart Labor (`#/labor`, `/api/labor/*`, `lib/mssql_client.php`) compares
  go-kart sales vs staff wages by Day/Week/Month/Year/Custom range (same
  window params + semantics as Performance via `perfResolveWindow`; Year
  renders month rows client-side). Sales = the POS's own "Go Kart Readers"
  division dollars (Sales, DivNo 808 — time passes never post money there);
  labor = the DB-computed wage total per day (the proven DATEDIFF
  expression — PayRate × seconds, unclosed punch accrues only when opened
  today — GROUP BY clock-in day). ALL dollar figures come live from these
  two admin-editable range queries (required `:from`/`:to` placeholders,
  single-SELECT guarded via MssqlClient::assertReadOnly; the legacy
  `dates=` param still works — each requested day is fetched alone, so
  year-over-year date lists never scan the span between them). Hour-of-day
  panels: Swipes by the hour (REAL counts from the app's own reader feed,
  readerHourlyRows stitch, coverage-aware) AND "Money in vs wages out, by the
  hour" + a weekday×hour heatmap (Money/Wages/Rate toggle, numbers in cells).
  The hourly money is REAL, not estimated — a genuine hourly source turned up:
  `PlayerCardTrans` TransType 1 / DivNo 808 carries every kart swipe's true
  clock time + dollars (`labor_hourly_sales_range_sql`), and wages-per-hour
  split each punch's PayRate across the wall-clock hours actually worked
  (`labor_punches_range_sql` + `laborPunchWageHours`; unclosed past-day punch =
  zero, today's accrues to now — same conventions as the daily query). Both are
  admin-editable range queries; Test reconciles the hourly ledger total against
  the daily DivNo-808 posting. (An earlier ESTIMATED hourly panel was removed;
  this one replaced it with ledger data.) Optional
  estimate mode (`labor_add_ride_value`, default OFF) adds paid rides ×
  per-track price to sales instead. Test connection runs both live queries
  and prints a fingerprint (server, DB, freshness, category/division dumps)
  for diagnosing data questions. Connection settings live
  encrypted in api_config. Driver detection tries PDO sqlsrv → dblib →
  odbc; the page reports what's installed. View gate: analytics +
  view_revenue (nav key view_revenue); config gate: settings. The
  sandbox/test env has no MSSQL driver — the live connection test happens
  on the venue server via the page's Test connection button.
- Card Loads (`#/cardloads`, `/api/cardloads/*`, `api/cardloads.php`) reports
  the money guests ADD to their cards, by Day/Week/Month/Year/Custom (same
  `perfResolveWindow` window model as Performance/Labor), plus a money-loaded-
  by-hour curve and a day-of-week × hour heatmap (per-occurrence averages, for
  staffing). This venue DEFERS card value (`ApplicationInfo.DeferValuePlayerCards
  = 1`), so a load is stored value — NOT a POS sale — and never appears in the
  `Sales` table; it lives only in the card ledger. Source: MSSQL
  `PlayerCardTrans` TransType 3 ("add value"); `DollarAmount` = real dollars
  paid, `TransDateTime` = a TRUE clock time (unlike `Sales.ShiftDate`, which is
  midnight-only — that's why hour-of-day is real here, not estimated). TransType
  1 = plays (deductions at readers, which reconcile to the Sales "Reader Sales"
  category). Paid loads and comped/bonus value (value adds with no DollarAmount,
  estimated from card value-units at ~100/$) are shown SEPARATELY. One
  admin-editable range query (`cardloads_range_sql`, required `:from`/`:to`,
  single-SELECT guarded via `MssqlClient::assertReadOnly`) returns per-(day,
  hour) buckets with paid_*/bonus_* columns; PHP rolls up daily/hourly/
  weekday×hour/summary, so a Year view costs the same one round-trip as a Day.
  Shares the Go-Kart Labor page's MSSQL connection (`lib/mssql_client.php`);
  the connection itself is configured there. View gate: analytics + view_revenue
  (nav key view_revenue); config gate: settings. The Test button reconciles a
  probe day and dumps the day's TransType breakdown. Like Labor, the live query
  only runs on the venue server (the sandbox has no MSSQL driver).
- Ticket Trends (`#/tickets`, `/api/tickets/*`, `api/tickets.php`) reports
  redemption tickets earned by AREA (division) over Day/Week/Month/Year/Custom
  (same `perfResolveWindow` model), with a tickets-by-day trend + a per-division
  breakdown + prior-period delta. Tickets attribute to a `DivNo` (area) but NEVER
  a reader/game — every `PlayerCardTrans` ValueNo-3 (ticket) credit has `rdrkey`
  0 — so the division is the finest grain the POS supports (this is also why the
  per-game backfill leaves `tickets` 0). Source: MSSQL `PlayerCardTrans` ValueNo
  3, `Amount` = ticket-unit count (no dollar value). One admin-editable range
  query (`tickets_range_sql`, required `:from`/`:to`, single-SELECT guarded)
  returns per-(day, DivNo) buckets; PHP rolls up the trend + breakdown. DivNo→
  name is a best-effort INFORMATION_SCHEMA lookup (like Labor). Shares the Labor
  page's MSSQL connection; gold `--tickets` theme. View gate: analytics +
  view_revenue; config gate: settings. Venue server only (no sandbox driver).
- Revenue Mix (`#/revenue`, `/api/revenue/*`, `api/revenue.php`) is the
  P&L-lite roll-up the app was missing: sales dollars by CATEGORY (CatNo /
  area) over Day/Week/Month/Year/Custom (same `perfResolveWindow` model), with
  a revenue-by-day trend + a per-category breakdown (revenue, mix share,
  discount rate, units) + prior-period delta. It frames every other money
  report — attractions vs food vs groups vs card fees, and the mix shift over
  time. Grain is DAY, not hour: `Sales.ShiftDate` is a business day at midnight
  (no real clock time), so there is no hour-of-day/heatmap here (same honesty as
  Ticket Trends). Source: MSSQL `Sales` — `SUM(AmtSold)` (dollars),
  `SUM(Discounts)`, `SUM(QtySold)` grouped by `CatNo` (all confirmed columns —
  the Labor diagnostics sum them live). Discount rate = discount dollars ÷ gross
  (revenue + discounts), flagged when >10%. One admin-editable range query
  (`revenue_range_sql`, required `:from`/`:to`, single-SELECT guarded via
  `MssqlClient::assertReadOnly`) returns per-(day, CatNo) buckets; PHP rolls up
  the trend + breakdown in `revenueCompose` (pure/testable). CatNo→name is a
  best-effort INFORMATION_SCHEMA lookup (like Labor/Tickets). Shares the Labor
  page's MSSQL connection; money-green `--revenue` theme. View gate: analytics +
  view_revenue; config gate: settings. The Test button reconciles a probe day
  and dumps that day's revenue-by-category breakdown. Venue server only (no
  sandbox driver).
- Database Explorer (`#/explorer`, `/api/explorer/*`) is a READ-ONLY window
  into the CenterEdge MSSQL database (shares the Labor page's connection)
  for finding where metrics live: table browser (columns/types, date-column
  freshness MIN→MAX, sample rows), "Find a metric" grouped totals over a
  date range (the generalized DivNo-808 probe), and a free-form guarded
  SELECT with CSV export. Gate: settings (admin). Builder identifiers are
  validated against INFORMATION_SCHEMA then bracket-quoted; free SQL goes
  through MssqlClient::assertReadOnly; rows are capped (500) and cells
  clamped; aggregate/query runs are audit-logged to action_log. Row counts
  come from sys.partitions when readable (COUNT(*) on a years-deep Sales
  table is never run). SQL errors return structured `{error}` (HTTP 200) —
  they're the expected failure mode while exploring.
- Reader Groups (`reader_groups`/`reader_group_games`, CRUD at
  `/api/reader-groups`, page at `#/readers`) are analytics-only groupings of
  games/readers — they never pause anything, and a game may be in many groups.
  `GET /api/analytics/reader-groups` compares every area (totals, avg plays
  per day / per game per day, busiest weekday+hour, prior-period deltas);
  `GET /api/analytics/reader-group?id=` adds the day-of-week × hour heatmap
  (per-occurrence averages for staffing), trend series, and per-game breakdown.
  Hour-grain data stitches `game_hourly_stats` + raw feed and reports its
  actual coverage window (hourly history only accumulates from feature ship).
  View gate `analytics`; create/edit/delete gate `reader_groups_manage`
  (its own catalog key — a one-time migration granted it to roles that held
  `groups_manage` when the key split).

### API Pattern
- API handlers are loaded via `require_once` from `index.php` which pre-loads `db.php`, `auth.php`, `csrf.php`, `crypto.php`
- Each handler is a function `handleX($method, $parts, $input)` dispatched from `index.php`
- `$parts` = URL segments after the resource name
- `$input` = parsed JSON body for POST/PUT/PATCH
- RuntimeException → 422, other Exception → 500

### Frontend Pattern
- Routes registered via `App.registerRoute('#/path', { render: fn })`
- API calls via `API.get()`, `API.post()`, `API.put()`, `API.patch()`, `API.del()`
- Note: DELETE method uses `API.del()` (not `API.delete()` — `delete` is a JS reserved word)
- DOM built with `App.el(tag, props, children)` helper

### Scheduling Engine
- Schedule windows = active (unpaused) hours. Outside windows = paused.
- Priority: manual override > schedule override > recurring schedule
- `planDay()` computes transition points, resolves conflicts, deduplicates
- Missed-action optimization: only latest per group executed, earlier superseded (status 3)
- Concurrency via ONE global scheduler lock (`Scheduler::acquireLock()/releaseLock()`,
  re-entrant per process). cron.php, cron_watchdog.php, run_action.php AND the web
  entry points (manual actions, per-request enforcement) all take it — never
  fopen/flock LOCK_FILE directly. The watchdog holds it only for its action
  phase and releases before the slow transaction poll.
- `executeStateChange()` patches both games AND kiosks for a group in one
  invocation — kiosks share the GameOperationStatus enum (enabled/paused/outOfService).
  Kiosk patching is best-effort; failure does not roll back game changes.
- Per the kiosk API spec, kiosks reporting no `operationStatus` ("unknown")
  must NOT be pause-controlled — the scheduler skips them automatically.

### Security
- Passwords: bcrypt cost 12, auto-rehash
- CSRF: 256-bit token, `X-CSRF-Token` header, timing-safe validation
- Encryption at rest: AES-256-CBC + HMAC-SHA256 for API credentials
- CLI-only guards on cron scripts
- Input validation via Validator class (throws RuntimeException)
- Roles are DATA (the `roles` table, edited via /api/roles + Settings UI);
  permissions are CODE (`Auth::PERMISSIONS` catalog — 17 keys incl.
  view_revenue, manual_control, reader_groups_manage). A read-only "Viewer"
  role (all pages + analytics + view_revenue + cards + view_logs) is seeded
  once as a normal custom role — fully editable/deletable in Settings.
  Mutation buttons are also hidden client-side for roles lacking the
  relevant permission (server re-checks regardless).
- Every sidebar section is hideable per role (and per user via the
  grant/deny override editor): the six operational pages have view_* keys
  (`Auth::PAGE_PERMISSIONS`, grouped as "Pages" in the role editor); the
  reporting pages use their existing keys (analytics, cards, view_logs,
  settings). Hiding removes the nav item, bounces the route
  (`App.SECTION_AREAS` drives nav + guard + `App.defaultHash()` landing;
  `#/no-access` covers roles with nothing enabled), and blocks reads:
  shared GET endpoints use `Auth::requireAnyAccess([...])` dependency sets
  (e.g. groups GET allows view_groups|view_dashboard|view_schedules|
  view_overrides|the manage keys) so a visible section always has the data
  it renders, and data closes only when every section needing it is hidden.
  A one-time migration (`migration_view_keys_v1`) granted all six view keys
  to every existing role so the upgrade changed nobody's access.
  `Auth::hasPermission()/canAccess()` resolve
  through the user's role; admin bypasses and is locked against edit/delete.
  The client gets the resolved permission list injected into
  `APP_CONFIG.user.permissions` (and login/status responses) —
  `App.canAccess()` reads it (LEGACY_ACCESS fallback for stale sessions).
  Non-admins can only assign roles whose permissions are a subset of their
  own (`Auth::canAssignRole`). Unknown role slugs resolve to ZERO permissions.
- Sessions re-validate role + is_active from the DB every ~60s, so role
  changes / deactivation / deletion apply without waiting for re-login.
- Admin accounts can only be modified/deleted by admins; last-admin
  demotion/deactivation/deletion is blocked. Card PIN checks are
  audit-logged and rate-limited (15 / 10 min per user).

### Testing
- No automated test suite
- Manual smoke testing via install.php, cron.php, UI flows
- `php -l` for syntax checking across all PHP files

### Running Locally
```bash
php -S localhost:8080 index.php   # Built-in PHP server
# Or with Apache: .htaccess handles URL rewriting
```

### Cron Setup
```bash
* * * * * /usr/bin/php /path/to/cron_watchdog.php >> /path/to/data/watchdog.log 2>&1
5 0 * * * /usr/bin/php /path/to/cron.php >> /path/to/data/cron.log 2>&1
```
