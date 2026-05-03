/**
 * SPA router, navigation rendering, toast notifications, shared utilities.
 */
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

    ROLES: ['admin', 'tech', 'manager'],
    ROLE_LABELS: {
        admin: 'Administrator',
        tech: 'Technician',
        manager: 'Manager'
    },

    /** Map of route hash -> roles allowed to view it. Missing entry = all roles. */
    ROUTE_ROLES: {
        '#/settings': ['admin', 'tech']
        // future: '#/sales': ['admin', 'manager']
    },

    userRole() {
        const u = this.currentUser;
        if (!u) return null;
        const role = (u.role || 'admin').toLowerCase();
        return this.ROLES.indexOf(role) === -1 ? 'admin' : role;
    },

    canAccess(routeHash) {
        const allowed = this.ROUTE_ROLES[routeHash];
        if (!allowed) return true;
        const role = this.userRole();
        return role !== null && allowed.indexOf(role) !== -1;
    },

    // ---- SVG Icon Library ----
    // Inline SVG icons, designed at 24x24 viewBox, stroke-based.
    // Each entry is the inner SVG markup (paths/lines) — see iconEl().
    ICONS: {
        dashboard: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        groups: '<circle cx="9" cy="7" r="3.2"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><circle cx="17" cy="9" r="2.6"/><path d="M14.5 20a4.5 4.5 0 0 1 6.5-4.05"/>',
        kiosk: '<rect x="4" y="3" width="16" height="14" rx="1.5"/><path d="M9 17v3h6v-3"/><path d="M7 20h10"/><circle cx="12" cy="10" r="2.5"/>',
        clock: '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/>',
        bolt: '<polygon points="13 2 4 14 11 14 10 22 20 10 13 10 13 2"/>',
        list: '<line x1="8" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="20" y2="12"/><line x1="8" y1="18" x2="20" y2="18"/><circle cx="4" cy="6" r="1.2"/><circle cx="4" cy="12" r="1.2"/><circle cx="4" cy="18" r="1.2"/>',
        settings: '<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/><circle cx="12" cy="12" r="3"/>',

        // Status / action icons
        check: '<polyline points="20 6 9 17 4 12"/>',
        cross: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        info: '<circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="13"/><line x1="12" y1="16" x2="12" y2="16"/>',
        warning: '<path d="M10.3 3.3 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.3a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12" y2="17"/>',
        sparkles: '<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/>',
        sun: '<circle cx="12" cy="12" r="4.2"/><line x1="12" y1="2" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22"/><line x1="2" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22" y2="12"/><line x1="4.9" y1="4.9" x2="6.3" y2="6.3"/><line x1="17.7" y1="17.7" x2="19.1" y2="19.1"/><line x1="4.9" y1="19.1" x2="6.3" y2="17.7"/><line x1="17.7" y1="6.3" x2="19.1" y2="4.9"/>',
        moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        chevronLeft: '<polyline points="15 6 9 12 15 18"/>',
        sync: '<path d="M3 12a9 9 0 0 1 15.5-6.3L21 8"/><polyline points="21 3 21 8 16 8"/><path d="M21 12a9 9 0 0 1-15.5 6.3L3 16"/><polyline points="3 21 3 16 8 16"/>',
        logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        menu: '<line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/>',
        search: '<circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16.5" y2="16.5"/>',
        hand: '<path d="M9 11V5a1.5 1.5 0 0 1 3 0v6"/><path d="M12 11V4a1.5 1.5 0 0 1 3 0v7"/><path d="M15 11V6a1.5 1.5 0 0 1 3 0v8a7 7 0 0 1-7 7h-1a6 6 0 0 1-5.6-3.8L3 16a1.5 1.5 0 0 1 2.7-1.3l1.5 2.3"/><path d="M9 11V8a1.5 1.5 0 0 0-3 0v6"/>',
        shield: '<path d="M12 2 4 5v6c0 5 3.5 9 8 11 4.5-2 8-6 8-11V5z"/>',
        lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        play: '<polygon points="6 4 19 12 6 20 6 4"/>',
        pause: '<rect x="6" y="4" width="4" height="16" rx="0.5"/><rect x="14" y="4" width="4" height="16" rx="0.5"/>',
        empty: '<path d="M5 8h14l-1.5 11a2 2 0 0 1-2 1.7H8.5A2 2 0 0 1 6.5 19z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        gamepad: '<rect x="3" y="6" width="18" height="12" rx="3"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="6" y1="12" x2="10" y2="12"/><circle cx="15.5" cy="10.5" r="0.8"/><circle cx="17.5" cy="13.5" r="0.8"/>',
        calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/>',
        ticket: '<path d="M3 9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><line x1="9" y1="7" x2="9" y2="17" stroke-dasharray="2 2"/>',
        coin: '<circle cx="12" cy="12" r="9"/><path d="M14.5 9a2.5 2.5 0 0 0-5 0c0 1.5 1.2 2 2.5 2.4 1.4.5 2.7 1 2.7 2.6a2.7 2.7 0 0 1-5.4 0"/><line x1="12" y1="6" x2="12" y2="7.5"/><line x1="12" y1="16.5" x2="12" y2="18"/>',
        chart: '<polyline points="3 17 9 11 13 15 21 6"/><polyline points="14 6 21 6 21 13"/>',
        trophy: '<path d="M8 4h8v4a4 4 0 0 1-8 0z"/><path d="M16 4h3a1 1 0 0 1 1 1v2a4 4 0 0 1-4 4"/><path d="M8 4H5a1 1 0 0 0-1 1v2a4 4 0 0 0 4 4"/><line x1="12" y1="12" x2="12" y2="17"/><path d="M9 17h6l-1 4h-4z"/>',
        users: '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17" cy="9.5" r="2.6"/><path d="M14.8 19.4a4.7 4.7 0 0 1 6.2-3.5"/>',
        flame: '<path d="M12 3c1 4 5 5 5 10a5 5 0 0 1-10 0c0-2.5 1.5-3.5 1.5-6 0 0 1.5 1 2 2 0-2.5 0-4 1.5-6z"/>',
        spark: '<polyline points="3 17 8 12 12 14 16 8 21 11"/>',
        rocket: '<path d="M14 4c4 0 6 2 6 6-2 0-4 1.5-6 4l-4-4c2.5-2 4-4 4-6z"/><path d="M10 10c-2.5 0-4 1.2-4.5 3.5L4 18l4.5-1.5C11 16 12 14.5 12 12z"/><circle cx="14.5" cy="9.5" r="1.3"/>',
        timer: '<circle cx="12" cy="13" r="8"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="13" x2="15" y2="14.5"/><path d="M9 2h6"/><path d="M19 6l1.5-1.5"/>'
    },

    /**
     * Build an inline SVG icon element for the named icon.
     * Pass `size` (px) and optional className.
     */
    iconEl(name, size, className) {
        size = size || 18;
        const inner = this.ICONS[name] || this.ICONS.info;
        const wrap = document.createElement('span');
        wrap.className = (className || 'icon');
        wrap.setAttribute('aria-hidden', 'true');
        wrap.innerHTML =
            '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '"' +
            ' viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
            ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            inner + '</svg>';
        return wrap;
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

        // Remove initial loading overlay
        const appLoading = document.getElementById('app-loading');
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
        if (this.themeToggleBtn) {
            this.themeToggleBtn.innerHTML = '';
            this.themeToggleBtn.appendChild(this.iconEl(this.theme === 'dark' ? 'sun' : 'moon', 14));
            this.themeToggleBtn.appendChild(this.el('span', {
                textContent: this.theme === 'dark' ? 'Light' : 'Dark'
            }));
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
        const hash = (window.location.hash || '#/login').replace(/\/$/, '');

        // Cleanup previous page
        if (this.currentCleanup) {
            try { this.currentCleanup(); } catch (e) { console.warn('Page cleanup error:', e); }
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

        // Role-based route guard. Bounce to dashboard if the user can't view this page.
        if (this.currentUser && !this.canAccess(hash)) {
            this.toast('You do not have permission to access that page.', 'warning');
            window.location.hash = '#/dashboard';
            return;
        }

        this.setAppStateClass();

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

    /**
     * Initials helper for the user avatar (max 2 characters).
     */
    initialsFor(user) {
        if (!user) return 'U';
        const name = (user.display_name || user.username || 'U').trim();
        const parts = name.split(/\s+/).filter(Boolean);
        if (parts.length === 0) return 'U';
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    },

    ensureLayout(container) {
        if (document.getElementById('main-content')) return;

        container.innerHTML = '';
        const layout = this.el('div', { className: 'layout' });

        // Mobile menu button + overlay
        const overlay = this.el('div', { className: 'sidebar-overlay', id: 'sidebar-overlay' });
        const menuBtn = this.el('button', {
            className: 'mobile-menu-btn',
            id: 'mobile-menu-btn',
            type: 'button',
            'aria-label': 'Open navigation menu',
            onClick: () => this.toggleMobileMenu()
        });
        menuBtn.appendChild(this.iconEl('menu', 18));
        overlay.addEventListener('click', () => this.toggleMobileMenu(false));
        layout.appendChild(overlay);
        layout.appendChild(menuBtn);

        // Sidebar
        const sidebar = this.el('aside', { className: 'sidebar', id: 'app-sidebar' });

        // Brand: mark + title + status pulse dot
        const brand = this.el('div', { className: 'sidebar-brand' });
        const brandRow = this.el('div', { className: 'sidebar-brand-row' }, [
            this.el('div', { className: 'sidebar-brand-mark', textContent: 'CE' }),
            this.el('div', { style: { minWidth: '0', flex: '1' } }, [
                this.el('h1', { textContent: 'Castle Fun Center' }),
                this.el('p', { textContent: 'Pause Groups' })
            ])
        ]);
        brand.appendChild(brandRow);
        brand.appendChild(this.el('span', {
            className: 'sidebar-brand-status',
            title: 'Service is running',
            'aria-label': 'Service is running'
        }));
        sidebar.appendChild(brand);

        // Nav items: each with proper SVG icon. Filtered by role.
        const navItems = [
            { hash: '#/dashboard', icon: 'dashboard', label: 'Dashboard' },
            { hash: '#/groups',    icon: 'groups',    label: 'Pause Groups' },
            { hash: '#/kiosks',    icon: 'kiosk',     label: 'Kiosks' },
            { hash: '#/schedules', icon: 'clock',     label: 'Schedules' },
            { hash: '#/overrides', icon: 'bolt',      label: 'Overrides' },
            { hash: '#/logs',      icon: 'list',      label: 'Action Log' },
            { hash: '#/settings',  icon: 'settings',  label: 'Settings' }
        ].filter(item => this.canAccess(item.hash));

        const nav = this.el('nav', { className: 'nav-section', 'aria-label': 'Primary navigation' });
        nav.appendChild(this.el('div', { className: 'nav-section-label', textContent: 'Navigation' }));

        navItems.forEach(item => {
            const navItem = this.el('div', {
                className: 'nav-item',
                role: 'link',
                tabindex: '0',
                'data-hash': item.hash,
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
            });
            navItem.appendChild(this.iconEl(item.icon, 18, 'nav-icon'));
            navItem.appendChild(this.el('span', { textContent: item.label }));
            nav.appendChild(navItem);
        });
        sidebar.appendChild(nav);

        // Sidebar footer: avatar + name/role + logout
        const initials = this.initialsFor(this.currentUser);
        const roleLabel = this.ROLE_LABELS[this.userRole()] || 'User';
        const footer = this.el('div', { className: 'sidebar-footer' }, [
            this.el('div', { className: 'sidebar-user-avatar', textContent: initials, 'aria-hidden': 'true' }),
            this.el('div', { className: 'sidebar-user' }, [
                this.el('span', { className: 'sidebar-user-name', textContent: this.currentUser.display_name || this.currentUser.username }),
                this.el('span', { className: 'sidebar-user-role', textContent: roleLabel })
            ])
        ]);
        const logoutBtn = this.el('button', {
            className: 'btn btn-ghost btn-icon-only',
            type: 'button',
            title: 'Sign out',
            'aria-label': 'Sign out',
            onClick: async () => {
                try { await API.post('auth/logout'); } catch (e) { /* ignore */ }
                this.currentUser = null;
                window.location.hash = '#/login';
            }
        });
        logoutBtn.appendChild(this.iconEl('logout', 16));
        footer.appendChild(logoutBtn);
        sidebar.appendChild(footer);

        layout.appendChild(sidebar);

        // Main content area
        const main = this.el('div', { className: 'main-content', id: 'main-content' });
        layout.appendChild(main);

        container.appendChild(layout);
    },

    updateActiveNav(hash) {
        document.querySelectorAll('.nav-item').forEach(item => {
            const itemHash = item.getAttribute('data-hash');
            if (itemHash && hash.startsWith(itemHash)) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });
    },

    // ---- Toast Notifications ----
    toast(message, type, duration) {
        type = type || 'info';
        duration = duration || 4000;
        const iconName = type === 'success' ? 'check'
            : type === 'error' ? 'cross'
            : type === 'warning' ? 'warning'
            : 'info';
        const toast = this.el('div', { className: 'toast toast-' + type, role: 'status' });
        toast.appendChild(this.iconEl(iconName, 18, 'toast-icon'));
        toast.appendChild(this.el('span', { textContent: message }));
        this.toastContainer.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('toast-exit');
            setTimeout(() => toast.remove(), 220);
        }, duration);
    },

    // ---- Modal ----
    showModal(titleText, contentEl, footerEl) {
        this.hideModal();
        const overlay = this.el('div', { className: 'modal-overlay', id: 'modal-overlay', role: 'dialog', 'aria-modal': 'true' });
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) this.hideModal();
        });

        const modal = this.el('div', { className: 'modal' });
        const closeBtn = this.el('button', {
            className: 'modal-close',
            type: 'button',
            'aria-label': 'Close dialog',
            onClick: () => this.hideModal()
        });
        closeBtn.appendChild(this.iconEl('cross', 16));

        const header = this.el('div', { className: 'modal-header' }, [
            this.el('div', { className: 'modal-title', textContent: titleText }),
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

        // Close on Escape key + trap focus inside modal
        const keyHandler = (e) => {
            if (e.key === 'Escape') { this.hideModal(); return; }
            if (e.key === 'Tab') {
                const focusable = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (focusable.length === 0) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (e.shiftKey) {
                    if (document.activeElement === first) { e.preventDefault(); last.focus(); }
                } else {
                    if (document.activeElement === last) { e.preventDefault(); first.focus(); }
                }
            }
        };
        document.addEventListener('keydown', keyHandler);
        overlay._escHandler = keyHandler;

        requestAnimationFrame(() => {
            const first = modal.querySelector('input, select, textarea, button, [href], [tabindex]:not([tabindex="-1"])');
            if (first) first.focus();
        });
    },

    hideModal() {
        const existing = document.getElementById('modal-overlay');
        if (existing) {
            if (existing._escHandler) {
                document.removeEventListener('keydown', existing._escHandler);
            }
            existing.remove();
        }
    },

    confirm(message, options) {
        options = options || {};
        return new Promise((resolve) => {
            const body = this.el('p', { textContent: message, style: { fontSize: '0.92rem', lineHeight: '1.5' } });
            const footer = this.el('div', { className: 'flex gap-sm' }, [
                this.el('button', {
                    className: 'btn btn-secondary',
                    type: 'button',
                    textContent: options.cancelText || 'Cancel',
                    onClick: () => { this.hideModal(); resolve(false); }
                }),
                this.el('button', {
                    className: 'btn ' + (options.danger === false ? 'btn-primary' : 'btn-danger'),
                    type: 'button',
                    textContent: options.confirmText || 'Confirm',
                    onClick: () => { this.hideModal(); resolve(true); }
                })
            ]);
            this.showModal(options.title || 'Confirm Action', body, footer);
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
                    elem.value = value;
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
                if (child === null || child === undefined || child === false) return;
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
        if (!dateStr) return '—';
        const d = this.toUtcDate(dateStr);
        if (!d) return '—';
        return new Intl.DateTimeFormat('en-US', {
            month: 'short', day: 'numeric', year: 'numeric', timeZone: this.appTimezone
        }).format(d);
    },

    formatDatetime(dateStr) {
        if (!dateStr) return '—';
        const d = this.toUtcDate(dateStr);
        if (!d) return '—';
        return new Intl.DateTimeFormat('en-US', {
            month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: this.appTimezone
        }).format(d);
    },

    formatTime(timeStr) {
        if (!timeStr) return '—';
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

        if (absMin < 1) return isPast ? 'just now' : 'in <1m';
        if (absMin < 60) return isPast ? absMin + 'm ago' : 'in ' + absMin + 'm';

        const absHr = Math.round(absMin / 60);
        if (absHr < 24) return isPast ? absHr + 'h ago' : 'in ' + absHr + 'h';

        const absDays = Math.round(absHr / 24);
        return isPast ? absDays + 'd ago' : 'in ' + absDays + 'd';
    },

    /** Compact number formatting — "1.2K", "8.4M", etc. */
    formatCompact(n) {
        if (n === null || n === undefined || isNaN(n)) return '0';
        n = Number(n);
        if (Math.abs(n) < 1000) return Number.isInteger(n) ? String(n) : n.toFixed(1);
        try {
            return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(n);
        } catch (e) {
            return String(Math.round(n));
        }
    },

    /** Thousands-separated integer/number. */
    formatNumber(n, decimals) {
        if (n === null || n === undefined || isNaN(n)) return '0';
        decimals = (decimals === undefined) ? 0 : decimals;
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    },

    /** USD currency formatting. */
    formatCurrency(n) {
        if (n === null || n === undefined || isNaN(n)) return '$0';
        n = Number(n);
        try {
            return new Intl.NumberFormat('en-US', {
                style: 'currency', currency: 'USD',
                maximumFractionDigits: Math.abs(n) >= 1000 ? 0 : 2
            }).format(n);
        } catch (e) {
            return '$' + n.toFixed(2);
        }
    },

    /**
     * Animate a numeric counter from its current value up to `target`.
     * No-ops gracefully if reduced motion is preferred.
     */
    animateCounter(elem, target, options) {
        if (!elem) return;
        options = options || {};
        var formatter = options.format || function(v) { return App.formatNumber(Math.round(v)); };
        var duration = options.duration || 700;
        var prefix = options.prefix || '';
        var suffix = options.suffix || '';

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) {
            elem.textContent = prefix + formatter(target) + suffix;
            return;
        }

        var start = parseFloat(elem.getAttribute('data-counter-current')) || 0;
        var startTs = null;
        function step(ts) {
            if (!startTs) startTs = ts;
            var p = Math.min(1, (ts - startTs) / duration);
            // Ease-out cubic
            var eased = 1 - Math.pow(1 - p, 3);
            var v = start + (target - start) * eased;
            elem.textContent = prefix + formatter(v) + suffix;
            if (p < 1) {
                requestAnimationFrame(step);
            } else {
                elem.setAttribute('data-counter-current', target);
            }
        }
        requestAnimationFrame(step);
    },

    statusBadge(status) {
        const cls = status === 'enabled' ? 'badge-enabled' :
                    status === 'paused' ? 'badge-paused' :
                    status === 'outOfService' ? 'badge-out-of-service' : 'badge-inactive';
        const label = status === 'enabled' ? 'Running'
            : status === 'paused' ? 'Paused'
            : status === 'outOfService' ? 'Out of Service'
            : (status || 'Unknown');
        const badge = this.el('span', { className: 'badge ' + cls, role: 'status', 'aria-label': 'Status: ' + label });
        badge.appendChild(this.el('span', { className: 'badge-dot' }));
        badge.appendChild(this.el('span', { textContent: label }));
        return badge;
    },

    loading() {
        return this.el('div', { className: 'loading-overlay' }, [
            this.el('div', { className: 'spinner' }),
            this.el('span', { textContent: 'Loading…' })
        ]);
    },

    toggleMobileMenu(forceState) {
        const sidebar = document.getElementById('app-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        if (!sidebar) return;
        const isOpen = typeof forceState === 'boolean' ? forceState : !sidebar.classList.contains('sidebar-open');
        sidebar.classList.toggle('sidebar-open', isOpen);
        if (overlay) overlay.classList.toggle('active', isOpen);
    },

    /**
     * Returns a debounced version of fn that delays execution by `delay` ms.
     */
    debounce(fn, delay) {
        let timer = null;
        return function () {
            const ctx = this, args = arguments;
            if (timer) clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
        };
    },

    /**
     * Runs callback on an interval only while the page is visible.
     */
    createVisibilityAwareInterval(callback, intervalMs, options) {
        const config = Object.assign({ runImmediately: false, runOnVisible: true }, options || {});
        let timer = null;

        const stop = () => { if (timer) { clearInterval(timer); timer = null; } };

        const start = () => {
            if (timer || document.hidden) return;
            timer = setInterval(callback, intervalMs);
            if (config.runImmediately) callback();
        };

        const handleVisibilityChange = () => {
            if (document.hidden) { stop(); return; }
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

    _navGeneration: 0,

    navGeneration() {
        return this._navGeneration;
    },

    /**
     * Build an empty-state block. `icon` may be an SVG icon name (string)
     * or any HTMLElement. Pass null/undefined for the default empty icon.
     */
    emptyState(icon, text, actionBtn) {
        const iconWrap = this.el('div', { className: 'empty-state-icon' });
        if (icon instanceof Node) {
            iconWrap.appendChild(icon);
        } else if (typeof icon === 'string' && this.ICONS[icon]) {
            iconWrap.appendChild(this.iconEl(icon, 22));
        } else {
            iconWrap.appendChild(this.iconEl('empty', 22));
        }
        const children = [
            iconWrap,
            this.el('div', { className: 'empty-state-text', textContent: text })
        ];
        if (actionBtn) {
            children.push(this.el('div', { className: 'empty-state-action' }, [actionBtn]));
        }
        return this.el('div', { className: 'empty-state' }, children);
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());
