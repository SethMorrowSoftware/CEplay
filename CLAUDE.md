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
- `lib/auth.php` — Session management (bcrypt, HttpOnly, SameSite, 2h timeout, rate limiting). Login rate-limit/audit key off `getClientIp()` (`api/auth.php`), which trusts `X-Forwarded-For` only from a loopback reverse proxy and then takes the RIGHTMOST (proxy-appended) hop — the client-supplied leftmost entries are never trusted, so the lockout can't be bypassed by rotating a spoofed IP.
- `lib/csrf.php` — CSRF token generation + timing-safe validation
- `lib/crypto.php` — AES-256-CBC encrypt-then-MAC (HMAC-SHA256, backward-compatible)
- `lib/validator.php` — Input validation (strings, ints, dates, times, enums, arrays)
- `lib/mssql_client.php` — READ-ONLY CenterEdge MSSQL client for the reporting pages (Labor/Card Loads/Ticket Trends/Revenue Mix + the Database Explorer). Single-SELECT guard (`assertReadOnly`), regex-validated `:from`/`:to` / `:date` range binding, driver detection (pdo sqlsrv → dblib → odbc), settable timeout. Connection config lives encrypted in `api_config`
- `lib/reporting.php` — `Reporting` class: the set of game IDs that count as "redemption" for payout math (`redemptionGameIds`, resolved from games/categories), shared across the analytics/games endpoints. NOTE: the Day/Week/Month/Year/Custom window model (`perfResolveWindow`/`perfRangeMeta`/`perfTimezone`) lives in `api/analytics.php`, which the MSSQL report handlers `require_once` and reuse

