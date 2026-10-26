(() => {
    'use strict';

    const config = window.oyisoAgent;
    const panel = document.getElementById('oyiso-agent-panel');
    const launcher = document.getElementById('oyiso-agent-launcher');
    const launcherWrapper = document.getElementById('oyiso-agent-launcher-wrap');
    const chat = document.getElementById('oyiso-agent-chat');
    if (!config || !panel || !launcher || !launcherWrapper || !chat) return;

    const $ = (selector, root = panel) => root?.querySelector(selector);
    const prompt = $('#oyiso-agent-prompt');
    const modelSelect = $('[data-agent-model]');
    const messages = $('[data-agent-messages]');
    const scroll = $('.oyiso-agent-scroll');
    const empty = messages.firstElementChild.cloneNode(true);
    const notice = $('[data-agent-notice]');
    const responseStatus = $('[data-agent-response-status]');
    const responseLabel = $('[data-agent-response-label]');
    const progress = $('.oyiso-agent-progress');
    const progressParking = $('[data-agent-progress-parking]');
    const progressState = $('[data-agent-status]', progress);
    const progressUsage = $('[data-agent-usage]', progress);
    const progressEvents = $('[data-agent-events]', progress);
    const progressRecords = new Map();
    const progressVersions = new WeakMap();
    $('summary', progress).append(responseStatus);
    const panelHistory = $('#oyiso-agent-panel-history');
    const historyToggle = $('[data-agent-history-toggle]');
    const historyDismiss = $('[data-agent-history-dismiss]');
    const layoutMenu = $('[data-agent-layout-menu]');
    const settingsMenu = $('[data-agent-settings-menu]');
    const settingsToggle = $('[data-agent-settings-toggle]');
    const productDialog = document.querySelector('[data-agent-product-dialog]');
    let productConfirmation;
    const statuses = { queued: '等待执行', running: '执行中', waiting: '等待确认', completed: '已完成', failed: '执行失败', cancelled: '已取消' };
    const fields = { description: '长描述', short_description: '短描述', seo_title: 'SEO 标题', seo_description: 'SEO 描述', seo_keywords: '目标关键词' };
    const storageKey = `oyiso-agent:${config.user}:${new URL(config.url).pathname}`;
    const changing = new Set();
    let history;
    let composer;
    let deleting = false;
    let refreshing = false;
    const liveRuns = new Map();
    const liveOwners = new Map();
    let sendSequence = 0;
    const drafts = new Map();
    const modelDrafts = new Map();
    const recoveredFailures = new Set();
    const messageEntries = new WeakMap();
    let run = null;
    let runId = 0;
    let timer;
    let sending = false;
    const cancelling = new Set();
    let composing = false;
    const stepping = new Set();
    let loading = false;
    let pollRevision = 0;
    let pollController;
    let version = '';
    let conversationVersion = '';
    let panelOpen = false;
    let cancelPanelMotion;
    let fullscreen = false;
    let fullscreenPreviousFloating = false;

    function store(key, value) {
        try { localStorage.setItem(storageKey + key, value); } catch { /* Storage is optional. */ }
    }

    function alignContent() {
        const width = `${Math.max(0, scroll.offsetWidth - scroll.clientWidth)}px`;
        if (panel.style.getPropertyValue('--oyiso-agent-scrollbar') !== width) panel.style.setProperty('--oyiso-agent-scrollbar', width);
    }
    window.addEventListener('resize', alignContent);
    window.addEventListener('resize', updateLayoutControls);
    if (window.ResizeObserver) new ResizeObserver(alignContent).observe(scroll);

    function populateModels(choices = config.models || {}, selected = modelSelect.value) {
        config.models = Object.fromEntries(Object.entries(choices).filter(([id, name]) => id.includes('|') && typeof name === 'string'));
        modelSelect.replaceChildren();
        const groups = new Map();
        for (const [id, name] of Object.entries(config.models)) {
            const separator = id.indexOf('|');
            const connection = id.slice(0, separator), model = id.slice(separator + 1);
            let group = groups.get(connection);
            if (!group) {
                group = document.createElement('optgroup');
                const suffix = ' / ' + model;
                group.label = name.endsWith(suffix) ? name.slice(0, -suffix.length) : name;
                groups.set(connection, group);
                modelSelect.append(group);
            }
            group.append(new Option(model, id));
        }
        if (selected && !Object.hasOwn(config.models, selected)) {
            const missing = new Option('原模型已不可用，请重新选择', selected); missing.disabled = true; modelSelect.add(missing);
        }
        if (!modelSelect.options.length) modelSelect.add(new Option('暂无可用模型，请先添加', ''));
        if (selected) modelSelect.value = selected;
    }

    function modelFor(id, fallback) {
        if (modelDrafts.has(id)) return modelDrafts.get(id);
        try { return localStorage.getItem(storageKey + ':model:' + id) || fallback; } catch { return fallback; }
    }

    function forgetModel(id) {
        modelDrafts.delete(id);
        try { localStorage.removeItem(storageKey + ':model:' + id); } catch { /* Storage is optional. */ }
    }

    function rememberDraft() {
        drafts.set(runId, prompt.value);
        try {
            sessionStorage.setItem(storageKey + ':draft', JSON.stringify({ id: runId, text: prompt.value }));
            if (prompt.value) sessionStorage.setItem(storageKey + ':draft:' + runId, prompt.value);
            else sessionStorage.removeItem(storageKey + ':draft:' + runId);
        } catch { /* Storage is optional. */ }
    }

    function draftFor(id) {
        if (drafts.has(id)) return drafts.get(id);
        try {
            const saved = sessionStorage.getItem(storageKey + ':draft:' + id);
            if (saved !== null) return saved.slice(0, 4000);
            const legacy = JSON.parse(sessionStorage.getItem(storageKey + ':draft') || 'null');
            if (legacy?.id === id && typeof legacy.text === 'string') return legacy.text.slice(0, 4000);
        } catch { /* Storage is optional. */ }
        return '';
    }

    function forgetDraft(id) {
        drafts.delete(id);
        forgetModel(id);
        composer?.clear(id);
        try { sessionStorage.removeItem(storageKey + ':draft:' + id); } catch { /* Storage is optional. */ }
    }

    function setHistory(open, focus = true) {
        panelHistory.hidden = !open;
        historyToggle.setAttribute('aria-expanded', String(open));
        store(':history-open', open ? '1' : '0');
        syncHistory();
        if (open) history?.reloadPanel();
        if (focus) (open ? $('[data-agent-history-back]') : settingsToggle).focus({ preventScroll: true });
    }

    function syncHistory() {
        const overlay = !panelHistory.hidden && panel.dataset.historyLayout !== 'sidebar';
        const moveFocus = overlay && chat.contains(document.activeElement);
        chat.toggleAttribute('inert', overlay);
        historyDismiss.hidden = !overlay;
        if (moveFocus) $('[data-agent-history-back]').focus({ preventScroll: true });
    }

    function finishPanelMotion() {
        cancelPanelMotion?.();
        cancelPanelMotion = undefined;
        document.body.classList.remove('oyiso-agent-transitioning');
        panel.hidden = !panelOpen;
        launcherWrapper.hidden = panelOpen || !config.enabled;
        window.dispatchEvent(new Event('resize'));
    }

    function setPanel(open, focus = true, animate = true) {
        if (open && !config.enabled) return;
        const wasOpen = panelOpen;
        const moving = animate && open !== panelOpen && !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        cancelPanelMotion?.();
        cancelPanelMotion = undefined;
        if (moving) {
            panel.hidden = false;
            launcherWrapper.hidden = false;
            document.body.classList.add('oyiso-agent-transitioning');
            // Commit the starting position before changing the transition target.
            panel.getBoundingClientRect();
            launcherWrapper.getBoundingClientRect();
        }
        panelOpen = open;
        panel.toggleAttribute('inert', !open);
        panel.setAttribute('aria-hidden', String(!open));
        launcher.setAttribute('aria-expanded', String(open));
        launcherWrapper.toggleAttribute('inert', open || !config.enabled);
        launcherWrapper.setAttribute('aria-hidden', String(open || !config.enabled));
        document.body.classList.toggle('oyiso-agent-open', open);
        store(':open', open ? '1' : '0');
        if (moving) {
            const ended = event => { if (event.target === panel && event.propertyName === 'transform') finishPanelMotion(); };
            panel.addEventListener('transitionend', ended);
            // Hidden tabs or interrupted transitions may not dispatch transitionend.
            const timeout = setTimeout(finishPanelMotion, 250);
            cancelPanelMotion = () => { clearTimeout(timeout); panel.removeEventListener('transitionend', ended); };
        } else {
            finishPanelMotion();
        }
        if (open) {
            alignContent();
            if (focus) $('[data-agent-close]').focus({ preventScroll: true });
            poll();
            if (!wasOpen && !panelHistory.hidden) history?.reloadPanel();
        } else {
            setSettingsMenu(false, false);
            clearTimeout(timer);
            pollRevision++;
            loading = false;
            refreshing = false;
            controls();
            rememberDraft();
            if (focus) launcher.focus({ preventScroll: true });
        }
        window.dispatchEvent(new Event('resize'));
    }

    function setLayout(floating) {
        if (fullscreen) setFullscreen(false);
        if (cancelPanelMotion) finishPanelMotion();
        document.body.classList.toggle('oyiso-agent-floating', floating);
        store(':layout', floating ? 'floating' : 'docked');
        updateLayoutControls();
        window.dispatchEvent(new Event('resize'));
    }

    function setSide(left) {
        if (fullscreen) setFullscreen(false);
        if (cancelPanelMotion) finishPanelMotion();
        document.body.classList.toggle('oyiso-agent-dock-left', left);
        store(':side', left ? 'left' : 'right');
        updateLayoutControls();
        window.dispatchEvent(new Event('resize'));
    }

    function setFullscreen(open) {
        if (open === fullscreen || (open && !config.enabled)) return;
        if (cancelPanelMotion) finishPanelMotion();
        if (open) fullscreenPreviousFloating = document.body.classList.contains('oyiso-agent-floating');
        fullscreen = open;
        document.body.classList.toggle('oyiso-agent-floating', open || fullscreenPreviousFloating);
        panel.toggleAttribute('data-agent-fullscreen', open);
        const button = $('[data-agent-fullscreen-toggle]');
        button.setAttribute('aria-pressed', String(open));
        button.title = open ? '退出全屏' : '全屏';
        button.setAttribute('aria-label', button.title);
        $('[data-agent-fullscreen-icon="enter"]').hidden = open;
        $('[data-agent-fullscreen-icon="exit"]').hidden = !open;
        store(':fullscreen', open ? '1' : '0');
        updateLayoutControls();
        window.dispatchEvent(new Event('resize'));
    }

    function updateLayoutControls() {
        const floating = panel.hasAttribute('data-agent-window');
        const available = panel.hasAttribute('data-agent-split-available');
        const left = document.body.classList.contains('oyiso-agent-dock-left');
        layoutMenu.querySelectorAll('[data-agent-layout-choice]').forEach(button => {
            const choice = button.dataset.agentLayoutChoice;
            button.disabled = choice !== 'floating' && !available;
            if (choice !== 'floating') button.title = available ? choice === 'left' ? '左侧分屏' : '右侧分屏' : '窗口较窄，请放大浏览器窗口后使用分屏';
            button.setAttribute('aria-checked', String(choice === (floating ? 'floating' : left ? 'left' : 'right')));
        });
    }

    function setSettingsMenu(open, focus = true) {
        settingsMenu.hidden = !open;
        panel.toggleAttribute('data-agent-menu-open', open);
        settingsToggle.setAttribute('aria-expanded', String(open));
        if (open) placeSettingsMenu();
        if (focus) (open ? menuItems()[0] : settingsToggle)?.focus({ preventScroll: true });
    }

    function placeSettingsMenu() {
        const trigger = settingsToggle.getBoundingClientRect();
        const below = window.innerHeight - trigger.bottom - 12;
        const above = trigger.top - 12;
        // The header can sit at the bottom while the body is outside the viewport.
        const up = below < Math.min(settingsMenu.scrollHeight, 240) && above > below;
        settingsMenu.style.top = up ? 'auto' : 'calc(100% + 8px)';
        settingsMenu.style.bottom = up ? 'calc(100% + 8px)' : 'auto';
        settingsMenu.style.maxHeight = `${Math.max(32, up ? above : below)}px`;
        const left = trigger.right - settingsMenu.offsetWidth;
        const desired = Math.max(5, Math.min(left, (document.documentElement.clientWidth || window.innerWidth) - settingsMenu.offsetWidth - 5));
        settingsMenu.style.transform = `translateX(${desired - left}px)`;
    }

    const menuItems = () => Array.from(settingsMenu.querySelectorAll('button, a')).filter(button => !button.disabled && !button.closest('[hidden]') && getComputedStyle(button).display !== 'none');

    const node = (tag, className, text) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    };

    async function api(path, options = {}) {
        const { live, ...init } = options;
        const streaming = live && window.oyisoAgentReadStream && window.TextDecoder && window.ReadableStream;
        const response = await fetch(config.url + path, {
            credentials: 'same-origin',
            ...init,
            cache: 'no-store',
            headers: { ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), ...(streaming ? { Accept: 'text/event-stream' } : {}), 'X-WP-Nonce': config.nonce, ...options.headers },
        });
        if (response.ok && streaming && response.headers?.get('Content-Type')?.includes('text/event-stream') && response.body?.getReader) return window.oyisoAgentReadStream(response, live);
        let data;
        try { data = await response.json(); } catch { throw new Error('服务器未返回有效数据，请检查网络或刷新页面。'); }
        if (!response.ok) {
            const error = new Error(data.message || `请求失败（HTTP ${response.status}）。`);
            error.status = response.status; error.code = data.code;
            throw error;
        }
        return data;
    }

    function liveReceiver(accepted) {
        let latest;
        const owner = {};
        return {
            event(type, data) {
                if (latest && liveOwners.get(latest.id) !== owner) return;
                if (type === 'text') {
                    if (!latest || data.id !== latest.id || typeof data.delta !== 'string') throw new Error('回复片段无效，请刷新对话。');
                    const previous = latest.step === data.step ? latest.partial?.text || '' : '';
                    latest = { ...latest, step: data.step, status: 'running', partial: { model: data.model, text: previous + data.delta } };
                } else {
                    if (!Number.isSafeInteger(data.id) || !Array.isArray(data.conversation)) throw new Error('对话数据无效，请刷新重试。');
                    if (!latest) { accepted?.(data); liveOwners.set(data.id, owner); }
                    latest = data;
                }
                if (latest.id === runId && run?.status === 'cancelled' && latest.status !== 'cancelled') return;
                liveRuns.set(latest.id, latest);
                if (latest.id === runId && panelOpen) render(latest, true);
            },
            close() { if (latest && liveOwners.get(latest.id) === owner) { liveOwners.delete(latest.id); liveRuns.delete(latest.id); } },
            get received() { return !!latest; },
        };
    }

    function showConversationLoading() {
        if (!$('[data-agent-loading]', messages)) {
            const state = node('div', 'oyiso-agent-loading'); state.dataset.agentLoading = ''; state.setAttribute('role', 'status');
            const spinner = node('span', 'oyiso-agent-loading-spinner'); spinner.setAttribute('aria-hidden', 'true');
            state.append(spinner, node('span', '', '正在加载对话…')); messages.replaceChildren(state);
        }
        messages.setAttribute('aria-busy', 'true');
    }

    function copyProgress() {
        const copy = progress.cloneNode(true);
        $('[data-agent-response-status]', copy)?.remove();
        for (const name of ['status', 'usage', 'events']) $(`[data-agent-${name}]`, copy)?.removeAttribute(`data-agent-${name}`);
        $('.oyiso-agent-progress-state', copy).hidden = false;
        return copy;
    }

    function freezeProgress() {
        const index = Number(progress.dataset.executionIndex);
        if (progressRecords.get(index) !== progress) return;
        const copy = copyProgress(); progressRecords.set(index, copy); progress.replaceWith(copy);
    }

    function parkProgress() {
        progressParking.append(progress); progress.hidden = true; progress.open = false;
        delete progress.dataset.executionIndex; progressRecords.clear();
    }

    function fillProgress(element, record) {
        element.hidden = false; element.dataset.executionIndex = String(record.index); element.dataset.status = record.status;
        const state = $('.oyiso-agent-progress-state', element); state.hidden = false; state.textContent = statuses[record.status] || record.status;
        $('.oyiso-agent-progress-usage', element).textContent = `${record.legacy ? '历史合并记录 · 最近任务 ' : ''}${Number(record.step || 0)} 次模型调用 · ${record.legacy ? '累计 ' : ''}${Number(record.tokens || 0).toLocaleString()} Token${record.truncated ? ' · 记录较长，部分细节已省略' : ''}`;
        const signature = JSON.stringify(record.events || []);
        if (progressVersions.get(element) === signature) return;
        progressVersions.set(element, signature);
        const list = $('.oyiso-agent-progress-events', element); list.replaceChildren();
        for (const event of record.events || []) {
            const item = node('li', `is-${event.type}`); item.append(node('span', '', event.label));
            if (event.detail) { const detail = node('small'); messageText(detail, event.detail); item.append(detail); }
            list.append(item);
        }
        if (!list.childElementCount) list.append(node('li', 'description', ['queued', 'running'].includes(record.status) ? '等待模型响应…' : '本轮没有额外执行事件。'));
    }

    function renderExecutions(next) {
        let records = (next.executions || []).filter(record => Number.isInteger(record.index) && (record.index === -1 || next.conversation[record.index]?.role === 'user'));
        if (!records.length) records = [{ index: next.conversation.map(entry => entry.role).lastIndexOf('user'), status: next.status, step: next.step, tokens: next.tokens, events: next.events, legacy: true }];
        const latest = records[records.length - 1];
        if (progress.dataset.executionIndex !== String(latest.index)) { freezeProgress(); progress.open = false; }
        const indexes = new Set(records.map(record => record.index));
        for (const [index, element] of progressRecords) if (!indexes.has(index)) { if (element !== progress) element.remove(); progressRecords.delete(index); }
        for (const record of records) {
            const element = record === latest ? progress : progressRecords.get(record.index) || copyProgress();
            progressRecords.set(record.index, element); fillProgress(element, record === latest ? { ...record, status: next.status, step: next.step } : record);
        }
        return progressRecords;
    }

    const connectionSnapshot = preset => JSON.stringify(Array.from(document.querySelectorAll(`[data-agent-connection="${preset}"]`), input => [input.name, input.type === 'checkbox' ? input.checked : input.value]));
    const connectionStates = new Map();
    document.querySelectorAll('[data-agent-test-connection]').forEach(button => {
        const preset = button.dataset.agentTestConnection;
        const state = { saved: connectionSnapshot(preset), revision: 0 };
        connectionStates.set(preset, state);
        button.disabled = !config.supported;
        const note = document.querySelector(`[data-agent-connection-notice="${preset}"]`);
        button.addEventListener('click', async () => {
            if (connectionSnapshot(preset) !== state.saved) {
                note.textContent = '请先保存模型配置，再测试连接。';
                return;
            }
            const revision = state.revision;
            const snapshot = state.saved;
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 45000);
            button.disabled = true;
            note.textContent = '正在检查模型连接与工具调用…';
            try {
                const result = await api(`connections/${preset}/test`, { method: 'POST', body: '{}', signal: controller.signal });
                note.textContent = revision === state.revision && snapshot === connectionSnapshot(preset) ? `${result.provider} / ${result.model}：${result.message}` : '配置已更新，请保存后重新测试连接。';
            } catch (error) {
                note.textContent = error.name === 'AbortError' ? '连接测试超时，请检查网络或稍后重试。' : error.message;
            } finally {
                clearTimeout(timeout);
                button.disabled = !config.supported;
            }
        });
    });
    document.addEventListener('input', event => {
        const preset = event.target.dataset.agentConnection;
        if (preset) document.querySelector(`[data-agent-connection-notice="${preset}"]`).textContent = '';
    });

    function showNotice(text, error = false, retry = null) {
        const failed = run?.status === 'failed' && failedRequest(run) ? run : null;
        if (failed) {
            if (!text) { text = failed.error || '执行失败，原消息已保留。'; error = true; }
            retry ||= () => retryFailedMessage(failed);
        }
        notice.textContent = text;
        if (text && retry) {
            const button = node('button', 'button button-small', '重试此消息'); button.type = 'button'; button.dataset.agentRetry = '';
            button.disabled = $('[data-agent-send]').disabled;
            button.addEventListener('click', retry); notice.append(button);
        }
        notice.classList.toggle('is-error', error);
        notice.hidden = !text;
    }

    function failedRequest(next) {
        const index = next.conversation.map(entry => entry.role).lastIndexOf('user');
        return index < 0 ? null : { index, entry: next.conversation[index] };
    }

    function restoreFailedDraft(next) {
        const failed = failedRequest(next);
        if (!failed) return;
        const key = `${next.id}:${failed.index}`, saved = `${storageKey}:failed-draft:${next.id}`;
        if (recoveredFailures.has(key)) return;
        try { if (sessionStorage.getItem(saved) === String(failed.index)) return; } catch { /* Storage is optional. */ }
        recoveredFailures.add(key);
        if (!prompt.value && !(composer?.files() || []).length) {
            prompt.value = failed.entry.text; composer?.resize(); rememberDraft();
        }
        try { sessionStorage.setItem(saved, String(failed.index)); } catch { /* Storage is optional. */ }
    }

    async function retryFailedMessage(failed) {
        const request = failedRequest(failed);
        if (failed.id !== runId || run?.status !== 'failed' || !request || $('[data-agent-send]').disabled) return;
        const preserveDraft = prompt.value !== request.entry.text || (composer?.files() || []).length > 0;
        await send(request.entry.text, preserveDraft);
    }

    function remember(id) {
        try {
            if (id) localStorage.setItem(storageKey, String(id));
            else localStorage.removeItem(storageKey);
        } catch { /* Private browsing may disable localStorage. */ }
        history?.setCurrent(id);
    }

    function controls() {
        const active = run && ['queued', 'running'].includes(run.status);
        const ready = config.enabled && config.supported;
        const stopping = cancelling.has(runId);
        const sendButton = $('[data-agent-send]'), stopButton = $('[data-agent-cancel]');
        sendButton.disabled = !ready || !Object.hasOwn(config.models || {}, modelSelect.value) || loading || sending || deleting || changing.size > 0 || active || run?.status === 'cancelled';
        sendButton.hidden = !!active;
        sendButton.classList.toggle('is-sending', sending);
        sendButton.setAttribute('aria-label', sending ? '正在发送' : '发送');
        sendButton.title = sending ? '正在发送' : '发送';
        sendButton.setAttribute('aria-busy', String(sending));
        stopButton.hidden = !active;
        stopButton.disabled = stopping;
        stopButton.setAttribute('aria-label', stopping ? '正在停止' : '停止回复');
        stopButton.title = stopping ? '正在停止' : '停止回复';
        const retry = $('[data-agent-retry]'); if (retry) retry.disabled = $('[data-agent-send]').disabled;
        modelSelect.disabled = !ready || loading || sending || deleting || changing.size > 0 || active;
        prompt.disabled = !ready || loading || sending || deleting || run?.status === 'cancelled';
        $('[data-agent-attach]').disabled = prompt.disabled;
        $('[data-agent-file]').disabled = prompt.disabled;
        document.querySelectorAll('[data-agent-attachments] button').forEach(button => { button.disabled = prompt.disabled; });
        $('[data-agent-new]').disabled = sending || deleting || changing.size > 0;
        $('[data-agent-refresh]').disabled = !runId || loading || sending || changing.size > 0;
        $('[data-agent-refresh]').setAttribute('aria-busy', String(refreshing));
        const pending = progress.dataset.executionIndex === 'pending';
        progressState.textContent = sending ? '发送中' : loading ? '读取中' : pending ? '未发送' : run?.status === 'running' ? (run.partial?.text ? '回复中' : '思考中') : run ? statuses[run.status] || run.status : '尚未开始';
        if (pending || !run) progressUsage.textContent = '';
        progress.hidden = !run && !sending && !loading && !pending;
        progress.dataset.status = pending ? sending ? 'queued' : 'failed' : run?.status || 'idle';
        const waiting = !loading && (sending || active) && !run?.partial?.text;
        responseStatus.hidden = !waiting;
        progressState.hidden = waiting;
        let state = sending ? '正在发送…' : '正在思考…';
        if (active && run.status === 'queued') state = run.step > 0 ? '正在继续处理…' : '正在准备回复…';
        if (stopping) state = '正在停止…';
        responseLabel.textContent = state;
        messages.setAttribute('aria-busy', String(!!(sending || active || loading)));
    }

    // Build links as DOM nodes; model output never becomes executable HTML.
    function messageText(element, text) {
        const pattern = /\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)|(https?:\/\/[^\s<>]+)/g;
        let start = 0;
        for (const match of text.matchAll(pattern)) {
            element.append(document.createTextNode(text.slice(start, match.index)));
            const link = node('a', '', match[1] || match[3]);
            link.href = match[2] || match[3];
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            element.append(link);
            start = match.index + match[0].length;
        }
        element.append(document.createTextNode(text.slice(start)));
    }

    function contentText(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        template.content.querySelectorAll('script, style, noscript, iframe').forEach(element => element.remove());
        template.content.querySelectorAll('br').forEach(element => element.replaceWith(document.createTextNode('\n')));
        template.content.querySelectorAll('p, div, h1, h2, h3, h4, h5, h6, li, tr, blockquote').forEach(element => {
            if (element.tagName === 'LI') element.prepend(document.createTextNode('• '));
            element.append(document.createTextNode('\n'));
        });
        return template.content.textContent.replace(/\n{3,}/g, '\n\n').trim();
    }

    const mayWrite = name => Array.isArray(config.writeTools) && config.writeTools.includes(name);
    const writePermissionNotice = '此工具未获写入授权，请在 AI Agent 的工具权限中授权并保存。';

    function editorReview(postId, change) {
        const page = window.oyisoAgentPage?.snapshot();
        const inEditor = postId > 0 && page?.post_id === postId;
        const expected = change.editor_before || change.before;
        const saved = change.saved || change.before;
        const fields = Object.keys(change.after).filter(key => change.after[key] !== change.before[key] || change.status === 'filled' && change.after[key] !== saved[key]);
        const baselines = change.status === 'filled'
            ? [expected, saved, change.after].map(snapshot => Object.fromEntries(fields.map(key => [key, snapshot[key]])))
            : [expected];
        return { inEditor, changed: inEditor && !baselines.some(snapshot => Object.entries(snapshot).every(([key, value]) => page.fields[key] === value)) };
    }

    function renderChanges(changes) {
        const container = $('[data-agent-changes]');
        const previousHistory = $('[data-agent-content-history]', container);
        const historyOpen = previousHistory?.open && previousHistory.dataset.agentContentHistory === String(runId);
        container.replaceChildren();
        const visible = changes.filter(change => !['superseded', 'cancelled'].includes(change.status));
        if (visible.length) container.append(node('h3', '', visible.some(change => change.type === 'trash') ? '商品操作' : '商品修改'));
        for (const change of visible) {
            const section = node('section', 'oyiso-agent-change');
            const heading = node('div', 'oyiso-agent-change-heading');
            heading.append(node('h4', '', `${change.name}（#${change.product_id}）`));
            const state = change.type === 'trash' ? { pending: '等待确认', applied: '已移入回收站', reverted: '已恢复', dismissed: '已取消' } : { pending: '尚未保存', applied: '已保存', reverted: '已撤回', filled: '已填入编辑器，尚未保存' };
            heading.append(node('span', 'description', state[change.status] || change.status));
            section.append(heading);
            if (change.type === 'trash') {
                const states = { publish: '已发布', private: '私密', draft: '草稿', pending: '待审核', future: '定时发布' };
                section.append(node('p', 'description', 'SKU：' + (change.sku || '未设置') + ' · 原状态：' + (states[change.previous_status] || change.previous_status || '未知') + ' · 关联变体：' + (change.variation_count || 0)));
                section.append(node('p', '', change.reason || ''));
                if (change.status === 'pending') section.append(node('p', 'description', mayWrite('oyiso/apply-product-trash') ? '确认后移入回收站，商品及关联变体从前台下架，图片和历史订单保留。' : writePermissionNotice));
                if (change.status === 'applied' && !mayWrite('oyiso/revert-product-trash')) section.append(node('p', 'description', writePermissionNotice));
                if (change.status === 'pending' || change.status === 'applied') {
                    const actions = node('div', 'oyiso-agent-product-actions');
                    for (const [action, label] of change.status === 'pending' ? [['dismiss', '不删除'], ['apply', '移入回收站']] : [['restore', '恢复商品']]) {
                        const button = node('button', 'button' + (action === 'apply' ? ' oyiso-button-danger' : ''), label);
                        button.type = 'button';
                        const permission = action === 'restore' ? 'oyiso/revert-product-trash' : 'oyiso/apply-product-trash';
                        button.disabled = !config.enabled || (action !== 'dismiss' && !mayWrite(permission)) || ['queued', 'running'].includes(run.status) || (action !== 'restore' && run.status === 'cancelled') || changing.has(change.id) || sending;
                        button.addEventListener('click', () => action === 'apply' ? confirmProductTrash(change, button) : applyChange(change.id, action));
                        actions.append(button);
                    }
                    section.append(actions);
                }
                container.append(section);
                continue;
            }
            for (const [name, after] of Object.entries(change.after)) {
                const before = change.before[name] || '';
                if (before === after) continue;
                const detail = node('details', 'oyiso-agent-field-diff');
                detail.open = change.status === 'pending';
                detail.append(node('summary', '', fields[name] || name));
                const columns = node('div', 'oyiso-agent-diff-columns');
                const previews = [];
                const isHtml = ['description', 'short_description'].includes(name);
                for (const [label, value] of [['原内容', before], ['拟修改', after]]) {
                    const column = node('div');
                    const preview = node('pre', '', (isHtml ? contentText(value) : value) || '（空）');
                    previews.push([preview, value]);
                    column.append(node('p', 'description', label), preview);
                    columns.append(column);
                }
                if (isHtml) {
                    const label = node('label', 'oyiso-agent-source-toggle');
                    const toggle = node('input'); toggle.type = 'checkbox';
                    label.append(toggle, document.createTextNode('查看 HTML'));
                    toggle.addEventListener('change', event => {
                        event.stopPropagation();
                        for (const [preview, value] of previews) preview.textContent = (toggle.checked ? value : contentText(value)) || '（空）';
                    });
                    toggle.addEventListener('keypress', event => event.stopPropagation());
                    detail.append(label);
                }
                detail.append(columns);
                section.append(detail);
            }
            if (change.status === 'pending' || change.status === 'applied' || change.status === 'filled' && window.oyisoAgentPage?.snapshot().post_id === change.product_id) {
                const action = change.status === 'applied' ? 'revert' : 'apply';
                const button = node('button', `button ${action === 'apply' ? 'button-primary' : ''}`, action === 'apply' ? '应用修改' : '撤回修改');
                button.type = 'button';
                if (!mayWrite(`oyiso/${action}-product-update`)) section.append(node('p', 'description', writePermissionNotice));
                button.disabled = !config.enabled || !mayWrite(`oyiso/${action}-product-update`) || ['queued', 'running'].includes(run.status) || (action === 'apply' && run.status === 'cancelled') || changing.has(change.id) || sending;
                button.addEventListener('click', () => applyChange(change.id, action));
                if (change.status !== 'filled' && !(change.status === 'pending' && window.oyisoAgentPage?.snapshot().post_id === change.product_id)) section.append(button);
                if (['pending', 'filled'].includes(change.status) && window.oyisoAgentPage?.snapshot().post_id === change.product_id) {
                    const review = editorReview(change.product_id, change);
                    if (review.changed) section.append(node('p', 'description', '当前内容已更新，可以按原要求重新生成预览。'));
                    const fill = node('button', 'button button-primary', review.changed ? '按当前内容重新生成' : change.status === 'filled' ? '重新填入当前编辑器' : '填入当前编辑器'); fill.type = 'button'; fill.disabled = button.disabled;
                    if (review.changed) fill.dataset.agentRegenerate = change.id;
                    fill.addEventListener('click', () => review.changed ? regenerate(change, 'product') : fillEditor(change, 'product')); section.append(fill);
                }
            }
            container.append(section);
        }
        const contentChanges = (run?.content_changes || []).filter(change => !['superseded', 'cancelled', 'dismissed'].includes(change.status));
        const latestContent = new Map();
        for (const change of contentChanges) if (change.post_id > 0 && change.kind !== 'draft') latestContent.set(change.post_id, change);
        const historical = new Set(contentChanges.filter(change => change.post_id > 0 && change.kind !== 'draft' && latestContent.get(change.post_id) !== change && ['filled', 'applied', 'reverted'].includes(change.status)));
        let contentHistory;
        if (historical.size) {
            contentHistory = node('details', 'oyiso-agent-change-history');
            contentHistory.dataset.agentContentHistory = String(runId);
            contentHistory.open = !!historyOpen;
            contentHistory.append(node('summary', '', `历史方案（${historical.size}）`));
        }
        if (contentChanges.length) container.append(node('h3', '', '文章与页面'));
        for (const change of contentChanges) {
            const section = node('section', 'oyiso-agent-change');
            section.dataset.agentContentChange = change.id;
            const heading = node('div', 'oyiso-agent-change-heading');
            const states = { pending: '尚未保存', applied: change.kind === 'draft' ? '草稿已创建' : '已保存', filled: '已填入编辑器，尚未保存', reverted: '已撤回' };
            heading.append(node('h4', '', `${change.title || '未命名'}${change.post_id ? `（#${change.post_id}）` : '（新草稿）'}`), node('span', 'description', states[change.status] || change.status));
            section.append(heading);
            for (const [field, after] of Object.entries(change.after)) {
                const before = change.before[field] || '';
                if (before === after) continue;
                const detail = node('details', 'oyiso-agent-field-diff'); detail.open = change.status === 'pending';
                detail.append(node('summary', '', ({ title: '标题', content: '正文', excerpt: '摘要', ...fields })[field] || field));
                const columns = node('div', 'oyiso-agent-diff-columns');
                for (const [label, value] of [['原内容', before], ['拟修改', after]]) {
                    const column = node('div'); column.append(node('p', 'description', label), node('pre', '', (['content', 'excerpt'].includes(field) ? contentText(value) : value) || '（空）')); columns.append(column);
                }
                detail.append(columns); section.append(detail);
            }
            const { inEditor, changed } = editorReview(change.post_id, change);
            if (change.status === 'pending' || change.status === 'applied' && change.kind !== 'draft' || change.status === 'filled' && inEditor && change.kind !== 'draft') {
                const actions = node('div', 'oyiso-agent-content-actions');
                const permission = change.kind === 'draft' ? 'oyiso/create-post-draft' : change.status === 'applied' ? 'oyiso/revert-post-update' : 'oyiso/apply-post-update';
                if (!mayWrite(permission)) section.append(node('p', 'description', writePermissionNotice));
                const separateDraft = change.kind === 'draft' && window.oyisoAgentPage?.snapshot().post_id > 0;
                if (separateDraft) section.append(node('p', 'description', '此方案将另外创建一份独立草稿。'));
                if (inEditor && ['pending', 'filled'].includes(change.status) && change.kind !== 'draft') section.append(node('p', 'description', changed ? '当前内容已更新，可以按原要求重新生成预览。' : '填入后请检查内容，再使用 WordPress 的保存按钮。'));
                const editorAction = changed ? ['regenerate', '按当前内容重新生成'] : ['editor', change.status === 'filled' ? '重新填入当前编辑器' : '填入当前编辑器'];
                const options = change.status === 'filled' ? [editorAction] : change.status === 'applied' ? [['revert', '撤回修改']] : change.kind === 'draft' ? [['apply', separateDraft ? '另外创建草稿' : '创建草稿'], ['dismiss', '取消']] : [inEditor ? editorAction : ['apply', '保存到站点'], ['dismiss', '取消']];
                for (const [action, label] of options) {
                    const button = node('button', 'button' + (action === 'apply' ? ' button-primary' : ''), label); button.type = 'button';
                    button.disabled = !config.enabled || action !== 'dismiss' && !mayWrite(permission) || ['queued', 'running', 'cancelled'].includes(run.status) || changing.has(change.id) || sending;
                    if (action === 'regenerate') button.dataset.agentRegenerate = change.id;
                    button.addEventListener('click', () => action === 'regenerate' ? regenerate(change) : action === 'editor' ? fillEditor(change) : applyContent(change, action)); actions.append(button);
                }
                section.append(actions);
            }
            if (change.url) { const link = node('a', 'button', '打开草稿'); link.href = change.url; link.target = '_blank'; link.rel = 'noopener noreferrer'; section.append(link); }
            (historical.has(change) ? contentHistory : container).append(section);
        }
        if (contentHistory) container.append(contentHistory);
        const actions = (run?.mcp_actions || []).filter(action => action.status !== 'cancelled');
        if (actions.length) container.append(node('h3', '', 'MCP 操作'));
        for (const action of actions) {
            const section = node('section', 'oyiso-agent-change');
            const heading = node('div', 'oyiso-agent-change-heading');
            const states = { pending: '等待确认', executing: '已提交执行，请核对实际结果', completed: '已执行', dismissed: '已取消', failed: '执行失败，请先核对实际结果' };
            heading.append(node('h4', '', action.label), node('span', 'description', states[action.status] || action.status));
            section.append(heading, node('p', 'description', action.name));
            const detail = node('details', 'oyiso-agent-field-diff');
            detail.open = action.status === 'pending';
            detail.append(node('summary', '', '操作参数'));
            let parameters = action.input_json;
            try { parameters = JSON.stringify(JSON.parse(parameters), null, 2); } catch { /* Show stored text if an old record is malformed. */ }
            detail.append(node('pre', 'oyiso-agent-mcp-result', parameters));
            section.append(detail);
            if (action.result) {
                const result = node('details', 'oyiso-agent-field-diff');
                result.append(node('summary', '', '执行结果'), node('pre', 'oyiso-agent-mcp-result', action.result));
                section.append(result);
            }
            if (action.status === 'pending') {
                section.append(node('p', 'description', mayWrite(action.name) ? '确认后将调用此工具修改站点。' : writePermissionNotice));
                for (const [decision, label] of [['dismiss', '取消'], ['execute', '确认执行']]) {
                    const button = node('button', `button${decision === 'execute' ? ' button-primary' : ''}`, changing.has(action.id) ? '处理中…' : label);
                    button.type = 'button';
                    button.disabled = !config.enabled || (decision === 'execute' && !mayWrite(action.name)) || ['queued', 'running', 'cancelled'].includes(run.status) || changing.has(action.id) || sending;
                    button.addEventListener('click', () => applyChange(action.id, decision, 'mcp'));
                    section.append(button);
                }
            }
            container.append(section);
        }
    }

    function confirmProductTrash(change, trigger) {
        if (!mayWrite('oyiso/apply-product-trash') || !productDialog || changing.has(change.id) || !run?.changes.some(item => item.id === change.id && item.type === 'trash' && item.status === 'pending') || ['queued', 'running', 'cancelled'].includes(run.status)) return;
        productConfirmation = { runId, proposal: change.id, trigger };
        $('[data-agent-product-description]', productDialog).textContent = '确定将“' + change.name + '”（#' + change.product_id + '，SKU：' + (change.sku || '未设置') + '）及其 ' + (change.variation_count || 0) + ' 个关联变体移入回收站？';
        window.oyisoModalMotion.show(productDialog);
        $('[data-agent-product-cancel]', productDialog).focus();
    }

    function closeProductConfirmation() {
        if (!productDialog) return;
        const trigger = productConfirmation?.trigger;
        productConfirmation = null;
        window.oyisoModalMotion.hide(productDialog, () => {
            if (trigger?.isConnected) trigger.focus();
            else if (panelOpen) prompt.focus();
        });
    }

    productDialog?.querySelectorAll('[data-agent-product-close], [data-agent-product-cancel]').forEach(button => button.addEventListener('click', closeProductConfirmation));
    productDialog?.addEventListener('cancel', event => { event.preventDefault(); closeProductConfirmation(); });
    productDialog?.addEventListener('keydown', event => { if (event.key === 'Escape') event.stopPropagation(); });
    productDialog?.querySelector('[data-agent-product-confirm]').addEventListener('click', () => {
        const pending = productConfirmation;
        if (!pending) return;
        closeProductConfirmation();
        if (pending.runId === runId && run?.changes.some(change => change.id === pending.proposal && change.type === 'trash' && change.status === 'pending')) applyChange(pending.proposal, 'trash');
    });

    function render(next, animate = false) {
        if (next.id !== runId) return;
        const nearBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 90;
        const active = ['queued', 'running'].includes(next.status);
        // Unsent choices belong to the conversation; active tasks use the server's model.
        if (active && (!run || !['queued', 'running'].includes(run.status))) forgetModel(next.id);
        if (!run || sending || active && modelSelect.value !== next.selection) {
            const selected = active ? next.selection : modelFor(next.id, next.selection);
            if (typeof selected === 'string') populateModels(config.models, selected);
        }
        const previous = run;
        run = next;
        if (next.status === 'cancelled') liveRuns.delete(next.id);
        if (next.status === 'failed') restoreFailedDraft(next);
        const executionRows = renderExecutions(next);
        controls();
        if (next.error || next.status === 'failed') showNotice(!next.selection && next.error.startsWith('尚无可用的工具调用模型') ? '此对话尚未选择模型。请在下方选择已添加的模型后重新发送。' : next.error || '执行失败，原消息已保留。', true, next.status === 'failed' && failedRequest(next) ? () => retryFailedMessage(next) : null);
        else if (next.status === 'cancelled') showNotice('任务已取消，后续步骤和待审核修改已停止。可新建对话继续。');
        else showNotice('');
        const signature = JSON.stringify([next.conversation, next.events, next.changes, next.content_changes, next.mcp_actions]);
        const conversationSignature = JSON.stringify([next.conversation, [...executionRows.keys()]]);
        let partial = $('[data-agent-partial]', messages);
        if (!version || conversationSignature !== conversationVersion) {
            conversationVersion = conversationSignature;
            composer?.syncFiles(next.conversation.flatMap(entry => entry.attachments || []));
            const fragment = document.createDocumentFragment();
            const updated = [];
            if (executionRows.has(-1)) fragment.append(executionRows.get(-1));
            const legacyModels = [...new Set(next.events.filter(event => event.label === '模型已响应' && event.detail).map(event => event.detail))];
            for (const [index, entry] of next.conversation.entries()) {
                const isUser = entry.role === 'user';
                const last = index === next.conversation.length - 1;
                const fromPartial = !isUser && last && partial && previous?.partial?.text && entry.text.startsWith(previous.partial.text);
                const item = (fromPartial ? partial : $(`[data-agent-message-index="${index}"]`, messages)) || node('article', `oyiso-agent-message is-${isUser ? 'user' : 'assistant'}`);
                if (fromPartial) { item.removeAttribute('data-agent-partial'); item.classList.remove('is-streaming'); partial = null; }
                item.classList.toggle('is-user', isUser); item.classList.toggle('is-assistant', !isUser);
                item.dataset.agentMessageIndex = String(index);
                const model = entry.model || (legacyModels.length === 1 ? legacyModels[0] : 'AI');
                const entrySignature = JSON.stringify([entry, model]);
                if (messageEntries.get(item) !== entrySignature) {
                    let role = $('.oyiso-agent-message-role', item), content = $('.oyiso-agent-message-content', item);
                    if (!role) { role = node('strong', 'oyiso-agent-message-role'); item.append(role); }
                    if (!content) { content = node('div', 'oyiso-agent-message-content'); item.append(content); }
                    role.textContent = isUser ? '我' : model;
                    if (!isUser && window.oyisoAgentMarkdown) updated.push(() => window.oyisoAgentMarkdown(content, entry.text, { animate: animate && last, scroll }));
                    else { content.replaceChildren(); messageText(content, entry.text); }
                    $('.oyiso-agent-message-attachments', item)?.remove();
                    composer?.renderFiles(item, entry.attachments);
                    messageEntries.set(item, entrySignature);
                }
                fragment.append(item);
                if (isUser && executionRows.has(index)) fragment.append(executionRows.get(index));
            }
            if (!next.conversation.length && !next.partial?.text && !active) fragment.append(empty.cloneNode(true));
            if (next.partial?.text && partial) fragment.append(partial);
            messages.replaceChildren(fragment);
            updated.forEach(paint => paint());
        }
        if (signature !== version) {
            version = signature;
            renderChanges(next.changes);
        }
        if (next.partial?.text) {
            if (!next.conversation.length) $('.oyiso-agent-empty', messages)?.remove();
            if (!partial) {
                partial = node('article', 'oyiso-agent-message is-assistant'); partial.dataset.agentPartial = '';
                partial.append(node('strong', 'oyiso-agent-message-role'), node('div', 'oyiso-agent-message-content'));
                messages.append(partial);
            }
            $('.oyiso-agent-message-role', partial).textContent = next.partial.model || 'AI';
            const partialContent = $('.oyiso-agent-message-content', partial);
            if (window.oyisoAgentMarkdown) window.oyisoAgentMarkdown(partialContent, next.partial.text, { streaming: active, animate, scroll });
            else partialContent.textContent = next.partial.text;
            partial.classList.toggle('is-streaming', ['queued', 'running'].includes(next.status));
        } else partial?.remove();
        if (nearBottom) scroll.scrollTop = scroll.scrollHeight;
    }

    function drive(id) {
        if (stepping.has(id) || liveRuns.has(id)) return;
        stepping.add(id);
        const receiver = liveReceiver();
        const revision = pollRevision;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 180000);
        api(`runs/${id}/step`, { method: 'POST', body: '{}', signal: controller.signal, live: receiver.event })
            .then(data => { if (id === runId && revision === pollRevision && panelOpen) render(data, true); })
            .catch(error => {
                if (id !== runId || revision !== pollRevision || !panelOpen || error.name === 'AbortError') return;
                if (error.status === 404 && error.code === 'agent_task_missing') unavailableRun(id);
                else showNotice(error.message, true);
            })
            .finally(() => {
                clearTimeout(timeout); receiver.close(); stepping.delete(id);
                if (id === runId && panelOpen && ['queued', 'running'].includes(run?.status)) { clearTimeout(timer); timer = setTimeout(poll, 700); }
            });
    }

    function unavailableRun(id) {
        liveRuns.delete(id); liveOwners.delete(id);
        history?.forget(id);
        if (id !== runId) return;
        newConversation();
        showNotice('该对话已不存在或无法访问，已从记录中移除。');
    }

    async function poll(force = false) {
        clearTimeout(timer);
        const id = runId;
        if (!id || !panelOpen) return;
        if (!force && liveRuns.has(id)) { loading = false; render(liveRuns.get(id)); return; }
        const revision = ++pollRevision;
        pollController?.abort();
        const controller = new AbortController(); pollController = controller;
        const timeout = setTimeout(() => controller.abort(), 15000);
        loading = force || !run || run.id !== id;
        refreshing = force;
        if (!run || run.id !== id) showConversationLoading();
        controls();
        try {
            const data = await api(`runs/${id}`, { signal: controller.signal });
            if (id !== runId || revision !== pollRevision || !panelOpen) return;
            loading = false;
            refreshing = false;
            render(liveRuns.get(id) || data);
            if (data.status === 'queued' || data.recoverable) drive(id);
            if (['queued', 'running'].includes(data.status)) timer = setTimeout(poll, 700);
        } catch (error) {
            if (id === runId && revision === pollRevision && panelOpen) {
                if (error.status === 404 && error.code === 'agent_task_missing') { unavailableRun(id); return; }
                loading = false;
                refreshing = false;
                controls();
                if (!run) { messages.replaceChildren(node('p', 'description', '暂时无法加载对话，请点击右上角刷新重试。')); messages.setAttribute('aria-busy', 'false'); }
                showNotice(error.name === 'AbortError' ? '读取对话超时，请点击刷新重试。' : error.message, true);
                timer = setTimeout(poll, 6000);
            }
        } finally {
            clearTimeout(timeout);
            if (pollController === controller) pollController = undefined;
        }
    }

    async function send(override, preserveDraft = true) {
        const generated = typeof override === 'string';
        const keepDraft = generated && preserveDraft;
        const files = generated ? [] : composer?.files() || [];
        const message = generated ? override : prompt.value.trim() || (files.length ? '请分析附件。' : '');
        if (!message || $('[data-agent-send]').disabled) return;
        const source = runId, sequence = ++sendSequence;
        $('[data-agent-pending]', messages)?.remove();
        $('.oyiso-agent-empty', messages)?.remove();
        const pending = node('article', 'oyiso-agent-message is-user'); pending.dataset.agentPending = '';
        pending.append(node('strong', 'oyiso-agent-message-role', '我'));
        const pendingText = node('div', 'oyiso-agent-message-content'); messageText(pendingText, message); pending.append(pendingText);
        if (files.length) pending.append(node('span', 'description', files.map(file => file.name).join('、')));
        messages.append(pending);
        freezeProgress(); progress.dataset.executionIndex = 'pending'; progress.open = false;
        progressEvents.replaceChildren(); progressUsage.textContent = ''; progressVersions.delete(progress);
        messages.append(progress);
        let accepted = false, sentId = 0;
        const accept = next => {
            if (accepted) return;
            accepted = true; sentId = next.id;
            if (!keepDraft) forgetDraft(source);
            runId = next.id; run = null; version = '';
            if (!keepDraft) { composer?.switchTo(runId); prompt.value = ''; composer?.resize(); }
            remember(runId); rememberDraft();
            sending = false; controls(); scroll.scrollTop = scroll.scrollHeight;
        };
        const receiver = liveReceiver(accept);
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 180000);
        sending = true;
        controls();
        scroll.scrollTop = scroll.scrollHeight;
        if (generated && run) renderChanges(run.changes);
        try {
            let body;
            const pageContext = window.oyisoAgentPage?.snapshot();
            if (files.length) {
                body = new FormData(); body.append('message', message); body.append('run_id', String(runId)); body.append('selection', modelSelect.value); files.forEach(file => body.append('files[]', file, file.name));
                if (pageContext) body.append('page_context', JSON.stringify(pageContext));
            } else body = JSON.stringify({ message, run_id: runId, selection: modelSelect.value, ...(pageContext ? { page_context: JSON.stringify(pageContext) } : {}) });
            const next = await api('runs', { method: 'POST', body, signal: controller.signal, live: receiver.event });
            if (!accepted) accept(next);
            if (sentId === runId && sequence === sendSequence && panelOpen) render(next, true);
        } catch (error) { if (!sentId || sentId === runId) showNotice(error.name === 'AbortError' ? '回复连接超时，正在重新读取对话进度。' : error.message, true); }
        finally {
            clearTimeout(timeout); receiver.close();
            if (sequence === sendSequence) { sending = false; controls(); if (generated && run) renderChanges(run.changes); }
            if (accepted && sentId === runId && (!receiver.received || ['queued', 'running'].includes(run?.status))) poll();
            if (accepted) loadLists();
        }
    }

    async function regenerate(change, kind = 'post') {
        const postId = kind === 'product' ? change.product_id : change.post_id;
        if (change.status !== 'pending' || !mayWrite(`oyiso/apply-${kind === 'product' ? 'product' : 'post'}-update`) || $('[data-agent-send]').disabled) return;
        if (window.oyisoAgentPage?.snapshot().post_id !== postId) { showNotice('请在对应的内容编辑页重新生成方案。', true); return; }
        const labels = { title: '标题', content: '正文', excerpt: '摘要', ...fields };
        const affected = Object.entries(change.after).filter(([key, value]) => value !== change.before[key]).map(([key]) => labels[key] || '内容').join('、');
        const target = kind === 'product' ? '商品' : change.post_type === 'page' ? '页面' : '文章';
        await send(`请按当前${target}（#${postId}）上一方案的修改要求，基于当前编辑器内容重新生成预览，涉及：${affected}。\n请在本轮先重新读取最新内容，保留我后续的编辑。只生成新预览，等待我确认，不要直接填入或保存。`);
    }

    async function applyChange(proposal, action, kind = 'changes') {
        if (changing.has(proposal)) return;
        const change = kind === 'changes' ? run?.changes.find(item => item.id === proposal) : null;
        const decision = { trash: 'apply', restore: 'revert' }[action] || action;
        const operation = change?.type === 'trash' || ['trash', 'restore'].includes(action) ? 'trash' : 'update';
        const permission = kind === 'mcp' ? run?.mcp_actions?.find(item => item.id === proposal)?.name : `oyiso/${decision}-product-${operation}`;
        if (action !== 'dismiss' && !mayWrite(permission)) return;
        const id = runId;
        if (action === 'apply' && change && operation === 'update' && window.oyisoAgentPage?.snapshot().post_id === change.product_id) {
            try { window.oyisoAgentPage.check({ post_id: change.product_id, expected: change.editor_before || change.before }); } catch (error) { showNotice(error.message, true); return; }
        }
        changing.add(proposal);
        controls();
        renderChanges(run.changes);
        try {
            const next = await api(`runs/${id}/${kind}/${proposal}`, { method: 'POST', body: JSON.stringify({ action }) });
            if (id === runId) { version = ''; render(next); if (kind === 'mcp' && next.status === 'queued') poll(); }
            loadLists();
        } catch (error) { if (id === runId) showNotice(error.message, true); }
        finally { changing.delete(proposal); controls(); if (runId === id && run) renderChanges(run.changes); }
    }

    async function fillEditor(change, kind = 'post') {
        if (changing.has(change.id)) return;
        const id = runId;
        changing.add(change.id); controls(); renderChanges(run.changes);
        try {
            const payload = await api(`runs/${id}/content/${change.id}`, { method: 'POST', body: JSON.stringify({ action: 'editor', kind }) });
            if (id !== runId) return;
            if (!window.oyisoAgentPage) throw new Error('请在对应的内容编辑页使用此操作。');
            window.oyisoAgentPage.apply(payload);
            const next = await api(`runs/${id}/content/${change.id}`, { method: 'POST', body: JSON.stringify({ action: 'filled', kind, signature: payload.signature }) });
            if (id === runId) { version = ''; render(next); showNotice('内容已填入当前编辑器，尚未保存。请检查后使用 WordPress 的保存按钮。'); }
            loadLists();
        } catch (error) { if (id === runId) showNotice(error.message, true); }
        finally { changing.delete(change.id); controls(); if (runId === id && run) renderChanges(run.changes); }
    }

    async function applyContent(change, action) {
        if (changing.has(change.id)) return;
        const id = runId;
        try {
            if (action === 'apply' && change.kind !== 'draft' && window.oyisoAgentPage?.snapshot().post_id === change.post_id) window.oyisoAgentPage.check({ post_id: change.post_id, expected: change.before });
            changing.add(change.id); controls(); renderChanges(run.changes);
            const next = await api(`runs/${id}/content/${change.id}`, { method: 'POST', body: JSON.stringify({ action }) });
            if (id === runId) { version = ''; render(next); }
            loadLists();
        } catch (error) { if (id === runId) showNotice(error.message, true); }
        finally { changing.delete(change.id); controls(); if (runId === id && run) renderChanges(run.changes); }
    }

    async function openRun(id) {
        if (panel.dataset.historyLayout !== 'sidebar') setHistory(false, false);
        if (sending || deleting || changing.size) {
            setPanel(true);
            showNotice('请等待当前操作完成后再切换对话。');
            return;
        }
        rememberDraft();
        if (id === runId && run) { if (!panelOpen) setPanel(true); return; }
        clearTimeout(timer);
        runId = id;
        run = null;
        version = '';
        parkProgress();
        remember(id);
        showConversationLoading();
        progressEvents.replaceChildren(); progressVersions.delete(progress);
        $('[data-agent-changes]').replaceChildren();
        prompt.value = draftFor(id);
        composer?.switchTo(id);
        rememberDraft();
        initialNotice();
        setPanel(true);
    }

    function loadLists() { history?.reload(); }

    panel.querySelectorAll('a[data-agent-tab]').forEach(link => {
        link.addEventListener('click', event => {
            if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || (link.target && link.target !== '_self')) return;
            const target = new URL(link.href);
            const current = new URL(window.location.href);
            const settings = document.querySelector('.csf-options[data-unique="oyiso"]');
            const tab = settings?.querySelector(`.csf-nav a[data-tab-id="${link.dataset.agentTab}"]`);
            const samePage = target.origin === current.origin && target.pathname === current.pathname && target.searchParams.get('page') === current.searchParams.get('page');
            setPanel(false, false, false);
            if (!samePage || !tab) return;
            event.preventDefault();
            // Use Oyiso's menu handler: changing only the hash leaves the previous section visible.
            tab.click();
            tab.focus({ preventScroll: true });
            window.scrollTo({ top: 0, behavior: 'instant' });
        });
    });
    launcher.addEventListener('click', () => { if (!panelOpen) setPanel(true); });
    historyToggle.addEventListener('click', () => { setSettingsMenu(false, false); setHistory(panelHistory.hidden); });
    $('[data-agent-history-back]').addEventListener('click', () => setHistory(false));
    historyDismiss.addEventListener('click', () => setHistory(false));
    panel.addEventListener('oyiso-agent-history-layout', syncHistory);
    panel.addEventListener('oyiso-agent-geometry-start', () => { if (cancelPanelMotion) finishPanelMotion(); setSettingsMenu(false, false); });
    $('[data-agent-close]').addEventListener('click', () => setPanel(false));
    $('[data-agent-fullscreen-toggle]').addEventListener('click', () => { setSettingsMenu(false, false); setFullscreen(!fullscreen); });
    settingsToggle.addEventListener('click', () => setSettingsMenu(settingsMenu.hidden));
    $('[data-agent-refresh]').addEventListener('click', () => { setSettingsMenu(false, false); rememberDraft(); poll(true); });
    layoutMenu.querySelectorAll('[data-agent-layout-choice]').forEach(button => button.addEventListener('click', () => {
        if (button.disabled) return;
        const choice = button.dataset.agentLayoutChoice;
        if (choice !== 'floating') setSide(choice === 'left');
        setLayout(choice === 'floating');
        setSettingsMenu(false);
    }));
    $('[data-agent-reset]').addEventListener('click', () => {
        setSettingsMenu(false);
        setSide(false);
        panel.dispatchEvent(new Event('oyiso-agent-reset-geometry'));
    });
    document.addEventListener('click', event => { if (!settingsMenu.hidden && !event.target.closest('[data-agent-settings-toggle], [data-agent-settings-menu]')) setSettingsMenu(false, false); });
    window.addEventListener('resize', () => { if (!settingsMenu.hidden) setSettingsMenu(false, false); });
    settingsMenu.addEventListener('keydown', event => {
        const buttons = menuItems();
        const index = buttons.indexOf(document.activeElement);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : ['ArrowDown', 'ArrowRight'].includes(event.key) ? (index + 1) % buttons.length : ['ArrowUp', 'ArrowLeft'].includes(event.key) ? (index + buttons.length - 1) % buttons.length : -1;
        if (next >= 0) { event.preventDefault(); buttons[next]?.focus(); }
    });
    document.addEventListener('keydown', event => {
        if (!settingsMenu.hidden && (event.key === 'Escape' || event.key === 'Tab')) {
            if (event.key === 'Escape') event.preventDefault();
            setSettingsMenu(false, event.key === 'Escape');
            return;
        }
        if (event.key === 'Escape' && panelOpen && (fullscreen || panel.contains(event.target) || event.target === document.body || event.target === launcher)) {
            event.preventDefault();
            if (!panelHistory.hidden && (!fullscreen || panel.dataset.historyLayout !== 'sidebar')) setHistory(false);
            else if (fullscreen) setFullscreen(false);
            else setPanel(false);
        }
    });
    $('[data-agent-send]').addEventListener('click', send);
    prompt.addEventListener('compositionstart', () => { composing = true; });
    prompt.addEventListener('compositionend', () => { composing = false; });
    prompt.addEventListener('keydown', event => {
        if (event.key !== 'Enter' || event.isComposing || composing || event.keyCode === 229) return;
        if (event.ctrlKey || event.metaKey || event.altKey) {
            event.preventDefault();
            if (prompt.disabled || prompt.readOnly || prompt.value.length - (prompt.selectionEnd - prompt.selectionStart) >= prompt.maxLength) return;
            prompt.setRangeText('\n', prompt.selectionStart, prompt.selectionEnd, 'end');
            prompt.dispatchEvent(new Event('input', { bubbles: true }));
        } else if (!event.shiftKey) {
            event.preventDefault();
            send();
        }
    });
    prompt.addEventListener('change', event => event.stopPropagation());
    prompt.addEventListener('keypress', event => event.stopPropagation());
    prompt.addEventListener('input', rememberDraft);
    modelSelect.addEventListener('change', event => {
        event.stopPropagation();
        store(':model', modelSelect.value);
        if (runId) { modelDrafts.set(runId, modelSelect.value); store(':model:' + runId, modelSelect.value); }
        controls();
    });
    modelSelect.addEventListener('keypress', event => event.stopPropagation());
    document.addEventListener('oyiso-agent-models-updated', event => { populateModels(event.detail); controls(); initialNotice(); });
    document.addEventListener('oyiso-agent-files-deleted', event => {
        if (!run || !Array.isArray(event.detail)) return;
        for (const entry of run.conversation) for (const file of entry.attachments || []) if (event.detail.includes(file.id)) file.deleted = true;
        render(run);
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden || !panelOpen) return;
        api('status').then(state => { if (state.models) { populateModels(state.models); controls(); initialNotice(); } }).catch(() => {});
    });
    chat.addEventListener('click', event => {
        const example = event.target.closest('[data-agent-example]');
        if (example && !prompt.disabled) { prompt.value = example.dataset.agentExample; rememberDraft(); composer?.resize(); prompt.focus(); }
    });
    function newConversation(options = {}) {
        if (!options.discardCurrent) rememberDraft();
        const draft = runId === 0 ? '' : draftFor(0);
        if (runId === 0) forgetDraft(0);
        if (panel.dataset.historyLayout !== 'sidebar') setHistory(false, false);
        clearTimeout(timer); pollController?.abort(); pollRevision++; loading = false; refreshing = false; runId = 0; run = null; version = ''; remember(0);
        parkProgress();
        let preferred;
        try { preferred = localStorage.getItem(storageKey + ':model'); } catch { /* Storage is optional. */ }
        populateModels(config.models, Object.hasOwn(config.models || {}, preferred) ? preferred : '');
        messages.replaceChildren(empty.cloneNode(true));
        messages.setAttribute('aria-busy', 'false');
        progressEvents.replaceChildren(); progressVersions.delete(progress);
        $('[data-agent-changes]').replaceChildren();
        prompt.value = draft;
        composer?.switchTo(0);
        rememberDraft();
        controls(); initialNotice(); prompt.focus();
    }
    $('[data-agent-new]').addEventListener('click', newConversation);

    $('[data-agent-cancel]').addEventListener('click', async () => {
        if (cancelling.has(runId) || !run || !['queued', 'running'].includes(run.status)) return;
        const id = runId;
        cancelling.add(id); controls();
        try { const data = await api(`runs/${id}/cancel`, { method: 'POST', body: '{}' }); if (id === runId) render(data); loadLists(); }
        catch (error) {
            if (error.status === 404 && error.code === 'agent_task_missing') unavailableRun(id);
            else { if (id === runId) showNotice(error.message, true); loadLists(); }
        }
        finally { cancelling.delete(id); controls(); }
    });
    composer = window.oyisoAgentComposer({ panel, prompt, key: storageKey, api, notice: showNotice });
    history = window.oyisoAgentHistory({
        api, openRun, current: () => runId, unavailable: unavailableRun,
        updated: data => { if (data.id === runId) render(data); },
        canDelete: () => !sending && !changing.size,
        deleting: value => { deleting = value; controls(); },
        removed: ids => {
            ids.forEach(forgetDraft);
            if (ids.includes(runId)) newConversation({ discardCurrent: true });
        },
    });

    function initialNotice() {
        if (!config.supported) showNotice('AI Agent 需要 WordPress 7.0 或以上版本。', true);
        else if (!config.enabled) showNotice('请先在「模型配置」中启用 AI Agent，并配置模型连接。');
        else if (!Object.keys(config.models || {}).length) showNotice('暂无可用模型，请先在「模型配置」中保存提供商密钥并添加模型。');
        else showNotice('');
    }
    if (window.jQuery) {
        window.jQuery(document).on('ajaxSuccess.oyisoAgent', (event, xhr, settings) => {
            const action = typeof settings.data === 'string' ? new URLSearchParams(settings.data).get('action') : settings.data?.action;
            if (action !== 'csf_oyiso_ajax_save') return;
            if (xhr.responseJSON?.success === false) return;
            const errors = xhr.responseJSON?.data?.errors || {};
            api('status').then(state => {
                config.enabled = state.enabled;
                config.supported = state.supported;
                config.writeTools = Array.isArray(state.writeTools) ? state.writeTools : [];
                if (!mayWrite('oyiso/apply-product-trash') && productConfirmation) closeProductConfirmation();
                if (run) renderChanges(run.changes);
                if (state.models) populateModels(state.models);
                if (!config.enabled) setPanel(false, false, false);
                for (const [name, configured] of Object.entries(state.secrets || {})) {
                    if (errors[`agent_${name}`]) continue;
                    const input = document.querySelector(`input[name="oyiso[agent_${name}]"]`);
                    const clear = document.querySelector(`input[type="checkbox"][name="oyiso[agent_${name}_clear]"]`);
                    if (input) { input.value = ''; input.placeholder = configured ? '已配置，留空保持' : '尚未配置'; }
                    if (clear) clear.checked = false;
                }
                for (const [preset, connection] of connectionStates) {
                    connection.revision++;
                    if (!Object.keys(errors).some(name => name.startsWith(`agent_${preset}_`))) connection.saved = connectionSnapshot(preset);
                    document.querySelector(`[data-agent-connection-notice="${preset}"]`).textContent = '';
                }
                controls(); initialNotice();
            }).catch(error => showNotice(error.message, true));
        });
    }
    let preferredModel;
    try { preferredModel = localStorage.getItem(storageKey + ':model'); } catch { /* Storage is optional. */ }
    populateModels(config.models, Object.hasOwn(config.models || {}, preferredModel) ? preferredModel : '');
    controls(); initialNotice(); loadLists();
    try {
        const saved = Number(localStorage.getItem(storageKey));
        if (Number.isSafeInteger(saved) && saved > 0) runId = saved;
        prompt.value = draftFor(runId);
        composer.switchTo(runId);
        setSide(localStorage.getItem(storageKey + ':side') === 'left');
        setLayout(localStorage.getItem(storageKey + ':layout') === 'floating');
        if (localStorage.getItem(storageKey + ':fullscreen') === '1') setFullscreen(true);
        if (localStorage.getItem(storageKey + ':history-open') === '1') setHistory(true, false);
        if (localStorage.getItem(storageKey + ':open') === '1') setPanel(true, false, false);
    } catch { /* Storage is optional. */ }
    syncHistory();
    // Keep the launcher unpainted until its saved geometry and open state are restored.
    launcher.getBoundingClientRect();
    launcherWrapper.removeAttribute('data-agent-initializing');
    launcherWrapper.style.removeProperty('visibility');
})();
