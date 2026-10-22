const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
const repo = path.resolve(__dirname, '../..');
const phpPath = path.join(repo, 'src/update-backup/backup/index.php').replaceAll('\\', '/').replaceAll("'", "\\'");
const markup = execFileSync('php', ['-r', `define('ABSPATH', '/'); function add_action(...$args){} function admin_url($path){return 'https://vape.local/wp-admin/'.$path;} function wp_nonce_url($url,$action){return $url.'&_wpnonce=fixture';} function esc_url($text){return htmlspecialchars($text,ENT_QUOTES);} function is_multisite(){return false;} require '${phpPath}'; Oyiso_Plugin_Backup::render();`], { encoding: 'utf8' });
const script = fs.readFileSync(path.join(repo, 'src/update-backup/backup/assets/backup.js'), 'utf8');
const payload = { plugin: 'oyiso', option_name: 'oyiso', blog_id: 1, data: { html: `<p>中文 O'Reilly \\ "quoted"</p>` } };
function fixture(t, router = () => ({ success: true, data: { message: '恢复成功' } })) {
    const dom = new JSDOM(`<form class="csf-options">${markup}</form>`, { url: 'https://vape.local/wp-admin/plugins.php?page=oyiso', runScripts: 'outside-only' });
    const { window } = dom;
    const $ = selector => window.document.querySelector(selector);
    const errors = []; const calls = []; const confirmations = []; const reloads = [];
    window.addEventListener('error', event => errors.push(event.error));
    window.confirm = message => { confirmations.push(message); return true; };
    const nativeTimeout = window.setTimeout.bind(window);
    window.setTimeout = (fn, delay, ...args) => delay === 800 ? reloads.push(fn) : nativeTimeout(fn, delay, ...args);
    window.oyisoPluginBackup = { ajaxUrl: 'https://vape.local/wp-admin/admin-ajax.php', nonce: 'fixture-import-nonce', maxBytes: 2097152 };
    window.fetch = async (url, options) => { calls.push({ url, options }); return { ok: true, json: async () => router(url, options) }; };
    let files = [];
    const input = $('#oyiso-plugin-backup-file');
    Object.defineProperty(input, 'files', { get: () => files });
    Object.defineProperty(input, 'value', { get: () => files.length ? 'C:\\fakepath\\backup.json' : '', set: value => { if (!value) files = []; } });
    window.eval(script);
    t.after(() => { window.close(); assert.deepEqual(errors, []); });
    return { window, $, calls, confirmations, reloads, select: (text = JSON.stringify(payload), name = 'backup.json') => { files = [new window.File([text], name, { type: 'application/json' })]; input.dispatchEvent(new window.Event('change', { bubbles: true })); } };
}
async function until(check) {
    for (let count = 0; count < 50; count++) {
        if (check()) return;
        await new Promise(resolve => setTimeout(resolve, 5));
    }
    assert.ok(check(), 'Expected the asynchronous backup operation to finish.');
}

test('export uses the authenticated download link and choosing a file does not submit or dirty the settings form', t => {
    const f = fixture(t);
    const url = new URL(f.$('#oyiso-plugin-backup-export').href);
    assert.equal(url.pathname, '/wp-admin/admin-post.php');
    assert.equal(url.searchParams.get('action'), 'oyiso_plugin_backup_export');
    assert.equal(f.$('#oyiso-plugin-backup-import').disabled, true);
    let changed = 0; f.$('form').addEventListener('change', () => changed++);
    let picked = 0; f.$('#oyiso-plugin-backup-file').click = () => picked++;
    f.$('#oyiso-plugin-backup-choose').click(); assert.equal(picked, 1);
    f.select(undefined, '<img src=x onerror=alert(1)>.json');
    assert.equal(changed, 0); assert.equal(f.calls.length, 0);
    assert.equal(f.$('#oyiso-plugin-backup-import').disabled, false);
    assert.equal(f.$('#oyiso-plugin-backup-file-meta').hidden, false);
    assert.equal(f.$('#oyiso-plugin-backup-file-name').querySelector('img'), null);
    f.$('#oyiso-plugin-backup-file-remove').click();
    assert.equal(f.$('#oyiso-plugin-backup-import').disabled, true);
    assert.equal(f.$('#oyiso-plugin-backup-file-meta').hidden, true);
});

