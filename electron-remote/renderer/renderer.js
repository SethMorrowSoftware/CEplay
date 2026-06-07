'use strict';

/* global window, document */

const api = window.ceplay;

const el = {
  connDot: document.getElementById('conn-dot'),
  statusLine: document.getElementById('status-line'),
  status: document.getElementById('status'),
  // views + nav
  navControls: document.getElementById('nav-controls'),
  navStatus: document.getElementById('nav-status'),
  viewControls: document.getElementById('view-controls'),
  viewStatus: document.getElementById('view-status'),
  groups: document.getElementById('groups'),
  // status view
  statusSynced: document.getElementById('status-synced'),
  statusRefresh: document.getElementById('status-refresh'),
  assetSearch: document.getElementById('asset-search'),
  groupSections: document.getElementById('group-sections'),
  // settings
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

let busy = false;        // a group action is in flight (Controls view)
let configured = false;  // server URL + login are set
let ready = false;       // connection test passed
let currentCfg = {};     // last config read from the main process
let controls = [];       // live group-button controls: [{ id, name, action, btn, sub }]
let currentView = 'controls';

// Status view state
let statusData = { games: [], kiosks: [], groups: [], kioskPauseSupported: false };
let assetSearch = '';
let assetActionInFlight = false;
let statusPollCleanup = null;

// Settings working state
let editGroups = [];
let allGroups = [];
let groupsLoaded = false;

// --------------------------------------------------------------------------
// Small DOM helpers
// --------------------------------------------------------------------------

function clearEl(node) { while (node.firstChild) node.removeChild(node.firstChild); }

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
// Status helpers (shared bottom status line)
// --------------------------------------------------------------------------

function setConn(state) { el.connDot.dataset.state = state; }

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
// View switching
// --------------------------------------------------------------------------

function switchView(name) {
  currentView = name;
  el.viewControls.classList.toggle('hidden', name !== 'controls');
  el.viewStatus.classList.toggle('hidden', name !== 'status');
  el.navControls.classList.toggle('active', name === 'controls');
  el.navStatus.classList.toggle('active', name === 'status');
  if (name === 'status') {
    renderGroupSections();
    if (ready) { loadStatus(); startStatusPolling(); }
  } else {
    stopStatusPolling();
  }
}

el.navControls.addEventListener('click', () => switchView('controls'));
el.navStatus.addEventListener('click', () => switchView('status'));

// --------------------------------------------------------------------------
// Controls view — one card per configured pause group, each Unpause/Pause.
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

function updateButtonsEnabled() {
  for (const c of controls) c.btn.disabled = !(ready && !busy);
}

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
// Confirmation dialog (group "pause/unpause all" + per-asset "out of service")
// --------------------------------------------------------------------------

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
    setStatus('Unexpected error: ' + (e && e.message ? e.message : String(e)), 'error');
    setConn('error');
  } finally {
    busy = false;
    subEl.textContent = 'Tap to start';
    updateButtonsEnabled();
  }
}

