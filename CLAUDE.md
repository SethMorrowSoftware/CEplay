# Castle Fun Center - Pause Group Automation

## Project Overview
Self-hosted, framework-free operations platform for Castle Fun Center (arcade/entertainment venue). Automates game scheduling (pause/unpause) via recurring time windows, overrides, and multi-tier enforcement. Includes modules for attractions, maintenance, parties, announcements, analytics, and card operations.

## Architecture
- **Backend:** Pure PHP 7.4+ (no Composer, no frameworks). SQLite database with WAL mode.
- **Frontend:** Vanilla JavaScript SPA with hash-based routing. No build step, no npm.
- **External API:** CenterEdge Card System API (REST, SHA-1 auth, bearer token caching).

## Key Files
- `index.php` — Main router: SPA shell, API dispatch, safety nets (Tier 1/2 enforcement)
- `config.php` — Constants: encryption key, DB path, session lifetime, API timeouts
- `cron.php` — Daily cron (00:05): game sync, plan day, queue `at` jobs, purge old data
- `cron_watchdog.php` — Per-minute watchdog: missed actions, state enforcement, re-queue
- `run_action.php` — Single-action executor invoked by `at` jobs
- `lib/scheduler.php` — Core scheduling engine (plan, execute, enforce, resolve conflicts)
- `lib/centeredge_client.php` — CenterEdge API client (auth, games, cards, pagination, retry)
- `lib/db.php` — SQLite singleton, schema init, query helpers (`:p0` positional params)
- `lib/auth.php` — Session management (bcrypt, HttpOnly, SameSite, 2h timeout, rate limiting)
- `lib/csrf.php` — CSRF token generation + timing-safe validation
- `lib/crypto.php` — AES-256-CBC encrypt-then-MAC (HMAC-SHA256, backward-compatible)
- `lib/validator.php` — Input validation (strings, ints, dates, times, enums, arrays)

## Directory Layout
```
api/          — 19 API endpoint handlers (auth, groups, schedules, overrides, cards, etc.)
lib/          — 7 core libraries
public/js/    — 19 vanilla JS modules (~6250 lines)
public/css/   — Dark/light theme stylesheet
data/         — Runtime: SQLite DB, locks, heartbeats, logs (gitignored)
docs/         — Internal docs: security audit, CenterEdge API reference (OpenAPI spec)
```

## Development Notes

### Database
- SQLite with WAL mode, foreign keys enabled, 30s busy timeout
- Parameterized queries use positional `:p0, :p1, ...` placeholders
- Schema auto-initializes on first DB access (CREATE TABLE IF NOT EXISTS)
- Migrations via ALTER TABLE in try/catch for backward compat

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
- Concurrency via file lock (flock). Different retry strategies per script.

### Security
- Passwords: bcrypt cost 12, auto-rehash
- CSRF: 256-bit token, `X-CSRF-Token` header, timing-safe validation
- Encryption at rest: AES-256-CBC + HMAC-SHA256 for API credentials
- CLI-only guards on cron scripts
- Input validation via Validator class (throws RuntimeException)

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
