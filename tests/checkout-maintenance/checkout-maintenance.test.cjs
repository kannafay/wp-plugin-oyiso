const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const moduleRoot = path.resolve(__dirname, '../../src/plugin-extensions/woocommerce-checkout-maintenance');
const script = fs.readFileSync(path.join(moduleRoot, 'assets/checkout-maintenance.js'), 'utf8');
const markup = fs.readFileSync(path.join(moduleRoot, 'index.php'), 'utf8').match(/<dialog[\s\S]*?<\/dialog>/)[0].replace(/<\?php[\s\S]*?\?>/g, 'Close');
const defaults = {
    template: 'default', accent: '#a36616', title: 'Checkout is under maintenance',
    message: 'Checkout is currently under maintenance. Please try placing your order again later.', button: 'Got it',
};
const templates = ['default', 'minimal', 'soft', 'dark'];

function setup(noticeSettings, colorControls = false) {
    const colorMarkup = colorControls ? '<input type="color" name="oyiso[oyiso_wc_checkout_maintenance_options][accent]" value="#a36616" data-oyiso-maintenance-accent><input type="text" value="#a36616" data-oyiso-maintenance-accent-hex><button type="button" data-oyiso-maintenance-color="#2563eb">Blue</button>' : '';
    const dom = new JSDOM('<button id="place-order">Place order</button><button data-oyiso-checkout-maintenance-preview>Preview</button>' + colorMarkup + markup, { runScripts: 'outside-only' });
    const { window } = dom;
    const { document } = window;
    const dialog = document.querySelector('dialog');
    dialog.showModal = function () { this.setAttribute('open', ''); };
    dialog.close = function () { this.removeAttribute('open'); this.dispatchEvent(new window.Event('close')); };
    window.oyisoCheckoutMaintenance = { defaults, templates, settings: defaults, settingsRoot: 'oyiso[oyiso_wc_checkout_maintenance_options]', previewUrl: '/preview', nonce: 'preview-nonce' };
    window.fetch = async (url, options) => ({ ok: true, json: async () => JSON.parse(options.body) });
    let checkoutError;
    let middleware;
    window.jQuery = () => ({ on: (event, callback) => { checkoutError = callback; } });
    window.wp = { apiFetch: { use: callback => { middleware = callback; } } };
    function addNotice(settings) {
        const notice = document.createElement('span');
        notice.dataset.oyisoCheckoutMaintenance = JSON.stringify(settings);
        document.body.appendChild(notice);
    }
    if (noticeSettings) addNotice(noticeSettings);
    window.eval(script);
    return { dom, window, document, dialog, addNotice, checkoutError, middleware };
}

function maintenanceError(settings = defaults) {
    return { code: 'oyiso_checkout_maintenance', message: settings.message, data: { status: 503, oyiso_checkout_maintenance: settings } };
}

async function preview(page) {
    page.document.querySelector('[data-oyiso-checkout-maintenance-preview]').click();
    await new Promise(resolve => setImmediate(resolve));
}

test('checkout clicks are left to WooCommerce; only a server maintenance response opens the dialog', () => {
    const page = setup();
    try {
        const event = new page.window.MouseEvent('click', { bubbles: true, cancelable: true });
        page.document.querySelector('#place-order').dispatchEvent(event);
        assert.equal(event.defaultPrevented, false);
        assert.equal(page.dialog.open, false);
        page.checkoutError();
        assert.equal(page.dialog.open, false);
    } finally { page.dom.window.close(); }
});

test('classic AJAX errors display the current PHP settings as text and restore focus after closing', () => {
    const page = setup();
    try {
        const trigger = page.document.querySelector('#place-order');
        trigger.focus();
        const custom = { ...defaults, title: '<img src=x onerror="alert(1)">', message: 'Line one\nZażółć gęślą jaźń & "quotes"', button: 'Understood' };
        page.addNotice(custom);
        page.checkoutError();
        assert.equal(page.dialog.open, true);
        assert.equal(page.document.querySelector('.oyiso-checkout-maintenance-dialog__title').textContent, custom.title);
        assert.equal(page.dialog.querySelector('img'), null);
        assert.equal(page.document.querySelector('.oyiso-checkout-maintenance-dialog__message').textContent, custom.message);
        assert.equal(page.document.activeElement, page.dialog.querySelector('.oyiso-checkout-maintenance-dialog__button'));
        page.dialog.querySelector('.oyiso-checkout-maintenance-dialog__button').click();
        assert.equal(page.dialog.open, false);
        assert.equal(page.document.documentElement.classList.contains('oyiso-checkout-maintenance-open'), false);
        assert.equal(page.document.activeElement, trigger);
    } finally { page.dom.window.close(); }
});

