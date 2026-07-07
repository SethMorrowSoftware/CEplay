# Demo Design Notes

How this preview is wired together, what's faked, and what to do if a
production change breaks the demo.

## Goal

Show the full UI surface of the pause-group automation app with **no
install** — no PHP, no SQLite, no CenterEdge credentials, no `at(1)`,
no cron. A reviewer should be able to clone the repo, double-click an
HTML file, and see every page including modals.

## Strategy

The production app is already a thin SPA: every screen lives in
`public/js/*.js` and talks to PHP only through a tiny `API` helper
(`public/js/api.js`). Replace that one file and the entire UI runs
without a backend.

```
              ┌─────────────────────────┐
              │  public/js/app.js       │
              │  public/js/dashboard.js │
              │  public/js/groups.js    │
              │  …                      │  ← copied verbatim into demo/js/
              │   call → window.API.X() │
              └────────────┬────────────┘
                           │
        ┌──────────────────┴───────────────────┐
        │                                      │
   ┌────▼─────────┐                  ┌─────────▼─────────┐
   │ public/js/   │   replaced       │ demo/js/          │
   │ api.js       │   by             │ mock-api.js       │
   │ (real fetch) │ ───────────────► │ (in-memory store) │
   └──────────────┘                  └─────────┬─────────┘
                                               │
                                     reads from │
                                     mutates    │
                                               ▼
                                     ┌──────────────────┐
                                     │ demo/js/         │
                                     │ mock-data.js     │
                                     │ (spoof fixtures) │
                                     └──────────────────┘
```

`mock-api.js` is a route table whose entries match `(method, regex)`
pairs against the path the UI requests, then call a handler that
reads/writes `window.MockData`. It returns **the same JSON shape** the
real PHP handlers return, so the front-end can't tell the difference.

## What's faked vs. what's real

| Concern                | Real backend                                    | Demo                                                     |
|------------------------|-------------------------------------------------|----------------------------------------------------------|
| Authentication         | bcrypt + sessions, CSRF                         | Any non-empty credentials accepted; CSRF is a constant   |
| Storage                | SQLite (`data/`), backed by SPA polling         | In-memory JS object, lost on refresh                     |
| CenterEdge API         | Live calls + token caching                      | Synthesized responses, latency simulated 60–220 ms       |
| Cron / `at` jobs       | Drives schedule transitions                     | Heartbeats are reported as healthy in `/api/health`      |
| Tier 1/2 enforcement   | Runs on every authed request                    | Skipped — no scheduler to enforce against                |
| Logs                   | Append-only SQLite table                        | Pre-seeded ~480 entries; manual actions append new rows  |
| Card transactions      | CenterEdge per-card history                     | One sample card (`80009100`) with 220 fake transactions  |

## Spoof data summary

`demo/js/mock-data.js` ships with a venue-realistic dataset, sized so
the new pagination + search controls have something meaningful to drive:

- **130 games** across 9 categories (Redemption, Video, Pinball, Sports
  & Skill, Kids Zone, Virtual Reality, Driving & Racing, Music & Rhythm,
  Light Gun) with a realistic mix of `enabled` / `paused` /
  `outOfService` — matches the scale of a real Castle Fun Center floor
  where 100+ machines is normal.
- **28 kiosks** spread across Front Desk, Arcade Refill, Redemption,
  Birthday Wing, Mobile Carts, Café, VR Lobby, Outdoor Patio, Office,
  and Storage. Three are legacy units with `operationStatus: null` to
  exercise the "kiosk reports unknown status — cannot pause" path.
- **21 pause groups**, mixing categories, individual games, and kiosks.
  Covers per-section groups (Pinball Row, Driving Lineup, etc.), bank
  groups (all Skee-Ball lanes, all Mini-Bowling lanes), kiosk-only
  groups (Café Kiosks, Mobile Refill Carts), and inactive groups
  (Birthday Party Wing, Storm Day Lockdown).