## Directory Layout
```
api/          — API endpoint handlers (auth, settings, games, cards, groups, reader_groups, promotions, kiosks, schedules, overrides, analytics, labor, cardloads, tickets, revenue, redemption, explorer, logs, users, roles, capabilities)
lib/          — 9 core libraries (db, auth, csrf, crypto, validator, scheduler, centeredge_client, mssql_client, reporting)
public/js/    — Vanilla JS modules (api, app, login, dashboard, games, cards, groups, kiosks, schedules, overrides, analytics, performance, readers, promotions, labor, cardloads, tickets, revenue, redemption, explorer, logs, settings)
public/css/   — Dark/light theme stylesheet (modular @imports from style.css; page styles under css/pages/)
data/         — Runtime: SQLite DB, locks, heartbeats, logs, nightly backups (gitignored)
docs/         — Internal docs: security audit (AUDIT.md), CenterEdge API reference (CENTEREDGE_API.md + api-reference/ OpenAPI), MSSQL driver setup (MSSQL_DRIVER.md), incident write-ups
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
- **Deep money history (Analytics overview):** the Analytics page's headline
  KPIs + trend reach back ~2 decades even though the raw feed is only ~30 days.
  A venue-wide daily rollup, `venue_daily_stats` (per local day: `plays`,
  `value`, `tickets`, `unique_cards`), is aggregated straight from the POS
  ledger (`PlayerCardTrans`) — plays + play-value (`DollarAmount`) from
  `TransType 1` reader deductions, tickets from all `ValueNo 3` earned.
  **Do NOT filter plays by `rdrkey<>0` here** (the per-game backfill must, to
  attribute a play to a game, but this venue-wide rollup must not): this venue's
  readers stopped populating `rdrkey` after ~2012 (verified — `TransType 1`
  carries `rdrkey<>0` in 2011 but `rdrkey=0` in 2019/2026), so an `rdrkey<>0`
  filter silently drops every play from 2013 on. `TransType 1` alone is the
  reader-swipe signal across all eras (same as Go-Kart Labor). The backfill is
  version-stamped (`Scheduler::VENUE_DAILY_BACKFILL_VERSION` /
  `venue_daily_backfill_done`) so a definition change auto-rebuilds the rollup
  on the next run instead of being blocked by a stale "done" flag. None of these
  depend on the per-game `rdrkey` mapping, so money/tickets/plays are REAL
  historically (unlike the per-game backfill, which can't attribute plays to a
  game once `rdrkey=0`). Written once
  ~2 decades deep (`Scheduler::backfillVenueDailyStatsFromMssql`, monthly MSSQL
  batches, flag `venue_daily_backfill_done`, via `runPendingBackfills`) and
  refreshed nightly for the trailing 40 days
  (`Scheduler::refreshVenueDailyStatsRecent`, cron Step 5c) — both cron-only,
  MSSQL-only (self-skip if unconfigured), and never touch today (today stays
  live from the raw feed). `GET /api/analytics/overview` activates deep mode
  ONLY when the requested range starts before the raw feed's earliest day
  (`analyticsRawFloorDate`); it is SINGLE-SOURCE (`analyticsVenueDaily`, the
  rollup only — never mixed with the raw feed, so no definitional seam), swaps
  the headline plays/play-value/tickets to the ledger, and NULLs every
  recent-only panel (hour-of-day, top games, category, payment/brand mix, guest
  insights, per-period unique cards) because they can't be rebuilt from a daily
  rollup. `value` = dollars spent at readers (broader than the recent "Reader CC
  payments" walk-up figure), scrubbed by `analyticsScrubMoney` for roles without
  view_revenue like every other dollar. Trend is per-month for Year-style ranges,
  else per-day (cap 370). The frontend (`public/js/analytics.js`) shows a
  "Historical view" banner with the exact coverage window, renders the deep KPIs
  (labeling money "Play value"), and hides the nulled panels with a
  "recent window only" note.
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
- Redemption Economics (`#/redemption`, `/api/redemption/*`, `api/redemption.php`)
  is the redemption half of the ticket economy — Ticket Trends reports tickets
  EARNED, this reports tickets SPENT for prizes, so together they give the
  redemption RATE (redeemed ÷ earned) and the period's net change in outstanding
  ticket liability. Over Day/Week/Month/Year/Custom (same `perfResolveWindow`
  model): a tickets-redeemed-by-day trend, a redemptions-by-hour curve, a
  weekday×hour heatmap (counter staffing), redemption %, period net float, and a
  per-prize mix. Source: MSSQL `RedeemReceipts` — the redemption RecType
  DEPENDS ON THE CARD SYSTEM: the current CenterEdge/Kiosoft readers (live since
  ~end of April 2026) write `RecType = 1`; the legacy Embed system used
  `RecType = 3`. Default filters `RecType = 1 AND TotalTickets > 0`.
  `TotalTickets` = tickets spent, `RecTime` = a TRUE clock time
  (`RedeemDateTime` is sometimes null/anomalous, so `RecTime` drives day +
  hour), plus `RedeemRecItems` (per-prize line items: `InvNo`, `NumberTickets`,
  `Qty`) for the prize breakdown. Prize MARGIN is NOT available — RedeemRecItems
  has no cost column — so the prize panel is a MIX (tickets/qty), not COGS; a
  prize-cost source would be needed for margin. All-time outstanding ticket
  float would come from `EmbedBalance.ETickets` (a candidate table); this report
  shows the PERIOD net (earned − redeemed). Two admin-editable range queries
  (`redemption_range_sql` + `redemption_items_sql`, required `:from`/`:to`,
  single-SELECT guarded); the redemption RATE reuses the Ticket Trends earned
  query (`ticketsRangeSql`) as the denominator. Prize names via best-effort
  INFORMATION_SCHEMA lookup (Inventory/Merch/Prize table). Violet `--redemption`
  theme; heatmap reuses the `cardloads-heat*` classes. View gate: analytics +
  view_revenue; config gate: settings; Test gate: data_explorer. The Test button
  dumps a RecType breakdown + reconciles line-item vs receipt-header ticket
  totals. Venue server only (no sandbox driver).
