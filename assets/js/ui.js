(() => {
    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;
        const toggle = event.target.closest('[data-toggle-password]');
        if (toggle) {
            const input = document.getElementById(toggle.dataset.togglePassword);
            if (!(input instanceof HTMLInputElement)) return;
            const showing = input.type === 'password';
            input.type = showing ? 'text' : 'password';
            toggle.textContent = showing ? 'Hide' : 'Show';
            toggle.setAttribute('aria-pressed', String(showing));
            toggle.setAttribute('aria-label', `${showing ? 'Hide' : 'Show'} ${input.id === 'password_confirmation' ? 'password confirmation' : 'password'}`);
            return;
        }
        const dialog = document.getElementById('app-mobile-navigation');
        if (!(dialog instanceof HTMLDialogElement)) return;
        if (event.target.closest('[data-open-navigation]')) {
            dialog.showModal();
        } else if (event.target.closest('[data-close-navigation]') || event.target === dialog) {
            dialog.close();
        }
    });
    window.matchMedia('(min-width: 1024px)').addEventListener('change', (event) => {
        const dialog = document.getElementById('app-mobile-navigation');
        if (event.matches && dialog instanceof HTMLDialogElement && dialog.open) dialog.close();
    });
})();
