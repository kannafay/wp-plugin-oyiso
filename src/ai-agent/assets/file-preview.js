(() => {
    'use strict';
    const supported = mime => /^(image\/(png|jpeg|webp|gif)|video\/(mp4|webm))$/.test(mime);
    const node = (tag, text) => { const element = document.createElement(tag); if (text !== undefined) element.textContent = text; return element; };
    const size = bytes => bytes < 1048576 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1048576).toFixed(1)} MB`;
    let dialog, title, detail, body, status, save, retry, current, sequence = 0, url = '', controller;

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
        dialog = node('dialog'); dialog.className = 'oyiso-agent-file-preview'; dialog.setAttribute('aria-labelledby', 'oyiso-agent-file-preview-title');
        const header = node('div'); header.className = 'oyiso-agent-file-preview-header';
        const heading = node('div'); title = node('h2'); title.id = 'oyiso-agent-file-preview-title'; detail = node('p'); heading.append(title, detail);
        const actions = node('div'); actions.className = 'oyiso-agent-file-preview-actions';
        save = node('button', '下载'); save.type = 'button'; save.className = 'button'; save.disabled = true;
        save.addEventListener('click', () => {
            if (!url || !current) return;
            const link = node('a'); link.href = url; link.download = current.file.name; document.body.append(link); link.click(); link.remove();
        });
        const dismiss = node('button', '×'); dismiss.type = 'button'; dismiss.className = 'oyiso-agent-file-preview-close'; dismiss.setAttribute('aria-label', '关闭预览'); dismiss.addEventListener('click', close);
        actions.append(save, dismiss); header.append(heading, actions);
        status = node('div'); status.className = 'oyiso-agent-file-preview-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        retry = node('button', '重试'); retry.type = 'button'; retry.className = 'button'; retry.hidden = true; retry.addEventListener('click', load);
        body = node('div'); body.className = 'oyiso-agent-file-preview-content';
        dialog.append(header, status, retry, body); document.body.append(dialog);
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
            const data = await entry.api(`files/${entry.file.id}`, { signal: controller.signal });
            if (active !== sequence || !dialog.open) return;
            if (!supported(data.mime)) throw new Error('此文件暂不支持在线预览，请下载后查看。');
            const bytes = Uint8Array.from(atob(data.data), char => char.charCodeAt(0));
            url = URL.createObjectURL(new Blob([bytes], { type: data.mime }));
            current.file = { ...entry.file, name: data.name };
            title.textContent = data.name; title.title = data.name; detail.textContent = `${data.mime.startsWith('video/') ? '视频' : '图片'} · ${size(bytes.length)}`;
            const media = node(data.mime.startsWith('video/') ? 'video' : 'img');
            if (media.tagName === 'IMG') media.alt = data.name;
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
        open(file, api, trigger) {
            if (!file || file.deleted || !supported(file.mime)) return;
            create(); current = { file, api, trigger }; title.textContent = file.name; title.title = file.name; detail.textContent = size(file.size);
            window.oyisoModalMotion.show(dialog);
            load();
        },
        close,
    };
})();
