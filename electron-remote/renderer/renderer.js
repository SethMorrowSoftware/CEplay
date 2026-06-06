'use strict';

/* global window, document */

const api = window.ceplay;

const el = {
  connDot: document.getElementById('conn-dot'),
  statusLine: document.getElementById('status-line'),
  status: document.getElementById('status'),
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
  // Never run a connection check on top of an in-flight unpause — its async
  // result would re-enable buttons and overwrite the operation's status line.
  if (busy) return;
  const cfg = await api.getConfig();
  configured = !!(cfg.baseUrl && cfg.username && cfg.hasPassword);
  if (!configured) {
    setConn('unknown');
    setButtonsEnabled(false);
    setStatus('Not configured yet. Open Settings (⚙) to enter the server URL and login.', 'warn');
    return;
  }
  if (busy) return;
  setConn('working');
  setStatus('Checking connection…', null);
  const res = await api.test();
  if (busy) return; // an operation started while the test was in flight
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

// The four action buttons, each with its sub-label, the IPC call it triggers,
// the noun for messages, and the present-tense verb shown while it runs.
const controls = [
  { btn: document.getElementById('btn-games-unpause'),  sub: document.getElementById('games-unpause-sub'),  run: () => api.unpauseGames(),  noun: 'arcade readers', verb: 'Unpausing' },
  { btn: document.getElementById('btn-kiosks-unpause'), sub: document.getElementById('kiosks-unpause-sub'), run: () => api.unpauseKiosks(), noun: 'kiosks',         verb: 'Unpausing' },
  { btn: document.getElementById('btn-games-pause'),    sub: document.getElementById('games-pause-sub'),    run: () => api.pauseGames(),    noun: 'arcade readers', verb: 'Pausing' },
  { btn: document.getElementById('btn-kiosks-pause'),   sub: document.getElementById('kiosks-pause-sub'),   run: () => api.pauseKiosks(),   noun: 'kiosks',         verb: 'Pausing' },
];

function setButtonsEnabled(on) {
  for (const c of controls) c.btn.disabled = !on;
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

async function withBusy(subEl, workingText, fn) {
  busy = true;
  setConn('working');
  setButtonsEnabled(false);
  subEl.textContent = workingText;
  try {
    await fn();
  } catch (e) {
    // fn (the IPC call + summarize) normally resolves with {ok:false} rather
    // than throwing, but guard anyway so the dot can't get stuck on 'working'.
    setStatus('Unexpected error: ' + (e && e.message ? e.message : String(e)), 'error');
    setConn('error');
  } finally {
    busy = false;
    subEl.textContent = 'Tap to start';
    // The run callback sets the connection dot from the actual result;
    // here we just restore button availability.
    setButtonsEnabled(configured);
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

  const isPause = s.action === 'pause';
  const didVerb = isPause ? 'Paused' : 'Unpaused';
  const alreadyWord = isPause ? 'already paused' : 'already running';

  const lines = [];
  if (typeof s.already === 'number' && s.already > 0) lines.push(`${s.already} ${alreadyWord}`);
  if (typeof s.out_of_service === 'number' && s.out_of_service > 0) lines.push(`${s.out_of_service} out of service (skipped)`);
  if (typeof s.unknown === 'number' && s.unknown > 0) lines.push(`${s.unknown} unknown status (skipped)`);

  let cls = 'ok';
  let head;
  if ((s.attempted || 0) === 0) {
    head = isPause
      ? `All ${noun} already paused. Nothing to pause.`
      : `All ${noun} already running. Nothing to unpause.`;
  } else if ((s.failed || 0) === 0) {
    head = `${didVerb} ${s.changed} ${noun}.`;
  } else {
    cls = 'warn';
    head = `${didVerb} ${s.changed} of ${s.attempted} ${noun}. ${s.failed} busy — the server will keep retrying.`;
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

for (const c of controls) {
  makeButton(c.btn, c.sub, 'Tap to start', async () => {
    await withBusy(c.sub, c.verb + '…', async () => {
      setStatus(`${c.verb} all ${c.noun}…`, null);
      const res = await c.run();
      const out = summarize(c.noun, res);
      setStatus(out.text, out.cls, out.detail);
      setConn(out.cls === 'error' ? 'error' : 'ok');
    });
  });
}

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
