'use strict';

/**
 * CEplay Remote — Electron main process.
 *
 * All network I/O lives here (never in the renderer) so we sidestep browser
 * CORS / CSP entirely and keep the CEplay session cookie + CSRF token out of
 * the web context. The renderer talks to us only over the IPC bridge defined
 * in preload.js.
 *
 * The buttons drive a user-managed list of CEplay pause groups (added/removed
 * in Settings), via the same group endpoints the web dashboard uses, so any
 * CEplay server supports them. The server applies the change through the SAME
 * retry queue the main app uses (Scheduler::queueRetry → watchdog
 * Scheduler::processRetries):
 *   POST /api/groups/{id}/unpause   — unpause a configured group
 *   POST /api/groups/{id}/pause     — pause a configured group
 * GET  /api/groups populates the Settings group picker.
 */

const { app, BrowserWindow, ipcMain, safeStorage, shell } = require('electron');
const fs = require('fs');
const path = require('path');

// Linux: Chromium's setuid sandbox can't initialize from a read-only AppImage
// mount, and many locked-down hosts disable unprivileged user namespaces, so
// Electron aborts at startup ("chrome-sandbox ... is owned by root and has mode
// 4755"). app.commandLine.appendSwitch('no-sandbox') runs too late to prevent
// it — the sandbox is decided from the real process command line during native
// startup — so if the flag isn't already present, relaunch ourselves once with
// it on argv. This window only renders trusted, local UI (no remote/untrusted
// web content), so running without the OS sandbox is an acceptable trade-off.
// To keep the sandbox instead, enable unprivileged user namespaces (see the
// README "Linux: sandbox & AppImage" section) and delete this block.
if (process.platform === 'linux' && !process.argv.includes('--no-sandbox')) {
  app.relaunch({ args: process.argv.slice(1).concat(['--no-sandbox']) });
  app.exit(0);
}

const CONFIG_PATH = path.join(app.getPath('userData'), 'config.json');

// In-memory session state. Re-established automatically on 401/403.
let cookieJar = {};      // cookie name -> value
let csrfToken = null;

// ---------------------------------------------------------------------------
// Config persistence (server URL + credentials)
// ---------------------------------------------------------------------------

function loadConfig() {
  try {
    return JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
  } catch (e) {
    return {};
  }
}

function saveConfig(cfg) {
  fs.mkdirSync(path.dirname(CONFIG_PATH), { recursive: true });
  // 0600 so other local users can't read the credentials file.
  fs.writeFileSync(CONFIG_PATH, JSON.stringify(cfg, null, 2), { mode: 0o600 });
}

/**
 * Normalize the configured pause-group buttons into an ordered [{id, name}]
 * list. Migrates the legacy fixed gamesGroupId/kiosksGroupId/outdoorGroupId
 * fields from older configs so existing setups keep their buttons.
 */
function getGroupButtons(cfg) {
  if (Array.isArray(cfg.groupButtons)) {
    return cfg.groupButtons
      .map((g) => ({ id: parseInt(g && g.id, 10), name: String((g && g.name) || '') }))
      .filter((g) => !Number.isNaN(g.id))
      .map((g) => ({ id: g.id, name: g.name || ('Group #' + g.id) }));
  }
  const out = [];
  [['gamesGroupId', 'gamesGroupName'], ['kiosksGroupId', 'kiosksGroupName'], ['outdoorGroupId', 'outdoorGroupName']]
    .forEach((pair) => {
      const id = parseInt(cfg[pair[0]], 10);
      if (cfg[pair[0]] != null && !Number.isNaN(id)) {
        out.push({ id, name: String(cfg[pair[1]] || ('Group #' + id)) });
      }
    });
  return out;
}

/**
 * Decrypt the stored password. We prefer the OS keychain (safeStorage); a
 * plaintext fallback exists only for platforms without an encryption backend.
 */
function getPassword(cfg) {
  if (cfg.passwordEnc) {
    if (!safeStorage.isEncryptionAvailable()) {
      throw new Error('Stored password is encrypted but this machine has no keychain available. Re-enter it in Settings.');
    }
    try {
      return safeStorage.decryptString(Buffer.from(cfg.passwordEnc, 'base64'));
    } catch (e) {
      throw new Error('Stored password could not be decrypted on this machine. Re-enter it in Settings.');
    }
  }
  if (cfg.password) {
    return cfg.password;
  }
  throw new Error('No password configured. Open Settings and enter the CEplay credentials.');
}