test('a full-page classic POST notice also opens the dialog on load', () => {
    const page = setup({ ...defaults, message: 'Full page checkout is paused.' });
    try { assert.equal(page.dialog.open, true); } finally { page.dom.window.close(); }
});

test('the selected template is applied to both classic notices and Store API errors', async () => {
    for (const template of templates) {
        const settings = { ...defaults, template, accent: '#0f766e', messageHtml: '<p><strong>Prepared HTML</strong></p>' };
        const page = setup(settings);
        try {
            assert.equal(page.dialog.dataset.template, template);
            assert.equal(page.dialog.style.getPropertyValue('--oyiso-maintenance-accent'), '#0f766e');
            assert.equal(page.dialog.querySelector('strong').textContent, 'Prepared HTML');
            page.dialog.close();
            const error = maintenanceError(settings);
            await assert.rejects(page.middleware({}, async () => { throw error; }), result => result === error);
            assert.equal(page.dialog.dataset.template, template);
            assert.equal(page.dialog.open, true);
            assert.equal(page.dialog.querySelector('h2').textContent, defaults.title);
            assert.equal(page.dialog.style.getPropertyValue('--oyiso-maintenance-accent'), '#0f766e');
            assert.equal(page.dialog.querySelector('strong').textContent, 'Prepared HTML');
        } finally { page.dom.window.close(); }
    }
});

test('preview switches between unsaved template choices and falls back for unknown values', async () => {
    const page = setup();
    try {
        const select = page.document.createElement('select');
        select.name = 'oyiso[oyiso_wc_checkout_maintenance_options][template]';
        for (const template of [...templates, 'unknown']) {
            const option = page.document.createElement('option');
            option.value = template;
            option.textContent = template;
            select.appendChild(option);
        }
        page.document.body.appendChild(select);
        for (const template of [...templates, 'unknown']) {
            select.value = template;
            await preview(page);
            assert.equal(page.dialog.dataset.template, templates.includes(template) ? template : 'default');
            assert.equal(page.dialog.querySelector('.oyiso-checkout-maintenance-dialog__message').textContent, defaults.message);
            page.dialog.close();
        }
    } finally { page.dom.window.close(); }
});

test('Store API rejection displays new server copy and preserves the exact error and request', async () => {
    const page = setup();
    try {
        const error = maintenanceError({ ...defaults, title: 'Updated on server', message: 'Please return tomorrow.' });
        const options = { path: '/wc/store/v1/checkout', method: 'POST' };
        let calls = 0;
        await assert.rejects(page.middleware(options, async request => {
            ++calls;
            assert.equal(request, options);
            throw error;
        }), result => result === error);
        assert.equal(calls, 1);
        assert.equal(page.dialog.open, true);
        assert.equal(page.dialog.querySelector('h2').textContent, error.data.oyiso_checkout_maintenance.title);
        assert.equal(page.dialog.querySelector('.oyiso-checkout-maintenance-dialog__message').textContent, error.message);
    } finally { page.dom.window.close(); }
});

test('Blocks raw HTTP responses are inspected through a clone while the original body stays readable', async () => {
    const page = setup();
    try {
        const error = maintenanceError();
        const raw = new Response(JSON.stringify(error), { status: 503, headers: { Nonce: 'keep-this-nonce' } });
        const result = await page.middleware({ path: '/wc/store/v1/checkout', method: 'POST', parse: false }, async () => raw);
        assert.equal(result, raw);
        assert.equal(raw.bodyUsed, false);
        assert.equal(raw.headers.get('Nonce'), 'keep-this-nonce');
        assert.equal(page.dialog.open, true);
        assert.deepEqual(await raw.json(), error);
    } finally { page.dom.window.close(); }
});

