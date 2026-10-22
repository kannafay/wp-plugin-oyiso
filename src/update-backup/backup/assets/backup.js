(() => {
    'use strict';
    const panel = document.querySelector('.oyiso-plugin-backup-panel');
    const config = window.oyisoPluginBackup;
    if (!panel || !config) return;
    const $ = selector => panel.querySelector(selector);
    const input = $('#oyiso-plugin-backup-file');
    const choose = $('#oyiso-plugin-backup-choose');
    const restore = $('#oyiso-plugin-backup-import');
    const remove = $('#oyiso-plugin-backup-file-remove');
    const meta = $('#oyiso-plugin-backup-file-meta');
    const name = $('#oyiso-plugin-backup-file-name');
    const status = $('#oyiso-plugin-backup-status');
    let busy = false;
    const selected = () => input.files && input.files[0];
    const notify = (message = '', state = '') => { status.textContent = message; status.className = state; };
    function controls() {
        const file = selected();
        choose.disabled = input.disabled = remove.disabled = busy;
        restore.disabled = busy || !file;
        meta.hidden = !file;
        name.textContent = file ? file.name : '';
    }
    function read(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => typeof reader.result === 'string' ? resolve(reader.result) : reject(new Error('无法读取所选文件，请重新选择。'));
            reader.onerror = reader.onabort = () => reject(new Error('无法读取所选文件，请重新选择。'));
            reader.readAsText(file, 'UTF-8');
        });
    }
    // Selecting a backup is not a change to the CSF settings form.
    for (const type of ['change', 'input', 'keypress']) panel.addEventListener(type, event => event.stopPropagation());
    choose.addEventListener('click', () => input.click());
    input.addEventListener('change', () => {
        notify();
        if (selected() && selected().size > config.maxBytes) {
            input.value = '';
            notify('备份文件不得超过 2 MB。', 'is-error');
        }
        controls();
    });
    remove.addEventListener('click', () => { input.value = ''; notify(); controls(); });
    restore.addEventListener('click', async () => {
        const file = selected();
        if (busy || !file) return;
        busy = true;
        controls();
        notify('正在读取备份…');
        let restored = false;
        try {
            if (file.size > config.maxBytes) throw new Error('备份文件不得超过 2 MB。');
            const json = (await read(file)).replace(/^\uFEFF/, '');
            let payload;
            try { payload = JSON.parse(json); } catch { throw new Error('所选文件不是有效的 JSON 备份。'); }
            if (!payload || typeof payload !== 'object' || Array.isArray(payload)) throw new Error('所选文件不是有效的 JSON 备份。');
            if (!window.confirm('恢复配置将覆盖当前插件设置，确定继续吗？')) { notify(); return; }
            notify('正在恢复配置，请稍候…');
            const response = await fetch(config.ajaxUrl, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                body: new URLSearchParams({ action: 'oyiso_plugin_backup_import', nonce: config.nonce, backup: json })
            });
            let result;
            try { result = await response.json(); } catch { throw new Error('恢复失败，请稍后重试。'); }
            const message = typeof result?.data?.message === 'string' ? result.data.message : '';
            if (!response.ok || result?.success !== true) throw new Error(message || '恢复失败，请稍后重试。');
            restored = true;
            notify(message || '插件配置已恢复，正在刷新页面。', 'is-success');
            window.setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            notify(typeof error?.message === 'string' ? error.message : '恢复失败，请稍后重试。', 'is-error');
        } finally {
            busy = restored;
            controls();
        }
    });
    controls();
})();
