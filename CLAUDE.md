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
api/          — API endpoint handlers (auth, settings, games, cards, groups, reader_groups, promotions, items, kiosks, schedules, overrides, analytics, labor, cardloads, tickets, revenue, redemption, explorer, logs, users, roles, capabilities)
lib/          — 9 core libraries (db, auth, csrf, crypto, validator, scheduler, centeredge_client, mssql_client, reporting)
public/js/    — Vanilla JS modules (api, app, login, dashboard, games, cards, groups, kiosks, schedules, overrides, analytics, performance, readers, promotions, items, labor, cardloads, tickets, revenue, redemption, explorer, logs, settings)
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
- **ACTUALS ONLY — no projections, no averaged baselines.** A standing product
  rule for this app: every number a user sees is something that actually
  happened. Do NOT add end-of-day/period projections, run-rate extrapolations,
  "typical day" baselines, or estimated dollars — and prefer real totals over
  averages when a panel has to pick one. Things removed under this rule, so they
  don't get reintroduced: the dashboard's "Today's pace" card and its
  `GET /api/games/transactions/pace` endpoint (projected tickets by close +
  typical-same-weekday baseline), the "Daily pace (7d)" KPI tile (7-day average),
  the Labor page's per-day / per-weekday-occurrence averages, and the Labor
  "rides × price" estimate mode. Averaging is still fine where it IS the metric
  (e.g. avg tickets per play), and the Card Loads bonus-dollar figure is a
  unit conversion of a recorded value-unit amount, not an estimate of activity.
