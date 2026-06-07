'use strict';

/**
 * Preload bridge. Exposes a tiny, explicit API to the renderer over
 * contextIsolation — no Node, no ipcRenderer, no network access leaks into
 * the web context.
 */

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('ceplay', {
  getConfig: () => ipcRenderer.invoke('ceplay:getConfig'),
  setConfig: (cfg) => ipcRenderer.invoke('ceplay:setConfig', cfg),
  test: () => ipcRenderer.invoke('ceplay:test'),
  getGroups: () => ipcRenderer.invoke('ceplay:getGroups'),
  groupAction: (groupId, action) => ipcRenderer.invoke('ceplay:groupAction', groupId, action),
});