// Honour an insecure-TLS opt-in for LAN servers using a self-signed cert.
// (Documented in the README — leave off unless you understand the trade-off.)
(() => {
  const startup = loadConfig();
  if (startup.insecureTLS) {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
  }
})();

// ---------------------------------------------------------------------------
// HTTP layer: cookie jar + CSRF + auto re-login
// ---------------------------------------------------------------------------

function joinUrl(base, p) {
  return String(base).replace(/\/+$/, '') + p;
}

function storeSetCookies(headers) {
  let list = [];
  if (typeof headers.getSetCookie === 'function') {
    list = headers.getSetCookie();
  } else {
    const sc = headers.get('set-cookie');
    if (sc) list = [sc];
  }
  for (const c of list) {
    const pair = c.split(';')[0];
    const idx = pair.indexOf('=');
    if (idx <= 0) continue;
    const name = pair.slice(0, idx).trim();
    const value = pair.slice(idx + 1).trim();
    // Treat a blank/expired cookie as a clear (logout).
    if (value === '' || /expires=Thu,\s*01\s*Jan\s*1970/i.test(c)) {
      delete cookieJar[name];
    } else {
      cookieJar[name] = value;
    }
  }
}

function cookieHeader() {
  return Object.entries(cookieJar)
    .map(([k, v]) => `${k}=${v}`)
    .join('; ');
}

function resetSession() {
  cookieJar = {};
  csrfToken = null;
}

/**
 * Log in against /api/auth/login, capturing the session cookie + CSRF token.
 * The login endpoint is CSRF-exempt, so no token is required here.
 */
async function login() {
  const cfg = loadConfig();
  if (!cfg.baseUrl) throw new Error('Server URL is not configured. Open Settings.');
  if (!cfg.username) throw new Error('Username is not configured. Open Settings.');
  const password = getPassword(cfg);

  const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
  const ck = cookieHeader();
  if (ck) headers.Cookie = ck;

  let res;
  try {
    res = await fetch(joinUrl(cfg.baseUrl, '/api/auth/login'), {
      method: 'POST',
      headers,
      body: JSON.stringify({ username: cfg.username, password }),
      redirect: 'manual',
    });
  } catch (e) {
    throw new Error(`Could not reach the server at ${cfg.baseUrl} (${e.message}).`);
  }

  storeSetCookies(res.headers);
  if (res.type === 'opaqueredirect' || (res.status >= 300 && res.status < 400)) {
    throw new Error('Server returned a redirect — check the server URL (http vs https, or an extra/missing path).');
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data && data.error ? data.error : `Login failed (HTTP ${res.status}).`);
  }
  csrfToken = data.csrf_token || null;
  return data.user || null;
}

async function ensureAuth() {
  if (!csrfToken || !cookieHeader()) {
    await login();
  }
}

/**
 * Authenticated JSON request. Retries once after a fresh login on 401/403
 * (session expiry / stale CSRF). Throws on non-2xx with the parsed body
 * attached as `err.data` so callers can still read a structured summary.
 */
