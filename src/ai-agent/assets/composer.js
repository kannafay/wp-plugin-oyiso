(() => {
    'use strict';
    window.oyisoAgentComposer = ({ panel, prompt, key, api, notice }) => {
        const input = panel.querySelector('[data-agent-file]');
        const trigger = panel.querySelector('[data-agent-attach]');
        const previews = panel.querySelector('[data-agent-attachments]');
        const drafts = new Map(); let current = 0, urls = [];
        const textExtensions = ['txt', 'md', 'csv', 'tsv', 'json', 'html', 'htm', 'xml', 'log', 'yaml', 'yml', 'css', 'js', 'ts', 'php', 'sql'];
        const allowed = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf', ...textExtensions];
        const node = (tag, text) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; return el; };
        const maxFile = window.oyisoAgent.attachmentMaxFile || 4194304;
        const maxTotal = window.oyisoAgent.attachmentMaxTotal || 8388608;
        const size = bytes => bytes < 1048576 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1048576).toFixed(1)} MB`;
        const db = window.indexedDB ? new Promise(resolve => {
            const request = indexedDB.open('oyiso-agent-attachment-drafts', 1);
            request.onupgradeneeded = () => request.result.createObjectStore('drafts');
            request.onsuccess = () => resolve(request.result); request.onerror = () => resolve(null);
        }) : Promise.resolve(null);
        const files = () => drafts.get(current) || [];
        function persist(id) {
            const items = drafts.get(id) || [];
            db.then(database => { if (!database) return; try { const store = database.transaction('drafts', 'readwrite').objectStore('drafts'); if (items.length) store.put(items, `${key}:${id}`); else store.delete(`${key}:${id}`); } catch { /* In-memory drafts remain usable. */ } });
        }
        function resize() {
            const scroll = panel.querySelector('.oyiso-agent-scroll');
            const pinned = scroll && scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 90;
            prompt.style.height = '0px';
            const style = getComputedStyle(prompt);
            const min = parseFloat(style.minHeight) || 42;
            const max = parseFloat(style.maxHeight) || 160;
            const height = Math.max(min, Math.min(prompt.scrollHeight + 2, max));
            prompt.style.height = `${height}px`;
            prompt.style.overflowY = prompt.scrollHeight + 2 > max ? 'auto' : 'hidden';
            if (pinned) scroll.scrollTop = scroll.scrollHeight;
        }
        function render() {
            urls.forEach(url => URL.revokeObjectURL?.(url)); urls = [];
            previews.replaceChildren(); previews.hidden = !files().length;
            files().forEach((file, index) => {
                const item = node('div'); item.className = 'oyiso-agent-attachment';
                if (/^image\/(png|jpeg|webp|gif)$/.test(file.type) && URL.createObjectURL) {
                    const image = node('img'); image.alt = ''; image.src = URL.createObjectURL(file); urls.push(image.src); item.append(image);
                }
                const label = node('span', file.name); label.title = file.name; item.append(label, node('small', size(file.size)));
                const remove = node('button', '×'); remove.type = 'button'; remove.className = 'oyiso-agent-icon-button'; remove.setAttribute('aria-label', `移除附件：${file.name}`);
                remove.disabled = prompt.disabled;
                remove.addEventListener('click', () => { drafts.set(current, files().filter((_, i) => i !== index)); persist(current); render(); }); item.append(remove); previews.append(item);
            });
            resize();
        }
        function add(incoming) {
            if (trigger.disabled || prompt.disabled) return;
            const items = [...files()]; let message = '';
            for (const file of incoming) {
                const extension = file.name.split('.').pop().toLowerCase();
                if (!allowed.includes(extension)) { message = '支持图片、PDF 和常见文本文件。'; continue; }
                if (!file.size || file.size > maxFile || (textExtensions.includes(extension) && file.size > 80000)) { message = `${file.name} 超过大小限制，图片/PDF 每个最多 ${size(maxFile)}，文本最多 80 KB。`; continue; }
                if (items.some(item => item.name === file.name && item.size === file.size && item.lastModified === file.lastModified)) continue;
                if (items.length >= 5 || items.reduce((sum, item) => sum + item.size, file.size) > maxTotal) { message = `每次最多 5 个附件，总大小不超过 ${size(maxTotal)}。`; break; }
                items.push(file);
            }
            drafts.set(current, items); persist(current); render(); notice(message, !!message);
        }
        async function switchTo(id) {
            current = id; render();
            if (drafts.has(id)) return;
            const database = await db;
            if (!database) return;
            try {
                const request = database.transaction('drafts').objectStore('drafts').get(`${key}:${id}`);
                request.onsuccess = () => { if (drafts.has(id)) return; const saved = request.result; drafts.set(id, Array.isArray(saved) ? saved.filter(file => file instanceof Blob) : []); if (current === id) render(); };
            } catch { /* Storage is optional. */ }
        }
        function clear(id) { drafts.set(id, []); persist(id); if (id === current) render(); }
        function renderFiles(parent, attachments) {
            if (!attachments?.length) return;
            const list = node('div'); list.className = 'oyiso-agent-message-attachments';
            for (const file of attachments) {
                const button = node('button', `${file.name} · ${size(file.size)}`); button.type = 'button'; button.title = `下载附件：${file.name}`;
                button.addEventListener('click', async () => {
                    button.disabled = true;
                    try {
                        const data = await api(`files/${file.id}`);
                        const bytes = Uint8Array.from(atob(data.data), char => char.charCodeAt(0));
                        const url = URL.createObjectURL(new Blob([bytes], { type: data.mime }));
                        const link = node('a'); link.href = url; link.download = data.name; document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 60000);
                    } catch (error) { notice(error.message, true); }
                    finally { button.disabled = false; }
                }); list.append(button);
            }
            parent.append(list);
        }
        trigger.addEventListener('click', () => input.click());
        input.addEventListener('change', event => { event.stopPropagation(); add([...input.files]); input.value = ''; });
        prompt.addEventListener('paste', event => {
            const incoming = [...(event.clipboardData?.files || [])];
            if (!incoming.length) return;
            event.preventDefault();
            const text = event.clipboardData.getData('text/plain');
            if (text && !prompt.disabled) { const available = prompt.maxLength - prompt.value.length + prompt.selectionEnd - prompt.selectionStart; prompt.setRangeText(text.slice(0, Math.max(0, available)), prompt.selectionStart, prompt.selectionEnd, 'end'); prompt.dispatchEvent(new Event('input', { bubbles: true })); }
            add(incoming);
        });
        const area = panel.querySelector('.oyiso-agent-composer');
        area.addEventListener('dragover', event => { if ([...(event.dataTransfer?.types || [])].includes('Files')) event.preventDefault(); });
        area.addEventListener('drop', event => { if (!event.dataTransfer?.files.length) return; event.preventDefault(); add([...event.dataTransfer.files]); });
        prompt.addEventListener('input', resize);
        window.addEventListener('resize', resize);
        if (window.ResizeObserver) new ResizeObserver(resize).observe(area);
        return { files, switchTo, clear, resize, renderFiles, size };
    };
})();
