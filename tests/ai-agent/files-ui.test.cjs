const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
const repo = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/files.js'), 'utf8');
const previewSource = fs.readFileSync(path.join(repo, 'src/ai-agent/assets/file-preview.js'), 'utf8');
const phpPath = path.join(repo, 'src/ai-agent/file-manager.php').replaceAll('\\', '/');
const markup = execFileSync('php', ['-r', `define('ABSPATH', '/');function current_user_can($cap){return true;}require '${phpPath}';Oyiso_Agent_File_Manager::render();`], { encoding: 'utf8' });
const settle = async () => { for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve)); };
const file = (id, name = 'reference.md') => ({ id, name, mime: 'text/plain', size: 1200, run_id: 12, run_title: '资料分析', created: '2026-10-20 12:00:00', legacy: false });
const page = (items = [file(1), file(2, 'source.pdf')], extra = {}) => ({ items, total: items.length, bytes: 2400, pages: 1, directory: 'D:/private/files', migration: { remaining: 0, failed: 0 }, ...extra });

function fixture(t, route = () => page()) {
    const dom = new JSDOM(`<form id="csf-form">${markup}</form>`, { url: 'https://vape.local/wp-admin/plugins.php?page=oyiso', runScripts: 'outside-only' });
    const { window } = dom;
    window.oyisoAgentFiles = { url: 'https://vape.local/wp-json/oyiso-agent/v1/', nonce: 'fixture-nonce' };
    const calls = [], errors = [], objects = [], revoked = [], observers = [];
    window.IntersectionObserver = class {
        constructor(callback) { this.callback = callback; this.targets = new Set(); observers.push(this); }
        observe(target) { this.targets.add(target); }
        unobserve(target) { this.targets.delete(target); }
        disconnect() { this.targets.clear(); }
    };
    window.URL.createObjectURL = blob => { objects.push(blob); return `blob:https://vape.local/preview-${objects.length}`; };
    window.URL.revokeObjectURL = url => revoked.push(url);
    window.HTMLMediaElement.prototype.pause = function () { this.dataset.paused = 'true'; };
    window.HTMLMediaElement.prototype.load = function () { this.dataset.released = 'true'; };
    window.addEventListener('error', event => errors.push(event.error));
    window.fetch = async (url, options) => { calls.push({ route: url.replace(window.oyisoAgentFiles.url, ''), options }); const result = await route(calls.at(-1).route, options); return { ok: result.ok !== false, status: result.status || 200, json: async () => result.body || result }; };
    window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    window.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
    window.eval(previewSource); window.eval(source);
    t.after(() => { window.close(); assert.deepEqual(errors, []); });
    return { window, calls, objects, revoked, showThumbnails: () => observers.forEach(observer => observer.callback([...observer.targets].map(target => ({ target, isIntersecting: true })))), $: selector => window.document.querySelector(selector) };
}

