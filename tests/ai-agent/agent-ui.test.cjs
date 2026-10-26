const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
const { ReadableStream } = require('node:stream/web');

const repo = path.resolve(__dirname, '../..');
const phpQuote = value => `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
const markup = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['-r', `define('ABSPATH', '/'); function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); } function esc_html($value) { return esc_attr($value); } function esc_url($value) { return esc_attr($value); } function admin_url($value) { return 'https://vape.local/wp-admin/' . $value; } function current_user_can($value) { return true; } function is_network_admin() { return false; } function is_user_admin() { return false; } function get_option($name, $default = []) { return $name === 'oyiso' ? ['agent_enabled' => true] : $default; } require ${phpQuote(path.join(repo, 'src/ai-agent/config.php'))}; require ${phpQuote(path.join(repo, 'src/ai-agent/admin.php'))}; $parts = []; foreach (['panel', 'history'] as $method) { ob_start(); Oyiso_Agent_Admin::$method(); $parts[$method] = ob_get_clean(); } ob_start(); Oyiso_Agent_Admin::connectionTest('deepseek'); $parts['connection'] = ob_get_clean(); echo json_encode($parts);`], { encoding: 'utf8' }));
const script = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/agent.js'), 'utf8');
const panelScript = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/panel.js'), 'utf8');
const markdownVendor = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/lib/markdown-it.min.js'), 'utf8');
const markdownScript = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/markdown.js'), 'utf8');
const historyScript = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/history.js'), 'utf8');
const pageScript = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/page-context.js'), 'utf8');
const modalScript = fs.readFileSync(path.join(repo, 'src/admin/assets/modal-motion.js'), 'utf8');
const composerScript = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/composer.js'), 'utf8');
const previewScript = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/file-preview.js'), 'utf8');
const liveScript = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/live.js'), 'utf8');
const csfScript = fs.readFileSync(path.join(repo, 'assets/js/main.min.js'), 'utf8');
const nativeNavigation = Array.from(fs.readFileSync(path.join(repo, 'src/_init.php'), 'utf8').matchAll(/<script>([\s\S]*?)<\/script>/g), match => match[1]).find(source => source.includes('function switchToSection(tabId)'));
const wpRoot = process.env.OYISO_WORDPRESS_ROOT || path.resolve(repo, '../../..');
const jquery = fs.readFileSync(path.join(wpRoot, 'wp-includes/js/jquery/jquery.js'), 'utf8');
const settle = async () => { for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve)); };
function frameClock(window) {
    let time = 0, sequence = 0;
    const frames = new Map();
    window.requestAnimationFrame = callback => { frames.set(++sequence, callback); return sequence; };
    window.cancelAnimationFrame = id => frames.delete(id);
    return {
        tick(ms = 16) { time += ms; const pending = [...frames.values()]; frames.clear(); pending.forEach(callback => callback(time)); },
        finish() { for (let i = 0; i < 80 && frames.size; i++) this.tick(); assert.equal(frames.size, 0); },
    };
}
const chooseLayout = (f, mode) => { if (f.$('[data-agent-settings-menu]').hidden) f.$('[data-agent-settings-toggle]').click(); f.$(`[data-agent-layout-choice="${mode}"]`).click(); };

const task = (extra = {}) => ({ id: 12, status: 'completed', step: 1, tokens: 42, conversation: [], events: [], changes: [], error: '', recoverable: false, ...extra });
const proposal = () => ({ id: '12345678-1234-1234-1234-123456789abc', product_id: 99, name: '商品示例', before: { description: '<p>Original</p>' }, after: { description: '<p>Proposed</p>' }, status: 'pending' });
const contentProposal = (extra = {}) => ({ id: 'content-latest', post_id: 7, post_type: 'post', kind: 'update', title: 'Article', before: { content: '<p>Original</p>', excerpt: '' }, after: { content: '<p>Latest</p>', excerpt: '' }, status: 'filled', ...extra });

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
    window.oyisoAgent = { url: 'https://vape.local/wp-json/oyiso-agent/v1/', nonce: 'fixture-nonce', user: 1, enabled: true, supported: true, writeTools: ['oyiso/apply-product-update', 'oyiso/revert-product-update', 'oyiso/apply-product-trash', 'oyiso/revert-product-trash', 'woodmart/update-theme-setting'], models: { 'fixture|model-a': '测试连接 / model-a', 'second|model-b': '备用连接 / model-b' }, ...settings };
    window.fetch = async (url, options) => {
        const route = url.replace(window.oyisoAgent.url, '');
        calls.push({ route, options });
        let response = await router(route, options);
        if (response === undefined) response = { items: [], pages: 0 };
        if (response.sse) return { ok: true, status: 200, headers: { get: () => 'text/event-stream; charset=utf-8' }, body: response.sse };
        return { ok: response.ok !== false, status: response.status || 200, json: async () => response.body || response };
    };
    window.eval(jquery);
    if (view.nativeNavigation) { window.eval(csfScript); window.eval(nativeNavigation); }
    before(window);
    if (!view.closed && window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:open') === null) window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:open', '1');
    window.eval(panelScript);
    window.eval(markdownVendor);
    window.eval(markdownScript);
    window.eval(modalScript); window.eval(historyScript);
    window.eval(previewScript); window.eval(composerScript);
    window.eval(liveScript);
    window.eval(pageScript);
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

test('a message automatically carries the current editor context and latest unsaved body', async t => {
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? task() : undefined, {
        pageContext: { screen: 'post', label: 'Current article', post_id: 7, post_type: 'post', editor: 'classic', signature: 'signed-fixture', fields: { title: 'Current article', content: '<p>Saved</p>', excerpt: '' } },
    }, window => window.document.body.insertAdjacentHTML('beforeend', '<div id="poststuff"><input id="title" value="Current article"><textarea id="content">&lt;p&gt;Unpublished changes&lt;/p&gt;</textarea><textarea id="excerpt"></textarea></div>'));
    f.$('#oyiso-agent-prompt').value = '我当前在哪里'; f.$('[data-agent-send]').click(); await settle();
    const body = JSON.parse(f.calls.find(call => call.route === 'runs' && call.options.method === 'POST').options.body);
    const context = JSON.parse(body.page_context);
    assert.equal(context.post_id, 7); assert.equal(context.fields.content, '<p>Unpublished changes</p>'); assert.equal(context.dirty, true);
    assert.match(f.$('[data-agent-page-context]').textContent, /当前文章.*Current article/);
});

test('panel gutters track native and overlay scrollbars as the content area resizes', async t => {
    let client = 303;
    const observers = [];
    const f = fixture(t, undefined, {}, window => {
        const area = window.document.querySelector('.oyiso-agent-scroll');
        Object.defineProperties(area, { offsetWidth: { get: () => 320 }, clientWidth: { get: () => client } });
        window.ResizeObserver = class { constructor(callback) { this.callback = callback; } observe(target) { observers.push({ target, callback: this.callback }); } };
    });
    await settle();
    const panel = f.$('#oyiso-agent-panel');
    assert.equal(panel.style.getPropertyValue('--oyiso-agent-scrollbar'), '17px');
    client = 305;
    observers.find(observer => observer.target === f.$('.oyiso-agent-scroll')).callback();
    assert.equal(panel.style.getPropertyValue('--oyiso-agent-scrollbar'), '15px');
    client = 320; f.window.dispatchEvent(new f.window.Event('resize'));
    assert.equal(panel.style.getPropertyValue('--oyiso-agent-scrollbar'), '0px');
    assert.equal(f.$('[data-agent-notice]').parentElement, f.$('.oyiso-agent-scroll').parentElement);
    assert.equal(f.$('.oyiso-agent-scroll').contains(f.$('[data-agent-notice]')), false);
});

test('new article drafts are previewed and created only after clicking the confirmation card', async t => {
    const change = { id: proposal().id, post_id: 0, post_type: 'post', kind: 'draft', title: '<img src=x> Article', before: {}, after: { title: 'Article', content: '<p>New article</p>', excerpt: '' }, saved: {}, status: 'pending' };
    const f = fixture(t, route => route === 'runs/12' ? task({ status: 'waiting', content_changes: [change] }) : route.includes('/content/') ? task({ content_changes: [{ ...change, post_id: 91, status: 'applied', url: 'https://vape.local/wp-admin/post.php?post=91&action=edit' }] }) : undefined,
        { writeTools: ['oyiso/create-post-draft'] }, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.equal(f.$('[data-agent-changes] img'), null);
    assert.equal(f.calls.some(call => call.route.includes('/content/')), false);
    const create = Array.from(f.window.document.querySelectorAll('[data-agent-changes] button')).find(button => button.textContent === '创建草稿');
    create.click(); create.click(); await settle();
    assert.equal(f.calls.filter(call => call.route.includes('/content/')).length, 1);
    assert.match(f.$('[data-agent-changes]').textContent, /草稿已创建/);
    assert.equal(f.$('[data-agent-changes] a').textContent, '打开草稿');
});

test('a separate draft requested from an editor is clearly labeled and leaves the current editor untouched', async t => {
    const change = { id: proposal().id, post_id: 0, post_type: 'post', kind: 'draft', title: 'Another article', before: {}, after: { title: 'Another article', content: '<p>New article</p>', excerpt: '' }, saved: {}, status: 'pending' };
    const f = fixture(t, route => route === 'runs/12' ? task({ status: 'waiting', content_changes: [change] }) : route.includes('/content/') ? task({ content_changes: [{ ...change, post_id: 91, status: 'applied' }] }) : undefined,
        { writeTools: ['oyiso/create-post-draft'], pageContext: { screen: 'post', post_id: 7, post_type: 'post', editor: 'classic', fields: { title: 'Current article', content: '<p>My current edits</p>', excerpt: '' } } }, window => {
            window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
            window.document.body.insertAdjacentHTML('beforeend', '<div id="poststuff"><input id="title" value="Current article"><textarea id="content">&lt;p&gt;My current edits&lt;/p&gt;</textarea><textarea id="excerpt"></textarea></div>');
        });
    await settle();
    assert.match(f.$('[data-agent-changes]').textContent, /独立草稿/);
    const create = Array.from(f.window.document.querySelectorAll('[data-agent-changes] button')).find(button => button.textContent === '另外创建草稿');
    assert.ok(create); assert.equal(f.calls.some(call => call.route.includes('/content/')), false);
    create.click(); await settle();
    assert.equal(JSON.parse(f.calls.find(call => call.route.includes('/content/')).options.body).action, 'apply');
    assert.equal(f.$('#title').value, 'Current article'); assert.equal(f.$('#content').value, '<p>My current edits</p>');
});

test('earlier filled article proposals are collapsed as history instead of stacked beside the latest proposal', async t => {
    const changes = [
        contentProposal({ id: 'content-first', after: { content: '<p>First version</p>', excerpt: 'First summary' } }),
        contentProposal({ id: 'content-second', after: { content: '<p>Second version</p>', excerpt: '' } }),
        contentProposal(),
    ];
    let current = task({ content_changes: changes, conversation: [{ role: 'user', text: 'Translate the article' }, { role: 'assistant', text: 'Preview ready' }] });
    const f = fixture(t, route => route === 'runs/12' ? current : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    const container = f.$('[data-agent-changes]');
    assert.deepEqual([...container.querySelectorAll(':scope > [data-agent-content-change]')].map(card => card.dataset.agentContentChange), ['content-latest']);
    const history = f.$('[data-agent-content-history]');
    assert.equal(history.open, false);
    assert.equal(history.querySelector('summary').textContent, '历史方案（2）');
    assert.deepEqual([...history.querySelectorAll('[data-agent-content-change]')].map(card => card.dataset.agentContentChange), ['content-first', 'content-second']);
    assert.match(history.textContent, /First version/); assert.match(history.textContent, /Second version/);
    assert.doesNotMatch(f.$('[data-agent-content-change="content-latest"]').textContent, /First version|Second version/);
    history.open = true;
    current = { ...current, events: [{ time: '', type: 'info', label: '历史刷新', detail: '' }] };
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(f.$('[data-agent-content-history]').open, true);
    assert.equal(container.querySelectorAll('[data-agent-content-change]').length, 3);
    assert.equal(f.calls.some(call => call.route.includes('/content/')), false);
    assert.equal(changes.every(change => change.status === 'filled'), true);
});

test('content history keeps different articles, independent drafts and unresolved proposals visible', async t => {
    const changes = [
        contentProposal({ id: 'content-old' }),
        contentProposal({ id: 'pending-earlier', status: 'pending' }),
        contentProposal({ status: 'pending' }),
        contentProposal({ id: 'other-page', post_id: 8, post_type: 'page' }),
        contentProposal({ id: 'draft-first', kind: 'draft', post_id: 0, status: 'pending' }),
        contentProposal({ id: 'draft-second', kind: 'draft', post_id: 0, status: 'pending' }),
        ...['superseded', 'dismissed', 'cancelled'].map(status => contentProposal({ id: status, status })),
    ];
    const f = fixture(t, route => route === 'runs/12' ? task({ status: 'waiting', content_changes: changes }) : undefined,
        { writeTools: ['oyiso/apply-post-update', 'oyiso/create-post-draft'] }, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.deepEqual([...f.$('[data-agent-changes]').querySelectorAll(':scope > [data-agent-content-change]')].map(card => card.dataset.agentContentChange), ['pending-earlier', 'content-latest', 'other-page', 'draft-first', 'draft-second']);
    assert.equal(f.$('[data-agent-content-history] > summary').textContent, '历史方案（1）');
    assert.equal(f.$('[data-agent-content-history]').querySelectorAll('[data-agent-content-change]').length, 1);
    for (const id of ['superseded', 'dismissed', 'cancelled']) assert.equal(f.$(`[data-agent-content-change="${id}"]`), null);
    assert.equal(f.$('[data-agent-content-change="content-latest"] button').textContent, '保存到站点');
    for (const id of ['draft-first', 'draft-second']) assert.equal(f.$(`[data-agent-content-change="${id}"] button`).textContent, '创建草稿');
});

test('an applied historical content proposal remains reversible without replacing the latest proposal', async t => {
    const changes = [contentProposal({ id: 'content-applied', status: 'applied' }), contentProposal()];
    let current = task({ content_changes: changes });
    const f = fixture(t, route => {
        if (route === 'runs/12') return current;
        if (route === 'runs/12/content/content-applied') {
            current = task({ content_changes: [{ ...changes[0], status: 'reverted' }, changes[1]] });
            return current;
        }
    }, { writeTools: ['oyiso/revert-post-update'] }, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    f.$('[data-agent-content-history]').open = true;
    const button = f.$('[data-agent-content-change="content-applied"] button');
    assert.equal(button.textContent, '撤回修改'); assert.equal(button.disabled, false);
    button.click(); await settle();
    const request = f.calls.filter(call => call.route.includes('/content/'));
    assert.equal(request.length, 1); assert.equal(request[0].route, 'runs/12/content/content-applied');
    assert.equal(JSON.parse(request[0].options.body).action, 'revert');
    assert.equal(f.$('[data-agent-content-history]').open, true);
    assert.match(f.$('[data-agent-content-change="content-applied"]').textContent, /已撤回/);
    assert.equal(f.$('[data-agent-content-change="content-applied"] button'), null);
    assert.equal(f.$('[data-agent-changes] > [data-agent-content-change]').dataset.agentContentChange, 'content-latest');
});

test('expired editor proposals regenerate with current context and preserve unsent text and attachments', async t => {
    for (const type of ['post', 'page', 'product']) await t.test(type, async t => {
        const product = type === 'product', field = product ? 'description' : 'content', summary = product ? 'short_description' : 'excerpt';
        const before = { title: 'Current content', [field]: '<p>Original</p>', [summary]: '' };
        const latest = { ...before, [field]: '<p>My later edit</p>' };
        const change = { id: proposal().id, status: 'pending', before, after: { ...before, [field]: '<p>Old AI text</p>', [summary]: 'Old summary' }, ...(product ? { product_id: 7, name: 'Current content' } : { post_id: 7, post_type: type, kind: 'update', title: 'Current content' }) };
        const nextChange = { ...change, id: '87654321-4321-4321-4321-cba987654321', before: latest, after: { ...latest, [field]: '<p>Regenerated text</p>', [summary]: 'New summary' } };
        const key = product ? 'changes' : 'content_changes';
        let current = task({ status: 'waiting', [key]: [change] }), finish;
        const f = fixture(t, (route, options) => {
            if (route === 'runs/12') return current;
            if (route === 'runs' && options.method === 'POST') return new Promise(resolve => { finish = () => { current = task({ status: 'waiting', [key]: [nextChange] }); resolve(current); }; });
            if (route === `runs/12/content/${nextChange.id}`) {
                if (JSON.parse(options.body).action === 'editor') return { post_id: 7, expected: latest, fields: { [field]: nextChange.after[field], [summary]: nextChange.after[summary] }, signature: 'fill-fixture' };
                current = task({ [key]: [{ ...nextChange, status: 'filled' }] }); return current;
            }
        }, { writeTools: [`oyiso/apply-${product ? 'product' : 'post'}-update`], pageContext: { screen: type, post_id: 7, post_type: type, editor: 'classic', signature: 'context-fixture', fields: before } }, window => {
            window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
            window.document.body.insertAdjacentHTML('beforeend', '<div id="poststuff"><input id="title" value="Current content"><textarea id="content">&lt;p&gt;My later edit&lt;/p&gt;</textarea><textarea id="excerpt"></textarea></div>');
        });
        await settle();
        assert.doesNotMatch(f.$('[data-agent-changes]').textContent, /填入当前编辑器/);
        assert.equal(f.$('[data-agent-regenerate]').textContent, '按当前内容重新生成');
        const grants = f.window.oyisoAgent.writeTools;
        f.window.oyisoAgent.writeTools = []; f.$('[data-agent-regenerate]').click();
        assert.equal(f.calls.some(call => call.route === 'runs' && call.options.method === 'POST'), false);
        f.window.oyisoAgent.writeTools = grants;
        f.$('#oyiso-agent-prompt').value = 'My unsent follow-up'; f.$('#oyiso-agent-prompt').dispatchEvent(new f.window.Event('input', { bubbles: true }));
        Object.defineProperty(f.$('[data-agent-file]'), 'files', { value: [new f.window.File(['draft'], 'unsent.txt', { type: 'text/plain' })] });
        f.$('[data-agent-file]').dispatchEvent(new f.window.Event('change', { bubbles: true }));
        f.$('[data-agent-regenerate]').click(); f.$('[data-agent-regenerate]').click(); await settle();
        const requests = f.calls.filter(call => call.route === 'runs' && call.options.method === 'POST');
        assert.equal(requests.length, 1); assert.equal(f.$('[data-agent-regenerate]').disabled, true);
        const body = JSON.parse(requests[0].options.body);
        assert.equal(body.run_id, 12); assert.equal(JSON.parse(body.page_context).fields[field], latest[field]);
        assert.match(body.message, /上一方案的修改要求/); assert.match(body.message, /重新读取最新内容/);
        assert.match(body.message, new RegExp(`${({ post: '文章', page: '页面', product: '商品' })[type]}（#7）`));
        assert.equal(f.calls.some(call => call.route.includes('/content/')), false);
        assert.equal(f.$('#content').value, latest[field]);
        finish(); await settle();
        assert.equal(f.$('#oyiso-agent-prompt').value, 'My unsent follow-up');
        assert.match(f.$('[data-agent-attachments]').textContent, /unsent.txt/);
        assert.equal(f.$('[data-agent-regenerate]'), null);
        const fill = Array.from(f.window.document.querySelectorAll('[data-agent-changes] button')).find(button => button.textContent === '填入当前编辑器');
        assert.ok(fill); assert.equal(f.$('#content').value, latest[field]);
        fill.click(); await settle();
        assert.equal(f.$('#content').value, nextChange.after[field]);
        assert.match(f.$('[data-agent-changes]').textContent, /已填入编辑器/);
        assert.equal(f.calls.filter(call => call.route.includes('/content/')).length, 2);
    });
});

