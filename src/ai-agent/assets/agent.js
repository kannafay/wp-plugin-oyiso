(() => {
    'use strict';

    const config = window.oyisoAgent;
    const chat = document.getElementById('oyiso-agent-chat');
    const history = document.getElementById('oyiso-agent-history');
    if (!config || !chat || !history) return;

    const $ = (selector, root = chat) => root.querySelector(selector);
    const prompt = $('#oyiso-agent-prompt');
    const messages = $('[data-agent-messages]');
    const empty = messages.firstElementChild.cloneNode(true);
    const notice = $('[data-agent-notice]');
    const statuses = { queued: '等待执行', running: '执行中', waiting: '等待确认', completed: '已完成', failed: '执行失败', cancelled: '已取消' };
    const fields = { description: '长描述', short_description: '短描述', seo_title: 'SEO 标题', seo_description: 'SEO 描述', seo_keywords: '目标关键词' };
    const storageKey = `oyiso-agent:${config.user}:${new URL(config.url).pathname}`;
    const changing = new Set();
    let run = null;
    let runId = 0;
    let timer;
    let sending = false;
    let stepping = false;
    let version = '';
    let historyPage = 1;
    let historyPages = 1;

    const node = (tag, className, text) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    };

    async function api(path, options = {}) {
        const response = await fetch(config.url + path, {
            credentials: 'same-origin',
            ...options,
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce, ...options.headers },
        });
        let data;
        try { data = await response.json(); } catch { throw new Error('服务器未返回有效数据，请检查网络或刷新页面。'); }
        if (!response.ok) throw new Error(data.message || `请求失败（HTTP ${response.status}）。`);
        return data;
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

    function showNotice(text, error = false) {
        notice.textContent = text;
        notice.classList.toggle('is-error', error);
        notice.hidden = !text;
    }

    function remember(id) {
        try {
            if (id) localStorage.setItem(storageKey, String(id));
            else localStorage.removeItem(storageKey);
        } catch { /* Private browsing may disable localStorage. */ }
    }

    function controls() {
        const active = run && ['queued', 'running'].includes(run.status);
        const ready = config.enabled && config.supported;
        $('[data-agent-send]').disabled = !ready || sending || active || run?.status === 'cancelled';
        prompt.disabled = !ready || sending || active || run?.status === 'cancelled';
        $('[data-agent-cancel]').hidden = !run || !['queued', 'running', 'waiting'].includes(run.status);
        $('[data-agent-status]').textContent = run ? statuses[run.status] || run.status : '尚未开始';
        $('[data-agent-usage]').textContent = run ? `本次任务 ${run.step} 轮 · 累计 ${Number(run.tokens).toLocaleString()} Token` : '';
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

    function renderChanges(changes) {
        const container = $('[data-agent-changes]');
        container.replaceChildren();
        const visible = changes.filter(change => !['superseded', 'cancelled'].includes(change.status));
        if (!visible.length) return;
        container.append(node('h3', '', '商品修改'));
        for (const change of visible) {
            const section = node('section', 'oyiso-agent-change');
            const heading = node('div', 'oyiso-agent-change-heading');
            heading.append(node('h4', '', `${change.name}（#${change.product_id}）`));
            const state = { pending: '尚未保存', applied: '已保存', reverted: '已撤回' };
            heading.append(node('span', 'description', state[change.status] || change.status));
            section.append(heading);
            for (const [name, after] of Object.entries(change.after)) {
                const before = change.before[name] || '';
                if (before === after) continue;
                const detail = node('details', 'oyiso-agent-field-diff');
                detail.open = true;
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
            if (change.status === 'pending' || change.status === 'applied') {
                const action = change.status === 'pending' ? 'apply' : 'revert';
                const button = node('button', `button ${action === 'apply' ? 'button-primary' : ''}`, action === 'apply' ? '应用修改' : '撤回修改');
                button.type = 'button';
                button.disabled = !config.enabled || ['queued', 'running'].includes(run.status) || (action === 'apply' && run.status === 'cancelled') || changing.has(change.id);
                button.addEventListener('click', () => applyChange(change.id, action));
                section.append(button);
            }
            container.append(section);
        }
    }

    function render(next) {
        if (next.id !== runId) return;
        run = next;
        controls();
        if (next.error) showNotice(next.error, true);
        else if (next.status === 'cancelled') showNotice('任务已取消，后续步骤和待审核修改已停止。可新建对话继续。');
        else showNotice('');
        const signature = JSON.stringify([next.conversation, next.events, next.changes]);
        if (signature === version) return;
        version = signature;
        const nearBottom = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 90;
        messages.replaceChildren();
        for (const entry of next.conversation) {
            const item = node('article', `oyiso-agent-message is-${entry.role}`);
            item.append(node('strong', 'oyiso-agent-message-role', entry.role === 'user' ? '你' : '助手'));
            const content = node('div', 'oyiso-agent-message-content');
            messageText(content, entry.text);
            item.append(content);
            messages.append(item);
        }
        if (!next.conversation.length) messages.append(empty.cloneNode(true));
        if (nearBottom) messages.scrollTop = messages.scrollHeight;
        const events = $('[data-agent-events]');
        events.replaceChildren();
        for (const event of next.events.slice(-15)) {
            const item = node('li', `is-${event.type}`);
            item.append(node('span', '', event.label));
            if (event.detail) {
                const detail = node('small');
                messageText(detail, event.detail);
                item.append(detail);
            }
            events.append(item);
        }
        renderChanges(next.changes);
    }

    function drive(id) {
        if (stepping) return;
        stepping = true;
        api(`runs/${id}/step`, { method: 'POST', body: '{}' })
            .then(data => { if (id === runId) render(data); })
            .catch(error => { if (id === runId) showNotice(error.message, true); })
            .finally(() => { stepping = false; });
    }

    async function poll() {
        clearTimeout(timer);
        const id = runId;
        if (!id) return;
        try {
            const data = await api(`runs/${id}`);
            if (id !== runId) return;
            render(data);
            if (data.status === 'queued' || data.recoverable) drive(id);
            if (['queued', 'running'].includes(data.status)) timer = setTimeout(poll, 2500);
        } catch (error) {
            if (id === runId) {
                showNotice(error.message, true);
                timer = setTimeout(poll, 6000);
            }
        }
    }

    async function send() {
        const message = prompt.value.trim();
        if (!message || $('[data-agent-send]').disabled) return;
        sending = true;
        controls();
        try {
            const next = await api('runs', { method: 'POST', body: JSON.stringify({ message, run_id: runId }) });
            runId = next.id;
            remember(runId);
            prompt.value = '';
            version = '';
            render(next);
            messages.scrollTop = messages.scrollHeight;
            poll();
            loadHistory();
        } catch (error) { showNotice(error.message, true); }
        finally { sending = false; controls(); }
    }

    async function applyChange(proposal, action) {
        if (changing.has(proposal)) return;
        const id = runId;
        changing.add(proposal);
        renderChanges(run.changes);
        try {
            const next = await api(`runs/${id}/changes/${proposal}`, { method: 'POST', body: JSON.stringify({ action }) });
            if (id === runId) { version = ''; render(next); }
            loadHistory();
        } catch (error) { if (id === runId) showNotice(error.message, true); }
        finally { changing.delete(proposal); if (runId === id && run) renderChanges(run.changes); }
    }

    async function openRun(id) {
        clearTimeout(timer);
        runId = id;
        version = '';
        remember(id);
        document.querySelector('.csf-nav a[data-tab-id="ai-agent/agent-chat"]')?.click();
        await poll();
        chat.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
    }

    async function loadHistory() {
        const note = $('[data-agent-history-notice]', history);
        try {
            const data = await api(`runs?page=${historyPage}`);
            const body = $('[data-agent-history-body]', history);
            body.replaceChildren();
            historyPages = Math.max(1, data.pages);
            for (const item of data.items) {
                const row = node('tr');
                row.append(node('td', '', item.title), node('td', '', statuses[item.status] || item.status), node('td', '', item.updated));
                const cell = node('td');
                const button = node('button', 'button button-small', '查看对话');
                button.type = 'button';
                button.addEventListener('click', () => openRun(item.id));
                cell.append(button); row.append(cell); body.append(row);
            }
            if (!data.items.length) {
                const cell = node('td', '', '暂无执行记录。发送第一个任务后，记录会显示在这里。');
                cell.colSpan = 4;
                const row = node('tr'); row.append(cell); body.append(row);
            }
            $('[data-agent-history-page]', history).textContent = `${historyPage} / ${historyPages}`;
            $('[data-agent-history-prev]', history).disabled = historyPage <= 1;
            $('[data-agent-history-next]', history).disabled = historyPage >= historyPages;
            note.textContent = '';
        } catch (error) { note.textContent = error.message; }
    }

    $('[data-agent-send]').addEventListener('click', send);
    prompt.addEventListener('keydown', event => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); send(); }
    });
    prompt.addEventListener('change', event => event.stopPropagation());
    prompt.addEventListener('keypress', event => event.stopPropagation());
    chat.addEventListener('click', event => {
        const example = event.target.closest('[data-agent-example]');
        if (example && !prompt.disabled) { prompt.value = example.dataset.agentExample; prompt.focus(); }
    });
    $('[data-agent-new]').addEventListener('click', () => {
        clearTimeout(timer); runId = 0; run = null; version = ''; remember(0);
        messages.replaceChildren(empty.cloneNode(true));
        $('[data-agent-events]').replaceChildren(node('li', 'description', '发送任务后，这里会显示查询和处理进度。'));
        $('[data-agent-changes]').replaceChildren();
        prompt.value = '';
        controls(); initialNotice(); prompt.focus();
    });
    $('[data-agent-cancel]').addEventListener('click', async event => {
        const id = runId;
        event.currentTarget.disabled = true;
        try { const data = await api(`runs/${id}/cancel`, { method: 'POST', body: '{}' }); if (id === runId) render(data); }
        catch (error) { showNotice(error.message, true); }
        finally { $('[data-agent-cancel]').disabled = false; }
    });
    $('[data-agent-history-refresh]', history).addEventListener('click', loadHistory);
    $('[data-agent-history-prev]', history).addEventListener('click', () => { if (historyPage > 1) { historyPage--; loadHistory(); } });
    $('[data-agent-history-next]', history).addEventListener('click', () => { if (historyPage < historyPages) { historyPage++; loadHistory(); } });

    function initialNotice() {
        if (!config.supported) showNotice('AI Agent 需要 WordPress 7.0 或以上版本。', true);
        else if (!config.enabled) showNotice('请先在「模型配置」中启用 AI Agent，并配置模型连接。');
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
                for (const [name, configured] of Object.entries(state.secrets || { search_key: state.search_configured, keywords_password: state.keywords_configured })) {
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
    controls(); initialNotice(); loadHistory();
    try { const saved = Number(localStorage.getItem(storageKey)); if (Number.isSafeInteger(saved) && saved > 0) { runId = saved; poll(); } } catch { /* Storage is optional. */ }
})();
