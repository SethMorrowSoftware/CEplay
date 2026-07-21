# Migrating pause-groups from Fedora CoreOS to Ubuntu Server

**Status: planning document — no changes have been made yet.**

This document describes everything that must change (and everything that
deliberately does not) to move the pause-groups app from its current Fedora
CoreOS (FCOS) host to an Ubuntu Server host. It is the companion — and
eventual replacement — for `INSTALL-FCOS.md` and `DEPLOY-CEPLAY.md` on the
new box.

How to read it:

- **Sections 1–5** — the big picture: what exists today, what the target
  looks like, and the decisions to make before touching anything.
- **Section 6** — the component-by-component change list (the "EVERYTHING
  that needs to change" inventory).
- **Sections 7–10** — what stays the same, the migration-day runbook, the
  verification checklist, and an operator command translation table.
- **Sections 11–12** — the concrete follow-up work items and the open
  decisions that need an answer before implementation starts.

---

## 1. Executive summary

The move to Ubuntu is overwhelmingly a **simplification**, not a rewrite:

- **Zero application code changes are required.** The PHP app is
  path-relative (`__DIR__`), environment-driven (`PG_ENCRYPTION_KEY`),
  auto-detects its base path, and already contains a portable fallback for
  every host capability it uses (most notably `at`). The README's own
  "Hosting Compatibility" section was written for exactly this kind of host
  ("VPS / dedicated Linux — cron jobs plus at/atrm").
- **Almost all of the FCOS deployment complexity exists to work around FCOS
  itself** — an immutable OS with no package manager, automatic OS rebuilds
  that wipe the container image cache, and a shared containerized Nginx.
  On Ubuntu, PHP-FPM, Nginx, the MSSQL driver, `at`, and cron are ordinary
  apt packages and native systemd units. The container images, the saved
  image tar, the `podman run` wrappers, and the "survive the OS rebuild"
  machinery are all deleted, not ported.
- **The data that must move is small and precise:** the SQLite database
  (`data/pause_groups.db`) and the encryption key file (`.env`). Everything
  else is reproducible. Losing the `.env` key would make the stored
  CenterEdge and MSSQL credentials undecryptable — it is the single most
  important file in the migration.
- **The biggest real risks are not code** — they are environmental details:
  delivering `PG_ENCRYPTION_KEY` to native PHP-FPM (which strips
  environment variables by default), carrying over the PHP-FPM worker-pool
  sizing that was baked into the container image (Ubuntu's default of 5
  workers will freeze the whole app under slow MSSQL report queries),
  the `www-data` entry in Ubuntu's default `/etc/at.deny`, and system
  timezone alignment for `at`/timer firing. Each is called out below.

---

## 2. Scope and assumptions

**In scope:** the pause-groups PHP application, its SQLite data, its
background jobs (daily planner, per-minute watchdog, `at`-queued actions),
its Nginx vhost, its TLS certificate/DNS, its update/rollback workflow, and
the optional Slack alerts service.

**Out of scope but living on the same FCOS box today** (decide their fate
separately — see Open Decisions, §12):

| Service on the FCOS box | Notes |
|---|---|
| Grafana (container, port 3000) | If the Ubuntu box replaces the whole server, Grafana needs its own migration (apt repo or container). If the FCOS box stays for Grafana, only the pause-groups vhost moves. |
| Tailscale (container) | Used for SSH/admin access. Native `tailscale` via apt on Ubuntu if the box is replaced. |
| Other Nginx vhosts / static `webroot/` sites | The shared containerized Nginx serves more than this app. Inventory `/var/persist/nginx.conf` before decommissioning anything. |
| Let's Encrypt certs (`/var/persist/letsencrypt/`) | Covers other sites too, not just `ceplay.thecastlefuncenter.com`. |

**Assumptions made throughout** (correct them if wrong):

1. The Ubuntu box is a fresh install of **Ubuntu Server 24.04 LTS** (see §5
   for the version rationale) on the same network segment as the CenterEdge
   server — it can reach the Card System API over HTTPS and the MSSQL
   server on TCP 1433 exactly like the FCOS VM does.
2. The app will be the primary site on the new box (dedicated vhost at `/`,
   with the `ceplay.thecastlefuncenter.com` name and/or the LAN IP). If it
   must instead coexist under `/ceplay` beside other sites again, the
   subpath location blocks in `DEPLOY-CEPLAY.md` port over with only path
   edits (see §6.3).
3. We migrate the existing database — we do **not** re-run `install.php`.
   The installer is only for fresh installs; a migration is a file copy.

---

## 3. What the FCOS deployment looks like today

Inventory of every deployment artifact currently in play, and why it
exists. This is the list we are porting *from*:

| # | Artifact (FCOS) | Purpose | Exists because of FCOS? |
|---|---|---|---|
| 1 | `/var/persist/pause-groups/` | Installed app copy (the live web root) | Path only — `/var/persist` is the FCOS "survives OS rebuilds" VHDX |
| 2 | `/var/persist/pause-groups-src/` | Git clone that `update.sh` pulls into, then rsyncs to #1 | No — the two-copy update flow is worth keeping |
| 3 | `/var/persist/pause-groups/.env` | `PG_ENCRYPTION_KEY` (AES-256 key) + `PG_APP_DEBUG`, mode 600 | No — moves as-is |
| 4 | `/var/persist/pause-groups/data/` | SQLite DB (WAL), scheduler lock, heartbeats, `cron.log`/`watchdog.log`, nightly `data/backups/` | No — moves as-is |
| 5 | `/var/persist/pause-groups-backups/` | `setup`/`update-*`/`pre-revert-*` snapshots + `LATEST_MARKER` for `revert.sh` | No — path changes only |
| 6 | `pause-groups-fpm.service` | Runs PHP-FPM as a **podman container** (`php:8.3-fpm` or the MSSQL overlay), `--network host`, listens on 127.0.0.1:9000, workers run as uid 33 | **Yes — replaced by native `php8.3-fpm`** |
| 7 | `deploy/Containerfile.mssql` + `localhost/pause-groups-fpm-mssql:latest` | Overlay image adding `pdo_dblib` (FreeTDS) **and** a PHP-FPM pool bump (`pm.max_children = 24` etc.) | **Yes — replaced by `apt install php8.3-sybase` + a native pool drop-in** |
| 8 | `/var/persist/pause-groups/php-fpm-mssql.tar` + `ExecStartPre podman load` | Podman's image cache does not survive FCOS OS rebuilds; the unit reloads the image from this tar | **Yes — deleted entirely** |
| 9 | `deploy/write-fpm-unit.sh` | Single source of truth for the container-wrapping FPM unit | **Yes — obsolete** (native unit ships with the php-fpm package) |
| 10 | `pause-groups-watchdog.timer/.service` | Every minute: `podman run … php cron_watchdog.php` as uid 33, output appended to `data/watchdog.log`, `Persistent=true` | Podman wrapper only — timer concept stays |
| 11 | `pause-groups-daily.timer/.service` | Daily 00:05: `podman run … php cron.php`, same pattern | Same |
| 12 | Shared containerized Nginx (`systemd-nginx`), config at `/var/persist/nginx.conf`, tested/reloaded via `podman exec` | Serves this vhost plus Grafana proxy and other sites; the pause-groups `server {}` block was pasted in manually | **Yes — replaced by native nginx** with a standard `sites-available` vhost |
| 13 | Certbot run as a podman container writing `/var/persist/letsencrypt` | TLS issuance for `ceplay.thecastlefuncenter.com` | **Yes — replaced by native certbot + auto-renew timer** |
| 14 | `setup-fcos.sh` | 12-step installer: copies files, generates key, pulls/builds images, runs installer, writes units/timers, generates the Nginx snippet | **Yes — replaced by a much shorter Ubuntu setup script/runbook** |
| 15 | `update.sh` | Backup → git pull → rsync → remove installers → migrate DB → rebuild/persist overlay image → run pending MSSQL backfills → restart FPM → health check | Port it — drop every podman step (§6.11) |
| 16 | `revert.sh` | Roll back DB + key + code to the pre-update snapshot | Port it — path/service-name edits only |
| 17 | SELinux `:z` volume labels on every mount | FCOS runs SELinux enforcing; podman relabels mounted volumes | **Yes — no equivalent needed** (Ubuntu uses AppArmor; see §6.8) |
| 18 | `at` **unavailable** (not installed in the PHP container) | `Scheduler::hasAtScheduler()` returns false → the app runs in watchdog-fallback mode: due actions execute within ≤60 s | On Ubuntu, `at` becomes genuinely available for exact-minute execution — a behavior decision, see §6.5 |
| 19 | Zincati auto OS rebuilds (Mon/Tue 2:30 AM) | The reason for #8 and the tar dance | **Gone — replaced by `unattended-upgrades`** (§6.9) |
| 20 | `alerts/` Slack alerter (systemd unit, Python, stdlib-only) | Already written for (K)ubuntu; runs wherever it was installed | No change — consolidation opportunity (§6.12) |

Three FCOS constraints shaped everything above, and none of them exist on
Ubuntu:

1. **No package manager for persistent installs** → everything ran in
   containers. Ubuntu: apt.
2. **Automatic OS rebuilds wipe `/var/lib/containers`** → the overlay image
   had to be tar-saved to `/var/persist` and reloaded by `ExecStartPre`.
   Ubuntu: packages persist; delete the machinery.
3. **A shared production Nginx that must never break** → config generated
   to a snippet file and pasted by hand, tested/reloaded through
   `podman exec`. Ubuntu (dedicated box): a normal
   `sites-available`/`sites-enabled` vhost managed directly.

---

## 4. Target architecture on Ubuntu

**Decision 1 — native packages vs. keeping containers.** Podman/Docker run
fine on Ubuntu, so the current unit files *could* be ported nearly
unchanged. **Recommendation: go native.** Rationale:

- The container layer existed to work around FCOS immutability, which no
  longer applies. Keeping it keeps the image-persistence problem (now
  self-inflicted), the overlay build on every update, and the uid-mapping
  and `--network host` subtleties — for no benefit.
- Native is the configuration the app documents and tests for elsewhere
  (README "Hosting Compatibility", `docs/MSSQL_DRIVER.md` "Bare-metal / VM
  installs", `install.php`'s own printed cron/chown guidance).
- Native unlocks `at` (exact-minute action execution), simpler backups
  (a real `sqlite3` CLI on the host), and one fewer moving part at 2 AM.

The rest of this document assumes native. If containers are kept anyway,
§6 still applies to paths, Nginx, timers-vs-cron, TLS, timezone, and
firewall — only §6.2/§6.7 (PHP runtime, MSSQL driver) revert to the
existing Containerfile approach with podman installed from apt.

Target layout:

```
Ubuntu Server 24.04 LTS
├── nginx (apt)                     — vhost for the app, ports 80/443
├── php8.3-fpm (apt)                — unix socket or 127.0.0.1:9000
│     └── pool drop-in: worker sizing + PG_ENCRYPTION_KEY delivery
├── php8.3-cli + sqlite3/curl/mbstring/sybase extensions (apt)
├── at + atd (apt, optional but recommended)      — exact-minute actions
├── systemd timers (native ExecStart=/usr/bin/php …)
│     ├── pause-groups-watchdog     — every minute
│     └── pause-groups-daily        — 00:05 local
├── certbot + auto-renew timer (apt)
├── /var/www/pause-groups           — installed app  (was /var/persist/pause-groups)
├── /var/www/pause-groups-src       — git clone      (was /var/persist/pause-groups-src)
├── /var/www/pause-groups-backups   — update/revert snapshots
└── optional: ceplay-game-alerts.service (alerts/, unchanged)
```

---

## 5. OS version and package requirements

**Decision 2 — Ubuntu release.** Recommendation: **Ubuntu Server 24.04 LTS
(Noble)**. Its stock PHP is **8.3** — the exact major.minor the app runs
today in the `php:8.3-fpm` container, so runtime parity is perfect and no
third-party PPA is needed. Supported until 2029.

- Ubuntu 22.04 LTS also works (PHP 8.1; the app's floor is 7.4) but gives
  up version parity, or requires the Ondřej Surý PPA to get 8.3 — an extra
  third-party dependency. Prefer 24.04.
- If a newer LTS exists at migration time, verify its PHP version is ≥ 8.3
  and re-check the package names below (`php8.3-*` becomes `phpX.Y-*`).

Packages to install (all from stock Ubuntu repos — no PPAs, no Composer,
no npm, matching the app's zero-dependency design):

| Package | Provides | Replaces on FCOS |
|---|---|---|
| `nginx` | Web server | The shared `systemd-nginx` container |
| `php8.3-fpm` | PHP FastCGI service | The `pause-groups-fpm` podman unit |
| `php8.3-cli` | `/usr/bin/php` for cron/at/CLI scripts | `podman run … php` wrappers |
| `php8.3-sqlite3` | `SQLite3` class + `pdo_sqlite` (the app uses the `SQLite3` class directly) | Baked into the container image |
| `php8.3-curl` | CenterEdge API client | Same |
| `php8.3-mbstring` | Installer/runtime string handling | Same |
| *(built-in)* | OpenSSL extension ships in `php8.3-common`/core on Ubuntu — nothing extra to install; verify with `php -m` | Same |
| `php8.3-sybase` | **`pdo_dblib` (FreeTDS)** — the whole MSSQL reporting stack + historical backfills | `deploy/Containerfile.mssql` overlay image + saved tar |
| `freetds-bin` | `tsql` for connection diagnosis (optional but invaluable) | — |
| `at` | Exact-minute action queueing (**optional** — see §6.5) | Not present on FCOS (fallback mode) |
| `sqlite3` | CLI for admin/backup/`VACUUM INTO` (the FCOS host had none — `update.sh` had to run snapshots inside the container) | The container detour |
| `git`, `rsync` | `update.sh` / `revert.sh` workflow | Same tools, host-native |
| `certbot`, `python3-certbot-nginx` | TLS issuance + automatic renewal | The certbot podman container |
| `ufw` (installed by default) | Host firewall | Nothing equivalent configured on FCOS |
| `python3` (installed by default) | Only needed if the Slack alerts service moves here | — |

After installing, verify with `php -m` that all of: `sqlite3`,
`pdo_sqlite`, `curl`, `mbstring`, `openssl`, `pdo_dblib` are present —
the same check `install.php` and `setup-fcos.sh` perform.

---

## 6. Component-by-component change list

### 6.1 Filesystem layout and hardcoded paths

`/var/persist/…` has no meaning on Ubuntu. Recommended mapping:

| FCOS path | Ubuntu path |
|---|---|
| `/var/persist/pause-groups/` (live app) | `/var/www/pause-groups/` |
| `/var/persist/pause-groups-src/` (git clone) | `/var/www/pause-groups-src/` |
| `/var/persist/pause-groups-backups/` | `/var/www/pause-groups-backups/` (or `/var/backups/pause-groups/`) |
| `/var/persist/pause-groups/.env` | `/var/www/pause-groups/.env` (unchanged relative location) |
| `/var/persist/nginx.conf` (shared, hand-edited) | `/etc/nginx/sites-available/pause-groups` + symlink in `sites-enabled` |
| `/var/persist/pause-groups-nginx.conf.snippet` | Obsolete — the vhost is managed directly |
| `/var/persist/letsencrypt/` | `/etc/letsencrypt/` (native certbot default) |
| `/var/persist/pause-groups/php-fpm-mssql.tar` | **Deleted — no equivalent** |

Keeping the app-under-`/var/www` convention is cosmetic; `/opt/pause-groups`
works identically. What matters is updating **every place the old paths are
hardcoded** — the app itself has none (everything is `__DIR__`-relative),
but the deployment tooling has many:

- `setup-fcos.sh` — `INSTALL_DIR`, `DATA_DIR`, `ENV_FILE`, `SNIPPET_FILE`,
  `BACKUP_DIR`, `NGINX_CONF` (top-of-file constants).
- `update.sh` — `INSTALL_DIR`, `SRC_DIR`, `DATA_DIR`, `ENV_FILE`,
  `DB_FILE`, `BACKUP_ROOT`, `LATEST_MARKER`.
- `revert.sh` — the same block of constants.
- `deploy/write-fpm-unit.sh` — obsolete on Ubuntu, but its unit text is
  where the old paths lived in systemd.
- The two timer `.service` files — `ExecStart`, `--env-file`, `-v`, `-w`,
  `StandardOutput=append:` paths.
- The Nginx vhost — `root`, and the `alias` paths if the `/ceplay` subpath
  variant from `DEPLOY-CEPLAY.md` is used.
- Documentation: `INSTALL-FCOS.md`, `DEPLOY-CEPLAY.md`, `README.md` (cron
  examples), `docs/MSSQL_DRIVER.md`, `CLAUDE.md` ("venue server" notes).

The `data/` directory and the two-copy (`-src` clone → rsync → live dir)
update flow are kept as-is, just relocated.

### 6.2 PHP runtime and the PHP-FPM service

**What changes:** the `pause-groups-fpm` podman-wrapper unit, the container
image, the tar-reload `ExecStartPre`, and `deploy/write-fpm-unit.sh` are
all retired. The stock `php8.3-fpm.service` from apt runs instead.
Three things need deliberate configuration on the native side:

**(a) Deliver `PG_ENCRYPTION_KEY` to the web workers — critical.**
On FCOS the container got it from `--env-file ${ENV_FILE}`. Native PHP-FPM
**clears the environment for workers by default** (`clear_env = yes` in the
pool config), so simply setting a variable in the service environment does
not reach PHP. If the key is missing, `config.php` falls back to an empty
key and every stored credential (CenterEdge API, MSSQL connection) fails
HMAC verification — the app runs but Settings/API/reporting silently break.
Two workable patterns (pick one and document it):

1. A pool drop-in (e.g. `/etc/php/8.3/fpm/pool.d/zz-pause-groups.conf`)
   with an `env[PG_ENCRYPTION_KEY] = …` line holding the key. Simple, but
   the key then lives in a second file — keep the drop-in root-only
   readable and remember it in backups/rotation procedures.
2. A systemd drop-in for `php8.3-fpm.service` with
   `EnvironmentFile=/var/www/pause-groups/.env` **plus** `clear_env = no`
   in the pool. This keeps the `.env` file as the single home of the key
   (the format is already `KEY=value`, which systemd reads directly), at
   the cost of workers inheriting the full service environment.

Either way, the same key must also reach every **CLI** execution context —
see §6.4 (timers) and §6.5 (`at`). Manual one-off runs (e.g.
`php run_backfills.php`) must be invoked with the env loaded; today
`--env-file` did that invisibly, on Ubuntu it becomes "export the vars from
`.env` first (e.g. via `systemd-run` with the same EnvironmentFile, or
sourcing it in the shell)" — worth a note in the runbook because forgetting
it produces the same silent decryption failure.

**(b) Carry over the worker-pool sizing — critical.** The overlay image
bakes in a pool drop-in raising `pm.max_children` to 24 (with dynamic
spares and `pm.max_requests = 500`) because the stock default of **5
workers** was observed to deadlock the entire app: a handful of slow MSSQL
report queries occupy all workers and even the SQLite-only pages stop
answering. Ubuntu's default pool has the **same 5-worker ceiling**, so
without this carry-over the migration reintroduces a solved production
outage. Recreate the same `[www]` values from `deploy/Containerfile.mssql`
as a native pool drop-in in `/etc/php/8.3/fpm/pool.d/`.

**(c) Listener: unix socket vs TCP 9000.** The container listened on
`127.0.0.1:9000`; Ubuntu's default pool listens on
`/run/php/php8.3-fpm.sock`. Either works — the recommendation is to adopt
the **socket** (native default, no open port, marginally faster) and set
`fastcgi_pass` in the vhost accordingly. If minimizing config drift matters
more, change the pool's `listen` back to `127.0.0.1:9000` and keep the
vhost line identical to today. Choose one; don't mix.

Also verify, once, that effective `php.ini` values are acceptable
(`php -i` diff against the container): Ubuntu ships production-style
defaults (`display_errors = Off`, `memory_limit = 128M`,
`max_execution_time = 30`). These are compatible with the app, but
year-range MSSQL reports should be exercised during verification since the
FPM/php.ini timeout stack differs slightly from the container's. Note that
Ubuntu's PHP enables OPcache by default — functionally fine, but it makes
"restart PHP-FPM after syncing new code" (already an `update.sh` step)
mandatory rather than optional.

**User/permissions:** a genuine free win — the container ran workers as
uid 33, and uid 33 **is** `www-data` on Ubuntu. Files copied over with
their numeric ownership intact already belong to the right user. The
`chown -R 33:33 data/` in the scripts becomes
`chown -R www-data:www-data`, cosmetically. One improvement worth taking:
the FCOS scripts forced the app tree world-readable (`chmod -R o+rX`)
because the container uid had no host group membership; natively the tree
can be tightened to `root:www-data` with group-read only, and `.env` stays
`600 root:root` (it is read by root-level systemd/FPM master, not by the
workers).

### 6.3 Web server (Nginx)

**What changes:** the vhost moves from a hand-pasted block inside a shared
containerized Nginx (`/var/persist/nginx.conf`, tested and reloaded via
`podman exec systemd-nginx nginx -t / -s reload`) to a normal native vhost
file in `/etc/nginx/sites-available/` with a `sites-enabled` symlink,
tested with `nginx -t` and reloaded with `systemctl reload nginx`.

**What ports over almost verbatim** — the `server {}` block generated by
`setup-fcos.sh` step 10 (and the `/ceplay` alias variant in
`DEPLOY-CEPLAY.md`) is plain Nginx config with nothing FCOS-specific in it.
Keep, with only path edits:

- The **deny rules** — this is the app's second line of defense and must
  not be lost in translation: block `/data/`, `/lib/`, `/docs/`, `/demo/`,
  direct hits on `api/*.php`, `.env`, `config.php`, the cron/CLI scripts
  (`cron.php`, `cron_watchdog.php`, `run_action.php`, `run_backfills.php`,
  `backfill_card_activity.php`), `install.php`, `fresh_install.php`,
  `*.md`, and dotfiles.
- The `/public/` static-asset location with its 1-hour cache headers.
- The front-controller `try_files … /index.php?$query_string` routing.
- The `fastcgi_param REMOTE_ADDR $remote_addr` pass-through. Note the app's
  login rate-limiter (`getClientIp()` in `api/auth.php`) only trusts
  `X-Forwarded-For` when the request peer is loopback — with native Nginx
  talking to FPM on the same host this behaves exactly as today; no change
  needed unless an *additional* reverse proxy is ever added in front.

**What changes in the block:** `root` (new path), `fastcgi_pass` (socket
path if Decision in §6.2(c) goes that way), and `server_name` (see §6.10).
On a dedicated box, also remove/replace the distro `default` site symlink
so this vhost owns port 80/443.

One subtlety that silently disappears: on FCOS, Nginx could only serve
`/public/` assets because the Nginx *container* had `/var/persist` mounted
inside it. Native Nginx just reads the filesystem — nothing to configure,
but it explains why no "mount" step exists in the new world.

If the app must again live under `/ceplay` beside other sites, the
location-block set in `DEPLOY-CEPLAY.md` is the template — same deny rules
and alias approach, only the filesystem paths change.

### 6.4 Background jobs: the two timers

**What changes:** both systemd timers survive conceptually, but their
`.service` halves lose the entire `podman run` wrapper. The native versions
should run `/usr/bin/php cron_watchdog.php` / `/usr/bin/php cron.php` with:

- `User=www-data` (replacing the container's `-u 33:33`) so DB/log file
  ownership stays consistent with the web workers;
- `WorkingDirectory=` the app dir (replacing `-w`);
- `EnvironmentFile=` pointing at `.env` (replacing `--env-file`) — the
  CLI side of the §6.2(a) requirement;
- the same `StandardOutput=append:` redirection to `data/cron.log` /
  `data/watchdog.log` (paths updated) so the in-app log expectations and
  the app's own 512 KB log self-rotation keep working;
- `After=`/dependency lines that referenced `pause-groups-fpm.service`
  updated to `php8.3-fpm.service` (or dropped — the CLI scripts don't
  actually need FPM, only the shared SQLite file).

Keep `Persistent=true` on both timers — it is what guarantees the daily
planner still runs after an overnight outage, and pairs with the app's own
missed-action recovery.

**Alternative:** plain crontab entries, exactly as the README and
`install.php` document (`* * * * *` watchdog, `5 0 * * *` daily, run as
`www-data` with the env sourced). Functionally sufficient, but systemd
timers are recommended to keep `Persistent=true` catch-up, journald
visibility, and `systemctl start pause-groups-watchdog` style manual runs
that the current ops habits (INSTALL-FCOS.md "Quick reference") rely on.
Whichever is chosen, make sure exactly **one** mechanism is active — never
both.

The old unit/timer names can be kept (`pause-groups-watchdog`,
`pause-groups-daily`) so existing operator muscle memory and the
`systemctl list-timers 'pause-groups*'` habit keep working.

### 6.5 `at` scheduling — an intentional behavior decision

Today's behavior on FCOS is a quirk worth understanding before migrating:
`at`/`atrm` do not exist inside the PHP container, so
`Scheduler::hasAtScheduler()` returns false and the app has **always run in
fallback mode** on this host — planned actions sit in `scheduled_actions`
and the per-minute watchdog (plus per-request safety nets) executes them
within ~60 seconds of their scheduled time. This works and is the
documented shared-hosting mode.

On native Ubuntu, `at` can finally work as designed (exact-minute
execution, `run_action.php` invoked by `atd` at the precise time, watchdog
demoted to a safety net). **Decision 3 — enable `at`, or deliberately keep
fallback mode?** Recommendation: enable it (it is the app's designed
precision path and the watchdog still backstops it), but only with the
following understood, because each is a real failure mode:

1. **`/etc/at.deny` blocks `www-data` by default.** Debian/Ubuntu ship an
   `at.deny` that includes `www-data`, and all `at` queueing runs as
   `www-data` (from the daily timer, the watchdog re-queue, and
   web-triggered replans through FPM). Until `www-data` is removed from
   `at.deny` (or an `at.allow` policy is set up), every queue attempt fails
   with a permission error — the app logs it and silently stays in
   fallback mode, which makes this easy to miss. Verify after setup with a
   test job queued as `www-data` and `atq`.
2. **`atd` must be enabled and running** (`at` package installs it;
   confirm it survives reboot). Pending jobs live in
   `/var/spool/cron/atjobs` and survive reboots; jobs whose time passed
   while the box was off run when `atd` starts — and the app's
   missed-action dedup (latest-per-group wins, earlier superseded) already
   handles that burst correctly.
3. **`at` parses times in the system timezone** while the app computes
   `scheduled_time` in its *configured* app timezone (from the DB). These
   must match or every job fires offset by the difference — see §6.6.
4. **`at` jobs inherit the environment captured at queue time.** Queued
   from the timers or FPM (both carrying `PG_ENCRYPTION_KEY` per
   §6.2/§6.4), `run_action.php` gets the key and can decrypt the
   CenterEdge credentials. If the env-delivery plumbing misses a context,
   actions queued from that context fail at execution time — another
   reason §6.2(a) must be done carefully.
5. **Job stdout goes to local mail** (i.e., nowhere, with no MTA
   installed) instead of `watchdog.log`. Not a problem — every action
   outcome is recorded in the DB `action_log` and surfaced in the UI —
   but expect per-action console output to stop appearing in file logs
   once `at` mode is active.

Choosing *not* to install `at` is also legitimate: it reproduces today's
behavior exactly (≤60 s precision), removes items 1–5 entirely, and can be
revisited any time by just installing the package — the app auto-detects
it. If minute-level precision has never been missed in production, this is
the lowest-risk option for migration day; enabling `at` can be a separate
change a week later so any behavior shift is attributable.

### 6.6 System timezone and clock

Two settings on the new box interact with scheduling correctness:

- **System timezone.** The app plans in its configured timezone
  (Settings → timezone, default `America/New_York`), but two host
  mechanisms use the *system* timezone: `OnCalendar=*-*-* 00:05:00` (the
  daily planner trigger) and `at HH:MM` parsing (§6.5). **Check what the
  FCOS box's system timezone actually is** (`timedatectl`) before
  migrating — FCOS defaults to UTC, which would mean the "00:05 nightly"
  cron has actually been firing at 00:05 UTC (≈ 8 PM Eastern). Whatever
  the finding, set the Ubuntu box's timezone deliberately
  (`timedatectl set-timezone America/New_York`) so that (a) the daily plan
  runs shortly after venue midnight as designed, and (b) `at` jobs — if
  enabled — fire at the intended wall-clock minute. If the FCOS box was
  indeed UTC, this migration will *change* (fix) the nightly run time;
  flag it so the shift in `cron.log` timestamps isn't mistaken for a
  problem.
- **NTP.** CenterEdge's SHA-1 auth rejects timestamps skewed more than a
  few minutes (the alerts README documents the ±5-minute failure mode).
  Ubuntu's `systemd-timesyncd` handles this out of the box — just verify
  `timedatectl` shows synchronized time on the new box before cutover.

### 6.7 MSSQL reporting driver (pdo_dblib)

**What changes:** the entire overlay-image pipeline —
`deploy/Containerfile.mssql`, the `podman build` on every
setup/update, the `podman save` to `php-fpm-mssql.tar`, the
`ExecStartPre … podman load` resurrection after OS rebuilds, and
`update.sh`'s `BASE_PHP_IMAGE` detection — collapses into one apt package:
`php8.3-sybase` (plus `freetds-bin` for diagnosis). This is exactly the
path `docs/MSSQL_DRIVER.md` already documents under "Bare-metal / VM
installs: Debian/Ubuntu".

Continuity notes:

- **Stay on `pdo_dblib`/FreeTDS** rather than switching to Microsoft's
  `pdo_sqlsrv`. `lib/mssql_client.php` auto-detects drivers in the order
  sqlsrv → dblib → odbc, so installing sqlsrv would silently change the
  active driver. Production has run for months on dblib; keep parity and
  treat a driver switch as a separate experiment, not part of the
  migration.
- The container's FreeTDS came from Debian bookworm; Ubuntu 24.04's is the
  same generation. Default protocol negotiation has worked unconfigured, so
  expect no `/etc/freetds/freetds.conf` edits — but if the post-migration
  "Test connection" fails at login where the old box worked, a pinned
  `tds version` in `freetds.conf` is the first knob to try.
- The MSSQL **connection settings migrate inside the database** (encrypted
  rows in `api_config`) — nothing to re-enter as long as `.env` moved with
  the DB. The Go-Kart Labor page's **Test connection** button is the
  end-to-end verification (driver present + network + credentials decrypt).
- Network: the container reached the SQL box via `--network host`; native
  PHP just uses the host network. Confirm the new box can reach the SQL
  server on TCP 1433 (and that the SQL server's own firewall allows the new
  box's IP, if it filters by source).
- The one-time historical backfills are flag-guarded in the migrated DB —
  they will **not** re-run after migration (correct behavior; the data is
  already in SQLite). No action needed.

### 6.8 Security model changes

- **SELinux → AppArmor.** All the `:z` volume-label suffixes in the podman
  commands were SELinux relabeling; the concept disappears. Ubuntu's
  AppArmor does not confine nginx or php-fpm by default, so no profiles
  need writing — but if the team later enables strict profiles, remember
  the app needs write access to its `data/` dir and read access to `.env`
  via the FPM master.
- **File permissions** — see §6.2: same uid (33/www-data), with the option
  to tighten the world-readable app tree to group-read.
- **Firewall.** The FCOS setup configured none. Recommendation: enable
  `ufw` with allow rules for SSH (or rely on Tailscale), 80, and 443 only.
  Nothing inbound is needed for MSSQL (the app is the client), and FPM on
  a socket/loopback is unreachable externally by construction.
- **Installer hygiene carries over unchanged:** the new setup must still
  delete `fresh_install.php` (known default password) and `update.sh`'s
  step that strips `install.php`/`fresh_install.php` from the web root
  stays. The Nginx deny rules (§6.3) remain the backstop.
- **App-level security is untouched** — bcrypt, CSRF, session policy,
  login rate limiting, RBAC, and credential encryption are all in the app
  and migrate with it.
- Optional hardening now available natively: the systemd sandboxing options
  already used by the alerts unit (`ProtectSystem=strict`, `PrivateTmp`,
  etc.) could be applied to the two timer services — noted as a follow-up,
  not a migration requirement.

### 6.9 OS updates: Zincati → unattended-upgrades

FCOS rebuilt the whole OS Mon/Tue 2:30 AM, which drove the "will it survive
a rebuild" engineering (persisted image tar, units in `/etc/systemd/system`
overlay, everything under `/var/persist`). On Ubuntu:

- Verify `unattended-upgrades` is enabled for security updates (it is by
  default on modern Ubuntu Server) and decide whether to allow automatic
  reboots for kernel updates (`Automatic-Reboot` with a night-time window,
  e.g. 03:30, after the nightly cron) — or handle reboots manually during
  maintenance. The timers' `Persistent=true` plus the app's missed-action
  logic already make reboots safe whenever they happen.
- Everything else in this category is **deletion**: no image cache to lose,
  no tar to reload, no "Part 5 — What to do after an OS rebuild" runbook.
  The INSTALL-FCOS "survives rebuild?" table has no Ubuntu equivalent
  because everything trivially persists.

### 6.10 TLS, DNS, and external access

- **Certificates.** Replace the podman-run certbot with native `certbot`
  (+ `python3-certbot-nginx`), which installs an automatic renewal timer —
  removing a manual-renewal failure mode that exists today. For the
  migration itself, **re-issuing** the `ceplay.thecastlefuncenter.com`
  certificate on the new box at cutover is simpler and cleaner than
  copying `/var/persist/letsencrypt` (whose `live/` symlink structure is
  easy to corrupt in transit). Re-issuance requires DNS (or the router's
  port-forward) to already point at the new box on port 80 — sequence it
  in the runbook (§8). Copying the cert directory (preserving symlinks,
  e.g. via tar) is the fallback if a zero-gap HTTPS cutover is required.
- **DNS / port forwarding.** Update the `ceplay.thecastlefuncenter.com`
  A record and/or the router port-forward (80/443) from the FCOS box's IP
  to the Ubuntu box's IP at cutover; lower the record TTL beforehand.
  LAN users who reach the app by raw IP will need the new IP — or move
  the old static IP to the new box at cutover, which makes DNS/bookmark
  changes unnecessary (Decision 6, §12).
- **server_name** in the vhost: set to the real hostname/IP (the FCOS
  snippet's `_` catch-all was a shared-server compromise; a dedicated box
  can use both the FQDN and the LAN IP explicitly).
- **Tailscale**, if the new box should be reachable over the tailnet like
  the old one: install the native client and join it; nothing in the app
  cares.

### 6.11 Deployment and maintenance scripts

Disposition of each shipped script, with what its Ubuntu successor must do:

| Script | Disposition | Ubuntu successor's job |
|---|---|---|
| `setup-fcos.sh` | **Replace** with `setup-ubuntu.sh` (or a short runbook — the script shrinks dramatically) | apt-install the package list (§5); create the three directories; generate `.env` if absent (same `openssl rand -hex 32`, mode 600, never rotate on re-run); rsync source → live dir with the same `data/`/`.env` excludes; write the FPM pool drop-in (workers + env, §6.2) and the two native timer units (§6.4); install the vhost + symlink, `nginx -t`, reload; `chown www-data data/`; delete `fresh_install.php`; enable services; verify with the same health checks. Steps that vanish: image pull, extension verification inside an image, overlay build, tar save, unit generation via `write-fpm-unit.sh`, the "paste this snippet by hand" flow. Keep: the DB/key backup-before-anything behavior (step 0), `--reset` semantics, idempotent re-run design. |
| `update.sh` | **Port** (keep the excellent structure: backup → pull → sync → clean → migrate → restart → health-check) | Drop: podman preflight, `BASE_PHP_IMAGE` detection, the whole overlay-build/tar/unit-rewrite step. Change: DB snapshot via the host `sqlite3 … "VACUUM INTO …"` (or host `php -r`, no container detour); migration step runs host `php -r 'require config.php; require lib/db.php; DB::getInstance();'` as `www-data` with the env loaded; backfill seeding runs host `php run_backfills.php`; restart `php8.3-fpm` instead of `pause-groups-fpm`; paths per §6.1. Keep: re-exec-from-temp-copy self-update trick, backup pruning, `LATEST_UPDATE` marker, uncommitted-changes/detached-HEAD guards, retry-with-backoff on pull, the health-check tail. |
| `revert.sh` | **Port** | Path constants and service names only (`php8.3-fpm` + the two timers). All logic (pick backup → snapshot current → stop writers → git checkout recorded commit → rsync → restore key+DB → restart) is host-agnostic. |
| `deploy/write-fpm-unit.sh` | **Retire** on this host | Its two real jobs move: worker sizing → FPM pool drop-in; env delivery → pool/systemd drop-in (§6.2). Keep the file in the repo for other/containerized installs, or fold a note into docs. |
| `deploy/Containerfile.mssql` | **Retire** on this host | Superseded by `php8.3-sybase`. Keep in-repo for containerized installs elsewhere (`docs/MSSQL_DRIVER.md` already frames it that way). |
| `install.php` | **Not used for the migration** (data moves instead) | Still the tool for any future fresh install; unchanged. Its printed crontab/chown guidance is already Ubuntu-correct. |
| `INSTALL-FCOS.md`, `DEPLOY-CEPLAY.md` | **Supersede** | Replace with an `INSTALL-UBUNTU.md` (the new setup runbook) once written; keep the FCOS docs until the old box is decommissioned, then mark them historical. Update `README.md`'s "Deployment Guides" list and the `CLAUDE.md` operational notes that say "venue server = FCOS". |

Recommendation: commit the new unit files, pool drop-in, and vhost template
under `deploy/ubuntu/` so the Ubuntu install is reproducible from the repo
the way the FCOS one is today — that's the implementation work in §11.

### 6.12 The Slack alerts service (`alerts/`)

No porting needed — it was **written for (K)ubuntu**: stdlib-only Python,
a hardened native systemd unit (`User=ceplay-alerts`,
`StateDirectory=ceplay-alerts`), config at `/etc/ceplay-alerts/config.ini`.
If it currently runs on a separate machine, the migration is a natural
moment to consolidate it onto the new Ubuntu server following
`alerts/README.md` exactly as written (create the service user, install the
script to `/usr/local/bin`, copy+fill the config, install the unit). Its
CenterEdge credentials live in its own config file — remember to move those
too if the host running it changes. It needs outbound HTTPS to the
CenterEdge API and `slack.com`.

### 6.13 Desktop / remote clients (`electron-remote`)

The Windows remote-control app stores the **server base URL in its own
settings** on each installed machine. If the migration changes the URL
(new IP, or the subpath going away because the app now lives at `/`),
each installed client needs its Settings updated to the new base URL.
If the hostname `ceplay.thecastlefuncenter.com` and path are preserved,
nothing to do. Same logic applies to staff browser bookmarks.

---

## 7. What does NOT change

Worth stating explicitly, because it is most of the system:

- **All application code.** PHP sources, JS, CSS — byte-identical. No
  framework, no Composer, no npm, no build step means no dependency
  re-resolution risk.
- **The database.** SQLite files are architecture-portable; the same
  `pause_groups.db` opens unchanged. Schema auto-migration
  (`DB::getInstance()`) runs on first access exactly as on every update.
  WAL mode, busy timeout, and the scheduler's flock-based global lock all
  behave identically on ext4.
- **Encrypted credentials** — provided `.env` moves with the DB (the pair
  is inseparable).
- **The permission model** — same `www-data` uid 33, same `data/` 770
  pattern.
- **The Nginx security rules** — ported verbatim (§6.3).
- **The health endpoint and heartbeats** (`/api/health`, cron/watchdog
  heartbeat freshness) — same URL, same JSON, so any external monitoring
  just needs the new host.
- **CenterEdge integration** — same API host, same SHA-1/bearer flow, same
  sync behavior. The bearer-token cache row migrates inside the DB and
  will simply refresh on age.
- **The nightly in-app backup** (`data/backups/`, VACUUM INTO, keep 14)
  and all rollup/purge/backfill logic — inside the app.
- **User accounts, roles, schedules, groups, overrides, reporting
  history** — all rows in the DB that moves.
- **Sessions are the one throwaway:** PHP session files are not migrated,
  so everyone logs in again after cutover. Harmless.

---

## 8. Migration-day runbook (data move + cutover)

High-level sequence — the detailed commands belong to the implementation
phase, but the order and the safety properties matter now:

1. **Prepare the Ubuntu box fully in advance** (§5–§6 complete: packages,
   dirs, pool drop-in, timers written but **disabled**, vhost in place,
   timezone + NTP verified, firewall on). The app tree can be pre-synced
   from git; the box serves 502s until data arrives. Verify
   `php -m` shows all required extensions.
2. **Rehearse the copy** while the FCOS box is live: take a nightly backup
   snapshot (`data/backups/`) plus `.env`, restore onto Ubuntu, confirm
   the app boots, login works, and Settings → Test Connection succeeds
   (proves the key + DB pairing and decryption). This de-risks cutover to
   minutes. Then delete the rehearsal data before the real cutover **or**
   plan to overwrite it — never let a stale rehearsal DB accept real
   traffic.
3. **Freeze the source of truth:** on FCOS, stop both timers and the FPM
   service (exactly the writer-stop sequence `revert.sh` uses). From this
   moment the venue has no automation until cutover completes — schedule
   the window accordingly (mid-morning before open, or after close; the
   watchdog's missed-action logic will reconcile states on first run
   afterward).
4. **Take the migration snapshot:** with writers stopped, a consistent
   copy of `pause_groups.db` (plus `-wal`/`-shm` sidecars if present — or
   a `VACUUM INTO` single-file snapshot), `.env`, and optionally
   `data/backups/`, recent `cron.log`/`watchdog.log` (context), and the
   `pause-groups-backups/` history (nice-to-have).
5. **Place data on Ubuntu:** into the new `data/` dir; `chown -R
   www-data:www-data data/`, mode 770; `.env` to the app dir, 600.
6. **Start services:** `php8.3-fpm`, nginx, then the two timers. Run the
   verification checklist (§9) against the LAN IP before moving public
   traffic.
7. **Cut over access:** repoint DNS / port-forward (or move the static IP),
   issue/install the TLS cert (§6.10 ordering), hard-refresh clients.
8. **Decommission gradually:** leave the FCOS box up with pause-groups
   services **stopped and disabled** (so it can never fight the new box
   for control of the games — two watchdogs enforcing divergent state
   against CenterEdge would be genuinely harmful) for a comfortable
   parole period. Rollback during that window = stop Ubuntu services,
   re-copy the DB back if any real data was written, restart FCOS
   services, repoint DNS. After the parole period, archive a final backup
   of `/var/persist/pause-groups*` somewhere off-box, then retire the VM
   or hand it fully to Grafana/other tenants.

**The cardinal rule:** at any instant, exactly one box may have the
timers/FPM running. The enforcement engine actively pushes state to
CenterEdge; two active copies with diverging databases is the worst
failure mode this migration can produce.

---

## 9. Post-migration verification checklist

In order, each proving a specific migration concern:

1. `php -m` lists `sqlite3, pdo_sqlite, curl, mbstring, openssl, pdo_dblib`
   → packages complete.
2. `curl http://localhost/api/health` returns `status: ok`, `database:
   true`; watchdog heartbeat goes healthy within ~2 minutes of the timers
   starting → services + DB wiring.
3. Log in with an existing account → DB migrated, sessions, cookies, CSRF
   fine.
4. Settings → CenterEdge **Test Connection** succeeds → `.env` key moved
   correctly (this is the decryption proof; if it fails, stop and check
   the key before anything else).
5. Dashboard → **Sync Now** pulls the game list → outbound API path from
   the new box works.
6. Go-Kart Labor → **Test connection** reports `Connected via dblib` with
   today's figures → native pdo_dblib + TCP 1433 reachability + encrypted
   MSSQL config all good. Load one Year-range report → timeout parity
   (§6.2 php.ini note).
7. Pause and unpause a test group manually → full write path to
   CenterEdge.
8. If `at` was enabled: after the next plan (or `systemctl start
   pause-groups-daily`), `atq` (as www-data) shows queued jobs and
   `scheduled_actions.at_job_id` is populated; watch one fire on its
   minute. If `at` was not enabled: watch the watchdog execute a due
   action within a minute and confirm the log line in `watchdog.log`.
9. Next morning: `cron.log` shows the full nightly sequence (sync, plan,
   backup written to `data/backups/`, rollup, purge) at the **expected
   local time** (§6.6) → timers + timezone correct.
10. `nginx -t` clean; spot-check the deny rules from outside: `/data/`,
    `/.env`, `/config.php`, `/README.md`, `/api/auth.php` all return
    404/denied → security posture intact.
11. External monitoring (if any) repointed at the new host; alerts service
    (if moved) posting to Slack; electron-remote clients reconnected.

---

## 10. Operator command translation (FCOS → Ubuntu)

The day-2 habits from `INSTALL-FCOS.md`, translated:

| Task | FCOS (today) | Ubuntu (after) |
|---|---|---|
| PHP-FPM status | `systemctl status pause-groups-fpm` | `systemctl status php8.3-fpm` |
| PHP-FPM logs | `journalctl -eu pause-groups-fpm` | `journalctl -eu php8.3-fpm` (plus `/var/log/php8.3-fpm.log`) |
| Restart PHP-FPM | `systemctl restart pause-groups-fpm` | `systemctl restart php8.3-fpm` |
| Test Nginx config | `podman exec systemd-nginx nginx -t` | `nginx -t` |
| Reload Nginx | `podman exec systemd-nginx nginx -s reload` | `systemctl reload nginx` |
| Timer status | `systemctl list-timers 'pause-groups*'` | unchanged |
| Run watchdog now | `systemctl start pause-groups-watchdog` | unchanged |
| Run daily plan now | `systemctl start pause-groups-daily` | unchanged |
| Watchdog log | `tail -f /var/persist/pause-groups/data/watchdog.log` | `tail -f /var/www/pause-groups/data/watchdog.log` |
| Run a CLI script (backfills etc.) | `podman exec pause-groups-fpm php run_backfills.php` | `php run_backfills.php` in the app dir, as www-data **with the `.env` env loaded** (§6.2) |
| Check PHP extensions | `podman exec pause-groups-fpm php -m` | `php -m` |
| Health check | `curl http://localhost/api/health` | unchanged |
| Update the app | `git pull` in src + `sudo bash update.sh` | unchanged (the ported script) |
| Roll back an update | `sudo bash revert.sh` | unchanged (the ported script) |
| Inspect queued actions | n/a (fallback mode) | `atq` / `at -c <job>` (if `at` enabled) |
| SQLite poke (careful, prefer read-only) | via container `php -r` | `sqlite3` CLI on the host |

---

## 11. Implementation work items (follow-up, in order)

The actual changes to author once this plan is agreed — none of them are
done yet:

1. **`deploy/ubuntu/` assets** — native watchdog + daily `.service`/
   `.timer` units, the PHP-FPM pool drop-in (workers + env delivery per
   the §6.2 decision), and the Nginx vhost template with the ported deny
   rules.
2. **`setup-ubuntu.sh`** (or `INSTALL-UBUNTU.md` runbook) per §6.11 —
   including the `at.deny` edit if Decision 3 lands on enabling `at`, the
   `timedatectl` step, ufw rules, and the same backup-first/idempotent/
   `--reset` behaviors as the FCOS script.
3. **Port `update.sh` and `revert.sh`** per §6.11 (path constants, drop
   podman, native snapshot/migrate/restart). Consider keeping the FCOS
   variants intact until decommissioning, gated by a hostname/OS check or
   just by filename (`update-ubuntu.sh`), then renaming after cutover.
4. **Docs pass** — new install doc, mark FCOS docs historical, update
   `README.md` Deployment Guides + Hosting Compatibility pointers,
   `docs/MSSQL_DRIVER.md` "this repo's install" section, and the
   `CLAUDE.md` venue-server notes.
5. **Dry-run rehearsal** (§8 step 2) on the new box with a copied backup.
6. **Cutover** per §8 + verification per §9.
7. Post-cutover cleanups: enable `at` (if deferred), consider timer-unit
   hardening (§6.8), consider off-box backup sync for `data/backups/`
   (the nightly backups currently live on the same disk as the DB —
   true on FCOS too, but the migration is a good moment to fix it).

---

## 12. Open decisions summary

| # | Decision | Options | Recommendation |
|---|---|---|---|
| 1 | Runtime model | Native packages vs. keep podman containers | **Native** (§4) |
| 2 | Ubuntu release | 24.04 LTS (PHP 8.3 parity) vs. 22.04 (PHP 8.1) | **24.04 LTS** (§5) |
| 3 | `at` scheduling | Enable (exact-minute, needs `at.deny`+TZ care) vs. keep watchdog fallback (today's behavior) | **Enable**, possibly as a fast-follow after cutover rather than on migration day (§6.5) |
| 4 | FPM listener | Unix socket (Ubuntu default) vs. keep TCP 127.0.0.1:9000 | **Socket** (§6.2c) |
| 5 | Key delivery to FPM | Pool `env[]` (key in a second file) vs. systemd `EnvironmentFile` + `clear_env=no` | Either; pick one and document it (§6.2a) |
| 6 | Network identity | New IP + DNS/port-forward update vs. move the old static IP to the new box | Team call — moving the IP avoids client-side changes (§6.10, §6.13) |
| 7 | TLS at cutover | Re-issue cert on the new box vs. copy `/var/persist/letsencrypt` | **Re-issue** (§6.10) |
| 8 | URL shape | Keep `/ceplay` subpath vs. serve at `/` on a dedicated vhost | Serve at `/` if the box is dedicated; keep `/ceplay` only if coexistence returns (§2, §6.3) |
| 9 | Scheduled-job mechanism | systemd timers (Persistent, journald) vs. plain crontab | **Timers**, keeping today's unit names (§6.4) |
| 10 | Fate of the FCOS box's other tenants | Migrate Grafana/Tailscale/other vhosts too vs. leave the FCOS box running for them | Out of scope here — inventory `/var/persist/nginx.conf` and decide (§2) |
| 11 | Timers user context | `www-data` (recommended, matches FPM) vs. root as on FCOS-container (`-u 33:33` was already www-data) | **www-data** (§6.4) |
| 12 | Alerts service host | Move onto the new server vs. leave where it runs | Consolidate onto the new server (§6.12) |
