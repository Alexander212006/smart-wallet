(() => {
    let requestId = 0;

    const isAppPage = (documentNode) =>
        documentNode.body instanceof HTMLElement && documentNode.body.dataset.appShell === 'true';

    const isInternalPhpUrl = (url) =>
        url.origin === window.location.origin && url.pathname.toLowerCase().endsWith('.php');

    const showToast = (type, message) => {
        window.walletAlert(type, message);
    };

    const showError = (message) => {
        showToast('error', message);
    };

    const createActionConfirmation = () => {
        const dialog = document.createElement('dialog');
        dialog.className = 'w-[calc(100%-2rem)] max-w-lg rounded-xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/50';
        dialog.setAttribute('aria-labelledby', 'app-confirmation-title');
        dialog.setAttribute('aria-describedby', 'app-confirmation-description');
        dialog.innerHTML = `
            <style>
                @keyframes app-confirmation-enter {
                    from { opacity: 0; transform: translateY(0.5rem) scale(0.97); }
                    to { opacity: 1; transform: translateY(0) scale(1); }
                }
                @keyframes app-confirmation-exit {
                    from { opacity: 1; transform: translateY(0) scale(1); }
                    to { opacity: 0; transform: translateY(0.5rem) scale(0.97); }
                }
                @keyframes app-confirmation-backdrop-enter {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }
                @keyframes app-confirmation-backdrop-exit {
                    from { opacity: 1; }
                    to { opacity: 0; }
                }
                dialog[open] { animation: app-confirmation-enter 140ms ease-out both; }
                dialog[open]::backdrop { animation: app-confirmation-backdrop-enter 140ms ease-out both; }
                dialog[data-closing] { animation: app-confirmation-exit 120ms ease-in both; }
                dialog[data-closing]::backdrop { animation: app-confirmation-backdrop-exit 120ms ease-in both; }
                @media (prefers-reduced-motion: reduce) {
                    dialog[open], dialog[open]::backdrop, dialog[data-closing], dialog[data-closing]::backdrop {
                        animation-duration: 1ms;
                    }
                }
            </style>
            <div class="grid gap-4 p-6">
                <div class="space-y-2">
                    <h2 id="app-confirmation-title" class="text-lg font-semibold tracking-tight"></h2>
                    <p id="app-confirmation-description" class="text-sm leading-6 text-slate-500"></p>
                </div>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" data-app-confirm-cancel class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-4 text-sm font-medium transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">Cancel</button>
                    <button type="button" data-app-confirm-submit class="inline-flex h-10 items-center justify-center rounded-md px-4 text-sm font-medium text-white transition focus-visible:outline-none focus-visible:ring-2">Confirm</button>
                </div>
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
    actionConfirmationSubmit.addEventListener('click', () => {
        closeActionConfirmation('confirm');
    });

    const confirmAppAction = ({ title, description, confirmLabel, danger = false }) => new Promise((resolve) => {
        if (actionConfirmationDialog.open) {
            resolve(false);
            return;
        }
        actionConfirmationTitle.textContent = title;
        actionConfirmationDescription.textContent = description;
        actionConfirmationSubmit.textContent = confirmLabel;
        actionConfirmationSubmit.className = danger
            ? 'inline-flex h-10 items-center justify-center rounded-md bg-rose-600 px-4 text-sm font-medium text-white transition hover:bg-rose-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500'
            : 'inline-flex h-10 items-center justify-center rounded-md bg-emerald-700 px-4 text-sm font-medium text-white transition hover:bg-emerald-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500';
        resolveActionConfirmation = resolve;
        try {
            actionConfirmationDialog.showModal();
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
            showToast(flash.dataset.type || 'info', flash.dataset.message || flash.textContent.trim());
            flash.remove();
        });
    };

    const closeExpenseDialog = (dialog) => {
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

        const openButton = event.target.closest('[data-open-expense-dialog]');
        if (openButton) {
            const dialog = document.getElementById(`expense-modal-${openButton.dataset.openExpenseDialog}`);
            if (dialog instanceof HTMLDialogElement && !dialog.open) {
                dialog.showModal();
                dialog.querySelector('input:not([type="hidden"]), select')?.focus({ preventScroll: true });
            }
            return;
        }

        const closeButton = event.target.closest('[data-close-expense-modal]');
        if (closeButton) {
            const dialog = closeButton.closest('dialog[data-expense-modal]');
            if (dialog instanceof HTMLDialogElement) {
                closeExpenseDialog(dialog);
            }
        }
    });

    const initializeExpenseDialogs = (root = document) => {
        root.querySelectorAll('dialog[data-expense-modal]').forEach((dialog) => {
            const main = document.querySelector('main');
            const contentTop = main?.getBoundingClientRect().top ?? 0;
            const visibleHeight = window.visualViewport?.height ?? window.innerHeight;
            const modalCenter = contentTop + (visibleHeight - contentTop) / 2;
            dialog.style.setProperty('--expense-modal-top', `${modalCenter}px`);

            if (!dialog.hasAttribute('data-modal-initialized')) {
                dialog.addEventListener('cancel', (event) => {
                    event.preventDefault();
                    closeExpenseDialog(dialog);
                });
                dialog.addEventListener('click', (event) => {
                    if (event.target === dialog) {
                        closeExpenseDialog(dialog);
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

    initializeCustomCategoryFields();
    initializeExpenseDialogs();
    window.addEventListener('resize', () => initializeExpenseDialogs());

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
            initializeExpenseDialogs(document);

            const nextUrl = new URL(response.url);
            if (historyMode === 'push') {
                window.history.pushState({}, '', nextUrl);
            } else if (historyMode === 'replace') {
                window.history.replaceState({}, '', nextUrl);
            }

            window.scrollTo(0, 0);
            document.querySelector('main')?.focus({ preventScroll: true });

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
