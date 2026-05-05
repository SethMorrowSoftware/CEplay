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
        if (this.themeToggleBtn) {
            this.themeToggleBtn.textContent = this.theme === 'dark' ? '\u263D Light mode' : '\u2600 Dark mode';
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

        // Mobile menu button + overlay
        const overlay = this.el('div', { className: 'sidebar-overlay', id: 'sidebar-overlay' });
        const menuBtn = this.el('button', {
            className: 'mobile-menu-btn',
            id: 'mobile-menu-btn',
            type: 'button',
            'aria-label': 'Open navigation menu',
            'aria-expanded': 'false',
            'aria-controls': 'app-sidebar',
            textContent: '\u2630',
            onClick: () => this.toggleMobileMenu()
        });
        overlay.addEventListener('click', () => this.toggleMobileMenu(false));
        layout.appendChild(overlay);
        layout.appendChild(menuBtn);

        // Sidebar
        const sidebar = this.el('aside', { className: 'sidebar', id: 'app-sidebar' });

        const brand = this.el('div', { className: 'sidebar-brand' }, [
            this.el('h1', { textContent: 'Castle Fun Center' }),
            this.el('p', { textContent: 'Management System' })
        ]);
        sidebar.appendChild(brand);

        const navItems = [
            { hash: '#/dashboard', icon: '\u25A3', label: 'Dashboard' },
            { hash: '#/games', icon: '\u25C6', label: 'Games' },
            { hash: '#/cards', icon: '\u25EB', label: 'Card Lookup' },
            { hash: '#/groups', icon: '\u25CB', label: 'Pause Groups' },
            { hash: '#/kiosks', icon: '\u25A4', label: 'Kiosks' },
            { hash: '#/schedules', icon: '\u25F4', label: 'Schedules' },
            { hash: '#/overrides', icon: '\u26A1', label: 'Overrides' },
            { hash: '#/logs', icon: '\u2630', label: 'Action Log' },
            { hash: '#/settings', icon: '\u2699', label: 'Settings' },
        ];

        const nav = this.el('nav', { className: 'nav-section', 'aria-label': 'Primary navigation' });
        const navLabel = this.el('div', { className: 'nav-section-label', textContent: 'Navigation' });
        nav.appendChild(navLabel);

        navItems.forEach(item => {
            const navItem = this.el('button', {
                className: 'nav-item',
                type: 'button',
                role: 'link',
                'data-hash': item.hash,
                onClick: (e) => {
                    e.preventDefault();
                    window.location.hash = item.hash;
                    this.toggleMobileMenu(false);
                }
            }, [
                this.el('span', { className: 'nav-icon', textContent: item.icon }),
                this.el('span', { textContent: item.label })
            ]);
            nav.appendChild(navItem);
        });
        sidebar.appendChild(nav);

        // Sidebar footer
        const footer = this.el('div', { className: 'sidebar-footer' }, [
            this.el('span', { className: 'sidebar-user', textContent: this.currentUser.display_name }),
            this.el('button', {
                className: 'btn btn-ghost btn-sm',
                textContent: 'Logout',
                onClick: async () => {
                    try {
                        await API.post('auth/logout');
                    } catch(e) {
                        // Surface logout failures so they're visible in dev tools
                        // instead of vanishing into a swallowed catch.
                        console.warn('Logout request failed:', e && e.message ? e.message : e);
                    }
                    this.currentUser = null;
                    window.location.hash = '#/login';
                }
            })
        ]);
        sidebar.appendChild(footer);

        layout.appendChild(sidebar);

        // Main content area
        const main = this.el('main', { className: 'main-content', id: 'main-content', tabindex: '-1' });
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
        const toast = this.el('div', { className: 'toast toast-' + type }, [
            this.el('span', { textContent: message })
        ]);
        this.toastContainer.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('toast-exit');
            setTimeout(() => toast.remove(), 200);
        }, duration);
    },

    // ---- Modal ----
    showModal(titleText, contentEl, footerEl) {
        this.hideModal();
        const overlay = this.el('div', { className: 'modal-overlay', id: 'modal-overlay' });
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) this.hideModal();
        });

        const modal = this.el('div', { className: 'modal' });
        const header = this.el('div', { className: 'modal-header' }, [
            this.el('div', { className: 'modal-title', textContent: titleText }),
            this.el('button', { className: 'modal-close', textContent: '\u00D7', onClick: () => this.hideModal() })
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

        // Auto-focus first focusable element in the modal
        requestAnimationFrame(() => {
            const first = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
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

    confirm(message) {
        return new Promise((resolve) => {
            const body = this.el('p', { textContent: message });
            const footer = this.el('div', { className: 'flex gap-sm' }, [
                this.el('button', {
                    className: 'btn btn-secondary',
                    textContent: 'Cancel',
                    onClick: () => { this.hideModal(); resolve(false); }
                }),
                this.el('button', {
                    className: 'btn btn-danger',
                    textContent: 'Confirm',
                    onClick: () => { this.hideModal(); resolve(true); }
                })
            ]);
            this.showModal('Confirm Action', body, footer);
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
        const label = status === 'outOfService' ? 'Out of Service' : status;
        return this.el('span', { className: 'badge ' + cls, textContent: label, role: 'status', 'aria-label': 'Status: ' + label });
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
        if (!sidebar) return;
        const isOpen = typeof forceState === 'boolean' ? forceState : !sidebar.classList.contains('sidebar-open');
        sidebar.classList.toggle('sidebar-open', isOpen);
        if (overlay) overlay.classList.toggle('active', isOpen);
        const menuBtn = document.getElementById('mobile-menu-btn');
        if (menuBtn) {
            menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            menuBtn.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
        }
        document.body.classList.toggle('menu-open', isOpen);
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
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());
