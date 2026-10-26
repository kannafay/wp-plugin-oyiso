(() => {
    'use strict';
    const seed = window.oyisoAgent?.pageContext;
    if (!seed?.screen) return;
    const badge = document.querySelector('[data-agent-page-context]');
    let selected = '';
    const cut = (value, limit) => Array.from(value).slice(0, limit).join('');
    const labels = { post: '文章', page: '页面', product: '商品' };
    const seo = { seo_title: 'seopress_titles_title', seo_description: 'seopress_titles_desc', seo_keywords: 'seopress_analysis_target_kw' };
    const input = name => document.getElementById(name) || document.querySelector(`[name="${name}"]`);
    const text = id => {
        const editor = window.tinymce?.get(id);
        return editor && !editor.isHidden() ? editor.getContent() : input(id)?.value;
    };
    const blockEditor = () => {
        try { const store = window.wp?.data?.select('core/editor'); return store?.getCurrentPostId?.() ? store : null; } catch { return null; }
    };
    function snapshot() {
        const address = new URL(location.href), params = new URLSearchParams();
        for (const key of ['post', 'post_type', 'action', 'page', 'tab']) if (address.searchParams.has(key)) params.set(key, address.searchParams.get(key));
        const page = { ...seed, fields: {}, url: address.pathname + (params.size ? '?' + params : '') + address.hash, selection: selected, truncated: false };
        if (!seed.post_id || !Object.keys(seed.fields || {}).length) return page;
        page.fields = { ...seed.fields };
        const store = blockEditor();
        const inBlockEditor = store && Number(store.getCurrentPostId()) === seed.post_id;
        if (inBlockEditor) {
            page.editor = 'gutenberg';
            for (const key of ['title', 'content', 'excerpt']) {
                const value = key === 'content' ? store.getEditedPostContent() : store.getEditedPostAttribute(key);
                const field = seed.post_type === 'product' ? ({ content: 'description', excerpt: 'short_description' })[key] || key : key;
                if (typeof value === 'string') page.fields[field] = value;
            }
            page.dirty = !!store.isEditedPostDirty();
            try {
                const blocks = window.wp.data.select('core/block-editor');
                const selectedBlocks = blocks?.getMultiSelectedBlocks?.() || [];
                const block = blocks?.getSelectedBlock?.();
                if (window.wp.blocks?.serialize && (selectedBlocks.length || block)) page.selection = cut(window.wp.blocks.serialize(selectedBlocks.length ? selectedBlocks : [block]), 5000);
            } catch { /* Some non-post editors do not provide a block selection store. */ }
        } else if (input('content')) {
            page.editor = 'classic';
            const values = { title: input('title')?.value, [seed.post_type === 'product' ? 'description' : 'content']: text('content'), [seed.post_type === 'product' ? 'short_description' : 'excerpt']: text('excerpt') };
            for (const [key, value] of Object.entries(values)) if (typeof value === 'string') page.fields[key] = value;
        } else page.editor = 'unsupported';
        for (const [key, id] of Object.entries(seo)) if (typeof input(id)?.value === 'string' && Object.hasOwn(seed.fields, key)) page.fields[key] = input(id).value;
        // Gutenberg keeps its saved baseline up to date after savePost without a page reload.
        page.dirty ||= Object.entries(page.fields).some(([key, value]) => (!inBlockEditor || Object.hasOwn(seo, key)) && value !== seed.fields[key]);
        const selectedInEditor = window.tinymce?.get('content')?.selection?.getContent({ format: 'text' });
        if (selectedInEditor) page.selection = cut(selectedInEditor, 5000);
        for (const [key, value] of Object.entries(page.fields)) {
            const limit = ['content', 'description'].includes(key) ? 20000 : key === 'title' ? 300 : 4000;
            const points = Array.from(value);
            if (points.length > limit) { page.truncated = true; page.fields[key] = points.slice(0, limit).join(''); }
        }
        return page;
    }
    function refresh() {
        if (!badge) return;
        const page = snapshot();
        badge.hidden = false;
        badge.textContent = seed.post_id ? `当前${labels[seed.post_type] || '内容'}：${page.fields.title || seed.fields?.title || seed.label || '未命名'}（#${seed.post_id}）${page.dirty ? ' · 含未保存内容' : ''}` : `当前页面：${seed.label || document.title}`;
        badge.title = page.url;
    }
    function check(payload) {
        const current = snapshot();
        if (current.post_id !== payload.post_id || current.editor === 'unsupported' || current.truncated) throw new Error('请在对应的完整内容编辑页填入此方案。');
        for (const [key, expected] of Object.entries(payload.expected)) if (current.fields[key] !== expected) throw new Error('编辑器内容已改变，旧方案已过期。请重新发送修改要求生成新方案。');
        return current;
    }
    function apply(payload) {
        check(payload);
        const store = blockEditor();
        if (store) {
            const edits = {};
            let blocks;
            for (const [key, value] of Object.entries(payload.fields)) {
                if (Object.hasOwn(seo, key)) {
                    if (!input(seo[key])) throw new Error('当前编辑器没有可用的 SEO 字段，请使用保存到站点。');
                } else if (!['title', 'content', 'excerpt', 'description', 'short_description'].includes(key)) throw new Error('当前区块编辑器不支持此字段。');
                else if (key === 'content' || key === 'description') {
                    if (!window.wp.blocks?.parse) throw new Error('当前区块编辑器尚未就绪，请稍后重试。');
                    blocks = window.wp.blocks.parse(value);
                    if (blocks.some(block => block.isValid === false)) throw new Error('生成的区块结构无效，未填入编辑器。请重新生成方案。');
                } else edits[key === 'short_description' ? 'excerpt' : key] = value;
            }
            // editPost changes the editor state and leaves saving to the user.
            window.wp.data.dispatch('core/editor').editPost(edits);
            if (blocks) window.wp.data.dispatch('core/block-editor').resetBlocks(blocks);
        } else {
            const ids = { title: 'title', content: 'content', description: 'content', excerpt: 'excerpt', short_description: 'excerpt', ...seo };
            for (const key of Object.keys(payload.fields)) if (!input(ids[key])) throw new Error('当前编辑器没有对应字段，未填入内容。');
            for (const [key, value] of Object.entries(payload.fields)) {
                const id = ids[key], editor = window.tinymce?.get(id);
                if (editor && !editor.isHidden()) { editor.setContent(value); editor.fire('change'); editor.save(); }
                input(id).value = value;
                input(id).dispatchEvent(new Event('input', { bubbles: true })); input(id).dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        if (store) for (const [key, value] of Object.entries(payload.fields)) if (seo[key]) { input(seo[key]).value = value; input(seo[key]).dispatchEvent(new Event('change', { bubbles: true })); }
        refresh();
    }
    document.addEventListener('selectionchange', () => {
        const selection = window.getSelection();
        const anchor = selection?.anchorNode?.parentElement;
        if (anchor && !anchor.closest('.oyiso-agent') && anchor.closest('.editor-styles-wrapper, #poststuff')) selected = cut(selection.toString(), 5000);
    });
    let timer;
    const changed = () => { clearTimeout(timer); timer = setTimeout(refresh, 150); };
    document.addEventListener('input', changed); document.addEventListener('change', changed);
    window.wp?.data?.subscribe?.(changed);
    window.oyisoAgentPage = { snapshot, apply, check, refresh };
    if (seed.post_id && ['post', 'page', 'product'].includes(seed.post_type)) {
        const examples = document.querySelectorAll('[data-agent-example]');
        const type = labels[seed.post_type];
        const requests = [`检查当前${type}的内容，先列出可改进的地方。`, `优化当前${type}的文案，保留原意和未保存的修改，先生成预览。`];
        examples.forEach((button, index) => { if (requests[index]) { button.dataset.agentExample = requests[index]; button.textContent = index ? `优化当前${type}` : `检查当前${type}`; } });
    }
    refresh();
})();
