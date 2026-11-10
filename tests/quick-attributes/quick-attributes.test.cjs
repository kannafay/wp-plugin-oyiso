const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const repo = path.resolve(__dirname, '../..');
const moduleRoot = path.join(repo, 'src/plugin-extensions/woocommerce-quick-attributes');
const source = fs.readFileSync(path.join(moduleRoot, 'assets/quick-attributes.js'), 'utf8');
const markup = fs.readFileSync(path.join(moduleRoot, 'index.php'), 'utf8').match(/<div id="oyiso-qa-modal"[\s\S]*?(?=\s*<\?php)/)[0];
const wpRoot = process.env.OYISO_WORDPRESS_ROOT || path.resolve(repo, '../../..');
const jquery = fs.readFileSync(path.join(wpRoot, 'wp-includes/js/jquery/jquery.js'), 'utf8');
const flavour = { id: 12, name: 'CHUPPA CHUPPS STRAWBERRY', new: false };
const added = terms => ({ success: true, data: { terms } });

function clock(window) {
    let now = 0;
    let sequence = 0;
    const timers = new Map();
    window.setTimeout = (callback, delay = 0) => {
        timers.set(++sequence, { callback, at: now + delay });
        return sequence;
    };
    window.clearTimeout = id => timers.delete(id);
    return {
        advance(ms) {
            const end = now + ms;
            while (true) {
                const next = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
                if (!next) break;
                const [id, timer] = next;
                timers.delete(id);
                now = timer.at;
                timer.callback();
            }
            now = end;
        },
    };
}

function fixture(t) {
    const dom = new JSDOM(`<button class="oyiso-qa-btn" data-taxonomy="pa_flavour" data-label="Flavour" data-idx="0">Flavour</button><button class="oyiso-qa-btn" data-taxonomy="pa_size" data-label="Size" data-idx="1">Size</button><select multiple class="attribute_values" data-taxonomy="pa_flavour"><option value="11" selected>Lemon</option></select><select multiple class="attribute_values" data-taxonomy="pa_size"></select>${markup}`, { url: 'https://vape.local/wp-admin/post.php', runScripts: 'outside-only' });
    const { window } = dom;
    const errors = [];
    window.addEventListener('error', event => errors.push(event.error));
    const timers = clock(window);
    window.eval(jquery);
    const $ = window.jQuery;
    const requests = [];
    $.ajaxTransport('+*', options => {
        const request = { options, aborted: false };
        requests.push(request);
        return {
            send(headers, complete) {
                request.raw = (text, status = 200) => complete(status, status === 200 ? 'OK' : 'Error', { text }, 'Content-Type: application/json\r\n');
                request.reply = (body, status = 200) => request.raw(JSON.stringify(body), status);
            },
            abort() { request.aborted = true; },
        };
    });
    window.oyisoQAConfig = { ajaxurl: 'https://vape.local/wp-admin/admin-ajax.php', nonce: 'fixture-nonce', action: 'add', previewAction: 'preview', getTermsAction: 'terms' };
    window.eval(source);
    t.after(() => { window.close(); assert.deepEqual(errors, []); });
    return {
        $, requests, timers,
        open(taxonomy = 'pa_flavour') { $(`.oyiso-qa-btn[data-taxonomy="${taxonomy}"]`).trigger('click'); },
        submit(value = flavour.name) { $('#oyiso-qa-modal-textarea').val(value); $('.oyiso-qa-modal-do').trigger('click'); },
    };
}

function assertIdle(f) {
    assert.equal(f.$('.oyiso-qa-modal-do').prop('disabled'), false);
    assert.equal(f.$('.oyiso-qa-modal-spinner').hasClass('is-active'), false);
    assert.equal(f.$('.oyiso-qa-tab:disabled').length, 0);
    assert.equal(f.$('#oyiso-qa-modal-textarea').prop('disabled'), false);
}

test('a normal reuse preserves selected terms, avoids duplicate submissions, and closes with idle controls', t => {
    const f = fixture(t);
    f.open(); f.submit(); f.$('.oyiso-qa-modal-do').trigger('click');
    assert.equal(f.requests.length, 1);
    assert.equal(new URLSearchParams(f.requests[0].options.data).get('taxonomy'), 'pa_flavour');
    f.requests[0].reply(added([flavour]));
    assert.deepEqual(Array.from(f.$('.attribute_values[data-taxonomy="pa_flavour"]').val()), ['11', '12']);
    assert.equal(f.$('#oyiso-qa-modal').hasClass('is-open'), false);
    assertIdle(f);
});

