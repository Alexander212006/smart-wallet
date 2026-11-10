(() => {
    let requestId = 0;

    const isAppPage = (documentNode) =>
        documentNode.body instanceof HTMLElement && documentNode.body.dataset.appShell === 'true';

    const isInternalPhpUrl = (url) =>
        url.origin === window.location.origin && url.pathname.toLowerCase().endsWith('.php');

    const showAlert = (type, message) => {
        window.walletAlert(type, message);
    };

    const showError = (message) => {
        showAlert('error', message);
    };

    const createActionConfirmation = () => {
        const dialog = document.createElement('dialog');
        dialog.className = 'app-modal app-confirmation';
        dialog.setAttribute('aria-labelledby', 'app-confirmation-title');
        dialog.setAttribute('aria-describedby', 'app-confirmation-description');
        dialog.innerHTML = `
            <div class="app-modal-header">
                <span class="app-modal-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path data-confirm-icon-path stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                <div class="app-modal-heading"><h2 id="app-confirmation-title"></h2></div>
                <button type="button" data-app-confirm-close class="app-modal-close" aria-label="Close confirmation"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
            </div>
            <p id="app-confirmation-description" class="app-modal-description"></p>
            <div class="app-modal-footer">
                <button type="button" data-app-confirm-cancel class="app-modal-button app-modal-secondary">Cancel</button>
                <button type="button" data-app-confirm-submit class="app-modal-button app-modal-primary">Confirm</button>
            </div>
        `;

        document.body.append(dialog);
        return dialog;
    };

    const actionConfirmationDialog = createActionConfirmation();
    const actionConfirmationTitle = actionConfirmationDialog.querySelector('#app-confirmation-title');
    const actionConfirmationDescription = actionConfirmationDialog.querySelector('#app-confirmation-description');
    const actionConfirmationSubmit = actionConfirmationDialog.querySelector('[data-app-confirm-submit]');
    let resolveActionConfirmation;

    const finishActionConfirmation = (confirmed) => {
        resolveActionConfirmation?.(confirmed);
        resolveActionConfirmation = undefined;
    };

    const closeActionConfirmation = (result) => {
        if (!actionConfirmationDialog.open || actionConfirmationDialog.hasAttribute('data-closing')) {
            return;
        }
        actionConfirmationDialog.dataset.closing = 'true';
        window.setTimeout(() => {
            actionConfirmationDialog.close(result);
            actionConfirmationDialog.removeAttribute('data-closing');
        }, 120);
    };

    actionConfirmationDialog.addEventListener('close', () => {
        finishActionConfirmation(actionConfirmationDialog.returnValue === 'confirm');
    });
    actionConfirmationDialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeActionConfirmation('cancel');
    });
    actionConfirmationDialog.querySelector('[data-app-confirm-cancel]').addEventListener('click', () => {
        closeActionConfirmation('cancel');
    });
    actionConfirmationDialog.querySelector('[data-app-confirm-close]').addEventListener('click', () => closeActionConfirmation('cancel'));
    actionConfirmationDialog.addEventListener('click', (event) => {
        if (event.target !== actionConfirmationDialog) return;
        const bounds = actionConfirmationDialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeActionConfirmation('cancel');
    });
    actionConfirmationSubmit.addEventListener('click', () => {
        closeActionConfirmation('confirm');
    });

    const confirmAppAction = ({ title, description, confirmLabel, danger = false, icon = '' }) => new Promise((resolve) => {
        if (actionConfirmationDialog.open) {
            resolve(false);
            return;
        }
        actionConfirmationTitle.textContent = title;
        actionConfirmationDescription.textContent = description;
        actionConfirmationSubmit.textContent = confirmLabel;
        actionConfirmationDialog.dataset.variant = danger ? 'danger' : 'confirm';
        actionConfirmationDialog.querySelector('[data-confirm-icon-path]').setAttribute('d', icon === 'logout'
            ? 'M9 4H5v16h4m6-12 4 4-4 4m-7-4h11'
            : danger
            ? 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 10v7m4-7v7'
            : 'm5 12 4 4L19 6');
        actionConfirmationSubmit.className = 'app-modal-button ' + (danger ? 'app-modal-danger' : 'app-modal-primary');
        resolveActionConfirmation = resolve;
        try {
            actionConfirmationDialog.showModal();
            actionConfirmationDialog.querySelector('[data-app-confirm-cancel]').focus();
        } catch (error) {
            resolveActionConfirmation = undefined;
            console.error('Unable to open confirmation dialog:', error);
            resolve(window.confirm(`${title}\n\n${description}`));
        }
    });
    window.confirmWalletAction = (action) => confirmAppAction(action === 'archive'
        ? {
            title: 'Archive this wallet?',
            description: 'It will be removed from active wallet views and expense totals. You can restore it at any time.',
            confirmLabel: 'Archive wallet',
            danger: true,
        }
        : {
            title: 'Restore this wallet?',
            description: 'This wallet will return to your active wallet views and expense totals.',
            confirmLabel: 'Restore wallet',
        });

    const initializeFlashMessages = (root = document) => {
        root.querySelectorAll('[data-flash-message]').forEach((flash) => {
            showAlert(flash.dataset.type || 'info', flash.dataset.message || flash.textContent.trim());
            flash.remove();
        });
    };

    const closeAppDialog = (dialog) => {
        if (!dialog.open || dialog.hasAttribute('data-closing')) {
            return;
        }
        dialog.dataset.closing = 'true';
        window.setTimeout(() => {
            dialog.close();
            dialog.removeAttribute('data-closing');
        }, 120);
    };

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const openButton = event.target.closest('[data-open-expense-dialog], [data-open-app-dialog]');
        if (openButton) {
            const dialog = document.getElementById(openButton.dataset.openAppDialog || `expense-modal-${openButton.dataset.openExpenseDialog}`);
            if (dialog instanceof HTMLDialogElement && !dialog.open) {
                dialog.showModal();
                dialog.querySelector('input:not([type="hidden"]), select')?.focus({ preventScroll: true });
            }
            return;
        }

        const closeButton = event.target.closest('[data-close-expense-modal], [data-close-app-modal]');
        if (closeButton) {
            const dialog = closeButton.closest('dialog[data-expense-modal], dialog[data-app-modal]');
            if (dialog instanceof HTMLDialogElement) {
                closeAppDialog(dialog);
            }
        }
    });

    const initializeAppDialogs = (root = document) => {
        root.querySelectorAll('dialog[data-expense-modal], dialog[data-app-modal]').forEach((dialog) => {
            if (!dialog.hasAttribute('data-modal-initialized')) {
                dialog.addEventListener('cancel', (event) => {
                    event.preventDefault();
                    closeAppDialog(dialog);
                });
                dialog.addEventListener('click', (event) => {
                    const bounds = dialog.getBoundingClientRect();
                    if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) {
                        closeAppDialog(dialog);
                    }
                });
                dialog.setAttribute('data-modal-initialized', '');
            }

            if (dialog.hasAttribute('data-open-on-load')) {
                dialog.removeAttribute('data-open-on-load');
                dialog.showModal();
                dialog.querySelector('input:not([type="hidden"]), select')?.focus({ preventScroll: true });
            }
        });
    };

    const initializeCustomCategoryFields = (root = document) => {
        root.querySelectorAll('select[data-custom-category-select]').forEach((select) => {
            const control = select.closest('[data-category-control]');
            const field = control?.querySelector('[data-custom-category-field]');
            const input = field?.querySelector('[data-custom-category-input]');
            if (!(field instanceof HTMLElement) || !(input instanceof HTMLInputElement)) {
                return;
            }

            const updateVisibility = () => {
                const isOther = select.value === 'other';
                field.hidden = !isOther;
                field.classList.toggle('hidden', !isOther);
                input.required = isOther;
            };

            if (!select.hasAttribute('data-category-initialized')) {
                select.addEventListener('change', updateVisibility);
                select.setAttribute('data-category-initialized', '');
            }
            updateVisibility();
        });
    };

    const initializeExpenseLimitRecurrenceControls = (root = document) => {
        root.querySelectorAll('input[data-expense-limit-recurring]').forEach((checkbox) => {
            if (!(checkbox instanceof HTMLInputElement) || checkbox.hasAttribute('data-recurrence-initialized')) {
                return;
            }

            const form = checkbox.form;
            const endDate = form?.querySelector('[data-expense-limit-end-date]');
            const stoppedEndDate = form?.querySelector('[data-expense-limit-stopped-end]');
            if (!(endDate instanceof HTMLInputElement)) {
                return;
            }

            const syncControls = () => {
                endDate.disabled = checkbox.checked;
                if (stoppedEndDate instanceof HTMLInputElement) {
                    stoppedEndDate.disabled = !checkbox.checked;
                }
            };

            checkbox.addEventListener('change', () => {
                if (!checkbox.checked) {
                    endDate.value = '';
                } else if (!(stoppedEndDate instanceof HTMLInputElement)) {
                    endDate.value = '';
                }
                syncControls();
            });
            checkbox.setAttribute('data-recurrence-initialized', '');
            syncControls();
        });
    };

    const initializeActiveNavigation = (root = document) => {
        root.querySelectorAll('nav[aria-label="Main navigation"]').forEach((navigation) => {
            const activeLink = navigation.querySelector('[aria-current="page"]');
            if (!(activeLink instanceof HTMLElement) || navigation.scrollWidth <= navigation.clientWidth) {
                return;
            }

            const navigationBounds = navigation.getBoundingClientRect();
            const activeBounds = activeLink.getBoundingClientRect();
            navigation.scrollLeft += activeBounds.left - navigationBounds.left
                - ((navigationBounds.width - activeBounds.width) / 2);
        });
    };

    initializeCustomCategoryFields();
    initializeAppDialogs();
    initializeExpenseLimitRecurrenceControls();
    initializeActiveNavigation();

    const navigate = async (url, { method = 'GET', body, historyMode = 'push' } = {}) => {
        const currentRequestId = ++requestId;
        const main = document.querySelector('main');
        main?.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, {
                method,
                body,
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const contentType = response.headers.get('content-type') || '';

            if (!contentType.includes('text/html')) {
                if (response.redirected && response.url) {
                    window.location.assign(response.url);
                    return;
                }
                throw new Error(`The server returned an unexpected response (${response.status}).`);
            }

            const html = await response.text();
            const nextDocument = new DOMParser().parseFromString(html, 'text/html');
            if (!isAppPage(nextDocument)) {
                if (response.redirected) {
                    window.location.assign(response.url);
                    return;
                }
                throw new Error('The requested page did not return valid application content.');
            }

            if (currentRequestId !== requestId) {
                return;
            }

            const nextAppContent = nextDocument.querySelector('#app-content');
            const currentAppContent = document.querySelector('#app-content');
            if (!nextAppContent || !currentAppContent) {
                throw new Error('The requested page did not include the application shell.');
            }

            document.title = nextDocument.title;
            currentAppContent.replaceWith(nextAppContent);
            initializeFlashMessages(document);
            initializeCustomCategoryFields(document);
            initializeAppDialogs(document);
            initializeExpenseLimitRecurrenceControls(document);
            initializeActiveNavigation(document);

            const nextUrl = new URL(response.url);
            const requestedUrl = new URL(url, window.location.href);
            if (method === 'GET' && requestedUrl.hash && nextUrl.pathname === requestedUrl.pathname) {
                nextUrl.hash = requestedUrl.hash;
            }
            if (historyMode === 'push') {
                window.history.pushState({}, '', nextUrl);
            } else if (historyMode === 'replace') {
                window.history.replaceState({}, '', nextUrl);
            }

            const anchor = nextUrl.hash ? document.getElementById(decodeURIComponent(nextUrl.hash.slice(1))) : null;
            if (anchor) {
                anchor.scrollIntoView({ block: 'start' });
                (anchor.querySelector('input:not([type="hidden"]),select,textarea,button') || document.querySelector('main'))?.focus({ preventScroll: true });
            } else {
                window.scrollTo(0, 0);
                document.querySelector('main')?.focus({ preventScroll: true });
            }

            if (!response.ok) {
                showError(`The request could not be completed (${response.status}).`);
            }
        } catch (error) {
            if (currentRequestId === requestId) {
                console.error('Application navigation failed:', error);
                showError(error instanceof Error ? error.message : 'Something went wrong. Please try again.');
            }
        } finally {
            if (currentRequestId === requestId) {
                document.querySelector('main')?.removeAttribute('aria-busy');
            }
        }
    };

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const link = event.target.closest('a[href]');
        if (
            !link ||
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey ||
            link.target ||
            link.hasAttribute('download')
        ) {
            return;
        }

        const url = new URL(link.href, window.location.href);
        if (!isInternalPhpUrl(url)) {
            return;
        }

        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) {
            return;
        }

        event.preventDefault();
        navigate(url);
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
            return;
        }

        const method = (form.method || 'GET').toUpperCase();
        const url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        if (!isInternalPhpUrl(url) || !['GET', 'POST'].includes(method)) {
            return;
        }

        const formData = new FormData(form);
        const walletAction = formData.get('action');
        if (walletAction === 'archive' || walletAction === 'restore') {
            event.preventDefault();
            const submitWalletAction = () => {
                navigate(url, { method, body: formData, historyMode: 'replace' });
            };

            window.confirmWalletAction(walletAction).then((confirmed) => {
                if (confirmed) {
                    submitWalletAction();
                }
            });
            return;
        }

        if (form.dataset.confirmTitle) {
            event.preventDefault();
            confirmAppAction({
                title: form.dataset.confirmTitle,
                description: form.dataset.confirmDescription || 'Please confirm this action.',
                confirmLabel: form.dataset.confirmLabel || 'Confirm',
                danger: form.dataset.confirmVariant === 'danger',
                icon: form.dataset.confirmIcon,
            }).then((confirmed) => {
                if (confirmed) {
                    navigate(url, { method, body: formData, historyMode: 'replace' });
                }
            });
            return;
        }

        event.preventDefault();

        if (method === 'GET') {
            for (const [key, value] of formData.entries()) {
                url.searchParams.delete(key);
                if (typeof value === 'string') {
                    url.searchParams.append(key, value);
                }
            }
            navigate(url, { historyMode: 'push' });
            return;
        }

        navigate(url, { method, body: formData, historyMode: 'replace' });
    });

    window.addEventListener('popstate', () => {
        navigate(window.location.href, { historyMode: 'none' });
    });

    initializeFlashMessages();
})();
