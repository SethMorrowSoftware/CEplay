# Castle Fun Center — UI Demo

Self-contained, install-free preview of the pause-group automation
front-end. All data is fake and lives in browser memory; nothing
is written to disk and no CenterEdge API is contacted.

The demo is meant for **showing off the UI** — to stakeholders,
new staff, or designers — without standing up PHP, SQLite, `at(1)`,
cron, or a real CenterEdge endpoint.

## Run it

The demo is just static HTML/CSS/JS. Pick any one of these:

```bash
# Option 1 — PHP built-in server (already on your dev box)
php -S localhost:8000 -t demo
# then visit http://localhost:8000/

# Option 2 — Python (bundled with macOS / most Linux)
cd demo && python3 -m http.server 8000
# then visit http://localhost:8000/

# Option 3 — Node http-server (npm i -g http-server)
http-server demo -p 8000
# then visit http://localhost:8000/

# Option 4 — open the file directly (works because the demo never
# uses fetch(); the API layer is mocked in JS).
xdg-open demo/index.html      # Linux
open demo/index.html          # macOS
start demo\index.html         # Windows
```

You will land on the dashboard signed in as **Manager Alex**. Use the
banner button at the top to jump to the login screen if you want to
see that flow — any non-empty username/password is accepted.

## What's covered

Every screen the real app exposes is reachable in the demo:

| Route          | What you see                                                 |
|----------------|--------------------------------------------------------------|
| `#/login`      | Sign-in card (any credentials accepted)                      |
| `#/dashboard`  | Game-state stat tiles, **venue-wide ticket summary** (tickets today / plays today / avg per play / 7-day tickets), **searchable + paginated live play feed**, top games widget with time-window selector, group controls with per-group ticket totals, game status table with search/filter/pagination, active overrides |
| `#/games`      | Games directory only — search/filter/sort/paginate across 130 demo games, click any row for the per-game detail modal (status, RPC actions, recent plays). Live activity widgets now live on the Dashboard. |
| `#/cards`      | Card lookup (try `80009100`), balance, time plays, history, PIN modal |
| `#/groups`     | **Searchable + paginated** group list with status filter and quick pause/unpause |
| `#/groups/:id` | Group editor — categories, game picker (paginated), kiosk picker |
| `#/groups/new` | New group form                                               |
| `#/kiosks`     | **Searchable + paginated** kiosk list with status filter, pause / unpause / out-of-service / actions |
| `#/schedules`  | Weekly grid + **searchable, paginated** schedule list with day & status filters |
| `#/overrides`  | Active / Upcoming / Expired sections (each paginated independently) with shared search + action filter |
| `#/analytics`  | KPI cards (plays / tickets / cash / unique cards with trend deltas), fleet posture strip, daily activity combo chart, hour-of-day & day-of-week bars, top-games leaderboards, revenue/source/outcome donuts, recent automation failures table |
| `#/logs`       | Filtered, paginated action log                               |
| `#/settings`   | API config, timezone, admin users                            |

### Demo dataset size

The mock store is sized to match a real Castle Fun Center floor (well over
100 machines), so the new search + pagination controls have something to
chew through:

| Entity        | Count |
|---------------|------:|
| Games         | 130   |
| Categories    | 9     |
| Kiosks        | 28    |
| Pause groups  | 21    |
| Schedules     | 111   |
| Overrides     | 53    |
| Action log    | 480   |
| Recent plays  | 900   |
| Card txns     | 220   |

## What you can do

- **Pause / Unpause groups** — flips game and kiosk state in memory; the
  dashboard's optimistic-update path runs end-to-end. Manual override
  banners and "Resume Schedule" buttons appear as expected.
- **Edit groups** — add/remove games, kiosks, categories. Persists for
  the page session.
- **Create / edit / delete schedules** — appears in the weekly grid
  immediately.
- **Create / delete overrides** — buckets correctly into Active /
  Upcoming / Expired based on the chosen times.
- **Toggle dark/light theme** — same toggle as production.
- **Test connection** — the Settings page's "Test Connection" returns a
  fake "connected" response.
- **Refresh feed / Sync games / Sync kiosks** — return success quickly.

Anything that would touch the real CenterEdge API is short-circuited by
the mock and returns a sensible canned response.

## Reset

The banner has a **Reset demo** button (it just reloads the page).
All state is rebuilt from `js/mock-data.js` on every load.

## File layout

```
demo/
├── README.md           # this file
├── DESIGN.md           # what's mocked, why, and how to extend
├── index.html          # SPA shell + demo banner
├── css/
│   ├── style.css       # ← flat concatenation of public/css/**.css (autogenerated)
│   ├── build.sh        # regenerator — run after touching anything in public/css/
│   └── demo.css        # demo-banner styles
└── js/
    ├── mock-data.js    # all spoof data (games, groups, schedules, overrides, …)
    ├── mock-api.js     # in-memory replacement for public/js/api.js
    ├── demo-controls.js# wires the banner buttons and adds the DEMO tag
    │
    ├── app.js          # ← copy of public/js/app.js
    ├── login.js        # ← copy of public/js/login.js
    ├── dashboard.js    # ← copy of public/js/dashboard.js
    ├── games.js        # ← copy of public/js/games.js
    ├── cards.js        # ← copy of public/js/cards.js
    ├── groups.js       # ← copy of public/js/groups.js
    ├── kiosks.js       # ← copy of public/js/kiosks.js
    ├── schedules.js    # ← copy of public/js/schedules.js
    ├── overrides.js    # ← copy of public/js/overrides.js
    ├── logs.js         # ← copy of public/js/logs.js
    ├── settings.js     # ← copy of public/js/settings.js
    └── analytics.js    # ← copy of public/js/analytics.js
```

> The analytics page additionally pulls Chart.js 4.4 from
> `cdn.jsdelivr.net`. If you're running the demo from a sandbox that
> blocks third-party CDNs, the page will toast a load failure but the
> rest of the demo keeps working.

The UI modules under `demo/js/` are byte-identical copies of the
production files. Only `mock-api.js`, `mock-data.js`, and
`demo-controls.js` are demo-specific.

## Keeping it in sync with production

The production stylesheet is now a tree of ~40 modular files plus an
`@import` manifest at `public/css/style.css`. The demo flattens that
tree into a single file so it can be opened with a `file://` URL.

```bash
# 1. Re-flatten the modular CSS (autogenerates demo/css/style.css)
./demo/css/build.sh

# 2. Re-copy the JS modules (these are still byte-identical with prod)
cp public/js/{app,login,dashboard,games,cards,groups,kiosks,schedules,overrides,logs,settings,analytics}.js demo/js/
```

If a production module starts hitting a new API endpoint, add a route
to `demo/js/mock-api.js`. See `DESIGN.md` for the route table format.
