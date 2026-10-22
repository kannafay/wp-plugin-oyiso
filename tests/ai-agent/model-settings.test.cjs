const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
const repo = path.resolve(__dirname, '../..');
const quote = value => `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
const markup = execFileSync('php', ['-r', `define('ABSPATH', '/'); function wp_json_encode($v){return json_encode($v);} function esc_attr($v){return htmlspecialchars($v, ENT_QUOTES);} function esc_html($v){return esc_attr($v);} function esc_url($v){return esc_attr($v);} function admin_url($v){return 'https://vape.local/wp-admin/'.$v;} function get_option($name,$default=[]){return $name==='oyiso_agent_connections'?[]:($name==='oyiso'?['agent_selection'=>'']:$default);} require ${quote(path.join(repo, 'src/ai-agent/config.php'))}; require ${quote(path.join(repo, 'src/ai-agent/connections.php'))}; require ${quote(path.join(repo, 'src/ai-agent/managed-connections.php'))}; require ${quote(path.join(repo, 'src/ai-agent/model-settings.php'))}; Oyiso_Agent_Model_Settings::render();`], { encoding: 'utf8' });
const script = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/model-settings.js'), 'utf8');
const modalScript = fs.readFileSync(path.join(repo, 'src/admin/assets/modal-motion.js'), 'utf8');
const settle = async () => { for (let i = 0; i < 6; i++) await new Promise(resolve => setImmediate(resolve)); };
const row = (id = 'first', extra = {}) => ({ id, name: 'DeepSeek 主账号', preset: 'deepseek', protocol: 'openai_chat', url: 'https://api.example.com/v1', models: ['model-a', 'model-b'], configured: true, ...extra });
function fixture(t, rows = [], router = () => ({ connections: rows }), { reducedMotion = true } = {}) {
    const dom = new JSDOM(`<form class="csf-content">${markup}</form>`, { url: 'https://vape.local/wp-admin/plugins.php?page=oyiso', runScripts: 'outside-only' });
    const { window } = dom; const document = window.document; const root = document.querySelector('[data-agent-settings]');
    const state = JSON.parse(root.dataset.state); state.connections = rows; root.dataset.state = JSON.stringify(state);
    const errors = []; const calls = []; const modelUpdates = [];
    document.addEventListener('oyiso-agent-models-updated', event => modelUpdates.push(event.detail));
    window.addEventListener('error', event => errors.push(event.error));
    window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    window.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new window.Event('close')); };
    window.confirm = () => true;
    window.matchMedia = () => ({ matches: reducedMotion });
    window.oyisoModelSettings = { url: 'https://vape.local/wp-json/oyiso-agent/v1/', nonce: 'fixture-nonce' };
    window.fetch = async (url, options) => { const route = url.replace(window.oyisoModelSettings.url, ''); calls.push({ route, options }); const result = await router(route, options); return { ok: result.ok !== false, status: result.status || 200, json: async () => result.body || result }; };
    window.eval(modalScript); window.eval(script);
    t.after(() => { window.close(); assert.deepEqual(errors, []); });
    const $ = selector => document.querySelector(selector);
    return { window, $, calls, modelUpdates, field: name => $(`[data-provider-field="${name}"]`) };
}
function transitionEnd(f, target = f.$('dialog'), propertyName = 'transform') {
    const event = new f.window.Event('transitionend', { bubbles: true });
    Object.defineProperty(event, 'propertyName', { value: propertyName });
    target.dispatchEvent(event);
}
function manualInput(f, value) { const input = f.$('[data-model-manual]'); input.value = value; input.dispatchEvent(new f.window.Event('input', { bubbles: true })); }
function modelCheckbox(f, id, checked) {
    const input = Array.from(f.window.document.querySelectorAll('[data-model-catalog] input')).find(input => input.value === id);
    assert.ok(input, `missing model ${id}`); input.checked = checked; input.dispatchEvent(new f.window.Event('change', { bubbles: true })); return input;
}

test('modal closes after its own exit transition, clears the key immediately and restores focus', t => {
    const f = fixture(t, [], undefined, { reducedMotion: false });
    const dialog = f.$('dialog'); const add = f.$('[data-provider-add]');
    add.click();
    assert.equal(dialog.open, true); assert.equal(dialog.classList.contains('is-open'), true);
    f.field('key').value = 'fixture-animation-key';
    f.$('[data-provider-key-toggle]').click(); assert.equal(f.field('key').type, 'text');
    f.$('[data-provider-close]').click();
    assert.equal(dialog.open, true); assert.equal(dialog.classList.contains('is-open'), false);
    assert.equal(dialog.inert, true); assert.equal(f.field('key').value, '');
    f.$('[data-provider-key-toggle]').click(); assert.equal(f.field('key').type, 'password');
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
    f.field('key').value = 'fixture-save-key'; f.$('[data-provider-key-toggle]').click(); f.$('[data-provider-save]').click();
    assert.equal(f.$('[data-provider-key-toggle]').disabled, true);
    f.$('[data-provider-close]').click();
    f.$('dialog').dispatchEvent(new f.window.Event('cancel', { cancelable: true }));
    assert.equal(f.$('dialog').classList.contains('is-open'), true);
    assert.equal(f.field('key').value, 'fixture-save-key');
    finish({ connections: [row()] }); await settle();
    assert.equal(f.$('dialog').open, true); assert.equal(f.$('dialog').classList.contains('is-closing'), true);
    assert.equal(f.field('key').value, '');
    assert.equal(f.field('key').type, 'password');
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
    assert.equal(f.field('preset').value, 'openai');
    assert.equal(f.field('name').value, 'OpenAI');
    assert.equal(f.field('url').value, 'https://api.openai.com/v1');
    assert.equal(f.field('protocol').value, 'openai_chat');
    assert.equal(f.field('models').value, '');
    f.field('preset').value = 'deepseek'; f.field('preset').dispatchEvent(new f.window.Event('change', { bubbles: true }));
    assert.equal(f.field('name').value, 'DeepSeek');
    assert.equal(f.field('url').value, 'https://api.deepseek.com');
    assert.equal(f.field('protocol').value, 'openai_chat');
    f.field('preset').value = 'gemini'; f.field('preset').dispatchEvent(new f.window.Event('change', { bubbles: true }));
    assert.equal(f.field('name').value, 'Gemini');
    assert.match(f.field('url').value, /generativelanguage/);
    assert.equal(changed, 0);
    const toggle = f.$('[data-provider-key-toggle]');
    assert.equal(f.field('key').type, 'password'); assert.equal(toggle.getAttribute('aria-pressed'), 'false');
    f.field('key').value = 'fixture-key'; toggle.click();
    assert.equal(f.field('key').type, 'text'); assert.equal(f.field('key').value, 'fixture-key');
    assert.equal(toggle.getAttribute('aria-pressed'), 'true'); assert.equal(toggle.getAttribute('aria-label'), '隐藏 API Key');
    assert.equal(f.$('[data-key-show]').hasAttribute('hidden'), true); assert.equal(f.$('[data-key-hide]').hasAttribute('hidden'), false);
    toggle.click(); assert.equal(f.field('key').type, 'password'); assert.equal(f.field('key').value, 'fixture-key');
    assert.equal(toggle.getAttribute('aria-label'), '显示 API Key');
    assert.equal(f.$('[data-key-show]').hasAttribute('hidden'), false); assert.equal(f.$('[data-key-hide]').hasAttribute('hidden'), true);
    assert.equal(changed, 0); assert.equal(f.calls.length, 0);
    toggle.click(); f.$('[data-provider-close]').click();
    assert.equal(f.field('key').value, '');
    assert.equal(f.$('dialog').open, false);
    f.$('[data-provider-add]').click(); f.field('preset').value = 'custom'; f.field('preset').dispatchEvent(new f.window.Event('change'));
    assert.equal(f.field('key').type, 'password'); assert.equal(toggle.getAttribute('aria-pressed'), 'false');
    assert.equal(f.field('url').value, '');
    assert.equal(f.field('models').value, '');
    assert.equal(f.field('protocol').value, 'openai_chat');
    assert.equal(new f.window.FormData(document.querySelector('form')).has('key'), false);
});
test('API protocol changes preserve official, custom and empty URLs while presets fill their own defaults', t => {
    const f = fixture(t);
    f.$('[data-provider-add]').click();
    assert.deepEqual(Array.from(f.field('protocol').options, option => option.value), ['openai_chat', 'openai_responses', 'anthropic_messages', 'gemini']);
    const change = (field, value) => { f.field(field).value = value; f.field(field).dispatchEvent(new f.window.Event('change', { bubbles: true })); };
    change('preset', 'gemini');
    assert.equal(f.field('protocol').value, 'gemini');
    assert.equal(f.field('url').value, 'https://generativelanguage.googleapis.com/v1beta');
    f.field('key').value = 'fixture-kept-key';
    change('protocol', 'anthropic_messages');
    assert.equal(f.field('url').value, 'https://generativelanguage.googleapis.com/v1beta');
    assert.equal(f.field('key').value, 'fixture-kept-key');
    change('protocol', 'openai_chat');
    assert.equal(f.field('url').value, 'https://generativelanguage.googleapis.com/v1beta');
    change('protocol', 'openai_responses');
    assert.equal(f.field('url').value, 'https://generativelanguage.googleapis.com/v1beta');
    assert.equal(f.field('url').placeholder, 'https://api.openai.com/v1');
    f.field('url').value = 'https://gateway.example.com/custom/v2';
    change('protocol', 'gemini');
    assert.equal(f.field('url').value, 'https://gateway.example.com/custom/v2');
    assert.equal(f.field('url').placeholder, 'https://generativelanguage.googleapis.com/v1beta');
    assert.equal(f.field('key').value, 'fixture-kept-key');
    f.field('url').value = '';
    change('protocol', 'openai_responses');
    assert.equal(f.field('url').value, '');
    change('preset', 'deepseek');
    assert.equal(f.field('url').value, 'https://api.deepseek.com');
    assert.equal(f.field('protocol').value, 'openai_chat');
    change('preset', 'agnes');
    assert.equal(f.field('protocol').value, 'openai_chat');
    change('preset', 'custom');
    assert.equal(f.field('protocol').value, 'openai_chat');
    change('preset', 'openai');
    assert.equal(f.field('protocol').value, 'openai_chat');
    assert.equal(f.field('url').value, 'https://api.openai.com/v1');
});

test('legacy Gemini edits keep their compatible API and native protocol saves persist and render correctly', async t => {
    const legacy = row('legacy', { preset: 'gemini', url: 'https://generativelanguage.googleapis.com/v1beta/openai' });
    const native = row('native', { preset: 'gemini', protocol: 'gemini', url: 'https://generativelanguage.googleapis.com/v1beta' });
    const f = fixture(t, [legacy, native], (route, options) => ({ connections: [legacy, row('native', JSON.parse(options.body))] }));
    assert.equal(f.$('[data-provider-id="legacy"] td:nth-child(2)').textContent, 'OpenAI Compatible');
    assert.equal(f.$('[data-provider-id="native"] td:nth-child(2)').textContent, 'Google Generative AI');
    f.$('[data-provider-id="legacy"] .oyiso-agent-provider-actions button:nth-child(2)').click();
    assert.equal(f.field('protocol').value, 'openai_chat');
    assert.equal(f.field('url').value, legacy.url);
    f.$('[data-provider-close]').click();
    f.$('[data-provider-id="native"] .oyiso-agent-provider-actions button:nth-child(2)').click();
    assert.equal(f.field('protocol').value, 'gemini');
    f.field('protocol').value = 'anthropic_messages';
    f.field('protocol').dispatchEvent(new f.window.Event('change'));
    f.$('[data-provider-save]').click(); await settle();
    const payload = JSON.parse(f.calls[0].options.body);
    assert.equal(payload.protocol, 'anthropic_messages');
    assert.equal(payload.url, native.url);
    assert.equal(payload.key, '');
    assert.equal(f.calls[0].route, 'providers/native');
    assert.equal(f.$('[data-provider-id="native"] td:nth-child(2)').textContent, 'Anthropic');
    f.$('[data-provider-id="native"] .oyiso-agent-provider-actions button:nth-child(2)').click();
    assert.equal(f.field('protocol').value, 'anthropic_messages');
});

test('a key-only connection saves without models and becomes usable after editing its model list', async t => {
    let saved;
    const f = fixture(t, [], (route, options) => {
        assert.ok(route === 'providers' || route === 'providers/draft');
        const input = JSON.parse(options.body);
        saved = row('draft', { ...input, configured: !!input.key || !!saved?.configured });
        return { connections: [saved] };
    });
    f.$('[data-provider-add]').click();
    f.field('preset').value = 'deepseek'; f.field('preset').dispatchEvent(new f.window.Event('change'));
    f.field('key').value = 'fixture-draft-key';
    f.$('[data-provider-save]').click(); await settle();
    assert.equal(f.calls.length, 1); assert.deepEqual(JSON.parse(f.calls[0].options.body).models, []);
    assert.equal(f.$('dialog').open, false); assert.equal(f.field('key').value, '');
    assert.match(f.$('[data-provider-id="draft"] td:nth-child(3)').textContent, /未添加模型，暂不可用/);
    assert.equal(f.$('[data-provider-id="draft"] .oyiso-agent-provider-actions button').disabled, true);
    assert.equal(f.$('[data-provider-default]'), null);
    assert.deepEqual(Object.keys(f.modelUpdates.at(-1)), []);
    assert.match(f.$('[data-provider-notice]').textContent, /已保存.*稍后编辑/);
    const edit = () => f.$('[data-provider-id="draft"] .oyiso-agent-provider-actions button:nth-child(2)').click();
    edit(); assert.equal(f.field('models').value, ''); assert.match(f.field('key').placeholder, /已配置/);
    f.$('[data-provider-save]').click(); await settle();
    assert.equal(f.calls[1].options.method, 'PUT'); assert.equal(JSON.parse(f.calls[1].options.body).key, '');
    edit(); f.$('[data-model-add]').click(); manualInput(f, 'completed-model'); f.$('[data-model-apply]').click();
    f.$('[data-provider-save]').click(); await settle();
    assert.deepEqual(JSON.parse(f.calls[2].options.body).models, ['completed-model']);
    assert.equal(f.$('[data-provider-id="draft"] .oyiso-agent-provider-actions button').disabled, false);
    assert.ok(f.modelUpdates.at(-1)['draft|completed-model']);
});

test('missing provider fields report only the field that needs completing', t => {
    const f = fixture(t); f.$('[data-provider-add]').click();
    f.field('key').value = 'fixture-draft-key'; f.field('name').value = '';
    f.$('[data-provider-save]').click();
    assert.equal(f.$('[data-provider-dialog-notice]').textContent, '请填写连接名称。');
    f.field('name').value = 'Custom'; f.field('url').value = ''; f.$('[data-provider-save]').click();
    assert.equal(f.$('[data-provider-dialog-notice]').textContent, '请填写 Base URL。');
    assert.equal(f.calls.length, 0);
});

test('adding a duplicate preset saves through nonce protected REST once and updates model choices', async t => {
    let finish;
    const rows = [row()];
    const f = fixture(t, rows, () => new Promise(resolve => { finish = resolve; }));
    f.$('[data-provider-add]').click(); f.field('preset').value = 'deepseek'; f.field('preset').dispatchEvent(new f.window.Event('change')); f.field('name').value = 'DeepSeek 备用'; f.field('key').value = 'fixture-second-key'; f.field('models').value = 'second-model\nreasoner';
    f.$('[data-provider-save]').click(); f.$('[data-provider-save]').click();
    assert.equal(f.calls.length, 1);
    const call = f.calls[0]; assert.equal(call.route, 'providers'); assert.equal(call.options.method, 'POST');
    assert.equal(call.options.credentials, 'same-origin'); assert.equal(call.options.headers['X-WP-Nonce'], 'fixture-nonce');
    assert.equal(JSON.parse(call.options.body).protocol, 'openai_chat');
    assert.deepEqual(JSON.parse(call.options.body).models, ['second-model', 'reasoner']);
    finish({ connections: [...rows, row('second', { name: 'DeepSeek 备用', protocol: 'openai_chat', models: ['second-model', 'reasoner'] })] }); await settle();
    assert.equal(f.$('dialog').open, false); assert.equal(f.field('key').value, '');
    assert.equal(f.$('[data-provider-rows]').children.length, 2);
    assert.equal(f.modelUpdates.at(-1)['second|reasoner'], 'DeepSeek 备用 / reasoner');
    assert.doesNotMatch(f.$('[data-agent-settings]').textContent, /fixture-second-key/);
});
test('edit keeps blank key, publishes model choices and safely renders names and API errors', async t => {
    const rows = [row('first', { name: '<img src=x onerror=alert(1)>' })];
    const f = fixture(t, rows, () => ({ connections: [row('first', { name: '新名称' })] }));
    assert.equal(f.$('[data-provider-rows] img'), null);
    f.$('[data-provider-rows] .oyiso-agent-provider-actions button:nth-child(2)').click();
    assert.equal(f.field('key').value, ''); assert.match(f.field('key').placeholder, /已配置/);
    f.field('name').value = '新名称'; f.$('[data-provider-save]').click(); await settle();
    assert.equal(f.calls[0].options.method, 'PUT'); assert.equal(f.calls[0].route, 'providers/first');
    assert.equal(JSON.parse(f.calls[0].options.body).key, '');
    assert.equal(f.modelUpdates.at(-1)['first|model-b'], '新名称 / model-b');
});
test('failed saves retain entered credentials for retry and reopening resets clear-key state', async t => {
    const f = fixture(t, [row()], () => ({ ok: false, status: 400, body: { message: '<script>invalid</script>' } }));
    f.$('[data-provider-rows] .oyiso-agent-provider-actions button:nth-child(2)').click();
    const toggle = f.$('[data-provider-key-toggle]');
    f.field('key').value = 'fixture-retry-key'; toggle.click(); f.$('[data-provider-save]').click(); await settle();
    assert.equal(f.$('dialog').open, true); assert.equal(f.field('key').value, 'fixture-retry-key');
    assert.equal(f.$('[data-provider-dialog-notice] script'), null);
    assert.equal(f.$('[data-provider-save]').disabled, false);
    assert.equal(toggle.disabled, false); assert.equal(f.field('key').type, 'text');
    f.field('clear_key').checked = true; f.field('clear_key').dispatchEvent(new f.window.Event('change'));
    assert.equal(f.field('key').disabled, true);
    assert.equal(f.field('key').value, ''); assert.equal(f.field('key').type, 'password'); assert.equal(toggle.disabled, true);
    f.field('clear_key').checked = false; f.field('clear_key').dispatchEvent(new f.window.Event('change'));
    assert.equal(toggle.disabled, false); assert.equal(toggle.getAttribute('aria-pressed'), 'false');
    f.field('clear_key').checked = true; f.field('clear_key').dispatchEvent(new f.window.Event('change'));
    f.$('[data-provider-close]').click(); f.$('[data-provider-add]').click();
    assert.equal(f.field('key').disabled, false); assert.equal(f.field('clear_key').checked, false);
    assert.equal(f.field('key').type, 'password'); assert.equal(toggle.disabled, false);
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
test('delete errors preserve rows and successful deletion publishes the remaining model choices', async t => {
    let fail = true;
    const f = fixture(t, [row()], () => fail ? { ok: false, status: 409, body: { message: '配置正在保存' } } : { connections: [] });
    f.$('.oyiso-agent-provider-delete').click(); await settle();
    assert.match(f.$('[data-provider-notice]').textContent, /正在保存/);
    assert.equal(f.$('[data-provider-rows]').children.length, 1);
    fail = false; f.$('.oyiso-agent-provider-delete').click(); await settle();
    assert.match(f.$('[data-provider-rows]').textContent, /还没有/);
    assert.deepEqual(Object.keys(f.modelUpdates.at(-1)), []);
    assert.equal(f.calls[1].options.method, 'DELETE');
});

test('manual model dialog batches and deduplicates IDs, animates independently and saves only with its provider', async t => {
    const f = fixture(t, [], (route, options) => ({ connections: [row('new', JSON.parse(options.body))] }), { reducedMotion: false });
    let changed = 0; f.window.document.querySelector('form').addEventListener('input', () => changed++);
    f.$('[data-provider-add]').click(); f.field('key').value = 'fixture-parent-key';
    assert.equal(f.field('models').type, 'hidden'); assert.equal(f.$('[data-provider-dialog] textarea'), null);
    f.$('[data-model-add]').click();
    const child = f.$('[data-model-dialog]'); assert.equal(child.open, true); assert.equal(child.classList.contains('is-open'), true);
    manualInput(f, 'model-a，model-a\nmodel-b, model-b');
    assert.match(f.$('[data-model-preview]').textContent, /新增 2/); assert.equal(changed, 0);
    f.$('[data-model-apply]').click();
    assert.equal(child.open, true); assert.equal(child.inert, true); assert.equal(f.$('[data-provider-dialog]').open, true);
    assert.equal(f.field('key').value, 'fixture-parent-key'); assert.equal(f.calls.length, 0);
    transitionEnd(f, child, 'opacity'); assert.equal(child.open, true);
    transitionEnd(f, child); assert.equal(child.open, false); assert.equal(child.inert, false);
    assert.equal(f.window.document.activeElement, f.$('[data-model-add]'));
    assert.equal(f.field('models').value, 'model-a\nmodel-b'); assert.equal(f.$('[data-model-count]').textContent, '2 / 30');
    f.$('[data-provider-save]').click(); await settle();
    assert.deepEqual(JSON.parse(f.calls[0].options.body).models, ['model-a', 'model-b']);
    transitionEnd(f); assert.equal(f.$('[data-provider-dialog]').open, false);
});

test('manual validation and limits keep previous models and Gemini aliases keep their saved ID', t => {
    const ids = Array.from({ length: 30 }, (_, i) => `model-${i}`);
    const f = fixture(t, [row('first', { models: ids })]);
    f.$('[data-provider-rows] .oyiso-agent-provider-actions button:nth-child(2)').click(); f.$('[data-model-add]').click();
    for (const value of ['bad model', 'bad|model', 'x'.repeat(201), '模'.repeat(100)]) {
        manualInput(f, value); f.$('[data-model-apply]').click(); assert.match(f.$('[data-model-notice]').textContent, /模型 ID/); assert.equal(f.$('[data-model-dialog]').open, true);
    }
    manualInput(f, 'one-more'); f.$('[data-model-apply]').click(); assert.match(f.$('[data-model-notice]').textContent, /30/);
    assert.equal(f.field('models').value, ids.join('\n')); f.$('[data-model-close]').click();
    f.$('[data-model-selected] button').click(); assert.match(f.$('[data-model-count]').textContent, /29/);
    f.$('[data-model-add]').click(); manualInput(f, 'one-more'); f.$('[data-model-apply]').click(); assert.match(f.$('[data-model-count]').textContent, /30/);
    const g = fixture(t, [row('gemini', { preset: 'gemini', protocol: 'gemini', models: ['models/gemini-fixture'] })]);
    g.$('[data-provider-rows] .oyiso-agent-provider-actions button:nth-child(2)').click(); g.$('[data-model-add]').click();
    manualInput(g, 'gemini-fixture\nmodels/gemini-fixture'); g.$('[data-model-apply]').click(); assert.equal(g.field('models').value, 'models/gemini-fixture');
});

test('fetched models support search and draft checkbox selection while retaining unlisted manual models', async t => {
    let finish;
    const saved = row('first', { models: ['manual-model', 'server-a'] });
    const f = fixture(t, [saved], route => route === 'providers/models' ? new Promise(resolve => { finish = resolve; }) : { connections: [saved] });
    let changed = 0; f.window.document.querySelector('form').addEventListener('change', () => changed++);
    f.$('[data-provider-rows] .oyiso-agent-provider-actions button:nth-child(2)').click(); f.$('[data-model-fetch]').click();
    assert.equal(f.$('[data-model-apply]').disabled, true); assert.equal(f.$('[data-model-retry]').disabled, true);
    assert.match(f.$('[data-model-status]').textContent, /正在获取/);
    const call = f.calls[0]; const payload = JSON.parse(call.options.body);
    assert.equal(call.route, 'providers/models'); assert.equal(call.options.headers['X-WP-Nonce'], 'fixture-nonce');
    assert.deepEqual(payload, { id: 'first', url: saved.url, protocol: saved.protocol, key: '', clear_key: false });
    f.$('[data-provider-save]').click(); assert.equal(f.calls.length, 1);
    finish({ models: [{ id: 'server-a', name: 'Server A' }, { id: 'server-b', name: '<img src=x onerror=alert(1)>' }], truncated: false }); await settle();
    assert.match(f.$('[data-model-status]').textContent, /获取 2/); assert.equal(f.$('[data-model-catalog] img'), null);
    assert.equal(Array.from(f.window.document.querySelectorAll('[data-model-catalog] input')).find(input => input.value === 'manual-model').checked, true);
    modelCheckbox(f, 'server-a', false); modelCheckbox(f, 'server-b', true);
    assert.equal(changed, 0); assert.equal(f.field('models').value, 'manual-model\nserver-a');
    f.$('[data-model-search]').value = 'server-b'; f.$('[data-model-search]').dispatchEvent(new f.window.Event('input', { bubbles: true }));
    assert.equal(f.window.document.querySelectorAll('[data-model-catalog] input').length, 1);
    f.$('[data-model-apply]').click(); assert.equal(f.$('[data-model-dialog]').open, false);
    assert.equal(f.field('models').value, 'manual-model\nserver-b'); assert.equal(f.calls.length, 1);
});

test('model list errors offer manual entry without clearing provider fields or saving a partial connection', async t => {
    const f = fixture(t, [], () => ({ ok: false, status: 502, body: { message: '<img src=x> 此接口未提供模型列表' } }));
    f.$('[data-provider-add]').click(); f.field('key').value = 'fixture-unsaved-key'; f.field('name').value = '未保存的连接';
    f.$('[data-model-fetch]').click(); await settle();
    assert.match(f.$('[data-model-notice]').textContent, /未提供模型列表/); assert.equal(f.$('[data-model-notice] img'), null);
    assert.equal(f.$('[data-model-retry]').disabled, false); f.$('[data-model-close]').click(); f.$('[data-model-add]').click();
    assert.equal(f.$('[data-model-manual-view]').hidden, false); assert.equal(f.$('[data-model-catalog-view]').hidden, true);
    manualInput(f, 'manual-model'); f.$('[data-model-apply]').click();
    assert.equal(f.field('name').value, '未保存的连接'); assert.equal(f.field('key').value, 'fixture-unsaved-key'); assert.equal(f.field('models').value, 'manual-model');
    assert.equal(f.calls.length, 1); assert.equal(f.$('[data-provider-dialog]').open, true);
});

test('adding models manually after catalog selection keeps both parts of the unsaved provider', async t => {
    const f = fixture(t, [row('first', { models: ['manual-old', 'server-a'] })], () => ({ models: [{ id: 'server-a', name: 'A' }, { id: 'server-b', name: 'B' }] }));
    f.$('[data-provider-rows] .oyiso-agent-provider-actions button:nth-child(2)').click(); f.$('[data-model-fetch]').click(); await settle();
    modelCheckbox(f, 'server-a', false); modelCheckbox(f, 'server-b', true);
    f.$('[data-model-apply]').click(); f.$('[data-model-add]').click(); manualInput(f, 'manual-new,server-b');
    assert.match(f.$('[data-model-preview]').textContent, /新增 1 个模型，1 个已添加/);
    assert.equal(f.field('models').value, 'manual-old\nserver-b');
    f.$('[data-model-apply]').click(); assert.equal(f.field('models').value, 'manual-old\nserver-b\nmanual-new');
    assert.equal(f.calls.length, 1);
});

test('closing, reopening or switching to manual entry aborts and ignores earlier model-list responses', async t => {
    const finishes = [];
    const f = fixture(t, [], () => new Promise(resolve => finishes.push(resolve)));
    f.$('[data-provider-add]').click(); f.$('[data-model-fetch]').click();
    f.$('[data-model-close]').click(); assert.equal(f.calls[0].options.signal.aborted, true);
    f.$('[data-model-fetch]').click();
    finishes[0]({ models: [{ id: 'stale-model', name: 'Stale' }] }); await settle();
    assert.equal(f.$('[data-model-catalog]').textContent, ''); assert.equal(f.$('[data-model-retry]').disabled, true);
    f.$('[data-model-close]').click(); f.$('[data-model-add]').click(); assert.equal(f.calls[1].options.signal.aborted, true);
    finishes[1]({ models: [{ id: 'another-stale-model', name: 'Stale' }] }); await settle();
    assert.equal(f.$('[data-model-manual-view]').hidden, false); assert.equal(f.field('models').value, '');
    manualInput(f, 'manual-kept'); f.$('[data-model-apply]').click();
    assert.equal(f.field('models').value, 'manual-kept'); assert.equal(f.$('[data-model-dialog]').open, false);
});

test('checkbox limit feedback preserves focus and list scroll while allowing a different model', async t => {
    const ids = Array.from({ length: 31 }, (_, i) => `model-${i}`);
    const f = fixture(t, [row('first', { models: ids.slice(0, 30) })], () => ({ models: ids.map(id => ({ id, name: id })) }));
    f.$('[data-provider-rows] .oyiso-agent-provider-actions button:nth-child(2)').click(); f.$('[data-model-fetch]').click(); await settle();
    assert.equal(modelCheckbox(f, 'model-30', true).checked, false); assert.match(f.$('[data-model-notice]').textContent, /30/);
    const input = Array.from(f.window.document.querySelectorAll('[data-model-catalog] input')).find(item => item.value === 'model-0');
    const list = f.$('[data-model-catalog]'); list.scrollTop = 110; input.focus(); modelCheckbox(f, 'model-0', false);
    assert.equal(list.scrollTop, 110); assert.equal(f.window.document.activeElement, input);
    modelCheckbox(f, 'model-30', true); f.$('[data-model-apply]').click();
    assert.equal(f.field('models').value.split('\n').length, 30); assert.equal(f.field('models').value.split('\n').includes('model-0'), false);
    assert.equal(f.field('models').value.split('\n').includes('model-30'), true);
});

test('retry, empty and partial lists remain usable and Escape cancels only the model dialog', async t => {
    let count = 0;
    const f = fixture(t, [row('first', { models: ['kept-model'] })], () => ++count === 1 ? { ok: false, status: 502, body: { message: '暂时不可用' } } : count === 2 ? { models: [], truncated: false } : { models: [{ id: 'new-model', name: 'New' }], truncated: true });
    f.$('[data-provider-rows] .oyiso-agent-provider-actions button:nth-child(2)').click(); f.field('key').value = 'fixture-kept-key';
    f.$('[data-model-fetch]').click(); await settle(); f.$('[data-model-retry]').click(); await settle();
    assert.match(f.$('[data-model-status]').textContent, /未返回可选模型/);
    f.$('[data-model-retry]').click(); await settle(); assert.match(f.$('[data-model-status]').textContent, /未完整返回/);
    modelCheckbox(f, 'new-model', true);
    const event = new f.window.Event('cancel', { cancelable: true }); f.$('[data-model-dialog]').dispatchEvent(event);
    assert.equal(event.defaultPrevented, true); assert.equal(f.$('[data-model-dialog]').open, false);
    assert.equal(f.$('[data-provider-dialog]').open, true); assert.equal(f.field('key').value, 'fixture-kept-key'); assert.equal(f.field('models').value, 'kept-model');
});
