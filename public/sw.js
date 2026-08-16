/**
 * CEplay service worker — deliberately conservative.
 *
 * Exists so the app installs as a PWA (home-screen Tag Board for floor
 * staff) and launches instantly on flaky venue Wi-Fi. It NEVER touches
 * /api/ requests — live game state, auth, and CSRF flow straight to the
 * network untouched — and it never serves a stale page when the network
 * is available:
 *
 *   - navigations:  network-first; the last good shell is the offline
 *                   fallback only.
 *   - /public/ assets: cache-first (safe — asset URLs carry a ?v=mtime
 *                   version, so a changed file is a new URL), with stale
 *                   versions of the same path evicted on update.
 *
 * Served at the app root by index.php with Cache-Control: no-cache so a
 * deployed update is picked up on the next visit.
 */
'use strict';

var CACHE = 'ceplay-v1';
var SHELL_KEY = './__app_shell__';

self.addEventListener('install', function() {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(keys.filter(function(k) {
                return k !== CACHE;
            }).map(function(k) {
                return caches.delete(k);
            }));
        }).then(function() {
            return self.clients.claim();
        })
    );
});

/** Store an asset response, evicting older ?v= versions of the same path. */
function putAsset(request, response) {
    return caches.open(CACHE).then(function(cache) {
        var url = new URL(request.url);
        return cache.keys().then(function(keys) {
            var stale = keys.filter(function(k) {
                var ku = new URL(k.url);
                return ku.pathname === url.pathname && ku.search !== url.search;
            });
            return Promise.all(stale.map(function(k) { return cache.delete(k); }));
        }).then(function() {
            return cache.put(request, response);
        });
    });
}

self.addEventListener('fetch', function(event) {
    var request = event.request;
    if (request.method !== 'GET') return;

    var url = new URL(request.url);
    if (url.origin !== self.location.origin) return;
    // API traffic is live data behind auth/CSRF — never intercept it.
    if (url.pathname.indexOf('/api/') !== -1) return;
    // The worker itself must always come from the network.
    if (url.pathname.slice(-6) === '/sw.js') return;

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).then(function(response) {
                // Only an HTML document may become the offline shell — a
                // direct navigation to an icon/manifest URL is also mode
                // 'navigate', and caching that would poison offline launch.
                var type = response.headers.get('content-type') || '';
                if (response.ok && type.indexOf('text/html') !== -1) {
                    var copy = response.clone();
                    event.waitUntil(caches.open(CACHE).then(function(cache) {
                        return cache.put(SHELL_KEY, copy);
                    }));
                }
                return response;
            }).catch(function() {
                return caches.match(SHELL_KEY).then(function(hit) {
                    return hit || Response.error();
                });
            })
        );
        return;
    }

    if (url.pathname.indexOf('/public/') !== -1) {
        event.respondWith(
            caches.match(request).then(function(hit) {
                if (hit) return hit;
                return fetch(request).then(function(response) {
                    if (response.ok) {
                        event.waitUntil(putAsset(request, response.clone()));
                    }
                    return response;
                });
            })
        );
    }
});
