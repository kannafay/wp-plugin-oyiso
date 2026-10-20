(() => {
    'use strict';
    const root = document.querySelector('[data-agent-settings]');
    const config = window.oyisoModelSettings;
    if (!root || !config) return;
    const $ = selector => root.querySelector(selector);
    const dialog = $('[data-provider-dialog]');
    const modelDialog = $('[data-model-dialog]');
    const fields = Object.fromEntries(Array.from(dialog.querySelectorAll('[data-provider-field]'), input => [input.dataset.providerField, input]));
    const keyToggle = $('[data-provider-key-toggle]');
    const initial = JSON.parse(root.dataset.state);
    const presets = initial.presets;
    const protocols = initial.protocols;
    let rows = initial.connections;
    let editing = '';
    let busy = false;
    let revision = 0;
    const testing = new Set();
    let opener;
    const closeMotions = new WeakMap();
    let modelMode = '';
    let modelOpener;
    let catalog = [];
    let catalogLoaded = false;
    let modelSelection = new Set();
    let manualBaseModels = [];
    let modelRequest = 0;
    let modelController;
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    const make = (tag, text, className = '') => { const element = document.createElement(tag); if (text !== undefined) element.textContent = text; element.className = className; return element; };
    const notify = (text, error = false) => { const notice = $('[data-provider-notice]'); notice.textContent = text; notice.hidden = !text; notice.classList.toggle('is-error', error); };
    function setKeyVisible(visible = false) {
        fields.key.type = visible ? 'text' : 'password';
        const label = visible ? '隐藏 API Key' : '显示 API Key';
        keyToggle.setAttribute('aria-pressed', String(visible)); keyToggle.setAttribute('aria-label', label); keyToggle.title = label;
        $('[data-key-show]').toggleAttribute('hidden', visible); $('[data-key-hide]').toggleAttribute('hidden', !visible);
        keyToggle.disabled = busy || fields.key.disabled;
    }
    async function api(route, method = 'GET', data, signal) {
        const response = await fetch(config.url + route, { method, credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' }, ...(data === undefined ? {} : { body: JSON.stringify(data) }), ...(signal ? { signal } : {}) });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || `请求失败（HTTP ${response.status}）。`);
        return result;
    }
    function showDialog(element) {
        closeMotions.get(element)?.(); closeMotions.delete(element);
        element.inert = false; element.classList.remove('is-closing');
        if (!element.open) element.showModal();
        void element.offsetHeight;
        element.classList.add('is-open');
    }
    function hideDialog(element, afterClose) {
        element.classList.remove('is-open');
        const finish = () => { closeMotions.get(element)?.(); closeMotions.delete(element); element.close(); afterClose(); };
        if (reducedMotion?.matches) { finish(); return; }
        element.inert = true; element.classList.add('is-closing');
        const ended = event => { if (event.target === element && event.propertyName === 'transform') finish(); };
        element.addEventListener('transitionend', ended);
        const timeout = setTimeout(finish, 250);
        closeMotions.set(element, () => { clearTimeout(timeout); element.removeEventListener('transitionend', ended); });
    }
    const modelKey = id => fields.protocol.value === 'gemini' ? id.replace(/^models\//, '') : id;
    const uniqueModels = values => { const seen = new Set(); return values.filter(id => { const key = modelKey(id); if (seen.has(key)) return false; seen.add(key); return true; }); };
    const getModels = () => uniqueModels(fields.models.value.split(/\r?\n/).filter(Boolean));
    const validModel = id => new Blob([id]).size <= 200 && !/[\s\x00-\x1f\x7f|]/u.test(id);
    function setModels(values) { fields.models.value = uniqueModels(values).join('\n'); renderSelectedModels(); }
    function renderSelectedModels() {
        const selected = getModels(); const list = $('[data-model-selected]'); list.replaceChildren();
        $('[data-model-count]').textContent = `${selected.length} / 30`;
        $('[data-model-fetch]').disabled = busy; $('[data-model-add]').disabled = busy;
        for (const id of selected) {
            const item = make('div', undefined, 'oyiso-agent-selected-model');
            const remove = make('button', '×', 'oyiso-agent-model-remove'); remove.type = 'button'; remove.disabled = busy;
            remove.setAttribute('aria-label', `移除模型 ${id}`);
            remove.addEventListener('click', () => { if (!busy) setModels(getModels().filter(model => model !== id)); });
            item.append(make('span', id), remove); list.append(item);
        }
        if (!selected.length) list.append(make('p', '暂未添加模型，可先保存，稍后编辑补充。', 'oyiso-agent-model-empty'));
    }
    function abortModelRequest() { modelRequest++; modelController?.abort(); modelController = undefined; }
    function closeModelDialog() {
        if (!modelDialog.open || modelDialog.classList.contains('is-closing')) return;
        abortModelRequest();
        hideDialog(modelDialog, () => { if (dialog.open) (modelOpener?.isConnected && !modelOpener.disabled ? modelOpener : $('[data-model-fetch]')).focus(); });
    }
    function updateModelSelection() {
        $('[data-model-selection-count]').textContent = `已选 ${modelSelection.size} / 30`;
        $('[data-model-apply]').disabled = !catalogLoaded || modelSelection.size === 0;
    }
    function renderCatalog() {
        const list = $('[data-model-catalog]'); list.replaceChildren();
        const search = $('[data-model-search]'); search.disabled = !catalogLoaded; updateModelSelection();
        if (!catalogLoaded) return;
        const existing = new Map(getModels().map(id => [modelKey(id), id]));
        const entries = new Map(catalog.map(item => { const id = existing.get(modelKey(item.id)) || item.id; return [modelKey(id), { ...item, id }]; }));
        for (const id of getModels()) if (!entries.has(modelKey(id))) entries.set(modelKey(id), { id, name: '已添加，未出现在本次列表中' });
        const query = search.value.trim().toLocaleLowerCase();
        for (const { id, name } of entries.values()) {
            if (query && !`${id} ${name}`.toLocaleLowerCase().includes(query)) continue;
            const label = make('label', undefined, 'oyiso-agent-model-option'); const checkbox = make('input');
            checkbox.type = 'checkbox'; checkbox.value = id; checkbox.checked = modelSelection.has(id);
            const text = make('span'); text.append(make('span', id, 'oyiso-agent-model-id'));
            if (name && name !== id) text.append(make('small', name));
            checkbox.addEventListener('change', () => {
                if (checkbox.checked && modelSelection.size >= 30) { checkbox.checked = false; $('[data-model-notice]').textContent = '最多选择 30 个模型。'; return; }
                if (checkbox.checked) modelSelection.add(id); else modelSelection.delete(id);
                $('[data-model-notice]').textContent = ''; updateModelSelection();
            });
            label.append(checkbox, text); list.append(label);
        }
        if (!list.children.length) list.append(make('p', query ? '没有匹配的模型。' : '没有可选模型，可以手动添加。', 'oyiso-agent-model-empty'));
    }
    const manualModels = () => uniqueModels($('[data-model-manual]').value.split(/[\r\n,，]+/).map(id => id.trim()).filter(Boolean));
    function renderManualPreview() {
        const entered = manualModels(); const existing = new Set(manualBaseModels.map(modelKey));
        const added = entered.filter(id => !existing.has(modelKey(id))).length;
        $('[data-model-preview]').textContent = !entered.length ? '输入后将显示新增的模型数量。' : entered.some(id => !validModel(id)) ? '模型 ID 不能包含空白或竖线，且最长为 200 个字符。' : `将新增 ${added} 个模型${entered.length > added ? `，${entered.length - added} 个已添加` : ''}。`;
        $('[data-model-apply]').disabled = entered.length === 0;
    }
    async function fetchModels() {
        abortModelRequest(); const request = modelRequest;
        const data = { id: editing, url: fields.url.value.trim(), protocol: fields.protocol.value, key: fields.key.value, clear_key: fields.clear_key.checked };
        const unchanged = () => data.id === editing && data.url === fields.url.value.trim() && data.protocol === fields.protocol.value && data.key === fields.key.value && data.clear_key === fields.clear_key.checked;
        catalog = []; catalogLoaded = false; $('[data-model-notice]').textContent = '';
        $('[data-model-status]').textContent = '正在获取模型列表…'; $('[data-model-retry]').disabled = true; renderCatalog();
        modelController = new AbortController(); const controller = modelController;
        const timeout = setTimeout(() => controller.abort(), 25000);
        try {
            const result = await api('providers/models', 'POST', data, controller.signal);
            if (request !== modelRequest || !modelDialog.open || modelMode !== 'catalog') return;
            if (!unchanged()) { $('[data-model-status]').textContent = '连接配置已变更，请重新获取模型列表。'; return; }
            if (!Array.isArray(result.models)) throw new Error('模型列表格式无法识别，请手动添加模型。');
            catalog = result.models.filter(item => typeof item.id === 'string' && item.id && validModel(item.id)).map(item => ({ id: item.id, name: typeof item.name === 'string' ? item.name : item.id }));
            catalogLoaded = true;
            $('[data-model-status]').textContent = result.truncated ? `已获取 ${catalog.length} 个模型，列表未完整返回；其他模型可手动添加。` : catalog.length ? `已获取 ${catalog.length} 个模型。` : '接口未返回可选模型，可以手动添加。';
            renderCatalog();
        } catch (error) {
            if (request === modelRequest && modelDialog.open && modelMode === 'catalog' && unchanged()) {
                $('[data-model-status]').textContent = '';
                $('[data-model-notice]').textContent = error.name === 'AbortError' ? '获取模型列表超时，请重试或手动添加模型。' : error.message;
            }
        } finally { clearTimeout(timeout); if (request === modelRequest) { modelController = undefined; $('[data-model-retry]').disabled = false; } }
    }
    function openModelDialog(mode, target) {
        if (busy || !dialog.open || dialog.classList.contains('is-closing')) return;
        abortModelRequest(); modelMode = mode; modelOpener = target;
        $('[data-model-catalog-view]').hidden = mode !== 'catalog'; $('[data-model-manual-view]').hidden = mode !== 'manual';
        $('[data-model-catalog-actions]').hidden = mode !== 'catalog';
        $('#oyiso-model-dialog-title').textContent = mode === 'catalog' ? '获取模型列表' : '手动添加模型';
        $('[data-model-apply]').textContent = mode === 'catalog' ? '添加所选模型' : '添加模型';
        $('[data-model-notice]').textContent = ''; showDialog(modelDialog);
        if (mode === 'catalog') { $('[data-model-search]').value = ''; modelSelection = new Set(getModels()); fetchModels(); $('[data-model-close]').focus(); }
        else { manualBaseModels = getModels(); $('[data-model-manual]').value = ''; renderManualPreview(); $('[data-model-manual]').focus(); }
    }
    function applyModels() {
        if (!modelDialog.open || modelDialog.classList.contains('is-closing') || $('[data-model-apply]').disabled) return;
        const entered = modelMode === 'manual' ? manualModels() : Array.from(modelSelection);
        if (entered.some(id => !validModel(id))) { $('[data-model-notice]').textContent = '模型 ID 不能包含空白或竖线，且最长为 200 个字符。'; return; }
        const next = modelMode === 'manual' ? uniqueModels([...manualBaseModels, ...entered]) : entered;
        if (next.length > 30) { $('[data-model-notice]').textContent = '最多选择 30 个模型，请减少选择后重试。'; return; }
        setModels(next); closeModelDialog();
    }
    function announceModels() {
        const models = Object.fromEntries(rows.filter(row => row.configured).flatMap(row => row.models.map(model => [`${row.id}|${model}`, `${row.name} / ${model}`])));
        document.dispatchEvent(new CustomEvent('oyiso-agent-models-updated', { detail: models }));
    }
    function action(label, callback, className = '') {
        const button = make('button', label, `button button-small ${className}`);
        button.type = 'button'; button.disabled = busy; button.addEventListener('click', callback); return button;
    }
    function render() {
        renderSelectedModels();
        keyToggle.disabled = busy || fields.key.disabled;
        const body = $('[data-provider-rows]'); body.replaceChildren();
        for (const row of rows) {
            const tr = make('tr'); tr.dataset.providerId = row.id;
            const name = make('td'); name.append(make('span', row.name, 'oyiso-agent-provider-name'));
            const url = make('span', row.url, 'oyiso-agent-provider-url'); url.title = row.url; name.append(url);
            const models = make('td'); const select = make('select'); select.setAttribute('aria-label', `${row.name} 的测试模型`);
            for (const model of row.models) select.add(new Option(model, model));
            if (row.models.length) models.append(select);
            else models.textContent = '未添加模型，暂不可用';
            const controls = make('td'); const actions = make('div', undefined, 'oyiso-agent-provider-actions');
            const test = action('测试', () => testConnection(row, select.value, test)); test.disabled = busy || !row.configured || !row.models.length || testing.has(row.id);
            actions.append(test, action('编辑', event => open(row, event.currentTarget)), action('删除', () => remove(row), 'oyiso-agent-provider-delete')); controls.append(actions);
            tr.append(name, make('td', protocols[row.protocol]?.name || row.protocol), models, make('td', row.configured ? '已配置' : '未配置'), controls); body.append(tr);
        }
        if (!rows.length) { const tr = make('tr'); const td = make('td', '还没有模型连接，点击「添加提供商」开始配置。', 'oyiso-agent-provider-empty'); td.colSpan = 5; tr.append(td); body.append(tr); }
        $('[data-provider-add]').disabled = busy;
    }
    function open(row, target) {
        if (busy) return;
        abortModelRequest();
        if (modelDialog.open) { closeMotions.get(modelDialog)?.(); modelDialog.close(); }
        opener = target; editing = row?.id || '';
        fields.preset.value = row?.preset || 'openai';
        const preset = presets[fields.preset.value];
        fields.name.value = row?.name || preset?.name || '';
        fields.url.value = row?.url || preset?.url || '';
        fields.protocol.value = row?.protocol || preset?.protocol || 'openai_responses';
        fields.url.placeholder = protocols[fields.protocol.value].url;
        setModels(row ? row.models : []);
        fields.key.value = ''; fields.key.disabled = false; fields.key.placeholder = row?.configured ? '已配置，留空保持' : '填写 API Key';
        setKeyVisible();
        fields.clear_key.checked = false; fields.clear_key.disabled = !row?.configured;
        $('#oyiso-provider-dialog-title').textContent = row ? '编辑提供商' : '添加提供商';
        $('[data-provider-dialog-notice]').textContent = '';
        showDialog(dialog); fields.name.focus();
    }
    function close() {
        if (busy || modelDialog.open || !dialog.open || dialog.classList.contains('is-closing')) return;
        fields.key.value = ''; setKeyVisible(); abortModelRequest();
        hideDialog(dialog, () => (opener?.isConnected && !opener.disabled ? opener : $('[data-provider-add]')).focus());
    }
    async function save() {
        if (busy || modelDialog.open || !dialog.open || dialog.classList.contains('is-closing')) return;
        const data = { preset: fields.preset.value, name: fields.name.value.trim(), url: fields.url.value.trim(), protocol: fields.protocol.value, models: getModels(), key: fields.key.value, clear_key: fields.clear_key.checked };
        if (!data.name) { $('[data-provider-dialog-notice]').textContent = '请填写连接名称。'; return; }
        if (!data.url) { $('[data-provider-dialog-notice]').textContent = '请填写 Base URL。'; return; }
        busy = true; $('[data-provider-save]').disabled = true; render();
        let saved = false;
        try {
            const result = await api(`providers${editing ? '/' + editing : ''}`, editing ? 'PUT' : 'POST', data);
            rows = result.connections; revision++; announceModels(); fields.key.value = ''; saved = true;
            notify(data.models.length ? '提供商已保存，可在 AI 助手中选择模型。' : '提供商已保存。尚未添加模型，暂不可用，可稍后编辑补充。');
        } catch (error) { $('[data-provider-dialog-notice]').textContent = error.message; }
        finally { busy = false; $('[data-provider-save]').disabled = false; render(); if (saved) close(); }
    }
    async function remove(row) {
        if (busy || !window.confirm(`删除「${row.name}」及其已保存的密钥？`)) return;
        busy = true; render();
        try { const result = await api(`providers/${row.id}`, 'DELETE'); rows = result.connections; revision++; announceModels(); notify('提供商已删除。'); }
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
        closeMotions.get(dialog)?.(); closeMotions.delete(dialog); abortModelRequest();
        dialog.inert = false; dialog.classList.remove('is-open', 'is-closing'); fields.key.value = ''; setKeyVisible();
    });
    fields.preset.addEventListener('change', () => {
        const preset = presets[fields.preset.value];
        fields.name.value = preset?.name || ''; fields.url.value = preset?.url || ''; setModels([]);
        fields.protocol.value = preset?.protocol || 'openai_responses';
        fields.url.placeholder = protocols[fields.protocol.value].url;
    });
    fields.protocol.addEventListener('change', () => {
        const protocol = protocols[fields.protocol.value];
        fields.url.placeholder = protocol.url;
    });
    keyToggle.addEventListener('click', () => {
        if (busy || fields.key.disabled || modelDialog.open || !dialog.open || dialog.classList.contains('is-closing')) return;
        setKeyVisible(fields.key.type === 'password');
    });
    fields.clear_key.addEventListener('change', () => { if (fields.clear_key.checked) fields.key.value = ''; fields.key.disabled = fields.clear_key.checked; setKeyVisible(); });
    $('[data-model-fetch]').addEventListener('click', event => openModelDialog('catalog', event.currentTarget));
    $('[data-model-add]').addEventListener('click', event => openModelDialog('manual', event.currentTarget));
    $('[data-model-retry]').addEventListener('click', fetchModels);
    $('[data-model-search]').addEventListener('input', renderCatalog);
    $('[data-model-manual]').addEventListener('input', renderManualPreview);
    $('[data-model-apply]').addEventListener('click', applyModels);
    modelDialog.querySelectorAll('[data-model-close]').forEach(button => button.addEventListener('click', closeModelDialog));
    modelDialog.addEventListener('cancel', event => { event.preventDefault(); closeModelDialog(); });
    modelDialog.addEventListener('close', () => {
        if (modelDialog.open) return;
        closeMotions.get(modelDialog)?.(); closeMotions.delete(modelDialog); abortModelRequest();
        modelDialog.inert = false; modelDialog.classList.remove('is-open', 'is-closing'); modelMode = ''; $('[data-model-manual]').value = '';
    });
    modelDialog.addEventListener('keydown', event => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); applyModels(); }
        else if (event.key === 'Enter' && event.target.matches('input')) event.preventDefault();
    });
    dialog.addEventListener('keydown', event => { if (event.key === 'Enter' && event.target.matches('input, select')) { event.preventDefault(); save(); } });
    // Provider edits save independently from the surrounding CSF form.
    for (const element of [dialog, modelDialog]) for (const type of ['change', 'input', 'keypress']) element.addEventListener(type, event => event.stopPropagation());
    if (window.jQuery) window.jQuery(document).on('ajaxSuccess.oyisoProviders', (event, xhr, settings) => {
        const action = typeof settings.data === 'string' ? new URLSearchParams(settings.data).get('action') : settings.data?.action;
        if (action === 'csf_oyiso_ajax_save' && xhr.responseJSON?.success !== false) render();
    });
    render();
})();