test('filled proposals can be restored to classic and block editors after a reload without saving', async t => {
    for (const type of ['post', 'page', 'product']) for (const editor of ['classic', 'gutenberg']) await t.test(`${type}: ${editor}`, async t => {
        const product = type === 'product', field = product ? 'description' : 'content', summary = product ? 'short_description' : 'excerpt';
        const saved = { title: 'Article', [field]: '<p>Saved body</p>', [summary]: '' };
        const before = { ...saved, [field]: '<p>Previous unsaved version</p>', [summary]: product ? '' : 'Previous unsaved summary' };
        const after = { ...before, [field]: '<p>Recovered AI version</p>' };
        const restoreFields = product ? { [field]: after[field] } : { [field]: after[field], [summary]: after[summary] };
        const baseline = values => Object.fromEntries(Object.keys(restoreFields).map(key => [key, values[key]]));
        const change = product ? { ...proposal(), product_id: 7, before: saved, editor_before: before, after, status: 'filled' } : contentProposal({ post_type: type, before, saved, after });
        const key = product ? 'changes' : 'content_changes';
        const values = { title: 'My later title', content: saved[field], excerpt: '' }; let saves = 0;
        const f = fixture(t, (route, options) => {
            if (route === 'runs/12') return task({ [key]: [change] });
            if (route === `runs/12/content/${change.id}`) {
                if (JSON.parse(options.body).action === 'filled') return task({ [key]: [change] });
                return { post_id: 7, expected: baseline(before), restore_from: [baseline(saved), baseline(after)], fields: restoreFields, signature: 'recovery-signature' };
            }
        }, { writeTools: [`oyiso/apply-${product ? 'product' : 'post'}-update`], pageContext: { screen: type, post_id: 7, post_type: type, editor, fields: saved } }, window => {
            window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
            window.document.body.insertAdjacentHTML('beforeend', '<div id="poststuff"><input id="title" value="My later title"><textarea id="content">&lt;p&gt;Saved body&lt;/p&gt;</textarea><textarea id="excerpt"></textarea></div>');
            if (editor === 'gutenberg') window.wp = { blocks: { parse: html => [{ html, isValid: true }] }, data: {
                select: () => ({ getCurrentPostId: () => 7, getEditedPostAttribute: name => values[name], getEditedPostContent: () => values.content, isEditedPostDirty: () => true }),
                dispatch: store => store === 'core/editor' ? { editPost: edits => Object.assign(values, edits), savePost: () => saves++ } : { resetBlocks: blocks => { values.content = blocks[0].html; } }, subscribe: () => () => {},
            } };
        });
        await settle();
        const button = [...f.$('[data-agent-changes]').querySelectorAll('button')].find(button => button.textContent === '重新填入当前编辑器');
        assert.ok(button); assert.equal(button.disabled, false);
        button.click(); button.click(); await settle();
        const dialog = f.$('[data-agent-refill-dialog]');
        assert.equal(dialog.open, true); assert.match(dialog.textContent, /重新填入当前编辑器.*手动保存/);
        assert.equal(f.window.document.activeElement, f.$('[data-agent-refill-cancel]'));
        assert.equal(f.calls.some(call => call.route.includes('/content/')), false);
        assert.equal(editor === 'gutenberg' ? values.content : f.$('#content').value, saved[field]);
        f.$('[data-agent-refill-cancel]').click();
        assert.equal(dialog.open, false); assert.equal(f.window.document.activeElement, button);
        assert.equal(f.calls.some(call => call.route.includes('/content/')), false);
        button.click();
        f.$('[data-agent-refill-confirm]').click(); f.$('[data-agent-refill-confirm]').click(); await settle();
        assert.equal(editor === 'gutenberg' ? values.content : f.$('#content').value, after[field]);
        assert.equal(editor === 'gutenberg' ? values.title : f.$('#title').value, 'My later title');
        assert.equal(editor === 'gutenberg' ? values.excerpt : f.$('#excerpt').value, after[summary]);
        assert.equal(saves, 0);
        const requests = f.calls.filter(call => call.route.includes('/content/'));
        assert.equal(requests.length, 2);
        assert.equal(JSON.parse(requests[0].options.body).action, 'editor');
        assert.equal(JSON.parse(requests[1].options.body).action, 'filled');
        assert.equal(JSON.parse(requests[1].options.body).signature, 'recovery-signature');
        assert.match(f.$('[data-agent-notice]').textContent, /尚未保存/);
    });
});

test('refill confirmation closes safely and cannot act on a changed conversation, grant or proposal', async t => {
    for (const mode of ['escape', 'conversation', 'permission', 'proposal', 'page']) await t.test(mode, async t => {
        const saved = { content: '<p>Saved body</p>' };
        const change = contentProposal({ title: '<img src=x> Article', saved, before: saved, after: { content: '<p>AI version</p>' } });
        let current = task({ content_changes: [change] });
        const f = fixture(t, route => route === 'runs/12' ? current : undefined,
            { writeTools: ['oyiso/apply-post-update'], pageContext: { screen: 'post', post_id: 7, post_type: 'post', editor: 'classic', fields: saved } }, window => {
                window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
                window.document.body.insertAdjacentHTML('beforeend', '<div id="poststuff"><textarea id="content">&lt;p&gt;Saved body&lt;/p&gt;</textarea></div>');
            });
        await settle();
        const button = f.$('[data-agent-changes] button'); button.click();
        const dialog = f.$('[data-agent-refill-dialog]');
        assert.equal(dialog.open, true); assert.equal(dialog.querySelector('img'), null);
        assert.match(dialog.textContent, /<img src=x> Article/);
        if (mode === 'escape') {
            dialog.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            dialog.dispatchEvent(new f.window.Event('cancel', { cancelable: true }));
            assert.equal(f.window.document.activeElement, button);
            assert.equal(f.$('#oyiso-agent-panel').hidden, false);
        } else if (mode === 'conversation') f.$('[data-agent-new]').click();
        else if (mode === 'permission') f.window.oyisoAgent.writeTools = [];
        else if (mode === 'page') f.window.oyisoAgentPage.snapshot = () => ({ post_id: 8, fields: {} });
        else {
            current = task({ content_changes: [{ ...change, status: 'superseded' }] });
            f.$('[data-agent-refresh]').click(); await settle();
        }
        f.$('[data-agent-refill-confirm]').click(); await settle();
        assert.equal(dialog.open, false);
        assert.equal(f.calls.some(call => call.route.includes('/content/')), false);
        assert.equal(f.$('#content').value, saved.content);
    });
});

test('edits made after opening refill confirmation are preserved when confirmation is accepted', async t => {
    const saved = { content: '<p>Saved body</p>' }, change = contentProposal({ saved, before: saved, after: { content: '<p>AI version</p>' } });
    const f = fixture(t, route => route === 'runs/12' ? task({ content_changes: [change] }) : route.includes('/content/') ? { post_id: 7, expected: saved, fields: change.after, restore_from: [change.after], signature: 'refill-confirmation' } : undefined,
        { writeTools: ['oyiso/apply-post-update'], pageContext: { screen: 'post', post_id: 7, post_type: 'post', editor: 'classic', fields: saved } }, window => {
            window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
            window.document.body.insertAdjacentHTML('beforeend', '<div id="poststuff"><textarea id="content">&lt;p&gt;Saved body&lt;/p&gt;</textarea></div>');
        });
    await settle(); f.$('[data-agent-changes] button').click();
    f.$('#content').value = 'My later edits';
    f.$('[data-agent-refill-confirm]').click(); await settle();
    assert.equal(f.$('#content').value, 'My later edits');
    assert.match(f.$('[data-agent-notice]').textContent, /编辑器内容已改变/);
    assert.equal(f.$('[data-agent-regenerate]').textContent, '按当前内容重新生成');
    const requests = f.calls.filter(call => call.route.includes('/content/'));
    assert.equal(requests.length, 1); assert.equal(JSON.parse(requests[0].options.body).action, 'editor');
});

test('recovery preserves newer editor changes and still requires the current write grant', async t => {
    const saved = { content: '<p>Saved body</p>' }, change = contentProposal({ saved, before: saved, after: { content: '<p>AI version</p>' } });
    const f = fixture(t, route => route === 'runs/12' ? task({ content_changes: [change] }) : undefined,
        { writeTools: ['oyiso/apply-post-update'], pageContext: { screen: 'post', post_id: 7, post_type: 'post', editor: 'classic', fields: saved } }, window => {
            window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
            window.document.body.insertAdjacentHTML('beforeend', '<div id="poststuff"><textarea id="content">My newer manual edit</textarea></div>');
        });
    await settle();
    assert.equal(f.$('[data-agent-regenerate]').textContent, '按当前内容重新生成');
    assert.equal([...f.$('[data-agent-changes]').querySelectorAll('button')].some(button => button.textContent === '重新填入当前编辑器'), false);
    assert.equal(f.$('#content').value, 'My newer manual edit');
    assert.equal(f.calls.some(call => call.route.includes('/content/')), false);
    f.window.oyisoAgent.writeTools = [];
    f.$('[data-agent-regenerate]').click(); await settle();
    assert.equal(f.calls.some(call => call.route === 'runs' && call.options.method === 'POST'), false);
});

test('a late editor conflict offers regeneration and a failed regeneration remains retryable', async t => {
    const before = { title: 'Article', content: '<p>Original</p>', excerpt: '' };
    const change = { id: proposal().id, post_id: 7, post_type: 'post', kind: 'update', title: 'Article', status: 'pending', before, after: { ...before, content: '<p>AI text</p>' } };
    const current = task({ status: 'waiting', content_changes: [change] });
    const f = fixture(t, (route, options) => route === 'runs/12' ? current : route === 'runs' && options.method === 'POST' ? { ok: false, status: 503, body: { message: '模型服务暂不可用' } } : route.includes('/content/') ? { post_id: 7, expected: before, fields: { content: change.after.content }, signature: 'fill-fixture' } : undefined,
        { writeTools: ['oyiso/apply-post-update'], pageContext: { screen: 'post', post_id: 7, post_type: 'post', editor: 'classic', fields: before } }, window => {
            window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
            window.document.body.insertAdjacentHTML('beforeend', '<div id="poststuff"><input id="title" value="Article"><textarea id="content">&lt;p&gt;Original&lt;/p&gt;</textarea><textarea id="excerpt"></textarea></div>');
        });
    await settle();
    f.$('#content').value = '<p>Late manual edit</p>';
    Array.from(f.window.document.querySelectorAll('[data-agent-changes] button')).find(button => button.textContent === '填入当前编辑器').click(); await settle();
    assert.match(f.$('[data-agent-notice]').textContent, /旧方案已过期/); assert.ok(f.$('[data-agent-regenerate]'));
    f.$('[data-agent-regenerate]').click(); await settle();
    assert.match(f.$('[data-agent-notice]').textContent, /模型服务暂不可用/);
    assert.equal(f.$('[data-agent-regenerate]').disabled, false); assert.equal(f.$('#content').value, '<p>Late manual edit</p>');
    assert.equal(f.calls.filter(call => call.route.includes('/content/')).length, 1);
});

test('example and keyboard submit use current prompt and CSRF-protected REST', async t => {
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? task({ conversation: [{ role: 'user', text: '检查商品' }] }) : route === 'runs/12' ? task({ conversation: [{ role: 'assistant', text: '已读取商品。' }] }) : undefined);
    f.$('[data-agent-example]').click();
    assert.match(f.$('#oyiso-agent-prompt').value, /商品/);
    f.$('#oyiso-agent-prompt').value = '  检查商品  ';
    const enter = new f.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    f.$('#oyiso-agent-prompt').dispatchEvent(enter);
    assert.equal(enter.defaultPrevented, true);
    await settle();
    const send = f.calls.find(call => call.route === 'runs' && call.options.method === 'POST');
    assert.equal(JSON.parse(send.options.body).message, '检查商品');
    assert.equal(send.options.headers['X-WP-Nonce'], 'fixture-nonce');
    assert.equal(send.options.credentials, 'same-origin');
    assert.equal(f.$('#oyiso-agent-prompt').value, '');
    assert.match(f.$('[data-agent-messages]').textContent, /已读取商品/);
});

test('Shift Enter keeps native newline behavior without submitting', async t => {
    const f = fixture(t);
    f.$('#oyiso-agent-prompt').value = 'Keep writing';
    const enter = new f.window.KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, bubbles: true, cancelable: true });
    f.$('#oyiso-agent-prompt').dispatchEvent(enter); await settle();
    assert.equal(enter.defaultPrevented, false);
    assert.equal(f.calls.some(call => call.route === 'runs' && call.options.method === 'POST'), false);
});

test('Ctrl Meta and Alt Enter insert a newline at the selection and preserve the draft and length limit', async t => {
    for (const modifier of ['ctrlKey', 'metaKey', 'altKey']) {
        const f = fixture(t);
        const input = f.$('#oyiso-agent-prompt'); input.value = 'one two'; input.setSelectionRange(4, 7);
        const enter = () => new f.window.KeyboardEvent('keydown', { key: 'Enter', [modifier]: true, bubbles: true, cancelable: true });
        const event = enter(); input.dispatchEvent(event); await settle();
        assert.equal(event.defaultPrevented, true);
        assert.equal(input.value, 'one \n');
        assert.equal(input.selectionStart, 5);
        assert.equal(JSON.parse(f.window.sessionStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:draft')).text, 'one \n');
        input.value = 'x'.repeat(input.maxLength); input.setSelectionRange(input.maxLength, input.maxLength);
        input.dispatchEvent(enter());
        assert.equal(input.value.length, input.maxLength);
        assert.equal(f.calls.some(call => call.route === 'runs' && call.options.method === 'POST'), false);
    }
});

test('Enter used to confirm IME composition never submits until composition has ended', async t => {
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? task() : undefined);
    const input = f.$('#oyiso-agent-prompt'); input.value = '你好';
    input.dispatchEvent(new f.window.CompositionEvent('compositionstart', { bubbles: true }));
    input.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    input.dispatchEvent(new f.window.CompositionEvent('compositionend', { bubbles: true }));
    input.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Enter', isComposing: true, bubbles: true }));
    input.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Enter', keyCode: 229, bubbles: true }));
    await settle();
    assert.equal(f.calls.some(call => call.route === 'runs' && call.options.method === 'POST'), false);
    assert.equal(input.value, '你好');
    input.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true })); await settle();
    assert.equal(f.calls.filter(call => call.route === 'runs' && call.options.method === 'POST').length, 1);
});

test('history is directly accessible in the toolbar and settings retain keyboard navigation', async t => {
    const f = fixture(t);
    const settings = f.$('[data-agent-settings-menu]'); const toggle = f.$('[data-agent-settings-toggle]');
    for (const selector of ['[data-agent-layout-choice="left"]', '[data-agent-reset]']) {
        assert.equal(settings.contains(f.$(selector)), true);
        assert.ok(f.$(selector).querySelector('svg'));
        assert.ok(f.$(selector).textContent.trim());
    }
    assert.equal(f.$('[data-agent-history-toggle]').parentElement, f.$('.oyiso-agent-panel-actions'));
    assert.equal(settings.contains(f.$('[data-agent-history-toggle]')), false);
    assert.equal(toggle.parentElement.previousElementSibling, f.$('[data-agent-refresh]'));
    assert.equal(f.$('[data-agent-fullscreen-toggle]').nextElementSibling, f.$('[data-agent-close]'));
    toggle.click(); assert.equal(settings.hidden, false); assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    settings.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    assert.equal(f.window.document.activeElement, f.$('[data-agent-layout-choice="right"]'));
    settings.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(settings.hidden, true); assert.equal(f.window.document.activeElement, toggle);
    toggle.click(); f.window.document.body.click(); assert.equal(settings.hidden, true);
    assert.equal(f.$('[data-agent-refresh]').disabled, true);
    await settle();
});

test('settings menu opens upwards within the viewport when the header is at the bottom', t => {
    const f = fixture(t, undefined, {}, window => {
        geometry(window);
        const toggle = window.document.querySelector('[data-agent-settings-toggle]');
        toggle.getBoundingClientRect = () => ({ top: 940, bottom: 972, left: 8, right: 40, width: 32, height: 32 });
        Object.defineProperties(window.document.querySelector('[data-agent-settings-menu]'), { scrollHeight: { get: () => 190 }, offsetWidth: { get: () => 196 } });
    });
    f.$('[data-agent-settings-toggle]').click();
    const menu = f.$('[data-agent-settings-menu]');
    assert.equal(menu.style.top, 'auto');
    assert.equal(menu.style.bottom, 'calc(100% + 8px)');
    assert.equal(menu.style.maxHeight, '928px');
    assert.equal(menu.style.transform, 'translateX(161px)');
    assert.equal(f.$('#oyiso-agent-panel').hasAttribute('data-agent-menu-open'), true);
    f.$('[data-agent-settings-toggle]').click();
    assert.equal(f.$('#oyiso-agent-panel').hasAttribute('data-agent-menu-open'), false);
});

test('refresh reloads only the current conversation and preserves draft model choice and geometry', async t => {
    let reads = 0; let resolve;
    const f = fixture(t, route => route === 'runs/12' ? (++reads === 1 ? task({ selection: 'fixture|model-a', conversation: [{ role: 'assistant', text: 'Old' }] }) : new Promise(done => { resolve = done; })) : undefined, {}, window => {
        geometry(window); window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
    });
    await settle();
    const input = f.$('#oyiso-agent-prompt'); input.value = 'Keep my draft';
    const model = f.$('[data-agent-model]'); model.value = 'second|model-b';
    const before = f.$('#oyiso-agent-panel').style.cssText;
    f.$('[data-agent-refresh]').click(); f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(reads, 2); assert.equal(f.$('[data-agent-refresh]').disabled, true);
    resolve(task({ selection: 'fixture|model-a', conversation: [{ role: 'assistant', text: 'Reloaded' }] })); await settle();
    assert.match(f.$('[data-agent-messages]').textContent, /Reloaded/);
    assert.equal(input.value, 'Keep my draft'); assert.equal(model.value, 'second|model-b');
    assert.equal(f.$('#oyiso-agent-panel').style.cssText, before);
    assert.equal(f.$('[data-agent-refresh]').disabled, false);
    assert.equal(f.calls.some(call => call.options.method === 'POST'), false);
});

test('a stalled conversation read times out and refresh remains available for retry', async t => {
    let abortRead; let reads = 0;
    const f = fixture(t, (route, options) => route === 'runs/12' ? (++reads === 1 ? new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new f.window.DOMException('Aborted', 'AbortError')))) : task({ conversation: [{ role: 'assistant', text: 'Recovered' }] })) : undefined, {}, window => {
        const originalTimeout = window.setTimeout.bind(window);
        window.setTimeout = (callback, delay, ...args) => { if (delay === 15000) abortRead = callback; return originalTimeout(callback, delay, ...args); };
        window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
    });
    abortRead(); await settle();
    assert.match(f.$('[data-agent-notice]').textContent, /读取对话超时/);
    assert.equal(f.$('[data-agent-refresh]').disabled, false);
    f.$('[data-agent-refresh]').click(); await settle();
    assert.match(f.$('[data-agent-messages]').textContent, /Recovered/);
});

test('streamed text updates the same bubble and is replaced once by the final reply', async t => {
    let state = task({ status: 'running', conversation: [{ role: 'user', text: '你好' }], partial: { text: '你', model: 'model-a' } });
    const f = fixture(t, route => route === 'runs/12' ? state : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle(); const bubble = f.$('[data-agent-partial]');
    assert.equal(bubble.querySelector('.oyiso-agent-message-content').textContent, '你');
    assert.equal(f.$('[data-agent-send]').disabled, true);
    state = { ...state, partial: { text: '你好 <img src=x>', model: 'model-a' } };
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(f.$('[data-agent-partial]'), bubble); assert.equal(bubble.querySelector('img'), null);
    assert.match(bubble.textContent, /你好/);
    state = task({ conversation: [{ role: 'user', text: '你好' }, { role: 'assistant', text: '你好', model: 'model-a' }] });
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(f.$('[data-agent-partial]'), null);
    assert.equal(f.window.document.querySelectorAll('.is-assistant').length, 1);
    assert.equal(f.$('[data-agent-messages]').getAttribute('aria-busy'), 'false');
    assert.equal(f.calls.some(call => call.route.endsWith('/step')), false);
});

const liveSupport = window => { window.TextDecoder = TextDecoder; window.ReadableStream = ReadableStream; };
function liveWire() {
    let controller;
    const sse = new ReadableStream({ start(value) { controller = value; } });
    return { sse, emit(type, value) { controller.enqueue(new TextEncoder().encode(`event: ${type}\ndata: ${JSON.stringify(value)}\n\n`)); }, close() { controller.close(); } };
}

test('sending gives immediate feedback and generation replaces send with stop while preserving the next draft', async t => {
    const wire = liveWire(); let accept;
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? new Promise(resolve => { accept = () => resolve(wire); }) : undefined, {}, liveSupport);
    const input = f.$('#oyiso-agent-prompt'), send = f.$('[data-agent-send]'), stop = f.$('[data-agent-cancel]');
    input.value = 'Rewrite this article'; send.click();
    assert.match(f.$('[data-agent-messages]').textContent, /Rewrite this article/);
    assert.equal(f.$('[data-agent-pending]').nextElementSibling, f.$('.oyiso-agent-progress').parentElement);
    assert.equal(f.$('.oyiso-agent-progress').previousElementSibling.textContent, '测试连接 / model-a');
    assert.equal(f.$('.oyiso-agent-empty'), null);
    assert.equal(f.$('[data-agent-response-status]').hidden, false);
    assert.match(f.$('[data-agent-response-label]').textContent, /正在发送/);
    assert.equal(f.$('[data-agent-messages]').getAttribute('aria-busy'), 'true');
    assert.equal(send.hidden, false); assert.equal(send.disabled, true); assert.equal(stop.hidden, true);
    accept(); await settle();
    const conversation = [{ role: 'user', text: 'Rewrite this article' }];
    wire.emit('state', task({ status: 'queued', step: 0, selection: 'fixture|model-a', conversation })); await settle();
    assert.equal(f.$('[data-agent-pending]'), null);
    assert.equal(f.window.document.querySelectorAll('.is-user').length, 1);
    assert.match(f.$('[data-agent-response-label]').textContent, /准备回复/);
    assert.equal(send.hidden, true); assert.equal(stop.hidden, false); assert.equal(stop.disabled, false);
    assert.equal(input.disabled, false);
    input.value = 'My next request'; input.dispatchEvent(new f.window.Event('input', { bubbles: true }));
    wire.emit('state', task({ status: 'running', selection: 'fixture|model-a', conversation })); await settle();
    assert.match(f.$('[data-agent-response-label]').textContent, /思考/);
    input.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true })); await settle();
    assert.equal(f.calls.filter(call => call.route === 'runs').length, 1);
    wire.emit('text', { id: 12, step: 1, model: 'model-a', delta: 'Rewritten article' }); await settle();
    assert.equal(f.$('[data-agent-response-status]').hidden, true);
    wire.emit('done', task({ selection: 'fixture|model-a', conversation: [...conversation, { role: 'assistant', text: 'Rewritten article', model: 'model-a' }] })); wire.close(); await settle();
    assert.equal(send.hidden, false); assert.equal(send.disabled, false); assert.equal(stop.hidden, true);
    assert.equal(input.value, 'My next request'); assert.equal(f.$('[data-agent-messages]').getAttribute('aria-busy'), 'false');
});