test('a rejected raw HTTP response is preserved for the WooCommerce error handler', async () => {
    const page = setup();
    try {
        const raw = new Response(JSON.stringify(maintenanceError()), { status: 503 });
        await assert.rejects(page.middleware({ path: '/wc/store/v1/checkout', parse: false }, async () => { throw raw; }), error => error === raw);
        assert.equal(raw.bodyUsed, false);
        assert.equal(page.dialog.open, true);
    } finally { page.dom.window.close(); }
});

test('unrelated errors and successful API responses retain normal behavior', async () => {
    const page = setup();
    try {
        const error = { code: 'invalid_address', message: 'Check the address.' };
        await assert.rejects(page.middleware({}, async () => { throw error; }), result => result === error);
        assert.equal(page.dialog.open, false);
        const response = { order_id: 42 };
        assert.equal(await page.middleware({}, async () => response), response);
        assert.equal(page.document.documentElement.classList.contains('oyiso-checkout-maintenance-open'), false);
        assert.equal(page.dialog.open, false);
        const unreadable = new Response('Not JSON', { status: 500 });
        assert.equal(await page.middleware({ parse: false }, async () => unreadable), unreadable);
        assert.equal(unreadable.bodyUsed, false);
        assert.equal(page.dialog.open, false);
    } finally { page.dom.window.close(); }
});

test('both parsed and raw batch responses can show a maintenance error', async () => {
    for (const raw of [false, true]) {
        const page = setup();
        try {
            const body = { responses: [{ body: maintenanceError() }] };
            const response = raw ? new Response(JSON.stringify(body)) : body;
            assert.equal(await page.middleware({ path: '/wc/store/v1/batch' }, async () => response), response);
            assert.equal(page.dialog.open, true);
            if (raw) assert.equal(response.bodyUsed, false);
        } finally { page.dom.window.close(); }
    }
});

test('preview uses unsaved field values, with English defaults for empty fields', async () => {
    const page = setup();
    try {
        for (const [key, value] of Object.entries({ title: 'Custom title', message: 'First line\nSecond line', button: '', template: 'default' })) {
            const input = page.document.createElement('textarea');
            input.name = `oyiso[oyiso_wc_checkout_maintenance_options][${key}]`;
            input.value = value;
            page.document.body.appendChild(input);
        }
        await preview(page);
        assert.equal(page.dialog.open, true);
        assert.equal(page.dialog.querySelector('h2').textContent, 'Custom title');
        assert.equal(page.dialog.querySelector('.oyiso-checkout-maintenance-dialog__message').textContent, 'First line\nSecond line');
        assert.equal(page.dialog.querySelector('.oyiso-checkout-maintenance-dialog__button').textContent, 'Got it');
    } finally { page.dom.window.close(); }
});

test('an incomplete checkout can show PHP maintenance status without cancelling submission', async () => {
    for (const blocks of [false, true]) {
        const page = setup();
        try {
            page.window.oyisoCheckoutMaintenance.statusUrl = '/wp-json/oyiso/v1/checkout-maintenance';
            let calls = 0;
            page.window.fetch = async (url, options) => {
                ++calls;
                assert.equal(url, page.window.oyisoCheckoutMaintenance.statusUrl);
                assert.equal(options.cache, 'no-store');
                return { ok: true, json: async () => ({ enabled: true, settings: { ...defaults, message: 'Confirmed by PHP.' } }) };
            };
            const form = page.document.createElement('form');
            form.className = blocks ? 'wc-block-checkout__form' : 'checkout';
            const orderButton = page.document.createElement('button');
            orderButton.type = 'button';
            if (blocks) orderButton.className = 'wc-block-components-checkout-place-order-button';
            else orderButton.id = 'place_order';
            form.appendChild(orderButton);
            page.document.body.appendChild(form);
            const event = new page.window.MouseEvent('click', { bubbles: true, cancelable: true });
            orderButton.dispatchEvent(event);
            form.dispatchEvent(new page.window.Event('submit', { bubbles: true, cancelable: true }));
            await new Promise(resolve => setImmediate(resolve));
            assert.equal(event.defaultPrevented, false);
            assert.equal(calls, 1, 'A click followed by submit must reuse the in-flight status request.');
            assert.equal(page.dialog.open, true);
            assert.equal(page.dialog.querySelector('.oyiso-checkout-maintenance-dialog__message').textContent, 'Confirmed by PHP.');
        } finally { page.dom.window.close(); }
    }
});

