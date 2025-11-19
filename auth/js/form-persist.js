/**
 * Lightweight form persistence using sessionStorage.
 * Attach `data-persist-key="unique-key"` on the <form>.
 */
(function () {
    if (typeof window === 'undefined' || !window.sessionStorage) {
        return;
    }

    function serialize(form) {
        const data = {};
        const elements = form.querySelectorAll('input, select, textarea');
        elements.forEach((el) => {
            if (el.dataset.persist === 'ignore') {
                return;
            }
            const type = (el.type || '').toLowerCase();
            if (type === 'password' || type === 'file') {
                return;
            }
            if ((type === 'checkbox' || type === 'radio') && !el.checked) {
                return;
            }
            if (!el.name) {
                return;
            }
            data[el.name] = el.value;
        });
        return data;
    }

    function restore(form, data) {
        const elements = form.querySelectorAll('input, select, textarea');
        elements.forEach((el) => {
            if (!el.name || !(el.name in data)) {
                return;
            }
            const type = (el.type || '').toLowerCase();
            if (type === 'checkbox' || type === 'radio') {
                el.checked = data[el.name] === el.value || data[el.name] === true || data[el.name] === 'on';
            } else {
                el.value = data[el.name];
            }
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const forms = document.querySelectorAll('form[data-persist-key]');
        forms.forEach((form) => {
            const key = form.getAttribute('data-persist-key');
            if (!key) {
                return;
            }
            const storageKey = `museek:form:${key}`;
            const storedRaw = sessionStorage.getItem(storageKey);
            if (storedRaw) {
                try {
                    const parsed = JSON.parse(storedRaw);
                    restore(form, parsed);
                } catch (_) {
                    sessionStorage.removeItem(storageKey);
                }
            }

            const save = () => {
                sessionStorage.setItem(storageKey, JSON.stringify(serialize(form)));
            };

            form.addEventListener('input', save);
            form.addEventListener('change', save);
            form.addEventListener('submit', () => sessionStorage.removeItem(storageKey));
        });
    });
})();