test('file manager lists mixed files safely and sends search and type filters without dirtying settings', async t => {
    const f = fixture(t, () => page([file(1, '<img src=x onerror=alert(1)>.md'), { ...file(2, 'photo.webp'), mime: 'image/webp' }]));
    await settle();
    assert.equal(f.$('[data-files-body] img[src]'), null);
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
    assert.equal(f.window.document.activeElement, f.$('[data-files-delete-cancel]'));
    f.$('[data-files-delete-close]').click(); assert.equal(f.$('dialog').open, false);
    assert.equal(f.window.document.activeElement, f.$('[data-files-delete-selected]'));
    assert.equal(f.calls.filter(call => call.options.method === 'DELETE').length, 0);
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

test('image preview uses authenticated bytes and safely renders filenames then releases its URL on close', async t => {
    const photo = { ...file(5, '<img onerror=alert(1)>.webp'), mime: 'image/webp' };
    const f = fixture(t, route => route === 'files/5' ? { name: photo.name, mime: photo.mime, data: Buffer.from('private bytes').toString('base64') } : page([photo]));
    await settle();
    const trigger = f.$('[aria-label^="在线预览："]'); trigger.click(); await settle();
    assert.equal(f.$('.oyiso-agent-file-preview').open, true);
    assert.equal(f.$('.oyiso-agent-file-preview h2').textContent, photo.name);
    assert.equal(f.$('.oyiso-agent-file-preview img').src, 'blob:https://vape.local/preview-1');
    assert.equal(f.$('.oyiso-agent-file-preview img').alt, photo.name);
    assert.equal(f.$('.oyiso-agent-file-preview h2 img'), null);
    assert.equal(f.objects[0].type, 'image/webp'); assert.equal(f.objects[0].size, 13);
    const request = f.calls.find(call => call.route === 'files/5');
    assert.equal(request.options.credentials, 'same-origin'); assert.equal(request.options.headers['X-WP-Nonce'], 'fixture-nonce');
    f.$('[aria-label="关闭预览"]').click();
    assert.equal(f.$('.oyiso-agent-file-preview').open, false); assert.deepEqual(f.revoked, ['blob:https://vape.local/preview-1']);
    assert.equal(f.window.document.activeElement, trigger);
});

test('video preview provides native controls and stops playback when deleted or closed', async t => {
    const video = { ...file(6, 'clip.mp4'), mime: 'video/mp4' };
    const f = fixture(t, route => route === 'files/6' ? { name: video.name, mime: video.mime, data: Buffer.from('video bytes').toString('base64') } : page([video]));
    await settle(); f.$('[aria-label^="在线预览："]').click(); await settle();
    const player = f.$('.oyiso-agent-file-preview video');
    assert.equal(player.controls, true); assert.equal(player.playsInline, true); assert.equal(player.autoplay, false);
    assert.equal(player.preload, 'metadata'); assert.match(f.$('[data-files-body]').textContent, /视频/);
    f.window.document.dispatchEvent(new f.window.CustomEvent('oyiso-agent-files-deleted', { detail: [6] }));
    assert.equal(f.$('.oyiso-agent-file-preview').open, false); assert.equal(player.dataset.paused, 'true'); assert.equal(player.dataset.released, 'true');
    assert.equal(player.getAttribute('src'), null); assert.equal(f.revoked.length, 1);
});

test('preview rejects active file content and offers retry after access or network failure', async t => {
    const photo = { ...file(5, 'photo.png'), mime: 'image/png' }; let attempt = 0;
    const f = fixture(t, route => route !== 'files/5' ? page([photo]) : ++attempt === 1 ? { ok: false, status: 401, body: { message: '请重新登录' } } : { name: 'bad.html', mime: 'text/html', data: btoa('<script>alert(1)</script>') });
    await settle(); f.$('[aria-label^="在线预览："]').click(); await settle();
    assert.match(f.$('.oyiso-agent-file-preview-status').textContent, /重新登录/);
    f.$('.oyiso-agent-file-preview > .button').click(); await settle();
    assert.match(f.$('.oyiso-agent-file-preview-status').textContent, /暂不支持/);
    assert.equal(f.$('.oyiso-agent-file-preview-content').childElementCount, 0); assert.equal(f.objects.length, 0);
    assert.equal(f.window.oyisoAgentFilePreview.can({ mime: 'image/svg+xml' }), false);
    assert.equal(f.window.oyisoAgentFilePreview.can({ mime: 'image/png', deleted: true }), false);
});

test('closing a pending preview aborts it and stale responses cannot reopen or replace a newer image', async t => {
    const photo = { ...file(5, 'photo.png'), mime: 'image/png' }; let finish;
    const f = fixture(t, route => route === 'files/5' ? new Promise(resolve => { finish = resolve; }) : route === 'files/6' ? { name: 'new.png', mime: 'image/png', data: btoa('new bytes') } : page([photo]));
    await settle(); f.$('[aria-label^="在线预览："]').click(); await settle();
    const first = f.calls.find(call => call.route === 'files/5');
    f.$('[aria-label="关闭预览"]').click(); assert.equal(first.options.signal.aborted, true);
    f.window.oyisoAgentFilePreview.open({ ...photo, id: 6 }, async route => ({ name: 'new.png', mime: 'image/png', data: btoa(route) })); await settle();
    finish({ name: 'old.png', mime: 'image/png', data: btoa('old bytes') }); await settle();
    assert.equal(f.$('.oyiso-agent-file-preview h2').textContent, 'new.png'); assert.equal(f.objects.length, 1);
    const event = new f.window.Event('cancel', { cancelable: true }); f.$('.oyiso-agent-file-preview').dispatchEvent(event);
    assert.equal(event.defaultPrevented, true); assert.equal(f.$('.oyiso-agent-file-preview').open, false); assert.equal(f.revoked.length, 1);
});

test('visible thumbnails load through the private attachment API and are released when the list refreshes', async t => {
    const photo = { ...file(5, 'photo.webp'), mime: 'image/webp' };
    const f = fixture(t, route => route === 'files/5' ? { name: photo.name, mime: photo.mime, data: btoa('private thumbnail') } : page([photo]));
    await settle(); assert.equal(f.calls.some(call => call.route === 'files/5'), false);
    f.showThumbnails(); await settle();
    const image = f.$('.oyiso-agent-files-thumb img');
    assert.equal(image.src, 'blob:https://vape.local/preview-1'); assert.equal(image.width, 40); assert.equal(image.height, 40);
    image.dispatchEvent(new f.window.Event('load'));
    assert.equal(image.hidden, false); assert.equal(f.$('.oyiso-agent-files-thumb .dashicons').hidden, true);
    const request = f.calls.find(call => call.route === 'files/5');
    assert.equal(request.options.credentials, 'same-origin'); assert.equal(request.options.headers['X-WP-Nonce'], 'fixture-nonce');
    f.$('[aria-label="预览缩略图：photo.webp"]').click(); await settle();
    assert.equal(f.$('.oyiso-agent-file-preview').open, true); f.$('[aria-label="关闭预览"]').click();
    f.$('[data-files-refresh]').click(); await settle();
    assert.ok(f.revoked.includes('blob:https://vape.local/preview-1'));
    f.showThumbnails(); await settle(); f.$('.oyiso-agent-files-thumb img').dispatchEvent(new f.window.Event('error'));
    assert.equal(f.$('.oyiso-agent-files-thumb img').hidden, true); assert.equal(f.$('.oyiso-agent-files-thumb .dashicons').hidden, false);
    assert.equal(f.$('[data-files-notice]').textContent, '');
});

test('a changed search aborts pending thumbnails and late private bytes never enter the new rows', async t => {
    const photo = { ...file(5, 'old.webp'), mime: 'image/webp' }; let finish;
    const f = fixture(t, route => route === 'files/5' ? new Promise(resolve => { finish = resolve; }) : page(new URLSearchParams(route.split('?')[1]).get('search') === 'new' ? [file(6, 'new.md')] : [photo]));
    await settle(); f.showThumbnails(); await settle();
    const request = f.calls.find(call => call.route === 'files/5');
    f.$('#oyiso-agent-files-search').value = 'new'; f.$('[data-files-search]').click(); await settle();
    assert.equal(request.options.signal.aborted, true);
    finish({ name: photo.name, mime: photo.mime, data: btoa('old image') }); await settle();
    assert.match(f.$('[data-files-body]').textContent, /new.md/); assert.equal(f.$('[data-files-body] img'), null); assert.equal(f.objects.length, 0);
});
