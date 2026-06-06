# CEplay Unpause Remote

A tiny standalone desktop app (Electron) with **two buttons**:

- **Unpause All Arcade Readers** — sets every paused arcade game to `enabled`.
- **Unpause All Kiosks** — sets every paused kiosk to `enabled`.

It is meant to live on a **different PC** than the main CEplay project (e.g. a
front‑desk machine) and talks to CEplay over the network using the project's
own API. It does **not** store CenterEdge credentials and it does **not** talk
to CenterEdge directly — CEplay handles all of that.

## How it works

The app logs in to CEplay (`POST /api/auth/login`), keeps the session cookie +
CSRF token in the Electron **main process**, and calls two endpoints:

| Button                    | Endpoint                      |
| ------------------------- | ----------------------------- |
| Unpause All Arcade Readers | `POST /api/games/unpause-all`  |
| Unpause All Kiosks         | `POST /api/kiosks/unpause-all` |

Each endpoint refreshes state from CenterEdge, flips every **paused** asset to
`enabled`, and skips anything `outOfService` (and kiosks with unknown status,
which the API spec says must not be controlled). **Per-asset failures (e.g. a
unit that is in use) are queued into the exact same retry table the main CEplay
app uses** (`Scheduler::queueRetry`), so the per‑minute watchdog
(`Scheduler::processRetries`) keeps re‑attempting them up to `max_attempts` —
identical behaviour to clicking pause/unpause inside the main app.

Because all network requests run in the main process (not the browser window),
there are no CORS/CSP issues and no backend CORS changes are required.

> Note: this is intended for arcade games/kiosks that are **not** on a pause
> schedule. If you point it at assets governed by an active pause window, the
> scheduler may re‑pause them on its next cycle (it has no way to know the
> unpause was intentional). For scheduled groups, use an override in the main
> app instead.

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

- Windows: `%APPDATA%\CEplay Unpause Remote\config.json`
- macOS: `~/Library/Application Support/CEplay Unpause Remote/config.json`
- Linux: `~/.config/CEplay Unpause Remote/config.json`

The password is encrypted at rest with the OS keychain via Electron's
`safeStorage` when available. If no keychain backend exists, it falls back to
plain text (the Settings panel warns you) — another reason to use a dedicated
low‑privilege account.

## Safety

Each button must be tapped **twice** (tap to arm, tap again within 3 seconds to
confirm) so it can't be triggered by an accidental click. The result line then
reports how many were unpaused, how many were already running, how many were
skipped, and how many are busy and being retried by the server.
