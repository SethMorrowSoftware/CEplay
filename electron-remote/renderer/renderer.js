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
  cfgGamesGroup: document.getElementById('cfg-games-group'),
  cfgKiosksGroup: document.getElementById('cfg-kiosks-group'),
  cfgSave: document.getElementById('cfg-save'),
  cfgCancel: document.getElementById('cfg-cancel'),
  cfgTest: document.getElementById('cfg-test'),
  cfgMsg: document.getElementById('cfg-msg'),
  encHint: document.getElementById('enc-hint'),
};

let busy = false;        // an action is in flight
let configured = false;  // server URL + login are set
let ready = false;       // connection test passed
let currentCfg = {};     // last config read from the main process

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
// Buttons — each maps to one configurable pause group + an action.
//   "games"  slot  -> the group chosen as "Arcade games group" in Settings
//   "kiosks" slot  -> the group chosen as "Kiosks group" in Settings
// Both pause and unpause for a slot target the same group, so the 2x2 grid is
// Unpause/Pause for each of the two groups.
// --------------------------------------------------------------------------

const controls = [
  { btn: document.getElementById('btn-games-unpause'),  sub: document.getElementById('games-unpause-sub'),  slot: 'games',  action: 'unpause', run: () => api.unpauseGames() },
  { btn: document.getElementById('btn-kiosks-unpause'), sub: document.getElementById('kiosks-unpause-sub'), slot: 'kiosks', action: 'unpause', run: () => api.unpauseKiosks() },
  { btn: document.getElementById('btn-games-pause'),    sub: document.getElementById('games-pause-sub'),    slot: 'games',  action: 'pause',   run: () => api.pauseGames() },
  { btn: document.getElementById('btn-kiosks-pause'),   sub: document.getElementById('kiosks-pause-sub'),   slot: 'kiosks', action: 'pause',   run: () => api.pauseKiosks() },
];
for (const c of controls) c.title = c.btn.querySelector('.big-btn-title');

function slotGroupId(slot) {
  return slot === 'games' ? currentCfg.gamesGroupId : currentCfg.kiosksGroupId;
}
function slotGroupName(slot) {
  return (slot === 'games' ? currentCfg.gamesGroupName : currentCfg.kiosksGroupName) || '';
}

// A button is live only when we're connected, not mid-action, and its slot has
// a pause group selected.
function updateButtonsEnabled() {
  for (const c of controls) {
    c.btn.disabled = !(ready && !busy && !!slotGroupId(c.slot));
  }
}

// Reflect the configured group names on the button faces + idle sub-labels.
function applyConfigToButtons() {
  for (const c of controls) {
    const id = slotGroupId(c.slot);
    if (c.title) c.title.textContent = id ? (slotGroupName(c.slot) || ('Group #' + id)) : 'No group selected';
    if (!busy) c.sub.textContent = id ? 'Tap to start' : 'Pick a group in Settings';
  }
  updateButtonsEnabled();
}

// --------------------------------------------------------------------------
// Connection check
// --------------------------------------------------------------------------

async function refreshConnection() {
  // Never run a connection check on top of an in-flight action — its async
  // result would re-enable buttons and overwrite the operation's status line.
  if (busy) return;
  currentCfg = await api.getConfig();
  configured = !!(currentCfg.baseUrl && currentCfg.username && currentCfg.hasPassword);
  applyConfigToButtons();
  if (!configured) {
    ready = false;
    setConn('unknown');
    updateButtonsEnabled();
    setStatus('Not configured yet. Open Settings (⚙) to enter the server URL and login.', 'warn');
    return;
  }
  if (busy) return;
  setConn('working');
  setStatus('Checking connection…', null);
  const res = await api.test();
  if (busy) return; // an operation started while the test was in flight
  if (res.ok) {
    ready = true;
    setConn('ok');
    applyConfigToButtons();
    const who = res.user && res.user.display_name ? res.user.display_name : (res.user && res.user.username) || '';
    const haveGroups = slotGroupId('games') || slotGroupId('kiosks');
    setStatus('Connected' + (who ? ' as ' + who : '') + '.' +
      (haveGroups ? ' Ready.' : ' Open Settings (⚙) to pick your pause groups.'),
      haveGroups ? 'ok' : 'warn');
  } else {
    ready = false;
    setConn('error');
    updateButtonsEnabled();
    setStatus('Cannot connect: ' + res.error, 'error');
  }
}

// --------------------------------------------------------------------------
// Confirmation dialog (guards against accidental presses)
// --------------------------------------------------------------------------