test('waiting for a proposal confirmation exposes send and the card actions without a generation stop button', async t => {
    const f = fixture(t, route => route === 'runs/12' ? task({ status: 'waiting', changes: [proposal()] }) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.equal(f.$('[data-agent-cancel]').hidden, true);
    assert.equal(f.$('[data-agent-send]').hidden, false);
    assert.equal(f.$('[data-agent-send]').disabled, false);
    assert.equal(f.$('[data-agent-response-status]').hidden, true);
    assert.match(f.$('[data-agent-changes]').textContent, /应用修改/);
});

test('stop submits once and keeps its pending state until the cancellation response arrives', async t => {
    let finish;
    const running = task({ status: 'running' });
    const f = fixture(t, route => route === 'runs/12' ? running : route === 'runs/12/cancel' ? new Promise(resolve => { finish = () => resolve(task({ status: 'cancelled' })); }) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    const stop = f.$('[data-agent-cancel]'); stop.click(); stop.click(); await settle();
    assert.equal(f.calls.filter(call => call.route === 'runs/12/cancel').length, 1);
    assert.equal(stop.disabled, true); assert.equal(stop.getAttribute('aria-label'), '正在停止');
    assert.equal(f.$('[data-agent-send]').hidden, true);
    assert.match(f.$('[data-agent-response-label]').textContent, /正在停止/);
    finish(); await settle();
    assert.equal(stop.hidden, true); assert.equal(f.$('[data-agent-send]').hidden, false);
    assert.equal(f.$('[data-agent-response-status]').hidden, true);
});

test('clicking composer space focuses the draft at its existing selection without intercepting controls', t => {
    const f = fixture(t), input = f.$('#oyiso-agent-prompt');
    input.value = 'Keep my caret'; input.setSelectionRange(3, 3);
    f.$('.oyiso-agent-composer-surface').dispatchEvent(new f.window.MouseEvent('pointerdown', { button: 0, bubbles: true, cancelable: true }));
    assert.equal(f.window.document.activeElement, input); assert.equal(input.selectionStart, 3);
    const model = f.$('[data-agent-model]'); model.focus();
    model.dispatchEvent(new f.window.MouseEvent('pointerdown', { button: 0, bubbles: true, cancelable: true }));
    assert.equal(f.window.document.activeElement, model);
    const attach = f.$('[data-agent-attach]'); attach.focus();
    attach.dispatchEvent(new f.window.MouseEvent('pointerdown', { button: 0, bubbles: true, cancelable: true }));
    assert.equal(f.window.document.activeElement, attach);
});

test('an older cancellation cannot disable or unlock the stop control of another conversation', async t => {
    const pending = new Map();
    const f = fixture(t, (route, options) => {
        if (route.endsWith('/cancel')) return new Promise(resolve => pending.set(route, resolve));
        if (route === 'runs' && options.method === 'POST' || route === 'runs/13') return task({ id: 13, status: 'running', conversation: [{ role: 'user', text: 'Another task' }] });
        if (route === 'runs/12') return task({ status: 'running' });
    }, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle(); f.$('[data-agent-cancel]').click(); await settle();
    f.$('[data-agent-new]').click(); f.$('#oyiso-agent-prompt').value = 'Another task'; f.$('[data-agent-send]').click(); await settle();
    const stop = f.$('[data-agent-cancel]'); assert.equal(stop.hidden, false); assert.equal(stop.disabled, false);
    stop.click(); await settle(); assert.equal(stop.disabled, true);
    pending.get('runs/12/cancel')(task({ status: 'cancelled' })); await settle();
    assert.equal(stop.disabled, true); assert.equal(stop.hidden, false);
    pending.get('runs/13/cancel')(task({ id: 13, status: 'cancelled' })); await settle();
    assert.equal(stop.hidden, true); assert.equal(f.$('[data-agent-status]').textContent, '已取消');
});

test('a fully buffered fresh reply reveals over frames while saved history stays immediate', async t => {
    let clock;
    const text = 'A fully buffered answer should still arrive comfortably. '.repeat(16).trim();
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? task({ conversation: [{ role: 'user', text: 'Explain' }, { role: 'assistant', text }] }) : undefined, {}, window => { clock = frameClock(window); }, { motion: true });
    f.$('#oyiso-agent-prompt').value = 'Explain'; f.$('[data-agent-send]').click(); await settle();
    const content = f.$('.is-assistant .oyiso-agent-message-content');
    assert.equal(content.textContent, 'A'); assert.equal(f.$('[data-agent-send]').disabled, false);
    clock.tick(); assert.ok(content.textContent.length > 1 && content.textContent.length < text.length);
    clock.finish(); assert.equal(content.textContent, text);
    assert.equal(f.$('[data-agent-response-status]').hidden, true);
});

test('restoring a saved conversation shows a spinner immediately and never flashes welcome content', async t => {
    let resolve;
    const f = fixture(t, route => route === 'runs/12' ? new Promise(done => { resolve = done; }) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    assert.ok(f.$('[data-agent-loading] .oyiso-agent-loading-spinner'));
    assert.equal(f.$('.oyiso-agent-empty'), null);
    assert.equal(f.$('[data-agent-messages]').getAttribute('aria-busy'), 'true');
    assert.equal(f.$('[data-agent-send]').disabled, true);
    resolve(task({ conversation: [{ role: 'assistant', text: 'Saved reply' }] })); await settle();
    assert.equal(f.$('[data-agent-loading]'), null);
    assert.equal(f.$('[data-agent-messages]').getAttribute('aria-busy'), 'false');
    assert.match(f.$('[data-agent-messages]').textContent, /Saved reply/);
    const fresh = fixture(t); assert.ok(fresh.$('.oyiso-agent-empty')); assert.equal(fresh.$('[data-agent-loading]'), null);
});

test('a failed saved conversation read stops the spinner without falling back to welcome content', async t => {
    const f = fixture(t, route => route === 'runs/12' ? { ok: false, body: { message: 'Read failed' } } : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.equal(f.$('[data-agent-loading]'), null); assert.equal(f.$('.oyiso-agent-empty'), null);
    assert.equal(f.$('[data-agent-messages]').getAttribute('aria-busy'), 'false');
    assert.match(f.$('[data-agent-messages]').textContent, /刷新重试/);
    assert.equal(f.$('[data-agent-refresh]').disabled, false);
});

test('a live send paints text before completion and refresh cannot replace it with an older snapshot', async t => {
    const wire = liveWire();
    const conversation = [{ role: 'user', text: '你好' }];
    const initial = task({ status: 'queued', selection: 'fixture|model-a', conversation });
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? wire : route === 'runs/12' ? task({ conversation: [{ role: 'assistant', text: 'Stale snapshot' }] }) : undefined, {}, liveSupport);
    f.$('#oyiso-agent-prompt').value = '你好'; f.$('[data-agent-send]').click(); await settle();
    const request = f.calls.find(call => call.route === 'runs');
    assert.equal(request.options.headers.Accept, 'text/event-stream'); assert.equal(request.options.headers['X-WP-Nonce'], 'fixture-nonce');
    wire.emit('state', initial); wire.emit('text', { id: 12, step: 1, model: 'model-a', delta: '你' }); await settle();
    const bubble = f.$('[data-agent-partial]'); assert.equal(bubble.querySelector('.oyiso-agent-message-content').textContent, '你');
    assert.equal(f.$('#oyiso-agent-prompt').value, ''); assert.equal(f.$('[data-agent-send]').disabled, true);
    assert.equal(f.calls.filter(call => call.route === 'runs/12').length, 0);
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(f.$('[data-agent-partial]'), bubble); assert.doesNotMatch(f.$('[data-agent-messages]').textContent, /Stale snapshot/);
    wire.emit('text', { id: 12, step: 1, model: 'model-a', delta: '好 **世界**' }); await settle();
    assert.equal(f.$('[data-agent-partial]'), bubble); assert.equal(bubble.querySelector('strong').textContent, '测试连接 / model-a');
    assert.equal(bubble.querySelector('.oyiso-agent-message-content strong').textContent, '世界');
    const final = task({ selection: 'fixture|model-a', conversation: [...conversation, { role: 'assistant', model: 'model-a', text: '你好 **世界**' }] });
    wire.emit('state', final); wire.emit('done', final); wire.close(); await settle();
    assert.equal(f.$('[data-agent-partial]'), null); assert.equal(f.window.document.querySelectorAll('.is-assistant').length, 1);
    assert.equal(f.$('[data-agent-send]').disabled, false);
    assert.equal(f.calls.filter(call => call.route === 'runs' && call.options.method === 'POST').length, 1);
});

test('old live events cannot overwrite a new conversation or unlock its pending submission', async t => {
    const first = liveWire(), second = liveWire(); let posts = 0;
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? (++posts === 1 ? first : second) : undefined, {}, liveSupport);
    f.$('#oyiso-agent-prompt').value = 'First'; f.$('[data-agent-send]').click(); await settle();
    first.emit('state', task({ status: 'queued', conversation: [{ role: 'user', text: 'First' }] })); await settle();
    f.$('[data-agent-new]').click(); f.$('#oyiso-agent-prompt').value = 'Second'; f.$('[data-agent-send]').click(); await settle();
    first.emit('text', { id: 12, step: 1, model: 'model-a', delta: 'Old reply' }); first.emit('done', task({ conversation: [{ role: 'assistant', text: 'Old reply' }] })); first.close(); await settle();
    assert.doesNotMatch(f.$('[data-agent-messages]').textContent, /Old reply/);
    assert.equal(f.$('[data-agent-send]').disabled, true); assert.equal(f.$('#oyiso-agent-prompt').value, 'Second');
    second.emit('state', task({ id: 13, status: 'queued', conversation: [{ role: 'user', text: 'Second' }] }));
    second.emit('done', task({ id: 13, conversation: [{ role: 'assistant', text: 'New reply' }] })); second.close(); await settle();
    assert.match(f.$('[data-agent-messages]').textContent, /New reply/); assert.equal(f.$('[data-agent-send]').disabled, false);
});

test('an interrupted live response resumes saved progress without submitting the message again', async t => {
    const wire = liveWire(); let reads = 0;
    const running = task({ status: 'running', partial: { model: 'model-a', text: '已收到' }, conversation: [{ role: 'user', text: 'Hello' }] });
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? wire : route === 'runs/12' ? (++reads, running) : undefined, {}, liveSupport);
    f.$('#oyiso-agent-prompt').value = 'Hello'; f.$('[data-agent-send]').click(); await settle();
    wire.emit('state', { ...running, status: 'queued', partial: undefined }); wire.emit('text', { id: 12, step: 1, model: 'model-a', delta: '已收到' }); await settle();
    wire.close(); await settle();
    assert.equal(reads, 1); assert.match(f.$('[data-agent-partial]').textContent, /已收到/);
    assert.equal(f.calls.filter(call => call.route === 'runs' && call.options.method === 'POST').length, 1);
    const restored = fixture(t, route => route === 'runs/12' ? running : undefined, {}, window => { liveSupport(window); window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'); });
    await settle(); assert.match(restored.$('[data-agent-partial]').textContent, /已收到/);
    assert.equal(restored.calls.some(call => call.options.method === 'POST'), false);
});

test('a late terminal frame cannot overwrite a newer reply in the same conversation', async t => {
    const first = liveWire(), second = liveWire(); let posts = 0;
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? (++posts === 1 ? first : second) : undefined, {}, liveSupport);
    f.$('#oyiso-agent-prompt').value = 'First'; f.$('[data-agent-send]').click(); await settle();
    first.emit('state', task({ status: 'queued', conversation: [{ role: 'user', text: 'First' }] }));
    first.emit('state', task({ conversation: [{ role: 'assistant', text: 'First reply' }] })); await settle();
    f.$('#oyiso-agent-prompt').value = 'Next'; f.$('[data-agent-send]').click(); await settle();
    second.emit('state', task({ status: 'queued', conversation: [{ role: 'user', text: 'Next' }] }));
    second.emit('text', { id: 12, step: 1, model: 'model-a', delta: 'New content' }); await settle();
    first.emit('done', task({ conversation: [{ role: 'assistant', text: 'Obsolete first reply' }] })); first.close(); await settle();
    assert.match(f.$('[data-agent-partial]').textContent, /New content/);
    assert.doesNotMatch(f.$('[data-agent-messages]').textContent, /Obsolete/);
    const final = task({ conversation: [{ role: 'assistant', text: 'New content' }] });
    second.emit('done', final); second.close(); await settle();
    assert.match(f.$('[data-agent-messages]').textContent, /New content/);
});

test('late live text cannot revive a cancelled reply', async t => {
    const wire = liveWire(); const cancelled = task({ status: 'cancelled', conversation: [{ role: 'user', text: 'Hello' }] });
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? wire : route === 'runs/12/cancel' || route === 'runs/12' ? cancelled : undefined, {}, liveSupport);
    f.$('#oyiso-agent-prompt').value = 'Hello'; f.$('[data-agent-send]').click(); await settle();
    wire.emit('state', { ...cancelled, status: 'queued' }); wire.emit('text', { id: 12, step: 1, model: 'model-a', delta: 'Started' }); await settle();
    f.$('[data-agent-cancel]').click(); await settle();
    wire.emit('text', { id: 12, step: 1, model: 'model-a', delta: 'Must be ignored' }); await settle();
    assert.equal(f.$('[data-agent-status]').textContent, '已取消');
    assert.doesNotMatch(f.$('[data-agent-messages]').textContent, /Must be ignored/);
    wire.emit('done', cancelled); wire.close(); await settle();
    assert.equal(f.$('[data-agent-status]').textContent, '已取消');
});

test('assistant Markdown renders structure with bounded type while user input stays plain text', async t => {
    const text = '# 大标题\n\n## 小标题\n\n正文 **加粗** 和 `inline`。\n\n- 项目一\n- 项目二\n\n> 引用\n\n```js\nconst x = "<tag>";\n```\n\n| 名称 | 值 |\n| --- | --- |\n| 模型 | model-a |';
    const f = fixture(t, route => route === 'runs/12' ? task({ conversation: [{ role: 'user', text: '**我的原文**' }, { role: 'assistant', text }] }) : undefined, {}, window => {
        window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
        const style = window.document.createElement('style'); style.textContent = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/agent.css'), 'utf8'); window.document.head.append(style);
    });
    await settle();
    const assistant = f.$('.is-assistant .oyiso-agent-message-content');
    assert.equal(assistant.querySelector('h1').textContent, '大标题');
    assert.equal(assistant.querySelector('strong').textContent, '加粗');
    assert.equal(assistant.querySelectorAll('ul > li').length, 2);
    assert.equal(assistant.querySelector('blockquote').textContent.trim(), '引用');
    assert.match(assistant.querySelector('pre code').textContent, /<tag>/);
    assert.equal(assistant.querySelectorAll('table td').length, 2);
    assert.equal(assistant.querySelector('.oyiso-agent-markdown-table').tabIndex, 0);
    assert.equal(f.window.getComputedStyle(assistant).fontSize, '14px');
    assert.equal(f.window.getComputedStyle(assistant.querySelector('h1')).fontSize, '20px');
    assert.equal(f.window.getComputedStyle(assistant.querySelector('pre')).whiteSpace, 'pre');
    assert.equal(f.$('.is-user .oyiso-agent-message-content').textContent, '**我的原文**');
    assert.equal(f.$('.is-user .oyiso-agent-message-content strong'), null);
});

test('Markdown blocks raw HTML unsafe links and automatic external image loads including in partial replies', async t => {
    let state = task({ status: 'running', partial: { model: 'model-a', text: '**安全**\n\n<img src=x onerror=alert(1)>\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1)) [entity](jav&#x61;script:alert(1)) [file](file:///secret)\n\n![图片](https://example.com/a.png) [来源](https://example.com) [邮件](mailto:hello@example.com)' } });
    const f = fixture(t, route => route === 'runs/12' ? state : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    const content = f.$('[data-agent-partial] .oyiso-agent-message-content');
    assert.equal(content.querySelector('strong').textContent, '安全');
    assert.equal(content.querySelector('img,script,iframe,style'), null);
    assert.equal(content.querySelectorAll('a').length, 3);
    for (const link of content.querySelectorAll('a')) {
        assert.match(link.href, /^(https?:|mailto:)/);
        assert.equal(link.rel, 'noopener noreferrer');
    }
    const strong = content.querySelector('strong');
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(content.querySelector('strong'), strong, 'unchanged streaming text preserves rendered nodes');
    state = { ...state, partial: { model: 'model-a', text: '**完整加粗**\n\n- 新项目' } };
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(f.$('[data-agent-partial] .oyiso-agent-message-content'), content);
    assert.equal(content.querySelector('li').textContent, '新项目');
});

test('streaming Markdown keeps completed blocks and text nodes while the last paragraph grows', async t => {
    let state = task({ status: 'running', partial: { model: 'model-a', text: '# 标题\n\n固定 **段落**。\n\n正在' } });
    const f = fixture(t, route => route === 'runs/12' ? state : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    const content = f.$('[data-agent-partial] .oyiso-agent-message-content');
    const heading = content.querySelector('h1'), strong = content.querySelector('strong'), tail = content.querySelector('p:last-child').firstChild;
    state = { ...state, partial: { model: 'model-a', text: '# 标题\n\n固定 **段落**。\n\n正在逐步补充文字。' } };
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(content.querySelector('h1'), heading);
    assert.equal(content.querySelector('strong'), strong);
    assert.equal(content.querySelector('p:last-child').firstChild, tail);
    assert.equal(tail.data, '正在逐步补充文字。');
    assert.equal(content.querySelector('.oyiso-agent-stream-caret').parentElement, content.querySelector('p:last-child'));
});

test('progress events preserve the existing conversation and streaming bubble', async t => {
    let state = task({ status: 'running', conversation: [{ role: 'assistant', text: 'Earlier reply' }], partial: { model: 'model-a', text: 'Current reply' } });
    const f = fixture(t, route => route === 'runs/12' ? state : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    const earlier = f.$('.is-assistant'), partial = f.$('[data-agent-partial]');
    state = { ...state, events: [{ type: 'info', label: '模型已响应', detail: 'model-a' }] };
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(f.$('.is-assistant'), earlier);
    assert.equal(f.$('[data-agent-partial]'), partial);
    assert.match(f.$('[data-agent-events]').textContent, /模型已响应/);
});

test('execution records stay before their own replies, survive refresh and retain separate statuses and usage', async t => {
    const firstEvents = [{ type: 'info', label: 'First turn tool', detail: 'First detail' }];
    const secondEvents = [{ type: 'info', label: 'Second turn tool', detail: 'Second detail' }];
    const conversation = [{ role: 'user', text: 'First request' }, { role: 'assistant', text: 'First answer' }, { role: 'user', text: 'Second request' }];
    const executions = [{ index: 0, status: 'completed', step: 2, tokens: 80, events: firstEvents }, { index: 2, status: 'running', step: 1, tokens: 0, events: secondEvents }];
    let state = task({ status: 'running', tokens: 80, conversation, executions, events: [...firstEvents, ...secondEvents], partial: { model: 'model-a', text: 'Second reply' } });
    const restore = window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
    const f = fixture(t, route => route === 'runs/12' ? state : undefined, {}, restore);
    await settle();
    const first = f.$('[data-execution-index="0"]'), current = f.$('[data-execution-index="2"]');
    assert.equal(first.parentElement, f.$('[data-agent-message-index="1"]')); assert.equal(current.parentElement, f.$('[data-agent-partial]'));
    assert.equal(f.$('[data-agent-message-index="0"]').nextElementSibling, first.parentElement);
    assert.equal(first.previousElementSibling.className, 'oyiso-agent-message-role');
    assert.equal(f.$('[data-agent-message-index="2"]').nextElementSibling, current.parentElement);
    assert.equal(current.nextElementSibling.className, 'oyiso-agent-message-content');
    assert.match(first.textContent, /First turn tool/); assert.doesNotMatch(first.textContent, /Second turn tool/);
    assert.match(current.textContent, /Second turn tool/); assert.doesNotMatch(current.textContent, /First turn tool/);
    assert.match(first.textContent, /80 Token/); assert.equal(f.window.document.querySelectorAll('[data-agent-status]').length, 1);
    assert.equal(f.$('[data-agent-progress-parking]').childElementCount, 0);
    first.open = true; current.open = true;
    const scroll = f.$('.oyiso-agent-scroll');
    Object.defineProperties(scroll, { scrollHeight: { get: () => 1000 + f.$('[data-agent-messages]').textContent.length * 4 }, clientHeight: { value: 200 } });
    scroll.scrollTop = scroll.scrollHeight - scroll.clientHeight; const bottom = scroll.scrollTop;
    state = { ...state, events: [...state.events, { type: 'success', label: 'Second done' }], executions: [executions[0], { ...executions[1], events: [...secondEvents, { type: 'success', label: 'Second done', detail: 'Additional execution detail. '.repeat(15) }] }] };
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(f.$('[data-execution-index="0"]'), first); assert.equal(first.open, true);
    assert.equal(f.$('[data-execution-index="2"]'), current); assert.equal(current.open, true);
    assert.ok(scroll.scrollTop > bottom, 'new execution details keep a reader at the bottom following the reply');
    scroll.scrollTop = 17;
    state = { ...state, status: 'completed', tokens: 120, partial: undefined, conversation: [...conversation, { role: 'assistant', text: 'Second reply', model: 'model-a' }], executions: [executions[0], { ...state.executions[1], status: 'completed', tokens: 40 }] };
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(current.parentElement, f.$('[data-agent-message-index="3"]'));
    assert.match(current.textContent, /40 Token/); assert.doesNotMatch(current.textContent, /120 Token/);
    assert.match(first.textContent, /80 Token/); assert.equal(first.open, true);
    assert.equal(scroll.scrollTop, 17, 'manual scrollback is not moved when execution records or the final reply update');
    const reloaded = fixture(t, route => route === 'runs/12' ? state : undefined, {}, restore); await settle();
    assert.match(reloaded.$('[data-execution-index="0"]').textContent, /First turn tool/);
    assert.match(reloaded.$('[data-execution-index="2"]').textContent, /Second done/);
    assert.equal(reloaded.window.document.querySelectorAll('.oyiso-agent-scroll .oyiso-agent-progress').length, 2);
});

test('continuing a conversation preserves the earlier execution node and its expanded state', async t => {
    const initial = task({ conversation: [{ role: 'user', text: 'First' }, { role: 'assistant', text: 'Answer' }], executions: [{ index: 0, status: 'completed', step: 1, tokens: 40, events: [{ type: 'info', label: 'Original event' }] }] });
    const wire = liveWire();
    const f = fixture(t, (route, options) => route === 'runs/12' ? initial : route === 'runs' && options.method === 'POST' ? wire : undefined, {}, window => { liveSupport(window); window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'); });
    await settle(); f.$('[data-execution-index="0"]').open = true;
    f.$('#oyiso-agent-prompt').value = 'Next'; f.$('[data-agent-send]').click(); await settle();
    const previous = f.$('[data-execution-index="0"]');
    assert.equal(previous.open, true); assert.match(previous.textContent, /Original event/);
    assert.equal(f.$('[data-agent-pending]').nextElementSibling.querySelector('.oyiso-agent-progress').dataset.executionIndex, 'pending');
    const next = task({ status: 'queued', conversation: [...initial.conversation, { role: 'user', text: 'Next' }], executions: [...initial.executions, { index: 2, status: 'queued', step: 0, tokens: 0, events: [{ type: 'info', label: 'New event' }] }] });
    wire.emit('state', next); await settle();
    assert.equal(f.$('[data-execution-index="0"]'), previous); assert.equal(previous.open, true);
    assert.match(f.$('[data-execution-index="2"]').textContent, /New event/);
    wire.emit('text', { id: 12, step: 1, model: 'model-a', delta: 'New reply' }); await settle();
    assert.match(f.$('[data-agent-usage]').textContent, /1 次模型调用/);
    wire.emit('done', { ...next, status: 'completed', executions: [initial.executions[0], { ...next.executions[1], status: 'completed' }] }); wire.close(); await settle();
    assert.equal(f.window.document.querySelectorAll('.oyiso-agent-scroll .oyiso-agent-progress').length, 2);
});

test('legacy combined execution history is labeled and its content remains safe text', async t => {
    const text = '<img src=x onerror=alert(1)>';
    const state = task({ conversation: [{ role: 'user', text: 'Old first' }, { role: 'assistant', text: 'Old answer' }, { role: 'user', text: 'Old next' }, { role: 'assistant', text: 'Old next answer' }], events: [{ type: 'info', label: text, detail: '[Source](https://example.com)' }] });
    const f = fixture(t, route => route === 'runs/12' ? state : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    const record = f.$('[data-execution-index="2"]');
    assert.match(record.textContent, /历史合并记录/); assert.match(record.textContent, /<img src=x/);
    assert.equal(record.querySelector('img, script'), null); assert.equal(record.querySelector('a').rel, 'noopener noreferrer');
    assert.equal(f.window.document.querySelectorAll('.oyiso-agent-scroll .oyiso-agent-progress').length, 1);
    assert.equal(f.$('[data-agent-message-index="2"]').nextElementSibling, record.parentElement);
});

test('live bursts and the final chunk reveal smoothly without delaying completed controls or replacing the reply', async t => {
    const wire = liveWire(); let clock;
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? wire : undefined, {}, window => { liveSupport(window); clock = frameClock(window); }, { motion: true });
    f.$('#oyiso-agent-prompt').value = 'Explain'; f.$('[data-agent-send]').click(); await settle();
    wire.emit('state', task({ status: 'queued', conversation: [{ role: 'user', text: 'Explain' }] }));
    const text = '👩‍💻你好，正在平滑显示这段回复，不应当一次跳出整个段落。';
    wire.emit('text', { id: 12, step: 1, model: 'model-a', delta: text }); await settle();
    const content = f.$('[data-agent-partial] .oyiso-agent-message-content');
    assert.equal(content.textContent, '👩‍💻');
    clock.tick();
    assert.ok(content.textContent.length > '👩‍💻'.length && content.textContent.length < text.length);
    clock.finish(); assert.equal(content.textContent, text);
    wire.emit('text', { id: 12, step: 1, model: 'model-a', delta: '这是最后一段补充。' }); await settle();
    const finalText = text + '这是最后一段补充。';
    const final = task({ conversation: [{ role: 'user', text: 'Explain' }, { role: 'assistant', text: finalText }] });
    wire.emit('done', final); wire.close(); await settle();
    assert.equal(f.$('.is-assistant .oyiso-agent-message-content'), content);
    assert.notEqual(content.textContent, finalText, 'the completion frame must not dump buffered text');
    assert.equal(f.$('[data-agent-send]').disabled, false);
    clock.finish(); assert.equal(f.$('[data-agent-partial]'), null);
    assert.equal(content.textContent, finalText);
    assert.equal(f.$('.oyiso-agent-stream-caret'), null);
});

test('restored partial replies and reduced motion are shown immediately without retyping', async t => {
    const text = '已经收到的回复不会在刷新后重新打字。'; let clock;
    const restored = fixture(t, route => route === 'runs/12' ? task({ status: 'running', partial: { model: 'model-a', text } }) : undefined, {}, window => {
        clock = frameClock(window); window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
    }, { motion: true });
    await settle();
    assert.equal(restored.$('[data-agent-partial] .oyiso-agent-message-content').textContent, text);
    clock.finish();
    const wire = liveWire();
    const reduced = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? wire : undefined, {}, liveSupport);
    reduced.$('#oyiso-agent-prompt').value = 'Explain'; reduced.$('[data-agent-send]').click(); await settle();
    wire.emit('state', task({ status: 'queued' })); wire.emit('text', { id: 12, step: 1, model: 'model-a', delta: text }); await settle();
    assert.equal(reduced.$('[data-agent-partial] .oyiso-agent-message-content').textContent, text);
    wire.close();
});

test('buffered typing follows the bottom but leaves manual scrollback in place', async t => {
    const wire = liveWire(); let clock;
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? wire : undefined, {}, window => { liveSupport(window); clock = frameClock(window); }, { motion: true });
    const scroll = f.$('.oyiso-agent-scroll');
    Object.defineProperties(scroll, {
        scrollHeight: { get: () => 1200 + f.$('[data-agent-messages]').textContent.length * 4 },
        clientHeight: { value: 200 },
    });
    f.$('#oyiso-agent-prompt').value = 'Explain'; f.$('[data-agent-send]').click(); await settle();
    wire.emit('state', task({ status: 'queued' }));
    wire.emit('text', { id: 12, step: 1, model: 'model-a', delta: '平滑显示回复，同时保持阅读位置。'.repeat(8) }); await settle();
    scroll.scrollTop = scroll.scrollHeight - scroll.clientHeight;
    const bottom = scroll.scrollTop; clock.tick();
    assert.ok(scroll.scrollTop > bottom);
    scroll.scrollTop = 75; clock.finish();
    assert.equal(scroll.scrollTop, 75);
    wire.close();
});

test('cancellation and changing conversations stop pending typing frames', async t => {
    for (const action of ['cancel', 'new']) {
        const wire = liveWire(); let clock;
        const text = '这些已经收到的内容正在分帧显示，切换或取消后不能继续写入。';
        const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? wire : route === 'runs/12/cancel' ? task({ status: 'cancelled', partial: { model: 'model-a', text } }) : undefined, {}, window => { liveSupport(window); clock = frameClock(window); }, { motion: true });
        f.$('#oyiso-agent-prompt').value = 'Explain'; f.$('[data-agent-send]').click(); await settle();
        wire.emit('state', task({ status: 'queued' })); wire.emit('text', { id: 12, step: 1, model: 'model-a', delta: text }); await settle();
        assert.notEqual(f.$('[data-agent-partial] .oyiso-agent-message-content').textContent, text);
        f.$(`[data-agent-${action}]`).click(); await settle(); clock.finish();
        if (action === 'cancel') {
            assert.equal(f.$('[data-agent-partial] .oyiso-agent-message-content').textContent, text);
            assert.equal(f.$('.oyiso-agent-stream-caret'), null);
            assert.equal(f.$('[data-agent-status]').textContent, '已取消');
        } else {
            assert.equal(f.$('[data-agent-partial]'), null);
            assert.ok(f.$('.oyiso-agent-empty'));
        }
        wire.close();
    }
});

const trashProposal = (extra = {}) => ({ ...proposal(), type: 'trash', sku: 'SKU-99', product_type: 'variable', previous_status: 'publish', variation_count: 2, reason: '用户指定此商品移入回收站', before: {}, after: {}, ...extra });
const productButton = (f, text) => [...f.$('[data-agent-changes]').querySelectorAll('button')].find(button => button.textContent === text);

test('product trash cards require modal confirmation and support restore without duplicate execution', async t => {
    const change = trashProposal();
    let state = task({ status: 'waiting', changes: [change] }), finish;
    const f = fixture(t, (route, options) => {
        if (route === 'runs/12') return state;
        if (route.includes('/changes/')) {
            const action = JSON.parse(options.body).action;
            if (action === 'trash') return new Promise(resolve => { finish = () => { state = task({ changes: [{ ...change, status: 'applied' }] }); resolve(state); }; });
            state = task({ changes: [{ ...change, status: 'reverted' }] }); return state;
        }
    }, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.match(f.$('[data-agent-changes]').textContent, /SKU-99/);
    assert.match(f.$('[data-agent-changes]').textContent, /关联变体：2/);
    const apply = productButton(f, '移入回收站'); assert.ok(apply.classList.contains('oyiso-button-danger'));
    apply.click();
    const dialog = f.$('[data-agent-product-dialog]');
    assert.equal(dialog.open, true); assert.ok(dialog.classList.contains('oyiso-modal-motion'));
    assert.match(dialog.textContent, /商品示例.*#99.*SKU-99/);
    assert.equal(f.window.document.activeElement, f.$('[data-agent-product-cancel]'));
    assert.equal(f.calls.some(call => call.route.includes('/changes/')), false);
    f.$('[data-agent-product-cancel]').click(); assert.equal(dialog.open, false);
    assert.equal(f.window.document.activeElement, apply);
    apply.click();
    f.$('[data-agent-product-confirm]').click(); f.$('[data-agent-product-confirm]').click(); await settle();
    const requests = f.calls.filter(call => call.route.includes('/changes/'));
    assert.equal(requests.length, 1); assert.equal(JSON.parse(requests[0].options.body).action, 'trash');
    assert.equal(requests[0].options.headers['X-WP-Nonce'], 'fixture-nonce');
    assert.equal(productButton(f, '移入回收站').disabled, true);
    finish(); await settle();
    assert.match(f.$('[data-agent-changes]').textContent, /已移入回收站/);
    productButton(f, '恢复商品').click(); await settle();
    assert.match(f.$('[data-agent-changes]').textContent, /已恢复/);
    assert.equal(productButton(f, '恢复商品'), undefined);
    assert.equal(JSON.parse(f.calls.filter(call => call.route.includes('/changes/'))[1].options.body).action, 'restore');
});

test('product deletion can be dismissed and unsafe product text stays escaped', async t => {
    const change = trashProposal({ name: '<img src=x onerror=bad>', reason: '<script>bad</script>' });
    let state = task({ status: 'waiting', changes: [change] });
    const f = fixture(t, (route, options) => {
        if (route === 'runs/12') return state;
        if (route.includes('/changes/')) { assert.equal(JSON.parse(options.body).action, 'dismiss'); state = task({ changes: [{ ...change, status: 'dismissed' }] }); return state; }
    }, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.equal(f.$('[data-agent-changes]').querySelector('script,img'), null);
    productButton(f, '不删除').click(); await settle();
    assert.match(f.$('[data-agent-changes]').textContent, /已取消/);
    assert.equal(f.$('[data-agent-product-dialog]').open, false);
    assert.equal(productButton(f, '移入回收站'), undefined);
});

test('a stale product confirmation cannot apply after conversation or proposal changes', async t => {
    const change = trashProposal();
    let state = task({ status: 'waiting', changes: [change] });
    const f = fixture(t, route => route === 'runs/12' ? state : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle(); productButton(f, '移入回收站').click();
    state = task({ changes: [{ ...change, status: 'dismissed' }] });
    f.$('[data-agent-refresh]').click(); await settle();
    f.$('[data-agent-product-confirm]').click(); await settle();
    assert.equal(f.calls.some(call => call.route.includes('/changes/')), false);
    state = task({ status: 'waiting', changes: [change] });
    f.$('[data-agent-refresh]').click(); await settle(); productButton(f, '移入回收站').click();
    f.$('[data-agent-new]').click(); f.$('[data-agent-product-confirm]').click(); await settle();
    assert.equal(f.calls.some(call => call.route.includes('/changes/')), false);
});

test('product confirmation Escape keeps the assistant open and a rejected deletion remains reviewable', async t => {
    const change = trashProposal();
    const f = fixture(t, route => route === 'runs/12' ? task({ status: 'waiting', changes: [change] }) : route.includes('/changes/') ? { ok: false, status: 409, body: { message: '商品已发生变化，请重新读取。' } } : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle(); f.$('[data-agent-fullscreen-toggle]').click(); productButton(f, '移入回收站').click();
    const dialog = f.$('[data-agent-product-dialog]');
    dialog.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    dialog.dispatchEvent(new f.window.Event('cancel', { cancelable: true }));
    assert.equal(dialog.open, false); assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.ok(f.$('#oyiso-agent-panel').hasAttribute('data-agent-fullscreen'));
    productButton(f, '移入回收站').click(); f.$('[data-agent-product-confirm]').click(); await settle();
    assert.match(f.$('[data-agent-notice]').textContent, /商品已发生变化/);
    assert.equal(productButton(f, '移入回收站').disabled, false);
    assert.equal(productButton(f, '恢复商品'), undefined);
});

test('product writes are individually opt-in and restoring also requires its own permission', async t => {
    const changes = [trashProposal(), trashProposal({ id: 'second', status: 'applied' })];
    for (const writeTools of [[], undefined, ['oyiso/revert-product-trash']]) {
        const f = fixture(t, route => route === 'runs/12' ? task({ status: 'waiting', changes }) : undefined, { writeTools }, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
        await settle();
        assert.match(f.$('[data-agent-changes]').textContent, /未获写入授权/);
        assert.equal(productButton(f, '移入回收站').disabled, true);
        assert.equal(productButton(f, '不删除').disabled, false);
        assert.equal(productButton(f, '恢复商品').disabled, !writeTools?.includes('oyiso/revert-product-trash'));
        productButton(f, '移入回收站').click();
        assert.equal(f.$('[data-agent-product-dialog]').open, false);
        assert.equal(f.calls.some(call => call.route.includes('/changes/')), false);
    }
});

test('saving permission changes updates pending cards and cancels an open deletion confirmation', async t => {
    let writeTools = [];
    const f = fixture(t, route => route === 'runs/12' ? task({ status: 'waiting', changes: [trashProposal()] }) : route === 'status' ? { enabled: true, supported: true, writeTools, secrets: {} } : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    const saved = async () => {
        f.window.jQuery(f.window.document).trigger('ajaxSuccess', [{ responseJSON: { success: true } }, { data: 'action=csf_oyiso_ajax_save' }]);
        await settle();
    };
    await settle(); productButton(f, '移入回收站').click();
    await saved();
    assert.equal(f.$('[data-agent-product-dialog]').open, false);
    assert.equal(productButton(f, '移入回收站').disabled, true);
    f.$('[data-agent-product-confirm]').click(); await settle();
    assert.equal(f.calls.some(call => call.route.includes('/changes/')), false);
    writeTools = ['oyiso/apply-product-trash']; await saved();
    assert.equal(productButton(f, '移入回收站').disabled, false);
    assert.doesNotMatch(f.$('[data-agent-changes]').textContent, /未获写入授权/);
    productButton(f, '移入回收站').click();
    assert.equal(f.$('[data-agent-product-dialog]').open, true);
});

test('read-only settings block content writes and MCP writes while cancellation remains available', async t => {
    const changes = [proposal(), { ...proposal(), id: 'applied', status: 'applied' }];
    const action = { id: 'mcp-write', name: 'woodmart/update-theme-setting', label: '修改主题', input_json: '{}', status: 'pending' };
    for (const writeTools of [[], undefined, ['oyiso/apply-product-update']]) {
        const f = fixture(t, route => route === 'runs/12' ? task({ status: 'waiting', changes, mcp_actions: [action] }) : undefined, { writeTools }, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
        await settle();
        assert.equal(productButton(f, '应用修改').disabled, !writeTools?.includes('oyiso/apply-product-update'));
        assert.equal(productButton(f, '撤回修改').disabled, true);
        assert.equal(productButton(f, '确认执行').disabled, true);
        assert.equal(productButton(f, '取消').disabled, false);
        productButton(f, '撤回修改').click(); productButton(f, '确认执行').click(); await settle();
        assert.equal(f.calls.some(call => /\/(changes|mcp)\//.test(call.route)), false);
    }
});

test('saving individual write grants immediately updates content and MCP action buttons', async t => {
    let writeTools = ['woodmart/update-theme-setting'];
    const action = { id: 'mcp-write', name: 'woodmart/update-theme-setting', label: '修改主题', input_json: '{}', status: 'pending' };
    const f = fixture(t, route => route === 'runs/12' ? task({ status: 'waiting', changes: [proposal()], mcp_actions: [action] }) : route === 'status' ? { enabled: true, supported: true, writeTools, secrets: {} } : undefined, { writeTools: [] }, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    const saved = async () => {
        f.window.jQuery(f.window.document).trigger('ajaxSuccess', [{ responseJSON: { success: true } }, { data: 'action=csf_oyiso_ajax_save' }]);
        await settle();
    };
    await settle(); await saved();
    assert.equal(productButton(f, '确认执行').disabled, false);
    assert.equal(productButton(f, '应用修改').disabled, true);
    writeTools = ['oyiso/apply-product-update']; await saved();
    assert.equal(productButton(f, '确认执行').disabled, true);
    assert.equal(productButton(f, '应用修改').disabled, false);
});

test('running and cancelled conversations cannot submit product deletions', async t => {
    for (const status of ['running', 'cancelled']) {
        const f = fixture(t, route => route === 'runs/12' ? task({ status, changes: [trashProposal()] }) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
        await settle();
        assert.equal(productButton(f, '移入回收站').disabled, true);
        productButton(f, '移入回收站').click();
        assert.equal(f.$('[data-agent-product-dialog]').open, false);
        assert.equal(f.calls.some(call => call.route.includes('/changes/')), false);
    }
    const f = fixture(t, route => route === 'runs/12' ? task({ status: 'cancelled', changes: [trashProposal({ status: 'applied' })] }) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.equal(productButton(f, '恢复商品').disabled, false);
});

test('MCP review cards show escaped arguments and require an explicit action without duplicate execution', async t => {
    const action = { id: '12345678-1234-1234-1234-123456789abc', name: 'woodmart/update-theme-setting', label: '更新主题设置', input_json: '{"id":"primary-color","value":"<script>bad</script>"}', status: 'pending', result: '' };
    let state = task({ status: 'waiting', mcp_actions: [action] }); let finish;
    const f = fixture(t, route => route === 'runs/12' ? state : route.includes('/mcp/') ? new Promise(resolve => { finish = () => { state = task({ mcp_actions: [{ ...action, status: 'completed', result: '<script>Result</script>' }] }); resolve({ ...state, status: 'queued' }); }; }) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.equal(f.$('[data-agent-changes] script'), null);
    assert.match(f.$('[data-agent-changes] pre').textContent, /<script>bad<\/script>/);
    assert.equal(f.calls.some(call => call.route.includes('/mcp/')), false);
    const approve = [...f.window.document.querySelectorAll('[data-agent-changes] button')].find(button => button.textContent === '确认执行');
    approve.click(); approve.click(); await settle();
    const calls = f.calls.filter(call => call.route.includes('/mcp/'));
    assert.equal(calls.length, 1);
    assert.equal(JSON.parse(calls[0].options.body).action, 'execute');
    assert.equal(f.$('[data-agent-changes] button').disabled, true);
    finish(); await settle();
    assert.equal(f.$('[data-agent-changes] button'), null);
    assert.match(f.$('[data-agent-changes]').textContent, /已执行/);
    assert.equal(f.$('[data-agent-changes] script'), null);
    assert.ok(f.calls.filter(call => call.route === 'runs/12').length >= 2, 'successful confirmation reconnects to the resumed assistant');
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(f.calls.filter(call => call.route.includes('/mcp/')).length, 1);
});

test('switching conversations loads quietly and only explicit refresh spins the header refresh button', async t => {
    let resolveSwitch, resolveRefresh, reads = 0;
    const f = fixture(t, route => {
        if (route === 'runs?page=1') return { items: [12, 13].map(id => ({ id, title: `对话 ${id}`, status: 'completed', updated: '2026-10-20 16:15:49' })), pages: 1 };
        if (route === 'runs/12') return task();
        if (route === 'runs/13') return new Promise(resolve => { if (++reads === 1) resolveSwitch = resolve; else resolveRefresh = resolve; });
    }, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle(); const listReads = f.calls.filter(call => call.route === 'runs?page=1').length;
    f.$('#oyiso-agent-history [data-agent-run="13"]').click(); await settle();
    assert.equal(f.$('[data-agent-refresh]').getAttribute('aria-busy'), 'false');
    assert.equal(f.calls.filter(call => call.route === 'runs?page=1').length, listReads);
    resolveSwitch(task({ id: 13, conversation: [{ role: 'assistant', text: '已切换' }] })); await settle();
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(f.$('[data-agent-refresh]').getAttribute('aria-busy'), 'true');
    resolveRefresh(task({ id: 13 })); await settle();
    assert.equal(f.$('[data-agent-refresh]').getAttribute('aria-busy'), 'false');
});

test('single deletion needs a modal confirmation and safely clears the active conversation once', async t => {
    let finish, deleted = false;
    const f = fixture(t, (route, options) => {
        if (route === 'runs?page=1') return { items: deleted ? [] : [{ id: 12, title: '要删除的对话', status: 'completed', updated: '2026-10-20 16:15:49' }], pages: 1 };
        if (route === 'runs/12') return task({ conversation: [{ role: 'user', text: '即将删除' }] });
        if (route === 'runs' && options.method === 'DELETE') return new Promise(resolve => { finish = () => { deleted = true; resolve({ deleted: [12] }); }; });
    }, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    f.$('#oyiso-agent-history [data-agent-history-remove="12"]').click();
    const dialog = f.window.document.querySelector('[data-agent-delete-dialog]');
    assert.equal(dialog.open, true); assert.match(dialog.textContent, /确定删除这条对话/);
    assert.equal(f.calls.some(call => call.options.method === 'DELETE'), false);
    dialog.querySelector('[data-agent-delete-cancel]').click(); assert.equal(dialog.open, false);
    f.$('#oyiso-agent-history [data-agent-history-remove="12"]').click();
    dialog.querySelector('[data-agent-delete-close]').click(); assert.equal(dialog.open, false);
    assert.equal(f.window.document.activeElement, f.$('#oyiso-agent-history [data-agent-history-remove="12"]'));
    f.$('#oyiso-agent-history [data-agent-history-remove="12"]').click();
    dialog.querySelector('[data-agent-delete-confirm]').click(); dialog.querySelector('[data-agent-delete-confirm]').click(); await settle();
    assert.equal(f.calls.filter(call => call.options.method === 'DELETE').length, 1);
    assert.equal(dialog.querySelector('[data-agent-delete-confirm]').disabled, true);
    assert.equal(dialog.querySelector('[data-agent-delete-close]').disabled, true);
    finish(); await settle();
    assert.equal(dialog.open, false); assert.equal(f.$('#oyiso-agent-history [data-agent-run="12"]'), null);
    assert.equal(f.window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/'), null);
    assert.equal(f.$('.oyiso-agent-message'), null); assert.ok(f.$('.oyiso-agent-empty'));
});

test('batch selection survives a rejected deletion and submits only the confirmed IDs', async t => {
    let reject = true, deleted = false;
    const f = fixture(t, (route, options) => {
        if (route === 'runs?page=1') return { items: deleted ? [] : [12, 13].map(id => ({ id, title: `批量 ${id}`, status: 'completed', updated: '2026-10-20 16:15:49' })), pages: 1 };
        if (route === 'runs' && options.method === 'DELETE') {
            assert.deepEqual(JSON.parse(options.body).ids, [12, 13]);
            if (reject) return { ok: false, status: 403, body: { message: '没有删除权限' } };
            deleted = true; return { deleted: [12, 13] };
        }
    });
    await settle(); const root = f.$('#oyiso-agent-history');
    root.querySelector('[data-agent-history-manage]').click(); root.querySelector('[data-agent-history-all]').click();
    assert.equal(root.querySelector('[data-agent-history-count]').textContent, '已选 2');
    assert.equal(root.querySelectorAll('[data-agent-history-pick]:checked').length, 2);
    root.querySelector('[data-agent-history-delete]').click();
    const dialog = f.window.document.querySelector('[data-agent-delete-dialog]');
    assert.match(dialog.textContent, /所选 2 条对话/);
    dialog.querySelector('[data-agent-delete-confirm]').click(); await settle();
    assert.equal(dialog.open, true); assert.match(dialog.querySelector('[data-agent-delete-error]').textContent, /没有删除权限/);
    assert.equal(root.querySelector('[data-agent-history-count]').textContent, '已选 2');
    reject = false; dialog.querySelector('[data-agent-delete-confirm]').click(); await settle();
    assert.equal(dialog.open, false); assert.equal(root.querySelector('[data-agent-history-count]').textContent, '已选 0');
});

test('conversation delete dismissals wait for the exit animation and cannot submit during it', async t => {
    const f = fixture(t, route => route === 'runs?page=1' ? { items: [{ id: 12, title: '保留的对话', status: 'completed', updated: '2026-10-20 16:15:49' }], pages: 1 } : undefined, {}, undefined, { motion: true });
    await settle(); const trigger = f.$('#oyiso-agent-history [data-agent-history-remove="12"]'); const dialog = f.$('[data-agent-delete-dialog]');
    for (const dismiss of [
        () => dialog.querySelector('[data-agent-delete-close]').click(),
        () => dialog.dispatchEvent(new f.window.Event('cancel', { cancelable: true })),
        () => dialog.click(),
    ]) {
        trigger.click(); dismiss();
        assert.equal(dialog.open, true); assert.equal(dialog.inert, true);
        dialog.querySelector('[data-agent-delete-confirm]').click();
        assert.equal(f.calls.some(call => call.options.method === 'DELETE'), false);
        transitionEnd(f, dialog); assert.equal(dialog.open, false); assert.equal(f.window.document.activeElement, trigger);
    }
});

test('composer grows from one line to a bounded height and shrinks when cleared', t => {
    let contentHeight = 21;
    const f = fixture(t, undefined, {}, window => Object.defineProperty(window.document.querySelector('#oyiso-agent-prompt'), 'scrollHeight', { get: () => contentHeight }));
    const input = f.$('#oyiso-agent-prompt');
    assert.equal(input.rows, 1); assert.equal(input.style.height, '23px');
    input.value = '多行内容'; contentHeight = 110; input.dispatchEvent(new f.window.Event('input', { bubbles: true }));
    assert.equal(input.style.height, '112px'); assert.equal(input.style.overflowY, 'hidden');
    contentHeight = 400; input.dispatchEvent(new f.window.Event('input', { bubbles: true }));
    assert.equal(input.style.height, '160px'); assert.equal(input.style.overflowY, 'auto');
    input.value = ''; contentHeight = 21; input.dispatchEvent(new f.window.Event('input', { bubbles: true }));
    assert.equal(input.style.height, '23px');
});

function pasteFiles(f, files, text = '') {
    const event = new f.window.Event('paste', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'clipboardData', { value: { files, getData: () => text } });
    f.$('#oyiso-agent-prompt').dispatchEvent(event); return event;
}

test('files can be pasted or selected and are submitted as CSRF-protected multipart content', async t => {
    const f = fixture(t, (route, options) => {
        if (route === 'runs' && options.method === 'POST') return task({ conversation: [{ role: 'user', text: '请分析附件。', attachments: [{ id: 88, name: 'reference.txt', mime: 'text/plain', size: 7 }] }] });
    });
    const text = new f.window.File(['fixture'], 'reference.txt', { type: 'text/plain' });
    const image = new f.window.File(['image'], 'image.png', { type: 'image/png' });
    assert.equal(pasteFiles(f, [image]).defaultPrevented, true);
    const input = f.$('[data-agent-file]'); Object.defineProperty(input, 'files', { value: [text], configurable: true }); input.dispatchEvent(new f.window.Event('change', { bubbles: true }));
    assert.equal(f.window.document.querySelectorAll('[data-agent-attachments] .oyiso-agent-attachment').length, 2);
    assert.equal(f.calls.some(call => call.options.method === 'POST'), false, 'draft files are uploaded only on Send');
    f.$('[data-agent-send]').click(); await settle();
    const sent = f.calls.find(call => call.route === 'runs' && call.options.method === 'POST');
    assert.ok(sent.options.body instanceof f.window.FormData); assert.equal(sent.options.headers['Content-Type'], undefined);
    assert.equal(sent.options.headers['X-WP-Nonce'], 'fixture-nonce');
    assert.equal(sent.options.body.getAll('files[]').length, 2); assert.equal(sent.options.body.get('message'), '请分析附件。');
    assert.equal(f.$('[data-agent-attachments]').hidden, true);
    assert.match(f.$('.oyiso-agent-message-attachments').textContent, /reference.txt/);
});

test('attachment drafts survive conversation switches and refresh without leaking into another draft', async t => {
    const f = fixture(t, route => route === 'runs?page=1' ? { items: [{ id: 12, title: '已保存对话', status: 'completed', updated: '2026-10-20' }], pages: 1 } : route === 'runs/12' ? task() : undefined);
    const file = new f.window.File(['fixture'], 'draft.txt', { type: 'text/plain' });
    pasteFiles(f, [file], '附件说明'); await settle();
    assert.equal(f.$('#oyiso-agent-prompt').value, '附件说明');
    f.$('#oyiso-agent-history [data-agent-run="12"]').click(); await settle();
    assert.equal(f.$('[data-agent-attachments]').hidden, true);
    f.$('[data-agent-refresh]').click(); await settle(); assert.equal(f.$('[data-agent-attachments]').hidden, true);
    f.$('[data-agent-new]').click();
    assert.equal(f.$('[data-agent-attachments]').hidden, false); assert.match(f.$('[data-agent-attachments]').textContent, /draft.txt/);
    assert.equal(f.$('#oyiso-agent-prompt').value, '附件说明');
    f.$('[data-agent-attachments] button').click(); assert.equal(f.$('[data-agent-attachments]').hidden, true);
});

test('file manager deletion updates open conversation attachments without deleting messages', async t => {
    const f = fixture(t, route => route === 'runs/12' ? task({ conversation: [{ role: 'user', text: '查看文件', attachments: [{ id: 41, name: 'source.md', mime: 'text/plain', size: 20 }] }] }) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    f.window.document.dispatchEvent(new f.window.CustomEvent('oyiso-agent-files-deleted', { detail: [41] }));
    assert.match(f.$('.oyiso-agent-message-attachments').textContent, /已删除/);
    assert.equal(f.$('.oyiso-agent-message-attachments button').disabled, true);
    assert.match(f.$('[data-agent-messages]').textContent, /查看文件/);
});

test('conversation images open the shared private preview instead of downloading them', async t => {
    const f = fixture(t, route => route === 'runs/12' ? task({ conversation: [{ role: 'user', text: '图片', attachments: [{ id: 41, name: 'photo.png', mime: 'image/png', size: 12 }] }] }) : route === 'files/41' ? { name: 'photo.png', mime: 'image/png', data: btoa('private image') } : undefined, {}, window => {
        window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
        window.URL.createObjectURL = () => 'blob:https://vape.local/private-image'; window.URL.revokeObjectURL = () => {};
        window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
        window.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
    });
    await settle(); const trigger = f.$('.oyiso-agent-message-attachments button');
    assert.match(trigger.title, /预览/); trigger.click(); await settle();
    assert.equal(f.$('.oyiso-agent-file-preview').open, true);
    assert.equal(f.$('.oyiso-agent-file-preview img').src, 'blob:https://vape.local/private-image');
    f.$('[aria-label="关闭预览"]').click(); assert.equal(f.$('#oyiso-agent-panel').hidden, false);
});

test('draft images preview locally without uploading and the separate remove button still works', async t => {
    const objects = [], revoked = [];
    const f = fixture(t, undefined, {}, window => {
        window.URL.createObjectURL = blob => { objects.push(blob); return `blob:https://vape.local/draft-${objects.length}`; };
        window.URL.revokeObjectURL = url => revoked.push(url);
    });
    const file = new f.window.File(['local bytes'], 'photo.png', { type: 'image/png' });
    pasteFiles(f, [file]);
    const preview = f.$('[aria-label="预览待发送附件：photo.png"]');
    assert.equal(preview.textContent, '');
    assert.ok(preview.querySelector('img')); preview.click(); await settle();
    assert.equal(f.$('.oyiso-agent-file-preview').open, true);
    assert.equal(f.$('.oyiso-agent-file-preview img').src, 'blob:https://vape.local/draft-2');
    assert.equal(objects[1].type, 'image/png'); assert.equal(objects[1].size, file.size);
    assert.equal(f.calls.some(call => call.route.startsWith('files/') || call.options.method === 'POST'), false);
    f.$('[aria-label="关闭预览"]').click();
    assert.equal(f.window.document.activeElement, preview); assert.deepEqual(revoked, ['blob:https://vape.local/draft-2']);
    f.$('[aria-label="移除附件：photo.png"]').click();
    assert.equal(f.$('[data-agent-attachments]').hidden, true); assert.ok(revoked.includes('blob:https://vape.local/draft-1'));
});

test('conversation thumbnails use authenticated bytes once, survive redraws and are cleared when deleted', async t => {
    let reads = 0; const revoked = [], blobs = [];
    const photo = { id: 41, name: '<img src=x>.webp', mime: 'image/webp', size: 13 };
    const f = fixture(t, route => route === 'runs/12' ? task({ events: [{ type: 'info', label: `读取 ${++reads}` }], conversation: [{ role: 'user', text: '查看图片', attachments: [photo] }] }) : route === 'files/41' ? { name: photo.name, mime: photo.mime, data: btoa('private image') } : undefined, {}, window => {
        window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
        window.URL.createObjectURL = blob => { blobs.push(blob); return `blob:https://vape.local/image-${blobs.length}`; };
        window.URL.revokeObjectURL = url => revoked.push(url);
    });
    await settle(); const image = f.$('.oyiso-agent-message-thumb img');
    assert.equal(image.src, 'blob:https://vape.local/image-1'); image.dispatchEvent(new f.window.Event('load'));
    assert.equal(image.hidden, false); assert.equal(f.$('.oyiso-agent-message-file-label'), null);
    assert.match(f.$('.oyiso-agent-message-attachments button').getAttribute('aria-label'), /<img src=x>/);
    const request = f.calls.find(call => call.route === 'files/41');
    assert.equal(request.options.credentials, 'same-origin'); assert.equal(request.options.headers['X-WP-Nonce'], 'fixture-nonce');
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(f.calls.filter(call => call.route === 'files/41').length, 1);
    assert.equal(f.$('.oyiso-agent-message-thumb img').src, 'blob:https://vape.local/image-1');
    f.$('.oyiso-agent-message-attachments button').click(); await settle();
    assert.equal(f.$('.oyiso-agent-file-preview img').src, 'blob:https://vape.local/image-2');
    assert.equal(f.calls.filter(call => call.route === 'files/41').length, 1, 'the preview reuses the authenticated image blob');
    f.$('[aria-label="关闭预览"]').click();
    f.window.document.dispatchEvent(new f.window.CustomEvent('oyiso-agent-files-deleted', { detail: [41] }));
    assert.equal(f.$('.oyiso-agent-message-thumb'), null); assert.equal(f.$('.oyiso-agent-message-attachments button').disabled, true);
    assert.ok(revoked.includes('blob:https://vape.local/image-1'));
});

test('switching conversations aborts pending thumbnails and rejects late bytes', async t => {
    let finish; let requestSignal; const blobs = [];
    const f = fixture(t, (route, options) => route === 'runs/12' ? task({ conversation: [{ role: 'user', text: '旧对话', attachments: [{ id: 41, name: 'old.png', mime: 'image/png', size: 10 }] }] }) : route === 'files/41' ? new Promise(resolve => { finish = resolve; requestSignal = options.signal; }) : undefined, {}, window => {
        window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
        window.URL.createObjectURL = blob => { blobs.push(blob); return 'blob:https://vape.local/stale'; }; window.URL.revokeObjectURL = () => {};
    });
    await settle(); assert.equal(requestSignal.aborted, false);
    f.$('[data-agent-new]').click(); assert.equal(requestSignal.aborted, true);
    finish({ name: 'old.png', mime: 'image/png', data: btoa('late image') }); await settle();
    assert.equal(blobs.length, 0); assert.equal(f.$('.oyiso-agent-message-thumb'), null); assert.ok(f.$('.oyiso-agent-empty'));
});

test('unavailable thumbnails retain a usable preview button without showing a broken image', async t => {
    const f = fixture(t, route => route === 'runs/12' ? task({ conversation: [{ role: 'user', text: '图片', attachments: [{ id: 41, name: 'photo.png', mime: 'image/png', size: 10 }] }] }) : route === 'files/41' ? { ok: false, status: 403, body: { message: '无权访问' } } : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.equal(f.$('.oyiso-agent-message-thumb img').hidden, true);
    assert.equal(f.$('.oyiso-agent-message-thumb img').getAttribute('src'), null);
    assert.equal(f.$('.oyiso-agent-message-attachments button').disabled, false);
    f.$('.oyiso-agent-message-attachments button').click(); await settle();
    assert.match(f.$('.oyiso-agent-file-preview-status').textContent, /无权访问/);
});

test('video selection supports the server limit and clearly marks preview-only attachments', async t => {
    const f = fixture(t, undefined, { attachmentMaxVideo: 20 * 1048576, attachmentMaxTotal: 32 * 1048576 });
    const video = new f.window.File([new Uint8Array(5 * 1048576)], 'clip.mp4', { type: 'video/mp4' });
    const paste = new f.window.Event('paste', { bubbles: true, cancelable: true });
    Object.defineProperty(paste, 'clipboardData', { value: { files: [video], getData: () => '' } });
    f.$('#oyiso-agent-prompt').dispatchEvent(paste); await settle();
    assert.match(f.$('[data-agent-attachments]').textContent, /clip.mp4/); assert.match(f.$('[data-agent-attachments]').textContent, /仅预览/);
    assert.match(f.$('[data-agent-notice]').textContent, /暂不支持模型分析/);
    assert.match(f.$('[data-agent-file]').accept, /video\/mp4/);
    const tooLarge = new f.window.File([new Uint8Array(21 * 1048576)], 'large.webm', { type: 'video/webm' });
    const over = new f.window.Event('paste', { bubbles: true, cancelable: true });
    Object.defineProperty(over, 'clipboardData', { value: { files: [tooLarge], getData: () => '' } });
    f.$('#oyiso-agent-prompt').dispatchEvent(over);
    assert.doesNotMatch(f.$('[data-agent-attachments]').textContent, /large.webm/); assert.match(f.$('[data-agent-notice]').textContent, /大小限制/);
});

test('large image drafts use the image upload limit while documents keep the smaller limit', t => {
    const f = fixture(t, undefined, { attachmentMaxImage: 20 * 1048576, attachmentMaxTotal: 32 * 1048576 });
    pasteFiles(f, [new f.window.File(['x'.repeat(5 * 1048576)], 'large.png', { type: 'image/png' })]);
    assert.ok(f.$('[aria-label="预览待发送附件：large.png"]'));
    pasteFiles(f, [new f.window.File(['x'.repeat(5 * 1048576)], 'large.pdf', { type: 'application/pdf' })]);
    assert.doesNotMatch(f.$('[data-agent-attachments]').textContent, /large.pdf/);
    assert.match(f.$('[data-agent-notice]').textContent, /大小限制/);
});

test('CSV and text drafts above 80 KB follow the document limit and explain partial reading', t => {
    const f = fixture(t);
    const csv = new f.window.File(['ID,SKU\n' + '12,SKU-12\n'.repeat(12000)], 'wc-product-export.csv', { type: 'text/csv' });
    pasteFiles(f, [csv]);
    assert.match(f.$('[data-agent-attachments]').textContent, /wc-product-export.csv/);
    assert.match(f.$('[data-agent-notice]').textContent, /预览.*分段读取/);
    assert.equal(f.$('[data-agent-notice]').classList.contains('is-error'), false);
    pasteFiles(f, [new f.window.File(['x'.repeat(4 * 1048576)], 'boundary.txt', { type: 'text/plain' })]);
    assert.match(f.$('[data-agent-attachments]').textContent, /boundary.txt/);
    pasteFiles(f, [new f.window.File(['x'.repeat(4 * 1048576 + 1)], 'over-limit.csv', { type: 'text/csv' })]);
    assert.doesNotMatch(f.$('[data-agent-attachments]').textContent, /over-limit.csv/);
    assert.match(f.$('[data-agent-notice]').textContent, /最多 4.0 MB/);
    assert.doesNotMatch(f.$('[data-agent-notice]').textContent, /80 KB/);
});

test('text drafts respect smaller PHP upload limits and empty files receive a specific error', t => {
    const f = fixture(t, undefined, { attachmentMaxFile: 131072 });
    pasteFiles(f, [new f.window.File(['x'.repeat(90000)], 'allowed.csv')]);
    assert.match(f.$('[data-agent-attachments]').textContent, /allowed.csv/);
    pasteFiles(f, [new f.window.File(['x'.repeat(140000)], 'server-limit.csv')]);
    assert.doesNotMatch(f.$('[data-agent-attachments]').textContent, /server-limit.csv/);
    assert.match(f.$('[data-agent-notice]').textContent, /最多 128 KB.*当前为 137 KB/);
    pasteFiles(f, [new f.window.File([], 'empty.csv')]);
    assert.match(f.$('[data-agent-notice]').textContent, /empty.csv 是空文件/);
});

test('invalid or oversized files are rejected and failed sending preserves the draft files', async t => {
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? { ok: false, status: 400, body: { message: '所选模型不支持此图片' } } : undefined);
    pasteFiles(f, [new f.window.File(['x'], 'bad.exe'), new f.window.File(['x'.repeat(4 * 1048576 + 1)], 'large.txt')]);
    assert.equal(f.$('[data-agent-attachments]').hidden, true); assert.match(f.$('[data-agent-notice]').textContent, /大小限制/);
    const file = new f.window.File(['x'], 'photo.png', { type: 'image/png' }); pasteFiles(f, [file]); pasteFiles(f, [file]);
    assert.equal(f.window.document.querySelectorAll('.oyiso-agent-attachment').length, 1);
    f.$('[data-agent-send]').click(); await settle();
    assert.match(f.$('[data-agent-notice]').textContent, /不支持此图片/); assert.equal(f.$('[data-agent-attachments]').hidden, false);
    assert.equal(pasteFiles(f, [], '正常文本').defaultPrevented, false);
});

test('execution failure restores the accepted message and retry uses the current editor without duplicate submits', async t => {
    const message = '请继续改写这篇文章\n保留我的修改。';
    let current, submitted = 0, finish;
    const f = fixture(t, (route, options) => {
        if (route === 'runs' && options.method === 'POST') {
            if (++submitted === 1) { current = task({ status: 'queued', conversation: [{ role: 'user', text: message }] }); return current; }
            return new Promise(resolve => { finish = () => { current = task({ conversation: [{ role: 'user', text: message }, { role: 'assistant', text: '已完成重试' }] }); resolve(current); }; });
        }
        if (route === 'runs/12') return current;
        if (route === 'runs/12/step') { current = task({ status: 'failed', conversation: [{ role: 'user', text: message }], error: 'The API only allows a single function response, as the only content of the message.' }); return current; }
    }, { pageContext: { screen: 'post', post_id: 7, post_type: 'post', editor: 'classic', fields: { title: 'Article', content: '<p>Original</p>', excerpt: '' } } }, window => {
        window.document.body.insertAdjacentHTML('beforeend', '<div id="poststuff"><input id="title" value="Article"><textarea id="content">&lt;p&gt;Original&lt;/p&gt;</textarea><textarea id="excerpt"></textarea></div>');
    });
    f.$('#oyiso-agent-prompt').value = message; f.$('[data-agent-send]').click(); await settle();
    assert.match(f.$('[data-agent-status]').textContent, /执行失败/);
    assert.equal(f.$('#oyiso-agent-prompt').value, message);
    assert.match(f.$('[data-agent-messages]').textContent, /保留我的修改/);
    assert.equal(f.$('[data-agent-retry]').textContent, '重试此消息');
    f.$('#content').value = '<p>Latest manual edit</p>';
    f.$('[data-agent-retry]').click(); f.$('[data-agent-retry]').click(); await settle();
    assert.equal(submitted, 2); assert.equal(f.$('[data-agent-retry]').disabled, true);
    const retry = JSON.parse(f.calls.filter(call => call.route === 'runs' && call.options.method === 'POST')[1].options.body);
    assert.equal(retry.message, message); assert.equal(retry.run_id, 12);
    assert.equal(JSON.parse(retry.page_context).fields.content, '<p>Latest manual edit</p>');
    finish(); await settle();
    assert.equal(f.$('#oyiso-agent-prompt').value, ''); assert.equal(f.$('[data-agent-retry]'), null);
    assert.match(f.$('[data-agent-messages]').textContent, /已完成重试/);
});

test('retrying a failed saved conversation preserves a newer draft and its unsent attachments', async t => {
    const original = 'Original request', draft = 'My newer unsent request';
    let current = task({ status: 'failed', error: '服务暂不可用', conversation: [{ role: 'user', text: original, attachments: [{ id: 77, name: 'original.txt', mime: 'text/plain', size: 10 }] }] });
    const f = fixture(t, (route, options) => {
        if (route === 'runs/12') return current;
        if (route === 'runs' && options.method === 'POST') { current = task({ conversation: [{ role: 'user', text: original }, { role: 'assistant', text: 'Done' }] }); return current; }
    }, {}, window => {
        window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
        window.sessionStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:draft:12', draft);
    });
    await settle();
    assert.equal(f.$('#oyiso-agent-prompt').value, draft); assert.match(f.$('[data-agent-messages]').textContent, /original.txt/);
    pasteFiles(f, [new f.window.File(['unsent'], 'new-draft.txt', { type: 'text/plain' })]);
    f.$('[data-agent-retry]').click(); await settle();
    const request = f.calls.find(call => call.route === 'runs' && call.options.method === 'POST');
    assert.equal(JSON.parse(request.options.body).message, original);
    assert.equal(f.$('#oyiso-agent-prompt').value, draft); assert.match(f.$('[data-agent-attachments]').textContent, /new-draft.txt/);
});

test('clearing a recovered failed message stays cleared across refresh and reload', async t => {
    const failed = task({ status: 'failed', error: 'Provider unavailable', conversation: [{ role: 'user', text: 'Recover this message' }] });
    const f = fixture(t, route => route === 'runs/12' ? failed : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle(); assert.equal(f.$('#oyiso-agent-prompt').value, 'Recover this message');
    f.$('#oyiso-agent-prompt').value = ''; f.$('#oyiso-agent-prompt').dispatchEvent(new f.window.Event('input', { bubbles: true }));
    f.$('[data-agent-refresh]').click(); await settle(); assert.equal(f.$('#oyiso-agent-prompt').value, '');
    const saved = Object.fromEntries(Object.keys(f.window.sessionStorage).map(key => [key, f.window.sessionStorage.getItem(key)]));
    const restored = fixture(t, route => route === 'runs/12' ? failed : undefined, {}, window => {
        window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12');
        for (const [key, value] of Object.entries(saved)) window.sessionStorage.setItem(key, value);
    });
    await settle(); assert.equal(restored.$('#oyiso-agent-prompt').value, '');
    assert.ok(restored.$('[data-agent-retry]')); assert.match(restored.$('[data-agent-messages]').textContent, /Recover this message/);
});

test('refresh and page restoration reconnect to running work without duplicate steps or stale response rollback', async t => {
    let reads = 0; let finishStep;
    const running = task({ status: 'running', partial: { text: 'Current text', model: 'model-a' } });
    const f = fixture(t, route => route === 'runs/12' ? (++reads === 1 ? task({ status: 'queued' }) : running) : route === 'runs/12/step' ? new Promise(resolve => { finishStep = resolve; }) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(f.calls.filter(call => call.route.endsWith('/step')).length, 1);
    finishStep(task({ conversation: [{ role: 'assistant', text: 'Stale result' }] })); await settle();
    assert.match(f.$('[data-agent-partial]').textContent, /Current text/);
    assert.doesNotMatch(f.$('[data-agent-messages]').textContent, /Stale result/);
    const restored = fixture(t, route => route === 'runs/12' ? running : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.match(restored.$('[data-agent-partial]').textContent, /Current text/);
    assert.equal(restored.calls.some(call => call.route.endsWith('/step') || call.options.method === 'POST'), false);
});

test('prompt changes do not mark plugin settings dirty', async t => {
    const f = fixture(t);
    let changed = 0;
    f.window.jQuery('.csf-content').on('change keypress', ':input', () => changed++);
    for (const type of ['change', 'keypress']) f.$('#oyiso-agent-prompt').dispatchEvent(new f.window.Event(type, { bubbles: true }));
    assert.equal(changed, 0);
});

test('conversation model selection is submitted and retained when an idle conversation is polled again', async t => {
    let selection = 'fixture|model-a';
    const f = fixture(t, (route, options) => {
        if (route === 'runs' && options.method === 'POST') { selection = JSON.parse(options.body).selection; return task({ selection }); }
        if (route === 'runs/12') return task({ selection });
    }, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    const select = f.$('[data-agent-model]');
    assert.equal(select.value, 'fixture|model-a');
    assert.equal(select.selectedOptions[0].textContent, 'model-a');
    assert.deepEqual([...select.querySelectorAll('optgroup')].map(group => group.label), ['测试连接', '备用连接']);
    select.value = 'second|model-b'; select.dispatchEvent(new f.window.Event('change', { bubbles: true }));
    f.$('[data-agent-close]').click(); f.$('#oyiso-agent-launcher').click(); await settle();
    assert.equal(select.value, 'second|model-b');
    assert.equal(select.selectedOptions[0].textContent, 'model-b');
    f.$('#oyiso-agent-prompt').value = 'Use the selected connection'; f.$('[data-agent-send]').click(); await settle();
    const sent = f.calls.find(call => call.route === 'runs' && call.options.method === 'POST');
    assert.equal(JSON.parse(sent.options.body).selection, 'second|model-b');
    assert.equal(select.value, 'second|model-b');
    assert.equal(f.window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:model'), 'second|model-b');
});

test('an unsent model choice survives a page reload and is used by the next message', async t => {
    const key = 'oyiso-agent:1:/wp-json/oyiso-agent/v1/';
    let selection = 'fixture|model-a';
    const router = (route, options) => {
        if (route === 'runs' && options.method === 'POST') selection = JSON.parse(options.body).selection;
        if (route === 'runs/12' || route === 'runs' && options.method === 'POST') return task({ selection });
    };
    const original = fixture(t, router, {}, window => window.localStorage.setItem(key, '12'));
    await settle();
    const select = original.$('[data-agent-model]');
    select.value = 'second|model-b'; select.dispatchEvent(new original.window.Event('change', { bubbles: true }));
    const saved = Object.entries(original.window.localStorage);
    const reloaded = fixture(t, router, {}, window => saved.forEach(([name, value]) => window.localStorage.setItem(name, value)));
    await settle();
    assert.equal(reloaded.$('[data-agent-model]').value, 'second|model-b');
    reloaded.$('#oyiso-agent-prompt').value = 'Continue with my selected model';
    reloaded.$('[data-agent-send]').click(); await settle();
    assert.equal(selection, 'second|model-b');
    assert.equal(JSON.parse(reloaded.calls.find(call => call.route === 'runs' && call.options.method === 'POST').options.body).selection, 'second|model-b');
});

test('unsent model choices stay with their own conversation when switching history', async t => {
    const f = fixture(t, route => {
        if (route === 'runs?page=1') return { items: [12, 13].map(id => ({ id, title: `Conversation ${id}`, status: 'completed', updated: '2026-10-23' })), pages: 1 };
        if (route === 'runs/12' || route === 'runs/13') return task({ id: Number(route.split('/')[1]), selection: 'fixture|model-a' });
    }, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    const select = f.$('[data-agent-model]');
    select.value = 'second|model-b'; select.dispatchEvent(new f.window.Event('change', { bubbles: true }));
    f.$('#oyiso-agent-history [data-agent-run="13"]').click(); await settle();
    assert.equal(select.value, 'fixture|model-a');
    f.$('#oyiso-agent-history [data-agent-run="12"]').click(); await settle();
    assert.equal(select.value, 'second|model-b');
    f.$('[data-agent-new]').click();
    assert.equal(select.value, 'second|model-b');
});

test('a removed unsent model remains unavailable after reloading rather than changing the connection', async t => {
    const key = 'oyiso-agent:1:/wp-json/oyiso-agent/v1/';
    const original = fixture(t, route => route === 'runs/12' ? task({ selection: 'fixture|model-a' }) : undefined, {}, window => window.localStorage.setItem(key, '12'));
    await settle();
    const select = original.$('[data-agent-model]');
    select.value = 'second|model-b'; select.dispatchEvent(new original.window.Event('change', { bubbles: true }));
    const saved = Object.entries(original.window.localStorage);
    const reloaded = fixture(t, route => route === 'runs/12' ? task({ selection: 'fixture|model-a' }) : undefined, { models: { 'fixture|model-a': 'Original / model-a' } }, window => saved.forEach(([name, value]) => window.localStorage.setItem(name, value)));
    await settle();
    assert.equal(reloaded.$('[data-agent-model]').value, 'second|model-b');
    assert.equal(reloaded.$('[data-agent-model]').selectedOptions[0].disabled, true);
    assert.equal(reloaded.$('[data-agent-send]').disabled, true);
});

test('a running task replaces an older draft choice and does not restore it after completion', async t => {
    const key = 'oyiso-agent:1:/wp-json/oyiso-agent/v1/';
    const original = fixture(t, route => route === 'runs/12' ? task({ selection: 'fixture|model-a' }) : undefined, {}, window => window.localStorage.setItem(key, '12'));
    await settle();
    const select = original.$('[data-agent-model]');
    select.value = 'second|model-b'; select.dispatchEvent(new original.window.Event('change', { bubbles: true }));
    const saved = Object.entries(original.window.localStorage);
    const running = fixture(t, route => route === 'runs/12' ? task({ status: 'running', selection: 'fixture|model-a' }) : undefined, {}, window => saved.forEach(([name, value]) => window.localStorage.setItem(name, value)));
    await settle();
    assert.equal(running.$('[data-agent-model]').value, 'fixture|model-a');
    assert.equal(running.$('[data-agent-model]').disabled, true);
    const afterRunning = Object.entries(running.window.localStorage);
    const completed = fixture(t, route => route === 'runs/12' ? task({ selection: 'fixture|model-a' }) : undefined, {}, window => afterRunning.forEach(([name, value]) => window.localStorage.setItem(name, value)));
    await settle();
    assert.equal(completed.$('[data-agent-model]').value, 'fixture|model-a');
    assert.equal(completed.$('[data-agent-model]').disabled, false);
    completed.$('[data-agent-new]').click();
    assert.equal(completed.$('[data-agent-model]').value, 'second|model-b');
});

test('a new conversation remembers the last chosen model across a page reload', async t => {
    const original = fixture(t);
    const select = original.$('[data-agent-model]');
    select.value = 'second|model-b'; select.dispatchEvent(new original.window.Event('change', { bubbles: true }));
    const saved = Object.entries(original.window.localStorage);
    const reloaded = fixture(t, undefined, {}, window => saved.forEach(([name, value]) => window.localStorage.setItem(name, value)));
    await settle();
    assert.equal(reloaded.$('[data-agent-model]').value, 'second|model-b');
    assert.equal(reloaded.$('[data-agent-model]').disabled, false);
});

test('history restores its own model and active tasks lock model switching', async t => {
    const f = fixture(t, route => route === 'runs/12' || route === 'runs/12/step' ? task({ status: 'running', selection: 'fixture|model-a' }) : undefined, {}, window => {
        const key = 'oyiso-agent:1:/wp-json/oyiso-agent/v1/';
        window.localStorage.setItem(key, '12'); window.localStorage.setItem(key + ':model', 'second|model-b');
    });
    await settle();
    assert.equal(f.$('[data-agent-model]').value, 'fixture|model-a');
    assert.equal(f.$('[data-agent-model]').disabled, true);
    f.$('[data-agent-new]').click();
    assert.equal(f.$('[data-agent-model]').disabled, false);
    assert.equal(f.$('[data-agent-model]').value, 'second|model-b');
});

test('newly saved models update an empty picker and removed models cannot silently switch connections', async t => {
    const f = fixture(t, undefined, { models: {} });
    const select = f.$('[data-agent-model]');
    assert.equal(f.$('[data-agent-send]').disabled, true);
    assert.equal(f.$('#oyiso-agent-prompt').disabled, false);
    assert.match(f.$('[data-agent-notice]').textContent, /暂无可用模型/);
    f.window.document.dispatchEvent(new f.window.CustomEvent('oyiso-agent-models-updated', { detail: { 'new|model': '<img src=x> / model' } }));
    assert.equal(select.value, 'new|model'); assert.equal(select.querySelector('img'), null);
    assert.equal(select.selectedOptions[0].textContent, 'model');
    assert.equal(select.querySelector('optgroup').label, '<img src=x>');
    assert.equal(f.$('[data-agent-send]').disabled, false);
    f.window.document.dispatchEvent(new f.window.CustomEvent('oyiso-agent-models-updated', { detail: { 'other|model': 'Other / model' } }));
    assert.equal(select.value, 'new|model'); assert.equal(select.selectedOptions[0].disabled, true);
    assert.equal(f.$('[data-agent-send]').disabled, true);
    select.value = 'other|model'; select.dispatchEvent(new f.window.Event('change', { bubbles: true }));
    assert.equal(f.$('[data-agent-send]').disabled, false);
});

test('request failures preserve prompt and restore send control', async t => {
    const f = fixture(t, (route, options) => route === 'runs' && options.method === 'POST' ? { ok: false, status: 400, body: { message: '请配置模型连接。' } } : undefined);
    f.$('#oyiso-agent-prompt').value = '保留这条指令'; f.$('[data-agent-send]').click();
    await settle();
    assert.equal(f.$('#oyiso-agent-prompt').value, '保留这条指令');
    assert.equal(f.$('[data-agent-send]').disabled, false);
    assert.match(f.$('[data-agent-notice]').textContent, /配置模型/);
    assert.equal(f.$('[data-agent-response-status]').hidden, true);
    assert.equal(f.$('[data-agent-cancel]').hidden, true);
    assert.equal(f.$('[data-agent-send]').hidden, false);
    assert.match(f.$('[data-agent-messages]').textContent, /保留这条指令/);
    f.$('[data-agent-send]').click(); await settle();
    assert.equal(f.window.document.querySelectorAll('.is-user').length, 1, 'retrying an unaccepted request does not duplicate the optimistic message');
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

test('chat bubbles label me and each responding model without relabeling history when the picker changes', async t => {
    const f = fixture(t, route => route === 'runs/12' ? task({
        selection: 'fixture|model-a',
        conversation: [{ role: 'user', text: 'Hello' }, { role: 'assistant', text: 'First answer', model: 'model-a' }, { role: 'assistant', text: 'Second answer', model: '<img src=x> / model-b' }],
    }) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    const labels = () => Array.from(f.window.document.querySelectorAll('.oyiso-agent-message-role'), label => label.textContent);
    assert.deepEqual(labels(), ['我', '测试连接 / model-a', '<img src=x> / model-b']);
    assert.equal(f.$('.oyiso-agent-message-role img'), null);
    assert.equal(f.$('.oyiso-agent-message').classList.contains('is-user'), true);
    assert.equal(f.$('[data-agent-message-index="1"]').classList.contains('is-assistant'), true);
    f.$('[data-agent-model]').value = 'second|model-b';
    f.$('[data-agent-model]').dispatchEvent(new f.window.Event('change', { bubbles: true }));
    f.$('[data-agent-close]').click(); f.$('#oyiso-agent-launcher').click(); await settle();
    assert.deepEqual(labels(), ['我', '测试连接 / model-a', '<img src=x> / model-b']);
});

test('recorded provider names stay above each execution when connections disappear or share model IDs', async t => {
    const state = task({ selection: 'second|shared-model', conversation: [
        { role: 'user', text: 'First request' }, { role: 'assistant', text: 'First answer', model: 'shared-model', model_label: 'DeepSeek / shared-model' },
        { role: 'user', text: 'Second request' }, { role: 'assistant', text: 'Second answer', model: 'shared-model', model_label: 'Other provider / shared-model' },
    ], executions: [
        { index: 0, status: 'completed', step: 1, tokens: 10, events: [] },
        { index: 2, status: 'completed', step: 1, tokens: 10, events: [] },
    ] });
    const f = fixture(t, route => route === 'runs/12' ? state : undefined, { models: {} }, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    for (const [index, label] of [[1, 'DeepSeek / shared-model'], [3, 'Other provider / shared-model']]) {
        const reply = f.$(`[data-agent-message-index="${index}"]`);
        assert.equal(reply.firstElementChild.textContent, label);
        assert.equal(reply.children[1].className, 'oyiso-agent-progress');
        assert.equal(reply.children[2].textContent, index === 1 ? 'First answer' : 'Second answer');
    }
    f.window.document.dispatchEvent(new f.window.CustomEvent('oyiso-agent-models-updated', { detail: { 'new|shared-model': 'Renamed group / shared-model' } }));
    f.$('[data-agent-refresh]').click(); await settle();
    assert.equal(f.$('[data-agent-message-index="1"]').firstElementChild.textContent, 'DeepSeek / shared-model');
    assert.equal(f.$('[data-agent-message-index="3"]').firstElementChild.textContent, 'Other provider / shared-model');
});

test('the full model header remains first from waiting through streaming and final reply', async t => {
    const execution = { index: 0, status: 'running', step: 1, tokens: 0, model_label: 'DeepSeek / deepseek-flash', events: [] };
    let current = task({ status: 'running', selection: 'removed|deepseek-flash', conversation: [{ role: 'user', text: 'Hello' }], executions: [execution] });
    const f = fixture(t, route => route === 'runs/12' ? current : undefined, { models: {} }, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    const progress = f.$('[data-execution-index="0"]');
    assert.equal(progress.previousElementSibling.textContent, 'DeepSeek / deepseek-flash');
    current = { ...current, partial: { model: 'deepseek-flash', text: 'Hello back' } };
    f.$('[data-agent-refresh]').click(); await settle();
    const partial = f.$('[data-agent-partial]');
    assert.equal(partial.firstElementChild.textContent, 'DeepSeek / deepseek-flash');
    assert.equal(partial.children[1], progress); assert.equal(partial.children[2].textContent, 'Hello back');
    assert.equal(f.$('[data-agent-execution-view]'), null);
    current = { ...current, status: 'completed', partial: undefined, conversation: [...current.conversation, { role: 'assistant', text: 'Hello back', model: 'deepseek-flash', model_label: 'DeepSeek / deepseek-flash' }] };
    f.$('[data-agent-refresh]').click(); await settle();
    const reply = f.$('[data-agent-message-index="1"]');
    assert.equal(reply, partial); assert.equal(reply.firstElementChild.textContent, 'DeepSeek / deepseek-flash');
    assert.equal(reply.children[1], progress); assert.equal(f.$('[data-agent-partial]'), null);
});

test('old model errors explain how to retry and known legacy replies keep their recorded model name', async t => {
    const f = fixture(t, route => route === 'runs/12' ? task({
        status: 'failed', error: '尚无可用的工具调用模型。请在「模型配置」中保存预设连接，或配置 WordPress 连接器。',
        conversation: [{ role: 'user', text: '你好' }, { role: 'assistant', text: '已保存的回复' }],
        events: [{ label: '模型已响应', detail: 'legacy-model', type: 'info' }],
    }) : undefined, {}, window => window.localStorage.setItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/', '12'));
    await settle();
    assert.match(f.$('[data-agent-notice]').textContent, /在下方选择已添加的模型后重新发送/);
    assert.equal(f.$('.is-assistant .oyiso-agent-message-role').textContent, 'legacy-model');
    assert.equal(f.$('[data-agent-send]').disabled, false);
    f.$('[data-agent-new]').click();
    assert.equal(f.$('[data-agent-notice]').hidden, true);
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
    assert.equal(f.window.document.activeElement, f.$('[data-agent-settings-toggle]'));
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

test('history loading, confirmed empty results and errors remain mutually exclusive', async t => {
    for (const inline of [true, false]) await t.test(inline ? 'sidebar' : 'management page', async t => {
        let mode = 'empty', finish;
        const item = { id: 12, title: 'Existing conversation', status: 'completed', updated: '2026-10-26' };
        const f = fixture(t, route => {
            if (route !== 'runs?page=1') return;
            if (mode === 'pending') return new Promise(resolve => { finish = resolve; });
            return { items: mode === 'populated' ? [item] : [], pages: 1 };
        }, {}, undefined, { globalOnly: inline });
        if (inline) f.$('[data-agent-history-toggle]').click();
        await settle();
        const root = inline ? panelRecords : '#oyiso-agent-history';
        const body = () => f.$(`${root} [data-agent-history-body]`), note = () => f.$(`${root} [data-agent-history-notice]`);
        const refresh = () => f.$(`${root} [data-agent-history-refresh]`).click();
        assert.match(body().textContent, /暂无对话记录/);
        mode = 'pending'; refresh(); await settle();
        assert.match(note().textContent, /正在读取记录/); assert.doesNotMatch(body().textContent, /暂无对话记录/);
        assert.equal(body().getAttribute('aria-busy'), 'true');
        finish({ items: [], pages: 1 }); await settle();
        assert.equal(note().textContent, ''); assert.match(body().textContent, /暂无对话记录/);
        assert.equal(body().getAttribute('aria-busy'), 'false');
        refresh(); await settle();
        finish({ ok: false, status: 503, body: { message: '读取记录失败，请重试' } }); await settle();
        assert.match(note().textContent, /读取记录失败/); assert.doesNotMatch(body().textContent, /暂无对话记录|正在读取记录/);
        assert.equal(body().getAttribute('aria-busy'), 'false');
        mode = 'populated'; refresh(); await settle(); assert.match(body().textContent, /Existing conversation/);
        mode = 'pending'; refresh(); await settle(); assert.match(body().textContent, /Existing conversation/);
        finish({ ok: false, status: 503, body: { message: '暂时无法刷新' } }); await settle();
        assert.match(body().textContent, /Existing conversation/); assert.doesNotMatch(body().textContent, /暂无对话记录/);
    });
});

test('history stays busy while reloading a page that disappears after records are removed', async t => {
    let shrinking = false, finish;
    const f = fixture(t, route => {
        if (route === 'runs?page=2') return { items: [], pages: shrinking ? 1 : 2 };
        if (route === 'runs?page=1') return shrinking ? new Promise(resolve => { finish = resolve; }) : { items: [], pages: 2 };
    }, {}, undefined, { globalOnly: true });
    f.$('[data-agent-history-toggle]').click(); await settle();
    f.$(`${panelRecords} [data-agent-history-next]`).click(); await settle();
    shrinking = true; f.$(`${panelRecords} [data-agent-history-refresh]`).click(); await settle();
    assert.equal(f.$(`${panelRecords} [data-agent-history-refresh]`).getAttribute('aria-busy'), 'true');
    assert.doesNotMatch(f.$(`${panelRecords} [data-agent-history-body]`).textContent, /暂无对话记录/);
    f.$(`${panelRecords} [data-agent-history-refresh]`).click(); await settle();
    assert.equal(f.calls.filter(call => call.route === 'runs?page=1').length, 2);
    finish({ items: [], pages: 1 }); await settle();
    assert.equal(f.$(`${panelRecords} [data-agent-history-page]`).textContent, '1 / 1');
    assert.match(f.$(`${panelRecords} [data-agent-history-body]`).textContent, /暂无对话记录/);
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
    const cancel = f.$(`${panelRecords} li [aria-label="取消任务"]`);
    cancel.click(); cancel.click(); await settle();
    assert.equal(f.calls.filter(call => call.route === 'runs/12/cancel').length, 1);
    assert.equal(cancel.disabled, true);
    finish(); await settle();
    assert.equal(f.$('#oyiso-agent-panel-history').hidden, false);
    assert.match(f.$(`${panelRecords} li`).textContent, /已取消/);
    assert.equal(f.$(`${panelRecords} li [aria-label="取消任务"]`), null);
});

test('a missing conversation is removed from both histories and no longer polled or restored', async t => {
    let retries = 0;
    const key = 'oyiso-agent:1:/wp-json/oyiso-agent/v1/';
    const f = fixture(t, route => {
        if (route === 'runs?page=1') return { items: [{ id: 12, title: '已失效的临时任务', status: 'queued', updated: '2026-10-23' }], pages: 1 };
        if (route === 'runs/12') return { ok: false, status: 404, body: { code: 'agent_task_missing', message: '任务不存在或不属于当前用户。' } };
    }, {}, window => {
        window.localStorage.setItem(key, '12');
        window.localStorage.setItem(key + ':history-open', '1');
        const schedule = window.setTimeout.bind(window);
        window.setTimeout = (callback, delay, ...args) => { if (delay === 6000) retries++; return schedule(callback, delay, ...args); };
    });
    await settle();
    assert.equal(f.$('[data-agent-run="12"]'), null);
    assert.equal(f.$(`${panelRecords} [aria-label="取消任务"]`), null);
    assert.equal(f.window.localStorage.getItem(key), null);
    assert.match(f.$('[data-agent-notice]').textContent, /已不存在或无法访问/);
    assert.equal(f.$('[data-agent-send]').disabled, false);
    assert.equal(retries, 0, 'a permanent 404 must not keep scheduling retries');
    assert.equal(f.calls.find(call => call.route === 'runs/12').options.cache, 'no-store');
    f.$('#oyiso-agent-history [data-agent-history-refresh]').click(); await settle();
    assert.equal(f.$('[data-agent-run="12"]'), null, 'an older list response must not revive the missing record');
});

test('cancelling a missing history record clears its actions but transient errors keep it retryable', async t => {
    for (const status of [404, 500]) {
        const f = fixture(t, route => {
            if (route === 'runs?page=1') return { items: [{ id: 12, title: '等待执行的任务', status: 'queued', updated: '2026-10-23' }], pages: 1 };
            if (route === 'runs/12/cancel') return { ok: false, status, body: { code: status === 404 ? 'agent_task_missing' : 'server_error', message: status === 404 ? '任务不存在或不属于当前用户。' : '服务器暂时不可用' } };
        }, {}, undefined, { globalOnly: true });
        f.$('[data-agent-history-toggle]').click(); await settle();
        const cancel = f.$(`${panelRecords} [aria-label="取消任务"]`);
        cancel.click(); await settle();
        if (status === 404) {
            assert.equal(f.$('[data-agent-run="12"]'), null);
            assert.equal(f.$(`${panelRecords} [aria-label="取消任务"]`), null);
        } else {
            assert.equal(cancel.disabled, false);
            assert.match(f.$(`${panelRecords} [data-agent-history-notice]`).textContent, /服务器暂时不可用/);
            assert.ok(f.$('[data-agent-run="12"]'));
        }
    }
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
    const wrapper = f.$('#oyiso-agent-launcher-wrap');
    const body = f.window.document.body;
    f.$('#oyiso-agent-launcher').click();
    assert.equal(panel.hidden, false);
    assert.equal(wrapper.hidden, false, 'launcher stays painted while fading out');
    assert.equal(wrapper.hasAttribute('inert'), true);
    assert.equal(wrapper.getAttribute('aria-hidden'), 'true');
    f.window.dispatchEvent(new f.window.Event('resize'));
    assert.equal(wrapper.hidden, false, 'geometry updates must not interrupt the fade');
    f.$('#oyiso-agent-launcher').click();
    assert.equal(body.classList.contains('oyiso-agent-open'), true, 'fading launcher cannot close the panel again');
    assert.equal(panel.hasAttribute('inert'), false);
    assert.equal(panel.getAttribute('aria-hidden'), 'false');
    assert.equal(body.classList.contains('oyiso-agent-transitioning'), true);
    assert.equal(f.window.document.activeElement, f.$('[data-agent-close]'));
    transitionEnd(f, f.$('[data-agent-close]'));
    transitionEnd(f, panel, 'opacity');
    assert.equal(body.classList.contains('oyiso-agent-transitioning'), true);
    transitionEnd(f);
    assert.equal(body.classList.contains('oyiso-agent-transitioning'), false);
    assert.equal(wrapper.hidden, true);
    f.$('[data-agent-close]').click();
    assert.equal(panel.hidden, false, 'exit animation keeps the panel painted');
    assert.equal(panel.hasAttribute('inert'), true);
    assert.equal(panel.getAttribute('aria-hidden'), 'true');
    assert.equal(f.$('#oyiso-agent-launcher-wrap').hidden, false);
    assert.equal(wrapper.hasAttribute('inert'), false);
    assert.equal(wrapper.getAttribute('aria-hidden'), 'false');
    assert.equal(f.$('#oyiso-agent-launcher').getAttribute('aria-expanded'), 'false');
    assert.equal(f.window.document.activeElement, f.$('#oyiso-agent-launcher'));
    assert.equal(body.classList.contains('oyiso-agent-open'), false);
    transitionEnd(f);
    assert.equal(panel.hidden, true);
    assert.equal(wrapper.hidden, false);
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
    assert.equal(f.$('#oyiso-agent-launcher-wrap').hidden, true);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-transitioning'), false);
    assert.equal(f.window.localStorage.getItem('oyiso-agent:1:/wp-json/oyiso-agent/v1/:open'), '1');
    f.$('[data-agent-close]').click();
    await new Promise(resolve => setTimeout(resolve, 280));
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
    assert.equal(f.$('#oyiso-agent-launcher-wrap').hidden, false);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-transitioning'), false);
});

test('dragging the fading-in launcher settles the transition before moving and cancels stale cleanup', async t => {
    const f = fixture(t, undefined, {}, geometry, { globalOnly: true, motion: true });
    const wrapper = f.$('#oyiso-agent-launcher-wrap');
    f.$('[data-agent-close]').click();
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-transitioning'), true);
    const x = parseFloat(wrapper.style.left) + 8;
    const y = parseFloat(wrapper.style.top) + 8;
    pointer(f, f.$('#oyiso-agent-launcher'), 'pointerdown', x, y);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-transitioning'), false);
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
    pointer(f, f.window.document, 'pointermove', x - 100, y - 80);
    const moved = wrapper.style.left;
    assert.equal(wrapper.classList.contains('is-dragging'), true);
    await new Promise(resolve => setTimeout(resolve, 280));
    assert.equal(wrapper.classList.contains('is-dragging'), true, 'old animation timeout must not cancel the active drag');
    assert.equal(wrapper.style.left, moved);
    pointer(f, f.window.document, 'pointerup', x - 100, y - 80);
    assert.equal(wrapper.hidden, false);
    assert.equal(wrapper.classList.contains('is-dragging'), false);
});

test('disabling the agent during launcher fade hides both controls immediately and cancels pending animation', async t => {
    const f = fixture(t, route => route === 'status' ? { enabled: false, supported: true, secrets: {} } : undefined, {}, geometry, { closed: true, motion: true });
    f.$('#oyiso-agent-launcher').click();
    assert.equal(f.$('#oyiso-agent-launcher-wrap').hidden, false);
    f.window.jQuery(f.window.document).trigger('ajaxSuccess', [{ responseJSON: { success: true, data: { errors: {} } } }, { data: 'action=csf_oyiso_ajax_save' }]);
    await settle();
    assert.equal(f.$('#oyiso-agent-launcher-wrap').hidden, true);
    assert.equal(f.$('#oyiso-agent-launcher-wrap').hasAttribute('inert'), true);
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-transitioning'), false);
    await new Promise(resolve => setTimeout(resolve, 280));
    f.window.dispatchEvent(new f.window.Event('resize'));
    assert.equal(f.$('#oyiso-agent-launcher-wrap').hidden, true);
    assert.equal(f.$('#oyiso-agent-panel').hidden, true);
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
    assert.equal(reduced.$('#oyiso-agent-launcher-wrap').hidden, true);
    assert.equal(reduced.window.document.body.classList.contains('oyiso-agent-transitioning'), false);
    reduced.$('[data-agent-close]').click();
    assert.equal(reduced.$('#oyiso-agent-panel').hidden, true);
    assert.equal(reduced.$('#oyiso-agent-launcher-wrap').hidden, false);
    const restored = fixture(t, undefined, {}, undefined, { globalOnly: true, motion: true });
    assert.equal(restored.$('#oyiso-agent-panel').hidden, false);
    assert.equal(restored.$('#oyiso-agent-launcher-wrap').hidden, true);
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
    assert.match(f.$('[data-agent-messages]').textContent, /正在加载/);
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
    assert.deepEqual(rows.map(row => row.querySelectorAll('button').length), [3, 3, 3, 2, 2, 2]);
    const button = rows[0].querySelector('[aria-label="取消任务"]');
    assert.equal(button.getAttribute('aria-label'), '取消任务');
    assert.equal(rows[0].querySelector('button:first-child').textContent, '查看对话');
    button.click(); button.click(); await settle();
    assert.equal(f.calls.filter(call => call.route === 'runs/12/cancel').length, 1);
    assert.equal(f.calls.find(call => call.route === 'runs/12/cancel').options.headers['X-WP-Nonce'], 'fixture-nonce');
    finish(); await settle();
    assert.match(f.$('#oyiso-agent-history tbody tr:first-child').textContent, /已取消/);
    assert.equal(f.$('#oyiso-agent-history tbody tr:first-child').querySelectorAll('button').length, 2);
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

test('fullscreen fills the viewport and restores floating geometry, draft and accessible controls', t => {
    const f = floatingWindow(t);
    const panel = f.$('#oyiso-agent-panel');
    const toggle = f.$('[data-agent-fullscreen-toggle]');
    const original = windowRect(f);
    const saved = f.window.localStorage.getItem(windowKey + ':window');
    inputDraft(f, '全屏时保留草稿');
    f.$('[data-agent-history-toggle]').click();
    assert.equal(panel.dataset.historyLayout, 'overlay');
    for (const control of panel.querySelectorAll('.oyiso-agent-icon-button')) {
        assert.ok(control.querySelector('svg'));
        assert.ok(control.getAttribute('aria-label'));
        assert.equal(control.querySelector('.dashicons'), null);
    }
    toggle.click();
    assert.deepEqual(windowRect(f), { x: 0, y: 0, width: 1600, height: 1000 });
    assert.equal(panel.hasAttribute('data-agent-fullscreen'), true);
    assert.equal(toggle.getAttribute('aria-pressed'), 'true');
    assert.equal(toggle.getAttribute('aria-label'), '退出全屏');
    assert.equal(f.$('[data-agent-fullscreen-icon="enter"]').hidden, true);
    assert.equal(f.$('[data-agent-fullscreen-icon="exit"]').hidden, false);
    assert.equal(f.$('[data-agent-drag]').tabIndex, -1);
    assert.equal(panel.dataset.historyLayout, 'sidebar');
    assert.equal(f.$('#oyiso-agent-chat').hasAttribute('inert'), false);
    assert.equal(f.window.localStorage.getItem(windowKey + ':window'), saved);
    assert.equal(f.window.localStorage.getItem(windowKey + ':layout'), 'floating');
    for (const edge of ['w', 'e', 'n', 's', 'nw', 'ne', 'sw', 'se']) resizeFloating(f, edge, 100, 100);
    const heading = f.$('[data-agent-drag]');
    pointer(f, heading, 'pointerdown', 300, 180);
    pointer(f, f.window.document, 'pointermove', 500, 280);
    pointer(f, f.window.document, 'pointerup', 500, 280);
    heading.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
    f.$('[data-agent-resize="n"]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
    assert.deepEqual(windowRect(f), { x: 0, y: 0, width: 1600, height: 1000 });
    toggle.click();
    assert.deepEqual(windowRect(f), original);
    assert.equal(panel.hasAttribute('data-agent-fullscreen'), false);
    assert.equal(toggle.getAttribute('aria-pressed'), 'false');
    assert.equal(toggle.getAttribute('aria-label'), '全屏');
    assert.equal(f.$('[data-agent-fullscreen-icon="enter"]').hidden, false);
    assert.equal(f.$('[data-agent-fullscreen-icon="exit"]').hidden, true);
    assert.equal(f.$('[data-agent-drag]').tabIndex, 0);
    assert.equal(f.$('[data-agent-resize="n"]').tabIndex, 0);
    assert.equal(f.$('#oyiso-agent-prompt').value, '全屏时保留草稿');
    assert.equal(f.window.localStorage.getItem(windowKey + ':window'), saved);
});

test('fullscreen tracks viewport changes and restores saved geometry after temporary viewport shrink', t => {
    const f = floatingWindow(t);
    const original = windowRect(f);
    const saved = f.window.localStorage.getItem(windowKey + ':window');
    f.$('[data-agent-fullscreen-toggle]').click();
    f.window.innerWidth = 375; f.window.innerHeight = 600;
    f.window.dispatchEvent(new f.window.Event('resize'));
    assert.deepEqual(windowRect(f), { x: 0, y: 0, width: 375, height: 600 });
    f.$('[data-agent-fullscreen-toggle]').click();
    assert.deepEqual(windowRect(f), { x: 5, y: 25, width: 365, height: 500 });
    assert.equal(f.window.localStorage.getItem(windowKey + ':window'), saved);
    f.window.innerWidth = 1600; f.window.innerHeight = 1000;
    f.window.dispatchEvent(new f.window.Event('resize'));
    assert.deepEqual(windowRect(f), original);
});

test('fullscreen from a split panel restores its side and width on Escape and yields to layout changes', t => {
    const f = fixture(t, undefined, {}, window => {
        geometry(window);
        window.localStorage.setItem(windowKey + ':side', 'left');
        window.localStorage.setItem(windowKey + ':width', '700');
    }, { globalOnly: true });
    const toggle = f.$('[data-agent-fullscreen-toggle]');
    toggle.click();
    assert.deepEqual(windowRect(f), { x: 0, y: 0, width: 1600, height: 1000 });
    assert.equal(f.window.localStorage.getItem(windowKey + ':layout'), 'docked');
    f.window.document.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(f.$('#oyiso-agent-panel').hasAttribute('data-agent-window'), false);
    assert.equal(f.$('#oyiso-agent-panel').dataset.agentDock, 'left');
    assert.equal(f.$('[data-agent-resize="e"]').getAttribute('aria-valuenow'), '700');
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(toggle.getAttribute('aria-pressed'), 'false');
    toggle.click(); chooseLayout(f, 'right');
    assert.equal(f.$('#oyiso-agent-panel').hasAttribute('data-agent-fullscreen'), false);
    assert.equal(f.$('#oyiso-agent-panel').dataset.agentDock, 'right');
    assert.equal(f.window.localStorage.getItem(windowKey + ':fullscreen'), '0');
});

test('fullscreen restores across navigation without entrance flashing and Escape preserves pinned history', t => {
    const f = floatingWindow(t, window => {
        window.localStorage.setItem(windowKey + ':fullscreen', '1');
        window.localStorage.setItem(windowKey + ':history-open', '1');
    }, { motion: true });
    assert.deepEqual(windowRect(f), { x: 0, y: 0, width: 1600, height: 1000 });
    assert.equal(f.$('[data-agent-fullscreen-toggle]').getAttribute('aria-pressed'), 'true');
    assert.equal(f.$('#oyiso-agent-launcher-wrap').hidden, true);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-transitioning'), false);
    f.$('[data-agent-close]').click(); f.$('#oyiso-agent-launcher').click(); transitionEnd(f);
    assert.equal(f.$('#oyiso-agent-panel').hasAttribute('data-agent-fullscreen'), true);
    f.window.document.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.deepEqual(windowRect(f), { x: 250, y: 125, width: 600, height: 500 });
    assert.equal(f.$('#oyiso-agent-panel-history').hidden, false);
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(f.window.localStorage.getItem(windowKey + ':fullscreen'), '0');
});

test('resetting a fullscreen panel restores default geometry and preserves the floating layout and draft', t => {
    const f = floatingWindow(t);
    inputDraft(f, '恢复全屏布局时保留');
    f.$('[data-agent-fullscreen-toggle]').click(); f.$('[data-agent-reset]').click();
    assert.equal(f.$('#oyiso-agent-panel').hasAttribute('data-agent-fullscreen'), false);
    assert.equal(f.$('[data-agent-fullscreen-toggle]').getAttribute('aria-pressed'), 'false');
    assert.equal(f.window.localStorage.getItem(windowKey + ':fullscreen'), '0');
    assert.equal(f.window.localStorage.getItem(windowKey + ':layout'), 'floating');
    assert.equal(f.window.localStorage.getItem(windowKey + ':window'), null);
    assert.deepEqual(windowRect(f), { x: 1115, y: 275, width: 480, height: 720 });
    assert.equal(f.$('#oyiso-agent-prompt').value, '恢复全屏布局时保留');
});

test('layout menu supports both split sides, keyboard navigation, dismissal and saved side restoration', async t => {
    const f = fixture(t, undefined, {}, geometry, { globalOnly: true });
    const menu = f.$('[data-agent-layout-menu]');
    const toggle = f.$('[data-agent-settings-toggle]');
    toggle.click();
    assert.equal(f.$('[data-agent-settings-menu]').hidden, false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(f.window.document.activeElement.dataset.agentLayoutChoice, 'left');
    menu.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    assert.equal(f.window.document.activeElement.dataset.agentLayoutChoice, 'right');
    menu.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
    assert.equal(f.window.document.activeElement.dataset.agentLayoutChoice, 'left');
    f.window.document.activeElement.click();
    assert.equal(f.$('[data-agent-settings-menu]').hidden, true);
    assert.equal(f.$('#oyiso-agent-panel').dataset.agentDock, 'left');
    assert.equal(f.$('[data-agent-layout-choice="left"]').getAttribute('aria-checked'), 'true');
    assert.equal(f.window.localStorage.getItem(windowKey + ':side'), 'left');
    toggle.click();
    menu.dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(f.$('[data-agent-settings-menu]').hidden, true);
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
    assert.equal(f.window.document.activeElement, toggle);
    toggle.click();
    f.window.document.body.click();
    assert.equal(f.$('[data-agent-settings-menu]').hidden, true);
    const restored = fixture(t, undefined, {}, window => {
        geometry(window);
        window.localStorage.setItem(windowKey + ':side', 'left');
    }, { globalOnly: true });
    assert.equal(restored.$('#oyiso-agent-panel').dataset.agentDock, 'left');
    chooseLayout(restored, 'right');
    assert.equal(restored.$('#oyiso-agent-panel').dataset.agentDock, 'right');
    chooseLayout(restored, 'floating');
    assert.equal(restored.$('#oyiso-agent-panel').hasAttribute('data-agent-window'), true);
    assert.equal(restored.$('[data-agent-layout-choice="floating"]').getAttribute('aria-checked'), 'true');
    assert.equal(menu.querySelectorAll('button').length, 4);
    assert.equal(menu.querySelector('[aria-haspopup]'), null);
    await settle();
});

test('narrow windows report the floating fallback and disable split choices until there is enough room', t => {
    const f = fixture(t, undefined, {}, window => {
        geometry(window, 1023, 900); window.document.body.classList.add('block-editor-page');
        window.localStorage.setItem(windowKey + ':side', 'left');
    }, { globalOnly: true });
    const panel = f.$('#oyiso-agent-panel'), left = f.$('[data-agent-layout-choice="left"]'), right = f.$('[data-agent-layout-choice="right"]'), floating = f.$('[data-agent-layout-choice="floating"]');
    assert.equal(panel.hasAttribute('data-agent-window'), true); assert.equal(left.disabled, true); assert.equal(right.disabled, true);
    assert.equal(floating.getAttribute('aria-checked'), 'true'); assert.match(left.title, /窗口较窄/);
    f.$('[data-agent-settings-toggle]').click(); assert.equal(f.window.document.activeElement, floating);
    f.$('[data-agent-settings-menu]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    assert.equal(f.window.document.activeElement, f.$('[data-agent-reset]'));
    right.click(); assert.equal(f.window.localStorage.getItem(windowKey + ':side'), 'left');
    f.$('#oyiso-agent-prompt').value = 'Keep this draft';
    f.window.innerWidth = 1024; f.window.dispatchEvent(new f.window.Event('resize'));
    assert.equal(panel.dataset.agentDock, 'left'); assert.equal(panel.hasAttribute('data-agent-window'), false);
    assert.equal(left.disabled, false); assert.equal(right.disabled, false); assert.equal(left.getAttribute('aria-checked'), 'true');
    chooseLayout(f, 'right'); assert.equal(panel.dataset.agentDock, 'right');
    f.window.innerWidth = 900; f.window.dispatchEvent(new f.window.Event('resize'));
    assert.equal(floating.getAttribute('aria-checked'), 'true'); assert.equal(right.disabled, true);
    f.window.innerWidth = 1440; f.window.dispatchEvent(new f.window.Event('resize'));
    assert.equal(panel.dataset.agentDock, 'right'); assert.equal(right.getAttribute('aria-checked'), 'true');
    assert.equal(f.$('#oyiso-agent-prompt').value, 'Keep this draft');
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

test('block editor split modes leave room for the native editor in normal and fullscreen editing', t => {
    const f = fixture(t, undefined, {}, window => {
        geometry(window);
        const document = window.document;
        document.body.classList.add('js', 'block-editor-page', 'admin-bar', 'auto-fold');
        document.body.insertAdjacentHTML('afterbegin', '<div id="wpadminbar"></div><div id="adminmenumain"><div id="adminmenuback"></div><div id="adminmenuwrap"></div></div><div id="editor"><div class="interface-interface-skeleton editor-editor-interface"><div class="interface-interface-skeleton__editor"><header>Native editor header</header><main>Unsaved article content</main><aside>Document settings</aside></div></div></div><div id="wpfooter"></div>');
        window.localStorage.setItem(windowKey + ':window', JSON.stringify(initialWindow));
        const addStyle = source => { const style = document.createElement('style'); style.textContent = source; document.head.append(style); return style; };
        addStyle(['wp-admin/css/common.css', 'wp-admin/css/admin-menu.css', 'wp-includes/css/admin-bar.css'].map(file => fs.readFileSync(path.join(wpRoot, file), 'utf8')).join('\n'));
        const native = addStyle(fs.readFileSync(path.join(wpRoot, 'wp-includes/css/dist/editor/style.css'), 'utf8'));
        // Evaluate the installed editor's layout rules in source order; jsdom does not evaluate media queries.
        const active = [];
        const collect = rules => { for (const rule of rules) {
            if (rule.selectorText?.includes('interface-interface-skeleton')) active.push(rule.cssText);
            else if (rule.conditionText && rule.cssRules) {
                const min = Number(/min-width:\s*([\d.]+)px/.exec(rule.conditionText)?.[1] || 0);
                const max = Number(/max-width:\s*([\d.]+)px/.exec(rule.conditionText)?.[1] || Infinity);
                if (window.innerWidth >= min && window.innerWidth <= max) collect(rule.cssRules);
            }
        } };
        collect(native.sheet.cssRules); addStyle(active.join('\n'));
        const agent = addStyle(fs.readFileSync(path.join(repo, 'src/ai-agent/assets/agent.css'), 'utf8'));
        const desktop = Array.from(agent.sheet.cssRules).find(rule => rule.conditionText === '(min-width: 1024px)');
        addStyle(Array.from(desktop.cssRules, rule => rule.cssText).join('\n'));
    }, { globalOnly: true });
    const panel = f.$('#oyiso-agent-panel'), skeleton = f.$('.interface-interface-skeleton');
    const css = (element, property) => f.window.getComputedStyle(element).getPropertyValue(property);
    const offset = 'var(--oyiso-agent-panel-width)';
    const noMargin = property => assert.ok(!css(skeleton, property) || parseFloat(css(skeleton, property)) === 0);
    assert.equal(panel.dataset.agentDock, 'right'); assert.equal(panel.hasAttribute('data-agent-window'), false);
    assert.equal(css(skeleton, 'left'), '160px'); assert.equal(css(skeleton, 'margin-right'), offset); noMargin('margin-left');
    assert.equal(css(panel, 'top'), '0px'); assert.equal(css(panel, 'bottom'), '0px');
    chooseLayout(f, 'left');
    assert.equal(panel.dataset.agentDock, 'left'); assert.equal(css(skeleton, 'margin-left'), offset); noMargin('margin-right');
    f.window.document.body.classList.add('folded'); assert.equal(css(skeleton, 'left'), '36px');
    f.window.document.body.classList.add('is-fullscreen-mode');
    assert.equal(css(skeleton, 'left'), '0px'); assert.equal(css(skeleton, 'margin-left'), offset);
    assert.equal(css(f.window.document.body, '--oyiso-agent-admin-menu-width').trim(), '0px');
    f.$('#oyiso-agent-prompt').value = 'Keep the assistant draft';
    f.$('[data-agent-fullscreen-toggle]').click(); noMargin('margin-left'); noMargin('margin-right');
    f.$('[data-agent-fullscreen-toggle]').click(); assert.equal(panel.dataset.agentDock, 'left'); assert.equal(css(skeleton, 'margin-left'), offset);
    chooseLayout(f, 'floating'); const rectangle = windowRect(f); noMargin('margin-left'); noMargin('margin-right');
    chooseLayout(f, 'right'); assert.equal(css(skeleton, 'margin-right'), offset);
    chooseLayout(f, 'floating'); assert.deepEqual(windowRect(f), rectangle);
    chooseLayout(f, 'left'); f.$('[data-agent-close]').click(); noMargin('margin-left'); noMargin('margin-right');
    f.$('#oyiso-agent-launcher').click(); assert.equal(css(skeleton, 'margin-left'), offset);
    assert.equal(skeleton.querySelector('main').textContent, 'Unsaved article content');
    assert.equal(f.$('#oyiso-agent-prompt').value, 'Keep the assistant draft');
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
        const desktop = Array.from(agent.sheet.cssRules).find(rule => rule.conditionText === '(min-width: 1024px)');
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
    assert.equal(css(bar, 'width'), 'auto');
    assert.equal(f.$(panel).dataset.agentDock, 'right');
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
    assert.deepEqual(windowRect(f), { x: 1115, y: 275, width: 480, height: 720 });
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
        assert.deepEqual(windowRect(f), { x: 250, y: 125, width: 600, height: 500 });
        const dx = edge.includes('w') ? -40 : edge.includes('e') ? 60 : 0;
        const dy = edge.includes('n') ? -30 : edge.includes('s') ? 50 : 0;
        resizeFloating(f, edge, dx, dy);
        const expected = { x: edge.includes('w') ? 210 : 250, y: edge.includes('n') ? 95 : 125, width: 600 + (edge.includes('w') ? 40 : edge.includes('e') ? 60 : 0), height: 500 + (edge.includes('n') ? 30 : edge.includes('s') ? 50 : 0) };
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
    assert.deepEqual(windowRect(f), { x: -435, y: 5, width: 600, height: 500 });
    pointer(f, f.window.document, 'pointermove', 4000, 4000);
    pointer(f, f.window.document, 'pointerup', 4000, 4000);
    assert.deepEqual(windowRect(f), { x: 1435, y: 919, width: 600, height: 500 });
    assert.equal(f.window.innerWidth - 5 - windowRect(f).x, 160);
    assert.ok(windowRect(f).y + 76 <= f.window.innerHeight - 5);
    assert.ok(windowRect(f).y + windowRect(f).height > f.window.innerHeight);
    assert.equal(f.window.document.body.classList.contains('oyiso-agent-dragging'), false);
    assert.equal(f.$('#oyiso-agent-panel').hidden, false);
});

test('horizontal overflow survives reload and viewport changes while keeping the title bar recoverable', t => {
    for (const direction of [-1, 1]) {
        const f = floatingWindow(t);
        const heading = f.$('[data-agent-drag]');
        pointer(f, heading, 'pointerdown', 280, 145);
        pointer(f, f.window.document, 'pointermove', direction * 4000, 145);
        pointer(f, f.window.document, 'pointerup', direction * 4000, 145);
        const moved = windowRect(f);
        assert.equal(direction < 0 ? moved.x + moved.width - 5 : 1595 - moved.x, 160);
        const saved = f.window.localStorage.getItem(windowKey + ':window');
        const restored = floatingWindow(t, window => window.localStorage.setItem(windowKey + ':window', saved));
        assert.deepEqual(windowRect(restored), moved);
        for (const width of [375, 120]) {
            restored.window.innerWidth = width; restored.window.innerHeight = 600;
            restored.window.dispatchEvent(new restored.window.Event('resize'));
            const small = windowRect(restored);
            assert.ok(Math.min(width - 5, small.x + small.width) - Math.max(5, small.x) >= Math.min(160, width - 10));
            assert.ok(small.y >= 5 && small.y + 76 <= 595);
            assert.equal(restored.window.localStorage.getItem(windowKey + ':window'), saved);
        }
        restored.window.innerWidth = 1600; restored.window.innerHeight = 1000;
        restored.window.dispatchEvent(new restored.window.Event('resize'));
        assert.deepEqual(windowRect(restored), moved);
        restored.$('[data-agent-fullscreen-toggle]').click(); restored.$('[data-agent-fullscreen-toggle]').click();
        chooseLayout(restored, 'left'); chooseLayout(restored, 'floating');
        assert.deepEqual(windowRect(restored), moved);
        for (let i = 0; i < 20; i++) restored.$('[data-agent-drag]').dispatchEvent(new restored.window.KeyboardEvent('keydown', { key: direction < 0 ? 'ArrowRight' : 'ArrowLeft', shiftKey: true, bubbles: true }));
        const recovered = windowRect(restored);
        assert.ok(recovered.x >= 5 && recovered.x + recovered.width <= 1595);
    }
});

test('resizing an overflowing window preserves the opposite edge and visible title-bar strip', t => {
    for (const direction of [-1, 1]) {
        const f = floatingWindow(t);
        const heading = f.$('[data-agent-drag]');
        pointer(f, heading, 'pointerdown', 280, 145);
        pointer(f, f.window.document, 'pointermove', direction * 4000, 145);
        pointer(f, f.window.document, 'pointerup', direction * 4000, 145);
        const original = windowRect(f);
        resizeFloating(f, direction < 0 ? 'e' : 'w', direction < 0 ? -4000 : 4000, 0);
        assert.deepEqual(windowRect(f), original, 'the visible resize edge cannot hide the last part of the title bar');
        for (const [edge, dx] of [['w', 4000], ['e', -4000], ['w', -4000], ['e', 4000]]) {
            const before = windowRect(f);
            resizeFloating(f, edge, dx, 0);
            const after = windowRect(f);
            assert.equal(edge === 'w' ? after.x + after.width : after.x, edge === 'w' ? before.x + before.width : before.x);
            assert.ok(after.width >= 360 && after.width <= 1590);
            assert.ok(Math.min(1595, after.x + after.width) - Math.max(5, after.x) >= 160);
            for (const handle of f.window.document.querySelectorAll('[data-agent-resize="w"], [data-agent-resize="e"]')) {
                assert.ok(Number(handle.getAttribute('aria-valuemin')) <= after.width);
                assert.ok(Number(handle.getAttribute('aria-valuemax')) >= after.width);
            }
        }
    }
});

test('viewport-wide floating windows restore their horizontal overflow without losing the title bar', t => {
    for (const direction of [-1, 1]) {
        const f = floatingWindow(t);
        resizeFloating(f, 'w', -4000, 0); resizeFloating(f, 'e', 4000, 0);
        assert.equal(windowRect(f).width, 1590);
        pointer(f, f.$('[data-agent-drag]'), 'pointerdown', 280, 145);
        pointer(f, f.window.document, 'pointermove', direction * 4000, 145);
        pointer(f, f.window.document, 'pointerup', direction * 4000, 145);
        const moved = windowRect(f);
        const saved = f.window.localStorage.getItem(windowKey + ':window');
        const restored = floatingWindow(t, window => window.localStorage.setItem(windowKey + ':window', saved));
        assert.deepEqual(windowRect(restored), moved);
        assert.equal(direction < 0 ? moved.x + moved.width - 5 : 1595 - moved.x, 160);
        restored.window.innerWidth = 375;
        restored.window.dispatchEvent(new restored.window.Event('resize'));
        const small = windowRect(restored);
        assert.ok(Math.min(370, small.x + small.width) - Math.max(5, small.x) >= 160);
        restored.window.innerWidth = 1600;
        restored.window.dispatchEvent(new restored.window.Event('resize'));
        assert.deepEqual(windowRect(restored), moved);
    }
});

test('window minimum size and available viewport constrain all resize directions', t => {
    const f = floatingWindow(t);
    resizeFloating(f, 'nw', 4000, 4000);
    assert.deepEqual(windowRect(f), { x: 490, y: 265, width: 360, height: 360 });
    resizeFloating(f, 'nw', -4000, -4000);
    assert.deepEqual(windowRect(f), { x: 5, y: 5, width: 845, height: 620 });
    resizeFloating(f, 'se', 4000, 4000);
    assert.deepEqual(windowRect(f), { x: 5, y: 5, width: 1590, height: 990 });
    resizeFloating(f, 'se', -4000, -4000);
    assert.deepEqual(windowRect(f), { x: 5, y: 5, width: 360, height: 360 });
});

test('overflow geometry restores and adapts to a taller wrapped header without trapping the drag handle', t => {
    let headerHeight = 96;
    const before = window => Object.defineProperty(window.document.querySelector('[data-agent-drag]'), 'offsetHeight', { get: () => headerHeight });
    const f = floatingWindow(t, before);
    const heading = f.$('[data-agent-drag]');
    pointer(f, heading, 'pointerdown', 280, 145);
    pointer(f, f.window.document, 'pointermove', 280, 4000);
    pointer(f, f.window.document, 'pointerup', 280, 4000);
    assert.equal(windowRect(f).y, 899);
    assert.ok(windowRect(f).y + windowRect(f).height > 1000);
    const saved = f.window.localStorage.getItem(windowKey + ':window');
    const restored = floatingWindow(t, window => { before(window); window.localStorage.setItem(windowKey + ':window', saved); });
    assert.deepEqual(windowRect(restored), windowRect(f));
    resizeFloating(restored, 's', 0, 1000);
    assert.equal(windowRect(restored).y, 899);
    assert.equal(windowRect(restored).height, 990);
    headerHeight = 140;
    restored.window.innerWidth = 375; restored.window.innerHeight = 600;
    restored.window.dispatchEvent(new restored.window.Event('resize'));
    assert.equal(windowRect(restored).x, 5);
    assert.equal(windowRect(restored).y, 455);
    assert.equal(windowRect(restored).y + headerHeight, 595);
    for (let i = 0; i < 20; i++) restored.$('[data-agent-drag]').dispatchEvent(new restored.window.KeyboardEvent('keydown', { key: 'ArrowUp', shiftKey: true, bubbles: true }));
    assert.equal(windowRect(restored).y, 5);
    restored.$('[data-agent-reset]').click();
    assert.ok(windowRect(restored).y + windowRect(restored).height <= 595);
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
    assert.equal(small.x, 5);
    assert.equal(small.width, 365);
    assert.ok(small.y >= 5 && small.y + small.height <= 595);
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
    assert.deepEqual(windowRect(f), { x: 210, y: 115, width: 600, height: 500 });
    f.$('[data-agent-resize="e"]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    f.$('[data-agent-resize="s"]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    assert.deepEqual(windowRect(f), { x: 210, y: 115, width: 620, height: 520 });
    const old = windowRect(f);
    f.$('[data-agent-new]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
    assert.deepEqual(windowRect(f), old);
    f.$('[data-agent-resize="n"]').dispatchEvent(new f.window.KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
    assert.equal(windowRect(f).height, 360);
    assert.equal(windowRect(f).y + windowRect(f).height, 635);
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
    assert.equal(f.$('[data-agent-resize]').getAttribute('aria-valuenow'), '365');
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
