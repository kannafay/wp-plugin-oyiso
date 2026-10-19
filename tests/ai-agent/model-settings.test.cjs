const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
const repo = path.resolve(__dirname, '../..');
const quote = value => `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
const markup = execFileSync('php', ['-r', `define('ABSPATH', '/'); function wp_json_encode($v){return json_encode($v);} function esc_attr($v){return htmlspecialchars($v, ENT_QUOTES);} function esc_html($v){return esc_attr($v);} function esc_url($v){return esc_attr($v);} function admin_url($v){return 'https://vape.local/wp-admin/'.$v;} function get_option($name,$default=[]){return $name==='oyiso_agent_connections'?[]:($name==='oyiso'?['agent_selection'=>'']:$default);} require ${quote(path.join(repo, 'src/ai-agent/config.php'))}; require ${quote(path.join(repo, 'src/ai-agent/connections.php'))}; require ${quote(path.join(repo, 'src/ai-agent/managed-connections.php'))}; require ${quote(path.join(repo, 'src/ai-agent/model-settings.php'))}; Oyiso_Agent_Model_Settings::selection(); Oyiso_Agent_Model_Settings::render();`], { encoding: 'utf8' });
const script = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/model-settings.js'), 'utf8');
const settle = async () => { for (let i = 0; i < 6; i++) await new Promise(resolve => setImmediate(resolve)); };
const row = (id = 'first', extra = {}) => ({ id, name: 'DeepSeek 主账号', preset: 'deepseek', protocol: 'openai_chat', url: 'https://api.example.com/v1', models: ['model-a', 'model-b'], configured: true, ...extra });
function fixture(t, rows = [], router = () => ({ connections: rows }), { reducedMotion = true } = {}) {
    const dom = new JSDOM(`<form class="csf-content">${markup}</form>`, { url: 'https://vape.local/wp-admin/plugins.php?page=oyiso', runScripts: 'outside-only' });
    const { window } = dom; const document = window.document; const root = document.querySelector('[data-agent-settings]');
    const state = JSON.parse(root.dataset.state); state.connections = rows; root.dataset.state = JSON.stringify(state);
    const errors = []; const calls = [];
    window.addEventListener('error', event => errors.push(event.error));
    window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    window.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new window.Event('close')); };
    window.confirm = () => true;
    window.matchMedia = () => ({ matches: reducedMotion });
    window.oyisoModelSettings = { url: 'https://vape.local/wp-json/oyiso-agent/v1/', nonce: 'fixture-nonce' };
    window.fetch = async (url, options) => { const route = url.replace(window.oyisoModelSettings.url, ''); calls.push({ route, options }); const result = await router(route, options); return { ok: result.ok !== false, status: result.status || 200, json: async () => result.body || result }; };
    window.eval(script);
    t.after(() => { window.close(); assert.deepEqual(errors, []); });
    const $ = selector => document.querySelector(selector);
    return { window, $, calls, field: name => $(`[data-provider-field="${name}"]`) };
}
function transitionEnd(f, target = f.$('dialog'), propertyName = 'transform') {
    const event = new f.window.Event('transitionend', { bubbles: true });
    Object.defineProperty(event, 'propertyName', { value: propertyName });
    target.dispatchEvent(event);
}

test('modal closes after its own exit transition, clears the key immediately and restores focus', t => {
    const f = fixture(t, [], undefined, { reducedMotion: false });
    const dialog = f.$('dialog'); const add = f.$('[data-provider-add]');
    add.click();
    assert.equal(dialog.open, true); assert.equal(dialog.classList.contains('is-open'), true);
    f.field('key').value = 'fixture-animation-key';
    f.$('[data-provider-close]').click();
    assert.equal(dialog.open, true); assert.equal(dialog.classList.contains('is-open'), false);
    assert.equal(dialog.inert, true); assert.equal(f.field('key').value, '');
    // Ignore transitions from the contents or the faster backdrop fade.
    transitionEnd(f, f.field('models')); transitionEnd(f, dialog, 'opacity');
    assert.equal(dialog.open, true);
    f.$('[data-provider-save]').click(); assert.equal(f.calls.length, 0);
    transitionEnd(f);
    assert.equal(dialog.open, false); assert.equal(dialog.inert, false);
    assert.equal(dialog.classList.contains('is-closing'), false);
    assert.equal(f.window.document.activeElement, add);
});

test('Escape and the footer cancel button share the animated close path', t => {
    const f = fixture(t, [], undefined, { reducedMotion: false });
    for (const trigger of [
        () => { const event = new f.window.Event('cancel', { cancelable: true }); f.$('dialog').dispatchEvent(event); assert.equal(event.defaultPrevented, true); },
        () => f.$('.oyiso-agent-provider-dialog-footer [data-provider-close]').click(),
    ]) {
        f.$('[data-provider-add]').click(); trigger();
        assert.equal(f.$('dialog').open, true); assert.equal(f.$('dialog').classList.contains('is-closing'), true);
        transitionEnd(f); assert.equal(f.$('dialog').open, false);
    }
});

test('successful saves animate the exit and pending requests prevent cancellation', async t => {
    let finish;
    const f = fixture(t, [row()], () => new Promise(resolve => { finish = resolve; }), { reducedMotion: false });
    f.$('[data-provider-rows] .oyiso-agent-provider-actions button:nth-child(2)').click();
    f.field('key').value = 'fixture-save-key'; f.$('[data-provider-save]').click();
    f.$('[data-provider-close]').click();
    f.$('dialog').dispatchEvent(new f.window.Event('cancel', { cancelable: true }));
    assert.equal(f.$('dialog').classList.contains('is-open'), true);
    assert.equal(f.field('key').value, 'fixture-save-key');
    finish({ connections: [row()] }); await settle();
    assert.equal(f.$('dialog').open, true); assert.equal(f.$('dialog').classList.contains('is-closing'), true);
    assert.equal(f.field('key').value, '');
    transitionEnd(f); assert.equal(f.$('dialog').open, false);
    // Rendering updated rows detaches the old edit button.
    assert.equal(f.window.document.activeElement, f.$('[data-provider-add]'));
});

test('closing has a timeout fallback and reopening cancels stale cleanup', async t => {
    const f = fixture(t, [], undefined, { reducedMotion: false });
    f.$('[data-provider-add]').click(); f.$('[data-provider-close]').click();
    await new Promise(resolve => setTimeout(resolve, 280));
    assert.equal(f.$('dialog').open, false);
    f.$('[data-provider-add]').click(); f.$('[data-provider-close]').click();
    f.$('[data-provider-add]').click(); f.field('key').value = 'fixture-reopened-key';
    transitionEnd(f);
    await new Promise(resolve => setTimeout(resolve, 280));
    assert.equal(f.$('dialog').open, true); assert.equal(f.$('dialog').inert, false);
    assert.equal(f.$('dialog').classList.contains('is-open'), true);
    assert.equal(f.field('key').value, 'fixture-reopened-key');
});

test('reduced motion closes immediately without waiting for a transition', t => {
    const f = fixture(t);
    f.$('[data-provider-add]').click(); f.$('[data-provider-close]').click();
    assert.equal(f.$('dialog').open, false); assert.equal(f.$('dialog').classList.contains('is-closing'), false);
    f.$('[data-provider-add]').click(); assert.equal(f.$('dialog').open, true);
});
test('provider presets prefill independent forms and cancel clears credentials without dirtying global settings', t => {
    const f = fixture(t); const document = f.window.document;
    let changed = 0; document.querySelector('form').addEventListener('change', () => changed++);
    assert.match(f.$('[data-provider-rows]').textContent, /添加提供商/);
    f.$('[data-provider-add]').click();
    assert.equal(f.$('dialog').open, true);
    assert.equal(f.field('name').value, 'DeepSeek');
    assert.equal(f.field('url').value, 'https://api.deepseek.com');
    f.field('preset').value = 'gemini'; f.field('preset').dispatchEvent(new f.window.Event('change', { bubbles: true }));
    assert.equal(f.field('name').value, 'Gemini');
    assert.match(f.field('url').value, /generativelanguage/);
    assert.equal(changed, 0);
    f.field('key').value = 'fixture-key'; f.$('[data-provider-close]').click();
    assert.equal(f.field('key').value, '');
    assert.equal(f.$('dialog').open, false);
    f.$('[data-provider-add]').click(); f.field('preset').value = 'custom'; f.field('preset').dispatchEvent(new f.window.Event('change'));
    assert.equal(f.field('url').value, '');
    assert.equal(f.field('models').value, '');
    assert.equal(new f.window.FormData(document.querySelector('form')).has('key'), false);
});
test('adding a duplicate preset saves through nonce protected REST once and updates model choices', async t => {
    let finish;
    const rows = [row()];
    const f = fixture(t, rows, () => new Promise(resolve => { finish = resolve; }));
    f.$('[data-provider-add]').click(); f.field('name').value = 'DeepSeek 备用'; f.field('key').value = 'fixture-second-key'; f.field('models').value = 'second-model\nreasoner';
    f.$('[data-provider-save]').click(); f.$('[data-provider-save]').click();
    assert.equal(f.calls.length, 1);
    const call = f.calls[0]; assert.equal(call.route, 'providers'); assert.equal(call.options.method, 'POST');
    assert.equal(call.options.credentials, 'same-origin'); assert.equal(call.options.headers['X-WP-Nonce'], 'fixture-nonce');
    assert.deepEqual(JSON.parse(call.options.body).models, ['second-model', 'reasoner']);
    finish({ connections: [...rows, row('second', { name: 'DeepSeek 备用', models: ['second-model', 'reasoner'] })] }); await settle();
    assert.equal(f.$('dialog').open, false); assert.equal(f.field('key').value, '');
    assert.equal(f.$('[data-provider-rows]').children.length, 2);
    assert.ok(Array.from(f.$('[data-provider-default]').options).some(option => option.value === 'second|reasoner'));
    assert.doesNotMatch(f.$('[data-agent-settings]').textContent, /fixture-second-key/);
});
test('edit keeps blank key, preserves default selection and safely renders names and API errors', async t => {
    const rows = [row('first', { name: '<img src=x onerror=alert(1)>' })];
    const f = fixture(t, rows, () => ({ connections: [row('first', { name: '新名称' })] }));
    assert.equal(f.$('[data-provider-rows] img'), null);
    f.$('[data-provider-default]').value = 'first|model-b';
    f.$('[data-provider-rows] .oyiso-agent-provider-actions button:nth-child(2)').click();
    assert.equal(f.field('key').value, ''); assert.match(f.field('key').placeholder, /已配置/);
    f.field('name').value = '新名称'; f.$('[data-provider-save]').click(); await settle();
    assert.equal(f.calls[0].options.method, 'PUT'); assert.equal(f.calls[0].route, 'providers/first');
    assert.equal(JSON.parse(f.calls[0].options.body).key, '');
    assert.equal(f.$('[data-provider-default]').value, 'first|model-b');
});
test('failed saves retain entered credentials for retry and reopening resets clear-key state', async t => {
    const f = fixture(t, [row()], () => ({ ok: false, status: 400, body: { message: '<script>invalid</script>' } }));
    f.$('[data-provider-rows] .oyiso-agent-provider-actions button:nth-child(2)').click();
    f.field('key').value = 'fixture-retry-key'; f.$('[data-provider-save]').click(); await settle();
    assert.equal(f.$('dialog').open, true); assert.equal(f.field('key').value, 'fixture-retry-key');
    assert.equal(f.$('[data-provider-dialog-notice] script'), null);
    assert.equal(f.$('[data-provider-save]').disabled, false);
    f.field('clear_key').checked = true; f.field('clear_key').dispatchEvent(new f.window.Event('change'));
    assert.equal(f.field('key').disabled, true);
    f.$('[data-provider-close]').click(); f.$('[data-provider-add]').click();
    assert.equal(f.field('key').disabled, false); assert.equal(f.field('clear_key').checked, false);
});
test('test model selector, duplicate protection and stale results remain correct after connection edits', async t => {
    let finish;
    const f = fixture(t, [row()], (route) => route.endsWith('/test') ? new Promise(resolve => { finish = resolve; }) : { connections: [row('first', { name: '已更新' })] });
    f.$('[data-provider-rows] select').value = 'model-b';
    const testButton = f.$('[data-provider-rows] button'); testButton.click(); testButton.click();
    assert.equal(f.calls.length, 1); assert.equal(JSON.parse(f.calls[0].options.body).model, 'model-b');
    f.$('[data-provider-rows] .oyiso-agent-provider-actions button:nth-child(2)').click(); f.$('[data-provider-save]').click(); await settle();
    assert.equal(f.$('[data-provider-rows] button').disabled, true);
    finish({ provider: '旧名称', model: 'model-b', message: '成功' }); await settle();
    assert.match(f.$('[data-provider-notice]').textContent, /重新测试/);
    assert.equal(f.$('[data-provider-rows] button').disabled, false);
});
test('delete errors preserve rows and successful deletion removes model choices without silently selecting another', async t => {
    let fail = true;
    const f = fixture(t, [row()], () => fail ? { ok: false, status: 409, body: { message: '请先更换默认模型' } } : { connections: [] });
    f.$('[data-provider-default]').value = 'first|model-a';
    f.$('.oyiso-agent-provider-delete').click(); await settle();
    assert.match(f.$('[data-provider-notice]').textContent, /更换默认模型/);
    assert.equal(f.$('[data-provider-rows]').children.length, 1);
    fail = false; f.$('.oyiso-agent-provider-delete').click(); await settle();
    assert.match(f.$('[data-provider-rows]').textContent, /还没有/);
    assert.equal(f.$('[data-provider-default]').value, 'first|model-a');
    assert.match(f.$('[data-provider-default]').selectedOptions[0].textContent, /已移除/);
    assert.equal(f.calls[1].options.method, 'DELETE');
});