// Modal confirm built from the same .overlay/.panel styling as Settings.
// Resolves true (confirmed) or false (cancelled / dismissed).
function confirmAction(opts) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div');
    overlay.className = 'overlay confirm-overlay';

    const panel = document.createElement('div');
    panel.className = 'panel confirm-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');

    const heading = document.createElement('h2');
    heading.textContent = opts.title;
    panel.appendChild(heading);

    const message = document.createElement('p');
    message.className = 'confirm-message';
    message.textContent = opts.message;
    panel.appendChild(message);

    const actions = document.createElement('div');
    actions.className = 'panel-actions';
    const spacer = document.createElement('span');
    spacer.className = 'spacer';
    actions.appendChild(spacer);

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn-secondary';
    cancelBtn.textContent = 'Cancel';

    const confirmBtn = document.createElement('button');
    confirmBtn.className = 'btn-primary confirm-go' + (opts.tone ? ' ' + opts.tone : '');
    confirmBtn.textContent = opts.confirmLabel || 'Confirm';

    actions.appendChild(cancelBtn);
    actions.appendChild(confirmBtn);
    panel.appendChild(actions);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);

    function close(result) {
      document.removeEventListener('keydown', onKey);
      overlay.remove();
      resolve(result);
    }
    function onKey(e) {
      if (e.key === 'Escape') close(false);
      else if (e.key === 'Enter') close(true);
    }

    cancelBtn.addEventListener('click', () => close(false));
    confirmBtn.addEventListener('click', () => close(true));
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(false); });
    document.addEventListener('keydown', onKey);

    requestAnimationFrame(() => confirmBtn.focus());
  });
}

async function withBusy(subEl, workingText, fn) {
  busy = true;
  setConn('working');
  updateButtonsEnabled();
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
    // The run callback sets the connection dot from the actual result; here we
    // just restore button availability.
    updateButtonsEnabled();
  }
}

// --------------------------------------------------------------------------
// Result formatting (group pause/unpause response)
//   { success, action, group_name, changed, skipped, errors, details }
// --------------------------------------------------------------------------

function summarizeGroup(name, isPause, res) {
  if (!res.ok && !res.summary) {
    return { cls: 'error', text: 'Failed: ' + (res.error || 'unknown error'), detail: null };
  }
  const s = res.summary || {};
  if (s.error) {
    return { cls: 'error', text: '"' + name + '": ' + s.error, detail: null };
  }

  const changed = s.changed || 0;
  const skipped = s.skipped || 0;
  const errors = s.errors || 0;
  const didVerb = isPause ? 'Paused' : 'Unpaused';
  const stateWord = isPause ? 'paused' : 'running';

  const lines = [];
  if (skipped > 0) lines.push(skipped + ' skipped (out of service / unknown status).');

  let cls = 'ok';
  let head;
  if (errors > 0) {
    cls = 'warn';
    head = didVerb + ' ' + changed + ' in "' + name + '". ' + errors + ' busy — the server will keep retrying.';
  } else if (changed === 0) {
    head = '"' + name + '" already ' + stateWord + ' — nothing to change.';
  } else {
    head = didVerb + ' ' + changed + ' ' + (changed === 1 ? 'item' : 'items') + ' in "' + name + '".';
  }

  return { cls, text: head, detail: lines.length ? lines.join('\n') : null };
}

// --------------------------------------------------------------------------
// Wire up buttons
// --------------------------------------------------------------------------

for (const c of controls) {
  c.btn.addEventListener('click', async () => {
    if (busy || c.btn.disabled) return;

    const id = slotGroupId(c.slot);
    if (!id) {
      setStatus('No group selected for this button. Open Settings (⚙) and pick one.', 'warn');
      return;
    }
    const name = slotGroupName(c.slot) || ('Group #' + id);
    const isPause = c.action === 'pause';

    const ok = await confirmAction({
      title: (isPause ? 'Pause ' : 'Unpause ') + '"' + name + '"?',
      message: isPause
        ? 'This pauses every running game and kiosk in "' + name + '". Anything in active use is skipped and retried by the server.'
        : 'This unpauses every paused game and kiosk in "' + name + '".',
      confirmLabel: isPause ? 'Pause' : 'Unpause',
      tone: isPause ? 'pause' : 'unpause',
    });
    if (!ok) return;

    await withBusy(c.sub, (isPause ? 'Pausing' : 'Unpausing') + '…', async () => {
      setStatus((isPause ? 'Pausing' : 'Unpausing') + ' "' + name + '"…', null);
      const res = await c.run();
      const out = summarizeGroup(name, isPause, res);
      setStatus(out.text, out.cls, out.detail);
      setConn(out.cls === 'error' ? 'error' : 'ok');
    });
  });
}

// --------------------------------------------------------------------------
// Settings panel
// --------------------------------------------------------------------------