- **Year over year (`GET /api/analytics/yoy`)** — month-to-date and year-to-date
  actuals against the identical stretch of the prior year. Rendered by the shared
  `App.buildYoyCard()` / `App.renderYoy()` widget (in `public/js/app.js`,
  styled by `public/css/components/yoy.css`) on BOTH the Command Center
  dashboard (`#/dashboard`, where the removed pace card used to sit) and the
  Analytics page (`#/analytics`, range-independent — it ignores the top-bar
  period picker and says so by printing its own coverage window). `through` is
  the newest COMPLETE local day the source covers (never today, which is partial
  by definition), and the prior year is cut at the same month/day with Feb 29
  clamped to Feb 28. SINGLE-SOURCE across all four windows so the two sides
  always share a definition: `venue_daily_stats` (the POS ledger rollup, ~2
  decades — money = play value) whenever it has any row, else `game_daily_stats`
  (the app's own rollup — money = cash at readers), reported as `source`
  `ledger`|`app`. `prior_has_data` is false when the prior year has no covered
  days, so the UI says "no 2025 history" instead of a fake +100%. Money is
  scrubbed to 0 for roles without `view_revenue` like every other dollar.
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
  this one replaced it with ledger data.)
  **VERIFIED July 2026 against the live venue DB:**
  - The daily SALES figure is correct — `Sales` DivNo 808 and `ReaderSales`
    InvNo 3074 agree TO THE PENNY for June 2026 ($65,985.08 both). Two
    independent tables, so the headline number is trustworthy.
  - The hourly ledger runs a few points LIGHT of it ($63,103.33, 95.6%) — not
    every DivNo-808 posting carries a card-ledger dollar amount. Normal and
    expected; the panel now reports its own coverage rather than drawing bars
    that quietly don't sum to the total above (`laborReconcile`, statuses
    ok/partial/missing/unknown, tolerance `LABOR_RECONCILE_TOLERANCE` 0.90).
  - The hourly panel goes EMPTY on old ranges and this is why: `PlayerCardTrans.
    DivNo` was 1 for everything in June 2015, and only carries 808 from ~2019
    on (2019: 8,907 plays, 2023: 5,255, 2025: 2,382, 2026: 5,560). The
    reconciliation reports `missing` for such ranges instead of showing an empty
    chart beside a real daily total.
  - Wages check out: `JobCode 3 'Go-Karts'` runs continuously 2006-04-12 →
    2026-07-31 (32,328 punches) and survived the 2015-01-08 job-code
    restructure that folded `Lazer Tag`/`Rock Wall`/`Free Fall`/`Snack Shack`
    into `Rides`/`Activities`/`POS`. Zero unclosed past-day punches out of 2,763
    since 2025, so the count-zero convention has never fired. Overtime is zero
    for this crew (`TimeClock_WorkHours.OTHours_1` = 0 on every kart row,
    rates $15.75-$16.50), so `PayRate` × elapsed is accurate.
  - **BREAKS ARE UNPAID** (operator-confirmed), so paid hours = elapsed −
    `TimeClock_Weekly.BreakHours`, applied by both the daily wages query and
    the hourly split. **Do NOT switch to `WorkHours` to get this** — measured on
    the venue DB, `WorkHours` is only the elapsed time rounded to 2dp and does
    NOT net breaks out (09:13:09→13:44:30 = 4.5225 elapsed, `WorkHours` 4.52,
    `BreakHours` 0.63). `BreakHours` is in HOURS (0.46-0.63 on real rows ≈
    28-38 min meal breaks); it is populated on ~15% of punches (48 of 312 in
    June 2026) and was overstating wages by $351.74 on $23,058.79 (1.53%,
    moving the labor rate 34.94% → 34.41%). The ledger records how MUCH break
    time a punch had but never WHEN, so the hourly split scales each punch by
    its paid fraction — approximate in placement, exact in total, so the hourly
    panel always ties to the daily wage figure.
  - Both wage queries are admin-editable and persist in `api_config`, so the
    pre-fix text is carried forward via `laborUpgradeStored()` — upgraded only
    on a VERBATIM match of the superseded default (`LABOR_LEGACY_*_RANGE_SQL`),
    never touching a hand-customized query. `laborPunchWageHours()` also
    tolerates rows with no `break_hours` column, so a custom punches query
    keeps working unchanged.
  - OPEN: whether any kart labor posts under `JobCode 44 'Rides'` (26,469
    punches, 2015→now, runs alongside Go-Karts). Also unexamined: `BreakCode`
    (1 on every sampled row) — if the venue ever adds a PAID break code, the
    deduction would need to key off it rather than applying to all breaks. **ACTUALS ONLY** — the hourly panel's
  bars and every heatmap cell are the REAL totals for the selected period
  (`hours[].dollars`/`wages`, `heatmap.rows[].cells[].dollars`/`wages`,
  `heatmap.max_dollars`/`max_wages`). They used to be averages — per day for the
  hour rows, per weekday-OCCURRENCE for the heatmap — with matching "avg"
  tooltips; both were removed so the page never shows a typical day. Heatmap
  rows still carry `occurrences` as context (a window with three Saturdays and
  one Sunday says so), and the heatmap's tap-to-inspect line replaced the hover
  tooltip. The old rides × price "estimate mode"
  (`labor_add_ride_value` / `labor_price_per_ride` / `labor_ride_prices`) is
  GONE: sales are always the recorded DivNo-808 dollars. The only ride config
  left is `labor_reader_group_id` — which reader-group area counts as the karts,
  used for swipe COUNTS only (payload key `ride_counts`, formerly
  `ride_valuation`). Test connection runs both live queries
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
  Metric defs (important — a giveaway is bulk-loaded up front, so "loaded" ≠
  "used"): `cards_used`/"played" = distinct cards with a `TransType 1` PLAY (came
  back and used); `cards_loaded` = distinct cards with a `TransType 3` (carry the
  promo value); `reloads`/"additional money" = PAID top-ups only (`TransType 3`
  AND `DollarAmount > 0`) — the initial promo value is COMPED (`DollarAmount 0`)
  so it is NOT a reload; `plays`/value = `TransType 1`; `tickets` = `ValueNo 3`.
  The per-card table filters to cards with real activity (played / earned tickets
  / paid reload), so bulk-loaded-but-unplayed cards don't fill it with zeros.
  One admin-editable aggregate query (`promo_range_sql`, a WITH-CTE with
  placeholders `:since`/`:cardfrom`/`:cardto`, single-SELECT guarded). **Card
  numbers get reissued to different physical cards over the years**, so the
  query DEDUPES to the MOST RECENT card per number: per `TRY_CONVERT(BIGINT,
  CardNumber)` it splits activity into "lives" at any gap > 365 days (a reissue)
  and keeps only the latest life — otherwise a decade-old card's plays/tickets
  get folded into a recent giveaway (the bug that made a 51-card range read
  "94 cards, 184%"). `cards_used` counts `DISTINCT cn` (the numeric value, so
  `0288051`==`288051`). The `:since` floor bounds the scan on the `TransDateTime`
  index (a bare `TRY_CONVERT` card-range scan is non-SARGable): the giveaway
  date when set, else a recent default (`PROMO_DEFAULT_LOOKBACK_DAYS`, ~3y) since
  the dedup already drops older reuse. The giveaway date is OPTIONAL (a
  speed/precision knob, not required). Card-range bounds are inlined as validated
  integer literals via `MssqlClient::bindCardRange` (digits-only →
  injection-proof, same rationale as bindDate/bindRange). `TRY_CONVERT(BIGINT,
  CardNumber)` tolerates the `000000`/blank card sentinels. (Window functions
  need SQL Server 2012+.) View gate: `analytics` (money scrubbed for
  roles without view_revenue — techs see activation/plays/tickets, never
  dollars); manage gate: `promotions_manage` (its own catalog key, granted once
  to roles holding `reader_groups_manage`); config gate: `settings`; Test gate:
  `data_explorer` (the Test button probes a card range + dumps a sample of the
  card numbers matched, to confirm the range hit real cards). Pink `--promo`
  theme. Batches can be defined even before MSSQL is configured (stats fill in
  later). Venue server only for the live numbers (no sandbox driver).
