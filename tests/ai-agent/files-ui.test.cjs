const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
const repo = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/files.js'), 'utf8');
const phpPath = path.join(repo, 'src/ai-agent/file-manager.php').replaceAll('\\', '/');
const markup = execFileSync('php', ['-r', `define('ABSPATH', '/');function current_user_can($cap){return true;}require '${phpPath}';Oyiso_Agent_File_Manager::render();`], { encoding: 'utf8' });
const settle = async () => { for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve)); };
const file = (id, name = 'reference.md') => ({ id, name, mime: 'text/plain', size: 1200, run_id: 12, run_title: '资料分析', created: '2026-10-20 12:00:00', legacy: false });
const page = (items = [file(1), file(2, 'source.pdf')], extra = {}) => ({ items, total: items.length, bytes: 2400, pages: 1, directory: 'D:/private/files', migration: { remaining: 0, failed: 0 }, ...extra });

function fixture(t, route = () => page()) {
    const dom = new JSDOM(`<form id="csf-form">${markup}</form>`, { url: 'https://vape.local/wp-admin/plugins.php?page=oyiso', runScripts: 'outside-only' });
    const { window } = dom;
    window.oyisoAgentFiles = { url: 'https://vape.local/wp-json/oyiso-agent/v1/', nonce: 'fixture-nonce' };
    const calls = [], errors = [];
    window.addEventListener('error', event => errors.push(event.error));
    window.fetch = async (url, options) => { calls.push({ route: url.replace(window.oyisoAgentFiles.url, ''), options }); const result = await route(calls.at(-1).route, options); return { ok: result.ok !== false, status: result.status || 200, json: async () => result.body || result }; };
    window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    window.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
    window.eval(source);
    t.after(() => { window.close(); assert.deepEqual(errors, []); });
    return { window, calls, $: selector => window.document.querySelector(selector) };
}

test('file manager lists mixed files safely and sends search and type filters without dirtying settings', async t => {
    const f = fixture(t, () => page([file(1, '<img src=x onerror=alert(1)>.md'), { ...file(2, 'photo.webp'), mime: 'image/webp' }]));
    await settle();
    assert.equal(f.$('[data-files-body] img'), null);
    assert.match(f.$('[data-files-body]').textContent, /<img src=x onerror=alert\(1\)>/);
    assert.match(f.$('[data-files-body]').textContent, /图片/);
    assert.equal(f.calls[0].options.headers['X-WP-Nonce'], 'fixture-nonce');
    let changes = 0; f.$('#csf-form').addEventListener('change', () => changes++);
    f.$('#oyiso-agent-files-search').value = 'price & sku';
    f.$('#oyiso-agent-files-type').value = 'text';
    f.$('#oyiso-agent-files-type').dispatchEvent(new f.window.Event('change', { bubbles: true }));
    await settle();
    assert.equal(changes, 0);
    const query = new URLSearchParams(f.calls.at(-1).route.split('?')[1]);
    assert.equal(query.get('search'), 'price & sku'); assert.equal(query.get('type'), 'text');
});

test('file deletion requires confirmation and batch deletion emits the deleted IDs', async t => {
    let removed = false;
    const f = fixture(t, (route, options) => options.method === 'DELETE' ? (removed = true, { deleted: JSON.parse(options.body).ids }) : page(removed ? [] : [file(1), file(2)]));
    await settle();
    f.$('[data-files-all]').checked = true; f.$('[data-files-all]').dispatchEvent(new f.window.Event('change', { bubbles: true }));
    f.$('[data-files-delete-selected]').click();
    assert.equal(f.calls.filter(call => call.options.method === 'DELETE').length, 0);
    assert.equal(f.$('dialog').open, true); assert.match(f.$('[data-files-delete-description]').textContent, /2 个/);
    f.$('[data-files-delete-cancel]').click(); assert.equal(f.$('dialog').open, false);
    f.$('[data-files-delete-selected]').click();
    let notified; f.window.document.addEventListener('oyiso-agent-files-deleted', event => { notified = [...event.detail]; });
    f.$('[data-files-delete-confirm]').click(); await settle();
    assert.deepEqual(notified, [1, 2]); assert.equal(f.$('dialog').open, false); assert.match(f.$('[data-files-body]').textContent, /暂无/);
});

test('failed deletion keeps the confirmation dialog and selection for retry', async t => {
    const f = fixture(t, (_, options) => options.method === 'DELETE' ? { ok: false, status: 409, body: { message: '对话正在执行' } } : page());
    await settle(); f.$('[aria-label="删除附件：reference.md"]').click(); f.$('[data-files-delete-confirm]').click(); await settle();
    assert.equal(f.$('dialog').open, true); assert.equal(f.$('[data-files-delete-error]').hidden, false);
    assert.equal(f.$('[data-files-delete-error]').textContent, '对话正在执行'); assert.equal(f.$('[data-files-delete-confirm]').disabled, false);
    assert.equal(f.$('[data-files-body]').querySelectorAll('tr').length, 2);
});

test('migration state and failures are visible and retry refreshes the list', async t => {
    let migrated = false;
    const f = fixture(t, route => route === 'files/migrate' ? (migrated = true, { migrated: 1, remaining: 0, failed: 0 }) : page([file(1)], { migration: { remaining: migrated ? 0 : 1, failed: migrated ? 0 : 1 } }));
    await settle(); assert.equal(f.$('[data-files-migration]').hidden, false); assert.equal(f.$('[data-files-migrate]').textContent, '重试迁移');
    f.$('[data-files-migrate]').click(); await settle();
    assert.equal(f.$('[data-files-migration]').hidden, true); assert.equal(f.$('[data-files-migrate]').disabled, false);
});

test('stale search responses cannot overwrite a newer file selection', async t => {
    let first;
    const f = fixture(t, route => new URLSearchParams(route.split('?')[1]).get('search') === 'new' ? page([file(3, 'new.md')]) : new Promise(resolve => { first = resolve; }));
    await settle(); f.$('#oyiso-agent-files-search').value = 'new'; f.$('[data-files-search]').click(); await settle();
    assert.match(f.$('[data-files-body]').textContent, /new.md/); first(page([file(1, 'old.md')])); await settle();
    assert.doesNotMatch(f.$('[data-files-body]').textContent, /old.md/);
});
