(() => {
    'use strict';
    window.oyisoAgentHistory = ({ api, openRun, current, updated, removed, unavailable, canDelete, deleting }) => {
        const panel = document.getElementById('oyiso-agent-panel');
        const dialog = document.querySelector('[data-agent-delete-dialog]');
        const find = (root, selector) => root.querySelector(selector);
        const node = (tag, className, text) => { const el = document.createElement(tag); if (className) el.className = className; if (text !== undefined) el.textContent = text; return el; };
        const statuses = { queued: '等待执行', running: '执行中', waiting: '等待确认', completed: '已完成', failed: '执行失败', cancelled: '已取消' };
        const lists = [...document.querySelectorAll('[data-agent-runs]')].map(root => ({ root, inline: root.dataset.agentRuns === 'panel', page: 1, pages: 1, items: [], selected: new Set(), selecting: false, loading: false, loaded: false }));
        const trash = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></svg>';
        const stop = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>';
        let pending = [], returnFocus, busy = false;
        const unavailableIds = new Set();

        function icon(label, svg) {
            const button = node('button', 'oyiso-agent-icon-button'); button.type = 'button'; button.title = label; button.setAttribute('aria-label', label);
            button.innerHTML = svg; // Static icon markup only.
            return button;
        }
        function syncSelection(list) {
            list.root.toggleAttribute('data-agent-selecting', list.selecting);
            find(list.root, '[data-agent-history-selection]').hidden = !list.selecting;
            const manage = find(list.root, '[data-agent-history-manage]');
            manage.setAttribute('aria-pressed', String(list.selecting)); manage.setAttribute('aria-label', list.selecting ? '取消选择' : '选择对话'); manage.title = list.selecting ? '取消选择' : '选择对话';
            if (!list.inline) manage.textContent = list.selecting ? '取消选择' : '选择对话';
            find(list.root, '[data-agent-history-count]').textContent = `已选 ${list.selected.size}`;
            find(list.root, '[data-agent-history-delete]').disabled = !list.selected.size || busy;
            const all = find(list.root, '[data-agent-history-all]');
            const count = list.items.filter(item => list.selected.has(item.id)).length;
            all.checked = !!list.items.length && count === list.items.length;
            all.indeterminate = count > 0 && count < list.items.length;
            all.disabled = !list.items.length || busy;
            list.root.querySelectorAll('[data-agent-history-select-column]').forEach(cell => { cell.hidden = !list.selecting; });
            list.root.querySelectorAll('[data-agent-history-pick]').forEach(input => { input.hidden = !list.selecting; input.checked = list.selected.has(Number(input.dataset.agentHistoryPick)); input.disabled = busy; });
        }
        function renderList(list) {
            const body = find(list.root, '[data-agent-history-body]'); body.replaceChildren();
            body.setAttribute('aria-busy', String(list.loading));
            for (const item of list.items) {
                const row = node(list.inline ? 'li' : 'tr', list.inline ? 'oyiso-agent-history-item' : '');
                const pick = node('input'); pick.type = 'checkbox'; pick.dataset.agentHistoryPick = item.id; pick.setAttribute('aria-label', `选择对话：${item.title}`);
                pick.addEventListener('change', () => { if (pick.checked) list.selected.add(item.id); else list.selected.delete(item.id); syncSelection(list); });
                if (list.inline) row.append(pick);
                else { const cell = node('td'); cell.dataset.agentHistorySelectColumn = ''; cell.append(pick); row.append(cell); }
                const button = node('button', list.inline ? 'oyiso-agent-history-open' : 'button button-small', list.inline ? undefined : '查看对话'); button.type = 'button'; button.dataset.agentRun = item.id;
                if (list.inline) {
                    button.append(node('span', 'oyiso-agent-history-title', item.title), node('span', 'oyiso-agent-history-meta', `${statuses[item.status] || item.status} · ${item.updated.slice(5, 16)}`));
                    button.title = `${item.title}\n${item.updated}`;
                } else row.append(node('td', '', item.title), node('td', '', statuses[item.status] || item.status), node('td', '', item.updated));
                if (item.id === current()) button.setAttribute('aria-current', 'true');
                button.addEventListener('click', () => openRun(item.id));
                const actions = list.inline ? row : node('td'); actions.append(button);
                const tools = node('div', 'oyiso-agent-history-row-actions');
                if (['queued', 'running', 'waiting'].includes(item.status)) {
                    const cancel = icon('取消任务', stop);
                    cancel.addEventListener('click', async () => {
                        cancel.disabled = true;
                        try { updated(await api(`runs/${item.id}/cancel`, { method: 'POST', body: '{}' })); reload(true); }
                        catch (error) {
                            if (error.status === 404 && error.code === 'agent_task_missing') unavailable(item.id);
                            else {
                                find(list.root, '[data-agent-history-notice]').textContent = error.message; cancel.disabled = false;
                                if (error.status === 409) reload(true);
                            }
                        }
                    });
                    tools.append(cancel);
                }
                const remove = icon(`删除对话：${item.title}`, trash); remove.dataset.agentHistoryRemove = item.id;
                remove.addEventListener('click', () => confirmDelete([item.id], remove)); tools.append(remove);
                actions.append(tools);
                if (!list.inline) row.append(actions);
                body.append(row);
            }
            if (!list.items.length && list.loaded && !list.loading) {
                const cell = node(list.inline ? 'li' : 'td', 'oyiso-agent-history-empty', '暂无对话记录');
                if (list.inline) body.append(cell);
                else { cell.colSpan = 5; const row = node('tr'); row.append(cell); body.append(row); }
            }
            find(list.root, '[data-agent-history-page]').textContent = `${list.page} / ${list.pages}`;
            find(list.root, '[data-agent-history-prev]').disabled = list.page <= 1;
            find(list.root, '[data-agent-history-next]').disabled = list.page >= list.pages;
            syncSelection(list);
        }
        async function load(list) {
            if (list.loading || (list.inline && (list.root.hidden || panel.hidden))) return;
            list.loading = true; list.loaded = false;
            const note = find(list.root, '[data-agent-history-notice]');
            const body = find(list.root, '[data-agent-history-body]'); body.setAttribute('aria-busy', 'true');
            const refresh = find(list.root, '[data-agent-history-refresh]'); refresh.setAttribute('aria-busy', 'true');
            if (!list.items.length) { body.replaceChildren(); note.textContent = '正在读取记录…'; }
            else note.textContent = '';
            try {
                let data;
                while (true) {
                    data = await api(`runs?page=${list.page}`);
                    list.pages = Number.isSafeInteger(data.pages) ? Math.max(1, data.pages) : 1;
                    if (list.page <= list.pages) break;
                    list.page = list.pages;
                }
                list.items = data.items.filter(item => !unavailableIds.has(item.id));
                list.loaded = true; note.textContent = '';
            } catch (error) { note.textContent = error.message; }
            finally { list.loading = false; refresh.setAttribute('aria-busy', 'false'); renderList(list); if (list.again) { list.again = false; load(list); } }
        }
        function confirmDelete(ids, trigger) {
            if (!ids.length || busy || !canDelete()) return;
            pending = [...ids]; returnFocus = trigger;
            find(dialog, '[data-agent-delete-description]').textContent = `确定删除${ids.length === 1 ? '这条' : `所选 ${ids.length} 条`}对话及其附件？附件删除后无法恢复，正在执行的任务也会停止。`;
            find(dialog, '[data-agent-delete-error]').hidden = true;
            window.oyisoModalMotion.show(dialog);
            find(dialog, '[data-agent-delete-cancel]').focus();
        }
        function closeDialog() {
            if (busy || !dialog.open || dialog.classList.contains('is-closing')) return;
            pending = []; const trigger = returnFocus;
            window.oyisoModalMotion.hide(dialog, () => { if (trigger?.isConnected) trigger.focus({ preventScroll: true }); });
        }
        find(dialog, '[data-agent-delete-cancel]').addEventListener('click', closeDialog);
        find(dialog, '[data-agent-delete-close]').addEventListener('click', closeDialog);
        dialog.addEventListener('cancel', event => { event.preventDefault(); closeDialog(); });
        dialog.addEventListener('keydown', event => { if (event.key === 'Escape' || event.key === 'Tab') event.stopPropagation(); });
        dialog.addEventListener('click', event => { if (event.target === dialog) closeDialog(); });
        find(dialog, '[data-agent-delete-confirm]').addEventListener('click', async () => {
            if (busy || !pending.length || !canDelete()) return;
            busy = true; deleting(true);
            const submit = find(dialog, '[data-agent-delete-confirm]'); const cancel = find(dialog, '[data-agent-delete-cancel]'); const dismiss = find(dialog, '[data-agent-delete-close]');
            submit.disabled = cancel.disabled = dismiss.disabled = true;
            try {
                const result = await api('runs', { method: 'DELETE', body: JSON.stringify({ ids: pending }) });
                result.deleted.forEach(id => unavailableIds.add(id));
                for (const list of lists) {
                    result.deleted.forEach(id => list.selected.delete(id));
                    list.items = list.items.filter(item => !result.deleted.includes(item.id)); renderList(list);
                }
                removed(result.deleted); busy = false; closeDialog(); reload(true);
            } catch (error) { const note = find(dialog, '[data-agent-delete-error]'); note.textContent = error.message; note.hidden = false; }
            finally { busy = false; submit.disabled = cancel.disabled = dismiss.disabled = false; deleting(false); lists.forEach(syncSelection); }
        });
        for (const list of lists) {
            for (const event of ['change', 'keypress']) list.root.addEventListener(event, event => event.stopPropagation());
            find(list.root, '[data-agent-history-refresh]').addEventListener('click', () => load(list));
            find(list.root, '[data-agent-history-prev]').addEventListener('click', () => { if (!list.loading && list.page > 1) { list.page--; load(list); } });
            find(list.root, '[data-agent-history-next]').addEventListener('click', () => { if (!list.loading && list.page < list.pages) { list.page++; load(list); } });
            find(list.root, '[data-agent-history-manage]').addEventListener('click', () => { list.selecting = !list.selecting; if (!list.selecting) list.selected.clear(); syncSelection(list); });
            find(list.root, '[data-agent-history-all]').addEventListener('change', event => { list.items.forEach(item => event.target.checked ? list.selected.add(item.id) : list.selected.delete(item.id)); syncSelection(list); });
            find(list.root, '[data-agent-history-delete]').addEventListener('click', event => confirmDelete([...list.selected], event.currentTarget));
        }
        function reload(force = false) { lists.forEach(list => { if (force && list.loading) list.again = true; else load(list); }); }
        function forget(id) {
            unavailableIds.add(id);
            for (const list of lists) {
                list.selected.delete(id);
                list.items = list.items.filter(item => item.id !== id); renderList(list);
            }
            reload(true);
        }
        return { reload, forget, reloadPanel: () => { const list = lists.find(item => item.inline); if (list) load(list); }, setCurrent: id => lists.forEach(list => list.root.querySelectorAll('[data-agent-run]').forEach(button => { if (Number(button.dataset.agentRun) === id) button.setAttribute('aria-current', 'true'); else button.removeAttribute('aria-current'); })) };
    };
})();
