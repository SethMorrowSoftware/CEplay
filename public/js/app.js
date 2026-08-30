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
    tags:      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.6 13.3 13.3 20.6a2 2 0 0 1-2.9 0L3 13.2V3h10.2l7.4 7.4a2 2 0 0 1 0 2.9z"/><circle cx="7.6" cy="7.6" r="1.1"/></svg>',
    cards:     '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/></svg>',
    groups:    '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/></svg>',
    kiosks:    '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="14" rx="2"/><path d="M9 21h6M12 17v4"/><circle cx="12" cy="10" r="0.9"/></svg>',
    schedules: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    overrides: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>',
    analytics: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21V8M9 21V4M15 21v-9M21 21v-6"/></svg>',
    performance:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 14l3-4 3 3 4-6"/><circle cx="17" cy="7" r="1.3" fill="currentColor" stroke="none"/></svg>',
    readers:   '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
    labor:     '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3.5 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M3.5 12h2M18.5 12h2" stroke="currentColor" stroke-width="2"/></svg>',
    cardloads: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M2.5 9.5h19" stroke="currentColor" stroke-width="2"/><path d="M6 15h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    tickets:   '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M15 6v12" stroke="currentColor" stroke-width="2" stroke-dasharray="2 2"/></svg>',
    revenue:   '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 12V3M12 12l7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    redemption:'<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="8" width="18" height="12.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3 12.5h18M12 8v12.5" stroke="currentColor" stroke-width="2"/><path d="M12 8C12 8 9.5 3.5 7 5s2 3 5 3zM12 8s2.5-4.5 5-3-2 3-5 3z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>',
    promotions:'<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="9" width="18" height="11" rx="1.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3 13h18" stroke="currentColor" stroke-width="2"/><path d="M12 9V5.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M12 6.5C12 6.5 10.5 3.8 8.8 4.9s1 2.6 3.2 1.6zM12 6.5s1.5-2.7 3.2-1.6-1 2.6-3.2 1.6z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    items:     '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 8.5 12 12.8 3.5 8.5 12 4.2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M3.5 8.5v7L12 19.8l8.5-4.3v-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M12 12.8v7" stroke="currentColor" stroke-width="2"/></svg>',
    explorer:  '<svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="5.5" rx="7.5" ry="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="M4.5 5.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6" fill="none" stroke="currentColor" stroke-width="2"/><path d="M4.5 11.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
    logs:      '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14M5 9h14M5 14h10M5 19h14"/></svg>',
    birthdays: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.2c.9 1 1.4 1.8 1.4 2.4a1.4 1.4 0 0 1-2.8 0c0-.6.5-1.4 1.4-2.4z"/><path d="M12 6.2v3.1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M4 14.4c1.3 0 1.3 1.2 2.7 1.2s1.3-1.2 2.7-1.2 1.3 1.2 2.6 1.2 1.3-1.2 2.7-1.2 1.3 1.2 2.6 1.2 1.4-1.2 2.7-1.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 14.4v-1.5a2.6 2.6 0 0 1 2.6-2.6h10.8a2.6 2.6 0 0 1 2.6 2.6v1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M4 17.6v2.2a1.6 1.6 0 0 0 1.6 1.6h12.8a1.6 1.6 0 0 0 1.6-1.6v-2.2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>',
    anniversaries: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 3.5h9v5a4.5 4.5 0 0 1-9 0z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M7.5 5.2H5.2a2.4 2.4 0 0 0 2.6 4M16.5 5.2h2.3a2.4 2.4 0 0 1-2.6 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M12 13v3.4M9.5 16.4h5M7.8 20.5h8.4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M9.5 16.4c-.4 2-.9 3.2-1.7 4.1M14.5 16.4c.4 2 .9 3.2 1.7 4.1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
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
        'view_dashboard', 'view_games', 'view_tags', 'view_groups',
        'view_kiosks', 'view_schedules', 'view_overrides',
        'analytics', 'view_revenue', 'cards', 'manual_control',
        'overrides_manage', 'groups_manage', 'reader_groups_manage',
        'promotions_manage', 'items_manage',
        'schedules_manage', 'settings', 'data_explorer', 'users', 'view_logs',
        'view_birthdays', 'birthdays_manage',
        'view_anniversaries', 'anniversaries_manage'
    ],

    /**
     * Fallback map for a stale session whose user object predates the
     * permission injection (e.g. a tab left open across the upgrade).
     * Mirrors the seeded system roles; one reload/re-login replaces it
     * with the real resolved list.
     */
    LEGACY_ACCESS: {
        admin:   ['view_dashboard', 'view_games', 'view_tags', 'view_groups', 'view_kiosks', 'view_schedules', 'view_overrides', 'analytics', 'view_revenue', 'cards', 'manual_control', 'overrides_manage', 'groups_manage', 'reader_groups_manage', 'promotions_manage', 'items_manage', 'schedules_manage', 'settings', 'data_explorer', 'users', 'view_logs', 'view_birthdays', 'birthdays_manage', 'view_anniversaries', 'anniversaries_manage'],
        manager: ['view_dashboard', 'view_games', 'view_tags', 'view_groups', 'view_kiosks', 'view_schedules', 'view_overrides', 'analytics', 'view_revenue', 'cards', 'manual_control', 'overrides_manage', 'groups_manage', 'reader_groups_manage', 'promotions_manage', 'items_manage', 'schedules_manage', 'view_logs'],
        tech:    ['view_dashboard', 'view_games', 'view_tags', 'view_groups', 'view_kiosks', 'view_schedules', 'view_overrides', 'analytics', 'manual_control', 'overrides_manage', 'settings', 'users', 'view_birthdays', 'birthdays_manage', 'view_anniversaries', 'anniversaries_manage']
    },

    /**
     * Sections in sidebar order with the permission that makes each
     * visible. Single source for the nav, the route guard, and the
     * "where do I land?" fallback — so hiding a section can never strand
     * a user on a page they can't see.
     */
    SECTION_AREAS: {
        '#/dashboard':   'view_dashboard',
        '#/games':       'view_games',
        '#/tags':        'view_tags',
        '#/performance': 'analytics',
        '#/readers':     'analytics',
        '#/labor':       'view_revenue',
        '#/cardloads':   'view_revenue',
        '#/tickets':     'view_revenue',
        '#/revenue':     'view_revenue',
        '#/redemption':  'view_revenue',
        '#/promotions':  'analytics',
        '#/items':       'analytics',
        '#/cards':       'cards',
        '#/groups':      'view_groups',
        '#/kiosks':      'view_kiosks',
        '#/schedules':   'view_schedules',
        '#/overrides':   'view_overrides',
        '#/analytics':   'analytics',
        '#/logs':        'view_logs',
        '#/explorer':    'data_explorer',
        '#/birthdays':   'view_birthdays',
        '#/anniversaries': 'view_anniversaries',
        '#/settings':    'settings'
    },

    /** First section the current user may see, or null if their role hides everything. */
    defaultHash() {
        for (const [hash, area] of Object.entries(this.SECTION_AREAS)) {
            if (this.canAccess(area)) return hash;
        }
        return null;
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

        // PWA: register the service worker (installability + offline shell).
        // Non-fatal on failure or on browsers without support — the app is
        // fully functional as a plain web page.
        if ('serviceWorker' in navigator) {
            const swBase = window.APP_CONFIG.basePath || '';
            navigator.serviceWorker.register(swBase + '/sw.js', { scope: swBase + '/' })
                .catch(() => {});
        }

        window.addEventListener('hashchange', () => this.route());

        // Landing spot for the pathological case where a role has every
        // section unticked — renders a plain notice instead of bounce-looping
        // between guards.
        this.registerRoute('#/no-access', { render: (container) => {
            container.appendChild(this.el('div', { className: 'card', style: { maxWidth: '30rem', margin: '10vh auto' } }, [
                this.el('div', { className: 'card-body' }, [
                    this.el('h2', { textContent: 'No pages enabled' }),
                    this.el('p', { className: 'text-secondary', style: { marginTop: '0.5rem' }, textContent:
                        'Your role currently has no sections enabled. Ask an administrator to enable at least one page for your role in Settings → Roles.' })
                ])
            ]));
        } });

        // Create toast container
        this.toastContainer = document.createElement('div');
        this.toastContainer.className = 'toast-container';
        document.body.appendChild(this.toastContainer);

        this.createThemeToggle();
        this.initGlobalKeyboardUX();
        this.startAccessRefresh();

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
            '#/no-access': 'No access',
            '#/dashboard': 'Dashboard',
            '#/games': 'Games',
            '#/tags': 'Tag Board',
            '#/performance': 'Performance',
            '#/readers': 'Reader Groups',
            '#/labor': 'Go-Kart Labor',
            '#/cardloads': 'Card Loads',
            '#/tickets': 'Ticket Trends',
            '#/revenue': 'Revenue Mix',
            '#/redemption': 'Redemption Economics',
            '#/promotions': 'Promotional Cards',
            '#/items': 'Item Watch',
            '#/cards': 'Card Lookup',
            '#/groups': 'Pause Groups',
            '#/kiosks': 'Kiosks',
            '#/schedules': 'Schedules',
            '#/overrides': 'Overrides',
            '#/analytics': 'Analytics',
            '#/logs': 'Action Log',
            '#/explorer': 'Database Explorer',
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
            window.location.hash = this.defaultHash() || '#/no-access';
            return;
        }

        // Role-based access guard. If a user types in a hash they aren't
        // allowed to reach (or follows a stale bookmark from before a role
        // change) we route them to their first visible section with a
        // friendly toast rather than silently rendering a 403'd empty page.
        if (this.currentUser && hash !== '#/no-access') {
            const requiredArea = this.SECTION_AREAS[hash];
            if (requiredArea && !this.canAccess(requiredArea)) {
                this.toast('You do not have permission to view that page.', 'warning');
                window.location.hash = this.defaultHash() || '#/no-access';
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
            window.location.hash = this.currentUser ? (this.defaultHash() || '#/no-access') : '#/login';
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
                this.renderRoute(handler, content, params);
                // Move focus to the fresh page so keyboard and screen-reader
                // users aren't stranded on the old nav link; preventScroll
                // keeps the viewport where the browser put it.
                content.setAttribute('tabindex', '-1');
                try { content.focus({ preventScroll: true }); } catch (e) {}
            }
        } else {
            appEl.innerHTML = '';
            this.renderRoute(handler, appEl, params);
        }

        this.mountThemeToggle();
    },

    /**
     * Run a route handler's render inside a guard: a synchronous throw in
     * one page must never leave #main-content permanently blank with the
     * error only in the console. Renders a retry-able error card instead.
     */
    renderRoute(handler, container, params) {
        try {
            const cleanup = handler.render(container, params);
            if (typeof cleanup === 'function') {
                this.currentCleanup = cleanup;
            }
        } catch (err) {
            console.error('Page render error:', err);
            container.innerHTML = '';
            const retryBtn = this.el('button', {
                className: 'btn btn-primary',
                type: 'button',
                textContent: 'Reload page',
                onClick: () => window.location.reload()
            });
            container.appendChild(this.el('div', { className: 'card', style: { maxWidth: '32rem', margin: '10vh auto' } }, [
                this.el('div', { className: 'card-body' }, [
                    this.el('h2', { textContent: 'Something went wrong' }),
                    this.el('p', { className: 'text-secondary', style: { margin: '0.5rem 0 1rem' }, textContent:
                        'This page hit an unexpected error while rendering. Reloading usually clears it — if it keeps happening, check the browser console and let an administrator know.' }),
                    retryBtn
                ])
            ]));
        }
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
            { hash: '#/tags',        icon: Icons.tags,        label: 'Tag Board' },
            { hash: '#/performance', icon: Icons.performance, label: 'Performance' },
            { hash: '#/readers',     icon: Icons.readers,     label: 'Reader Groups' },
            { hash: '#/labor',       icon: Icons.labor,       label: 'Go-Kart Labor' },
            { hash: '#/cardloads',   icon: Icons.cardloads,   label: 'Card Loads' },
            { hash: '#/tickets',     icon: Icons.tickets,     label: 'Ticket Trends' },
            { hash: '#/revenue',     icon: Icons.revenue,     label: 'Revenue Mix' },
            { hash: '#/redemption',  icon: Icons.redemption,  label: 'Redemption' },
            { hash: '#/promotions',  icon: Icons.promotions,  label: 'Promo Cards' },
            { hash: '#/items',       icon: Icons.items,       label: 'Item Watch' },
            { hash: '#/cards',       icon: Icons.cards,       label: 'Card Lookup' },
            { hash: '#/groups',    icon: Icons.groups,    label: 'Pause Groups' },
            { hash: '#/kiosks',    icon: Icons.kiosks,    label: 'Kiosks' },
            { hash: '#/schedules', icon: Icons.schedules, label: 'Schedules' },
            { hash: '#/overrides', icon: Icons.overrides, label: 'Overrides' },
            { hash: '#/analytics', icon: Icons.analytics, label: 'Analytics' },
            { hash: '#/logs',      icon: Icons.logs,      label: 'Action Log' },
            { hash: '#/explorer',  icon: Icons.explorer,  label: 'DB Explorer' },
            { hash: '#/birthdays', icon: Icons.birthdays, label: 'Birthdays' },
            { hash: '#/anniversaries', icon: Icons.anniversaries, label: 'Anniversaries' },
            { hash: '#/settings',  icon: Icons.settings,  label: 'Settings' }
        ];
        // Every section is gated by its SECTION_AREAS permission — a role
        // lacking the key simply doesn't get the nav item.
        const navItems = allNavItems.filter(item => {
            const area = this.SECTION_AREAS[item.hash];
            return !area || this.canAccess(area);
        });

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
                // The service worker keeps the last authenticated shell (it
                // embeds the user's name, role, and permission list) as the
                // offline fallback. A deliberate sign-out on a shared device
                // must not leave that behind — drop the PWA caches.
                if (window.caches && caches.keys) {
                    try {
                        const keys = await caches.keys();
                        await Promise.all(keys
                            .filter(k => k.indexOf('ceplay') === 0)
                            .map(k => caches.delete(k)));
                    } catch (e) {}
                }
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
        // Errors linger longer — their messages carry failure details the
        // user may need to read (and often act on).
        duration = duration || (type === 'error' ? 8000 : 4000);
        const iconSvg = type === 'success' ? Icons.check
                      : type === 'error'   ? Icons.xmark
                      : type === 'warning' ? Icons.bang
                      : Icons.info;
        const iconEl = this.el('span', { className: 'toast-icon', 'aria-hidden': 'true' });
        iconEl.innerHTML = iconSvg;
        const toast = this.el('div', {
            className: 'toast toast-' + type,
            role: type === 'error' ? 'alert' : 'status',
            'aria-live': type === 'error' ? 'assertive' : 'polite',
            title: 'Dismiss'
        }, [
            iconEl,
            this.el('span', { className: 'toast-message', textContent: message })
        ]);
        let dismissed = false;
        const dismiss = () => {
            if (dismissed) return;
            dismissed = true;
            toast.classList.add('toast-exit');
            setTimeout(() => toast.remove(), 220);
        };
        toast.addEventListener('click', dismiss); // tap/click to dismiss early
        this.toastContainer.appendChild(toast);
        setTimeout(dismiss, duration);
    },

    // ---- Modal ----
    /**
     * `opts.onClose` (optional) runs exactly once when the modal closes by
     * ANY path \u2014 footer button, X, Escape, or overlay click \u2014 so dialogs
     * that resolve a promise (App.confirm) can never be left dangling.
     */
    showModal(titleText, contentEl, footerEl, opts) {
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
        overlay._onClose = opts && typeof opts.onClose === 'function' ? opts.onClose : null;

        // Keep screen-reader virtual cursors out of the page behind the
        // dialog (the Tab trap below only covers keyboard focus).
        const appRoot = document.getElementById('app');
        if (appRoot) appRoot.setAttribute('inert', '');

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
            const onClose = existing._onClose;
            existing._onClose = null;
            existing.remove();
            document.body.classList.remove('modal-open');
            const appRoot = document.getElementById('app');
            if (appRoot) appRoot.removeAttribute('inert');
            if (onClose) {
                try { onClose(); } catch (e) { console.warn('Modal onClose error:', e); }
            }
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
            // Settle exactly once no matter how the dialog closes — the
            // buttons resolve explicitly; Escape / X / overlay-click land
            // in onClose and count as a cancel. Without this, a dismissed
            // confirm left its awaiting caller hanging forever.
            let settled = false;
            const done = (result) => {
                if (settled) return;
                settled = true;
                this.hideModal(); // onClose fires but `settled` blocks re-entry
                resolve(result);
            };
            const body = this.el('p', { textContent: message });
            const cancelBtn = this.el('button', {
                className: 'btn btn-secondary',
                type: 'button',
                textContent: cancelLabel,
                onClick: () => done(false)
            });
            const confirmBtn = this.el('button', {
                className: 'btn ' + (danger ? 'btn-danger' : 'btn-primary'),
                type: 'button',
                textContent: confirmLabel,
                onClick: () => done(true)
            });
            const footer = this.el('div', { className: 'flex gap-sm' }, [cancelBtn, confirmBtn]);
            this.showModal(title, body, footer, { onClose: () => {
                if (!settled) { settled = true; resolve(false); }
            } });
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
                if (value === null || value === undefined) continue; // never stamp attr="null"
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
                else if (key === 'disabled' || key === 'checked' || key === 'selected') {
                    elem[key] = !!value;
                }
                else if (key === 'readonly') {
                    elem.readOnly = !!value; // DOM property is camelCase
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
        // Lock page scroll behind the drawer (mirrors body.modal-open).
        document.body.classList.toggle('menu-open', isOpen);
        if (overlay) overlay.classList.toggle('active', isOpen);
        if (menuBtn) {
            menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            menuBtn.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
            // Sighted users get the same signal the aria-label gives:
            // hamburger while closed, X while the drawer is open.
            menuBtn.innerHTML = isOpen ? Icons.xmark : Icons.menu;
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
    /**
     * Live access refresh. Roles and per-user overrides can change while
     * someone is logged in, but the resolved permission list was otherwise
     * only read at login / full page load — so a freshly granted page kept
     * saying "no permission" until the person thought to hit F5. Poll the
     * session status (visibility-aware, immediate on tab focus — exactly
     * the "okay, try it now" moment) and rebuild the shell only when the
     * effective access actually changed. If the page they're on just
     * became forbidden, the route guard bounces them to their first
     * visible section; if their session ended, reload lands on login.
     */
    startAccessRefresh() {
        const signature = (u) => JSON.stringify([
            u ? u.role : null,
            (u && Array.isArray(u.permissions)) ? u.permissions.slice().sort() : null
        ]);
        this.createVisibilityAwareInterval(async () => {
            if (!this.currentUser) return;
            let res;
            try {
                res = await API.get('auth/status');
            } catch (e) {
                return; // transient network/server hiccup — next tick retries
            }
            if (!res || !res.authenticated || !res.user) {
                // Session ended server-side. A hard reload here would throw
                // away anything the user has typed (open modal, half-filled
                // form) — land them on the login page the SPA way instead.
                this.currentUser = null;
                if (window.APP_CONFIG) window.APP_CONFIG.user = null;
                this.hideModal();
                const layout = document.querySelector('.layout');
                if (layout) layout.remove();
                this.toast('Your session has expired. Please sign in again.', 'warning');
                if (window.location.hash === '#/login') {
                    this.route();
                } else {
                    window.location.hash = '#/login';
                }
                return;
            }
            if (signature(res.user) === signature(this.currentUser)) return;
            this.currentUser = res.user;
            if (window.APP_CONFIG) window.APP_CONFIG.user = res.user;
            const layout = document.querySelector('.layout');
            if (layout) layout.remove();
            this.toast('Your access has been updated.', 'info');
            this.route();
        }, 60000, { runImmediately: true, runOnVisible: true });
    },

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
            // start() already fires the callback when runImmediately is set —
            // only fire here when it won't, so refocusing the tab never
            // double-hits the API.
            start();
            if (config.runOnVisible && !config.runImmediately) callback();
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

    // ------------------------------------------------------------------
    // Year-over-year widget (shared by the Command Center and Analytics)
    //
    // Month-to-date and year-to-date ACTUALS against the identical stretch of
    // the prior year, straight from GET /api/analytics/yoy. Both sides are
    // completed days cut at the same calendar point — nothing here is
    // projected, paced, or averaged.
    // ------------------------------------------------------------------

    /**
     * Card shell for the year-over-year comparison. Fill it by passing the
     * element with id `opts.bodyId` to `renderYoy()` once the fetch lands.
     *
     * Options: id, bodyId, title, subtitle, className.
     */
    buildYoyCard(opts) {
        const o = opts || {};
        return this.el('div', {
            className: 'card yoy-card' + (o.className ? ' ' + o.className : ''),
            id: o.id || 'yoy-card'
        }, [
            this.el('div', { className: 'card-header' }, [
                this.el('div', {}, [
                    this.el('div', { className: 'card-title', textContent: o.title || 'Year over year' }),
                    this.el('div', { className: 'text-sm text-secondary', textContent: o.subtitle
                        || 'Month to date and year to date against the same stretch of last year' })
                ])
            ]),
            this.el('div', { className: 'card-body', id: o.bodyId || 'yoy-body' }, [this.loading()])
        ]);
    },

    /** "Jul 30, 2026" from a bare YYYY-MM-DD, with no timezone round-trip. */
    formatPlainDate(iso, withYear) {
        if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '—';
        const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const y = iso.slice(0, 4);
        const m = MONTHS[parseInt(iso.slice(5, 7), 10) - 1] || '';
        const d = String(parseInt(iso.slice(8, 10), 10));
        return m + ' ' + d + (withYear === false ? '' : ', ' + y);
    },

    /** Render a /api/analytics/yoy payload into `body`. */
    renderYoy(body, data) {
        if (!body) return;
        body.innerHTML = '';

        const periods = (data && data.periods) || [];
        if (!data || !data.has_data || !periods.length) {
            body.appendChild(this.el('p', { className: 'text-sm text-secondary',
                textContent: 'No completed days recorded yet — the comparison appears once the '
                    + 'nightly rollup has a full day of history to compare.' }));
            return;
        }

        const canSeeMoney = this.canSeeMoney() && !data.hide_money;

        const coverage = 'Actuals through ' + this.formatPlainDate(data.through)
            + ' · measured against ' + this.formatPlainDate(data.prior_through)
            + (data.source === 'app' ? ' · from this app’s own play rollup' : ' · from the POS card ledger');
        body.appendChild(this.el('p', { className: 'text-sm text-secondary yoy-coverage',
            textContent: coverage }));

        // A rollup that stopped updating still renders a clean card — every
        // window is honestly labelled, just cut at a day that drifts further
        // into the past each night. Say so once it's more than a couple of days
        // back; one day behind is routine (the nightly refresh can land while
        // the day it would cover is still running).
        const stale = Number(data.stale_days);
        if (Number.isFinite(stale) && stale >= 3) {
            const src = data.source === 'app' ? 'play rollup' : 'POS ledger rollup';
            body.appendChild(this.el('p', {
                className: 'yoy-stale',
                role: 'status',
                textContent: 'The ' + src + ' is ' + stale + ' days behind: its newest complete day is '
                    + this.formatPlainDate(data.through) + ', not '
                    + this.formatPlainDate(data.expected_through)
                    + '. These totals stop there — the nightly refresh has not advanced them.'
            }));
        }

        const grid = this.el('div', { className: 'yoy-grid' });
        periods.forEach((p) => grid.appendChild(this._yoyPeriod(p, data, canSeeMoney)));
        body.appendChild(grid);
    },

    /** One period block (Month to date / Year to date). */
    _yoyPeriod(period, data, canSeeMoney) {
        const cur = period.current || {};
        const prior = period.prior || {};
        const delta = period.delta || {};

        const metrics = [];
        if (canSeeMoney) {
            metrics.push({
                label: data.money_label || 'Value',
                hint: data.money_hint || '',
                value: this._yoyMoney(cur.value),
                prior: this._yoyMoney(prior.value),
                delta: delta.value
            });
        }
        metrics.push({
            label: 'Plays',
            hint: 'Reader swipes recorded',
            value: this._yoyCount(cur.plays),
            prior: this._yoyCount(prior.plays),
            delta: delta.plays
        });
        metrics.push({
            label: 'Tickets',
            hint: 'Redemption tickets earned',
            value: this._yoyCount(cur.tickets),
            prior: this._yoyCount(prior.tickets),
            delta: delta.tickets
        });

        const priorYear = data.prior_year != null ? String(data.prior_year) : 'last year';
        const tiles = metrics.map((m) => {
            const children = [
                this.el('div', { className: 'yoy-metric-label', textContent: m.label }),
                this.el('div', { className: 'yoy-metric-value', textContent: m.value }),
                this.el('div', { className: 'yoy-metric-prior',
                    textContent: period.prior_has_data
                        ? priorYear + ': ' + m.prior
                        : 'no ' + priorYear + ' history' })
            ];
            const chip = this._yoyDeltaChip(m.delta, period.prior_has_data);
            if (chip) children.push(chip);
            return this.el('div', { className: 'yoy-metric', title: m.hint || '' }, children);
        });

        return this.el('div', { className: 'yoy-period' }, [
            this.el('div', { className: 'yoy-period-head' }, [
                this.el('span', { className: 'yoy-period-title', textContent: period.label || '' }),
                this.el('span', { className: 'yoy-period-range',
                    textContent: this.formatPlainDate(cur.from, false) + ' – ' + this.formatPlainDate(cur.to, false)
                        + ' · ' + (cur.days || 0) + (cur.days === 1 ? ' day' : ' days') })
            ]),
            this.el('div', { className: 'yoy-metrics' }, tiles)
        ]);
    },

    /** Up/down/flat chip for one metric. Null when there's nothing to compare. */
    _yoyDeltaChip(delta, priorHasData) {
        if (!priorHasData || !delta || delta.pct === null || delta.pct === undefined) {
            return this.el('span', { className: 'yoy-delta yoy-delta-none', textContent: '—' });
        }
        const pct = delta.pct;
        const dir = pct > 0.05 ? 'up' : (pct < -0.05 ? 'down' : 'flat');
        const arrow = dir === 'up' ? '↑' : (dir === 'down' ? '↓' : '→');
        const text = dir === 'flat' ? 'even' : arrow + ' ' + Math.abs(pct).toFixed(1) + '%';
        return this.el('span', { className: 'yoy-delta yoy-delta-' + dir, textContent: text });
    },

    _yoyMoney(v) {
        const n = Number(v) || 0;
        return '$' + Math.round(n).toLocaleString();
    },

    _yoyCount(v) {
        const n = Number(v) || 0;
        return Math.round(n).toLocaleString();
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
    },

    // ---- Table Sorting ----

    /**
     * Type-aware value comparator shared by the table-sort helpers.
     * Numbers compare numerically; everything else falls back to a
     * locale-aware, numeric-collating string compare ("Game 2" < "Game 10").
     * Nullish handling lives in the callers so empty cells can pin to the
     * bottom in BOTH directions.
     */
    sortCompare(a, b) {
        if (typeof a === 'number' && typeof b === 'number') {
            if (a === b) return 0;
            return a < b ? -1 : 1;
        }
        return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
    },

    /**
     * Sort a data array by a column definition. Returns a NEW array — the
     * input is never mutated. The sort is stable, and rows whose value is
     * null/undefined/'' always sort to the bottom regardless of direction
     * (so "Last play: —" rows never bury the real data).
     *
     * `sort`    {key, dir}  dir is 'asc' | 'desc'
     * `columns` array of {key, sortValue?: fn(row), type?: 'number'|'date'|'string'}
     *           sortValue extracts the RAW value when the rendered cell is a
     *           formatted string; type coerces ('date' accepts anything
     *           App.toUtcDate parses, 'number' strips $ , % formatting).
     */
    sortRows(rows, sort, columns) {
        const list = rows ? rows.slice() : [];
        if (!sort || !sort.key) return list;
        const col = (columns || []).filter(c => c.key === sort.key)[0] || {};
        const dir = sort.dir === 'desc' ? -1 : 1;
        const self = this;
        const raw = (row) => {
            let v = typeof col.sortValue === 'function' ? col.sortValue(row) : (row ? row[sort.key] : null);
            if (v === null || v === undefined || v === '') return null;
            if (col.type === 'number') {
                const n = typeof v === 'number' ? v : parseFloat(String(v).replace(/[$,%\s]/g, ''));
                return isNaN(n) ? null : n;
            }
            if (col.type === 'date') {
                const d = v instanceof Date ? v : self.toUtcDate(v);
                return d ? d.getTime() : null;
            }
            return v;
        };
        return list
            .map((row, i) => ({ row: row, i: i, v: raw(row) }))
            .sort((a, b) => {
                const aNull = a.v === null, bNull = b.v === null;
                if (aNull && bNull) return a.i - b.i;
                if (aNull) return 1;
                if (bNull) return -1;
                const c = self.sortCompare(a.v, b.v);
                return c !== 0 ? c * dir : a.i - b.i;
            })
            .map(x => x.row);
    },

    /**
     * Build one header cell for a sortable table. Pages that own their
     * render loop (filter → sort → paginate → render) build their <thead>
     * from these so every table gets the same affordance: pointer +
     * hover style, ▲/▼ indicator, aria-sort, and full keyboard support.
     *
     * `col`    {key, label, sortable?, className?, type?, defaultDir?}
     *          sortable defaults to true; pass false for action columns.
     * `sort`   current {key, dir} state (owned by the page).
     * `onSort` receives the NEXT {key, dir} — the page stores it and
     *          re-renders. First activation of a numeric/date column
     *          defaults to 'desc' (biggest first), strings to 'asc'.
     */
    sortableTh(col, sort, onSort) {
        const classes = [];
        if (col.className) classes.push(col.className);
        if (col.sortable === false || typeof onSort !== 'function') {
            return this.el('th', { className: classes.join(' '), scope: 'col', textContent: col.label || '' });
        }
        classes.push('sortable');
        const active = !!(sort && sort.key === col.key);
        const dir = active ? (sort.dir === 'desc' ? 'desc' : 'asc') : null;
        if (active) classes.push('sorted');
        const th = this.el('th', {
            className: classes.join(' '),
            scope: 'col',
            tabindex: '0',
            'aria-sort': active ? (dir === 'desc' ? 'descending' : 'ascending') : 'none',
            title: 'Sort by ' + (col.label || col.key)
        }, [
            this.el('span', { className: 'th-label', textContent: col.label || '' }),
            this.el('span', {
                className: 'sort-icon',
                'aria-hidden': 'true',
                textContent: active ? (dir === 'desc' ? '▼' : '▲') : '↕'
            })
        ]);
        const activate = () => {
            let next;
            if (active) {
                next = { key: col.key, dir: dir === 'asc' ? 'desc' : 'asc' };
            } else {
                const firstDir = col.defaultDir
                    || ((col.type === 'number' || col.type === 'date') ? 'desc' : 'asc');
                next = { key: col.key, dir: firstDir };
            }
            onSort(next);
        };
        th.addEventListener('click', activate);
        th.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                e.preventDefault();
                activate();
            }
        });
        return th;
    },

    /**
     * Make an already-rendered, NON-paginated table sortable in place —
     * the zero-refactor path for report tables that render all their rows
     * at once. Click (or Enter/Space on) a header to sort; click again to
     * flip. Cell values come from a `data-sort` attribute when present
     * (use it for formatted cells — dates, "1.2k", bars), else from the
     * cell's text. A column whose populated cells all parse as numbers
     * ("1,234", "$12.50", "43%", "(5)") sorts numerically and defaults to
     * descending on first click; empty cells ("", "—", "-", "N/A") always
     * sink to the bottom. Headers with a `data-nosort` attribute (or no
     * text) are left alone. Safe to call again after a re-render.
     *
     * NOT for paginated tables — it reorders only the rendered rows. Those
     * pages sort their data array via App.sortRows + App.sortableTh.
     */
    enhanceTableSort(table, opts) {
        if (!table || !table.tHead || !table.tHead.rows.length) return table;
        const options = opts || {};
        const headRow = table.tHead.rows[0];
        const ths = Array.prototype.slice.call(headRow.cells);
        const self = this;

        const EMPTY = Object.create(null); // null proto: a cell texted "constructor" must not match
        ['', '—', '-', '–', 'n/a'].forEach(k => { EMPTY[k] = 1; });
        const parseCell = (td) => {
            const attr = td ? td.getAttribute('data-sort') : null;
            const s = (attr !== null ? attr : (td ? td.textContent : '')).trim();
            if (EMPTY[s.toLowerCase()] !== undefined) return { v: null, num: true };
            const cleaned = s.replace(/[$,%\s]/g, '').replace(/^\((.*)\)$/, '-$1');
            if (cleaned !== '' && !isNaN(Number(cleaned))) return { v: Number(cleaned), num: true };
            return { v: s, num: false };
        };

        const applySort = (index, dir) => {
            const tbody = table.tBodies[0];
            if (!tbody) return;
            const rows = Array.prototype.slice.call(tbody.rows);
            const entries = rows.map((tr, i) => ({ tr: tr, i: i, cell: parseCell(tr.cells[index]) }));
            const numeric = entries.every(e => e.cell.num);
            const mul = dir === 'desc' ? -1 : 1;
            entries.sort((a, b) => {
                const av = a.cell.v, bv = b.cell.v;
                const aNull = av === null || (numeric && !a.cell.num);
                const bNull = bv === null || (numeric && !b.cell.num);
                if (aNull && bNull) return a.i - b.i;
                if (aNull) return 1;
                if (bNull) return -1;
                const c = self.sortCompare(numeric ? Number(av) : av, numeric ? Number(bv) : bv);
                return c !== 0 ? c * mul : a.i - b.i;
            });
            const frag = document.createDocumentFragment();
            entries.forEach(e => frag.appendChild(e.tr));
            tbody.appendChild(frag);

            table._sortState = { index: index, dir: dir };
            ths.forEach((th, tIdx) => {
                const isActive = tIdx === index;
                th.classList.toggle('sorted', isActive);
                if (th._sortIcon) {
                    th._sortIcon.textContent = isActive ? (dir === 'desc' ? '▼' : '▲') : '↕';
                }
                if (th.classList.contains('sortable')) {
                    th.setAttribute('aria-sort', isActive ? (dir === 'desc' ? 'descending' : 'ascending') : 'none');
                }
            });
        };

        ths.forEach((th, index) => {
            if (th.hasAttribute('data-nosort')) return;
            if (!(th.textContent || '').trim()) return;
            if (th._sortWired) return;
            th._sortWired = true;
            th.classList.add('sortable');
            th.setAttribute('tabindex', '0');
            th.setAttribute('aria-sort', 'none');
            // Don't clobber an existing explanatory tooltip.
            if (!th.hasAttribute('title')) {
                th.setAttribute('title', 'Sort by ' + th.textContent.trim());
            }
            const icon = this.el('span', { className: 'sort-icon', 'aria-hidden': 'true', textContent: '↕' });
            th.appendChild(icon);
            th._sortIcon = icon;
            const activate = () => {
                const prev = table._sortState;
                let dir;
                if (prev && prev.index === index) {
                    dir = prev.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    // Peek at the column: numeric columns open big-first.
                    const tbody = table.tBodies[0];
                    const sample = tbody ? Array.prototype.slice.call(tbody.rows)
                        .map(tr => parseCell(tr.cells[index]))
                        .filter(c => c.v !== null) : [];
                    const numeric = sample.length > 0 && sample.every(c => c.num);
                    dir = numeric ? 'desc' : 'asc';
                }
                applySort(index, dir);
            };
            th.addEventListener('click', activate);
            th.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                    e.preventDefault();
                    activate();
                }
            });
        });

        if (options.defaultSort && !table._sortState) {
            applySort(options.defaultSort.index, options.defaultSort.dir || 'asc');
        }
        return table;
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());
