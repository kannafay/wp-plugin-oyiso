const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const moduleRoot = path.resolve(__dirname, '../../src/plugin-extensions/woocommerce-checkout-maintenance');
const script = fs.readFileSync(path.join(moduleRoot, 'assets/checkout-maintenance.js'), 'utf8');
const markup = fs.readFileSync(path.join(moduleRoot, 'index.php'), 'utf8').match(/<dialog[\s\S]*?<\/dialog>/)[0].replace(/<\?php[\s\S]*?\?>/g, 'Close');
const defaults = {
    template: 'default', title: 'Checkout is under maintenance',
    message: 'Checkout is currently under maintenance. Please try placing your order again later.', button: 'Got it',
};

function setup(noticeSettings) {
    const dom = new JSDOM('<button id="place-order">Place order</button><button data-oyiso-checkout-maintenance-preview>Preview</button>' + markup, { runScripts: 'outside-only' });
    const { window } = dom;
    const { document } = window;
    const dialog = document.querySelector('dialog');
    dialog.showModal = function () { this.setAttribute('open', ''); };
    dialog.close = function () { this.removeAttribute('open'); this.dispatchEvent(new window.Event('close')); };
    window.oyisoCheckoutMaintenance = { defaults, settings: defaults, settingsRoot: 'oyiso[oyiso_wc_checkout_maintenance_options]' };
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
        assert.equal(page.dialog.querySelector('p').textContent, error.message);
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

test('preview uses unsaved field values, with English defaults for empty fields', () => {
    const page = setup();
    try {
        for (const [key, value] of Object.entries({ title: 'Custom title', message: 'First line\nSecond line', button: '', template: 'default' })) {
            const input = page.document.createElement('textarea');
            input.name = `oyiso[oyiso_wc_checkout_maintenance_options][${key}]`;
            input.value = value;
            page.document.body.appendChild(input);
        }
        page.document.querySelector('[data-oyiso-checkout-maintenance-preview]').click();
        assert.equal(page.dialog.open, true);
        assert.equal(page.dialog.querySelector('h2').textContent, 'Custom title');
        assert.equal(page.dialog.querySelector('p').textContent, 'First line\nSecond line');
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
            assert.equal(page.dialog.querySelector('p').textContent, 'Confirmed by PHP.');
        } finally { page.dom.window.close(); }
    }
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