- Item Watch (`#/items`, `/api/items/*`, `api/items.php`) is the "how is this
  item / this deal selling?" page: an operator-pinned WATCHLIST of POS inventory
  items rendered as cards (units, dollars, a bar sparkline, and the change vs
  the previous period), a click-through detail view, and a BEST SELLERS
  leaderboard for the same window. Modeled on Promotional Cards — definitions in
  SQLite (`watch_items`: name, `inv_nos`, tag, start/end date, notes), every
  number computed LIVE from MSSQL. Browsable by Day/Week/Month/Year/Custom via
  the shared `perfResolveWindow` model.
  **One entry = a SET of `InvNo` values**, not just one: a deal made of several
  inventory numbers (e.g. a 3-ride kart pass + its variants) becomes ONE card
  whose figures are the union, with a per-InvNo breakdown on the detail view
  showing which member is actually moving. Source: MSSQL `Sales` grouped by
  `InvNo` — `SUM(QtySold)` units, `SUM(AmtSold)` dollars, `SUM(Discounts)`,
  `SUM(CostSold)` (the only cost source in the schema — but see the `Sales`
  entry below: `CostSold` is EMPTY on this install, so in practice the margin
  columns stay hidden here. `has_cost` is computed per response and the margin
  UI hides itself entirely rather than printing a fake 100%; the code path is
  live and correct for a venue that does record cost).
  Grain is DAY, never hour — `Sales.ShiftDate` is a business day stamped at
  midnight, so there is no hour-of-day panel here (same honesty as Revenue Mix /
  Ticket Trends). FOUR admin-editable single-SELECT queries: `items_range_sql`
  (per day + InvNo, placeholders `:from`/`:to`/`:invnos` — drives the cards and
  the trend), `items_totals_sql` (per-InvNo totals for the same placeholders —
  used for the prior-period comparison and the since-launch figures, so a
  multi-year lookback costs one row per item instead of one per item per day),
  `items_top_sql` (the leaderboard, `:from`/`:to`/`:rankexpr`), and
  `items_history_sql` (the multi-period table, `:from`/`:to`/`:invnos`/
  `:periodexpr`). `:invnos` is inlined as a validated comma-separated integer
  list by `MssqlClient::bindIntList` (digits-only → injection-proof, same
  rationale as bindDate/bindRange/bindCardRange); an EMPTY list throws rather
  than producing `IN ()` or an unfiltered scan.
  **`GET /api/items/history`** is the "how is it doing over various periods"
  view, deliberately INDEPENDENT of the page's period picker: the last N
  calendar days/weeks/months/quarters/years for one item, each row with units,
  money and the step change against the row above. Takes `?id=` (a watched
  entry) OR `?inv=7157,7158` (any ad-hoc InvNo), so an item can be examined
  straight off the best-sellers leaderboard without pinning it first — that is
  what the per-row "History" button opens. Grouping happens IN SQL via
  `:periodexpr`, so a 5-year lookback returns ~5 rows, not days × items;
  `ITEMS_PERIOD_EXPR` is a server-side ALLOWLIST (never request input) keyed by
  grain, and the PHP key builder (`itemsPeriodKey`) must produce byte-identical
  keys or the zero-fill silently drops every row — there is a unit test pinning
  the two together. The week expression counts days from a known Sunday
  (1900-01-07) instead of using `DATEPART(WEEKDAY, …)`, which shifts with the
  connection's `SET DATEFIRST`. The newest row is normally a period IN PROGRESS:
  it is flagged, hatched, excluded from the totals/averages/best-period picks,
  and its change renders NEUTRAL with a "so far" suffix — a month that is three
  days old is not "down 70%".
  **Comparison basis** (`?compare=prev|yoy`) switches every change figure
  between the immediately preceding span and the same calendar dates a year
  earlier (via `analyticsYoyPriorDate`, so Feb 29 clamps to Feb 28). For a
  seasonal venue the year-over-year reading is usually the honest one.
  **Leaderboard ranking** (`?rank=revenue|units|margin`) swaps the `ORDER BY`
  through the `ITEMS_RANK_EXPR` allowlist, so the SQL picks its TOP N on the
  measure actually being ranked rather than re-sorting a revenue-shaped pool. A
  stored query customized with its own `ORDER BY` has no `:rankexpr` to swap —
  that is not an error, it reports `rank_locked` and the UI disables the control
  with the reason.
  The watchlist toolbar (search by name/tag/InvNo, tag chips, sort by
  units/revenue/biggest gain/biggest drop/name/recently added) and both CSV
  exports are pure client-side work over the already-loaded payload, so they
  cost no extra query; the CSV mirrors exactly what is filtered and sorted on
  screen, and drops every money column for a money-blind role.
  **ACTUALS ONLY** — every figure is a real total for the selected window. The
  prior-period fields are null (not 0) when the previous period has no rows for
  those items at all, so a newly-added item says "no prior" instead of showing a
  fake −100%. Setting a start date adds a "since it launched" block (clamped to
  ~5 years back, and it says so when clamped) that deliberately ignores the
  period picker.
  Caps, all reported rather than silent: 50 InvNos per entry (validation), 120
  InvNos per page load across the whole watchlist (entries past it are listed
  WITHOUT numbers and the payload carries `stats_skipped`), and 60,000 rows on
  the day-grain query (`truncated` flag → the UI says the totals are incomplete
  and to narrow the period). Item NAMES come from a best-effort
  `Inventory.Description` lookup, falling back to INFORMATION_SCHEMA discovery
  (same approach as the Revenue Mix category lookup); a miss just shows
  "Item 7157". View gate: `analytics` (money scrubbed for roles without
  `view_revenue` — techs see units, unit trends and days-sold, never dollars or
  margin); the best-sellers leaderboard is RANKED by dollars end to end so it
  requires `view_revenue` outright rather than being scrubbed. Manage gate:
  `items_manage` (its own catalog key, granted once to roles holding
  `promotions_manage` by `migration_items_manage_v1`); config gate: `settings`;
  Test gate: `data_explorer` (the Test button dumps the window's top InvNos with
  names and per-item cost — the fastest way to find an item's number — states
  the `CostSold` coverage ratio outright, reconciles the day-grain query against
  the totals query, and runs the history query on ALL FIVE grains checking each
  ties back to those totals. With the InvNo box left blank it probes the range's
  top seller automatically, so the reconcile and grain checks never depend on
  remembering to fill a field — they were skipped on two consecutive venue runs
  before that fallback existed). Teal `--items`
  theme. Items can be pinned before MSSQL is configured (numbers fill in later).
  Venue server only for the live numbers (no sandbox driver).
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
- **Per-game history reach probe (`GET /api/explorer/history-sources`,**
  "How far back can per-game history reach?" card on `#/explorer`). Performance
  attributes a play to a game via a READER KEY, so per-game history stops at
  the Embed → CenterEdge/Kiosoft cutover (`EXPLORER_CUTOVER_DATE`, ~May 2026)
  unless some table carries a usable reader key on older rows. The probe
  answers that against the live DB instead of guessing: it scans
  INFORMATION_SCHEMA for every table having BOTH a reader-key-ish column
  (`rdrkey`/`readerkey`/`readerid`/…, see `explorerIsReaderKeyColumn`) AND a
  date column, sizes them via sys.partitions, then for the largest few
  measures key coverage **YEAR BY YEAR** (`explorerProbeReaderYears`, one
  grouped pass per table) and — whenever ANY year has keys — how many resolve
  to a current game through `ReaderDevices` + the same name normalization the
  per-game backfill uses (`explorerNormName` mirrors
  `Scheduler::normReaderName`). Returns a `recommended` source or null, and the
  UI prints a plain verdict either way plus the per-year table behind it. Every
  check is independently try/caught, so one failure never takes the others
  down. Gate: `data_explorer`; audit-logged; venue server only. **Both facts
  matter** — a populated key that no longer maps to a game attributes to
  nothing.
  **Three false-negative bugs this shipped with, all fixed — do not
  reintroduce:** (1) it sampled ONE hardcoded month per era (Feb 2026), but key
  population varies WITHIN an era, so it read zeros and declared no per-game
  history existed while eight fully-attributed years (2005-2012) sat in the
  column it had just sampled; coverage is now measured per year, never assumed
  from the cutover date. (2) the mapping check only ran when that sample month
  had keys, so on this DB it never ran at all — yet the UI still reported the
  full conjunction ("no populated key AND none resolve"), asserting a result it
  had never tested; it now runs against the most recent year that HAS keys.
  (3) the populated-test was `<> 0`, which THROWS on a varchar key (T-SQL tries
  to convert), hiding the Embed `GameStation` columns entirely; the predicate is
  now type-aware (`explorerKeyPopulatedExpr`). Also: the date column is chosen
  by name preference (`explorerPickDateColumn`) rather than first-date-column-
  wins, since a table whose `CreatedDate` precedes its `ShiftDate` would
  otherwise be probed on the wrong column and read as empty.
  **A mapping miss can be a NAMING problem rather than a missing-data one** —
  exact normalized string equality can't match `1lazer tag` to a current "Laser
  Tag". But it can equally mean the machine is simply gone, and a bare "no
  match" cannot tell the two apart, so the probe reports the CLOSEST current
  game name and a 0-100 similarity for every miss (`explorerNearestName`), plus
  the size of the pool it compared against. Measured separation: a punctuation
  or spelling gap scores 89-100 (`1lazer tag`→"Laser Tag" 88.9, `1Go Kart Mini
  Indy`→"Go-Kart Mini Indy" 94.1, `tin can alley #1`→"Tin Can Alley 1" 96.8),
  while a retired machine scores 29-39 (`crane 2 mp3` 38.5, `Yellow Submarine`
  33.3). So the near-miss column decides whether a hand-built crosswalk would
  pay off or whether the floor has simply turned over — do not assume either.
  NOTE the normalizer ALREADY lowercases and strips a leading digit run
  (`^\d+\s*`), so `1Batting Cage 1` → `batting cage 1`: a miss on one of those
  means the name is genuinely absent from `game_state_cache`, not that the
  prefix defeated it.