test('the browser reads the selected JSON and posts only its text with a nonce, never multipart data', async t => {
    const f = fixture(t); const text = JSON.stringify(payload);
    f.select(text); f.$('#oyiso-plugin-backup-import').click(); f.$('#oyiso-plugin-backup-import').click();
    await until(() => f.reloads.length === 1);
    assert.equal(f.calls.length, 1); assert.equal(f.confirmations.length, 1);
    const { options } = f.calls[0];
    assert.equal(options.method, 'POST'); assert.equal(options.credentials, 'same-origin'); assert.equal(options.cache, 'no-store');
    assert.ok(options.body instanceof f.window.URLSearchParams);
    assert.deepEqual([...options.body.keys()], ['action', 'nonce', 'backup']);
    assert.equal(options.body.get('action'), 'oyiso_plugin_backup_import');
    assert.equal(options.body.get('nonce'), 'fixture-import-nonce');
    assert.equal(options.body.get('backup'), text);
    assert.equal(options.body.has('file'), false);
    assert.equal(f.$('#oyiso-plugin-backup-status').textContent, '恢复成功');
    assert.equal(f.$('#oyiso-plugin-backup-choose').disabled, true);
});

test('invalid JSON and a cancelled confirmation leave configuration untouched and controls usable', async t => {
    const f = fixture(t);
    for (const text of ['{', '[]', 'null']) {
        f.select(text); f.$('#oyiso-plugin-backup-import').click();
        await until(() => !f.$('#oyiso-plugin-backup-import').disabled);
        assert.match(f.$('#oyiso-plugin-backup-status').textContent, /有效的 JSON/);
    }
    assert.equal(f.calls.length, 0); assert.equal(f.confirmations.length, 0);
    f.window.confirm = () => false;
    f.select(); f.$('#oyiso-plugin-backup-import').click();
    await until(() => !f.$('#oyiso-plugin-backup-import').disabled);
    assert.equal(f.calls.length, 0); assert.equal(f.$('#oyiso-plugin-backup-status').textContent, '');
});

test('oversized files and read failures are rejected without sending any request', async t => {
    const f = fixture(t);
    f.select('a'.repeat(2097153));
    assert.equal(f.$('#oyiso-plugin-backup-import').disabled, true);
    assert.match(f.$('#oyiso-plugin-backup-status').textContent, /2 MB/);
    f.window.FileReader = class { readAsText() { this.onerror(); } };
    f.select(); f.$('#oyiso-plugin-backup-import').click();
    await until(() => !f.$('#oyiso-plugin-backup-import').disabled);
    assert.match(f.$('#oyiso-plugin-backup-status').textContent, /无法读取/);
    assert.equal(f.calls.length, 0);
});

test('UTF-8 BOM is accepted and PHP validation errors are shown safely without a reload', async t => {
    const message = '<img src=x onerror=alert(1)> invalid configuration';
    const f = fixture(t, () => ({ success: false, data: { message } }));
    f.select('\uFEFF' + JSON.stringify(payload)); f.$('#oyiso-plugin-backup-import').click();
    await until(() => !f.$('#oyiso-plugin-backup-import').disabled);
    assert.equal(f.calls[0].options.body.get('backup'), JSON.stringify(payload));
    assert.equal(f.$('#oyiso-plugin-backup-status').textContent, message);
    assert.equal(f.$('#oyiso-plugin-backup-status').querySelector('img'), null);
    assert.equal(f.reloads.length, 0);
});

test('network and non-JSON server failures restore controls for a retry', async t => {
    const f = fixture(t);
    for (const failure of [async () => { throw new Error('offline'); }, async () => ({ ok: false, json: async () => { throw new Error('HTML response'); } }), async () => ({ ok: true, json: async () => null })]) {
        f.window.fetch = failure;
        f.select(); f.$('#oyiso-plugin-backup-import').click();
        await until(() => !f.$('#oyiso-plugin-backup-import').disabled);
        assert.equal(f.$('#oyiso-plugin-backup-status').className, 'is-error');
        assert.equal(f.$('#oyiso-plugin-backup-choose').disabled, false);
    }
    assert.equal(f.reloads.length, 0);
});
