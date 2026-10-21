(function () {
    'use strict';

    const config = window.oyisoCheckoutMaintenance;
    if (!config) return;

    const dialog = document.getElementById('oyiso-checkout-maintenance-dialog');
    if (!dialog) return;

    const title = dialog.querySelector('.oyiso-checkout-maintenance-dialog__title');
    const message = dialog.querySelector('.oyiso-checkout-maintenance-dialog__message');
    const button = dialog.querySelector('.oyiso-checkout-maintenance-dialog__button');
    let previousFocus = null;

    function openDialog(settings) {
        const content = Object.assign({}, config.settings, settings);
        title.textContent = content.title || config.defaults.title;
        message.textContent = content.message || config.defaults.message;
        button.textContent = content.button || config.defaults.button;
        dialog.dataset.template = content.template === 'default' ? content.template : 'default';
        if (dialog.open) return;

        previousFocus = document.activeElement;
        document.documentElement.classList.add('oyiso-checkout-maintenance-open');
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
        button.focus({ preventScroll: true });
    }

    function finishClose() {
        document.documentElement.classList.remove('oyiso-checkout-maintenance-open');
        if (previousFocus && previousFocus.isConnected) previousFocus.focus({ preventScroll: true });
        previousFocus = null;
    }

    function closeDialog() {
        if (typeof dialog.close === 'function') dialog.close();
        else {
            dialog.removeAttribute('open');
            finishClose();
        }
    }

    dialog.addEventListener('close', finishClose);
    dialog.addEventListener('click', function (event) {
        if (event.target.closest('[data-oyiso-maintenance-close]')) closeDialog();
        else if (event.target === dialog) {
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeDialog();
        }
    });
    dialog.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && typeof dialog.close !== 'function') closeDialog();
    });

    function showClassicNotice() {
        const notice = document.querySelector('[data-oyiso-checkout-maintenance]');
        if (!notice) return;
        try {
            openDialog(JSON.parse(notice.getAttribute('data-oyiso-checkout-maintenance')));
        } catch (error) {
            // Keep WooCommerce's normal text notice when another extension changes its markup.
        }
    }

    if (window.jQuery) window.jQuery(document.body).on('checkout_error.oyisoCheckoutMaintenance', showClassicNotice);
    showClassicNotice();

    function showStoreApiError(error) {
        if (error && error.code === 'oyiso_checkout_maintenance') {
            openDialog(Object.assign({}, error.data && error.data.oyiso_checkout_maintenance, { message: error.message }));
        }
    }

    function showStoreApiResponse(response) {
        showStoreApiError(response);
        if (response && Array.isArray(response.responses)) {
            response.responses.forEach(function (item) { showStoreApiError(item.body); });
        }
    }

    async function observeStoreApiResponse(response, options) {
        if (response && typeof response.clone === 'function') {
            // Blocks requests a raw Response to read nonce headers. Read a clone without consuming its body.
            if (!response.ok || /\/batch(?:[/?]|$)/.test(options.path || options.url || '')) {
                try { showStoreApiResponse(await response.clone().json()); } catch (error) { /* Keep the original response. */ }
            }
        } else showStoreApiResponse(response);
    }

    if (window.wp && window.wp.apiFetch && typeof window.wp.apiFetch.use === 'function') {
        window.wp.apiFetch.use(function (options, next) {
            // Observe the server response; WooCommerce still submits and handles its own checkout errors.
            return next(options).then(async function (response) {
                await observeStoreApiResponse(response, options);
                return response;
            }).catch(async function (error) {
                await observeStoreApiResponse(error, options);
                throw error;
            });
        });
    }

    let statusRequest = null;
    function checkMaintenanceStatus() {
        if (statusRequest || !config.statusUrl || typeof window.fetch !== 'function') return;
        // A click can fail client-side required-field validation before checkout reaches PHP.
        // Ask PHP for the current state as well, without cancelling or replaying the submission.
        statusRequest = window.fetch(config.statusUrl, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (status) { if (status && status.enabled === true) openDialog(status.settings); })
            .catch(function () { /* Normal checkout and the server submission guard still apply. */ })
            .finally(function () { statusRequest = null; });
    }

    document.addEventListener('submit', function (event) {
        if (event.target.matches('form.checkout, form.wc-block-checkout__form')) checkMaintenanceStatus();
    }, true);

    document.addEventListener('click', function (event) {
        if (event.target.closest('form.checkout #place_order, .wc-block-components-checkout-place-order-button')) checkMaintenanceStatus();
        if (!event.target.closest('[data-oyiso-checkout-maintenance-preview]')) return;
        const settings = {};
        ['template', 'title', 'message', 'button'].forEach(function (field) {
            const input = document.querySelector('[name="' + config.settingsRoot + '[' + field + ']"]');
            settings[field] = input && input.value.trim() || config.defaults[field];
        });
        openDialog(settings);
    }, true);
}());
