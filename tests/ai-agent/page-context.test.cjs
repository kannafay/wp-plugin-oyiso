const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const script = fs.readFileSync(path.join(__dirname, '../../src/ai-agent/assets/page-context.js'), 'utf8');

function fixture(t, extra = {}, setup = () => {}) {
    const dom = new JSDOM('<p data-agent-page-context hidden></p><div id="poststuff"><input id="title" value="Hello world!"><textarea id="content">&lt;p&gt;Original&lt;/p&gt;</textarea><textarea id="excerpt">Summary</textarea><input name="seopress_titles_title" value="SEO title"></div><div class="oyiso-agent"><textarea id="prompt"></textarea></div>', { url: 'https://vape.local/wp-admin/post.php?post=7&action=edit&_wpnonce=secret&api_key=secret', runScripts: 'outside-only' });
    const { window } = dom;
    window.oyisoAgent = { pageContext: { screen: 'post', label: 'Hello world!', post_id: 7, post_type: 'post', editor: 'classic', dirty: false, signature: 'fixture-signature', fields: { title: 'Hello world!', content: '<p>Original</p>', excerpt: 'Summary', seo_title: 'SEO title' }, ...extra } };
    setup(window);
    window.eval(script);
    t.after(() => window.close());
    return { window, page: window.oyisoAgentPage, input: id => window.document.getElementById(id), badge: window.document.querySelector('[data-agent-page-context]') };
}

test('classic editor context includes unsaved text but excludes nonces and unrelated form values', t => {
    const f = fixture(t);
    f.input('title').value = 'Unpublished title';
    f.input('content').value = '<p>Unpublished body</p>';
    f.page.refresh();
    const page = f.page.snapshot();
    assert.equal(page.fields.title, 'Unpublished title');
    assert.equal(page.fields.content, '<p>Unpublished body</p>');
    assert.equal(page.dirty, true);
    assert.equal(page.url, '/wp-admin/post.php?post=7&action=edit');
    assert.doesNotMatch(JSON.stringify(page), /secret/);
    assert.match(f.badge.textContent, /当前文章.*Unpublished title.*含未保存内容/);
});

test('frontend context uses saved source fields and never reads matching storefront forms or editor globals', t => {
    const f = fixture(t, { screen: 'frontend', editor: 'frontend' }, window => {
        window.history.replaceState({}, '', '/shop/item/?page_id=7&key=secret&api_key=secret#access_token=secret');
        window.document.getElementById('content').value = 'Private customer form content';
        window.document.getElementById('title').value = 'A storefront form field';
        window.tinymce = { get() { throw new Error('Frontend must not read editor globals.'); } };
    });
    const page = f.page.snapshot();
    assert.equal(page.editor, 'frontend'); assert.equal(page.dirty, false);
    assert.equal(page.fields.content, '<p>Original</p>'); assert.equal(page.fields.title, 'Hello world!');
    assert.equal(page.url, '/shop/item/?page_id=7'); assert.match(f.badge.textContent, /前台浏览/);
    assert.throws(() => f.page.apply({ post_id: 7, expected: page.fields, fields: { content: 'Should not replace a form' } }), /完整内容编辑页/);
    assert.equal(f.input('content').value, 'Private customer form content');
});

test('frontend source content is bounded and pages without an editable object do not collect form fields', t => {
    const f = fixture(t, { screen: 'frontend', editor: 'frontend', fields: { title: 'Saved', content: 'x'.repeat(20001) } });
    assert.equal(f.page.snapshot().fields.content.length, 20000); assert.equal(f.page.snapshot().truncated, true);
    const home = fixture(t, { screen: 'frontend', editor: 'frontend', post_id: 0, fields: {}, label: '首页' });
    assert.deepEqual({ ...home.page.snapshot().fields }, {}); assert.match(home.badge.textContent, /当前页面：.*首页/);
});