test('a stalled submission times out, restores controls, and can be retried successfully', t => {
    const f = fixture(t);
    f.open(); f.submit();
    assert.equal(f.$('.oyiso-qa-modal-do').prop('disabled'), true);
    assert.equal(f.$('.oyiso-qa-modal-spinner').hasClass('is-active'), true);
    f.timers.advance(30000);
    assertIdle(f);
    assert.equal(f.requests[0].aborted, true);
    assert.match(f.$('#oyiso-qa-modal-preview').text(), /超时/);
    f.submit(); f.requests[1].reply(added([flavour]));
    assertIdle(f);
    assert.equal(f.$('#oyiso-qa-modal').hasClass('is-open'), false);
});

test('HTTP failures and invalid responses leave the modal open with a visible error and usable controls', t => {
    const f = fixture(t);
    const failures = [
        request => request.reply({ success: false, data: { message: '权限不足' } }, 403),
        request => request.reply({ success: false, data: { message: '无法添加' } }),
        request => request.raw('<html>server error</html>', 500),
        request => request.raw('<html>invalid JSON</html>'),
        request => request.reply(null),
        request => request.reply(added(null)),
        request => request.reply(added([])),
        request => request.reply(added([null])),
    ];
    for (const fail of failures) {
        f.open(); f.submit();
        assert.doesNotThrow(() => fail(f.requests.at(-1)));
        assertIdle(f);
        assert.equal(f.$('#oyiso-qa-modal').hasClass('is-open'), true);
        assert.equal(f.$('#oyiso-qa-modal-preview').hasClass('oyiso-qa-preview-error'), true);
    }
    assert.equal(f.$.active, 0);
});

test('a failing change listener cannot strand a new-value submission or prevent a later retry', t => {
    const f = fixture(t);
    const select = f.$('.attribute_values[data-taxonomy="pa_flavour"]');
    select.on('change.fixture', () => { throw new Error('another plugin failed'); });
    f.open(); f.submit();
    assert.doesNotThrow(() => f.requests[0].reply(added([flavour])));
    assertIdle(f);
    assert.equal(f.$('#oyiso-qa-modal').hasClass('is-open'), true);
    assert.equal(f.$('#oyiso-qa-modal-preview').hasClass('oyiso-qa-preview-error'), true);
    assert.equal(f.$.active, 0);
    select.off('.fixture');
    f.submit(); f.requests[1].reply(added([flavour]));
    assert.equal(select.find('option[value="12"]').length, 1);
    assert.equal(f.$('#oyiso-qa-modal').hasClass('is-open'), false);
    assertIdle(f);
});

test('existing-value selection also recovers from a change listener exception without losing the checks', t => {
    const f = fixture(t);
    f.open(); f.$('.oyiso-qa-tab[data-tab="existing"]').trigger('click');
    f.requests[0].reply({ success: true, data: { terms: [flavour] } });
    f.$('.oyiso-qa-term-check').prop('checked', true);
    const select = f.$('.attribute_values[data-taxonomy="pa_flavour"]');
    select.on('change.fixture', () => { throw new Error('another plugin failed'); });
    assert.doesNotThrow(() => f.$('.oyiso-qa-modal-do').trigger('click'));
    assertIdle(f);
    assert.equal(f.$('#oyiso-qa-modal').hasClass('is-open'), true);
    assert.equal(f.$('.oyiso-qa-term-check:checked').length, 1);
    assert.match(f.$('.oyiso-qa-term-count').text(), /失败/);
    select.off('.fixture'); f.$('.oyiso-qa-modal-do').trigger('click');
    assert.equal(f.$('#oyiso-qa-modal').hasClass('is-open'), false);
    assertIdle(f);
});

test('synchronous AJAX setup errors restore the submission controls', t => {
    const f = fixture(t);
    f.$.ajax = () => { throw new Error('AJAX setup failed'); };
    f.open();
    assert.doesNotThrow(() => f.submit());
    assertIdle(f);
    assert.equal(f.$('#oyiso-qa-modal-preview').hasClass('oyiso-qa-preview-error'), true);
});

test('a global beforeSend cancellation cannot leave the submit button busy', t => {
    const f = fixture(t);
    f.$.ajaxPrefilter(options => { options.beforeSend = () => false; });
    f.open(); f.submit();
    assert.equal(f.requests.length, 0);
    assert.equal(f.$.active, 0);
    assertIdle(f);
    assert.equal(f.$('#oyiso-qa-modal-preview').hasClass('oyiso-qa-preview-error'), true);
});

