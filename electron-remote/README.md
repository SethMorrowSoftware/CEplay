# CEplay Remote

A tiny standalone desktop app (Electron) for front-desk staff — unpause
everything in the morning, pause it again at night. In Settings you **add a
card for each CEplay pause group** you want to control, and each card gets an
**Unpause** and a **Pause** button. Add as many as you need (arcade games,
kiosks, outdoor attractions, redemption, …) and remove them any time.

It is meant to live on a **different PC** than the main CEplay project (e.g. a
front‑desk machine) and talks to CEplay over the network using the project's
own API. It does **not** store CenterEdge credentials and it does **not** talk
to CenterEdge directly — CEplay handles all of that.

## How it works

The app logs in to CEplay (`POST /api/auth/login`), keeps the session cookie +
CSRF token in the Electron **main process**, and drives each pause group you've
added via the same endpoints the web dashboard's group Pause/Unpause buttons use:

| Button             | Endpoint                        |
| ------------------ | ------------------------------- |
| Unpause (a group)  | `POST /api/groups/{id}/unpause` |
| Pause (a group)    | `POST /api/groups/{id}/pause`   |

Because these are the standard group endpoints, any CEplay server supports them.
A pause group can contain games
**and** kiosks; CEplay flips every eligible member to the target state and skips
anything `outOfService` (and kiosks with unknown status, which the API spec says
must not be controlled). **Per-asset failures (e.g. a unit that is in use) are
queued into the exact same retry table the main CEplay app uses**
(`Scheduler::queueRetry`), so the per‑minute watchdog (`Scheduler::processRetries`)
keeps re‑attempting them up to `max_attempts` — identical behaviour to clicking
pause/unpause inside the main app.

Because all network requests run in the main process (not the browser window),
there are no CORS/CSP issues and no backend CORS changes are required.

> Note: point the buttons at groups you drive **manually** (on in the morning,
> off at night). If a group is also governed by an active pause schedule, the
> scheduler may re‑pause it on its next cycle (it has no way to know a manual
> change was intentional). For scheduled groups, use an override in the main app
> instead.

## Status console

Switch to the **Status** tab (top of the window) for a live staff console:

- A **group monitor** strip shows each pause group you've added with its current
  state (Running / Paused / Mixed) and a running / paused / OOS breakdown.
- A **searchable, filterable list** of every game and kiosk (filter by type and
  by status), **sorted problems-first** (Out of service, then Paused, then
  Running), with per-asset **Unpause / Pause / Out of service** buttons. Games
  use the JSON-Patch `operationStatus` endpoint; kiosks use
  `POST /api/kiosks/{id}/{pause|unpause|out-of-service}`. Kiosks the card system
  can't control (or that report no status) are shown read-only, per the API spec.