test('PHP-prepared HTML is displayed for classic and Blocks without being overwritten by the plain API error', async () => {
    const settings = { ...defaults, message: '<p><strong>Back soon.</strong></p><p><a href="https://example.com/help">Contact us</a></p>', messageHtml: '<p><strong>Back soon.</strong></p><p><a href="https://example.com/help">Contact us</a></p>' };
    const page = setup(settings);
    try {
        assert.equal(page.dialog.querySelector('strong').textContent, 'Back soon.');
        assert.equal(page.dialog.querySelector('a').href, 'https://example.com/help');
        page.dialog.close();
        const error = maintenanceError(settings);
        error.message = 'Back soon.\nContact us';
        await assert.rejects(page.middleware({}, async () => { throw error; }), result => result === error);
        assert.equal(page.dialog.querySelector('strong').textContent, 'Back soon.');
        assert.equal(page.dialog.querySelectorAll('.oyiso-checkout-maintenance-dialog__message > p').length, 2);
    } finally { page.dom.window.close(); }
});

test('plain legacy errors cannot reuse stale HTML from the page', async () => {
    const page = setup();
    try {
        page.window.oyisoCheckoutMaintenance.settings = { ...defaults, messageHtml: '<p>Stale HTML</p>' };
        const error = { code: 'oyiso_checkout_maintenance', message: 'Current plain message' };
        await assert.rejects(page.middleware({}, async () => { throw error; }), result => result === error);
        assert.equal(page.dialog.querySelector('.oyiso-checkout-maintenance-dialog__message').textContent, error.message);
        assert.equal(page.dialog.querySelector('p'), null);
    } finally { page.dom.window.close(); }
});

test('HTML preview waits for PHP and reads the active visual editor, or the code textarea when hidden', async () => {
    const page = setup();
    try {
        const input = page.document.createElement('textarea');
        input.name = 'oyiso[oyiso_wc_checkout_maintenance_options][message]';
        input.id = 'customer-message';
        input.value = '<p>Unsaved code HTML</p>';
        page.document.body.appendChild(input);
        let hidden = false;
        let finishResponse;
        page.window.tinymce = { get: id => {
            assert.equal(id, input.id);
            return { isHidden: () => hidden, getContent: () => '<p><strong>Unsaved visual HTML</strong></p>' };
        } };
        page.window.fetch = async (url, options) => {
            assert.equal(url, '/preview');
            assert.equal(options.method, 'POST');
            assert.equal(options.headers['X-WP-Nonce'], 'preview-nonce');
            const settings = JSON.parse(options.body);
            assert.equal(settings.message, hidden ? input.value : '<p><strong>Unsaved visual HTML</strong></p>');
            await new Promise(resolve => { finishResponse = resolve; });
            return { ok: true, json: async () => ({ ...settings, messageHtml: '<p><em>PHP-filtered preview</em></p>' }) };
        };
        const trigger = page.document.querySelector('[data-oyiso-checkout-maintenance-preview]');
        for (hidden of [false, true]) {
            trigger.click();
            assert.equal(page.dialog.open, false, 'Raw editor HTML must not be rendered before PHP answers.');
            assert.equal(trigger.disabled, true);
            finishResponse();
            await new Promise(resolve => setImmediate(resolve));
            assert.equal(trigger.disabled, false);
            assert.equal(page.dialog.querySelector('em').textContent, 'PHP-filtered preview');
            page.dialog.close();
            assert.equal(page.document.activeElement, trigger);
        }
    } finally { page.dom.window.close(); }
});

