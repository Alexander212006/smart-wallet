(() => {
    let alertHost = document.querySelector('.app-alert-host');
    if (!alertHost) {
        alertHost = document.createElement('div');
        alertHost.className = 'app-alert-host';
        document.body.append(alertHost);
    }
    alertHost.setAttribute('role', 'region');
    alertHost.setAttribute('aria-label', 'Notifications');
    alertHost.removeAttribute('aria-live');

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
        el.setAttribute('role', alertType === 'error' ? 'alert' : 'status');
        el.setAttribute('aria-atomic', 'true');

        const icon = document.createElement('span');
        icon.className = 'app-alert__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = icons[alertType];

        const body = document.createElement('div');
        body.className = 'app-alert__body';

        const title = document.createElement('span');
        title.className = 'app-alert__title';
        title.textContent = labels[alertType];

        const text = document.createElement('span');
        text.className = 'app-alert__message';
        text.textContent = String(message);

        body.append(title, text);

        const closeBtn = document.createElement('button');
        closeBtn.className = 'app-alert__close';
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Dismiss notification');
        closeBtn.innerHTML = '<svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M2.5 2.5l7 7m0-7l-7 7"/></svg>';

        const progress = document.createElement('div');
        progress.className = 'app-alert__progress';
        progress.setAttribute('aria-hidden', 'true');

        let dismissed = false;
        let timer;
        let remaining = ['error', 'warning'].includes(alertType) ? 8000 : 6000;
        let startedAt;
        let hovering = false;
        let focusing = false;
        el.style.setProperty('--alert-duration', `${remaining}ms`);
        const dismiss = () => {
            if (dismissed) return;
            dismissed = true;
            window.clearTimeout(timer);
            el.classList.add('app-alert--leaving');
            window.setTimeout(() => el.remove(), 140);
        };

        const start = () => {
            startedAt = performance.now();
            timer = window.setTimeout(dismiss, remaining);
        };
        const syncPause = () => {
            if (dismissed) return;
            const shouldPause = hovering || focusing;
            const wasPaused = el.classList.contains('app-alert--paused');
            if (shouldPause === wasPaused) return;
            el.classList.toggle('app-alert--paused', shouldPause);
            if (shouldPause) {
                window.clearTimeout(timer);
                remaining = Math.max(0, remaining - (performance.now() - startedAt));
            } else {
                start();
            }
        };
        el.addEventListener('mouseenter', () => { hovering = true; syncPause(); });
        el.addEventListener('mouseleave', () => {
            hovering = false;
            syncPause();
        });
        el.addEventListener('focusin', () => { focusing = true; syncPause(); });
        el.addEventListener('focusout', (event) => {
            focusing = el.contains(event.relatedTarget);
            syncPause();
        });

        closeBtn.addEventListener('click', dismiss);

        el.append(icon, body, closeBtn, progress);
        alertHost.append(el);
        start();
    };

    window.sileoAlert = window.walletAlert;
})();