test('classic editor fill changes requested fields without submitting or discarding other fields', t => {
    const f = fixture(t); let changes = 0;
    f.input('content').addEventListener('change', () => changes++);
    f.page.apply({ post_id: 7, expected: f.page.snapshot().fields, fields: { content: '<p>Updated</p>' } });
    assert.equal(f.input('content').value, '<p>Updated</p>');
    assert.equal(f.input('title').value, 'Hello world!');
    assert.equal(f.input('excerpt').value, 'Summary');
    assert.equal(changes, 1);
    assert.match(f.badge.textContent, /含未保存内容/);
});

test('late edits or a different object reject a fill before any field changes', t => {
    const f = fixture(t), expected = f.page.snapshot().fields;
    f.input('content').value = 'Manual later edit';
    assert.throws(() => f.page.apply({ post_id: 7, expected, fields: { title: 'Should not change', content: 'AI' } }), /编辑器内容已改变/);
    assert.equal(f.input('title').value, 'Hello world!');
    assert.throws(() => f.page.apply({ post_id: 8, expected: {}, fields: { content: 'Other post' } }), /对应/);
});

test('visual TinyMCE content and selections are read and synchronized with its textarea on fill', t => {
    let content = '<p>Visual unsaved</p>', saved = 0;
    const f = fixture(t, {}, window => {
        window.tinymce = { get: id => id === 'content' ? { isHidden: () => false, getContent: () => content, selection: { getContent: () => 'Selected paragraph' }, setContent: value => { content = value; }, fire: () => {}, save: () => saved++ } : null };
    });
    assert.equal(f.page.snapshot().fields.content, content);
    assert.equal(f.page.snapshot().selection, 'Selected paragraph');
    f.page.apply({ post_id: 7, expected: f.page.snapshot().fields, fields: { content: '<p>New visual content</p>' } });
    assert.equal(content, '<p>New visual content</p>'); assert.equal(saved, 1);
    assert.equal(f.input('content').value, content);
});

test('refilling after a reload accepts the saved baseline and preserves unrelated edits', t => {
    const f = fixture(t);
    const payload = { post_id: 7, expected: { content: '<p>Previous unsaved version</p>' }, restore_from: [{ content: '<p>Original</p>' }, { content: '<p>Recovered AI version</p>' }], fields: { content: '<p>Recovered AI version</p>' } };
    f.input('title').value = 'My later title'; f.input('excerpt').value = 'My later summary';
    f.page.apply(payload);
    assert.equal(f.input('content').value, '<p>Recovered AI version</p>');
    assert.equal(f.input('title').value, 'My later title'); assert.equal(f.input('excerpt').value, 'My later summary');
    assert.doesNotThrow(() => f.page.apply(payload), 'Refilling an already present version is safe.');
    f.input('content').value = '<p>Previous unsaved version</p>';
    assert.doesNotThrow(() => f.page.apply(payload));
    f.input('content').value = '<p>My newer manual edits</p>';
    assert.throws(() => f.page.apply(payload), /编辑器内容已改变/);
    assert.equal(f.input('content').value, '<p>My newer manual edits</p>');
    assert.throws(() => f.page.apply({ ...payload, post_id: 8 }), /对应/);
});

test('a recovery cannot mix baseline versions to silently replace a partially edited proposal', t => {
    const f = fixture(t);
    const payload = { post_id: 7, expected: { content: '<p>Unsaved</p>', excerpt: 'Unsaved summary' }, restore_from: [{ content: '<p>Original</p>', excerpt: 'Summary' }, { content: '<p>AI</p>', excerpt: 'AI summary' }], fields: { content: '<p>AI</p>', excerpt: 'AI summary' } };
    f.input('excerpt').value = 'AI summary';
    assert.throws(() => f.page.apply(payload), /编辑器内容已改变/);
    assert.equal(f.input('content').value, '<p>Original</p>'); assert.equal(f.input('excerpt').value, 'AI summary');
});