// (Re)fill a group <select> with a "none" choice + the live group list, keeping
// the stored selection visible even if the live list can't be loaded yet.
function fillGroupSelect(sel, groups, selectedId, selectedName) {
  while (sel.firstChild) sel.removeChild(sel.firstChild);

  const none = document.createElement('option');
  none.value = '';
  none.textContent = '— none —';
  sel.appendChild(none);

  let matched = false;
  (groups || []).forEach((g) => {
    const o = document.createElement('option');
    o.value = String(g.id);
    o.textContent = g.name + (g.is_active ? '' : ' (inactive)');
    if (selectedId != null && selectedId !== '' && String(g.id) === String(selectedId)) {
      o.selected = true;
      matched = true;
    }
    sel.appendChild(o);
  });

  // Preserve a stored selection that isn't in the (possibly empty) live list.
  if (!matched && selectedId != null && selectedId !== '') {
    const o = document.createElement('option');
    o.value = String(selectedId);
    o.textContent = (selectedName || ('Group #' + selectedId)) + ' (current)';
    o.selected = true;
    sel.appendChild(o);
  }
}

function readGroupSelection(sel) {
  const id = sel.value ? parseInt(sel.value, 10) : null;
  let name = '';
  if (id != null && sel.selectedIndex >= 0) {
    name = (sel.options[sel.selectedIndex].textContent || '').replace(/\s*\(.*\)\s*$/, '');
  }
  return { id, name };
}

async function loadGroupsIntoSelects() {
  const res = await api.getGroups();
  if (res && res.ok) {
    fillGroupSelect(el.cfgGamesGroup, res.groups, el.cfgGamesGroup.value || currentCfg.gamesGroupId, currentCfg.gamesGroupName);
    fillGroupSelect(el.cfgKiosksGroup, res.groups, el.cfgKiosksGroup.value || currentCfg.kiosksGroupId, currentCfg.kiosksGroupName);
  } else if (!el.cfgMsg.textContent) {
    el.cfgMsg.className = 'panel-msg';
    el.cfgMsg.textContent = 'Enter the server URL + login and click "Test connection" to load your pause groups.';
  }
}

async function openSettings() {
  currentCfg = await api.getConfig();
  el.cfgUrl.value = currentCfg.baseUrl || '';
  el.cfgUser.value = currentCfg.username || '';
  el.cfgPass.value = '';
  el.cfgPass.placeholder = currentCfg.hasPassword ? '(unchanged)' : 'password';
  el.cfgInsecure.checked = !!currentCfg.insecureTLS;
  el.encHint.textContent = currentCfg.encryptionAvailable
    ? 'Password is stored encrypted via the OS keychain.'
    : 'Warning: no OS keychain available — the password is stored in plain text in this app’s config folder. Use a dedicated low-privilege CEplay account.';
  el.cfgMsg.textContent = '';
  el.cfgMsg.className = 'panel-msg';

  // Seed the group dropdowns with the stored selections, then try to load the
  // live list (needs a working connection).
  fillGroupSelect(el.cfgGamesGroup, [], currentCfg.gamesGroupId, currentCfg.gamesGroupName);
  fillGroupSelect(el.cfgKiosksGroup, [], currentCfg.kiosksGroupId, currentCfg.kiosksGroupName);

  el.overlay.classList.remove('hidden');
  el.cfgUrl.focus();
  loadGroupsIntoSelects();
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
  const games = readGroupSelection(el.cfgGamesGroup);
  const kiosks = readGroupSelection(el.cfgKiosksGroup);
  await api.setConfig({
    baseUrl: el.cfgUrl.value,
    username: el.cfgUser.value,
    password: el.cfgPass.value, // blank = keep existing
    insecureTLS: el.cfgInsecure.checked,
    gamesGroupId: games.id,
    gamesGroupName: games.name,
    kiosksGroupId: kiosks.id,
    kiosksGroupName: kiosks.name,
  });
  el.cfgMsg.className = 'panel-msg ok';
  el.cfgMsg.textContent = 'Saved.';
  closeSettings();
  refreshConnection();
});

el.cfgTest.addEventListener('click', async () => {
  // Persist first so the test uses exactly what's on screen. Group selections
  // are preserved by the main process even though we don't send them here.
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
    el.cfgMsg.textContent = 'Connected' + (who ? ' as ' + who : '') + '. Loading groups…';
    currentCfg = await api.getConfig();
    loadGroupsIntoSelects();
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

// Hide the logo gracefully if logo.png hasn't been dropped in yet, so the
// brand falls back to the wordmark instead of a broken-image icon.
const brandLogo = document.getElementById('brand-logo');
if (brandLogo) {
  brandLogo.addEventListener('error', () => brandLogo.classList.add('hero-logo--hidden'));
}

refreshConnection();
