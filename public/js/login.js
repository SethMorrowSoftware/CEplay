/**
 * Login page module.
 */
(function () {
    App.registerRoute('#/login', { render: renderLogin });

    function renderLogin(container) {
        const wrap = App.el('div', { className: 'login-container' });
        const card = App.el('div', { className: 'login-card' });

        // Header: logo mark + eyebrow + title + subtitle
        const header = App.el('div', { className: 'login-header' });
        header.appendChild(App.el('div', { className: 'login-mark', textContent: 'CE' }));

        const eyebrow = App.el('span', { className: 'login-eyebrow' });
        eyebrow.appendChild(App.iconEl('lock', 11));
        eyebrow.appendChild(App.el('span', { textContent: 'Secure access' }));
        header.appendChild(eyebrow);

        header.appendChild(App.el('div', { className: 'login-title', textContent: 'Castle Fun Center' }));
        header.appendChild(App.el('div', { className: 'login-subtitle', textContent: 'Sign in to manage games, kiosks, and schedules' }));
        card.appendChild(header);

        const errorBox = App.el('div', { className: 'login-error hidden', id: 'login-error', role: 'alert' });
        card.appendChild(errorBox);

        const form = App.el('form', { id: 'login-form', autocomplete: 'on' });

        const userGroup = App.el('div', { className: 'form-group' });
        userGroup.appendChild(App.el('label', { className: 'form-label', for: 'login-username', textContent: 'Username' }));
        const userInput = App.el('input', {
            className: 'form-input', type: 'text', id: 'login-username',
            placeholder: 'Enter your username', autocomplete: 'username', autofocus: 'true'
        });
        userGroup.appendChild(userInput);
        form.appendChild(userGroup);

        const passGroup = App.el('div', { className: 'form-group' });
        passGroup.appendChild(App.el('label', { className: 'form-label', for: 'login-password', textContent: 'Password' }));
        const passInput = App.el('input', {
            className: 'form-input', type: 'password', id: 'login-password',
            placeholder: '••••••••', autocomplete: 'current-password'
        });
        passGroup.appendChild(passInput);
        form.appendChild(passGroup);

        const submitBtn = App.el('button', {
            className: 'btn btn-primary btn-block', type: 'submit',
            textContent: 'Sign In',
            style: { marginTop: '0.85rem' }
        });
        form.appendChild(submitBtn);

        // Footer hint
        const footHint = App.el('p', {
            className: 'text-xs text-muted',
            style: { textAlign: 'center', marginTop: '1.5rem' },
            textContent: 'Sessions expire after 2 hours of inactivity.'
        });
        card.appendChild(form);
        card.appendChild(footHint);

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const username = userInput.value.trim();
            const password = passInput.value;

            if (!username || !password) {
                showError('Please enter both username and password.');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Signing in…';
            errorBox.classList.add('hidden');

            const loginTimeout = setTimeout(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Sign In';
                showError('Login is taking too long. Please try again.');
            }, 15000);

            try {
                const result = await API.post('auth/login', { username, password }) || {};
                clearTimeout(loginTimeout);
                App.currentUser = result.user;
                if (result.csrf_token) {
                    API.setCsrfToken(result.csrf_token);
                }
                window.location.hash = '#/dashboard';
            } catch (err) {
                clearTimeout(loginTimeout);
                showError(err.message || 'Login failed.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Sign In';
            }
        });

        wrap.appendChild(card);
        container.appendChild(wrap);

        function showError(msg) {
            errorBox.innerHTML = '';
            errorBox.appendChild(App.iconEl('warning', 16));
            errorBox.appendChild(App.el('span', { textContent: msg }));
            errorBox.classList.remove('hidden');
        }
    }
})();