- **Per-item cost / price probe (`GET /api/explorer/cost-sources`,** "Is there a
  per-item unit cost or list price?" card on `#/explorer`). `Sales.CostSold` is
  the schema's only cost-of-goods column and it is EMPTY here, so gross margin
  needs a unit cost from somewhere else — this answers whether one exists rather
  than guessing. Scans INFORMATION_SCHEMA for every table carrying BOTH an
  inventory-key column (`explorerIsInvKeyColumn` — a bare `No`/`ID` only counts
  on an inventory-ish table, or half the database matches) AND a money-ish
  column classified as cost or price (`explorerCostColumnKind`). Reports cost
  and price SEPARATELY, since they answer different questions (margin vs
  list-vs-actual).
  **The decisive measure is coverage of items that actually SELL, not overall
  population** — a cost populated on 6,000 discontinued SKUs and none of this
  month's sellers buys no margin at all, and would read as a healthy 75% on a
  whole-table count. The probe pulls the recent top sellers from `Sales`
  (`EXPLORER_COST_SELLER_SAMPLE`/`_DAYS`) and reports both figures side by side
  so the gap between them is visible. Verdicts are per-kind and honest either
  way: "usable" at ≥80% seller coverage, "partial" below it (with the note that
  uncovered items must show as unknown, never as 100% margin), "none found"
  when nothing is populated for any seller. With no seller list readable it
  says so instead of printing 0% — which would read as "no cost data exists".
  Lookalike columns are excluded BY NAME (`PriceLevel`, `PriceGroup`,
  `CostCenter`, `PriceCode`…): they are integers that look monetary, and type
  alone can't separate them since some installs store money in int cents — so
  int types are deliberately still accepted.
  **BOUNDED, because the first version timed out on the venue.** Every column
  probe is a full aggregate pass, and the candidate set includes `Sales` and
  `ReaderSales` — both carry an `InvNo` and are ~21M rows here, so scanning
  them (times several columns) blew the request budget. Three limits now apply,
  all reported rather than silent: tables over `EXPLORER_COST_SCAN_MAX_ROWS`
  (750k) are listed with "too large to scan" and never touched (a per-item unit
  cost lives on a MASTER table, so this loses nothing); a wall-clock
  `EXPLORER_COST_TIME_BUDGET` (30s) stops the sweep and marks the rest "time
  budget reached"; and the deadline is checked before EVERY query inside a
  column probe, not just between columns, so worst-case overshoot is one
  12s driver timeout rather than three. When the budget bites, the UI says the
  sweep was incomplete so a "none found" verdict is not mistaken for a definite
  answer. Gate: `data_explorer`; audit-logged; venue server only.
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
  - **`rdrkey` dies after 2012 (CONFIRMED per-year, era gotcha):** measured
    year by year over `TransType 1` on the venue DB — **2005-2011: 100%**
    populated (18 readers in 2005 growing to 97 by 2011); **2012: 76%**
    (760,744 of 997,431 — the transition year); **2013 onward: exactly 0**,
    every year through 2026. So per-game attribution via this column covers
    **2005-2012, ~4.94M plays across 98 readers**, and nothing after. A
    venue-wide count MUST use `TransType 1` alone. Real per-play
    `DollarAmount` is 0 in the early era and populated from ~2013 on — so the
    attributable era and the money-bearing era barely overlap.
    NOTE the shape of this: coverage is NOT uniform within an era, which is
    why sampling one month is not a valid test (see the reach probe below).
- **`Sales` — POS sales lines (CONFIRMED).** `ShiftDate` is a business DAY
  stamped at MIDNIGHT (not a clock time), so Sales-sourced reports are honest at
  day grain only — no real hour-of-day (that's why Revenue Mix / the go-kart
  cash figure have no heatmap). Columns in use: `AmtSold` (dollars), `QtySold`,
  `Discounts`, `CostSold`, `NumberTickets`, `CatNo` (category/area),
  `SubCatNo`, `DivNo`, **`InvNo`** (the inventory item — CONFIRMED: the venue
  already tracks a kart deal in Grafana with `SELECT SUM(QtySold) FROM Sales
  WHERE InvNo = 7157`, and the Item Watch page groups this table by it).
  `CostSold` is the ONLY cost-of-goods column anywhere in this schema — but
  **on THIS install it is empty. MEASURED August 2026 via the Item Watch Test
  button: 0 of the 150 items sold in a 30-day window record any cost**, across
  reader aggregates, admissions, merchandise and F&B alike. So **per-item gross
  margin is NOT available at this venue** and no report should promise it. Item
  Watch already handles this correctly — `has_cost` comes back false and the
  margin columns hide themselves rather than printing a fake 100% — and its
  Test button now reports the coverage ratio outright. Do not re-derive this;
  re-run that button if you suspect the POS config changed. If margin is ever
  wanted, a NEW source is needed, not a different read of `Sales` — run the
  Database Explorer's **cost/price probe** (`GET /api/explorer/cost-sources`,
  below) to find out whether one exists on this install. Confirmed codes on this install: **`CatNo 108` = Go
  Karts** (rides post at `AmtSold` 0 — paid at the reader; walk-up cash posts as
  cash), **`CatNo 106` = Beverages**, **`DivNo 808` = "Go Kart Readers"** (the
  aggregated daily dollars spent at the kart readers — the go-kart sales figure
  on the Labor page). Category/division NAMES are discovered live via
  `INFORMATION_SCHEMA` (a `%Cat%`/`%Div%` table with an int No column + a text
  Name column), because the lookup table's name varies by install.