async function apiRequest(method, p, body, opts = {}) {
  const { retryAuth = true } = opts;
  const cfg = loadConfig();
  if (!cfg.baseUrl) throw new Error('Server URL is not configured. Open Settings.');

  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const ck = cookieHeader();
  if (ck) headers.Cookie = ck;
  if (csrfToken && method !== 'GET') headers['X-CSRF-Token'] = csrfToken;

  let res;
  try {
    res = await fetch(joinUrl(cfg.baseUrl, p), {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      redirect: 'manual',
    });
  } catch (e) {
    throw new Error(`Could not reach the server (${e.message}).`);
  }

  storeSetCookies(res.headers);

  if (res.type === 'opaqueredirect' || (res.status >= 300 && res.status < 400)) {
    throw new Error('Server returned a redirect — check the server URL (http vs https, or an extra/missing path).');
  }

  if ((res.status === 401 || res.status === 403) && retryAuth && p !== '/api/auth/login') {
    await login();
    return apiRequest(method, p, body, { retryAuth: false });
  }

  const text = await res.text();
  let data = {};
  if (text) {
    try { data = JSON.parse(text); } catch (e) { data = { error: text.slice(0, 300) }; }
  }

  if (!res.ok) {
    const err = new Error(data && data.error ? data.error : `HTTP ${res.status}`);
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}

// ---------------------------------------------------------------------------
// IPC handlers (the only surface the renderer can reach)
// ---------------------------------------------------------------------------

ipcMain.handle('ceplay:getConfig', () => {
  const cfg = loadConfig();
  return {
    baseUrl: cfg.baseUrl || '',
    username: cfg.username || '',
    hasPassword: !!(cfg.passwordEnc || cfg.password),
    insecureTLS: !!cfg.insecureTLS,
    encryptionAvailable: safeStorage.isEncryptionAvailable(),
    // Ordered list of pause-group buttons: [{ id, name }].
    groups: getGroupButtons(cfg),
  };
});

ipcMain.handle('ceplay:setConfig', (_event, incoming) => {
  const cfg = loadConfig();
  const next = {
    baseUrl: (incoming.baseUrl || '').trim(),
    username: (incoming.username || '').trim(),
    insecureTLS: !!incoming.insecureTLS,
  };

  // Only replace the password when a new one is supplied; otherwise keep
  // whatever is already stored so "Save" doesn't wipe the credential.
  if (incoming.password) {
    if (safeStorage.isEncryptionAvailable()) {
      next.passwordEnc = safeStorage.encryptString(incoming.password).toString('base64');
    } else {
      next.password = incoming.password;
    }
  } else {
    if (cfg.passwordEnc) next.passwordEnc = cfg.passwordEnc;
    if (cfg.password) next.password = cfg.password;
  }

  // Pause-group buttons: an ordered [{id, name}] list. Preserve the stored list
  // (migrating legacy fields if needed) when the caller doesn't send one — the
  // "Test connection" path calls setConfig with only the server/login fields.
  if (Array.isArray(incoming.groups)) {
    next.groupButtons = incoming.groups
      .map((g) => ({ id: parseInt(g && g.id, 10), name: String((g && g.name) || '').trim() }))
      .filter((g) => !Number.isNaN(g.id))
      .map((g) => ({ id: g.id, name: g.name || ('Group #' + g.id) }));
  } else {
    next.groupButtons = getGroupButtons(cfg);
  }

  saveConfig(next);
  resetSession(); // force a fresh login with the new settings
  // Apply the TLS choice to subsequent connections without needing a restart.
  process.env.NODE_TLS_REJECT_UNAUTHORIZED = next.insecureTLS ? '0' : '1';
  return { ok: true };
});

ipcMain.handle('ceplay:test', async () => {
  try {
    resetSession();
    const user = await login();
    let health = null;
    try { health = await apiRequest('GET', '/api/health'); } catch (e) { /* health is best-effort */ }
    // Surface the bits the UI cares about: is the per-minute watchdog (which
    // processes retries of busy assets) alive, and did any retries give up?
    const healthSummary = health ? {
      status: health.status || null,
      watchdogHealthy: health.watchdog ? !!health.watchdog.healthy : null,
      retriesPending: health.retries && typeof health.retries.pending === 'number' ? health.retries.pending : null,
      retriesGaveUp: health.retries && typeof health.retries.gave_up === 'number' ? health.retries.gave_up : null,
    } : null;
    return { ok: true, user, status: health ? health.status : null, health: healthSummary };
  } catch (err) {
    return { ok: false, error: err.message };
  }
});

// List the venue's pause groups so the renderer can populate the Settings
// dropdowns. Trimmed to the fields the picker needs.
ipcMain.handle('ceplay:getGroups', async () => {
  try {
    await ensureAuth();
    const data = await apiRequest('GET', '/api/groups');
    const groups = (data.groups || []).map((g) => ({
      id: g.id,
      name: g.name,
      is_active: g.is_active,
    }));
    return { ok: true, groups };
  } catch (err) {
    return { ok: false, error: err.message };
  }
});

// Pause/unpause a specific pause group by id. CEplay flips every game AND kiosk
// in the group (and queues busy assets into its own retry table).
ipcMain.handle('ceplay:groupAction', async (_event, groupId, action) => {
  const id = parseInt(groupId, 10);
  if (Number.isNaN(id) || (action !== 'pause' && action !== 'unpause')) {
    return { ok: false, error: 'Invalid group action.' };
  }
  try {
    await ensureAuth();
    const summary = await apiRequest('POST', '/api/groups/' + id + '/' + action, {});
    return { ok: summary.success !== false, summary };
  } catch (err) {
    // group pause/unpause returns a structured body even on failure — surface
    // it so the UI can explain what happened.
    if (err.data && typeof err.data === 'object') {
      return { ok: false, summary: err.data, error: err.message };
    }
    return { ok: false, error: err.message };
  }
});

// ---------------------------------------------------------------------------
// Live status console: list every game + kiosk with its current
// operationStatus + the configured groups' live state, and change a single
// asset's status (pause / unpause / out of service).
// ---------------------------------------------------------------------------

ipcMain.handle('ceplay:getStatus', async () => {
  try {
    await ensureAuth();
    const [gamesRes, kiosksRes, capsRes, groupsRes] = await Promise.all([
      apiRequest('GET', '/api/games').catch(() => ({ games: [] })),
      apiRequest('GET', '/api/kiosks').catch(() => ({ kiosks: [] })),
      apiRequest('GET', '/api/capabilities').catch(() => null),
      apiRequest('GET', '/api/groups').catch(() => ({ groups: [] })),
    ]);
    const games = (gamesRes.games || []).map((g) => ({
      id: String(g.game_id),
      name: g.game_name || ('Game ' + g.game_id),
      status: g.operation_status || 'unknown',
    }));
    const kiosks = (kiosksRes.kiosks || []).map((k) => ({
      id: String(k.id),
      name: k.name || ('Kiosk ' + k.id),
      status: k.operationStatus || 'unknown',
    }));
    const kioskPauseSupported = !!(capsRes && capsRes.kiosks && capsRes.kiosks.operationStatus === true);
    const groups = (groupsRes.groups || []).map((g) => {
      const c = g.combined_stats || g.game_stats || {};
      return {
        id: g.id,
        name: g.name,
        state: g.effective_state || 'empty',
        total: c.total || 0,
        enabled: c.enabled || 0,
        paused: c.paused || 0,
        oos: c.out_of_service || 0,
        active: g.is_active ? 1 : 0,
        gameIds: (g.game_ids || []).map(String),
        kioskIds: (g.kiosk_ids || []).map(String),
      };
    });
    return {
      ok: true,
      games,
      kiosks,
      kioskPauseSupported,
      groups,
      lastSynced: gamesRes.last_synced || kiosksRes.last_synced || null,
    };
  } catch (err) {
    return { ok: false, error: err.message };
  }
});

// Change one game's status via the JSON-Patch games endpoint. A per-asset
// failure (e.g. busy) is queued server-side for retry exactly like the bulk
// path, so we report it as "busy — will retry" rather than a hard error.
async function setGameStatus(id, status) {
  const body = { games: {} };
  body.games[String(id)] = [{ op: 'replace', path: '/operationStatus', value: status }];
  const res = await apiRequest('PATCH', '/api/games', body);
  const confirmed = Array.isArray(res.games) && res.games.some((g) => String(g.id) === String(id));
  const errored = res.errors && (res.errors[id] || res.errors[String(id)]);
  if (confirmed && !errored) return { ok: true, status };
  const e = errored && (errored.message || errored);
  return { ok: false, busy: true, error: e ? String(e) : 'not confirmed — the server will retry' };
}

async function setKioskStatus(id, status) {
  const action = status === 'paused' ? 'pause' : (status === 'outOfService' ? 'out-of-service' : 'unpause');
  const res = await apiRequest('POST', '/api/kiosks/' + encodeURIComponent(id) + '/' + action, {});
  if (res && res.success) return { ok: true, status };
  return { ok: false, error: (res && res.error) || 'failed' };
}

ipcMain.handle('ceplay:setAssetStatus', async (_event, type, id, status) => {
  if (status !== 'enabled' && status !== 'paused' && status !== 'outOfService') {
    return { ok: false, error: 'Invalid status.' };
  }
  try {
    await ensureAuth();
    return type === 'kiosk' ? await setKioskStatus(id, status) : await setGameStatus(id, status);
  } catch (err) {
    if (err.data && err.data.error) return { ok: false, error: err.data.error };
    return { ok: false, error: err.message };
  }
});

// ---------------------------------------------------------------------------
// Window
// ---------------------------------------------------------------------------

function createWindow() {
  const win = new BrowserWindow({
    width: 1100,
    height: 780,
    // Keep the minimums small enough that the window fully fits low-resolution
    // POS / ELO touch panels (e.g. 800x600, even 640x480). The renderer's CSS
    // is responsive and scrolls, so the UI stays usable at these sizes.
    minWidth: 640,
    minHeight: 480,
    backgroundColor: '#090d14',
    title: 'CEplay Remote',
    autoHideMenuBar: true,
    show: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
    },
  });

  win.removeMenu();

  // Open as a full desktop portal by default, without a small-window flash.
  win.once('ready-to-show', () => {
    win.maximize();
    win.show();
  });

  // Open any external links in the system browser, never in-app.
  win.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });

  win.loadFile(path.join(__dirname, 'renderer', 'index.html'));
}

app.whenReady().then(() => {
  createWindow();
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
