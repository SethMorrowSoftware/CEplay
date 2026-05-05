/**
 * Login page module.
 */
(function() {
    App.registerRoute('#/login', { render: renderLogin });

    function renderLogin(container) {
        const wrap = App.el('div', { className: 'login-container' });
        const card = App.el('div', { className: 'login-card' });

        card.appendChild(App.el('div', { className: 'login-brand', 'aria-hidden': 'true', textContent: 'CF' }));
        card.appendChild(App.el('div', { className: 'login-title', textContent: 'Castle Fun Center' }));
        card.appendChild(App.el('div', { className: 'login-subtitle' }, [
            App.el('span', { textContent: 'Sign in to manage game schedules' })
        ]));

        const errorBox = App.el('div', { className: 'login-error hidden', id: 'login-error' });
        card.appendChild(errorBox);

        const form = App.el('form', { id: 'login-form' });

        const userGroup = App.el('div', { className: 'form-group' });
        userGroup.appendChild(App.el('label', { className: 'form-label', textContent: 'Username' }));
        const userInput = App.el('input', {
            className: 'form-input', type: 'text', id: 'login-username',
            placeholder: 'Enter username', autocomplete: 'username', autofocus: 'true'
        });
        userGroup.appendChild(userInput);
        form.appendChild(userGroup);

        const passGroup = App.el('div', { className: 'form-group' });
        passGroup.appendChild(App.el('label', { className: 'form-label', textContent: 'Password' }));
        const passInput = App.el('input', {
            className: 'form-input', type: 'password', id: 'login-password',
            placeholder: 'Enter password', autocomplete: 'current-password'
        });
        passGroup.appendChild(passInput);
        form.appendChild(passGroup);

        const submitBtn = App.el('button', {
            className: 'btn btn-primary', type: 'submit',
            textContent: 'Sign In',
            style: { width: '100%', marginTop: '0.5rem', justifyContent: 'center' }
        });
        form.appendChild(submitBtn);

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const username = userInput.value.trim();
            const password = passInput.value;

            if (!username || !password) {
                showError('Please enter both username and password.');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Signing in...';
            errorBox.classList.add('hidden');

            // Timeout protection: if login takes more than 15s, re-enable the button
            const loginTimeout = setTimeout(function() {
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

        card.appendChild(form);
        wrap.appendChild(card);
        container.appendChild(wrap);

        function showError(msg) {
            errorBox.textContent = msg;
            errorBox.classList.remove('hidden');
        }
    }
})();