- **111 schedules** spanning realistic operating hours, plus a nightly
  closing-time maintenance window for the whole venue.
- **53 overrides** total — 3 active right now, 10 upcoming, 40 expired.
  The expired list is intentionally large so the new per-section
  pagination visibly engages.
- **~900 weighted play transactions** spread over the last 7 days
  (denser in the last hour) for the live feed and ticket-monitoring
  widgets. Game weights produce realistic "hot games" — Skee-Ball lanes,
  Big Bass Wheel, Mario Kart cabinets, etc. dominate, with occasional
  jackpot payouts. Aggregations match the real
  `/api/games/transactions/stats` shape so the dashboard tiles, table,
  group cards, and game-detail modal all show consistent ticket totals.
- **One sample customer card** (`80009100`) with balance, time plays,
  privileges, a PIN of `1234`, and **220 transactions** — enough to
  demonstrate the card-history pagination.
- **~480 action-log entries** spread across the last several days, with
  a realistic ~6% failure rate.
- **4 admin users**, one inactive.
- **Health response** that includes a sample security warning so the
  dashboard's "warnings banner" is demonstrated.

Every timestamp is computed from `Date.now()` at page load, so the
demo never shows stale relative dates.

## Mock API route table

Each entry is `[METHOD, regex, handler]`. Lookup is first-match-wins,
so put more-specific routes before generic ones (`groups/\d+/pause`
before `groups/\d+`).

```js
// demo/js/mock-api.js
['GET',  /^groups$/,                  listGroups],
['POST', /^groups$/,                  createGroup],
['GET',  /^groups\/\d+$/,             getGroupDetail],
['POST', /^groups\/\d+\/pause$/,      pauseGroup],
['POST', /^groups\/\d+\/unpause$/,    unpauseGroup],
…
```

Adding a new endpoint:

1. Add the route to the table in `mock-api.js`.
2. If you need new fixture data, extend `mock-data.js` and re-export
   it on `window.MockData`.
3. Reload the page — there's no build step.

## Stateful behavior

The mock keeps state in memory so the demo *feels* live:

- Pause/Unpause flips the underlying game/kiosk records and writes a
  manual-override stamp; the dashboard's "Manually Paused / Resume
  Schedule" banner appears.
- Group/schedule/override CRUD updates the store and shows up
  immediately in lists.
- Group deletion cascades to schedules and overrides for that group.
- The action log gets a real entry every time a manual action runs.

Refresh the page (or click **Reset demo** in the banner) to start over.

## Out-of-scope

The demo intentionally does **not** simulate:

- Schedule transitions firing in real time (cron + `at` would do this
  in production). The "next transition at HH:MM" indicator in the group
  cards is shown but no flip happens.
- Override expiry firing in real time. The countdown ticks down, but
  expiry is not auto-applied — the production server-side enforcer
  handles this.
- The CenterEdge connection-test response varying by input — it always
  returns success.
- Multi-user sessions / RBAC. There is one user.

These are runtime behaviors of the scheduler (`lib/scheduler.php` +
`cron_watchdog.php`); they are **not** part of the front-end surface
this demo is meant to showcase.

## Keeping the demo accurate

The UI modules under `demo/js/` are byte-identical to those in
`public/js/`. After a production UI change, re-copy them:

```bash
cp public/css/style.css demo/css/style.css
cp public/js/{app,login,dashboard,games,cards,groups,kiosks,schedules,overrides,logs,settings,analytics}.js \
   demo/js/
```

If the change introduced a **new** API call:

1. Add a matching route to `demo/js/mock-api.js`.
2. If the response shape uses new fields, extend the relevant fixture
   in `demo/js/mock-data.js`.
3. Visit the affected page — toast errors will be visible if a route
   is missing.

A 30-second smoke test using Playwright lives in this repo's history;
re-create it locally if you want to validate every route after a sync.