- **Unpause / Pause are one tap**; **Out of service** asks for a quick confirm
  (it's the most consequential). The view **auto-refreshes ~every 12s** while
  open (and pauses when the window is hidden); a busy asset is queued for retry
  server-side just like a group action.

Use the **Controls** tab for the big one-tap group Unpause/Pause buttons.

## Verifying retries (busy assets)

When an asset is in use, the server can't flip it right away, so the result
reports "N busy — the server will keep retrying." Those assets are queued in
CEplay's `action_retries` table and re-attempted by **cron_watchdog.php** once a
minute (`Scheduler::processRetries`), up to `max_attempts`; after that they give
up and then self-heal after a cooldown. It's the same retry path the main app
uses for manual pause/unpause.

Two things make this work:

1. **The per-minute watchdog cron must be running on the CEplay server** — it's
   what drains the queue. If it isn't, busy assets never retry. The remote shows
   a warning on connect if the server reports the watchdog as stalled.
2. You can watch the queue drain with the bundled inspector (run on the server):

   ```bash
   php retry_status.php                 # one-shot snapshot
   watch -n 5 'php retry_status.php'    # live view as it drains
   ```

   Trigger a pause/unpause that reports "N busy", then run it — you'll see the
   rows with their attempt counts climbing each minute, and entries disappearing
   as the watchdog succeeds.

## Prerequisites

- A reachable CEplay server URL (e.g. `https://ceplay.yourvenue.local`).
- A CEplay login. **Create a dedicated, low‑privilege account** for this remote
  rather than reusing an admin's personal credentials.
- [Node.js](https://nodejs.org/) 18+ and npm (only needed to run/build from
  source; packaged builds bundle their own runtime).

## Run from source

```bash
cd electron-remote
npm install
npm start
```

On first launch, click the **⚙ Settings** button and enter:

- **CEplay server URL** — base URL only (no `/api`), e.g. `https://ceplay.local`
- **Username** / **Password** — the dedicated CEplay account
- **Allow self-signed TLS** — only if your LAN server uses a self‑signed cert

Click **Test connection** to verify — this also loads your pause groups into the
**Pause-group buttons** picker. Use it to **add** a card for each group you want
to control (each gets an Unpause + a Pause button), **remove** any you don't need
with the × button, then **Save**. (Create the groups first in the main CEplay app
under *Pause Groups* if you haven't already.)

## Build an installer

```bash
npm run dist:win     # Windows (NSIS installer)  -> dist/
npm run dist:mac     # macOS (dmg)
npm run dist:linux   # Linux (AppImage)
```

Output lands in `electron-remote/dist/`. Build on the target OS (electron‑builder
does not cross‑compile reliably).

## Linux: sandbox & AppImage

Chromium normally runs each renderer inside an OS sandbox. On Linux that needs
either a setuid `chrome-sandbox` helper **or** unprivileged user namespaces.
AppImages mount read‑only under `/tmp`, so the bundled helper can't be
setuid‑root, and several distros (Ubuntu 23.10+/24.04 via AppArmor, hardened
Debian) disable unprivileged user namespaces — so a stock AppImage would abort
on launch with:

```
FATAL:setuid_sandbox_host.cc … chrome-sandbox is owned by root and has mode 4755
```

Because this window only renders trusted, local UI (no remote/untrusted web
content), **on Linux the app relaunches itself once with `--no-sandbox`** (and
`npm start` passes it too), so it works out of the box with no per‑machine
setup. Windows and macOS are unaffected and keep the sandbox.

### Prefer to keep the sandbox on Linux?

Enable unprivileged user namespaces on each machine, then delete the Linux
relaunch block near the top of `main.js` (the `if (process.platform === 'linux'
…)`) and drop `--no-sandbox` from the `start` script:

```bash
# Ubuntu 23.10+/24.04 (AppArmor gate):
echo 'kernel.apparmor_restrict_unprivileged_userns=0' | sudo tee /etc/sysctl.d/60-userns.conf

# Debian / older kernels:
echo 'kernel.unprivileged_userns_clone=1' | sudo tee -a /etc/sysctl.d/60-userns.conf

sudo sysctl --system   # apply now (persists across reboots)
```

To test a single run without editing config: `sudo sysctl -w
kernel.apparmor_restrict_unprivileged_userns=0` then launch normally. (You can
also force a one‑off either way with `./CEplay\ Remote-*.AppImage --no-sandbox`.)

## Where settings are stored

Configuration lives in the per‑user Electron `userData` folder (not in this
repo):

- Windows: `%APPDATA%\CEplay Remote\config.json`
- macOS: `~/Library/Application Support/CEplay Remote/config.json`
- Linux: `~/.config/CEplay Remote/config.json`

The password is encrypted at rest with the OS keychain via Electron's
`safeStorage` when available. If no keychain backend exists, it falls back to
plain text (the Settings panel warns you) — another reason to use a dedicated
low‑privilege account.

## Safety

Tapping a button opens a **confirmation dialog** (e.g. "Pause all arcade
readers?") so it can't be triggered by an accidental click — confirm to run, or
cancel/Esc to back out. The result line then reports how many were changed, how
many were already in the target state, how many were skipped, and how many are
busy and being retried by the server.

## Branding

A large `logo.png` is shown as a hero banner above the action buttons. Drop your
own `logo.png` into the `renderer/` folder — a landscape/wide image works best;
it's scaled to fit (up to ~104px tall). If the file is missing, the hero is
hidden and the top-bar "CEplay Remote" wordmark still identifies the app.
