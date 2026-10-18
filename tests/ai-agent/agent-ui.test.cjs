const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');

const repo = path.resolve(__dirname, '../..');
const phpQuote = value => `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
const markup = execFileSync(process.env.PHP_BINARY || 'php', ['-r', `define('ABSPATH', '/'); function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); } require ${phpQuote(path.join(repo, 'src/ai-agent/admin.php'))}; Oyiso_Agent_Admin::chat(); Oyiso_Agent_Admin::history(); Oyiso_Agent_Admin::connectionTest('deepseek');`], { encoding: 'utf8' });
const script = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/agent.js'), 'utf8');
const wpRoot = process.env.OYISO_WORDPRESS_ROOT || path.resolve(repo, '../../..');
const jquery = fs.readFileSync(path.join(wpRoot, 'wp-includes/js/jquery/jquery.js'), 'utf8');
const settle = async () => { for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve)); };

const task = (extra = {}) => ({ id: 12, status: 'completed', step: 1, tokens: 42, conversation: [], events: [], changes: [], error: '', recoverable: false, ...extra });
const proposal = () => ({ id: '12345678-1234-1234-1234-123456789abc', product_id: 99, name: '商品示例', before: { description: '<p>Original</p>' }, after: { description: '<p>Proposed</p>' }, status: 'pending' });

function fixture(t, router = () => undefined, settings = {}, before = () => {}) {
    const dom = new JSDOM(`<div class="csf-options" data-unique="oyiso"><form id="csf-form"><nav class="csf-nav"><a data-tab-id="ai-agent/agent-chat" href="#tab=ai-agent/agent-chat">对话助手</a></nav><div class="csf-content">${markup}</div><input type="password" name="oyiso[agent_search_key]"><input type="checkbox" name="oyiso[agent_search_key_clear]"><input name="oyiso[agent_deepseek_url]" value="https://api.deepseek.com" data-agent-connection="deepseek"><input name="oyiso[agent_deepseek_model]" value="deepseek-flash" data-agent-connection="deepseek"><input type="password" name="oyiso[agent_deepseek_key]" data-agent-connection="deepseek"><input type="checkbox" name="oyiso[agent_deepseek_key_clear]" data-agent-connection="deepseek"></form></div>`, { url: 'https://vape.local/wp-admin/plugins.php?page=oyiso', runScripts: 'outside-only', pretendToBeVisual: true });
    const { window } = dom;
    const calls = [];
    const errors = [];
    window.addEventListener('error', event => errors.push(event.error));
    window.HTMLElement.prototype.scrollIntoView = () => {};
    window.oyisoAgent = { url: 'https://vape.local/wp-json/oyiso-agent/v1/', nonce: 'fixture-nonce', user: 1, enabled: true, supported: true, ...settings };
    window.fetch = async (url, options) => {
        const route = url.replace(window.oyisoAgent.url, '');
        calls.push({ route, options });
        let response = await router(route, options);
        if (response === undefined) response = { items: [], pages: 0 };
        return { ok: response.ok !== false, status: response.status || 200, json: async () => response.body || response };
    };
    window.eval(jquery);
    before(window);
    window.eval(script);
    t.after(() => { window.close(); assert.deepEqual(errors, []); });
    const $ = selector => window.document.querySelector(selector);
    return { window, calls, $ };
}

test('disabled or unsupported agent shows guidance and prevents sending', async t => {
    const f = fixture(t, undefined, { enabled: false });
    await settle();
    assert.equal(f.$('[data-agent-send]').disabled, true);
    assert.match(f.$('[data-agent-notice]').textContent, /模型配置/);
    assert.equal(f.$('#oyiso-agent-prompt').disabled, true);
});

test('example and keyboard submit use current prompt and CSRF-protected REST', async t => {
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? task({ conversation: [{ role: 'user', text: '检查商品' }] }) : route === 'runs/12' ? task({ conversation: [{ role: 'assistant', text: '已读取商品。' }] }) : undefined);
    f.$('[data-agent-example]').click();
    assert.match(f.$('#oyiso-agent-prompt').value, /商品/);
    f.$('#oyiso-agent-prompt').value = '  检查商品  ';
    f.$('#oyiso-agent-prompt').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, bubbles: true }));
    await settle();
    const send = f.calls.find(call => call.route === 'runs' && call.options.method === 'POST');
    assert.equal(JSON.parse(send.options.body).message, '检查商品');
    assert.equal(send.options.headers['X-WP-Nonce'], 'fixture-nonce');
    assert.equal(send.options.credentials, 'same-origin');
    assert.equal(f.$('#oyiso-agent-prompt').value, '');
    assert.match(f.$('[data-agent-messages]').textContent, /已读取商品/);
});

test('prompt changes do not mark plugin settings dirty', async t => {
    const f = fixture(t);
    let changed = 0;
    f.window.jQuery('.csf-content').on('change keypress', ':input', () => changed++);
    for (const type of ['change', 'keypress']) f.$('#oyiso-agent-prompt').dispatchEvent(new f.window.Event(type, { bubbles: true }));
    assert.equal(changed, 0);
});

test('request failures preserve prompt and restore send control', async t => {
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? { ok: false, status: 400, body: { message: '请配置模型连接。' } } : undefined);
    f.$('#oyiso-agent-prompt').value = '保留这条指令'; f.$('[data-agent-send]').click();
    await settle();
    assert.equal(f.$('#oyiso-agent-prompt').value, '保留这条指令');
    assert.equal(f.$('[data-agent-send]').disabled, false);
    assert.match(f.$('[data-agent-notice]').textContent, /配置模型/);
});

test('model text and field previews never execute HTML', async t => {
    const unsafe = '<img src=x onerror="window.agentXss=true"><script>window.agentXss=true</script>';
    const run = task({ conversation: [{ role: 'assistant', text: `${unsafe}\n[来源](https://example.com/product)` }], changes: [{ ...proposal(), after: { description: unsafe } }], status: 'waiting' });
    const f = fixture(t, route => route === 'runs/12' ? run : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.equal(f.window.agentXss, undefined);
    assert.equal(f.$('[data-agent-messages] img'), null);
    assert.equal(f.$('[data-agent-changes] script'), null);
    assert.equal(f.$('[data-agent-changes] pre:last-child').textContent, 'Original');
    f.$('[data-agent-changes] input[type="checkbox"]').click();
    assert.match(f.$('[data-agent-changes] pre:last-child').textContent, /<p>Original/);
    assert.equal(f.$('[data-agent-messages] a').href, 'https://example.com/product');
    assert.equal(f.$('[data-agent-messages] a').rel, 'noopener noreferrer');
});