test('cancelling and immediately reopening aborts the old request and isolates the new submission', t => {
    const f = fixture(t);
    f.open(); f.submit();
    const old = f.requests[0];
    f.$('.oyiso-qa-modal-cancel').trigger('click');
    assert.equal(old.aborted, true);
    assertIdle(f);
    f.open('pa_size'); f.submit('Large');
    // A late callback must not modify a newer modal session or release its busy state.
    old.options.success(added([flavour]));
    old.options.error({}, 'error');
    f.timers.advance(250);
    assert.equal(f.$('#oyiso-qa-modal').css('display'), 'flex');
    assert.equal(f.$('#oyiso-qa-modal').hasClass('is-open'), true);
    assert.equal(f.$('.oyiso-qa-modal-do').prop('disabled'), true);
    assert.deepEqual(Array.from(f.$('.attribute_values[data-taxonomy="pa_flavour"]').val()), ['11']);
    f.requests[1].reply(added([{ id: 30, name: 'Large' }]));
    assert.deepEqual(Array.from(f.$('.attribute_values[data-taxonomy="pa_size"]').val()), ['30']);
    assertIdle(f);
});

test('a submission cancels pending previews so late preview results cannot replace its failure message', t => {
    const f = fixture(t);
    f.open(); f.$('#oyiso-qa-modal-textarea').val(flavour.name).trigger('input'); f.timers.advance(200);
    const preview = f.requests[0];
    f.$('.oyiso-qa-modal-do').trigger('click');
    assert.equal(preview.aborted, true);
    f.requests[1].reply({ success: false, data: { message: '添加失败，请重试。' } });
    preview.options.success({ success: true, data: { total: 1, new_vals: [], existing: [flavour] } });
    assert.match(f.$('#oyiso-qa-modal-preview').text(), /添加失败/);
    assertIdle(f);
});

test('preview and existing-term requests time out with visible errors and no lingering loading indicator', t => {
    const f = fixture(t);
    f.open(); f.$('#oyiso-qa-modal-textarea').val(flavour.name).trigger('input'); f.timers.advance(200);
    f.timers.advance(30000);
    assert.equal(f.$('#oyiso-qa-modal-preview').hasClass('oyiso-qa-preview-loading'), false);
    assert.match(f.$('#oyiso-qa-modal-preview').text(), /超时/);
    f.$('.oyiso-qa-tab[data-tab="existing"]').trigger('click'); f.timers.advance(30000);
    assert.equal(f.$('.oyiso-qa-term-list .spinner').length, 0);
    assert.match(f.$('.oyiso-qa-term-list').text(), /超时/);
    assertIdle(f);
});

test('editing or closing cancels obsolete preview work before another attribute is opened', t => {
    const f = fixture(t);
    f.open(); f.$('#oyiso-qa-modal-textarea').val('Old').trigger('input'); f.timers.advance(200);
    const old = f.requests[0];
    f.$('#oyiso-qa-modal-textarea').val('New').trigger('input');
    assert.equal(old.aborted, true);
    f.$('.oyiso-qa-modal-cancel').trigger('click');
    f.open('pa_size'); f.timers.advance(250);
    assert.equal(f.requests.length, 1);
    old.options.success({ success: true, data: { total: 1, new_vals: ['Old'], existing: [] } });
    assert.match(f.$('#oyiso-qa-modal-preview').text(), /输入后/);
    assert.equal(f.$('#oyiso-qa-modal').css('display'), 'flex');
    assertIdle(f);
});

test('switching tabs aborts hidden requests and starts a fresh preview when returning', t => {
    const f = fixture(t);
    f.open(); f.$('#oyiso-qa-modal-textarea').val(flavour.name).trigger('input'); f.timers.advance(200);
    f.$('.oyiso-qa-tab[data-tab="existing"]').trigger('click');
    assert.equal(f.requests[0].aborted, true);
    f.$('.oyiso-qa-tab[data-tab="new"]').trigger('click');
    assert.equal(f.requests[1].aborted, true);
    assert.equal(f.requests.length, 3);
    f.requests[2].reply({ success: true, data: { total: 1, new_vals: [], existing: [flavour] } });
    assert.equal(f.$('#oyiso-qa-modal-preview').hasClass('oyiso-qa-preview-loading'), false);
    assert.match(f.$('#oyiso-qa-modal-preview').text(), /复用 1 个/);
    assertIdle(f);
});
