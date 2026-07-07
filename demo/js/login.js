/**
 * Login page module.
 */
(function() {
    App.registerRoute('#/login', { render: renderLogin });

    function renderLogin(container) {
        const wrap = App.el('div', { className: 'login-container' });
        const card = App.el('div', { className: 'login-card' });

        // Brand block — gradient logo mark + "Castle Fun Center" pill
        const mark = App.el('div', { className: 'login-brand-mark', 'aria-hidden': 'true' });
        if (typeof Icons !== 'undefined' && Icons.castle) {
            mark.innerHTML = Icons.castle;
        } else {
            mark.textContent = 'C';
        }
        const tag = App.el('span', { className: 'login-brand-tag', textContent: 'Castle Fun Center' });
        card.appendChild(App.el('div', { className: 'login-brand' }, [mark, tag]));

        card.appendChild(App.el('div', { className: 'login-title', textContent: 'Welcome back' }));
        card.appendChild(App.el('div', { className: 'login-subtitle',
            textContent: 'Sign in to manage games, kiosks, and pause schedules.' }));

        const errorBox = App.el('div', {
            className: 'login-error hidden',
            id: 'login-error',
            role: 'alert',
            'aria-live': 'assertive'
        });
        card.appendChild(errorBox);

        const form = App.el('form', { id: 'login-form', autocomplete: 'on', noValidate: 'true' });

        const userGroup = App.el('div', { className: 'form-group' });
        userGroup.appendChild(App.el('label', { className: 'form-label', for: 'login-username', textContent: 'Username' }));
        const userInput = App.el('input', {
            className: 'form-input', type: 'text', id: 'login-username', name: 'username',
            placeholder: 'Enter username', autocomplete: 'username', required: 'required'
        });
        userInput.autofocus = true;
        userGroup.appendChild(userInput);
        form.appendChild(userGroup);

        const passGroup = App.el('div', { className: 'form-group' });
        passGroup.appendChild(App.el('label', { className: 'form-label', for: 'login-password', textContent: 'Password' }));
        const passInput = App.el('input', {
            className: 'form-input', type: 'password', id: 'login-password', name: 'password',
            placeholder: 'Enter password', autocomplete: 'current-password', required: 'required'
        });
        passGroup.appendChild(passInput);
        form.appendChild(passGroup);

        const submitBtn = App.el('button', {
            className: 'btn btn-primary btn-lg btn-block', type: 'submit',
            textContent: 'Sign In',
            style: { marginTop: '0.5rem' }
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

        // Discreet footer — orients new users without cluttering the form.
        card.appendChild(App.el('div', {
            className: 'login-footer',
            textContent: 'Castle Fun Center  ·  Pause Group Automation'
        }));

        wrap.appendChild(card);
        container.appendChild(wrap);

        function showError(msg) {
            errorBox.textContent = msg;
            errorBox.classList.remove('hidden');
            // Move focus to whichever field is empty so the user can recover
            // immediately. Falls back to the username field.
            requestAnimationFrame(function() {
                if (!userInput.value.trim()) userInput.focus();
                else if (!passInput.value) passInput.focus();
                else passInput.select();
            });
        }
    }
})();
