const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');

const repo = path.resolve(__dirname, '../..');
const phpQuote = value => `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
const markup = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['-r', `define('ABSPATH', '/'); function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); } function esc_html($value) { return esc_attr($value); } function esc_url($value) { return esc_attr($value); } function admin_url($value) { return 'https://vape.local/wp-admin/' . $value; } function current_user_can($value) { return true; } function is_network_admin() { return false; } function is_user_admin() { return false; } function get_option($name, $default = []) { return $name === 'oyiso' ? ['agent_enabled' => true] : $default; } require ${phpQuote(path.join(repo, 'src/ai-agent/config.php'))}; require ${phpQuote(path.join(repo, 'src/ai-agent/admin.php'))}; $parts = []; foreach (['panel', 'history'] as $method) { ob_start(); Oyiso_Agent_Admin::$method(); $parts[$method] = ob_get_clean(); } ob_start(); Oyiso_Agent_Admin::connectionTest('deepseek'); $parts['connection'] = ob_get_clean(); echo json_encode($parts);`], { encoding: 'utf8' }));
const script = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/agent.js'), 'utf8');
const panelScript = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/panel.js'), 'utf8');
const csfScript = fs.readFileSync(path.join(repo, 'assets/js/main.min.js'), 'utf8');
const nativeNavigation = Array.from(fs.readFileSync(path.join(repo, 'src/_init.php'), 'utf8').matchAll(/<script>([\s\S]*?)<\/script>/g), match => match[1]).find(source => source.includes('function switchToSection(tabId)'));
const wpRoot = process.env.OYISO_WORDPRESS_ROOT || path.resolve(repo, '../../..');
const jquery = fs.readFileSync(path.join(wpRoot, 'wp-includes/js/jquery/jquery.js'), 'utf8');
const settle = async () => { for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve)); };
const chooseLayout = (f, mode) => { f.$('[data-agent-layout]').click(); f.$(`[data-agent-layout-choice="${mode}"]`).click(); };

const task = (extra = {}) => ({ id: 12, status: 'completed', step: 1, tokens: 42, conversation: [], events: [], changes: [], error: '', recoverable: false, ...extra });
const proposal = () => ({ id: '12345678-1234-1234-1234-123456789abc', product_id: 99, name: '商品示例', before: { description: '<p>Original</p>' }, after: { description: '<p>Proposed</p>' }, status: 'pending' });

function fixture(t, router = () => undefined, settings = {}, before = () => {}, view = {}) {
    const navigation = view.nativeNavigation ? '<nav class="csf-nav csf-nav-options"><ul><li class="csf-tab-item"><a data-tab-id="ai-agent" class="csf-arrow" href="#tab=ai-agent">AI Agent</a><ul><li><a data-tab-id="ai-agent/agent-model" href="#tab=ai-agent/agent-model">模型配置</a></li><li><a data-tab-id="ai-agent/agent-records" href="#tab=ai-agent/agent-records">对话记录</a></li></ul></li></ul></nav>' : '';
    const content = view.nativeNavigation ? `<section class="csf-section hidden" data-section-id="ai-agent/agent-model"><h2>模型配置</h2>${markup.connection}</section><section class="csf-section hidden" data-section-id="ai-agent/agent-records">${markup.history}</section>` : markup.history + markup.connection;
    const settingsMarkup = `<div class="csf-options" data-unique="oyiso"><form id="csf-form">${navigation}<div class="csf-content">${content}</div><input name="oyiso[agent_deepseek_url]" value="https://api.deepseek.com" data-agent-connection="deepseek"><input name="oyiso[agent_deepseek_model]" value="deepseek-flash" data-agent-connection="deepseek"><input type="password" name="oyiso[agent_deepseek_key]" data-agent-connection="deepseek"><input type="checkbox" name="oyiso[agent_deepseek_key_clear]" data-agent-connection="deepseek"></form></div>`;
    const dom = new JSDOM(`${view.globalOnly ? '<div id="wpcontent">仪表盘</div>' : settingsMarkup}${markup.panel}`, { url: 'https://vape.local/wp-admin/plugins.php?page=oyiso', runScripts: 'outside-only', pretendToBeVisual: true });
    const { window } = dom;
    const calls = [];
    const errors = [];
    window.addEventListener('error', event => errors.push(event.error));
    window.HTMLElement.prototype.scrollIntoView = () => {};
    window.matchMedia = query => ({ matches: !view.motion, media: query });
    window.oyisoAgent = { url: 'https://vape.local/wp-json/oyiso-agent/v1/', nonce: 'fixture-nonce', user: 1, enabled: true, supported: true, ...settings };
    window.fetch = async (url, options) => {
        const route = url.replace(window.oyisoAgent.url, '');
        calls.push({ route, options });
        let response = await router(route, options);
        if (response === undefined) response = { items: [], pages: 0 };
        return { ok: response.ok !== false, status: response.status || 200, json: async () => response.body || response };
    };
    window.eval(jquery);
    if (view.nativeNavigation) { window.eval(csfScript); window.eval(nativeNavigation); }
    before(window);
    if (!view.closed && window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:open') === null) window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:open', '1');
    window.eval(panelScript);
    view.afterGeometry?.(window);
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
    for (const element of [launcher, ...window.document.querySelectorAll('[data-agent-resize], [data-agent-drag]')]) {
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

const panelRecords = '[data-agent-runs="panel"]';
const inputDraft = (f, value) => { const input = f.$('#oyiso-agent-prompt'); input.value = value; input.dispatchEvent(new f.window.Event('input', { bubbles: true })); };

test('panel conversation history loads on demand and switches conversations without navigating or changing window geometry', async t => {
    const f = fixture(t, route => route === 'runs?page=1' ? { items: [{ id: 12, title: '商品内容检查', status: 'completed', updated: '2026-10-19' }], pages: 1 } : route === 'runs/12' ? task({ conversation: [{ role: 'assistant', text: '已读取历史对话' }] }) : undefined, {}, window => {
        geometry(window);
        window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:layout', 'floating');
    }, { globalOnly: true });
    await settle();
    const url = f.window.location.href;
    const geometryBefore = f.$('#oyiso-agent-panel').style.cssText;
    assert.equal(f.calls.length, 0);
    f.$('[data-agent-history-toggle]').click();
    await settle();
    assert.equal(f.$('#oyiso-agent-chat').hidden, false);
    assert.equal(f.$('#oyiso-agent-chat').hasAttribute('inert'), true);
    assert.equal(f.$('#oyiso-agent-panel-history').hidden, false);
    assert.equal(f.$('[data-agent-history-toggle]').getAttribute('aria-expanded'), 'true');
    assert.equal(f.$(`${panelRecords} .oyiso-agent-history-title`).textContent, '商品内容检查');
    f.$(`${panelRecords} .oyiso-agent-history-open`).click();
    await settle();
    assert.equal(f.$('#oyiso-agent-chat').hidden, false);
    assert.equal(f.$('#oyiso-agent-panel-history').hidden, true);
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(f.window.location.href, url);
    assert.equal(f.$('#oyiso-agent-panel').style.cssText, geometryBefore);
    assert.match(f.$('[data-agent-messages]').textContent, /已读取历史对话/);
});

test('quick conversation switching keeps separate drafts and returning to the list preserves the current one', async t => {
    const key = 'oyiso-agent:1:/wp-json/oyiso-agent/v1/';
    const router = route => route === 'runs?page=1' ? { items: [12, 13].map(id => ({ id, title: `对话 ${id}`, status: 'completed', updated: '2026-10-19' })), pages: 1 } : route === 'runs/12' || route === 'runs/13' ? task({ id: Number(route.split('/')[1]) }) : undefined;
    const f = fixture(t, router, {}, window => window.localStorage.setItem(key, '12'), { globalOnly: true });
    await settle();
    inputDraft(f, '对话十二的草稿');
    f.$('[data-agent-history-toggle]').click(); await settle();
    assert.equal(f.$(`${panelRecords} .oyiso-agent-history-open`).getAttribute('aria-current'), 'true');
    f.$(`${panelRecords} li:nth-child(2) .oyiso-agent-history-open`).click(); await settle();
    assert.equal(f.$('#oyiso-agent-prompt').value, '');
    inputDraft(f, '对话十三的草稿');
    f.$('[data-agent-history-toggle]').click(); await settle();
    f.$(`${panelRecords} li:first-child .oyiso-agent-history-open`).click(); await settle();
    assert.equal(f.$('#oyiso-agent-prompt').value, '对话十二的草稿');
    assert.equal(f.window.sessionStorage.getItem(key + ':draft:13'), '对话十三的草稿');
    f.$('[data-agent-history-toggle]').click(); await settle();
    f.$('[data-agent-history-back]').click();
    assert.equal(f.$('#oyiso-agent-prompt').value, '对话十二的草稿');
    f.$('[data-agent-history-toggle]').click(); await settle();
    f.$('[data-agent-history-back]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(f.$('#oyiso-agent-panel-history').hidden, true);
    assert.equal(f.window.document.activeElement, f.$('[data-agent-history-toggle]'));
    const restored = fixture(t, router, {}, window => {
        window.localStorage.setItem(key, '13');
        window.sessionStorage.setItem(key + ':draft:13', f.window.sessionStorage.getItem(key + ':draft:13'));
    }, { globalOnly: true });
    await settle();
    assert.equal(restored.$('#oyiso-agent-prompt').value, '对话十三的草稿');
});

test('panel conversation history handles errors, empty results and pagination and renders titles as text', async t => {
    let failed = true;
    const title = '<img src=x onerror="window.historyXss=true">历史对话';
    const f = fixture(t, route => {
        if (route === 'runs?page=1') return failed ? { ok: false, status: 500, body: { message: '读取对话失败' } } : { items: [{ id: 12, title, status: 'completed', updated: '2026-10-19' }], pages: 2 };
        if (route === 'runs?page=2') return { items: [], pages: 2 };
    }, {}, undefined, { globalOnly: true });
    f.$('[data-agent-history-toggle]').click(); await settle();
    assert.match(f.$(`${panelRecords} [data-agent-history-notice]`).textContent, /读取对话失败/);
    failed = false;
    f.$(`${panelRecords} [data-agent-history-refresh]`).click(); await settle();
    assert.equal(f.$(`${panelRecords} .oyiso-agent-history-title`).textContent, title);
    assert.equal(f.$(`${panelRecords} img`), null);
    assert.equal(f.window.historyXss, undefined);
    f.$(`${panelRecords} [data-agent-history-next]`).click(); await settle();
    assert.equal(f.$(`${panelRecords} [data-agent-history-page]`).textContent, '2 / 2');
    assert.match(f.$(`${panelRecords} [data-agent-history-body]`).textContent, /暂无对话记录/);
    assert.equal(f.$(`${panelRecords} [data-agent-history-next]`).disabled, true);
});

test('an unsent new conversation draft survives viewing history and disappears after successful submission', async t => {
    const f = fixture(t, (route, options) => {
        if (route === 'runs?page=1') return { items: [{ id: 13, title: '已有对话', status: 'completed', updated: '2026-10-19' }], pages: 1 };
        if (route === 'runs/13') return task({ id: 13 });
        if (route === 'runs' && options.method === 'POST' || route === 'runs/14') return task({ id: 14 });
    }, {}, undefined, { globalOnly: true });
    inputDraft(f, '还没发送的新对话');
    f.$('[data-agent-history-toggle]').click(); await settle();
    f.$(`${panelRecords} .oyiso-agent-history-open`).click(); await settle();
    f.$('[data-agent-new]').click();
    assert.equal(f.$('#oyiso-agent-prompt').value, '还没发送的新对话');
    f.$('[data-agent-send]').click(); await settle();
    assert.equal(f.window.sessionStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:draft:0'), null);
    f.$('[data-agent-new]').click();
    assert.equal(f.$('#oyiso-agent-prompt').value, '');
});

test('panel history cancellation submits once and updates the record without closing the list', async t => {
    let finish;
    let cancelled = false;
    const f = fixture(t, route => {
        if (route === 'runs?page=1') return { items: [{ id: 12, title: '待确认的商品修改', status: cancelled ? 'cancelled' : 'waiting', updated: '2026-10-19' }], pages: 1 };
        if (route === 'runs/12/cancel') return new Promise(resolve => { finish = () => { cancelled = true; resolve(task({ status: 'cancelled' })); }; });
    }, {}, undefined, { globalOnly: true });
    f.$('[data-agent-history-toggle]').click(); await settle();
    const cancel = f.$(`${panelRecords} li .button`);
    cancel.click(); cancel.click(); await settle();
    assert.equal(f.calls.filter(call => call.route === 'runs/12/cancel').length, 1);
    assert.equal(cancel.disabled, true);
    finish(); await settle();
    assert.equal(f.$('#oyiso-agent-panel-history').hidden, false);
    assert.match(f.$(`${panelRecords} li`).textContent, /已取消/);
    assert.equal(f.$(`${panelRecords} li .button`), null);
});

test('rapid switches ignore earlier conversation responses and sending clears the submitted draft', async t => {
    let oldResponse;
    let newResponse;
    const f = fixture(t, (route, options) => {
        if (route === 'runs?page=1') return { items: [13, 14].map(id => ({ id, title: `对话 ${id}`, status: 'completed', updated: '2026-10-19' })), pages: 1 };
        if (route === 'runs/13') return new Promise(resolve => { oldResponse = resolve; });
        if (route === 'runs/14') return new Promise(resolve => { newResponse = resolve; });
        if (route === 'runs' && options.method === 'POST') return task({ id: 14 });
    }, {}, undefined, { globalOnly: true });
    f.$('[data-agent-history-toggle]').click(); await settle();
    f.$(`${panelRecords} li:first-child .oyiso-agent-history-open`).click(); await settle();
    f.$('[data-agent-history-toggle]').click(); await settle();
    f.$(`${panelRecords} li:nth-child(2) .oyiso-agent-history-open`).click(); await settle();
    oldResponse(task({ id: 13, status: 'waiting', changes: [proposal()], conversation: [{ role: 'assistant', text: '旧对话的响应' }] }));
    newResponse(task({ id: 14, conversation: [{ role: 'assistant', text: '当前对话的响应' }] }));
    await settle();
    assert.match(f.$('[data-agent-messages]').textContent, /当前对话的响应/);
    assert.doesNotMatch(f.$('[data-agent-messages]').textContent, /旧对话的响应/);
    assert.equal(f.$('[data-agent-changes] button'), null);
    inputDraft(f, '提交这条消息');
    f.$('[data-agent-send]').click(); await settle();
    assert.equal(f.window.sessionStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:draft:14'), null);
    assert.equal(f.$('#oyiso-agent-prompt').value, '');
});

test('launcher stays unpainted until saved position, edge and open state have all restored', t => {
    const key = 'oyiso-agent:1:/wp-json/oyiso-agent/v1/';
    for (const { edge, open, left } of [{ edge: '', open: false, left: '370px' }, { edge: 'right', open: false, left: '1480px' }, { edge: 'left', open: true, left: '0px' }]) {
        const f = fixture(t, undefined, {}, window => {
            const wrapper = window.document.getElementById('oyiso-agent-launcher-wrap');
            assert.equal(wrapper.style.visibility, 'hidden', 'server markup must start invisible before scripts load');
            geometry(window);
            window.localStorage.setItem(key + ':position', JSON.stringify({ x: 0.25, y: 0.6, edge }));
            window.localStorage.setItem(key + ':open', open ? '1' : '0');
        }, { globalOnly: true, closed: true, afterGeometry: window => {
            const wrapper = window.document.getElementById('oyiso-agent-launcher-wrap');
            assert.equal(wrapper.style.left, left);
            assert.equal(wrapper.style.top, '586px');
            assert.equal(wrapper.style.visibility, 'hidden', 'geometry alone must not reveal the launcher before panel state restores');
            assert.equal(wrapper.hasAttribute('data-agent-initializing'), true);
        } });
        const wrapper = f.$('#oyiso-agent-launcher-wrap');
        assert.equal(wrapper.style.left, left);
        assert.equal(wrapper.style.visibility, '');
        assert.equal(wrapper.hasAttribute('data-agent-initializing'), false);
        assert.equal(wrapper.hidden, open);
        assert.equal(f.$('#oyiso-agent-panel').hidden, !open);
    }
});

test('saving disabled state hides launcher and panel immediately and resize cannot bring them back', async t => {
    const f = fixture(t, route => route === 'status' ? { enabled: false, supported: true, secrets: {} } : undefined);
    f.$('#oyiso-agent-prompt').value = '保留未发送内容';
    f.window.jQuery(f.window.document).trigger('ajaxSuccess', [{ responseJSON: { success: true, data: { errors: {} } } }, { data: 'action=csf_oyiso_ajax_save' }]);
    await settle();
    f.window.dispatchEvent(new f.window.Event('resize'));
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
    assert.equal(f.$('#oyiso-agent-launcher-wrap').hidden, true);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-open'), false);
    assert.equal(f.$('[data-agent-send]').disabled, true);
    assert.equal(f.$('#oyiso-agent-prompt').value, '保留未发送内容');
    f.$('#oyiso-agent-launcher').click();
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
    assert.equal(f.calls.some(call => call.route.endsWith('/cancel')), false);
});

function transitionEnd(f, target = f.$('#oyiso-agent-panel'), property = 'transform') {
    const event = new f.window.Event('transitionend', { bubbles: true });
    Object.defineProperty(event, 'propertyName', { value: property });
    target.dispatchEvent(event);
}

test('panel open and close animate while focus and accessibility update immediately', t => {
    const f = fixture(t, undefined, {}, undefined, { globalOnly: true, closed: true, motion: true });
    const panel = f.$('#oyiso-agent-panel');
    const body = f.window.document.body;
    f.$('#oyiso-agent-launcher').click();
    assert.equal(panel.hidden, false);
    assert.equal(panel.hasAttribute('inert'), false);
    assert.equal(panel.getAttribute('aria-hidden'), 'false');
    assert.equal(body.classList.contains('oyiso-agent-transitioning'), true);
    assert.equal(f.window.document.activeElement, f.$('[data-agent-close]'));
    transitionEnd(f, f.$('[data-agent-close]'));
    transitionEnd(f, panel, 'opacity');
    assert.equal(body.classList.contains('oyiso-agent-transitioning'), true);
    transitionEnd(f);
    assert.equal(body.classList.contains('oyiso-agent-transitioning'), false);
    f.$('[data-agent-close]').click();
    assert.equal(panel.hidden, false, 'exit animation keeps the panel painted');
    assert.equal(panel.hasAttribute('inert'), true);
    assert.equal(panel.getAttribute('aria-hidden'), 'true');
    assert.equal(f.$('#oyiso-agent-launcher-wrap').hidden, false);
    assert.equal(f.$('#oyiso-agent-launcher').getAttribute('aria-expanded'), 'false');
    assert.equal(f.window.document.activeElement, f.$('#oyiso-agent-launcher'));
    assert.equal(body.classList.contains('oyiso-agent-open'), false);
    transitionEnd(f);
    assert.equal(panel.hidden, true);
    assert.equal(body.classList.contains('oyiso-agent-transitioning'), false);
});

test('rapid open close open cancels stale cleanup and missing transition events still settle', async t => {
    const f = fixture(t, undefined, {}, undefined, { globalOnly: true, closed: true, motion: true });
    f.$('#oyiso-agent-launcher').click();
    f.$('[data-agent-close]').click();
    f.$('#oyiso-agent-launcher').click();
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(f.$('#oyiso-agent-panel').hasAttribute('inert'), false);
    await new Promise(resolve => setTimeout(resolve, 280));
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-transitioning'), false);
    assert.equal(f.window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:open'), '1');
    f.$('[data-agent-close]').click();
    await new Promise(resolve => setTimeout(resolve, 280));
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-transitioning'), false);
});

test('closing animation immediately invalidates polling without starting a returned queued task', async t => {
    let finish;
    let reads = 0;
    const f = fixture(t, route => {
        if (route === 'runs/12') return ++reads === 1 ? new Promise(resolve => { finish = resolve; }) : task({ conversation: [{ role: 'assistant', text: '重新打开后读取' }] });
    }, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'), { globalOnly: true, closed: true, motion: true });
    f.$('#oyiso-agent-launcher').click();
    await settle();
    f.$('[data-agent-close]').click();
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    finish(task({ status: 'queued', conversation: [{ role: 'assistant', text: '已过期的响应' }] }));
    await settle();
    assert.equal(f.calls.some(call => call.route.endsWith('/step')), false);
    assert.doesNotMatch(f.$('[data-agent-messages]').textContent, /已过期的响应/);
    f.$('#oyiso-agent-launcher').click();
    await settle();
    assert.match(f.$('[data-agent-messages]').textContent, /重新打开后读取/);
    transitionEnd(f);
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
});

test('reduced motion uses immediate toggles and page restoration skips the entrance animation', t => {
    const reduced = fixture(t, undefined, {}, undefined, { globalOnly: true, closed: true });
    reduced.$('#oyiso-agent-launcher').click();
    assert.equal(reduced.$('#oyiso-agent-panel').hidden, false);
    assert.equal(reduced.window.document.body.classList.contains('oyiso-agent-transitioning'), false);
    reduced.$('[data-agent-close]').click();
    assert.equal(reduced.$('#oyiso-agent-panel').hidden, true);
    const restored = fixture(t, undefined, {}, undefined, { globalOnly: true, motion: true });
    assert.equal(restored.$('#oyiso-agent-panel').hidden, false);
    assert.equal(restored.window.document.body.classList.contains('oyiso-agent-transitioning'), false);
    restored.$('[data-agent-close]').click();
    chooseLayout(restored, 'floating');
    assert.equal(restored.$('#oyiso-agent-panel').hidden, true);
    assert.equal(restored.window.document.body.classList.contains('oyiso-agent-transitioning'), false);
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
    chooseLayout(f, 'right');
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-floating'), false);
    assert.equal(f.$('[data-agent-layout-choice="right"]').getAttribute('aria-checked'), 'true');
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
    chooseLayout(f, 'floating');
    pointer(f, handle, 'pointerdown', 800, 400);
    pointer(f, f.window.document, 'pointermove', 560, 400);
    pointer(f, f.window.document, 'pointerup', 560, 400);
    assert.equal(handle.getAttribute('aria-valuenow'), '600');
});

const windowKey = 'oyiso-agent:1:/wp-json/oyiso-agent/v1/';
const initialWindow = { x: 0.25, y: 0.25, width: 600, height: 500 };
const windowRect = f => Object.fromEntries([['x', 'left'], ['y', 'top'], ['width', 'width'], ['height', 'height']].map(([name, property]) => [name, parseFloat(f.$('#oyiso-agent-panel').style[property])]));

function floatingWindow(t, before = () => {}, view = {}) {
    return fixture(t, undefined, {}, window => {
        geometry(window);
        window.localStorage.setItem(windowKey + ':layout', 'floating');
        window.localStorage.setItem(windowKey + ':window', JSON.stringify(initialWindow));
        before(window);
    }, { globalOnly: true, ...view });
}

function resizeFloating(f, edge, dx, dy) {
    const rect = windowRect(f);
    const x = rect.x + (edge.includes('w') ? 0 : edge.includes('e') ? rect.width : rect.width / 2);
    const y = rect.y + (edge.includes('n') ? 0 : edge.includes('s') ? rect.height : rect.height / 2);
    pointer(f, f.$(`[data-agent-resize="${edge}"]`), 'pointerdown', x, y);
    pointer(f, f.window.document, 'pointermove', x + dx, y + dy);
    pointer(f, f.window.document, 'pointerup', x + dx, y + dy);
}

test('layout menu supports both split sides, keyboard navigation, dismissal and saved side restoration', async t => {
    const f = fixture(t, undefined, {}, geometry, { globalOnly: true });
    const menu = f.$('[data-agent-layout-menu]');
    const toggle = f.$('[data-agent-layout]');
    toggle.click();
    assert.equal(menu.hidden, false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(f.window.document.activeElement.dataset.agentLayoutChoice, 'right');
    menu.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
    assert.equal(f.window.document.activeElement.dataset.agentLayoutChoice, 'left');
    f.window.document.activeElement.click();
    assert.equal(menu.hidden, true);
    assert.equal(f.$('#oyiso-agent-panel').dataset.agentDock, 'left');
    assert.equal(f.$('[data-agent-layout-choice="left"]').getAttribute('aria-checked'), 'true');
    assert.equal(f.$('[data-agent-split-divider]').getAttribute('d'), 'M9 4v16');
    assert.equal(f.window.localStorage.getItem(windowKey + ':side'), 'left');
    toggle.click();
    menu.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(menu.hidden, true);
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(f.window.document.activeElement, toggle);
    toggle.click();
    f.window.document.body.click();
    assert.equal(menu.hidden, true);
    const restored = fixture(t, undefined, {}, window => {
        geometry(window);
        window.localStorage.setItem(windowKey + ':side', 'left');
    }, { globalOnly: true });
    assert.equal(restored.$('#oyiso-agent-panel').dataset.agentDock, 'left');
    chooseLayout(restored, 'right');
    assert.equal(restored.$('#oyiso-agent-panel').dataset.agentDock, 'right');
    chooseLayout(restored, 'floating');
    assert.equal(restored.$('#oyiso-agent-panel').hasAttribute('data-agent-window'), true);
    assert.equal(restored.$('[data-agent-layout-icon="floating"]').hidden, false);
    assert.equal(restored.$('[data-agent-layout-icon="split"]').hidden, true);
    await settle();
});

test('left split resizes from its right edge with matching keyboard direction and ignores its outer edge', t => {
    const f = fixture(t, undefined, {}, geometry, { globalOnly: true });
    chooseLayout(f, 'left');
    const inner = f.$('[data-agent-resize="e"]');
    const outer = f.$('[data-agent-resize="w"]');
    pointer(f, inner, 'pointerdown', 544, 400);
    pointer(f, f.window.document, 'pointermove', 684, 400);
    pointer(f, f.window.document, 'pointerup', 684, 400);
    assert.equal(inner.getAttribute('aria-valuenow'), '684');
    inner.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    assert.equal(inner.getAttribute('aria-valuenow'), '704');
    pointer(f, outer, 'pointerdown', 0, 400);
    pointer(f, f.window.document, 'pointermove', 60, 400);
    pointer(f, f.window.document, 'pointerup', 60, 400);
    assert.equal(inner.getAttribute('aria-valuenow'), '704');
    outer.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'End', bubbles: true }));
    assert.equal(inner.getAttribute('aria-valuenow'), '704');
    inner.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
    assert.equal(inner.getAttribute('aria-valuenow'), '360');
    inner.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'End', bubbles: true }));
    assert.equal(inner.getAttribute('aria-valuenow'), '960');
    chooseLayout(f, 'right');
    assert.equal(outer.getAttribute('aria-valuenow'), '960');
});

test('split layout separates the full-height assistant from the native WordPress toolbar, menu and content', t => {
    let desktopStyle;
    let desktopCss;
    const f = fixture(t, undefined, {}, window => {
        geometry(window);
        const document = window.document;
        document.body.classList.add('admin-bar', 'sticky-menu');
        document.body.insertAdjacentHTML('afterbegin', '<div id="wpadminbar"></div><div id="adminmenumain"><div id="adminmenuback"></div><div id="adminmenuwrap"></div></div><div id="wpfooter"></div>');
        const addStyle = source => { const style = document.createElement('style'); style.textContent = source; document.head.append(style); return style; };
        addStyle(['wp-admin/css/common.css', 'wp-admin/css/admin-menu.css', 'wp-includes/css/admin-bar.css'].map(file => fs.readFileSync(path.join(wpRoot, file), 'utf8')).join('\n'));
        const agent = addStyle(fs.readFileSync(path.join(repo, 'src/ai-agent/assets/agent.css'), 'utf8'));
        // jsdom has no media-query evaluation; enable the real desktop block for this viewport.
        const desktop = Array.from(agent.sheet.cssRules).find(rule => rule.conditionText === '(min-width: 1280px)');
        desktopCss = Array.from(desktop.cssRules, rule => rule.cssText).join('\n');
        desktopStyle = addStyle(desktopCss);
    }, { globalOnly: true });
    const css = (selector, property) => f.window.getComputedStyle(f.$(selector)).getPropertyValue(property);
    const panel = '#oyiso-agent-panel';
    const bar = '#wpadminbar';
    const offset = 'var(--oyiso-agent-panel-width)';
    const normalMenu = () => {
        assert.equal(parseFloat(css('#adminmenuwrap', 'margin-left')), 0);
        assert.equal(parseFloat(css('#adminmenuback', 'margin-left')), 0);
        assert.equal(css('#wpcontent', 'margin-left'), '160px');
    };
    const fullToolbar = () => { assert.equal(css(bar, 'left'), '0px'); assert.equal(css(bar, 'right'), '0px'); };
    assert.equal(css(panel, 'top'), '0px');
    assert.equal(css(panel, 'bottom'), '0px');
    assert.equal(css(panel, 'right'), '0px');
    assert.equal(f.$('[data-agent-resize="s"]').getAttribute('aria-valuenow'), '1000');
    assert.equal(css(bar, 'left'), '0px');
    assert.equal(css(bar, 'right'), offset);
    assert.equal(css(bar, 'width'), 'auto');
    assert.equal(parseFloat(css(bar, 'min-width')), 0);
    normalMenu();
    chooseLayout(f, 'left');
    assert.equal(css(panel, 'left'), '0px');
    assert.equal(css(panel, 'top'), '0px');
    assert.equal(css(bar, 'left'), offset);
    assert.equal(css(bar, 'right'), '0px');
    for (const selector of ['#adminmenuwrap', '#adminmenuback']) assert.equal(css(selector, 'margin-left'), offset);
    assert.equal(css('#wpcontent', 'margin-left'), 'calc(var(--oyiso-agent-admin-menu-width) + var(--oyiso-agent-panel-width))');
    f.window.document.body.classList.add('folded');
    assert.equal(css('body', '--oyiso-agent-admin-menu-width').trim(), '36px');
    assert.equal(css(bar, 'left'), offset);
    f.window.document.body.classList.remove('folded');
    f.$('[data-agent-close]').click();
    fullToolbar(); normalMenu();
    f.$('#oyiso-agent-launcher').click();
    assert.equal(css(bar, 'left'), offset);
    chooseLayout(f, 'floating');
    assert.equal(css(bar, 'left'), '0px');
    assert.equal(css(bar, 'width'), '100%');
    normalMenu();
    chooseLayout(f, 'right');
    assert.equal(css(bar, 'right'), offset);
    f.window.document.body.classList.add('block-editor-page');
    assert.equal(css(bar, 'width'), '100%');
    normalMenu();
    f.window.document.body.classList.remove('block-editor-page');
    desktopStyle.textContent = '';
    f.window.innerWidth = 1000;
    f.window.dispatchEvent(new f.window.Event('resize'));
    assert.equal(css(bar, 'width'), '100%');
    normalMenu();
    desktopStyle.textContent = desktopCss;
    f.window.innerWidth = 1600;
    f.window.dispatchEvent(new f.window.Event('resize'));
    assert.equal(css(bar, 'right'), offset);
    assert.equal(css(panel, 'top'), '0px');
});

test('reset restores default panel geometry and right split while preserving mode, launcher position and draft', t => {
    const launcherPosition = JSON.stringify({ x: 0, y: 0.5, edge: 'left' });
    const f = floatingWindow(t, window => {
        window.localStorage.setItem(windowKey + ':width', '700');
        window.localStorage.setItem(windowKey + ':position', launcherPosition);
    });
    inputDraft(f, '恢复布局时保留这段草稿');
    resizeFloating(f, 'se', 80, 40);
    f.$('[data-agent-reset]').click();
    assert.deepEqual(windowRect(f), { x: 1096, y: 256, width: 480, height: 720 });
    assert.equal(f.window.localStorage.getItem(windowKey + ':layout'), 'floating');
    assert.equal(f.window.localStorage.getItem(windowKey + ':width'), null);
    assert.equal(f.window.localStorage.getItem(windowKey + ':window'), null);
    assert.equal(f.window.localStorage.getItem(windowKey + ':position'), launcherPosition);
    assert.equal(f.$('#oyiso-agent-prompt').value, '恢复布局时保留这段草稿');
    chooseLayout(f, 'left');
    f.$('[data-agent-resize="e"]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'End', bubbles: true }));
    assert.equal(f.$('[data-agent-resize="e"]').getAttribute('aria-valuenow'), '960');
    f.$('[data-agent-reset]').click();
    assert.equal(f.$('#oyiso-agent-panel').dataset.agentDock, 'right');
    assert.equal(f.$('[data-agent-resize="w"]').getAttribute('aria-valuenow'), '544');
    assert.equal(f.$('#oyiso-agent-panel').hasAttribute('data-agent-window'), false);
});

test('history stays beside chat in a wide panel and becomes a dismissible left overlay when narrowed', async t => {
    const f = fixture(t, route => route === 'runs?page=1' ? { items: [12, 13].map(id => ({ id, title: `对话 ${id}`, status: 'completed', updated: '2026-10-19' })), pages: 1 } : route === 'runs/12' || route === 'runs/13' ? task({ id: Number(route.split('/')[1]), conversation: [{ role: 'assistant', text: route }] }) : undefined, {}, window => {
        geometry(window);
        window.localStorage.setItem(windowKey + ':layout', 'floating');
        window.localStorage.setItem(windowKey + ':window', JSON.stringify({ x: 0.25, y: 0.25, width: 800, height: 600 }));
    }, { globalOnly: true });
    const panel = f.$('#oyiso-agent-panel');
    f.$('[data-agent-history-toggle]').click(); await settle();
    assert.equal(panel.dataset.historyLayout, 'sidebar');
    assert.equal(f.$('#oyiso-agent-chat').hidden, false);
    assert.equal(f.$('#oyiso-agent-chat').hasAttribute('inert'), false);
    assert.equal(f.$('[data-agent-history-dismiss]').hidden, true);
    f.$(`${panelRecords} li:first-child .oyiso-agent-history-open`).click(); await settle();
    assert.equal(f.$('#oyiso-agent-panel-history').hidden, false);
    assert.equal(f.$(`${panelRecords} li:first-child .oyiso-agent-history-open`).getAttribute('aria-current'), 'true');
    resizeFloating(f, 'e', -160, 0);
    assert.equal(panel.dataset.historyLayout, 'overlay');
    assert.equal(f.$('#oyiso-agent-panel-history').hidden, false);
    assert.equal(f.$('#oyiso-agent-chat').hasAttribute('inert'), true);
    assert.equal(f.$('[data-agent-history-dismiss]').hidden, false);
    resizeFloating(f, 'e', 80, 0);
    assert.equal(panel.dataset.historyLayout, 'sidebar');
    assert.equal(f.$('#oyiso-agent-chat').hasAttribute('inert'), false);
    resizeFloating(f, 'e', -80, 0);
    f.$('[data-agent-history-dismiss]').click();
    assert.equal(f.$('#oyiso-agent-panel-history').hidden, true);
    assert.equal(f.$('#oyiso-agent-chat').hasAttribute('inert'), false);
    f.$('[data-agent-history-toggle]').click(); await settle();
    f.$(`${panelRecords} li:nth-child(2) .oyiso-agent-history-open`).click(); await settle();
    assert.equal(f.$('#oyiso-agent-panel-history').hidden, true);
    assert.match(f.$('[data-agent-messages]').textContent, /runs\/13/);
});

test('floating window resizes from every edge and corner while preserving opposite edges', t => {
    for (const edge of ['w', 'e', 'n', 's', 'nw', 'ne', 'sw', 'se']) {
        const f = floatingWindow(t);
        assert.deepEqual(windowRect(f), { x: 262, y: 161, width: 600, height: 500 });
        const dx = edge.includes('w') ? -40 : edge.includes('e') ? 60 : 0;
        const dy = edge.includes('n') ? -30 : edge.includes('s') ? 50 : 0;
        resizeFloating(f, edge, dx, dy);
        const expected = { x: edge.includes('w') ? 222 : 262, y: edge.includes('n') ? 131 : 161, width: 600 + (edge.includes('w') ? 40 : edge.includes('e') ? 60 : 0), height: 500 + (edge.includes('n') ? 30 : edge.includes('s') ? 50 : 0) };
        assert.deepEqual(windowRect(f), expected, edge);
        const saved = JSON.parse(f.window.localStorage.getItem(windowKey + ':window'));
        assert.equal(saved.width, expected.width);
        assert.equal(saved.height, expected.height);
        assert.equal(f.window.document.body.classList.contains('oyiso-agent-resizing'), false);
        assert.equal(f.window.document.body.style.getPropertyValue('--oyiso-agent-resize-cursor'), '');
    }
});

test('title-bar dragging is bounded and ignores action buttons and links', t => {
    const f = floatingWindow(t);
    const old = windowRect(f);
    for (const control of f.window.document.querySelectorAll('[data-agent-drag] button, [data-agent-drag] a')) {
        pointer(f, control, 'pointerdown', 500, 180);
        pointer(f, f.window.document, 'pointermove', 650, 280);
        pointer(f, f.window.document, 'pointerup', 650, 280);
        assert.deepEqual(windowRect(f), old);
    }
    const title = f.$('#oyiso-agent-title');
    pointer(f, title, 'pointerdown', 280, 180);
    pointer(f, f.window.document, 'pointermove', -800, -900);
    assert.deepEqual(windowRect(f), { x: 24, y: 56, width: 600, height: 500 });
    pointer(f, f.window.document, 'pointermove', 4000, 4000);
    pointer(f, f.window.document, 'pointerup', 4000, 4000);
    assert.deepEqual(windowRect(f), { x: 976, y: 476, width: 600, height: 500 });
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-dragging'), false);
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
});

test('window minimum size and available viewport constrain all resize directions', t => {
    const f = floatingWindow(t);
    resizeFloating(f, 'nw', 4000, 4000);
    assert.deepEqual(windowRect(f), { x: 502, y: 301, width: 360, height: 360 });
    resizeFloating(f, 'nw', -4000, -4000);
    assert.deepEqual(windowRect(f), { x: 24, y: 56, width: 838, height: 605 });
    resizeFloating(f, 'se', 4000, 4000);
    assert.deepEqual(windowRect(f), { x: 24, y: 56, width: 1552, height: 920 });
    resizeFloating(f, 'se', -4000, -4000);
    assert.deepEqual(windowRect(f), { x: 24, y: 56, width: 360, height: 360 });
});

test('floating geometry survives layout switches, reload and temporary viewport shrink independently of split width', t => {
    const f = floatingWindow(t, window => window.localStorage.setItem(windowKey + ':width', '700'));
    resizeFloating(f, 'se', 80, 40);
    const heading = f.$('[data-agent-drag]');
    pointer(f, heading, 'pointerdown', 300, 180);
    pointer(f, f.window.document, 'pointermove', 200, 130);
    pointer(f, f.window.document, 'pointerup', 200, 130);
    const moved = windowRect(f);
    const saved = f.window.localStorage.getItem(windowKey + ':window');
    assert.equal(f.window.localStorage.getItem(windowKey + ':width'), '700');
    chooseLayout(f, 'right');
    assert.equal(f.$('#oyiso-agent-panel').hasAttribute('data-agent-window'), false);
    assert.equal(f.$('#oyiso-agent-panel').style.left, '');
    assert.equal(f.$('[data-agent-drag]').tabIndex, -1);
    const dockedWidth = f.$('[data-agent-resize="w"]');
    assert.equal(dockedWidth.getAttribute('aria-valuenow'), '700');
    dockedWidth.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
    assert.equal(f.window.localStorage.getItem(windowKey + ':width'), '720');
    chooseLayout(f, 'floating');
    assert.deepEqual(windowRect(f), moved);
    const restored = floatingWindow(t, window => {
        window.localStorage.setItem(windowKey + ':window', saved);
        window.localStorage.setItem(windowKey + ':width', '720');
    });
    assert.deepEqual(windowRect(restored), moved);
    restored.window.innerWidth = 375; restored.window.innerHeight = 600;
    restored.window.dispatchEvent(new restored.window.Event('resize'));
    const small = windowRect(restored);
    assert.equal(small.x, 12);
    assert.equal(small.width, 351);
    assert.ok(small.y >= 44 && small.y + small.height <= 588);
    assert.equal(restored.window.localStorage.getItem(windowKey + ':window'), saved);
    restored.window.innerWidth = 1600; restored.window.innerHeight = 1000;
    restored.window.dispatchEvent(new restored.window.Event('resize'));
    assert.deepEqual(windowRect(restored), moved);
});

test('escape, touch cancellation and lost capture restore floating geometry without closing the assistant', t => {
    for (const cancel of ['escape', 'pointercancel', 'lostpointercapture', 'blur', 'resize']) {
        const f = floatingWindow(t);
        const old = windowRect(f);
        const saved = f.window.localStorage.getItem(windowKey + ':window');
        const handle = f.$(cancel === 'pointercancel' ? '[data-agent-drag]' : '[data-agent-resize="nw"]');
        pointer(f, handle, 'pointerdown', 262, 161, { touch: true });
        pointer(f, f.window.document, 'pointermove', 200, 100, { touch: true });
        assert.notDeepEqual(windowRect(f), old);
        if (cancel === 'escape') f.window.document.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        else if (cancel === 'blur' || cancel === 'resize') f.window.dispatchEvent(new f.window.Event(cancel));
        else pointer(f, cancel === 'lostpointercapture' ? handle : f.window.document, cancel, 200, 100, { touch: true });
        assert.deepEqual(windowRect(f), old, cancel);
        assert.equal(f.$('#oyiso-agent-panel').hidden, false);
        assert.equal(f.window.localStorage.getItem(windowKey + ':window'), saved);
        assert.equal(f.window.document.body.classList.contains('oyiso-agent-resizing'), false);
        assert.equal(f.window.document.body.classList.contains('oyiso-agent-dragging'), false);
    }
});

test('floating title and edge keyboard controls move and resize without intercepting action keys', t => {
    const f = floatingWindow(t);
    const heading = f.$('[data-agent-drag]');
    assert.equal(heading.tabIndex, 0);
    heading.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowLeft', shiftKey: true, bubbles: true }));
    heading.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
    assert.deepEqual(windowRect(f), { x: 222, y: 151, width: 600, height: 500 });
    f.$('[data-agent-resize="e"]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    f.$('[data-agent-resize="s"]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    assert.deepEqual(windowRect(f), { x: 222, y: 151, width: 620, height: 520 });
    const old = windowRect(f);
    f.$('[data-agent-new]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
    assert.deepEqual(windowRect(f), old);
    f.$('[data-agent-resize="n"]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
    assert.equal(windowRect(f).height, 360);
    assert.equal(windowRect(f).y + windowRect(f).height, 671);
});

test('dragging during entrance finishes the animation before capture so its timeout cannot cancel the drag', async t => {
    const f = floatingWindow(t, undefined, { closed: true, motion: true });
    f.$('#oyiso-agent-launcher').click();
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-transitioning'), true);
    pointer(f, f.$('[data-agent-drag]'), 'pointerdown', 300, 180);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-transitioning'), false);
    pointer(f, f.window.document, 'pointermove', 200, 130);
    const moving = windowRect(f);
    await new Promise(resolve => setTimeout(resolve, 280));
    assert.deepEqual(windowRect(f), moving);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-dragging'), true);
    pointer(f, f.window.document, 'pointerup', 200, 130);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-dragging'), false);
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

test('launcher docks within 5px of each edge and stays expanded 6px away', async t => {
    for (const [edge, x, y, property, value] of [['left', 5, 400, 'left', '0px'], ['right', 1475, 400, 'left', '1480px'], ['top', 700, 37, 'top', '32px'], ['bottom', 700, 951, 'top', '956px']]) {
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
        const outside = { left: [6, 400], right: [1474, 400], top: [700, 38], bottom: [700, 950] }[edge];
        dragLauncher(f, ...outside);
        assert.equal(wrapper.dataset.agentEdge, undefined);
        assert.equal(wrapper.style.left, `${outside[0]}px`);
        assert.equal(wrapper.style.top, `${outside[1]}px`);
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

test('settings shortcut uses native tab navigation and resets page scroll', async t => {
    const scrolls = [];
    const f = fixture(t, undefined, {}, window => { window.scrollTo = options => scrolls.push(options); }, { nativeNavigation: true });
    await settle();
    f.$('.csf-nav [data-tab-id="ai-agent/agent-records"]').click(); await settle();
    assert.equal(f.window.location.hash, '#tab=ai-agent/agent-records');
    f.$('#oyiso-agent-panel a[aria-label="模型配置"]').click(); await settle();
    assert.equal(f.window.document.querySelectorAll('.csf-section:not(.hidden)').length, 1);
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
    assert.equal(f.window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:open'), '0');
    assert.equal(f.window.location.hash, '#tab=ai-agent/agent-model');
    assert.equal(f.$('[data-section-id="ai-agent/agent-model"]').classList.contains('hidden'), false);
    assert.equal(f.$('.csf-nav [data-tab-id="ai-agent/agent-model"]').classList.contains('csf-active'), true);
    assert.equal(scrolls.at(-1).top, 0);
    assert.equal(f.calls.some(call => call.route.endsWith('/cancel')), false);
});

test('conversation shortcut opens panel history without switching native tabs or changing the URL', async t => {
    const f = fixture(t, undefined, {}, window => { window.scrollTo = () => {}; }, { nativeNavigation: true });
    await new Promise(resolve => f.window.jQuery(resolve));
    f.$('.csf-nav [data-tab-id="ai-agent/agent-model"]').click();
    await settle();
    f.window.history.replaceState(null, '', '#tab=ai-agent/agent-records');
    f.$('[data-agent-history-toggle]').click(); await settle();
    assert.equal(f.$('[data-section-id="ai-agent/agent-model"]').classList.contains('hidden'), false);
    assert.equal(f.window.document.querySelectorAll('.csf-section:not(.hidden)').length, 1);
    assert.equal(f.window.location.hash, '#tab=ai-agent/agent-records');
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(f.$('#oyiso-agent-panel-history').hidden, false);
    assert.equal(f.$('#oyiso-agent-chat').hidden, false);
    assert.equal(f.$('#oyiso-agent-chat').hasAttribute('inert'), true);
});

test('settings shortcut closes the panel before normal navigation from another admin page', async t => {
    const f = fixture(t, undefined, {}, window => window.history.replaceState(null, '', '/wp-admin/edit.php?post_type=product'), { globalOnly: true });
    let prevented;
    f.window.document.addEventListener('click', event => {
        prevented = event.defaultPrevented;
        event.preventDefault(); // Observe default navigation without leaving the test document.
    }, { once: true });
    f.$('#oyiso-agent-panel a[aria-label="模型配置"]').click();
    assert.equal(prevented, false);
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
    assert.equal(f.window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:open'), '0');
    assert.equal(new URL(f.$('a[aria-label="模型配置"]').href).hash, '#tab=ai-agent/agent-model');
    assert.equal(f.calls.some(call => call.route.endsWith('/cancel')), false);
});

test('opening a settings shortcut in a new tab preserves the current assistant', async t => {
    const f = fixture(t, undefined, {}, undefined, { globalOnly: true });
    for (const modifier of ['ctrlKey', 'metaKey', 'shiftKey']) {
        let prevented;
        f.window.document.addEventListener('click', event => { prevented = event.defaultPrevented; event.preventDefault(); }, { once: true });
        f.$('a[aria-label="模型配置"]').dispatchEvent(new f.window.MouseEvent('click', { button: 0, [modifier]: true, bubbles: true, cancelable: true }));
        assert.equal(prevented, false);
        assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    }
    assert.equal(f.window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:open'), '1');
});
