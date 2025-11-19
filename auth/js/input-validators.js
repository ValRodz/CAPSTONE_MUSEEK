/**
 * Shared input helpers for registration forms.
 */
(function () {
    function restrictCharacters(input, pattern) {
        input.addEventListener('input', () => {
            const start = input.selectionStart;
            const end = input.selectionEnd;
            const filtered = input.value.replace(pattern, '');
            if (filtered !== input.value) {
                input.value = filtered;
                if (typeof start === 'number' && typeof end === 'number') {
                    input.setSelectionRange(start - 1, end - 1);
                }
            }
        });
    }

    function wireEmailValidation(input) {
        const group = input.closest('.form-group') || input.parentElement;
        const errorEl = group ? group.querySelector('[data-email-error]') : null;

        const showError = () => {
            if (!errorEl) return;
            errorEl.classList.add('show');
            input.classList.add('input-invalid');
        };
        const hideError = () => {
            if (!errorEl) return;
            errorEl.classList.remove('show');
            input.classList.remove('input-invalid');
        };

        const isValidEmail = (value) => {
            if (!value) return false;
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        };

        input.addEventListener('blur', () => {
            if (!isValidEmail(input.value.trim())) {
                showError();
            }
        });
        input.addEventListener('input', () => {
            if (isValidEmail(input.value.trim())) {
                hideError();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('input[data-allow="alpha"]').forEach((input) => {
            restrictCharacters(input, /[^A-Za-zÀ-ÖØ-öø-ÿ \-]/g);
        });

        document.querySelectorAll('input[data-email-validate]').forEach(wireEmailValidation);
    });
})();

