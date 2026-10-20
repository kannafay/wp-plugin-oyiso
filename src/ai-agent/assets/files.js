(() => {
    'use strict';
    const config = window.oyisoAgentFiles;
    const root = document.getElementById('oyiso-agent-files');
    if (!config || !root) return;
    const $ = selector => root.querySelector(selector);
    const selected = new Set();
    const dialog = $('.oyiso-agent-files-confirm');
    let items = [], page = 1, pages = 1, sequence = 0, loading = false, deleting = false, pending = [], returnFocus;
    let releaseThumbnails = () => {};
    const node = (tag, text) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; return el; };
    const size = bytes => bytes < 1048576 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1048576).toFixed(1)} MB`;
    async function api(route, options = {}) {
        const response = await fetch(config.url + route, { credentials: 'same-origin', ...options, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce } });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || `请求失败（HTTP ${response.status}）`);
        return data;
    }
    function sync() {
        $('[data-files-delete-selected]').disabled = !selected.size || loading || deleting;
        $('[data-files-delete-selected]').textContent = selected.size ? `删除所选（${selected.size}）` : '删除所选';
        const all = $('[data-files-all]');
        all.checked = !!items.length && items.every(item => selected.has(item.id));
        all.indeterminate = !all.checked && selected.size > 0;
        all.disabled = !items.length || loading || deleting;
        root.querySelectorAll('[data-files-pick]').forEach(input => { input.checked = selected.has(Number(input.dataset.filesPick)); input.disabled = loading || deleting; });
        $('[data-files-prev]').disabled = page <= 1 || loading || deleting;
        $('[data-files-next]').disabled = page >= pages || loading || deleting;
    }
    async function download(file, button) {
        button.disabled = true;
        try {
            const data = await api(`files/${file.id}`);
            const bytes = Uint8Array.from(atob(data.data), char => char.charCodeAt(0));
            const url = URL.createObjectURL(new Blob([bytes], { type: data.mime }));
            const link = node('a'); link.href = url; link.download = data.name; document.body.append(link); link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (error) { $('[data-files-notice]').textContent = error.message; }
        finally { button.disabled = false; }
    }
    function thumbnails() {
        const controller = new AbortController(); const urls = new Set(); const queue = []; let active = 0;
        const observer = window.IntersectionObserver ? new IntersectionObserver(entries => {
            for (const entry of entries) if (entry.isIntersecting) { observer.unobserve(entry.target); queue.push(entry.target.loadThumbnail); }
            drain();
        }, { rootMargin: '80px' }) : null;
        function drain() {
            if (controller.signal.aborted) return;
            while (active < 3 && queue.length) {
                const work = queue.shift(); active++;
                work().finally(() => { active--; drain(); });
            }
        }
        releaseThumbnails = () => { controller.abort(); observer?.disconnect(); queue.length = 0; urls.forEach(url => URL.revokeObjectURL(url)); urls.clear(); };
        return (file, preview) => {
            const thumb = node(preview ? 'button' : 'span'); thumb.className = 'oyiso-agent-files-thumb';
            const icon = node('span'); icon.className = `dashicons dashicons-${file.mime.startsWith('image/') ? 'format-image' : file.mime.startsWith('video/') ? 'video-alt3' : file.mime === 'application/pdf' ? 'media-document' : 'media-text'}`; icon.setAttribute('aria-hidden', 'true'); thumb.append(icon);
            if (preview) { thumb.type = 'button'; thumb.setAttribute('aria-label', `预览缩略图：${file.name}`); thumb.addEventListener('click', () => window.oyisoAgentFilePreview.open(file, api, thumb)); }
            if (/^image\/(png|jpeg|webp|gif)$/.test(file.mime)) {
                const image = node('img'); image.alt = ''; image.width = image.height = 40; image.hidden = true; image.decoding = 'async'; thumb.append(image);
                thumb.loadThumbnail = async () => {
                    try {
                        const data = await api(`files/${file.id}`, { signal: controller.signal });
                        if (controller.signal.aborted || !thumb.isConnected || !/^image\/(png|jpeg|webp|gif)$/.test(data.mime)) return;
                        const bytes = Uint8Array.from(atob(data.data), char => char.charCodeAt(0));
                        const url = URL.createObjectURL(new Blob([bytes], { type: data.mime })); urls.add(url);
                        image.addEventListener('load', () => { image.hidden = false; icon.hidden = true; });
                        image.addEventListener('error', () => { URL.revokeObjectURL(url); urls.delete(url); image.removeAttribute('src'); image.hidden = true; icon.hidden = false; }, { once: true });
                        image.src = url;
                    } catch { /* Keep the type icon when the private image is unavailable. */ }
                };
                if (observer) observer.observe(thumb); else { queue.push(thumb.loadThumbnail); Promise.resolve().then(drain); }
            }
            return thumb;
        };
    }
    function render() {
        releaseThumbnails(); const thumbnail = thumbnails();
        const body = $('[data-files-body]'); body.replaceChildren();
        for (const file of items) {
            const row = node('tr'); const cell = node('td'); cell.className = 'check-column';
            const pick = node('input'); pick.type = 'checkbox'; pick.dataset.filesPick = file.id; pick.setAttribute('aria-label', `选择附件：${file.name}`);
            pick.addEventListener('change', () => { if (pick.checked) selected.add(file.id); else selected.delete(file.id); sync(); }); cell.append(pick); row.append(cell);
            const preview = window.oyisoAgentFilePreview?.can(file);
            const name = node('td'); const label = node('div'); label.className = 'oyiso-agent-files-label';
            const text = node('div'); const title = node(preview ? 'button' : 'span', file.name); title.className = 'oyiso-agent-files-name'; text.append(title);
            label.append(thumbnail(file, preview), text); name.append(label);
            if (preview) { title.type = 'button'; title.setAttribute('aria-label', `预览附件：${file.name}`); title.addEventListener('click', () => window.oyisoAgentFilePreview.open(file, api, title)); }
            if (file.legacy) text.append(node('small', '等待迁移到私有目录'));
            const type = file.mime.startsWith('image/') ? '图片' : file.mime.startsWith('video/') ? '视频' : file.mime === 'application/pdf' ? 'PDF' : '文本与代码';
            const run = node('td'); const runTitle = node('span', file.run_title); runTitle.className = 'oyiso-agent-files-run'; runTitle.title = file.run_title; run.append(runTitle);
            row.append(name, node('td', type), node('td', size(file.size)), run, node('td', file.created));
            const action = node('td'); const buttons = node('div'); buttons.className = 'oyiso-agent-files-actions';
            if (preview) { const view = node('button', '预览'); view.type = 'button'; view.className = 'button button-small'; view.setAttribute('aria-label', `在线预览：${file.name}`); view.addEventListener('click', () => window.oyisoAgentFilePreview.open(file, api, view)); buttons.append(view); }
            const get = node('button', '下载'); get.type = 'button'; get.className = 'button button-small'; get.setAttribute('aria-label', `下载附件：${file.name}`); get.addEventListener('click', () => download(file, get));
            const remove = node('button', '删除'); remove.type = 'button'; remove.className = 'button button-small'; remove.setAttribute('aria-label', `删除附件：${file.name}`); remove.addEventListener('click', () => confirmDelete([file.id], remove));
            buttons.append(get, remove); action.append(buttons); row.append(action); body.append(row);
        }
        if (!items.length) { const row = node('tr'); const cell = node('td', '暂无符合条件的附件'); cell.colSpan = 7; row.append(cell); body.append(row); }
        $('[data-files-page]').textContent = `${page} / ${pages}`; sync();
    }
    async function load() {
        const current = ++sequence; loading = true; selected.clear(); sync();
        $('[data-files-refresh]').setAttribute('aria-busy', 'true'); $('[data-files-notice]').textContent = '';
        try {
            const query = new URLSearchParams({ page, search: $('#oyiso-agent-files-search').value, type: $('#oyiso-agent-files-type').value });
            const data = await api(`files?${query}`); if (current !== sequence) return;
            pages = Math.max(1, data.pages);
            if (page > pages) { page = pages; return load(); }
            items = data.items; $('[data-files-summary]').textContent = `${data.total} 个文件 · ${data.total ? size(data.bytes) : '0 KB'}`;
            $('[data-files-directory]').textContent = data.directory ? `私有附件目录：${data.directory}` : '';
            $('[data-files-notice]').textContent = data.error || '';
            const migration = $('[data-files-migration]'); migration.hidden = !data.migration.remaining;
            $('[data-files-migration] span').textContent = `还有 ${data.migration.remaining} 个旧附件待迁移${data.migration.failed ? `，${data.migration.failed} 个迁移失败，原数据已保留` : '，后台会分批处理'}。`;
            $('[data-files-migrate]').textContent = data.migration.failed ? '重试迁移' : '迁移旧附件';
            render();
        } catch (error) { if (current === sequence) $('[data-files-notice]').textContent = error.message; }
        finally { if (current === sequence) { loading = false; $('[data-files-refresh]').setAttribute('aria-busy', 'false'); sync(); } }
    }
    function confirmDelete(ids, trigger) {
        if (deleting || loading || !ids.length) return;
        pending = [...ids]; returnFocus = trigger;
        $('[data-files-delete-description]').textContent = `确定删除${ids.length === 1 ? '这个' : `所选 ${ids.length} 个`}附件？文件删除后无法恢复，所属对话会保留并标记附件已删除。`;
        $('[data-files-delete-error]').hidden = true;
        window.oyisoModalMotion.show(dialog);
        $('[data-files-delete-cancel]').focus();
    }
    function close() {
        if (deleting || !dialog.open || dialog.classList.contains('is-closing')) return;
        pending = []; const trigger = returnFocus;
        window.oyisoModalMotion.hide(dialog, () => { if (trigger?.isConnected) trigger.focus({ preventScroll: true }); });
    }
    $('[data-files-delete-confirm]').addEventListener('click', async () => {
        if (deleting || !pending.length) return;
        deleting = true; sync(); $('[data-files-delete-confirm]').disabled = $('[data-files-delete-cancel]').disabled = $('[data-files-delete-close]').disabled = true;
        try {
            const result = await api('files', { method: 'DELETE', body: JSON.stringify({ ids: pending }) });
            document.dispatchEvent(new CustomEvent('oyiso-agent-files-deleted', { detail: result.deleted }));
            deleting = false; close(); await load();
        } catch (error) { $('[data-files-delete-error]').textContent = error.message; $('[data-files-delete-error]').hidden = false; }
        finally { deleting = false; $('[data-files-delete-confirm]').disabled = $('[data-files-delete-cancel]').disabled = $('[data-files-delete-close]').disabled = false; sync(); }
    });
    $('[data-files-delete-cancel]').addEventListener('click', close);
    $('[data-files-delete-close]').addEventListener('click', close);
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    dialog.addEventListener('keydown', event => { if (event.key === 'Escape' || event.key === 'Tab') event.stopPropagation(); });
    dialog.addEventListener('click', event => { if (event.target === dialog) close(); });
    $('[data-files-all]').addEventListener('change', event => { items.forEach(item => event.target.checked ? selected.add(item.id) : selected.delete(item.id)); sync(); });
    $('[data-files-delete-selected]').addEventListener('click', event => confirmDelete([...selected], event.currentTarget));
    $('[data-files-prev]').addEventListener('click', () => { if (page > 1 && !loading) { page--; load(); } });
    $('[data-files-next]').addEventListener('click', () => { if (page < pages && !loading) { page++; load(); } });
    $('[data-files-refresh]').addEventListener('click', load);
    $('[data-files-search]').addEventListener('click', () => { page = 1; load(); });
    $('#oyiso-agent-files-type').addEventListener('change', () => { page = 1; load(); });
    $('#oyiso-agent-files-search').addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); page = 1; load(); } });
    $('[data-files-migrate]').addEventListener('click', async event => {
        event.currentTarget.disabled = true;
        try { await api('files/migrate', { method: 'POST', body: '{}' }); await load(); }
        catch (error) { $('[data-files-notice]').textContent = error.message; }
        finally { $('[data-files-migrate]').disabled = false; }
    });
    for (const name of ['change', 'keypress']) root.addEventListener(name, event => event.stopPropagation());
    load();
})();