test('history opens correct task and navigates to native child tab', async t => {
    const f = fixture(t, route => route === 'runs?page=1' ? { items: [{ id: 12, title: '历史任务', status: 'waiting', updated: '2026-10-18 12:00:00' }], pages: 1 } : route === 'runs/12' ? task({ status: 'waiting', changes: [proposal()] }) : undefined);
    let clicked = false;
    f.$('.csf-nav a').addEventListener('click', () => { clicked = true; });
    await settle();
    f.$('[data-agent-history-body] button').click(); await settle();
    assert.equal(clicked, true);
    assert.equal(f.$('[data-agent-changes] button').textContent, '应用修改');
    assert.equal(f.$('[data-agent-status]').textContent, '等待确认');
});

test('apply is explicit and duplicate clicks cannot submit twice', async t => {
    let finish;
    const pending = task({ status: 'waiting', changes: [proposal()] });
    const f = fixture(t, (route, options) => {
        if (route === 'runs/12') return pending;
        if (route.includes('/changes/')) return new Promise(resolve => { finish = () => resolve(task({ changes: [{ ...proposal(), status: 'applied' }] })); });
    }, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.equal(f.calls.some(call => call.route.includes('/changes/')), false);
    f.$('[data-agent-changes] button').click();
    f.$('[data-agent-changes] button').click();
    await settle();
    assert.equal(f.calls.filter(call => call.route.includes('/changes/')).length, 1);
    assert.equal(f.$('[data-agent-changes] button').disabled, true);
    finish(); await settle();
    assert.equal(f.$('[data-agent-changes] button').textContent, '撤回修改');
    assert.equal(JSON.parse(f.calls.find(call => call.route.includes('/changes/')).options.body).action, 'apply');
});

test('conflict leaves pending proposal visible and retryable', async t => {
    const f = fixture(t, route => route === 'runs/12' ? task({ status: 'waiting', changes: [proposal()] }) : route.includes('/changes/') ? { ok: false, status: 409, body: { message: '商品已被其他操作修改。' } } : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle(); f.$('[data-agent-changes] button').click(); await settle();
    assert.match(f.$('[data-agent-notice]').textContent, /其他操作/);
    assert.equal(f.$('[data-agent-changes] button').disabled, false);
    assert.equal(f.$('[data-agent-changes] button').textContent, '应用修改');
});

test('cancellation disables continuation and new conversation clears only client view', async t => {
    const f = fixture(t, route => route === 'runs/12' ? task({ status: 'running' }) : route === 'runs/12/cancel' ? task({ status: 'cancelled' }) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle(); f.$('[data-agent-cancel]').click(); await settle();
    assert.equal(f.$('[data-agent-send]').disabled, true);
    assert.match(f.$('[data-agent-notice]').textContent, /已取消/);
    f.$('[data-agent-new]').click();
    assert.equal(f.$('[data-agent-send]').disabled, false);
    assert.equal(f.$('[data-agent-status]').textContent, '尚未开始');
    assert.equal(f.calls.filter(call => call.route.endsWith('/cancel')).length, 1);
});

test('source URLs are accessible in execution trace', async t => {
    const f = fixture(t, route => route === 'runs/12' ? task({ events: [{ type: 'info', label: '资料来源', detail: 'Manufacturer https://example.com/product' }] }) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.equal(f.$('[data-agent-events] a').href, 'https://example.com/product');
});

test('saving native settings updates availability and clears entered credentials', async t => {
    const f = fixture(t, route => route === 'status' ? { enabled: true, supported: true, search_configured: true, keywords_configured: false } : undefined, { enabled: false });
    const input = f.$('input[name="oyiso[agent_search_key]"]'); input.value = 'fixture-key';
    const clear = f.$('input[name="oyiso[agent_search_key_clear]"]'); clear.checked = true;
    f.window.jQuery(f.window.document).trigger('ajaxSuccess', [{}, { data: 'action=csf_oyiso_ajax_save&data=fixture' }]);
    await settle();
    assert.equal(f.$('[data-agent-send]').disabled, false);
    assert.equal(input.value, ''); assert.equal(clear.checked, false);
    assert.match(input.placeholder, /已配置/);
});

test('history pagination requests a separate page', async t => {
    const f = fixture(t, route => route.startsWith('runs?page=') ? { items: [], pages: 2 } : undefined);
    await settle(); f.$('[data-agent-history-next]').click(); await settle();
    assert.equal(f.$('[data-agent-history-page]').textContent, '2 / 2');
    assert.ok(f.calls.some(call => call.route === 'runs?page=2'));
    assert.equal(f.$('[data-agent-history-next]').disabled, true);
});

test('connection test requires saving edited endpoint or key first', async t => {
    const f = fixture(t);
    f.$('input[name="oyiso[agent_deepseek_key]"]').value = 'unsaved-fixture-key';
    f.$('[data-agent-test-connection]').click(); await settle();
    assert.match(f.$('[data-agent-connection-notice]').textContent, /先保存/);
    assert.equal(f.calls.some(call => call.route.startsWith('connections/')), false);
});

test('saved preset tests use protected REST and block duplicate clicks', async t => {
    let finish;
    const f = fixture(t, route => route.startsWith('connections/') ? new Promise(resolve => { finish = () => resolve({ provider: 'DeepSeek', model: 'deepseek-flash', message: '工具调用测试通过。' }); }) : undefined);
    const button = f.$('[data-agent-test-connection]');
    button.click(); button.click(); await settle();
    assert.equal(button.disabled, true);
    assert.equal(f.calls.filter(call => call.route.startsWith('connections/')).length, 1);
    const call = f.calls.find(call => call.route.startsWith('connections/'));
    assert.equal(call.route, 'connections/deepseek/test');
    assert.equal(call.options.headers['X-WP-Nonce'], 'fixture-nonce');
    assert.equal(call.options.body, '{}');
    finish(); await settle();
    assert.equal(button.disabled, false);
    assert.match(f.$('[data-agent-connection-notice]').textContent, /DeepSeek \/ deepseek-flash/);
});

test('saving preset credentials clears inputs and enables testing the saved settings', async t => {
    const f = fixture(t, route => route === 'status' ? { enabled: false, supported: true, secrets: { deepseek_key: true } } : route.startsWith('connections/') ? { provider: 'DeepSeek', model: 'new-model', message: '通过。' } : undefined);
    const key = f.$('input[name="oyiso[agent_deepseek_key]"]');
    key.value = 'fixture-new-key'; f.$('input[name="oyiso[agent_deepseek_model]"]').value = 'new-model';
    f.window.jQuery(f.window.document).trigger('ajaxSuccess', [{ responseJSON: { success: true, data: { errors: {} } } }, { data: 'action=csf_oyiso_ajax_save' }]);
    await settle();
    assert.equal(key.value, ''); assert.match(key.placeholder, /已配置/);
    f.$('[data-agent-test-connection]').click(); await settle();
    assert.match(f.$('[data-agent-connection-notice]').textContent, /new-model/);
    assert.equal(f.$('[data-agent-send]').disabled, true);
});

test('failed settings saves preserve unsaved keys and connection tests show errors as text', async t => {
    const f = fixture(t, route => route.startsWith('connections/') ? { ok: false, body: { message: '<img src=x onerror=alert(1)>服务不可用' } } : undefined);
    const key = f.$('input[name="oyiso[agent_deepseek_key]"]'); key.value = 'fixture-key';
    f.window.jQuery(f.window.document).trigger('ajaxSuccess', [{ responseJSON: { success: false } }, { data: 'action=csf_oyiso_ajax_save' }]); await settle();
    assert.equal(key.value, 'fixture-key');
    assert.equal(f.calls.some(call => call.route === 'status'), false);
    key.value = ''; f.$('[data-agent-test-connection]').click(); await settle();
    assert.equal(f.$('[data-agent-connection-notice] img'), null);
    assert.match(f.$('[data-agent-connection-notice]').textContent, /服务不可用/);
    assert.equal(f.$('[data-agent-test-connection]').disabled, false);
});
