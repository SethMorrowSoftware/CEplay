'use strict';

/* global window, document */

const api = window.ceplay;

const el = {
  connDot: document.getElementById('conn-dot'),
  statusLine: document.getElementById('status-line'),
  status: document.getElementById('status'),
  btnGames: document.getElementById('btn-games'),
  btnKiosks: document.getElementById('btn-kiosks'),
  gamesSub: document.getElementById('games-sub'),
  kiosksSub: document.getElementById('kiosks-sub'),
  settingsBtn: document.getElementById('settings-btn'),
  overlay: document.getElementById('settings-overlay'),
  cfgUrl: document.getElementById('cfg-url'),
  cfgUser: document.getElementById('cfg-user'),
  cfgPass: document.getElementById('cfg-pass'),
  cfgInsecure: document.getElementById('cfg-insecure'),
  cfgSave: document.getElementById('cfg-save'),
  cfgCancel: document.getElementById('cfg-cancel'),
  cfgTest: document.getElementById('cfg-test'),
  cfgMsg: document.getElementById('cfg-msg'),
  encHint: document.getElementById('enc-hint'),
};

const ARM_TIMEOUT_MS = 3000;
let busy = false;
let configured = false;

// --------------------------------------------------------------------------
// Status helpers
// --------------------------------------------------------------------------

function setConn(state) {
  el.connDot.dataset.state = state; // ok | error | working | unknown
}

function setStatus(text, cls, detail) {
  el.statusLine.className = 'status-line' + (cls ? ' ' + cls : '');
  el.statusLine.textContent = text;
  const existing = document.getElementById('status-detail');
  if (existing) existing.remove();
  if (detail) {
    const p = document.createElement('p');
    p.id = 'status-detail';
    p.className = 'status-detail';
    p.textContent = detail;
    el.status.appendChild(p);
  }
}

// --------------------------------------------------------------------------
// Connection check
// --------------------------------------------------------------------------

async function refreshConnection() {
  const cfg = await api.getConfig();
  configured = !!(cfg.baseUrl && cfg.username && cfg.hasPassword);
  if (!configured) {
    setConn('unknown');
    setButtonsEnabled(false);
    setStatus('Not configured yet. Open Settings (⚙) to enter the server URL and login.', 'warn');
    return;
  }
  setConn('working');
  setStatus('Checking connection…', null);
  const res = await api.test();
  if (res.ok) {
    setConn('ok');
    setButtonsEnabled(true);
    const who = res.user && res.user.display_name ? res.user.display_name : (res.user && res.user.username) || '';
    setStatus('Connected' + (who ? ' as ' + who : '') + '. Ready.', 'ok');
  } else {
    setConn('error');
    setButtonsEnabled(false);
    setStatus('Cannot connect: ' + res.error, 'error');
  }
}

function setButtonsEnabled(on) {
  el.btnGames.disabled = !on;
  el.btnKiosks.disabled = !on;
}

// --------------------------------------------------------------------------
// Arm-to-confirm button behaviour (guards against accidental presses)
// --------------------------------------------------------------------------

function makeButton(btn, subEl, idleText, run) {
  let armTimer = null;

  function disarm() {
    btn.dataset.armed = '0';
    subEl.textContent = idleText;
    if (armTimer) { clearTimeout(armTimer); armTimer = null; }
  }

  btn.addEventListener('click', async () => {
    if (busy || btn.disabled) return;

    if (btn.dataset.armed !== '1') {
      btn.dataset.armed = '1';
      subEl.textContent = 'Tap again to confirm';
      armTimer = setTimeout(disarm, ARM_TIMEOUT_MS);
      return;
    }

    disarm();
    await run();
  });

  return { disarm };
}

async function withBusy(activeBtn, subEl, workingText, fn) {
  busy = true;
  setConn('working');
  setButtonsEnabled(false);
  subEl.textContent = workingText;
  try {
    await fn();
  } finally {
    busy = false;
    subEl.textContent = 'Tap to start';
    // Re-evaluate connection state (also re-enables buttons if healthy).
    setButtonsEnabled(configured);
    setConn(configured ? 'ok' : 'unknown');
  }
}

// --------------------------------------------------------------------------
// Result formatting
// --------------------------------------------------------------------------

