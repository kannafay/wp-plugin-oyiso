(() => {
    'use strict';
    const root = document.querySelector('[data-agent-settings]');
    const config = window.oyisoModelSettings;
    if (!root || !config) return;
    const $ = selector => root.querySelector(selector);
    const dialog = $('[data-provider-dialog]');
    const fields = Object.fromEntries(Array.from(dialog.querySelectorAll('[data-provider-field]'), input => [input.dataset.providerField, input]));
    const initial = JSON.parse(root.dataset.state);
    const presets = initial.presets;
    const protocols = initial.protocols;
    const defaults = document.querySelector('[data-provider-default]');
    const external = Array.from(defaults.options).filter(option => option.value === '' || option.value.startsWith('wp:')).map(option => [option.value, option.textContent]);
    let rows = initial.connections;
    let editing = '';
    let busy = false;
    let revision = 0;
    const testing = new Set();
    let opener;
    let cancelCloseMotion;
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    const make = (tag, text, className = '') => { const element = document.createElement(tag); if (text !== undefined) element.textContent = text; element.className = className; return element; };
    const notify = (text, error = false) => { const notice = $('[data-provider-notice]'); notice.textContent = text; notice.hidden = !text; notice.classList.toggle('is-error', error); };
    async function api(route, method = 'GET', data, signal) {
        const response = await fetch(config.url + route, { method, credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' }, ...(data === undefined ? {} : { body: JSON.stringify(data) }), ...(signal ? { signal } : {}) });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || `请求失败（HTTP ${response.status}）。`);
        return result;
    }
    function updateDefaults() {
        const selected = defaults.value;
        defaults.replaceChildren();
        for (const [value, label] of external) defaults.add(new Option(label, value));
        for (const row of rows) {
            const group = make('optgroup'); group.label = row.name;
            for (const model of row.models) group.append(new Option(`${row.name} / ${model}`, `${row.id}|${model}`));
            defaults.append(group);
        }
        if (selected && !Array.from(defaults.options).some(option => option.value === selected)) defaults.add(new Option('原默认模型已移除，请重新选择', selected));
        defaults.value = selected;
    }
    function action(label, callback, className = '') {
        const button = make('button', label, `button button-small ${className}`);
        button.type = 'button'; button.disabled = busy; button.addEventListener('click', callback); return button;
    }
    function render() {
        const body = $('[data-provider-rows]'); body.replaceChildren();
        for (const row of rows) {
            const tr = make('tr'); tr.dataset.providerId = row.id;
            const name = make('td'); name.append(make('span', row.name, 'oyiso-agent-provider-name'));
            const url = make('span', row.url, 'oyiso-agent-provider-url'); url.title = row.url; name.append(url);
            const models = make('td'); const select = make('select'); select.setAttribute('aria-label', `${row.name} 的测试模型`);
            for (const model of row.models) select.add(new Option(model, model));
            const current = defaults.value.split('|'); if (current[0] === row.id && row.models.includes(current[1])) select.value = current[1];
            models.append(select);
            const controls = make('td'); const actions = make('div', undefined, 'oyiso-agent-provider-actions');
            const test = action('测试', () => testConnection(row, select.value, test)); test.disabled = busy || !row.configured || testing.has(row.id);
            actions.append(test, action('编辑', event => open(row, event.currentTarget)), action('删除', () => remove(row), 'oyiso-agent-provider-delete')); controls.append(actions);
            tr.append(name, make('td', protocols[row.protocol]?.name || row.protocol), models, make('td', row.configured ? '已配置' : '未配置'), controls); body.append(tr);
        }
        if (!rows.length) { const tr = make('tr'); const td = make('td', '还没有模型连接，点击「添加提供商」开始配置。', 'oyiso-agent-provider-empty'); td.colSpan = 5; tr.append(td); body.append(tr); }
        $('[data-provider-add]').disabled = busy;
    }
    function open(row, target) {
        if (busy) return;
        cancelCloseMotion?.(); cancelCloseMotion = undefined;
        dialog.inert = false; dialog.classList.remove('is-closing');
        opener = target; editing = row?.id || '';
        fields.preset.value = row?.preset || 'deepseek';
        const preset = presets[fields.preset.value];
        fields.name.value = row?.name || preset?.name || '';
        fields.url.value = row?.url || preset?.url || '';
        fields.protocol.value = row?.protocol || preset?.protocol || 'openai_chat';
        fields.url.placeholder = protocols[fields.protocol.value].url;
        fields.models.value = row ? row.models.join('\n') : preset?.model || '';
        fields.key.value = ''; fields.key.disabled = false; fields.key.placeholder = row?.configured ? '已配置，留空保持' : '填写 API Key';
        fields.clear_key.checked = false; fields.clear_key.disabled = !row?.configured;
        $('#oyiso-provider-dialog-title').textContent = row ? '编辑提供商' : '添加提供商';
        $('[data-provider-dialog-notice]').textContent = '';
        if (!dialog.open) dialog.showModal();
        // Establish the initial scale before starting the shared modal transition.
        void dialog.offsetHeight;
        dialog.classList.add('is-open'); fields.name.focus();
    }
    function finishClose() {
        cancelCloseMotion?.(); cancelCloseMotion = undefined;
        dialog.close();
        (opener?.isConnected && !opener.disabled ? opener : $('[data-provider-add]')).focus();
    }
    function close() {
        if (busy || !dialog.open || dialog.classList.contains('is-closing')) return;
        fields.key.value = ''; dialog.classList.remove('is-open');
        if (reducedMotion?.matches) { finishClose(); return; }
        dialog.inert = true; dialog.classList.add('is-closing');
        const ended = event => { if (event.target === dialog && event.propertyName === 'transform') finishClose(); };
        dialog.addEventListener('transitionend', ended);
        // Interrupted transitions and hidden tabs may not emit transitionend.
        const timeout = setTimeout(finishClose, 250);
        cancelCloseMotion = () => { clearTimeout(timeout); dialog.removeEventListener('transitionend', ended); };
    }
    async function save() {
        if (busy || !dialog.open || dialog.classList.contains('is-closing')) return;
        const data = { preset: fields.preset.value, name: fields.name.value.trim(), url: fields.url.value.trim(), protocol: fields.protocol.value, models: fields.models.value.split(/\r?\n/).map(value => value.trim()).filter(Boolean), key: fields.key.value, clear_key: fields.clear_key.checked };
        if (!data.name || !data.url || !data.models.length) { $('[data-provider-dialog-notice]').textContent = '请填写连接名称、Base URL 和可用模型。'; return; }
        busy = true; $('[data-provider-save]').disabled = true; render();
        let saved = false;
        try {
            const result = await api(`providers${editing ? '/' + editing : ''}`, editing ? 'PUT' : 'POST', data);
            rows = result.connections; revision++; updateDefaults(); fields.key.value = ''; saved = true;
            notify('提供商已保存。选择默认模型后，请点击页面顶部的「保存配置」。');
        } catch (error) { $('[data-provider-dialog-notice]').textContent = error.message; }
        finally { busy = false; $('[data-provider-save]').disabled = false; render(); if (saved) close(); }
    }
    async function remove(row) {
        if (busy || !window.confirm(`删除「${row.name}」及其已保存的密钥？`)) return;
        busy = true; render();
        try { const result = await api(`providers/${row.id}`, 'DELETE'); rows = result.connections; revision++; updateDefaults(); notify('提供商已删除。'); }
        catch (error) { notify(error.message, true); }
        finally { busy = false; render(); }
    }
    async function testConnection(row, model, button) {
        if (button.disabled || testing.has(row.id)) return;
        testing.add(row.id); button.disabled = true; const version = revision;
        const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 45000);
        notify(`正在测试 ${row.name} / ${model} 的工具调用…`);
        try {
            const result = await api(`providers/${row.id}/test`, 'POST', { model }, controller.signal);
            notify(version === revision ? `${result.provider} / ${result.model}：${result.message}` : '配置已更新，请重新测试连接。');
        } catch (error) { if (version === revision) notify(error.name === 'AbortError' ? '连接测试超时，请检查网络后重试。' : error.message, true); }
        finally { clearTimeout(timeout); testing.delete(row.id); render(); }
    }
    $('[data-provider-add]').addEventListener('click', event => open(null, event.currentTarget));
    $('[data-provider-save]').addEventListener('click', save);
    dialog.querySelectorAll('[data-provider-close]').forEach(button => button.addEventListener('click', close));
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    dialog.addEventListener('close', () => {
        if (dialog.open) return;
        cancelCloseMotion?.(); cancelCloseMotion = undefined;
        dialog.inert = false; dialog.classList.remove('is-open', 'is-closing'); fields.key.value = '';
    });
    fields.preset.addEventListener('change', () => {
        const preset = presets[fields.preset.value];
        fields.name.value = preset?.name || ''; fields.url.value = preset?.url || ''; fields.models.value = preset?.model || '';
        fields.protocol.value = preset?.protocol || fields.protocol.value;
        fields.url.placeholder = protocols[fields.protocol.value].url;
    });
    fields.protocol.addEventListener('change', () => {
        const protocol = protocols[fields.protocol.value];
        const url = fields.url.value.trim().replace(/\/+$/, '');
        const known = [...Object.values(protocols), ...Object.values(presets)].map(preset => preset.url);
        known.push('https://generativelanguage.googleapis.com/v1beta/openai');
        if (!url || known.includes(url)) fields.url.value = protocol.url;
        fields.url.placeholder = protocol.url;
    });
    fields.clear_key.addEventListener('change', () => { if (fields.clear_key.checked) fields.key.value = ''; fields.key.disabled = fields.clear_key.checked; });
    dialog.addEventListener('keydown', event => { if (event.key === 'Enter' && event.target.matches('input, select')) { event.preventDefault(); save(); } });
    // Provider edits save independently from the surrounding CSF form.
    for (const type of ['change', 'input', 'keypress']) dialog.addEventListener(type, event => event.stopPropagation());
    if (window.jQuery) window.jQuery(document).on('ajaxSuccess.oyisoProviders', (event, xhr, settings) => {
        const action = typeof settings.data === 'string' ? new URLSearchParams(settings.data).get('action') : settings.data?.action;
        if (action === 'csf_oyiso_ajax_save' && xhr.responseJSON?.success !== false) render();
    });
    updateDefaults(); render();
})();
