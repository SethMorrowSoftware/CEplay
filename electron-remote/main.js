'use strict';

/**
 * CEplay Remote — Electron main process.
 *
 * All network I/O lives here (never in the renderer) so we sidestep browser
 * CORS / CSP entirely and keep the CEplay session cookie + CSRF token out of
 * the web context. The renderer talks to us only over the IPC bridge defined
 * in preload.js.
 *
 * The four buttons map to four CEplay endpoints, which apply the change through
 * the SAME retry queue the main CEplay app uses (Scheduler::queueRetry →
 * watchdog Scheduler::processRetries):
 *   POST /api/games/unpause-all    — unpause every paused arcade reader
 *   POST /api/games/pause-all      — pause every enabled arcade reader
 *   POST /api/kiosks/unpause-all   — unpause every paused kiosk
 *   POST /api/kiosks/pause-all     — pause every enabled kiosk
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
    return { ok: true, user, status: health ? health.status : null };
  } catch (err) {
    return { ok: false, error: err.message };
  }
});

async function runBulk(endpoint) {
  try {
    await ensureAuth();
    const summary = await apiRequest('POST', endpoint, {});
    return { ok: true, summary };
  } catch (err) {
    // The pause/unpause-all endpoints return a structured summary even on a
    // hard failure (HTTP 502) — surface it so the UI can explain what happened.
    if (err.data && typeof err.data === 'object') {
      return { ok: false, summary: err.data, error: err.message };
    }
    return { ok: false, error: err.message };
  }
}

ipcMain.handle('ceplay:unpauseGames', () => runBulk('/api/games/unpause-all'));
ipcMain.handle('ceplay:pauseGames', () => runBulk('/api/games/pause-all'));
ipcMain.handle('ceplay:unpauseKiosks', () => runBulk('/api/kiosks/unpause-all'));
ipcMain.handle('ceplay:pauseKiosks', () => runBulk('/api/kiosks/pause-all'));

// ---------------------------------------------------------------------------
// Window
// ---------------------------------------------------------------------------

function createWindow() {
  const win = new BrowserWindow({
    width: 780,
    height: 680,
    minWidth: 480,
    minHeight: 520,
    backgroundColor: '#0b0e14',
    title: 'CEplay Remote',
    autoHideMenuBar: true,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
    },
  });

  win.removeMenu();

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
