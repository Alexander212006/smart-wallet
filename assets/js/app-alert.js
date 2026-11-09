(() => {
    let alertHost = document.querySelector('.app-alert-host');
    if (!alertHost) {
        alertHost = document.createElement('div');
        alertHost.className = 'app-alert-host';
        alertHost.setAttribute('aria-live', 'polite');
        alertHost.setAttribute('aria-relevant', 'additions');
        document.body.append(alertHost);
    }

    const icons = {
        success: '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.75 9.75 7.5 13.5l6.75-9"/></svg>',
        error: '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="9" r="7.5"/><path d="M9 6v4"/><circle cx="9" cy="13" r="0.5" fill="currentColor" stroke="none"/></svg>',
        warning: '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2 1.5 16h15L9 2Z"/><path d="M9 7v4"/><circle cx="9" cy="13.5" r="0.5" fill="currentColor" stroke="none"/></svg>',
        info: '<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="9" r="7.5"/><path d="M9 8.5V13"/><circle cx="9" cy="6" r="0.5" fill="currentColor" stroke="none"/></svg>',
    };

    const labels = {
        success: 'Success',
        error: 'Error',
        warning: 'Warning',
        info: 'Notice',
    };

    window.walletAlert = (type, message) => {
        if (!document.body.contains(alertHost)) {
            document.body.append(alertHost);
        }

        const alertType = Object.hasOwn(icons, type) ? type : 'info';
        const el = document.createElement('div');
        el.className = `app-alert app-alert--${alertType}`;
        el.style.position = 'relative';
        el.style.overflow = 'hidden';
        el.setAttribute('role', alertType === 'error' ? 'alert' : 'status');

        const icon = document.createElement('span');
        icon.className = 'app-alert__icon';
        icon.innerHTML = icons[alertType];

        const body = document.createElement('div');
        body.className = 'app-alert__body';

        const title = document.createElement('span');
        title.className = 'app-alert__title';
        title.textContent = labels[alertType];

        const text = document.createElement('span');
        text.className = 'app-alert__message';
        text.textContent = String(message);

        body.append(title, document.createTextNode(' — '), text);

        const closeBtn = document.createElement('button');
        closeBtn.className = 'app-alert__close';
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Dismiss');
        closeBtn.innerHTML = '<svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M2.5 2.5l7 7m0-7l-7 7"/></svg>';

        const progress = document.createElement('div');
        progress.className = 'app-alert__progress';

        let dismissed = false;
        const dismiss = () => {
            if (dismissed) return;
            dismissed = true;
            el.classList.add('app-alert--leaving');
            window.setTimeout(() => el.remove(), 140);
        };

        el.addEventListener('mouseenter', () => el.classList.add('app-alert--paused'));
        el.addEventListener('mouseleave', () => {
            el.classList.remove('app-alert--paused');
        });

        progress.addEventListener('animationend', dismiss);
        closeBtn.addEventListener('click', dismiss);

        el.append(icon, body, closeBtn, progress);
        alertHost.append(el);
    };

    window.sileoAlert = window.walletAlert;
})();
