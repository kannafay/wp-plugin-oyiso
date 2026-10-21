(function () {
    'use strict';

    const config = window.oyisoCheckoutMaintenance;
    if (!config) return;

    const dialog = document.getElementById('oyiso-checkout-maintenance-dialog');
    if (!dialog) return;

    const title = dialog.querySelector('.oyiso-checkout-maintenance-dialog__title');
    const message = dialog.querySelector('.oyiso-checkout-maintenance-dialog__message');
    const button = dialog.querySelector('.oyiso-checkout-maintenance-dialog__button');
    const templates = Array.isArray(config.templates) ? config.templates : ['default'];
    let previousFocus = null;

    function normalizeColor(value) {
        if (typeof value !== 'string' || !/^#(?:[\da-f]{3}|[\da-f]{6})$/i.test(value.trim())) return config.defaults.accent;
        const hex = value.trim().toLowerCase();
        return hex.length === 4 ? '#' + hex.slice(1).split('').map(function (digit) { return digit + digit; }).join('') : hex;
    }

    function contrastingText(hex) {
        const channels = hex.slice(1).match(/../g).map(function (channel) {
            const value = parseInt(channel, 16) / 255;
            return value <= 0.04045 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
        });
        const luminance = channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
        return (luminance + 0.05) / 0.05 > 1.05 / (luminance + 0.05) ? '#000000' : '#ffffff';
    }

    function applyColor(value) {
        const accent = normalizeColor(value);
        const hover = '#' + accent.slice(1).match(/../g).map(function (channel) {
            return Math.round(parseInt(channel, 16) * 0.9).toString(16).padStart(2, '0');
        }).join('');
        dialog.style.setProperty('--oyiso-maintenance-accent', accent);
        dialog.style.setProperty('--oyiso-maintenance-on-accent', contrastingText(accent));
        dialog.style.setProperty('--oyiso-maintenance-accent-hover', hover);
        dialog.style.setProperty('--oyiso-maintenance-on-accent-hover', contrastingText(hover));
    }

    function openDialog(settings, focusOrigin) {
        const content = Object.assign({}, config.settings, settings);
        // Legacy/plain error payloads must not reuse HTML from the page's older settings.
        if (settings && Object.prototype.hasOwnProperty.call(settings, 'message') && !Object.prototype.hasOwnProperty.call(settings, 'messageHtml')) delete content.messageHtml;
        title.textContent = content.title || config.defaults.title;
        if (typeof content.messageHtml === 'string' && content.messageHtml.trim()) {
            // Only PHP-prepared payloads reach here, including unsaved administrator previews.
            message.innerHTML = content.messageHtml;
            message.dataset.format = 'html';
        } else {
            message.textContent = content.message || config.defaults.message;
            message.dataset.format = 'text';
        }
        button.textContent = content.button || config.defaults.button;
        dialog.dataset.template = templates.includes(content.template) ? content.template : 'default';
        applyColor(content.accent);
        if (dialog.open) return;

        previousFocus = focusOrigin || document.activeElement;
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
        document.documentElement.classList.add('oyiso-checkout-maintenance-open');
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
            const settings = error.data && error.data.oyiso_checkout_maintenance;
            openDialog(settings || { message: error.message });
        }
    }

    function showStoreApiResponse(response) {
        showStoreApiError(response);
        if (response && Array.isArray(response.responses)) {
            response.responses.forEach(function (item) { showStoreApiError(item.body); });
        }
    }

    async function observeStoreApiResponse(response, options) {
        try {
            if (response && typeof response.clone === 'function') {
                // Blocks requests a raw Response to read nonce headers. Read a clone without consuming its body.
                if (!response.ok || /\/batch(?:[/?]|$)/.test(options.path || options.url || '')) showStoreApiResponse(await response.clone().json());
            } else showStoreApiResponse(response);
        } catch (error) { /* A preview/DOM failure must never replace WooCommerce's original response. */ }
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

    function readSettings() {
        const settings = {};
        ['template', 'accent', 'title', 'message', 'button'].forEach(function (field) {
            const input = document.querySelector('[name="' + config.settingsRoot + '[' + field + ']"]');
            const editor = field === 'message' && input && window.tinymce && window.tinymce.get(input.id);
            const value = editor && !editor.isHidden() ? editor.getContent() : input && input.value;
            settings[field] = value && value.trim() || config.defaults[field];
        });
        return settings;
    }

    async function previewDialog(trigger) {
        if (trigger.disabled || !config.previewUrl) return;
        const error = document.querySelector('[data-oyiso-maintenance-preview-error]');
        if (error) error.hidden = true;
        trigger.disabled = true;
        trigger.setAttribute('aria-busy', 'true');
        try {
            const response = await window.fetch(config.previewUrl, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                body: JSON.stringify(readSettings()),
            });
            if (!response.ok) throw new Error('Preview unavailable');
            openDialog(await response.json(), trigger);
        } catch (failure) {
            if (error) error.hidden = false;
        } finally {
            trigger.disabled = false;
            trigger.removeAttribute('aria-busy');
        }
    }

    const accentInput = document.querySelector('[data-oyiso-maintenance-accent]');
    const accentHex = document.querySelector('[data-oyiso-maintenance-accent-hex]');
    if (accentInput && accentHex) {
        function syncAccent(value, notify) {
            const color = normalizeColor(value);
            accentInput.value = color;
            accentHex.value = color;
            document.querySelectorAll('[data-oyiso-maintenance-color]').forEach(function (preset) {
                preset.setAttribute('aria-pressed', String(preset.dataset.oyisoMaintenanceColor === color));
            });
            if (notify) accentInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        syncAccent(accentInput.value, false);
        accentInput.addEventListener('input', function () { syncAccent(accentInput.value, false); });
        accentHex.addEventListener('input', function () {
            if (/^#(?:[\da-f]{3}|[\da-f]{6})$/i.test(accentHex.value.trim())) {
                // Keep partially typed short colors intact until the field loses focus.
                const color = normalizeColor(accentHex.value);
                accentInput.value = color;
                accentInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        accentHex.addEventListener('change', function () { syncAccent(accentHex.value, true); });
        document.addEventListener('click', function (event) {
            const preset = event.target.closest('[data-oyiso-maintenance-color]');
            if (preset) syncAccent(preset.dataset.oyisoMaintenanceColor, true);
        });
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('form.checkout #place_order, .wc-block-components-checkout-place-order-button')) checkMaintenanceStatus();
        const preview = event.target.closest('[data-oyiso-checkout-maintenance-preview]');
        if (preview) previewDialog(preview);
    }, true);
}());
