# CEplay Remote

A tiny standalone desktop app (Electron) with **four buttons** — unpause
everything in the morning, pause it again at night:

- **Unpause All Arcade Readers** — sets every paused arcade game to `enabled`.
- **Unpause All Kiosks** — sets every paused kiosk to `enabled`.
- **Pause All Arcade Readers** — sets every enabled arcade game to `paused`.
- **Pause All Kiosks** — sets every enabled kiosk to `paused`.

It is meant to live on a **different PC** than the main CEplay project (e.g. a
front‑desk machine) and talks to CEplay over the network using the project's
own API. It does **not** store CenterEdge credentials and it does **not** talk
to CenterEdge directly — CEplay handles all of that.

## How it works

The app logs in to CEplay (`POST /api/auth/login`), keeps the session cookie +
CSRF token in the Electron **main process**, and calls these endpoints:

| Button                     | Endpoint                       |
| -------------------------- | ------------------------------ |
| Unpause All Arcade Readers | `POST /api/games/unpause-all`  |
| Pause All Arcade Readers   | `POST /api/games/pause-all`    |
| Unpause All Kiosks         | `POST /api/kiosks/unpause-all` |
| Pause All Kiosks           | `POST /api/kiosks/pause-all`   |

Each endpoint refreshes state from CenterEdge, flips every eligible asset to the
target state (unpause: `paused → enabled`; pause: `enabled → paused`), and skips
anything `outOfService` (and kiosks with unknown status, which the API spec says
must not be controlled). **Per-asset failures (e.g. a unit that is in use) are
queued into the exact same retry table the main CEplay app uses**
(`Scheduler::queueRetry`), so the per‑minute watchdog (`Scheduler::processRetries`)
keeps re‑attempting them up to `max_attempts` — identical behaviour to clicking
pause/unpause inside the main app.

Because all network requests run in the main process (not the browser window),
there are no CORS/CSP issues and no backend CORS changes are required.

> Note: this is intended for arcade games/kiosks that are **not** on a pause
> schedule (you drive them manually — on in the morning, off at night). If you
> point it at assets governed by an active pause window, the scheduler may
> re‑pause them on its next cycle (it has no way to know a manual change was
> intentional). For scheduled groups, use an override in the main app instead.

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

Click **Test connection** to verify, then **Save**.

## Build an installer

```bash
npm run dist:win     # Windows (NSIS installer)  -> dist/
npm run dist:mac     # macOS (dmg)
npm run dist:linux   # Linux (AppImage)
```

Output lands in `electron-remote/dist/`. Build on the target OS (electron‑builder
does not cross‑compile reliably).

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

The top bar shows `renderer/logo.png` as the app logo. Drop your own
`logo.png` into the `renderer/` folder (a wide/landscape image around 26px tall
looks best). If the file is missing, the brand falls back to the "CEplay Remote"
wordmark.
