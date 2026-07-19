/**
 * API fetch wrapper with CSRF token injection and error handling.
 */
const API = {
    basePath: '',
    csrfToken: '',

    init(config) {
        this.basePath = config.basePath || '';
        this.csrfToken = config.csrfToken || '';
    },

    setCsrfToken(token) {
        this.csrfToken = token;
    },

    /** Milliseconds before an in-flight request is abandoned. */
    REQUEST_TIMEOUT_MS: 30000,

    async request(method, path, body, _retryCount) {
        _retryCount = _retryCount || 0;
        const url = this.basePath + '/api/' + path;
        const headers = { 'Accept': 'application/json' };
        // Only GETs are safe to retry automatically: a mutation whose
        // connection dropped AFTER the server processed it would be
        // silently executed twice (double pause action, duplicate group…).
        const retriable = method === 'GET';

        if (this.csrfToken && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
            headers['X-CSRF-Token'] = this.csrfToken;
        }
        if (body !== undefined && body !== null) {
            headers['Content-Type'] = 'application/json';
        }

        // Abort stalled connections so pages never hang on a spinner
        // forever — flaky venue Wi-Fi commonly stalls without erroring.
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timeoutId = controller
            ? setTimeout(function() { controller.abort(); }, this.REQUEST_TIMEOUT_MS)
            : null;

        const opts = {
            method: method,
            headers: headers,
            credentials: 'same-origin'
        };
        if (controller) opts.signal = controller.signal;
        if (body !== undefined && body !== null) {
            opts.body = JSON.stringify(body);
        }

        let response;
        try {
            response = await fetch(url, opts);
        } catch (networkErr) {
            const timedOut = networkErr && networkErr.name === 'AbortError';
            // Retry on network errors (offline, DNS, connection reset) up
            // to 2 times — GET only (see `retriable` above).
            if (retriable && _retryCount < 2) {
                await new Promise(function(r) { setTimeout(r, 1000 * Math.pow(2, _retryCount)); });
                return this.request(method, path, body, _retryCount + 1);
            }
            throw new ApiError(0, timedOut
                ? 'Request timed out. The server may be busy — please try again.'
                : 'Network error: unable to reach server. Check your connection.', null);
        } finally {
            if (timeoutId !== null) clearTimeout(timeoutId);
        }

        let data = null;
        const text = await response.text();
        if (text) {
            try { data = JSON.parse(text); } catch (e) { data = null; }
        }

        // Retry on 502/503/504 (transient server errors) up to 2 times.
        // GET only: a gateway timeout does NOT prove the backend didn't
        // finish the mutation behind it.
        if (retriable && [502, 503, 504].includes(response.status) && _retryCount < 2) {
            await new Promise(function(r) { setTimeout(r, 1000 * Math.pow(2, _retryCount)); });
            return this.request(method, path, body, _retryCount + 1);
        }

        // Ensure successful responses never return null so callers
        // can safely access properties like data.groups without crashing.
        if (data === null && response.ok) {
            data = {};
        }

        if (response.status === 401) {
            // Don't redirect if we're already on the login page (this is a login attempt)
            const isLoginRequest = path === 'auth/login';
            if (!isLoginRequest) {
                App.currentUser = null;
                window.location.hash = '#/login';
            }
            const msg = (data && data.error) ? data.error : 'Session expired. Please log in again.';
            throw new ApiError(401, msg, data && data.field);
        }

        if (!response.ok) {
            const msg = (data && data.error) ? data.error : 'Request failed (HTTP ' + response.status + ')';
            throw new ApiError(response.status, msg, data && data.field);
        }

        return data;
    },

    get(path) { return this.request('GET', path); },
    post(path, body) { return this.request('POST', path, body); },
    put(path, body) { return this.request('PUT', path, body); },
    patch(path, body) { return this.request('PATCH', path, body); },
    del(path) { return this.request('DELETE', path); }
};

class ApiError extends Error {
    constructor(status, message, field) {
        super(message);
        this.status = status;
        this.field = field || null;
    }
}