function summarize(noun, res) {
  if (!res.ok && !res.summary) {
    return { cls: 'error', text: 'Failed: ' + (res.error || 'unknown error'), detail: null };
  }
  const s = res.summary || {};
  if (s.error) {
    return { cls: 'error', text: noun + ': ' + s.error, detail: null };
  }

  const lines = [];
  if (typeof s.already_enabled === 'number') lines.push(`${s.already_enabled} already running`);
  if (typeof s.out_of_service === 'number' && s.out_of_service > 0) lines.push(`${s.out_of_service} out of service (skipped)`);
  if (typeof s.unknown === 'number' && s.unknown > 0) lines.push(`${s.unknown} unknown status (skipped)`);

  let cls = 'ok';
  let head;
  if ((s.attempted || 0) === 0) {
    head = `All ${noun} already running. Nothing to unpause.`;
  } else if ((s.failed || 0) === 0) {
    head = `Unpaused ${s.unpaused} ${noun}.`;
  } else {
    cls = 'warn';
    head = `Unpaused ${s.unpaused} of ${s.attempted} ${noun}. ${s.failed} busy — the server will keep retrying.`;
    const r = (s.retrying || []).slice(0, 6).map((x) => {
      const a = `attempt ${x.attempts}/${x.max_attempts}`;
      return `• ${x.id} (${a})${x.last_error ? ' — ' + x.last_error : ''}`;
    });
    if (r.length) lines.push('Retrying:\n' + r.join('\n'));
  }

  if (s.sync_warning) {
    if (cls === 'ok') cls = 'warn';
    lines.push('Note: could not refresh state first (' + s.sync_warning + '); acted on last-known state.');
  }

  return { cls, text: head, detail: lines.length ? lines.join('\n') : null };
}

// --------------------------------------------------------------------------
// Wire up buttons
// --------------------------------------------------------------------------

makeButton(el.btnGames, el.gamesSub, 'Tap to start', async () => {
  await withBusy(el.btnGames, el.gamesSub, 'Unpausing…', async () => {
    setStatus('Unpausing all arcade readers…', null);
    const res = await api.unpauseGames();
    const out = summarize('arcade readers', res);
    setStatus(out.text, out.cls, out.detail);
  });
});

makeButton(el.btnKiosks, el.kiosksSub, 'Tap to start', async () => {
  await withBusy(el.btnKiosks, el.kiosksSub, 'Unpausing…', async () => {
    setStatus('Unpausing all kiosks…', null);
    const res = await api.unpauseKiosks();
    const out = summarize('kiosks', res);
    setStatus(out.text, out.cls, out.detail);
  });
});

// --------------------------------------------------------------------------
// Settings panel
// --------------------------------------------------------------------------

async function openSettings() {
  const cfg = await api.getConfig();
  el.cfgUrl.value = cfg.baseUrl || '';
  el.cfgUser.value = cfg.username || '';
  el.cfgPass.value = '';
  el.cfgPass.placeholder = cfg.hasPassword ? '(unchanged)' : 'password';
  el.cfgInsecure.checked = !!cfg.insecureTLS;
  el.encHint.textContent = cfg.encryptionAvailable
    ? 'Password is stored encrypted via the OS keychain.'
    : 'Warning: no OS keychain available — the password is stored in plain text in this app’s config folder. Use a dedicated low-privilege CEplay account.';
  el.cfgMsg.textContent = '';
  el.cfgMsg.className = 'panel-msg';
  el.overlay.classList.remove('hidden');
  el.cfgUrl.focus();
}

function closeSettings() {
  el.overlay.classList.add('hidden');
}

el.settingsBtn.addEventListener('click', openSettings);
el.cfgCancel.addEventListener('click', closeSettings);
el.overlay.addEventListener('click', (e) => { if (e.target === el.overlay) closeSettings(); });

el.cfgSave.addEventListener('click', async () => {
  el.cfgMsg.className = 'panel-msg';
  el.cfgMsg.textContent = 'Saving…';
  await api.setConfig({
    baseUrl: el.cfgUrl.value,
    username: el.cfgUser.value,
    password: el.cfgPass.value, // blank = keep existing
    insecureTLS: el.cfgInsecure.checked,
  });
  el.cfgMsg.className = 'panel-msg ok';
  el.cfgMsg.textContent = 'Saved.';
  closeSettings();
  refreshConnection();
});

el.cfgTest.addEventListener('click', async () => {
  // Persist first so the test uses exactly what's on screen.
  el.cfgMsg.className = 'panel-msg';
  el.cfgMsg.textContent = 'Testing…';
  await api.setConfig({
    baseUrl: el.cfgUrl.value,
    username: el.cfgUser.value,
    password: el.cfgPass.value,
    insecureTLS: el.cfgInsecure.checked,
  });
  el.cfgPass.value = '';
  const res = await api.test();
  if (res.ok) {
    const who = res.user && (res.user.display_name || res.user.username);
    el.cfgMsg.className = 'panel-msg ok';
    el.cfgMsg.textContent = 'Connected' + (who ? ' as ' + who : '') + '.';
  } else {
    el.cfgMsg.className = 'panel-msg error';
    el.cfgMsg.textContent = res.error;
  }
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && !el.overlay.classList.contains('hidden')) closeSettings();
});

// --------------------------------------------------------------------------
// Boot
// --------------------------------------------------------------------------

refreshConnection();
