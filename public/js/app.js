/**
 * SPA router, navigation rendering, toast notifications, shared utilities.
 */

/**
 * Inline SVG icon set. Stored as raw strings so they can be inserted via
 * innerHTML on a known-safe parent. The path data is hand-curated to a
 * consistent stroke/proportion family (Lucide-style, 24-grid, 1.7 stroke).
 */
const Icons = {
    dashboard: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 9h8V3h-8z"/></svg>',
    games:     '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="20" height="12" rx="3"/><path d="M6 12h4M8 10v4"/><circle cx="16" cy="10.5" r="0.9"/><circle cx="18" cy="13.5" r="0.9"/></svg>',
    cards:     '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/></svg>',
    groups:    '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/></svg>',
    kiosks:    '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="14" rx="2"/><path d="M9 21h6M12 17v4"/><circle cx="12" cy="10" r="0.9"/></svg>',
    schedules: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    overrides: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>',
    analytics: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21V8M9 21V4M15 21v-9M21 21v-6"/></svg>',
    performance:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 14l3-4 3 3 4-6"/><circle cx="17" cy="7" r="1.3" fill="currentColor" stroke="none"/></svg>',
    readers:   '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
    logs:      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14M5 9h14M5 14h10M5 19h14"/></svg>',
    settings:  '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>',
    sun:       '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>',
    moon:      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>',
    check:     '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>',
    xmark:     '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    bang:      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v5"/><circle cx="12" cy="16.5" r="0.6" fill="currentColor" stroke="none"/></svg>',
    info:      '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><circle cx="12" cy="7.5" r="0.6" fill="currentColor" stroke="none"/></svg>',
    castle:    '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V9l3 2V7l3 2V6l3 2V5l3 2V8l3-1v4l3-2v12"/><path d="M3 21h18M10 21v-5h4v5"/></svg>',
    plus:      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
    arrowLeft: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>',
    refresh:   '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 0 1 15.5-6.3L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15.5 6.3L3 16"/><path d="M3 21v-5h5"/></svg>',
    menu:      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>',
    logout:    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>'
};

