const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');

const repo = path.resolve(__dirname, '../..');
const phpQuote = value => `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
const markup = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['-r', `define('ABSPATH', '/'); function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); } function esc_html($value) { return esc_attr($value); } function esc_url($value) { return esc_attr($value); } function admin_url($value) { return 'https://vape.local/wp-admin/' . $value; } function current_user_can($value) { return true; } function is_network_admin() { return false; } function is_user_admin() { return false; } require ${phpQuote(path.join(repo, 'src/ai-agent/admin.php'))}; $parts = []; foreach (['panel', 'history'] as $method) { ob_start(); Oyiso_Agent_Admin::$method(); $parts[$method] = ob_get_clean(); } ob_start(); Oyiso_Agent_Admin::connectionTest('deepseek'); $parts['connection'] = ob_get_clean(); echo json_encode($parts);`], { encoding: 'utf8' }));
const script = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/agent.js'), 'utf8');
const panelScript = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/panel.js'), 'utf8');
const wpRoot = process.env.OYISO_WORDPRESS_ROOT || path.resolve(repo, '../../..');
const jquery = fs.readFileSync(path.join(wpRoot, 'wp-includes/js/jquery/jquery.js'), 'utf8');
const settle = async () => { for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve)); };

const task = (extra = {}) => ({ id: 12, status: 'completed', step: 1, tokens: 42, conversation: [], events: [], changes: [], error: '', recoverable: false, ...extra });
const proposal = () => ({ id: '12345678-1234-1234-1234-123456789abc', product_id: 99, name: '商品示例', before: { description: '<p>Original</p>' }, after: { description: '<p>Proposed</p>' }, status: 'pending' });

function fixture(t, router = () => undefined, settings = {}, before = () => {}, view = {}) {
    const settingsMarkup = `<div class="csf-options" data-unique="oyiso"><form id="csf-form"><div class="csf-content">${markup.history}${markup.connection}</div><input name="oyiso[agent_deepseek_url]" value="https://api.deepseek.com" data-agent-connection="deepseek"><input name="oyiso[agent_deepseek_model]" value="deepseek-flash" data-agent-connection="deepseek"><input type="password" name="oyiso[agent_deepseek_key]" data-agent-connection="deepseek"><input type="checkbox" name="oyiso[agent_deepseek_key_clear]" data-agent-connection="deepseek"></form></div>`;
    const dom = new JSDOM(`${view.globalOnly ? '<div id="wpcontent">仪表盘</div>' : settingsMarkup}${markup.panel}`, { url: 'https://vape.local/wp-admin/plugins.php?page=oyiso', runScripts: 'outside-only', pretendToBeVisual: true });
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
    if (!view.closed && window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:open') === null) window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:open', '1');
    window.eval(panelScript);
    window.eval(script);
    t.after(() => { window.close(); assert.deepEqual(errors, []); });
    const $ = selector => window.document.querySelector(selector);
    return { window, calls, $ };
}

function geometry(window, width = 1600, height = 1000) {
    Object.defineProperties(window, { innerWidth: { value: width, writable: true }, innerHeight: { value: height, writable: true } });
    Object.defineProperty(window.document.documentElement, 'clientWidth', { get: () => window.innerWidth, configurable: true });
    window.document.body.style.setProperty('--oyiso-agent-bar-height', '32px');
    const launcher = window.document.getElementById('oyiso-agent-launcher');
    Object.defineProperties(launcher, { offsetWidth: { get: () => 120 }, offsetHeight: { get: () => 44 } });
    for (const element of [launcher, window.document.querySelector('[data-agent-resize]')]) {
        let captured;
        element.setPointerCapture = id => { captured = id; };
        element.hasPointerCapture = id => captured === id;
        element.releasePointerCapture = () => { captured = undefined; };
    }
}

function pointer(f, target, type, x, y, extra = {}) {
    const event = new f.window.MouseEvent(type, { clientX: x, clientY: y, button: extra.button || 0, bubbles: true, cancelable: true });
    Object.defineProperties(event, { pointerId: { value: extra.id || 1 }, isPrimary: { value: extra.primary !== false }, pointerType: { value: extra.touch ? 'touch' : 'mouse' } });
    target.dispatchEvent(event);
}

function dragLauncher(f, x, y) {
    const wrapper = f.$('#oyiso-agent-launcher-wrap');
    const startX = parseFloat(wrapper.style.left) + 8;
    const startY = parseFloat(wrapper.style.top) + 8;
    pointer(f, f.$('#oyiso-agent-launcher'), 'pointerdown', startX, startY);
    pointer(f, f.window.document, 'pointermove', x + 8, y + 8);
    pointer(f, f.window.document, 'pointerup', x + 8, y + 8);
    f.$('#oyiso-agent-launcher').click();
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

test('history opens the correct task in the global panel outside settings forms', async t => {
    const f = fixture(t, route => route === 'runs?page=1' ? { items: [{ id: 12, title: '历史任务', status: 'waiting', updated: '2026-10-18 12:00:00' }], pages: 1 } : route === 'runs/12' ? task({ status: 'waiting', changes: [proposal()] }) : undefined, {}, undefined, { closed: true });
    await settle();
    f.$('[data-agent-history-body] button').click(); await settle();
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(f.$('#oyiso-agent-launcher').getAttribute('aria-expanded'), 'true');
    assert.equal(f.$('#oyiso-agent-chat').closest('form'), null);
    assert.equal(f.$('[data-agent-changes] button').textContent, '应用修改');
    assert.equal(f.$('[data-agent-status]').textContent, '等待确认');
});

test('global launcher works on other admin pages without loading task data until opened', async t => {
    const f = fixture(t, route => route === 'runs/12' ? task() : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'), { globalOnly: true, closed: true });
    await settle();
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
    assert.equal(f.calls.length, 0);
    f.$('#oyiso-agent-launcher').click(); await settle();
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-open'), true);
    assert.equal(f.calls.filter(call => call.route === 'runs/12').length, 1);
    f.$('[data-agent-close]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-open'), false);
    assert.equal(f.window.document.activeElement, f.$('#oyiso-agent-launcher'));
    assert.equal(f.window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:open'), '0');
    assert.equal(f.calls.some(call => call.route.endsWith('/cancel')), false);
});

test('open panel, floating layout, conversation and unsent draft restore across admin navigation', async t => {
    const key = 'oyiso-agent:1:/wp-json/oyiso-agent/v1/';
    const f = fixture(t, route => route === 'runs/12' ? task() : undefined, {}, window => {
        window.localStorage.setItem(key, '12');
        window.localStorage.setItem(key + ':open', '1');
        window.localStorage.setItem(key + ':layout', 'floating');
        window.sessionStorage.setItem(key + ':draft', JSON.stringify({ id: 12, text: '待发送的商品问题' }));
    }, { globalOnly: true });
    await settle();
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-floating'), true);
    assert.equal(f.$('#oyiso-agent-prompt').value, '待发送的商品问题');
    f.$('[data-agent-layout]').click();
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-floating'), false);
    assert.equal(f.$('[data-agent-layout]').getAttribute('aria-pressed'), 'false');
    assert.equal(f.window.localStorage.getItem(key + ':layout'), 'docked');
    f.$('#oyiso-agent-prompt').value = '下一页继续';
    f.$('#oyiso-agent-prompt').dispatchEvent(new f.window.Event('input', { bubbles: true }));
    assert.equal(JSON.parse(f.window.sessionStorage.getItem(key + ':draft')).text, '下一页继续');
});

test('closing the panel discards pending polling responses and resumes on reopen', async t => {
    let finish;
    let reads = 0;
    const f = fixture(t, route => route === 'runs/12' ? (++reads === 1 ? new Promise(resolve => { finish = resolve; }) : task()) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'), { globalOnly: true });
    await settle();
    f.$('[data-agent-close]').click();
    finish(task({ status: 'queued' })); await settle();
    assert.equal(f.calls.some(call => call.route.endsWith('/step')), false);
    assert.equal(f.$('[data-agent-status]').textContent, '尚未开始');
    f.$('#oyiso-agent-launcher').click(); await settle();
    assert.equal(reads, 2);
    assert.equal(f.$('[data-agent-status]').textContent, '已完成');
});

test('switching tasks clears previous proposals while the next conversation is loading', async t => {
    let finish;
    const f = fixture(t, route => {
        if (route === 'runs?page=1') return { items: [{ id: 13, title: '另一个任务', status: 'completed', updated: '2026-10-19' }], pages: 1 };
        if (route === 'runs/12') return task({ status: 'waiting', changes: [proposal()] });
        if (route === 'runs/13') return new Promise(resolve => { finish = resolve; });
    }, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.equal(f.$('[data-agent-changes] button').textContent, '应用修改');
    f.$('#oyiso-agent-history tbody button').click(); await settle();
    assert.equal(f.$('[data-agent-changes] button'), null);
    assert.equal(f.$('[data-agent-send]').disabled, true);
    assert.match(f.$('[data-agent-messages]').textContent, /正在读取/);
    finish(task({ id: 13, conversation: [{ role: 'assistant', text: '另一个任务的结果' }] })); await settle();
    assert.match(f.$('[data-agent-messages]').textContent, /另一个任务的结果/);
    assert.equal(f.$('[data-agent-send]').disabled, false);
});

test('execution history cancels eligible tasks once and refreshes their status', async t => {
    let cancelled = false;
    let finish;
    const f = fixture(t, route => {
        if (route === 'runs?page=1') return { items: ['running', 'queued', 'waiting', 'completed', 'failed', 'cancelled'].map((status, index) => ({ id: 12 + index, title: `任务 ${12 + index}`, status: index === 0 && cancelled ? 'cancelled' : status, updated: '2026-10-19' })), pages: 1 };
        if (route === 'runs/12/cancel') return new Promise(resolve => { finish = () => { cancelled = true; resolve(task({ status: 'cancelled' })); }; });
    }, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'), { closed: true });
    await settle();
    const rows = Array.from(f.window.document.querySelectorAll('#oyiso-agent-history tbody tr'));
    assert.deepEqual(rows.map(row => row.querySelectorAll('button').length), [2, 2, 2, 1, 1, 1]);
    const button = rows[0].querySelector('button:last-child');
    assert.equal(button.textContent, '取消任务');
    assert.equal(rows[0].querySelector('button:first-child').textContent, '查看对话');
    button.click(); button.click(); await settle();
    assert.equal(f.calls.filter(call => call.route === 'runs/12/cancel').length, 1);
    assert.equal(f.calls.find(call => call.route === 'runs/12/cancel').options.headers['X-WP-Nonce'], 'fixture-nonce');
    finish(); await settle();
    assert.match(f.$('#oyiso-agent-history tbody tr:first-child').textContent, /已取消/);
    assert.equal(f.$('#oyiso-agent-history tbody tr:first-child').querySelectorAll('button').length, 1);
    assert.equal(f.$('[data-agent-status]').textContent, '已取消');
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
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
    const f = fixture(t, route => route === 'status' ? { enabled: true, supported: true, secrets: { deepseek_key: true } } : undefined, { enabled: false });
    const input = f.$('input[name="oyiso[agent_deepseek_key]"]'); input.value = 'fixture-key';
    const clear = f.$('input[name="oyiso[agent_deepseek_key_clear]"]'); clear.checked = true;
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

test('panel resize changes split width, persists it and supports keyboard limits', async t => {
    const f = fixture(t, undefined, {}, geometry);
    const handle = f.$('[data-agent-resize]');
    assert.equal(handle.getAttribute('aria-valuenow'), '544');
    pointer(f, handle, 'pointerdown', 1056, 400);
    pointer(f, f.window.document, 'pointermove', 900, 400);
    assert.equal(handle.getAttribute('aria-valuenow'), '700');
    assert.equal(f.window.document.body.style.getPropertyValue('--oyiso-agent-panel-width'), '700px');
    pointer(f, f.window.document, 'pointerup', 900, 400);
    assert.equal(f.window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:width'), '700');
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-resizing'), false);
    handle.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'End', bubbles: true }));
    assert.equal(handle.getAttribute('aria-valuenow'), '960');
    handle.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
    assert.equal(handle.getAttribute('aria-valuenow'), '360');
    f.$('[data-agent-layout]').click();
    pointer(f, handle, 'pointerdown', 800, 400);
    pointer(f, f.window.document, 'pointermove', 560, 400);
    pointer(f, f.window.document, 'pointerup', 560, 400);
    assert.equal(handle.getAttribute('aria-valuenow'), '600');
});

test('launcher moves freely without opening on drag release and still opens on a click', async t => {
    const f = fixture(t, undefined, {}, geometry, { closed: true, globalOnly: true });
    dragLauncher(f, 700, 410);
    const wrapper = f.$('#oyiso-agent-launcher-wrap');
    assert.equal(wrapper.style.left, '700px');
    assert.equal(wrapper.style.top, '410px');
    assert.equal(wrapper.dataset.agentEdge, undefined);
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
    assert.equal(f.calls.length, 0);
    const saved = JSON.parse(f.window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:position'));
    assert.ok(saved.x > 0 && saved.x < 1 && saved.y > 0 && saved.y < 1);
    await settle();
    f.$('#oyiso-agent-launcher').click();
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(wrapper.hidden, true);
    f.$('[data-agent-close]').click();
    assert.equal(wrapper.hidden, false);
    assert.equal(wrapper.style.left, '700px');
});

test('launcher docks to each edge and can be dragged back into the page', async t => {
    for (const [edge, x, y, property, value] of [['left', 5, 400, 'left', '0px'], ['right', 1475, 400, 'left', '1480px'], ['top', 700, 36, 'top', '32px'], ['bottom', 700, 950, 'top', '956px']]) {
        const f = fixture(t, undefined, {}, geometry, { closed: true, globalOnly: true });
        dragLauncher(f, x, y);
        const wrapper = f.$('#oyiso-agent-launcher-wrap');
        assert.equal(wrapper.dataset.agentEdge, edge);
        assert.equal(wrapper.style[property], value);
        assert.equal(f.$('#oyiso-agent-panel').hidden, true);
        dragLauncher(f, 600, 450);
        assert.equal(wrapper.dataset.agentEdge, undefined);
        assert.equal(wrapper.style.left, '600px');
        assert.equal(wrapper.style.top, '450px');
    }
});

test('saved width and edge position adapt to narrow viewports without losing preferences', async t => {
    const key = 'oyiso-agent:1:/wp-json/oyiso-agent/v1/';
    const f = fixture(t, undefined, {}, window => {
        geometry(window);
        window.localStorage.setItem(key + ':width', '700');
        window.localStorage.setItem(key + ':position', JSON.stringify({ x: 1, y: 0.5, edge: 'right' }));
    }, { closed: true, globalOnly: true });
    const wrapper = f.$('#oyiso-agent-launcher-wrap');
    assert.equal(wrapper.style.left, '1480px');
    assert.equal(f.$('[data-agent-resize]').getAttribute('aria-valuenow'), '700');
    f.window.innerWidth = 375; f.window.innerHeight = 600;
    f.window.dispatchEvent(new f.window.Event('resize'));
    assert.equal(wrapper.style.left, '255px');
    assert.equal(wrapper.style.top, '294px');
    assert.equal(wrapper.dataset.agentEdge, 'right');
    assert.equal(f.$('[data-agent-resize]').getAttribute('aria-valuenow'), '351');
    assert.equal(f.window.localStorage.getItem(key + ':width'), '700');
    f.window.innerWidth = 1280;
    Object.defineProperty(f.window.document.documentElement, 'clientWidth', { get: () => f.window.innerWidth - 15 });
    f.window.dispatchEvent(new f.window.Event('resize'));
    assert.equal(f.$('[data-agent-resize]').getAttribute('aria-valuemax'), '625');
    f.window.innerWidth = 1600; f.window.innerHeight = 1000;
    f.window.dispatchEvent(new f.window.Event('resize'));
    assert.equal(wrapper.style.left, '1465px');
    assert.equal(f.$('[data-agent-resize]').getAttribute('aria-valuenow'), '700');
});

test('escape and lost pointer capture restore geometry and release drag state', async t => {
    const f = fixture(t, undefined, {}, geometry);
    const handle = f.$('[data-agent-resize]');
    pointer(f, handle, 'pointerdown', 1056, 400);
    pointer(f, f.window.document, 'pointermove', 900, 400, { id: 2 });
    assert.equal(handle.getAttribute('aria-valuenow'), '544');
    pointer(f, f.window.document, 'pointermove', 900, 400);
    f.window.document.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(handle.getAttribute('aria-valuenow'), '544');
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-resizing'), false);
    f.$('[data-agent-close]').click();
    const wrapper = f.$('#oyiso-agent-launcher-wrap');
    const old = wrapper.style.cssText;
    pointer(f, f.$('#oyiso-agent-launcher'), 'pointerdown', 1464, 940, { touch: true });
    pointer(f, f.window.document, 'pointermove', 600, 450, { touch: true });
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-dragging'), true);
    pointer(f, f.$('#oyiso-agent-launcher'), 'lostpointercapture', 600, 450, { touch: true });
    assert.equal(wrapper.style.cssText, old);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-dragging'), false);
    assert.equal(f.window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:position'), null);
});

test('launcher keyboard movement can leave an edge and touch cancellation does not move it', async t => {
    const f = fixture(t, undefined, {}, window => {
        geometry(window);
        window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:position', JSON.stringify({ x: 0, y: 0.5, edge: 'left' }));
    }, { closed: true, globalOnly: true });
    const launcher = f.$('#oyiso-agent-launcher');
    const wrapper = f.$('#oyiso-agent-launcher-wrap');
    pointer(f, launcher, 'pointerenter', 8, 502);
    assert.equal(wrapper.classList.contains('is-hovered'), true);
    pointer(f, launcher, 'pointerleave', 8, 502);
    assert.equal(wrapper.classList.contains('is-hovered'), false);
    pointer(f, launcher, 'pointerenter', 8, 502, { touch: true });
    assert.equal(wrapper.classList.contains('is-hovered'), false);
    launcher.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    assert.equal(wrapper.style.left, '10px');
    assert.equal(wrapper.dataset.agentEdge, undefined);
    const old = wrapper.style.cssText;
    pointer(f, launcher, 'pointerdown', 18, 502, { touch: true });
    pointer(f, f.window.document, 'pointermove', 500, 502, { touch: true });
    pointer(f, f.window.document, 'pointercancel', 500, 502, { touch: true });
    assert.equal(wrapper.style.cssText, old);
    pointer(f, launcher, 'pointerdown', 18, 502, { button: 2 });
    pointer(f, f.window.document, 'pointermove', 500, 502);
    pointer(f, f.window.document, 'pointerup', 500, 502);
    assert.equal(wrapper.style.cssText, old);
});

test('invalid geometry preferences and unavailable storage keep the launcher usable', async t => {
    const f = fixture(t, undefined, {}, window => {
        geometry(window);
        window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:width', 'NaN');
        window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:position', '{invalid');
    }, { closed: true, globalOnly: true });
    assert.equal(f.$('[data-agent-resize]').getAttribute('aria-valuenow'), '544');
    Object.defineProperty(f.window, 'localStorage', { get() { throw new Error('Storage disabled'); } });
    dragLauncher(f, 600, 450);
    await settle();
    f.$('#oyiso-agent-launcher').click();
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
});
