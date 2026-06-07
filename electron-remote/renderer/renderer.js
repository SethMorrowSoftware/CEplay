'use strict';

/* global window, document */

const api = window.ceplay;

const el = {
  connDot: document.getElementById('conn-dot'),
  statusLine: document.getElementById('status-line'),
  status: document.getElementById('status'),
  groups: document.getElementById('groups'),
  settingsBtn: document.getElementById('settings-btn'),
  overlay: document.getElementById('settings-overlay'),
  cfgUrl: document.getElementById('cfg-url'),
  cfgUser: document.getElementById('cfg-user'),
  cfgPass: document.getElementById('cfg-pass'),
  cfgInsecure: document.getElementById('cfg-insecure'),
  groupList: document.getElementById('group-list'),
  groupAddSelect: document.getElementById('group-add-select'),
  groupAddBtn: document.getElementById('group-add-btn'),
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
let controls = [];       // live button controls: [{ id, name, action, btn, sub }]

// Settings working state
let editGroups = [];     // working copy of the configured group buttons
let allGroups = [];      // live pause-group list from the server
let groupsLoaded = false;

// --------------------------------------------------------------------------
// Small DOM helpers
// --------------------------------------------------------------------------

function clearEl(node) {
  while (node.firstChild) node.removeChild(node.firstChild);
}

function makeEl(tag, className, text) {
  const e = document.createElement(tag);
  if (className) e.className = className;
  if (text != null) e.textContent = text;
  return e;
}

function makeOption(value, text, disabled) {
  const o = document.createElement('option');
  o.value = value;
  o.textContent = text;
  if (disabled) o.disabled = true;
  return o;
}

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
    const p = makeEl('p', 'status-detail', detail);
    p.id = 'status-detail';
    el.status.appendChild(p);
  }
}

// --------------------------------------------------------------------------
// Main view — one card per configured pause group, each with Unpause/Pause.
// The configured list is dynamic (managed in Settings), so the cards and their
// click handlers are rebuilt from currentCfg.groups whenever it changes.
// --------------------------------------------------------------------------

function configuredGroups() {
  return Array.isArray(currentCfg.groups) ? currentCfg.groups : [];
}

function renderGroupCards() {
  clearEl(el.groups);
  controls = [];

  const list = configuredGroups();
  if (list.length === 0) {
    const empty = makeEl('div', 'groups-empty');
    empty.appendChild(makeEl('p', 'groups-empty-text', 'No pause-group buttons yet.'));
    const btn = makeEl('button', 'btn-secondary', 'Open Settings');
    btn.type = 'button';
    btn.addEventListener('click', openSettings);
    empty.appendChild(btn);
    el.groups.appendChild(empty);
    return;
  }

  list.forEach((g) => {
    const name = g.name || ('Group #' + g.id);
    const row = makeEl('section', 'group-row');
    row.setAttribute('data-group-id', String(g.id));

    const head = makeEl('div', 'group-row-head');
    head.appendChild(makeEl('span', 'group-row-name', name));
    row.appendChild(head);

    const actions = makeEl('div', 'group-row-actions');
    [['unpause', 'Unpause'], ['pause', 'Pause']].forEach(function (pair) {
      const action = pair[0];
      const btn = makeEl('button', 'big-btn ' + action);
      btn.type = 'button';
      btn.disabled = true;
      btn.appendChild(makeEl('span', 'big-btn-verb', pair[1]));
      const sub = makeEl('span', 'big-btn-sub', 'Tap to start');
      btn.appendChild(sub);
      const ctrl = { id: g.id, name: name, action: action, btn: btn, sub: sub };
      btn.addEventListener('click', function () { onActionClick(ctrl); });
      actions.appendChild(btn);
      controls.push(ctrl);
    });
    row.appendChild(actions);
    el.groups.appendChild(row);
  });

  updateButtonsEnabled();
}

// Buttons are live only when connected and not mid-action.
function updateButtonsEnabled() {
  for (const c of controls) c.btn.disabled = !(ready && !busy);
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
  renderGroupCards();
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
    updateButtonsEnabled();
    const who = res.user && res.user.display_name ? res.user.display_name : (res.user && res.user.username) || '';
    const haveGroups = configuredGroups().length > 0;
    setStatus('Connected' + (who ? ' as ' + who : '') + '.' +
      (haveGroups ? ' Ready.' : ' Open Settings (⚙) to add a pause group.'),
      haveGroups ? 'ok' : 'warn');
    maybeWarnWatchdog(res);
  } else {
    ready = false;
    setConn('error');
    updateButtonsEnabled();
    setStatus('Cannot connect: ' + res.error, 'error');
  }
}

