/**
 * Password requirement checklist + confirmation helper.
 *
 * Usage:
 *  - Add data-password-field to the primary password input.
 *  - Add data-password-confirm to the confirmation input.
 *  - Add a list with data-password-checklist containing <li data-rule="length|uppercase|...">
 *  - Add an element with data-password-match for real-time match status.
 */
(function () {
    const RULES = {
        length: (val) => val.length >= 8,
        uppercase: (val) => /[A-Z]/.test(val),
        lowercase: (val) => /[a-z]/.test(val),
        number: (val) => /[0-9]/.test(val),
        special: (val) => /[!@#$%^&*()_+\-=[\]{};':"\\|,.<>/?]/.test(val),
    };

    function updateChecklist(listEl, value) {
        if (!listEl) return;
        Object.entries(RULES).forEach(([rule, fn]) => {
            const item = listEl.querySelector(`[data-rule="${rule}"]`);
            if (!item) return;
            const passed = fn(value);
            item.classList.toggle('is-valid', passed);
            item.classList.toggle('is-invalid', !passed);
        });
    }

    function updateMatchStatus(statusEl, password, confirm) {
        if (!statusEl) return;
        if (!confirm) {
            statusEl.textContent = '';
            statusEl.classList.remove('is-valid', 'is-invalid');
            return;
        }

        const matches = password === confirm && password.length > 0;
        statusEl.classList.toggle('is-valid', matches);
        statusEl.classList.toggle('is-invalid', !matches);
        statusEl.textContent = matches ? 'Passwords match' : 'Passwords do not match yet';
    }

    document.addEventListener('DOMContentLoaded', () => {
        const passwordInputs = document.querySelectorAll('input[data-password-field]');
        passwordInputs.forEach((passwordInput) => {
            const form = passwordInput.form;
            if (!form) return;

            const confirmInput = form.querySelector('input[data-password-confirm]');
            const checklist = form.querySelector('[data-password-checklist]');
            const matchEl = form.querySelector('[data-password-match]');

            const handleInput = () => {
                const value = passwordInput.value || '';
                updateChecklist(checklist, value);
                if (confirmInput) {
                    updateMatchStatus(matchEl, value, confirmInput.value || '');
                }
            };

            passwordInput.addEventListener('input', handleInput);
            if (confirmInput) {
                confirmInput.addEventListener('input', () => {
                    updateMatchStatus(matchEl, passwordInput.value || '', confirmInput.value || '');
                });
            }

            handleInput();
        });
    });
})();