- **`ReaderDevices` (CONFIRMED):** maps `rdrkey` → reader/game by name. The join
  used to attribute `PlayerCardTrans` TransType-1 plays and the reader feed to
  games (`normReaderName` in the scheduler backfill).
- **Ticket attribution gotcha (CONFIRMED):** in the card ledger, tickets exist
  ONLY at the division grain — every `ValueNo 3` credit has `rdrkey` 0. This is
  why Ticket Trends is by-area and why the per-game backfill leaves `tickets` 0.
  `ReaderTickets` (`rdrKey`, `ShiftDate`, `TicketsDispensed`, `TicketsOnCard`)
  looked like a counterexample but **is EMPTY — the per-year sweep returns zero
  rows.** The table exists in the schema and was never populated. One possible
  early-era exception remains, see `ReaderTransSummary` below.
- **PER-MACHINE IDENTITY DIES AT THE END OF 2012 (CONFIRMED, four independent
  sources).** This is the single most important fact about per-game history on
  this install, and it is now corroborated rather than inferred. Measured per
  year:
  | Source | Key populated | Then |
  |---|---|---|
  | `PlayerCardTrans.rdrkey` | 2005-2011 100%, 2012 76% | 0 from 2013 |
  | `ReaderTransSummary.rdrKey` | 2005-2012 (~90%+) | 0 from 2013 |
  | `CardActivity.rdrkey` | 2005-2012 (~54-78%) | 0 from 2013 |
  | `ReaderSwipes.rdrKey` | 2007-2012 100% | table ENDS after 2012 |
  Four tables, written by different subsystems, all stop recording machine
  identity at the same boundary — so this is a venue-wide configuration change
  in 2012, not a gap in any one table. **Per-game history for 2013 onward is
  not recoverable via reader key from any of these.** Do not re-litigate this
  without new evidence; do NOT read it as "the right table hasn't been found".
  **The search is now EXHAUSTIVE, not pattern-based** — a full catalog sweep
  (`sys.tables` + `sys.columns`, every table over 50k rows with its complete
  column list, 67 tables) found exactly FIVE reader/device columns in the whole
  database: the four above plus `ReaderSales.rdrKey` (also 0 after 2012). No
  other table carries one. `StationNo` (on `Receipt`, `Till`, `AuditLog`,
  `CashierSales`) is a POS register, not a machine. Two further columns on the
  play rows themselves were tested and are NOT identity: `rdrSeq` is 0 in every
  year sampled, and `UseSeq` is a per-CARD use counter (consecutive per
  CardNumber — 1,378 distinct values in 2013, nothing like a machine count).
  **The Kiosoft/CenterEdge cutover does NOT restore it**: June 2026 has 70,840
  `TransType 1` plays and ZERO with `rdrkey`, so MSSQL will never be a per-game
  source going forward either — the app's own API feed (`game_daily_stats`) is,
  and it accumulates from install date. `ReaderDevices` still lists all ~110
  machines individually (`Ms. Pac-Man`, `Ice Ball 1`-`4`, `crane 1 bling`,
  `1Batting Cage 1`-`6`, each with `rdrClass`, `Retired`, `DeviceId` GUID), so
  the machines were never anonymous — they simply stopped stamping identity onto
  transactions in 2012.
  **Per-ATTRACTION history for 2013-2026 DOES exist — see `ReaderSales.InvNo`
  below.** That is the answer to "which games", at attraction grain.