// If the server's health check says the per-minute watchdog is stalled, warn —
// that's the process that actually retries busy assets. Without it, the "the
// server will keep retrying" promise can't be kept.
function maybeWarnWatchdog(res) {
  const h = res && res.health;
  if (!h) return;
  if (h.watchdogHealthy === false) {
    setStatus(el.statusLine.textContent, 'warn',
      'Heads up: the server’s per-minute watchdog hasn’t run recently, so retries of busy assets may not process. Check the cron_watchdog cron on the CEplay server.');
  } else if (typeof h.retriesGaveUp === 'number' && h.retriesGaveUp > 0) {
    setStatus(el.statusLine.textContent, 'warn',
      h.retriesGaveUp + ' asset(s) on the server gave up after exhausting retries. They’ll be re-tried after a cooldown, or on the next action.');
  }
}

// --------------------------------------------------------------------------
// Confirmation dialog (guards against accidental presses)
// --------------------------------------------------------------------------

// Modal confirm built from the same .overlay/.panel styling as Settings.
// Resolves true (confirmed) or false (cancelled / dismissed).
function confirmAction(opts) {
  return new Promise((resolve) => {
    const overlay = makeEl('div', 'overlay confirm-overlay');
    const panel = makeEl('div', 'panel confirm-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');

    panel.appendChild(makeEl('h2', null, opts.title));
    panel.appendChild(makeEl('p', 'confirm-message', opts.message));

    const actions = makeEl('div', 'panel-actions');
    actions.appendChild(makeEl('span', 'spacer'));

    const cancelBtn = makeEl('button', 'btn-secondary', 'Cancel');
    const confirmBtn = makeEl('button', 'btn-primary confirm-go' + (opts.tone ? ' ' + opts.tone : ''), opts.confirmLabel || 'Confirm');
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
    updateButtonsEnabled();
  }
}

// --------------------------------------------------------------------------
// Action click → confirm → run group pause/unpause
// --------------------------------------------------------------------------

async function onActionClick(ctrl) {
  if (busy || ctrl.btn.disabled) return;
  const isPause = ctrl.action === 'pause';

  const ok = await confirmAction({
    title: (isPause ? 'Pause ' : 'Unpause ') + '"' + ctrl.name + '"?',
    message: isPause
      ? 'This pauses every running game and kiosk in "' + ctrl.name + '". Anything in active use is skipped and retried by the server.'
      : 'This unpauses every paused game and kiosk in "' + ctrl.name + '".',
    confirmLabel: isPause ? 'Pause' : 'Unpause',
    tone: isPause ? 'pause' : 'unpause',
  });
  if (!ok) return;

  await withBusy(ctrl.sub, (isPause ? 'Pausing' : 'Unpausing') + '…', async () => {
    setStatus((isPause ? 'Pausing' : 'Unpausing') + ' "' + ctrl.name + '"…', null);
    const res = await api.groupAction(ctrl.id, ctrl.action);
    const out = summarizeGroup(ctrl.name, isPause, res);
    setStatus(out.text, out.cls, out.detail);
    setConn(out.cls === 'error' ? 'error' : 'ok');
  });
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
    lines.push('Busy items are queued on the server; its per-minute watchdog re-attempts them automatically.');
  } else if (changed === 0) {
    head = '"' + name + '" already ' + stateWord + ' — nothing to change.';
  } else {
    head = didVerb + ' ' + changed + ' ' + (changed === 1 ? 'item' : 'items') + ' in "' + name + '".';
  }

  return { cls, text: head, detail: lines.length ? lines.join('\n') : null };
}

// --------------------------------------------------------------------------
// Settings panel — server/login fields + a dynamic editor for the group buttons
// --------------------------------------------------------------------------