test('failed authenticated previews restore the button and never insert raw editor HTML', async () => {
    const page = setup();
    try {
        const notice = page.document.createElement('p');
        notice.dataset.oyisoMaintenancePreviewError = '';
        notice.hidden = true;
        page.document.body.appendChild(notice);
        page.window.fetch = async () => ({ ok: false, status: 403 });
        await preview(page);
        assert.equal(page.dialog.open, false);
        assert.equal(notice.hidden, false);
        assert.equal(page.document.querySelector('[data-oyiso-checkout-maintenance-preview]').disabled, false);
    } finally { page.dom.window.close(); }
});

test('single accent colors normalize safely and choose readable button text at both extremes', () => {
    for (const [value, expected, text] of [
        ['#FFF', '#ffffff', '#000000'], ['#000000', '#000000', '#ffffff'],
        [' #2563EB ', '#2563eb', '#ffffff'], ['#ffef00', '#ffef00', '#000000'],
        ['red; color: red', defaults.accent, '#ffffff'], [null, defaults.accent, '#ffffff'],
    ]) {
        const page = setup({ ...defaults, accent: value });
        try {
            assert.equal(page.dialog.style.getPropertyValue('--oyiso-maintenance-accent'), expected);
            assert.equal(page.dialog.style.getPropertyValue('--oyiso-maintenance-on-accent'), text);
            assert.match(page.dialog.style.getPropertyValue('--oyiso-maintenance-accent-hover'), /^#[\da-f]{6}$/);
        } finally { page.dom.window.close(); }
    }
});

test('native color input, hex text and presets keep one saved value and preview in sync', async () => {
    const page = setup(undefined, true);
    try {
        const native = page.document.querySelector('[data-oyiso-maintenance-accent]');
        const hex = page.document.querySelector('[data-oyiso-maintenance-accent-hex]');
        const blue = page.document.querySelector('[data-oyiso-maintenance-color]');
        let changes = 0;
        native.addEventListener('change', () => { ++changes; });
        assert.equal(native.type, 'color');
        blue.click();
        assert.equal(native.value, '#2563eb');
        assert.equal(hex.value, '#2563eb');
        assert.equal(blue.getAttribute('aria-pressed'), 'true');
        assert.equal(changes, 1);
        native.value = '#0f766e';
        native.dispatchEvent(new page.window.Event('input', { bubbles: true }));
        assert.equal(hex.value, '#0f766e');
        assert.equal(blue.getAttribute('aria-pressed'), 'false');
        hex.value = '#ABC';
        hex.dispatchEvent(new page.window.Event('change', { bubbles: true }));
        assert.equal(native.value, '#aabbcc');
        assert.equal(hex.value, '#aabbcc');
        await preview(page);
        assert.equal(page.dialog.style.getPropertyValue('--oyiso-maintenance-accent'), '#aabbcc');
        page.dialog.close();
        hex.value = 'invalid';
        hex.dispatchEvent(new page.window.Event('change', { bubbles: true }));
        assert.equal(native.value, defaults.accent);
        assert.equal(hex.value, defaults.accent);
        assert.equal(page.document.querySelectorAll('[name="oyiso[oyiso_wc_checkout_maintenance_options][accent]"]').length, 1);
    } finally { page.dom.window.close(); }
});

test('dialog rendering failures cannot replace a Blocks response or error', async () => {
    const page = setup();
    try {
        page.dialog.showModal = () => { throw new Error('Another dialog implementation failed.'); };
        const response = maintenanceError();
        assert.equal(await page.middleware({}, async () => response), response);
        await assert.rejects(page.middleware({}, async () => { throw response; }), error => error === response);
    } finally { page.dom.window.close(); }
});

test('disabled maintenance and a failed status lookup do not interrupt normal checkout', async () => {
    for (const fail of [false, true]) {
        const page = setup();
        try {
            page.window.oyisoCheckoutMaintenance.statusUrl = '/wp-json/oyiso/v1/checkout-maintenance';
            page.window.fetch = async () => {
                if (fail) throw new Error('Network unavailable');
                return { ok: true, json: async () => ({ enabled: false }) };
            };
            const button = page.document.createElement('button');
            button.className = 'wc-block-components-checkout-place-order-button';
            page.document.body.appendChild(button);
            button.click();
            await new Promise(resolve => setImmediate(resolve));
            assert.equal(page.dialog.open, false);
        } finally { page.dom.window.close(); }
    }
});