- Promotional Cards (`#/promotions`, `/api/promotions/*`, `api/promotions.php`)
  tracks BLOCKS of giveaway cards by card-number RANGE (e.g. "K104 on-air
  giveaway, cards 100000–100499, $30 each") and measures how they performed:
  how many came back (activation), reloads + average additional $, plays,
  tickets earned, value spent, and a per-card drill-down. Modeled on Reader
  Groups (a managed list of ranges + click-through detail): batch DEFINITIONS
  live in SQLite (`promo_batches`: name, card_from/card_to, giveaway_date,
  initial_value, notes — no child table, a batch is just a range), while every
  PERFORMANCE number is computed LIVE from MSSQL `PlayerCardTrans` by card
  number. A card never used has NO ledger rows, so "cards used" = distinct
  cards that appear and activation = used ÷ range size (`card_to−card_from+1`).
  Money defs match the other reports: `TransType 1` = plays (`DollarAmount` =
  value spent), `TransType 3` = value ADDED (reloads), `ValueNo 3` = tickets.
  One admin-editable aggregate query (`promo_range_sql`, placeholders
  `:since`/`:cardfrom`/`:cardto`, single-SELECT guarded); the `:since` floor
  (the giveaway date) lets the `TransDateTime` index bound the scan so a
  card-range query over the 20-year ledger stays fast (a full CardNumber scan
  would be non-SARGable). Card-range bounds are inlined as validated integer
  literals via `MssqlClient::bindCardRange` (digits-only → injection-proof, same
  rationale as bindDate/bindRange). `TRY_CONVERT(BIGINT, CardNumber)` tolerates
  the `000000`/blank card sentinels. View gate: `analytics` (money scrubbed for
  roles without view_revenue — techs see activation/plays/tickets, never
  dollars); manage gate: `promotions_manage` (its own catalog key, granted once
  to roles holding `reader_groups_manage`); config gate: `settings`; Test gate:
  `data_explorer` (the Test button probes a card range + dumps a sample of the
  card numbers matched, to confirm the range hit real cards). Pink `--promo`
  theme. Batches can be defined even before MSSQL is configured (stats fill in
  later). Venue server only for the live numbers (no sandbox driver).
- Database Explorer (`#/explorer`, `/api/explorer/*`) is a READ-ONLY window
  into the CenterEdge MSSQL database (shares the Labor page's connection)
  for finding where metrics live: table browser (columns/types, date-column
  freshness MIN→MAX, sample rows), "Find a metric" grouped totals over a
  date range (the generalized DivNo-808 probe), and a free-form guarded
  SELECT with CSV export. Gate: `data_explorer` (admin-only by default — a
  dedicated permission separate from `settings`, so a technician who holds
  `settings` for CenterEdge/timezone config cannot reach raw POS data here; the
  same key also gates the MSSQL report Test buttons on Labor/Card Loads/Ticket
  Trends/Revenue Mix). Builder identifiers are
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

### CenterEdge MSSQL Database (schema reference)
Everything the reporting features read lives in the venue's CenterEdge POS
database (MSSQL, `[CenterEdge].[dbo].*`), accessed READ-ONLY through
`lib/mssql_client.php` (single-SELECT guarded, admin-editable `:from`/`:to`
range queries). CenterEdge exposes NO reporting API, so all aggregation is
local. The live queries run ONLY on the venue server (the sandbox has no MSSQL
driver). This section records what we've verified about the schema so future
work doesn't re-discover it. **CONFIRMED** = referenced by shipped code and/or
reconciled on the venue via the page Test buttons / Database Explorer.
**CANDIDATE** = seen only in a schema browse (row counts approximate, columns
NOT yet verified live) — treat as a starting point, confirm with the Explorer
before building.

- **Deferred-value model (CONFIRMED, architectural):** `ApplicationInfo.
  DeferValuePlayerCards = 1`. Card value is STORED VALUE, not a POS sale — a
  card load is booked to the card ledger as a liability, NOT to the `Sales`
  table. This is why Card Loads reads the ledger (not Sales), and why per-game
  "cash" is 0 on reader rows in Performance (deferred plays don't post cash to
  `Sales`).
- **`PlayerCardTrans` — the card ledger (CONFIRMED), the single most-used
  source.** One row per card transaction; `TransDateTime` is a TRUE venue-local
  clock time (so hour-of-day is REAL for anything sourced here). Discriminators:
  - `TransType = 1` — plays / deductions at a reader. Carries `rdrkey` (the
    reader) and `DollarAmount` (value spent). Powers the Go-Kart hourly money
    panel and the per-game play-history backfill. `rdrkey` maps to a game via
    `ReaderDevices` (name join — see `Scheduler::backfillGameStatsFromMssql`).
  - `TransType = 3` — "add value" (a card LOAD). `DollarAmount` = real dollars
    paid; value adds with no `DollarAmount` are comped/bonus value (estimated
    from card value-units at ~100/$). Source for Card Loads.
  - `ValueNo = 3` — redemption tickets EARNED. `Amount` = ticket-unit count (no
    dollar value). Attributes to a `DivNo` (area) but **NEVER a reader/game —
    every ValueNo-3 credit has `rdrkey` 0** (confirmed by Explorer; this is why
    Ticket Trends is by-division and the per-game backfill leaves `tickets` 0).
  - Also present: `CardNumber`, `EmpNo` (cashier/employee), `DivNo`. MIN/MAX
    `TransDateTime` per card seeds the guest "new vs returning" ledger
    (`Scheduler::backfillCardActivityFromMssql`), reaching back ~2 decades.
  - **Venue-wide daily rollup (CONFIRMED consumer):** grouped by
    `CONVERT(VARCHAR(10),TransDateTime,120)` (local day), this same table feeds
    `venue_daily_stats` — plays + play-value (`DollarAmount`) from `TransType 1`
    (NO `rdrkey` filter — see below), tickets from all `ValueNo 3`, distinct
    cards — for the Analytics overview's deep money history
    (`Scheduler::backfillVenueDailyStatsFromMssql` / `refreshVenueDailyStatsRecent`).
    Venue-wide (no `rdrkey`→game mapping), so money/tickets/plays are real here
    where the per-game backfill can only leave them 0 once `rdrkey=0`.
  - **`rdrkey` populated only pre-~2012 (CONFIRMED, era gotcha):** `TransType 1`
    reader deductions carry a nonzero `rdrkey` in the early era (2011: all
    `rdrkey<>0`) but `rdrkey = 0` afterward (2019 & 2026: all `rdrkey=0`). So the
    per-game backfill (which joins `rdrkey`→game) can only attribute plays for
    the early era, while a venue-wide count MUST use `TransType 1` alone. Real
    per-play `DollarAmount` is 0 in the early era and populated from ~2013 on.
- **`Sales` — POS sales lines (CONFIRMED).** `ShiftDate` is a business DAY
  stamped at MIDNIGHT (not a clock time), so Sales-sourced reports are honest at
  day grain only — no real hour-of-day (that's why Revenue Mix / the go-kart
  cash figure have no heatmap). Columns in use: `AmtSold` (dollars), `QtySold`,
  `Discounts`, `CostSold`, `NumberTickets`, `CatNo` (category/area),
  `SubCatNo`, `DivNo`. Confirmed codes on this install: **`CatNo 108` = Go
  Karts** (rides post at `AmtSold` 0 — paid at the reader; walk-up cash posts as
  cash), **`CatNo 106` = Beverages**, **`DivNo 808` = "Go Kart Readers"** (the
  aggregated daily dollars spent at the kart readers — the go-kart sales figure
  on the Labor page). Category/division NAMES are discovered live via
  `INFORMATION_SCHEMA` (a `%Cat%`/`%Div%` table with an int No column + a text
  Name column), because the lookup table's name varies by install.
- **`ReaderDevices` (CONFIRMED):** maps `rdrkey` → reader/game by name. The join
  used to attribute `PlayerCardTrans` TransType-1 plays and the reader feed to
  games (`normReaderName` in the scheduler backfill).
- **Ticket attribution gotcha (CONFIRMED):** tickets exist ONLY at the division
  grain. We checked exhaustively — there is no per-game ticket source anywhere
  in the DB (every ticket credit's `rdrkey` is 0).

- **CARD-SYSTEM CUTOVER (important):** the venue switched from the **Embed**
  card system to **CenterEdge/Kiosoft** readers ~end of April 2026. Table
  CONVENTIONS can differ across that boundary — e.g. `RedeemReceipts` uses
  `RecType = 1` for redemptions on the new system but `RecType = 3` on legacy
  Embed data (this bit the Redemption report — it shipped filtering RecType 3
  and read zeros until the default was corrected to RecType 1). Any table with
  "Embed" in its name, or any pre-May-2026 assumption, must be re-confirmed
  against recent rows before use.
- **CANDIDATE tables — high-value sources for reports not yet built** (row
  counts from a one-time schema browse; confirm columns via the Explorer first):
  - `EmbedBalance` (~563K) — stored-value balances per card (`Card_Barcode`,
    `Cash_Balance`, `Bonus_Balance`, `ETickets`). Aged by last-activity →
    outstanding liability + breakage. (Snapshot, not a range.) NOTE the
    "Embed" name + cutover above — confirm it still populates post-April before
    building on it.
  - `CreditCardTrans` (~1.2M, `TransDateTime` real clock, `Amount`,
    `ShortAcctNumber`, `CardType`) — real card-tender dollars + brand mix.
  - `GroupSales` (~105K) / `GroupBirthdays` (~24K) / `GroupArrivals` (~32K) —
    parties/birthdays revenue + forward booking pipeline.
  - `Customers` (~131K) / `CustPasses` (~70K) / `CustVisits` (~173K) /
    `CustSales` (~545K) — the durable NAMED-customer dimension (membership /
    season-pass / RFM) the card-number Guest Insights can't reach.
  - `CashierSales` (~3.2M) / `PlayerCardTrans.EmpNo` — per-employee productivity.
  - `ReaderSales` (~20.9M, `rdrKey`, `ShiftDate` real clock, `DivNo`,
    `SaleAmount`) — an alternate per-game revenue source (vs PlayerCardTrans
    TransType-1); `TimeSales` (~6.6M) timed-attraction sales; `SubCatSales`
    (~1.4M) F&B sub-category drill-down; `TicketTrans` (~17K) printed vouchers.

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
  permissions are CODE (`Auth::PERMISSIONS` catalog — 19 keys incl.
  view_revenue, manual_control, reader_groups_manage, promotions_manage,
  data_explorer). A
  read-only "Viewer"
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