function summarizeGroup(name, isPause, res) {
  if (!res.ok && !res.summary) {
    return { cls: 'error', text: 'Failed: ' + (res.error || 'unknown error'), detail: null };
  }
  const s = res.summary || {};
  if (s.error) return { cls: 'error', text: '"' + name + '": ' + s.error, detail: null };

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
// Status view — each configured group with only its own readers, plus
// individual pause / unpause / out-of-service.
// --------------------------------------------------------------------------

function statusBadge(status) {
  if (status === 'enabled') return { label: 'Running', cls: 'running' };
  if (status === 'paused') return { label: 'Paused', cls: 'paused' };
  if (status === 'outOfService') return { label: 'OOS', cls: 'oos' };
  return { label: 'Unknown', cls: 'unknown' };
}

function groupBadge(state) {
  if (state === 'enabled') return { label: 'Running', cls: 'running' };
  if (state === 'paused') return { label: 'Paused', cls: 'paused' };
  if (state === 'mixed') return { label: 'Mixed', cls: 'mixed' };
  if (state === 'empty') return { label: 'Empty', cls: 'empty' };
  return { label: 'Unknown', cls: 'unknown' };
}

// Surface problems first: Out of service, then Paused, then Unknown, then
// Running — alphabetical within each tier.
function statusRank(s) {
  const order = { outOfService: 0, paused: 1, unknown: 2, enabled: 3 };
  return order[s] != null ? order[s] : 9;
}

function memberCountText(g) {
  const parts = [g.enabled + ' running', g.paused + ' paused'];
  if (g.oos > 0) parts.push(g.oos + ' OOS');
  return parts.join(' · ');
}

function assetActions(a) {
  const wrap = makeEl('div', 'asset-actions');
  const controllable = a.type === 'game' || (statusData.kioskPauseSupported && a.status !== 'unknown');
  if (!controllable) {
    wrap.appendChild(makeEl('span', 'asset-note', a.type === 'kiosk' ? 'no control' : '—'));
    return wrap;
  }
  [['enabled', 'Unpause', 'unpause'], ['paused', 'Pause', 'pause'], ['outOfService', 'OOS', 'oos']].forEach((t) => {
    if (a.status === t[0]) return;
    const btn = makeEl('button', 'asset-btn ' + t[2], t[1]);
    btn.type = 'button';
    btn.disabled = !(ready && !assetActionInFlight);
    btn.addEventListener('click', () => onAssetAction(a, t[0], t[1]));
    wrap.appendChild(btn);
  });
  return wrap;
}

function renderAssetRow(a) {
  const row = makeEl('div', 'asset-row');
  row.setAttribute('data-asset', a.type + ':' + a.id);
  const info = makeEl('div', 'asset-info');
  info.appendChild(makeEl('div', 'asset-name', a.name));
  info.appendChild(makeEl('div', 'asset-meta', a.type === 'kiosk' ? 'Kiosk' : 'Game'));
  row.appendChild(info);
  const badge = statusBadge(a.status);
  row.appendChild(makeEl('span', 'state-badge ' + badge.cls + ' asset-badge', badge.label));
  row.appendChild(assetActions(a));
  return row;
}

function renderGroupSections() {
  const top = el.groupSections.scrollTop;
  clearEl(el.groupSections);

  if (!ready) {
    el.groupSections.appendChild(makeEl('div', 'asset-empty', 'Connect to the server to see live status.'));
    return;
  }

  const configured = configuredGroups();
  if (configured.length === 0) {
    const empty = makeEl('div', 'groups-empty');
    empty.appendChild(makeEl('p', 'groups-empty-text', 'No groups yet. Add one in Settings to see its games and kiosks here.'));
    const btn = makeEl('button', 'btn-secondary', 'Open Settings');
    btn.type = 'button';
    btn.addEventListener('click', openSettings);
    empty.appendChild(btn);
    el.groupSections.appendChild(empty);
    return;
  }

  const gById = {}; (statusData.groups || []).forEach((g) => { gById[String(g.id)] = g; });
  const gamesById = {}; (statusData.games || []).forEach((a) => { gamesById[String(a.id)] = a; });
  const kiosksById = {}; (statusData.kiosks || []).forEach((a) => { kiosksById[String(a.id)] = a; });
  const q = assetSearch.trim().toLowerCase();
  let anyShown = false;

  configured.forEach((cg) => {
    const g = gById[String(cg.id)];
    const members = [];
    if (g) {
      (g.gameIds || []).forEach((id) => { const m = gamesById[String(id)]; if (m) members.push({ type: 'game', id: m.id, name: m.name, status: m.status }); });
      (g.kioskIds || []).forEach((id) => { const m = kiosksById[String(id)]; if (m) members.push({ type: 'kiosk', id: m.id, name: m.name, status: m.status }); });
    }
    let shown = members;
    if (q) shown = members.filter((a) => a.name.toLowerCase().indexOf(q) !== -1);
    if (q && shown.length === 0) return; // hide non-matching groups while searching
    anyShown = true;
    shown.sort((a, b) => {
      const r = statusRank(a.status) - statusRank(b.status);
      return r !== 0 ? r : a.name.localeCompare(b.name);
    });

    const sec = makeEl('section', 'gsection');
    const head = makeEl('div', 'gsection-head');
    head.appendChild(makeEl('span', 'gsection-name', cg.name || ('Group #' + cg.id)));
    const st = g ? groupBadge(g.state) : { label: '?', cls: 'unknown' };
    head.appendChild(makeEl('span', 'state-badge ' + st.cls, st.label));
    head.appendChild(makeEl('span', 'gsection-count', g ? memberCountText(g) : 'unavailable'));
    sec.appendChild(head);

    const body = makeEl('div', 'gsection-body');
    if (!g) {
      body.appendChild(makeEl('div', 'gsection-empty', 'This group was not found on the server.'));
    } else if (shown.length === 0) {
      body.appendChild(makeEl('div', 'gsection-empty', 'No games or kiosks in this group yet.'));
    } else {
      shown.forEach((a) => body.appendChild(renderAssetRow(a)));
    }
    sec.appendChild(body);
    el.groupSections.appendChild(sec);
  });

  if (q && !anyShown) {
    el.groupSections.appendChild(makeEl('div', 'asset-empty', 'No games or kiosks match "' + assetSearch.trim() + '".'));
  }
  el.groupSections.scrollTop = top;
}

function updateLocalAsset(a) {
  const arr = a.type === 'kiosk' ? statusData.kiosks : statusData.games;
  const m = (arr || []).find((x) => String(x.id) === String(a.id));
  if (m) m.status = a.status;
}

async function onAssetAction(a, target, label) {
  if (assetActionInFlight || !ready) return;

  // Tagging an asset out of service is more consequential than a quick
  // pause/unpause, so confirm that one (pause/unpause stay one-tap).
  if (target === 'outOfService') {
    const ok = await confirmAction({
      title: 'Tag "' + a.name + '" out of service?',
      message: '"' + a.name + '" will be marked Out of Service in CEplay and stop accepting plays until you set it back to Running.',
      confirmLabel: 'Out of service',
      tone: 'oos',
    });
    if (!ok) return;
  }

  assetActionInFlight = true;
  renderGroupSections(); // disables action buttons while in flight
  setStatus('Setting "' + a.name + '" to ' + label + '…', null);

  let res;
  try {
    res = await api.setAssetStatus(a.type, a.id, target);
  } catch (e) {
    res = { ok: false, error: e && e.message ? e.message : String(e) };
  }

  if (res && res.ok) {
    a.status = target;
    updateLocalAsset(a);
    setStatus('"' + a.name + '" → ' + label + '.', 'ok');
  } else if (res && res.busy) {
    setStatus('"' + a.name + '" is busy — the server will keep retrying.', 'warn');
  } else {
    setStatus('Failed to update "' + a.name + '": ' + ((res && res.error) || 'unknown error'), 'error');
  }

  assetActionInFlight = false;
  renderGroupSections();
  // Reconcile from the server shortly (covers busy/retry + group counts).
  setTimeout(() => { if (currentView === 'status' && !assetActionInFlight) loadStatus(); }, 1600);
}

async function loadStatus() {
  if (!ready) { renderGroupSections(); return; }
  if (assetActionInFlight) return;
  const res = await api.getStatus();
  if (currentView !== 'status') return;
  if (res && res.ok) {
    statusData = res;
    el.statusSynced.textContent = 'Updated ' + new Date().toLocaleTimeString();
    renderGroupSections();
  } else {
    el.statusSynced.textContent = '';
    clearEl(el.groupSections);
    el.groupSections.appendChild(makeEl('div', 'asset-empty', 'Could not load status: ' + ((res && res.error) || 'unknown error')));
  }
}

function startStatusPolling() {
  stopStatusPolling();
  const tick = () => {
    if (currentView === 'status' && ready && !document.hidden && !assetActionInFlight) loadStatus();
  };
  const iv = setInterval(tick, 12000);
  const vis = () => { if (!document.hidden) tick(); };
  document.addEventListener('visibilitychange', vis);
  statusPollCleanup = () => { clearInterval(iv); document.removeEventListener('visibilitychange', vis); };
}

function stopStatusPolling() {
  if (statusPollCleanup) { statusPollCleanup(); statusPollCleanup = null; }
}

el.statusRefresh.addEventListener('click', () => { if (ready) loadStatus(); });
el.assetSearch.addEventListener('input', () => { assetSearch = el.assetSearch.value; renderGroupSections(); });

// --------------------------------------------------------------------------
// Connection check
// --------------------------------------------------------------------------

async function refreshConnection() {
  if (busy) return;
  currentCfg = await api.getConfig();
  configured = !!(currentCfg.baseUrl && currentCfg.username && currentCfg.hasPassword);
  renderGroupCards();
  if (currentView === 'status') renderGroupSections();
  if (!configured) {
    ready = false;
    stopStatusPolling();
    setConn('unknown');
    updateButtonsEnabled();
    setStatus('Not configured yet. Open Settings (⚙) to enter the server URL and login.', 'warn');
    return;
  }
  if (busy) return;
  setConn('working');
  setStatus('Checking connection…', null);
  const res = await api.test();
  if (busy) return;
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
    if (currentView === 'status') { loadStatus(); startStatusPolling(); }
  } else {
    ready = false;
    stopStatusPolling();
    setConn('error');
    updateButtonsEnabled();
    if (currentView === 'status') renderGroupSections();
    setStatus('Cannot connect: ' + res.error, 'error');
  }
}

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
// Settings panel — server/login + the dynamic group-button editor
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

function closeSettings() { el.overlay.classList.add('hidden'); }

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

const brandLogo = document.getElementById('brand-logo');
if (brandLogo) {
  brandLogo.addEventListener('error', () => brandLogo.classList.add('hero-logo--hidden'));
}

refreshConnection();