test('Gutenberg snapshots its live store and fills blocks through native editor actions', t => {
    let values = { title: 'Block title', content: '<!-- wp:paragraph --><p>Draft block</p><!-- /wp:paragraph -->', excerpt: 'Block excerpt' };
    let saves = 0, resets = 0;
    const f = fixture(t, { editor: 'gutenberg' }, window => {
        window.wp = { blocks: { parse: value => [{ html: value, isValid: true }] }, data: {
            select: () => ({ getCurrentPostId: () => 7, getEditedPostAttribute: key => values[key], getEditedPostContent: () => values.content, isEditedPostDirty: () => true }),
            dispatch: store => store === 'core/editor' ? { editPost: edits => Object.assign(values, edits), savePost: () => saves++ } : { resetBlocks: blocks => { resets++; values.content = blocks[0].html; } }, subscribe: () => () => {},
        } };
    });
    assert.equal(f.page.snapshot().fields.title, 'Block title');
    f.page.apply({ post_id: 7, expected: f.page.snapshot().fields, fields: { title: 'Rewritten title', content: '<p>New block</p>' } });
    assert.equal(values.title, 'Rewritten title'); assert.equal(values.content, '<p>New block</p>');
    assert.equal(resets, 1); assert.equal(saves, 0);
});

test('Gutenberg save clears the unsaved badge using the live store rather than the page load baseline', t => {
    let dirty = false;
    const values = { title: 'Hello world!', content: '<p>Original</p>', excerpt: 'Summary' };
    const f = fixture(t, { editor: 'gutenberg' }, window => {
        window.wp = { data: { select: () => ({ getCurrentPostId: () => 7, getEditedPostAttribute: key => values[key], getEditedPostContent: () => values.content, isEditedPostDirty: () => dirty }), subscribe: () => () => {} } };
    });
    values.content = '<p>Saved after AI fill</p>'; values.excerpt = 'New summary'; dirty = true;
    f.page.refresh();
    assert.match(f.badge.textContent, /含未保存内容/);
    dirty = false; f.page.refresh();
    assert.equal(f.page.snapshot().dirty, false);
    assert.equal(f.page.snapshot().fields.content, values.content);
    assert.doesNotMatch(f.badge.textContent, /含未保存内容/);
    f.window.document.querySelector('[name="seopress_titles_title"]').value = 'Unsaved SEO title';
    assert.equal(f.page.snapshot().dirty, true, 'Untracked SEO form edits still require saving.');
});

test('product fields map to classic long and short descriptions and preserve unrelated data', t => {
    const f = fixture(t, { screen: 'product', post_type: 'product', fields: { title: 'Product', description: '<p>Original</p>', short_description: 'Summary' } });
    f.input('title').value = 'Product';
    const before = f.page.snapshot();
    assert.equal(before.fields.description, '<p>Original</p>');
    assert.equal(before.fields.short_description, 'Summary');
    f.page.apply({ post_id: 7, expected: before.fields, fields: { short_description: 'New short description' } });
    assert.equal(f.input('excerpt').value, 'New short description');
    assert.equal(f.input('content').value, '<p>Original</p>');
    assert.match(f.badge.textContent, /当前商品/);
});

test('long content is marked truncated and cannot be used for a destructive full replacement', t => {
    const f = fixture(t); f.input('content').value = '文'.repeat(20001);
    const page = f.page.snapshot();
    assert.equal(page.fields.content.length, 20000); assert.equal(page.truncated, true);
    assert.throws(() => f.page.apply({ post_id: 7, expected: page.fields, fields: { content: 'Partial replacement' } }), /完整内容/);
});

test('disabled content tools and ordinary admin pages do not scrape forms', t => {
    const f = fixture(t, { post_id: 0, post_type: '', label: '模型配置', fields: {} });
    f.input('title').value = 'Sensitive field';
    assert.deepEqual(Object.keys(f.page.snapshot().fields), []);
    assert.equal(f.badge.textContent, '当前页面：模型配置');
});