const App = {
    currentUser: null,
    routes: {},
    currentCleanup: null,
    toastContainer: null,
    theme: 'dark',
    themeToggleBtn: null,
    appTimezone: 'UTC',

    DAYS: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    DAYS_SHORT: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],

    /**
     * Role → resource access matrix mirrored from PHP (Auth::ACCESS_MATRIX).
     * Keep these two in sync — the server enforces with HTTP 403 either way,
     * but the UI uses this to hide nav and guard routes for a clean UX.
     */
    /**
     * The gateable permission areas (mirrors the keys of Auth::PERMISSIONS).
     * Access is decided by the user's resolved permission list, which the
     * server injects into APP_CONFIG.user.permissions (and into the login /
     * auth-status responses) — roles themselves are data, editable in
     * Settings → Roles. Areas NOT in this list are open to every signed-in
     * user. The server re-checks every API call regardless.
     */
    PERMISSION_AREAS: [
        'analytics', 'view_revenue', 'cards', 'manual_control',
        'overrides_manage', 'groups_manage', 'schedules_manage',
        'settings', 'users', 'view_logs'
    ],

    /**
     * Fallback map for a stale session whose user object predates the
     * permission injection (e.g. a tab left open across the upgrade).
     * Mirrors the seeded system roles; one reload/re-login replaces it
     * with the real resolved list.
     */
    LEGACY_ACCESS: {
        admin:   ['analytics', 'view_revenue', 'cards', 'manual_control', 'overrides_manage', 'groups_manage', 'schedules_manage', 'settings', 'users', 'view_logs'],
        manager: ['analytics', 'view_revenue', 'cards', 'manual_control', 'overrides_manage', 'groups_manage', 'schedules_manage', 'view_logs'],
        tech:    ['analytics', 'manual_control', 'overrides_manage', 'settings', 'users']
    },

    /** The current user's resolved permission keys. */
    currentPermissions() {
        if (!this.currentUser) return [];
        if (Array.isArray(this.currentUser.permissions)) {
            return this.currentUser.permissions;
        }
        return this.LEGACY_ACCESS[this.currentRole()] || [];
    },

    /**
     * True when the current role should be shown monetary figures
     * (cash revenue, avg cash / play, revenue-mix chart). Tickets, plays,
     * and points spent are NOT considered monetary — only literal dollar
     * columns. Mirrors the server-side view_revenue scrub.
     */
    canSeeMoney() {
        return this.canAccess('view_revenue');
    },

    ROLE_LABELS: {
        admin:   'Administrator',
        manager: 'Manager',
        tech:    'Technician'
    },

    /**
     * Returns the current user's role. Fails CLOSED to the least-privileged
     * role ('tech') when a signed-in user object somehow lacks a role, so a
     * malformed/legacy session can never be handed admin-only UI. (The server
     * enforces every gate independently and normalizes unknown roles to 'tech'
     * as well — this just keeps the client from leaking privileged controls.)
     */
    currentRole() {
        return (this.currentUser && this.currentUser.role) || 'tech';
    },

    /** True when the current user can access the named resource area. */
    canAccess(area) {
        if (!this.currentUser) return false;
        if (this.PERMISSION_AREAS.indexOf(area) === -1) return true;
        return this.currentPermissions().indexOf(area) !== -1;
    },

    init() {
        API.init(window.APP_CONFIG);
        this.currentUser = window.APP_CONFIG.user;
        this.appTimezone = window.APP_CONFIG.timezone || 'UTC';
        this.initTheme();

        window.addEventListener('hashchange', () => this.route());

        // Create toast container
        this.toastContainer = document.createElement('div');
        this.toastContainer.className = 'toast-container';
        document.body.appendChild(this.toastContainer);

        this.createThemeToggle();
        this.initGlobalKeyboardUX();

        // Remove initial loading overlay
        var appLoading = document.getElementById('app-loading');
        if (appLoading) appLoading.remove();

        this.route();
    },

    initTheme() {
        const stored = localStorage.getItem('pause-groups-theme');
        this.theme = stored === 'light' ? 'light' : 'dark';
        this.applyTheme();
    },

    applyTheme() {
        document.documentElement.setAttribute('data-theme', this.theme);
        // Keep the mobile browser chrome in step with the app theme.
        const themeColorMeta = document.querySelector('meta[name="theme-color"]');
        if (themeColorMeta) {
            themeColorMeta.setAttribute('content', this.theme === 'light' ? '#f4f6fb' : '#0a0d14');
        }
        if (this.themeToggleBtn) {
            this.themeToggleBtn.innerHTML = '';
            const icon = this.el('span', { className: 'theme-toggle-icon', 'aria-hidden': 'true' });
            icon.innerHTML = this.theme === 'dark' ? Icons.moon : Icons.sun;
            const label = this.el('span', { textContent: this.theme === 'dark' ? 'Light' : 'Dark' });
            this.themeToggleBtn.appendChild(icon);
            this.themeToggleBtn.appendChild(label);
        }
    },

    toggleTheme() {
        this.theme = this.theme === 'dark' ? 'light' : 'dark';
        localStorage.setItem('pause-groups-theme', this.theme);
        this.applyTheme();
    },

    createThemeToggle() {
        this.themeToggleBtn = this.el('button', {
            className: 'theme-toggle',
            type: 'button',
            title: 'Toggle light/dark theme',
            'aria-label': 'Toggle light or dark mode',
            onClick: () => this.toggleTheme()
        });
        this.applyTheme();
    },


    mountThemeToggle() {
        if (!this.themeToggleBtn) return;
        const host = this.currentUser
            ? document.querySelector('.sidebar-brand')
            : document.querySelector('.login-card');
        if (host) {
            host.appendChild(this.themeToggleBtn);
            this.themeToggleBtn.classList.add('theme-toggle-inline');
        }
    },



    initGlobalKeyboardUX() {
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.toggleMobileMenu(false);
            }
        });
    },

    setDocumentTitle(hash) {
        const routeTitles = {
            '#/dashboard': 'Dashboard',
            '#/games': 'Games',
            '#/performance': 'Performance',
            '#/readers': 'Reader Groups',
            '#/cards': 'Card Lookup',
            '#/groups': 'Pause Groups',
            '#/kiosks': 'Kiosks',
            '#/schedules': 'Schedules',
            '#/overrides': 'Overrides',
            '#/analytics': 'Analytics',
            '#/logs': 'Action Log',
            '#/settings': 'Settings',
            '#/login': 'Login'
        };
        const title = routeTitles[hash] || 'Control Center';
        document.title = title + ' · Castle Fun Center';
    },

    setTimezone(timezone) {
        this.appTimezone = timezone || 'UTC';
        if (window.APP_CONFIG) {
            window.APP_CONFIG.timezone = this.appTimezone;
        }
    },

    registerRoute(hash, handler) {
        this.routes[hash] = handler;
    },

    route() {
        this._navGeneration++;
        const fullHash = window.location.hash || '#/login';

        // Split hash into path + query so pages can deep-link with filters.
        // Example: "#/games?status=paused" → path "#/games", query {status: "paused"}.
        const qIdx = fullHash.indexOf('?');
        let hash = qIdx >= 0 ? fullHash.slice(0, qIdx) : fullHash;
        hash = hash.replace(/\/$/, ''); // normalize trailing slash on path
        const queryStr = qIdx >= 0 ? fullHash.slice(qIdx + 1) : '';
        const queryParams = {};
        if (queryStr) {
            try {
                new URLSearchParams(queryStr).forEach((value, key) => {
                    queryParams[key] = value;
                });
            } catch (e) { /* ignore malformed query */ }
        }

        // Cleanup previous page
        if (this.currentCleanup) {
            try { this.currentCleanup(); } catch(e) { console.warn('Page cleanup error:', e); }
            this.currentCleanup = null;
        }

        // Auth guards
        if (!this.currentUser && hash !== '#/login') {
            window.location.hash = '#/login';
            return;
        }
        if (this.currentUser && (hash === '#/login' || hash === '#/' || hash === '')) {
            window.location.hash = '#/dashboard';
            return;
        }

        // Role-based access guard. If a user types in a hash they aren't
        // allowed to reach (or follows a stale bookmark from before a role
        // change) we route them back to the dashboard with a friendly toast
        // rather than silently rendering a 403'd empty page.
        if (this.currentUser) {
            const restricted = {
                '#/analytics':   'analytics',
                '#/performance': 'analytics',
                '#/readers':     'analytics',
                '#/cards':       'cards',
                '#/settings':    'settings',
                '#/logs':        'view_logs'
            };
            const requiredArea = restricted[hash];
            if (requiredArea && !this.canAccess(requiredArea)) {
                this.toast('You do not have permission to view that page.', 'warning');
                window.location.hash = '#/dashboard';
                return;
            }
        }

        this.setAppStateClass();
        this.setDocumentTitle(hash);
        this.toggleMobileMenu(false);

        // Find matching route
        let handler = null;
        let params = {};
        for (const [pattern, h] of Object.entries(this.routes)) {
            const match = this.matchRoute(pattern, hash);
            if (match !== null) {
                handler = h;
                params = match;
                break;
            }
        }

        if (!handler) {
            window.location.hash = this.currentUser ? '#/dashboard' : '#/login';
            return;
        }

        // Expose query params under `_query` so route handlers can apply
        // deep-link filters (e.g. ?status=paused) without parsing window.location.
        params._query = queryParams;

        const appEl = document.getElementById('app');

        if (this.currentUser) {
            this.ensureLayout(appEl);
            this.updateActiveNav(hash);
            const content = document.getElementById('main-content');
            if (content) {
                content.innerHTML = '';
                const cleanup = handler.render(content, params);
                if (typeof cleanup === 'function') {
                    this.currentCleanup = cleanup;
                }
            }
        } else {
            appEl.innerHTML = '';
            const cleanup = handler.render(appEl, params);
            if (typeof cleanup === 'function') {
                this.currentCleanup = cleanup;
            }
        }

        this.mountThemeToggle();
    },


    setAppStateClass() {
        document.body.classList.toggle('app-authenticated', !!this.currentUser);
        document.body.classList.toggle('app-guest', !this.currentUser);
    },

    matchRoute(pattern, hash) {
        // Convert #/groups/:id to regex
        const parts = pattern.split('/');
        const hashParts = hash.split('/');

        if (parts.length !== hashParts.length) return null;

        const params = {};
        for (let i = 0; i < parts.length; i++) {
            if (parts[i].startsWith(':')) {
                params[parts[i].slice(1)] = hashParts[i];
            } else if (parts[i] !== hashParts[i]) {
                return null;
            }
        }
        return params;
    },

    ensureLayout(container) {
        if (document.getElementById('main-content')) return;

        container.innerHTML = '';
        const layout = this.el('div', { className: 'layout' });

        // Skip link for keyboard users — visually hidden until focused.
        const skipLink = this.el('a', {
            className: 'skip-link',
            href: '#main-content',
            textContent: 'Skip to main content',
            onClick: (e) => {
                e.preventDefault();
                const main = document.getElementById('main-content');
                if (main) {
                    main.setAttribute('tabindex', '-1');
                    main.focus({ preventScroll: false });
                }
            }
        });
        layout.appendChild(skipLink);

        // Mobile menu button + overlay
        const overlay = this.el('div', { className: 'sidebar-overlay', id: 'sidebar-overlay' });
        const menuBtn = this.el('button', {
            className: 'mobile-menu-btn',
            id: 'mobile-menu-btn',
            type: 'button',
            'aria-label': 'Open navigation menu',
            'aria-controls': 'app-sidebar',
            'aria-expanded': 'false',
            onClick: () => this.toggleMobileMenu()
        });
        menuBtn.innerHTML = Icons.menu;
        overlay.addEventListener('click', () => this.toggleMobileMenu(false));
        layout.appendChild(overlay);
        layout.appendChild(menuBtn);

        // Sidebar
        const sidebar = this.el('aside', { className: 'sidebar', id: 'app-sidebar' });

        // Brand: gradient logo mark + title + subtitle
        const brandMark = this.el('div', { className: 'sidebar-brand-mark', 'aria-hidden': 'true', textContent: 'C' });
        const brandText = this.el('div', { className: 'sidebar-brand-text' }, [
            this.el('h1', { textContent: 'Castle Fun Center' }),
            this.el('p', { textContent: 'Pause Group Automation' })
        ]);
        const brand = this.el('div', { className: 'sidebar-brand' }, [brandMark, brandText]);
        sidebar.appendChild(brand);

        // Each nav item declares the resource area it represents (matching
        // App.ACCESS keys); items without an `area` are visible to every
        // authenticated role.
        const allNavItems = [
            { hash: '#/dashboard',   icon: Icons.dashboard,   label: 'Dashboard' },
            { hash: '#/games',       icon: Icons.games,       label: 'Games' },
            { hash: '#/performance', icon: Icons.performance, label: 'Performance', area: 'analytics' },
            { hash: '#/readers',     icon: Icons.readers,     label: 'Reader Groups', area: 'analytics' },
            { hash: '#/cards',       icon: Icons.cards,       label: 'Card Lookup', area: 'cards' },
            { hash: '#/groups',    icon: Icons.groups,    label: 'Pause Groups' },
            { hash: '#/kiosks',    icon: Icons.kiosks,    label: 'Kiosks' },
            { hash: '#/schedules', icon: Icons.schedules, label: 'Schedules' },
            { hash: '#/overrides', icon: Icons.overrides, label: 'Overrides' },
            { hash: '#/analytics', icon: Icons.analytics, label: 'Analytics',   area: 'analytics' },
            { hash: '#/logs',      icon: Icons.logs,      label: 'Action Log',  area: 'view_logs' },
            { hash: '#/settings',  icon: Icons.settings,  label: 'Settings',    area: 'settings' }
        ];
        const navItems = allNavItems.filter(item => !item.area || this.canAccess(item.area));

        const nav = this.el('nav', { className: 'nav-section' });
        const navLabel = this.el('div', { className: 'nav-section-label', textContent: 'Navigation' });
        nav.appendChild(navLabel);

        navItems.forEach(item => {
            const iconEl = this.el('span', { className: 'nav-icon', 'aria-hidden': 'true' });
            iconEl.innerHTML = item.icon;
            const navItem = this.el('a', {
                className: 'nav-item',
                'data-hash': item.hash,
                href: item.hash,
                tabindex: '0',
                onClick: (e) => {
                    e.preventDefault();
                    window.location.hash = item.hash;
                    this.toggleMobileMenu(false);
                },
                onKeydown: (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        window.location.hash = item.hash;
                        this.toggleMobileMenu(false);
                    }
                }
            }, [
                iconEl,
                this.el('span', { className: 'nav-label', textContent: item.label })
            ]);
            nav.appendChild(navItem);
        });
        sidebar.appendChild(nav);

        // Sidebar footer \u2014 avatar circle + truncated display name + role badge + logout icon button
        const initial = (this.currentUser.display_name || this.currentUser.username || '?').trim().charAt(0).toUpperCase();
        const role = this.currentRole();
        // Custom roles carry their display name from the server; the static
        // labels only cover the three system roles.
        const roleLabel = this.currentUser.role_name || this.ROLE_LABELS[role] || role;
        const userMeta = this.el('div', { className: 'sidebar-user-meta' }, [
            this.el('span', { className: 'sidebar-user-name', title: this.currentUser.display_name, textContent: this.currentUser.display_name }),
            this.el('span', { className: 'role-pill role-pill-' + role, textContent: roleLabel, title: 'Signed in as ' + roleLabel })
        ]);
        const userBlock = this.el('div', { className: 'sidebar-user' }, [
            this.el('span', { className: 'sidebar-user-avatar', textContent: initial, 'aria-hidden': 'true' }),
            userMeta
        ]);
        const logoutBtn = this.el('button', {
            className: 'btn btn-ghost btn-sm sidebar-logout',
            type: 'button',
            title: 'Sign out',
            'aria-label': 'Sign out',
            onClick: async () => {
                try {
                    await API.post('auth/logout');
                } catch(e) {}
                this.currentUser = null;
                window.location.hash = '#/login';
            }
        });
        logoutBtn.innerHTML = Icons.logout;
        const footer = this.el('div', { className: 'sidebar-footer' }, [userBlock, logoutBtn]);
        sidebar.appendChild(footer);

        layout.appendChild(sidebar);

        // Main content area — `<main>` makes this a real landmark for
        // assistive tech instead of an anonymous <div>.
        const main = this.el('main', {
            className: 'main-content',
            id: 'main-content',
            role: 'main'
        });
        layout.appendChild(main);

        container.appendChild(layout);
    },

    updateActiveNav(hash) {
        document.querySelectorAll('.nav-item').forEach(item => {
            const itemHash = item.getAttribute('data-hash');
            const isActive = itemHash && hash.startsWith(itemHash);
            item.classList.toggle('active', !!isActive);
            if (isActive) {
                item.setAttribute('aria-current', 'page');
            } else {
                item.removeAttribute('aria-current');
            }
        });
    },

    // ---- Toast Notifications ----
    toast(message, type, duration) {
        type = type || 'info';
        duration = duration || 4000;
        const iconSvg = type === 'success' ? Icons.check
                      : type === 'error'   ? Icons.xmark
                      : type === 'warning' ? Icons.bang
                      : Icons.info;
        const iconEl = this.el('span', { className: 'toast-icon', 'aria-hidden': 'true' });
        iconEl.innerHTML = iconSvg;
        const toast = this.el('div', {
            className: 'toast toast-' + type,
            role: type === 'error' ? 'alert' : 'status',
            'aria-live': type === 'error' ? 'assertive' : 'polite'
        }, [
            iconEl,
            this.el('span', { className: 'toast-message', textContent: message })
        ]);
        this.toastContainer.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('toast-exit');
            setTimeout(() => toast.remove(), 220);
        }, duration);
    },

    // ---- Modal ----
    showModal(titleText, contentEl, footerEl) {
        this.hideModal();
        // Remember the element that opened the modal so we can restore focus
        // when it closes \u2014 important for keyboard / screen-reader users who
        // would otherwise lose their place in the page.
        const previouslyFocused = document.activeElement;

        const overlay = this.el('div', { className: 'modal-overlay', id: 'modal-overlay' });
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) this.hideModal();
        });

        const titleId = 'modal-title-' + Date.now().toString(36);
        const modal = this.el('div', {
            className: 'modal',
            role: 'dialog',
            'aria-modal': 'true',
            'aria-labelledby': titleId
        });
        const closeBtn = this.el('button', {
            className: 'modal-close',
            type: 'button',
            'aria-label': 'Close dialog',
            title: 'Close',
            onClick: () => this.hideModal()
        });
        closeBtn.innerHTML = Icons.xmark;
        const header = this.el('div', { className: 'modal-header' }, [
            this.el('div', { className: 'modal-title', id: titleId, textContent: titleText }),
            closeBtn
        ]);
        modal.appendChild(header);

        if (contentEl) {
            const body = this.el('div', { className: 'modal-body' });
            body.appendChild(contentEl);
            modal.appendChild(body);
        }
        if (footerEl) {
            const footer = this.el('div', { className: 'modal-footer' });
            footer.appendChild(footerEl);
            modal.appendChild(footer);
        }

        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        document.body.classList.add('modal-open');
        overlay._previouslyFocused = previouslyFocused;

        // Close on Escape key + trap focus inside modal
        const keyHandler = (e) => {
            if (e.key === 'Escape') { this.hideModal(); return; }
            if (e.key === 'Tab') {
                const focusable = modal.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
                if (focusable.length === 0) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (e.shiftKey) {
                    if (document.activeElement === first || !modal.contains(document.activeElement)) {
                        e.preventDefault(); last.focus();
                    }
                } else {
                    if (document.activeElement === last) { e.preventDefault(); first.focus(); }
                }
            }
        };
        document.addEventListener('keydown', keyHandler);
        overlay._escHandler = keyHandler;

        // Auto-focus the first useful focusable element. Prefer inputs over the
        // close button so users land on the action they came to perform.
        requestAnimationFrame(() => {
            const preferred = modal.querySelector('.modal-body input:not([type="hidden"]):not([disabled]), .modal-body select:not([disabled]), .modal-body textarea:not([disabled])');
            const fallback = modal.querySelector('.modal-footer button:not([disabled]), button:not(.modal-close):not([disabled]), [href], [tabindex]:not([tabindex="-1"])');
            const target = preferred || fallback || closeBtn;
            if (target) target.focus();
        });
    },

    hideModal() {
        const existing = document.getElementById('modal-overlay');
        if (existing) {
            if (existing._escHandler) {
                document.removeEventListener('keydown', existing._escHandler);
            }
            const restoreTo = existing._previouslyFocused;
            existing.remove();
            document.body.classList.remove('modal-open');
            // Restore focus to the trigger element (if it still exists in the
            // DOM and is focusable). Skip restoration if focus has already
            // moved into a new modal that opened in place.
            if (restoreTo && document.body.contains(restoreTo) &&
                typeof restoreTo.focus === 'function' &&
                !document.getElementById('modal-overlay')) {
                try { restoreTo.focus(); } catch (e) {}
            }
        }
    },

    /**
     * Show a confirm dialog. Accepts a plain string (legacy) or an options
     * object: { title, message, confirmLabel, cancelLabel, danger }.
     * Resolves true/false. The confirm button defaults to a danger style;
     * pass `danger: false` for non-destructive confirmations.
     */
    confirm(messageOrOpts) {
        const opts = typeof messageOrOpts === 'string'
            ? { message: messageOrOpts }
            : (messageOrOpts || {});
        const title = opts.title || 'Confirm Action';
        const message = opts.message || 'Are you sure?';
        const confirmLabel = opts.confirmLabel || 'Confirm';
        const cancelLabel = opts.cancelLabel || 'Cancel';
        const danger = opts.danger !== false;
        return new Promise((resolve) => {
            const body = this.el('p', { textContent: message });
            const cancelBtn = this.el('button', {
                className: 'btn btn-secondary',
                type: 'button',
                textContent: cancelLabel,
                onClick: () => { this.hideModal(); resolve(false); }
            });
            const confirmBtn = this.el('button', {
                className: 'btn ' + (danger ? 'btn-danger' : 'btn-primary'),
                type: 'button',
                textContent: confirmLabel,
                onClick: () => { this.hideModal(); resolve(true); }
            });
            const footer = this.el('div', { className: 'flex gap-sm' }, [cancelBtn, confirmBtn]);
            this.showModal(title, body, footer);
            // Focus the safer "Cancel" button by default for destructive
            // confirmations so users can't dismiss accidentally with Enter.
            requestAnimationFrame(() => {
                if (danger) cancelBtn.focus();
                else confirmBtn.focus();
            });
        });
    },

    // ---- DOM Utility ----
    el(tag, attrs, children) {
        const elem = document.createElement(tag);
        if (attrs) {
            for (const [key, value] of Object.entries(attrs)) {
                if (key === 'className') elem.className = value;
                else if (key === 'textContent') elem.textContent = value;
                else if (key === 'innerHTML') { /* skip for XSS safety */ }
                else if (key.startsWith('on') && key.length > 2) {
                    const event = key.charAt(2).toLowerCase() + key.slice(3);
                    elem.addEventListener(event, value);
                }
                else if (key === 'style' && typeof value === 'object') {
                    Object.assign(elem.style, value);
                }
                else if (key === 'value') {
                    elem.value = value; // Set as property, not attribute (required for textarea)
                }
                else if (key === 'disabled' || key === 'checked' || key === 'selected' || key === 'readonly') {
                    elem[key] = !!value;
                }
                else elem.setAttribute(key, value);
            }
        }
        if (children) {
            const arr = Array.isArray(children) ? children : [children];
            arr.forEach(child => {
                if (typeof child === 'string') elem.appendChild(document.createTextNode(child));
                else if (child instanceof Node) elem.appendChild(child);
            });
        }
        return elem;
    },

    // ---- Formatting Utilities ----
    toUtcDate(dateStr) {
        if (!dateStr) return null;
        if (dateStr instanceof Date) return dateStr;
        const normalized = String(dateStr).trim().replace(' ', 'T');
        if (!normalized) return null;
        const hasTimezone = /[zZ]|[+-]\d{2}:?\d{2}$/.test(normalized);
        const value = hasTimezone ? normalized : normalized + 'Z';
        const d = new Date(value);
        return Number.isNaN(d.getTime()) ? null : d;
    },

    formatDate(dateStr) {
        if (!dateStr) return '-';
        const d = this.toUtcDate(dateStr);
        if (!d) return '-';
        return new Intl.DateTimeFormat('en-US', {
            month: 'short', day: 'numeric', year: 'numeric', timeZone: this.appTimezone
        }).format(d);
    },

    formatDatetime(dateStr) {
        if (!dateStr) return '-';
        const d = this.toUtcDate(dateStr);
        if (!d) return '-';
        return new Intl.DateTimeFormat('en-US', {
            month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: this.appTimezone
        }).format(d);
    },

    formatTime(timeStr) {
        if (!timeStr) return '-';
        const [h, m] = timeStr.split(':');
        const hour = parseInt(h);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const h12 = hour % 12 || 12;
        return h12 + ':' + m + ' ' + ampm;
    },

    formatRelative(dateStr) {
        if (!dateStr) return '';
        const now = new Date();
        const d = this.toUtcDate(dateStr);
        if (!d) return '';
        const diffMs = d - now;
        const absMin = Math.round(Math.abs(diffMs) / 60000);
        const isPast = diffMs < 0;

        if (absMin < 60) {
            return isPast ? absMin + 'm ago' : 'in ' + absMin + 'm';
        }

        const absHr = Math.round(absMin / 60);
        if (absHr < 24) {
            return isPast ? absHr + 'h ago' : 'in ' + absHr + 'h';
        }

        const absDays = Math.round(absHr / 24);
        return isPast ? absDays + 'd ago' : 'in ' + absDays + 'd';
    },

    statusBadge(status) {
        const cls = status === 'enabled' ? 'badge-enabled' :
                    status === 'paused' ? 'badge-paused' :
                    status === 'outOfService' ? 'badge-out-of-service' : '';
        // Human labels — keep in sync with the groups/kiosks pages so the
        // same state never reads as "enabled" here and "Running" there.
        const label = status === 'enabled' ? 'Running'
                    : status === 'paused' ? 'Paused'
                    : status === 'outOfService' ? 'Out of service'
                    : status;
        const dotCls = status === 'enabled' ? 'badge-dot badge-dot-success'
                     : status === 'paused' ? 'badge-dot badge-dot-warning'
                     : status === 'outOfService' ? 'badge-dot badge-dot-danger'
                     : 'badge-dot';
        return this.el('span', {
            className: 'badge ' + cls,
            role: 'status',
            'aria-label': 'Status: ' + label
        }, [
            this.el('span', { className: dotCls, 'aria-hidden': 'true' }),
            this.el('span', { textContent: label })
        ]);
    },

    loading() {
        return this.el('div', { className: 'loading-overlay' }, [
            this.el('div', { className: 'spinner' }),
            this.el('span', { textContent: 'Loading...' })
        ]);
    },

    toggleMobileMenu(forceState) {
        const sidebar = document.getElementById('app-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const menuBtn = document.getElementById('mobile-menu-btn');
        if (!sidebar) return;
        const isOpen = typeof forceState === 'boolean' ? forceState : !sidebar.classList.contains('sidebar-open');
        sidebar.classList.toggle('sidebar-open', isOpen);
        if (overlay) overlay.classList.toggle('active', isOpen);
        if (menuBtn) {
            menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            menuBtn.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
        }
    },

    /**
     * Returns a debounced version of fn that delays execution by `delay` ms.
     * Calling the returned function again before delay expires resets the timer.
     */
    debounce(fn, delay) {
        let timer = null;
        return function() {
            const ctx = this, args = arguments;
            if (timer) clearTimeout(timer);
            timer = setTimeout(function() { fn.apply(ctx, args); }, delay);
        };
    },

    /**
     * Runs callback on an interval only while the page is visible.
     * Reduces unnecessary polling load during long-running browser sessions.
     */
    createVisibilityAwareInterval(callback, intervalMs, options) {
        const config = Object.assign({ runImmediately: false, runOnVisible: true }, options || {});
        let timer = null;

        const stop = () => {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        };

        const start = () => {
            if (timer || document.hidden) return;
            timer = setInterval(callback, intervalMs);
            if (config.runImmediately) callback();
        };

        const handleVisibilityChange = () => {
            if (document.hidden) {
                stop();
                return;
            }
            if (config.runOnVisible) callback();
            start();
        };

        document.addEventListener('visibilitychange', handleVisibilityChange);
        start();

        return function cleanup() {
            document.removeEventListener('visibilitychange', handleVisibilityChange);
            stop();
        };
    },

    /**
     * Navigation generation counter. Incremented on every route change.
     * Async operations can capture this value before starting and check
     * it after completing to avoid updating a page the user has left.
     */
    _navGeneration: 0,

    /**
     * Returns the current navigation generation. Async code should capture
     * this before an await and compare after: if it changed, bail out.
     */
    navGeneration() {
        return this._navGeneration;
    },

    emptyState(icon, text, actionBtn) {
        const children = [
            this.el('div', { className: 'empty-state-icon', textContent: icon }),
            this.el('div', { className: 'empty-state-text', textContent: text })
        ];
        if (actionBtn) {
            children.push(this.el('div', { className: 'empty-state-action' }, [actionBtn]));
        }
        return this.el('div', { className: 'empty-state' }, children);
    },

    /**
     * Make a card-like element clickable via mouse and keyboard.
     *
     * `onActivate` runs on click or Enter/Space when the card has focus.
     * Clicks that originate inside an interactive descendant (button, link,
     * input, select, textarea) are ignored so nested action buttons keep
     * working without `stopPropagation` boilerplate at every call site.
     *
     * Options:
     *   title  Tooltip / aria-label hint for the navigation target.
     *   role   ARIA role (default 'link'). Pass 'button' for in-page actions.
     */
    makeCardClickable(el, onActivate, options) {
        if (!el || typeof onActivate !== 'function') return el;
        const opts = options || {};
        el.classList.add('is-clickable');
        // Skip role override on table-internal elements — they have semantic
        // roles ('row', 'cell') the screen reader relies on. Tabindex still
        // makes them keyboard-focusable.
        const tag = (el.tagName || '').toUpperCase();
        const isTablePart = tag === 'TR' || tag === 'TD' || tag === 'TH';
        if (!isTablePart) {
            el.setAttribute('role', opts.role || 'link');
        }
        el.setAttribute('tabindex', '0');
        if (opts.title) {
            el.setAttribute('title', opts.title);
            if (!el.hasAttribute('aria-label')) el.setAttribute('aria-label', opts.title);
        }
        const handler = (e) => {
            if (e && e.target && e.target.closest &&
                e.target.closest('button, a, input, select, textarea, label')) {
                return;
            }
            onActivate(e);
        };
        el.addEventListener('click', handler);
        el.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                if (e.target && e.target.closest &&
                    e.target.closest('button, a, input, select, textarea, label')) {
                    return;
                }
                e.preventDefault();
                onActivate(e);
            }
        });
        return el;
    },

    /**
     * Convenience wrapper around `makeCardClickable` that navigates to a
     * hash route on activation. `target` may be a string or a function
     * returning the hash to navigate to.
     */
    makeCardLink(el, target, options) {
        return this.makeCardClickable(el, () => {
            const hash = typeof target === 'function' ? target() : target;
            if (hash) window.location.hash = hash;
        }, options);
    },

    /**
     * Compute a single page slice of an item list.
     * Clamps page so it never falls outside [1, totalPages].
     * Returns { items, total, page, pageSize, totalPages, startIdx, endIdx }
     * where startIdx/endIdx are 1-indexed for "Showing X-Y of Z" display.
     */
    paginate(items, page, pageSize) {
        const total = items ? items.length : 0;
        const ps = Math.max(1, parseInt(pageSize, 10) || 25);
        const totalPages = Math.max(1, Math.ceil(total / ps));
        let p = parseInt(page, 10) || 1;
        if (p > totalPages) p = totalPages;
        if (p < 1) p = 1;
        const start = (p - 1) * ps;
        const slice = items ? items.slice(start, start + ps) : [];
        return {
            items: slice,
            total: total,
            page: p,
            pageSize: ps,
            totalPages: totalPages,
            startIdx: total === 0 ? 0 : start + 1,
            endIdx: Math.min(start + ps, total)
        };
    },

    /**
     * Render a complete pagination bar (info + controls + page-size selector).
     * `state` must expose `page`, `pageSize`, `totalItems`. The bar updates
     * state in place and calls `onChange` after every interaction.
     *
     * Options:
     *   pageSizeOptions  array of page-size choices (default [25, 50, 100, 200])
     *   itemLabel        word used in "Showing X-Y of Z entries" (default 'entries')
     *   showPageNumbers  show numbered buttons around the current page (default true)
     */
    buildPaginationBar(state, onChange, options) {
        const opts = Object.assign({
            pageSizeOptions: [25, 50, 100, 200],
            itemLabel: 'entries',
            showPageNumbers: true
        }, options || {});
        const total = state.totalItems || 0;
        const ps = state.pageSize || opts.pageSizeOptions[0];
        const totalPages = Math.max(1, Math.ceil(total / ps));
        if (state.page > totalPages) state.page = totalPages;
        if (state.page < 1) state.page = 1;
        const cur = state.page;
        const startIdx = total === 0 ? 0 : (cur - 1) * ps + 1;
        const endIdx = Math.min(cur * ps, total);

        const bar = this.el('div', { className: 'pagination-bar' });

        const infoText = total === 0
            ? 'No ' + opts.itemLabel
            : 'Showing ' + startIdx + '–' + endIdx + ' of ' + total.toLocaleString() + ' ' + opts.itemLabel;

        const sizeSelect = this.el('select', {
            className: 'page-size-select',
            'aria-label': 'Items per page',
            onChange: function() {
                state.pageSize = parseInt(this.value, 10);
                state.page = 1;
                onChange();
            }
        }, opts.pageSizeOptions.map((size) => {
            const opt = this.el('option', { value: String(size), textContent: size + ' / page' });
            if (size === ps) opt.selected = true;
            return opt;
        }));

        bar.appendChild(this.el('div', { className: 'pagination-info' }, [
            this.el('span', { textContent: infoText }),
            sizeSelect
        ]));

        const controls = this.el('div', { className: 'pagination-controls' });

        controls.appendChild(this.el('button', {
            className: 'btn btn-ghost btn-sm', textContent: '«',
            disabled: cur <= 1, title: 'First page',
            'aria-label': 'First page',
            onClick: function() { state.page = 1; onChange(); }
        }));
        controls.appendChild(this.el('button', {
            className: 'btn btn-ghost btn-sm', textContent: '‹',
            disabled: cur <= 1, title: 'Previous page',
            'aria-label': 'Previous page',
            onClick: function() { state.page = Math.max(1, cur - 1); onChange(); }
        }));

        if (opts.showPageNumbers && totalPages > 1) {
            const start = Math.max(1, cur - 2);
            const end = Math.min(totalPages, cur + 2);
            const pageBtn = (n) => this.el('button', {
                className: 'btn btn-sm ' + (n === cur ? 'btn-primary' : 'btn-ghost'),
                textContent: String(n),
                'aria-label': 'Page ' + n,
                'aria-current': n === cur ? 'page' : null,
                onClick: function() { state.page = n; onChange(); }
            });
            const ellipsis = () => this.el('span', {
                textContent: '…',
                style: { padding: '0.25rem 0.35rem', color: 'var(--text-muted)' }
            });
            if (start > 1) {
                controls.appendChild(pageBtn(1));
                if (start > 2) controls.appendChild(ellipsis());
            }
            for (let i = start; i <= end; i++) controls.appendChild(pageBtn(i));
            if (end < totalPages) {
                if (end < totalPages - 1) controls.appendChild(ellipsis());
                controls.appendChild(pageBtn(totalPages));
            }
        } else {
            controls.appendChild(this.el('span', {
                className: 'text-sm',
                style: { padding: '0 0.5rem' },
                textContent: cur + ' / ' + totalPages
            }));
        }

        controls.appendChild(this.el('button', {
            className: 'btn btn-ghost btn-sm', textContent: '›',
            disabled: cur >= totalPages, title: 'Next page',
            'aria-label': 'Next page',
            onClick: function() { state.page = Math.min(totalPages, cur + 1); onChange(); }
        }));
        controls.appendChild(this.el('button', {
            className: 'btn btn-ghost btn-sm', textContent: '»',
            disabled: cur >= totalPages, title: 'Last page',
            'aria-label': 'Last page',
            onClick: function() { state.page = totalPages; onChange(); }
        }));

        bar.appendChild(controls);
        return bar;
    },

    /**
     * Build a labelled, debounced search input. `onSearch` fires after the
     * user pauses typing for `debounceMs` (default 200ms) — never on each
     * keystroke — so re-renders stay smooth even with hundreds of items.
     */
    buildSearchInput(opts) {
        const cfg = Object.assign({
            placeholder: 'Search…',
            value: '',
            debounceMs: 200,
            ariaLabel: 'Search',
            className: 'form-input'
        }, opts || {});
        const input = this.el('input', {
            className: cfg.className,
            type: 'search',
            placeholder: cfg.placeholder,
            value: cfg.value,
            'aria-label': cfg.ariaLabel
        });
        if (typeof cfg.onSearch === 'function') {
            const handler = this.debounce(function() {
                cfg.onSearch((input.value || '').trim());
            }, cfg.debounceMs);
            input.addEventListener('input', handler);
        }
        return input;
    },

    /**
     * Filter a collection by a search term against the listed string fields.
     * Term is matched case-insensitively as a substring; fields can be either
     * direct property names or accessor functions taking the item.
     */
    matchesSearch(item, term, fields) {
        if (!term) return true;
        const t = String(term).toLowerCase();
        for (let i = 0; i < fields.length; i++) {
            const f = fields[i];
            const v = typeof f === 'function' ? f(item) : item[f];
            if (v != null && String(v).toLowerCase().indexOf(t) !== -1) return true;
        }
        return false;
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());
