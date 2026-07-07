/**
 * Demo banner wiring:
 *   - close / reset / "go to login" buttons
 *   - role switcher (admin / manager / tech)
 *   - "DEMO" tag injected into the sidebar brand
 *
 * The role switcher mirrors the live app's permission tiers so anyone
 * exploring the demo can see exactly what each role can — and can't —
 * touch. The choice is persisted to localStorage under `demoRole` and
 * re-applied on the next reload (the inline bootstrap script in
 * index.html reads the same key before APP_CONFIG is built).
 */
(function() {
    var ROLE_PROFILES = {
        admin:   { id: 1, username: 'admin',  display_name: 'Owner Avery' },
        manager: { id: 2, username: 'morgan', display_name: 'Manager Morgan' },
        tech:    { id: 3, username: 'casey',  display_name: 'Tech Casey' }
    };

    function readStoredRole() {
        try {
            var v = localStorage.getItem('demoRole');
            if (v && ROLE_PROFILES[v]) return v;
        } catch (e) {}
        return 'admin';
    }

    function writeStoredRole(role) {
        try { localStorage.setItem('demoRole', role); } catch (e) {}
    }

    /**
     * Switch the signed-in identity to the named role and re-render the
     * current page so nav, dashboards, and settings reflect the new
     * permission set instantly. Avoids a full page reload so any in-flight
     * pause-group state on the dashboard stays alive.
     *
     * The naive "wipe #app and call App.route()" approach was unreliable
     * because (a) some pages register cleanup callbacks and async polling
     * that could race the new render, and (b) the sidebar "DEMO" tag is
     * added by a one-shot poller on initial load, so it disappears as soon
     * as ensureLayout() rebuilds the sidebar. This routine resets the hash
     * to `#/dashboard` on every role change so every switch lands on a
     * known-good page that's accessible to all three roles, then re-tags
     * the brand once the new layout is in place.
     */
    function applyRole(role) {
        var profile = ROLE_PROFILES[role];
        if (!profile) return;
        var prevRole = (window.APP_CONFIG && window.APP_CONFIG.user && window.APP_CONFIG.user.role) || null;
        if (prevRole === role) {
            updateActiveButton(role);
            return; // nothing to do
        }

        writeStoredRole(role);

        if (window.APP_CONFIG) {
            window.APP_CONFIG.user = {
                id: profile.id,
                username: profile.username,
                display_name: profile.display_name,
                role: role
            };
        }
        if (window.App) {
            App.currentUser = window.APP_CONFIG.user;
        }

        updateActiveButton(role);

        if (window.App && typeof App.route === 'function') {
            // Run any active page cleanup explicitly so background polls /
            // timers from the previous render can't fire after the new role
            // takes over (App.route() also calls cleanup, but doing it here
            // first guarantees ordering when we manually re-route below).
            if (typeof App.currentCleanup === 'function') {
                try { App.currentCleanup(); } catch (e) {}
                App.currentCleanup = null;
            }

            var appEl = document.getElementById('app');
            if (appEl) appEl.innerHTML = '';

            // Always reset to /dashboard on role switch — every role can see
            // it, so we never need to think about which pages the new role
            // is allowed to view. Setting an identical hash does not fire
            // hashchange, so we call route() directly when already there.
            var target = '#/dashboard';
            if (window.location.hash === target) {
                App.route();
            } else {
                window.location.hash = target;
            }
        }

        // The "DEMO" tag is normally added by a one-shot poller on first
        // load; ensureLayout() rebuilds the sidebar fresh on every route()
        // call, so we re-tag every role switch.
        scheduleDemoBrandTag();

        if (window.App && typeof App.toast === 'function') {
            var labels = { admin: 'Administrator', manager: 'Manager', tech: 'Technician' };
            App.toast('Now viewing as ' + (labels[role] || role) + '.', 'info', 2200);
        }
    }

    /**
     * Add the "DEMO" pill to the sidebar brand if it isn't already there.
     * Runs immediately, then briefly polls because ensureLayout() may not
     * have rebuilt the sidebar by the time this fires.
     */
    function scheduleDemoBrandTag() {
        var attempts = 0;
        var ticker = setInterval(function() {
            attempts++;
            var brand = document.querySelector('.sidebar-brand h1');
            if (brand) {
                if (!brand.querySelector('.demo-brand-tag')) {
                    var tag = document.createElement('span');
                    tag.className = 'demo-brand-tag';
                    tag.textContent = 'DEMO';
                    brand.appendChild(tag);
                }
                clearInterval(ticker);
                return;
            }
            if (attempts > 30) clearInterval(ticker); // ~3s budget
        }, 100);
    }

    function updateActiveButton(role) {
        var btns = document.querySelectorAll('.demo-role-btn');
        btns.forEach(function(btn) {
            var match = btn.getAttribute('data-role') === role;
            btn.classList.toggle('is-active', match);
            btn.setAttribute('aria-pressed', match ? 'true' : 'false');
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        var closeBtn = document.getElementById('demo-banner-close');
        var resetBtn = document.getElementById('demo-reset');
        var loginBtn = document.getElementById('demo-jump-login');

        if (closeBtn) closeBtn.addEventListener('click', function() {
            document.body.classList.add('demo-banner-hidden');
        });

        if (resetBtn) resetBtn.addEventListener('click', function() {
            // Easiest path to "reset state" — clear the stored role and
            // reload. Mock data is rebuilt from scratch on every load.
            try { localStorage.removeItem('demoRole'); } catch (e) {}
            window.location.reload();
        });

        if (loginBtn) loginBtn.addEventListener('click', async function() {
            try { await API.post('auth/logout'); } catch (e) {}
            App.currentUser = null;
            window.location.hash = '#/login';
        });

        // Role switcher buttons
        var roleBtns = document.querySelectorAll('.demo-role-btn');
        roleBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                applyRole(btn.getAttribute('data-role'));
            });
        });
        updateActiveButton(readStoredRole());

        // Tag the sidebar brand with a DEMO pill once the layout exists.
        // The SPA builds .sidebar-brand inside ensureLayout(); poll briefly.
        scheduleDemoBrandTag();
    });
})();