- **`ReaderSales` — the per-ATTRACTION source for 2013-2026 (CONFIRMED).**
  ~20.9M rows; `DataKey`, `ShiftDate`, `rdrKey`, `DivNo`, `SaleAmount`,
  `TaxAmount`, **`InvNo`**. `rdrKey` is dead here like everywhere else, but
  **`InvNo` (the inventory item = the attraction sold at the reader) is
  populated on 100% of rows in every year sampled** (427,060/427,060 in 2013;
  same through 2025), 15-20 distinct items, with real dollars. This is how
  per-attraction history survives the 2012 machine-identity loss — the identity
  moved from a reader column to a product column in a different table.
  - **`ShiftDate` carries the HOUR here** (`2019-06-01 08:00:00`,
    `09:00:00`, …) — do NOT assume it behaves like `Sales.ShiftDate`, which is
    a midnight-stamped business day. Hour-of-day is real for 2013-2026.
  - **`DivNo` is populated** (801, 803, 808, 811 alongside 1) — unlike
    `PlayerCardTrans`, whose `DivNo` collapsed to 1 for everything in the
    mid-2010s (June 2015: all 112,750 plays on DivNo 1).
  - Names via `Inventory.Description`. Measured 2025: `Redemption Game Readers`
    469,211 plays/$777,578; `Merchandise` 103,657/$156,903; `Video Game`
    83,306/$260,343; `Go Kart` 54,576/$458,591; `Driving Range` 28,278/$369,613;
    `Laser Tag` 24,160/$176,967; plus Novelty, Free Fall, Batting Cage, Family
    Swing, Dragon Coaster, Air Hockey, Zipline, Rockwall, Ballocity, Mini Golf.
    ~938K plays / ~$2.79M for the year.
  - Grain is attraction CATEGORY, not machine — "Redemption Game Readers" is
    every redemption cabinet in one bucket. Individual games are not separable.
  - 2013 looks anomalous (3x the rows of other years, $152 total) — `SaleAmount`
    was likely not populated yet, same pattern as early-era `DollarAmount`.
    Confirm the money start year before promising a date range.