function renderGroupEditor() {
  clearEl(el.groupList);
  if (editGroups.length === 0) {
    el.groupList.appendChild(makeEl('div', 'group-list-empty', 'No buttons yet — add a group below.'));
    return;
  }
  editGroups.forEach((g, idx) => {
    const row = makeEl('div', 'group-list-row');
    row.appendChild(makeEl('span', 'group-list-name', g.name || ('Group #' + g.id)));
    const rm = makeEl('button', 'group-remove-btn', '×');
    rm.type = 'button';
    rm.title = 'Remove';
    rm.setAttribute('aria-label', 'Remove ' + (g.name || ('Group #' + g.id)));
    rm.addEventListener('click', () => {
      editGroups.splice(idx, 1);
      renderGroupEditor();
      refreshAddSelect();
    });
    row.appendChild(rm);
    el.groupList.appendChild(row);
  });
}

function refreshAddSelect() {
  const sel = el.groupAddSelect;
  clearEl(sel);

  if (!groupsLoaded) {
    sel.appendChild(makeOption('', 'Connect to load groups…', true));
    el.groupAddBtn.disabled = true;
    return;
  }

  const added = new Set(editGroups.map((g) => String(g.id)));
  const available = allGroups.filter((g) => !added.has(String(g.id)));

  if (available.length === 0) {
    sel.appendChild(makeOption('', allGroups.length ? 'All groups added' : 'No pause groups found', true));
    el.groupAddBtn.disabled = true;
    return;
  }

  const placeholder = makeOption('', 'Choose a group to add…', true);
  placeholder.selected = true;
  sel.appendChild(placeholder);
  available.forEach((g) => sel.appendChild(makeOption(String(g.id), g.name + (g.is_active ? '' : ' (inactive)'))));
  el.groupAddBtn.disabled = false;
}

function addSelectedGroup() {
  const id = el.groupAddSelect.value ? parseInt(el.groupAddSelect.value, 10) : null;
  if (!id) return;
  if (editGroups.some((g) => String(g.id) === String(id))) return;
  const match = allGroups.find((g) => String(g.id) === String(id));
  editGroups.push({ id: id, name: match ? match.name : ('Group #' + id) });
  renderGroupEditor();
  refreshAddSelect();
}

async function loadGroupsForAdd() {
  const res = await api.getGroups();
  if (res && res.ok) {
    allGroups = res.groups || [];
    groupsLoaded = true;
    // Refresh stored names to the current server names (in case of renames).
    editGroups.forEach((g) => {
      const m = allGroups.find((x) => String(x.id) === String(g.id));
      if (m) g.name = m.name;
    });
    renderGroupEditor();
    refreshAddSelect();
  } else {
    groupsLoaded = false;
    refreshAddSelect();
    if (!el.cfgMsg.textContent) {
      el.cfgMsg.className = 'panel-msg';
      el.cfgMsg.textContent = 'Enter the server URL + login and click "Test connection" to load your pause groups.';
    }
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

  editGroups = configuredGroups().map((g) => ({ id: g.id, name: g.name }));
  allGroups = [];
  groupsLoaded = false;
  renderGroupEditor();
  refreshAddSelect();

  el.overlay.classList.remove('hidden');
  el.cfgUrl.focus();
  loadGroupsForAdd();
}

function closeSettings() {
  el.overlay.classList.add('hidden');
}

el.settingsBtn.addEventListener('click', openSettings);
el.cfgCancel.addEventListener('click', closeSettings);
el.overlay.addEventListener('click', (e) => { if (e.target === el.overlay) closeSettings(); });
el.groupAddBtn.addEventListener('click', addSelectedGroup);

el.cfgSave.addEventListener('click', async () => {
  el.cfgMsg.className = 'panel-msg';
  el.cfgMsg.textContent = 'Saving…';
  await api.setConfig({
    baseUrl: el.cfgUrl.value,
    username: el.cfgUser.value,
    password: el.cfgPass.value, // blank = keep existing
    insecureTLS: el.cfgInsecure.checked,
    groups: editGroups.map((g) => ({ id: g.id, name: g.name })),
  });
  el.cfgMsg.className = 'panel-msg ok';
  el.cfgMsg.textContent = 'Saved.';
  closeSettings();
  refreshConnection();
});

el.cfgTest.addEventListener('click', async () => {
  // Persist the server/login fields first so the test uses exactly what's on
  // screen. The group list is preserved by the main process (not sent here).
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
    loadGroupsForAdd();
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
