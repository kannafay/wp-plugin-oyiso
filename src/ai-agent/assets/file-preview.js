(() => {
    'use strict';
    const supported = mime => /^(image\/(png|jpeg|webp|gif)|video\/(mp4|webm))$/.test(mime);
    const localMime = file => supported(file?.type) ? file.type : ({ png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', webp: 'image/webp', gif: 'image/gif', mp4: 'video/mp4', webm: 'video/webm' }[file?.name?.split('.').pop().toLowerCase()] || '');
    const node = (tag, text) => { const element = document.createElement(tag); if (text !== undefined) element.textContent = text; return element; };
    const size = bytes => bytes < 1048576 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1048576).toFixed(1)} MB`;
    let dialog, title, detail, body, status, save, retry, footerClose, current, sequence = 0, url = '', controller;

    function release() {
        controller?.abort(); controller = null;
        const video = body?.querySelector('video');
        if (video) { video.pause(); video.removeAttribute('src'); video.load(); }
        body?.replaceChildren();
        if (url) URL.revokeObjectURL(url);
        url = '';
    }

    function close() {
        if (!dialog?.open || dialog.classList.contains('is-closing')) return;
        ++sequence; controller?.abort(); body.querySelector('video')?.pause();
        const trigger = current?.trigger; current = null;
        window.oyisoModalMotion.hide(dialog, () => { release(); if (trigger?.isConnected && !trigger.disabled) trigger.focus({ preventScroll: true }); });
    }

    function create() {
        if (dialog) return;
        dialog = node('dialog'); dialog.className = 'oyiso-agent-file-preview oyiso-confirm-dialog'; dialog.setAttribute('aria-labelledby', 'oyiso-agent-file-preview-title'); dialog.setAttribute('aria-describedby', 'oyiso-agent-file-preview-meta');
        const header = node('div'); header.className = 'oyiso-agent-file-preview-header oyiso-confirm-dialog__heading';
        const heading = node('div'); title = node('h2'); title.id = 'oyiso-agent-file-preview-title'; detail = node('p'); detail.id = 'oyiso-agent-file-preview-meta'; heading.append(title, detail);
        const actions = node('div'); actions.className = 'oyiso-agent-file-preview-actions oyiso-confirm-dialog__footer';
        save = node('button', '下载'); save.type = 'button'; save.className = 'button button-primary'; save.disabled = true;
        save.addEventListener('click', () => {
            if (!url || !current) return;
            const link = node('a'); link.href = url; link.download = current.file.name; document.body.append(link); link.click(); link.remove();
        });
        const dismiss = node('button', '×'); dismiss.type = 'button'; dismiss.className = 'oyiso-agent-file-preview-close oyiso-confirm-dialog__close'; dismiss.setAttribute('aria-label', '关闭预览'); dismiss.addEventListener('click', close);
        header.append(heading, dismiss);
        status = node('div'); status.className = 'oyiso-agent-file-preview-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        retry = node('button', '重试'); retry.type = 'button'; retry.className = 'button oyiso-agent-file-preview-retry'; retry.hidden = true; retry.addEventListener('click', load);
        footerClose = node('button', '关闭'); footerClose.type = 'button'; footerClose.className = 'button'; footerClose.addEventListener('click', close);
        body = node('div'); body.className = 'oyiso-agent-file-preview-content';
        const content = node('div'); content.className = 'oyiso-agent-file-preview-body oyiso-confirm-dialog__body'; content.append(status, body);
        actions.append(retry, footerClose, save);
        dialog.append(header, content, actions); document.body.append(dialog);
        dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
        dialog.addEventListener('keydown', event => { if (event.key === 'Escape' || event.key === 'Tab') event.stopPropagation(); });
        dialog.addEventListener('click', event => { if (event.target === dialog) close(); });
        document.addEventListener('oyiso-agent-files-deleted', event => { if (current && event.detail.includes(current.file.id)) close(); });
    }

    function message(text, busy = false) {
        status.textContent = text; status.hidden = !text; status.classList.toggle('is-loading', busy); dialog.setAttribute('aria-busy', String(busy));
    }

    async function load() {
        if (!current) return;
        const active = ++sequence; const entry = current;
        release(); controller = new AbortController(); save.disabled = true; retry.hidden = true;
        message('正在加载预览…', true);
        try {
            let blob = entry.blob; let name = entry.file.name; let mime = blob?.type;
            if (!blob) {
                const data = await entry.api(`files/${entry.file.id}`, { signal: controller.signal });
                name = data.name; mime = data.mime;
                if (!supported(mime)) throw new Error('此文件暂不支持在线预览，请下载后查看。');
                const bytes = Uint8Array.from(atob(data.data), char => char.charCodeAt(0));
                blob = new Blob([bytes], { type: mime });
            }
            if (active !== sequence || !dialog.open) return;
            if (!supported(mime)) throw new Error('此文件暂不支持在线预览，请下载后查看。');
            url = URL.createObjectURL(blob);
            current.file = { ...entry.file, name };
            title.textContent = name; title.title = name; detail.textContent = `${mime.startsWith('video/') ? '视频' : '图片'} · ${size(blob.size)}`;
            const media = node(mime.startsWith('video/') ? 'video' : 'img');
            if (media.tagName === 'IMG') media.alt = name;
            else { media.controls = true; media.playsInline = true; media.preload = 'metadata'; }
            media.addEventListener(media.tagName === 'IMG' ? 'load' : 'loadedmetadata', () => { if (active === sequence) message(''); });
            media.addEventListener('error', () => { if (active === sequence) message('浏览器无法显示这个文件，可能是不支持的编码，请下载后查看。'); });
            media.src = url; body.append(media); save.disabled = false;
            message('');
        } catch (error) {
            if (active !== sequence || error.name === 'AbortError') return;
            message(error.message || '预览加载失败，请重试。'); retry.hidden = false;
        }
    }

    window.oyisoAgentFilePreview = {
        can: file => !!file && !file.deleted && supported(file.mime),
        canLocal: file => file instanceof Blob && supported(localMime(file)),
        openLocal(file, trigger) {
            const mime = localMime(file);
            if (!(file instanceof Blob) || !supported(mime)) return;
            this.open({ name: file.name, size: file.size, mime }, null, trigger, new Blob([file], { type: mime }));
        },
        open(file, api, trigger, blob) {
            if (!file || file.deleted || !supported(file.mime)) return;
            create(); current = { file, api, trigger, blob }; title.textContent = file.name; title.title = file.name; detail.textContent = size(file.size);
            window.oyisoModalMotion.show(dialog);
            footerClose.focus();
            load();
        },
        close,
    };
})();