- **`ReaderTransSummary` — the best 2005-2012 per-game source (CONFIRMED).**
  `ShiftDate`, `rdrKey`, `ValueNo`, `Quantity`, `TotalAmount`, `Dollars`;
  pre-aggregated per (day, reader, ValueNo). Better than `PlayerCardTrans` for a
  per-game backfill of that era on every axis: it is ~25x smaller (~196K rows
  total for 2005-2012, vs ~5M plays), it is already in the shape
  `game_daily_stats` wants, and **it carries `Dollars` where the early-era
  `PlayerCardTrans.DollarAmount` is 0** ($891K in 2006 rising to $3.87M in
  2011) — so per-game MONEY is available for 2006-2012 even though the raw
  ledger has none. `ValueNo` semantics here: **1** = plays/value (22.19M qty,
  $88.09M, 2005-2026), **2** = 2.33M qty / $27.6K, **3** = tickets (951,642 qty,
  $0 — matching the ledger's ValueNo-3-is-tickets convention).
  CAUTION on ValueNo 3: only 4,124 rows across two decades, which is sparse for
  a ticket arcade — per-game tickets for the early era are PLAUSIBLE but the
  density has not been verified. Measure ValueNo-3-with-`rdrKey` per year before
  relying on it. `Dollars` in 2005 is 0; money starts 2006.
  `StationNo` is NOT a machine identifier — it is a POS register (it appears on
  `Till`, `TaxDocuments`, `RedeemScreens`, `PosKeys`…). Do not treat it as one.

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
  - `TimeSales` (~6.6M, carries `InvNo` AND `HourNo` — real hour-of-day for
    timed attractions); `SubCatSales` (~1.4M) F&B sub-category drill-down;
    `TicketTrans` (~17K) printed vouchers.

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
  permissions are CODE (`Auth::PERMISSIONS` catalog — 20 keys incl.
  view_revenue, manual_control, reader_groups_manage, promotions_manage,
  items_manage, data_explorer). A
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
