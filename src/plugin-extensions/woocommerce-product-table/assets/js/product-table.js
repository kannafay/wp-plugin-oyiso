document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('[data-oyiso-product-table]');

    if (!root) {
        return;
    }

    var searchInput = root.querySelector('[data-role="table-search"]');
    var statusNode = root.querySelector('[data-role="action-status"]');
    var filterEmptyNode = root.querySelector('[data-role="filter-empty"]');
    var rowNodes = Array.prototype.slice.call(root.querySelectorAll('tbody tr[data-product-row]'));
    var columnToggleNodes = Array.prototype.slice.call(root.querySelectorAll('[data-role="column-toggle"]'));
    var buttonCopy = root.querySelector('[data-action="copy-markdown"]');
    var buttonCsv = root.querySelector('[data-action="export-csv"]');
    var buttonMarkdown = root.querySelector('[data-action="export-markdown"]');
    var defaultStatusMessage = statusNode ? String(statusNode.textContent || '').trim() : '';
    var statusResetTimer = 0;

    function resetStatus() {
        if (!statusNode) {
            return;
        }

        statusNode.textContent = defaultStatusMessage;
        statusNode.setAttribute('data-tone', 'neutral');
    }

    function setStatus(message, tone, shouldReset) {
        if (!statusNode) {
            return;
        }

        if (statusResetTimer) {
            window.clearTimeout(statusResetTimer);
            statusResetTimer = 0;
        }

        statusNode.textContent = message;
        statusNode.setAttribute('data-tone', tone || 'neutral');

        if (shouldReset && defaultStatusMessage && message !== defaultStatusMessage) {
            statusResetTimer = window.setTimeout(resetStatus, 2600);
        }
    }

    function getHeaders() {
        return Array.prototype.slice.call(root.querySelectorAll('thead th[data-column-key]')).filter(function (node) {
            return !node.hidden;
        }).map(function (node) {
            return {
                key: node.getAttribute('data-column-key') || '',
                label: node.getAttribute('data-export-label') || (node.textContent || '').trim()
            };
        });
    }

    function getVisibleRows() {
        return rowNodes.filter(function (row) {
            return !row.hidden;
        });
    }

    function findCell(row, columnKey) {
        return row.querySelector('td[data-column-key="' + columnKey + '"]');
    }

    function getCellValue(row, columnKey, mode) {
        var cell = findCell(row, columnKey);

        if (!cell) {
            return '';
        }

        return mode === 'markdown'
            ? (cell.getAttribute('data-export-markdown') || '')
            : (cell.getAttribute('data-export-csv') || '');
    }

    function getActiveColumnKeys() {
        return columnToggleNodes.filter(function (node) {
            return node.getAttribute('data-active') !== 'false';
        }).map(function (node) {
            return node.getAttribute('data-column-key') || '';
        });
    }

    function isLockedColumn(node) {
        return node.getAttribute('data-locked') === 'true';
    }

    function setColumnToggleState(node, isActive) {
        node.setAttribute('data-active', isActive ? 'true' : 'false');
        node.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    }

    function syncVisibleColumns() {
        var activeColumnKeys = getActiveColumnKeys();

        Array.prototype.slice.call(root.querySelectorAll('thead th[data-column-key], tbody td[data-column-key]')).forEach(function (node) {
            var columnKey = node.getAttribute('data-column-key') || '';
            node.hidden = activeColumnKeys.indexOf(columnKey) === -1;
        });
    }

    function escapeMarkdown(text) {
        return String(text || '')
            .replace(/\|/g, '\\|')
            .replace(/\r?\n/g, '<br>');
    }

    function buildMarkdown() {
        var headers = getHeaders();
        var rows = getVisibleRows();
        var lines = [];

        lines.push('| ' + headers.map(function (header) {
            return escapeMarkdown(header.label);
        }).join(' | ') + ' |');
        lines.push('| ' + headers.map(function () {
            return '---';
        }).join(' | ') + ' |');

        rows.forEach(function (row) {
            lines.push('| ' + headers.map(function (header) {
                return escapeMarkdown(getCellValue(row, header.key, 'markdown'));
            }).join(' | ') + ' |');
        });

        return lines.join('\n');
    }

    function escapeCsv(text) {
        var value = String(text || '');

        if (/[",\n]/.test(value)) {
            return '"' + value.replace(/"/g, '""') + '"';
        }

        return value;
    }

    function buildCsv() {
        var headers = getHeaders();
        var rows = getVisibleRows();
        var lines = [];

        lines.push(headers.map(function (header) {
            return escapeCsv(header.label);
        }).join(','));

        rows.forEach(function (row) {
            lines.push(headers.map(function (header) {
                return escapeCsv(getCellValue(row, header.key, 'csv'));
            }).join(','));
        });

        return lines.join('\r\n');
    }

    function buildFilename(extension) {
        var slug = root.getAttribute('data-table-slug') || 'product-table';
        var now = new Date();
        var stamp = [
            now.getFullYear(),
            String(now.getMonth() + 1).padStart(2, '0'),
            String(now.getDate()).padStart(2, '0')
        ].join('') + '-' + [
            String(now.getHours()).padStart(2, '0'),
            String(now.getMinutes()).padStart(2, '0'),
            String(now.getSeconds()).padStart(2, '0')
        ].join('');

        return slug + '-' + stamp + '.' + extension;
    }

    function downloadFile(filename, content, mimeType) {
        var blob = new Blob([content], { type: mimeType });
        var url = URL.createObjectURL(blob);
        var anchor = document.createElement('a');

        anchor.href = url;
        anchor.download = filename;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(url);
    }

    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        var success = document.execCommand('copy');
        textarea.remove();

        if (!success) {
            throw new Error('copy_failed');
        }
    }

    function copyMarkdown() {
        if (getVisibleRows().length === 0) {
            setStatus('当前筛选结果为空，无法复制 Markdown 表格', 'error', true);
            return;
        }

        var markdown = buildMarkdown();

        if (!markdown.trim()) {
            setStatus('当前暂无可复制的数据', 'error', true);
            return;
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(markdown).then(function () {
                setStatus('Markdown 表格已复制到剪贴板', 'success', true);
            }).catch(function () {
                try {
                    fallbackCopy(markdown);
                    setStatus('Markdown 表格已复制到剪贴板', 'success', true);
                } catch (error) {
                    setStatus('复制失败，请稍后重试', 'error', true);
                }
            });

            return;
        }

        try {
            fallbackCopy(markdown);
            setStatus('Markdown 表格已复制到剪贴板', 'success', true);
        } catch (error) {
            setStatus('复制失败，请稍后重试', 'error', true);
        }
    }

    function exportCsv() {
        if (getVisibleRows().length === 0) {
            setStatus('当前筛选结果为空，无法导出 CSV 文件', 'error', true);
            return;
        }

        var csv = buildCsv();

        if (!csv.trim()) {
            setStatus('当前暂无可导出的 CSV 数据', 'error', true);
            return;
        }

        downloadFile(buildFilename('csv'), '\uFEFF' + csv, 'text/csv;charset=utf-8');
        setStatus('CSV 文件已开始下载', 'success', true);
    }

    function exportMarkdown() {
        if (getVisibleRows().length === 0) {
            setStatus('当前筛选结果为空，无法导出 Markdown 文件', 'error', true);
            return;
        }

        var markdown = buildMarkdown();

        if (!markdown.trim()) {
            setStatus('当前暂无可导出的 Markdown 数据', 'error', true);
            return;
        }

        downloadFile(buildFilename('md'), markdown, 'text/markdown;charset=utf-8');
        setStatus('Markdown 文件已开始下载', 'success', true);
    }

    function applyFilter() {
        var keyword = searchInput ? String(searchInput.value || '').trim().toLowerCase() : '';
        var visibleCount = 0;

        rowNodes.forEach(function (row) {
            var searchText = (row.getAttribute('data-search') || '').toLowerCase();
            var matched = keyword === '' || searchText.indexOf(keyword) !== -1;

            row.hidden = !matched;

            if (matched) {
                visibleCount += 1;
            }
        });

        if (filterEmptyNode) {
            filterEmptyNode.hidden = visibleCount !== 0;
        }
    }

    function toggleColumn(node) {
        var activeColumnKeys = getActiveColumnKeys();
        var isActive = node.getAttribute('data-active') !== 'false';

        if (isLockedColumn(node)) {
            setStatus('产品名称、产品链接和产品规格会固定显示', 'neutral', true);
            return;
        }

        if (isActive && activeColumnKeys.length <= 1) {
            setStatus('至少保留 1 个字段用于查看和导出', 'error', true);
            return;
        }

        setColumnToggleState(node, !isActive);
        syncVisibleColumns();
        setStatus('字段视图已更新，后续复制与导出会自动同步', 'neutral', true);
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilter);
    }

    columnToggleNodes.forEach(function (node) {
        node.addEventListener('click', function () {
            toggleColumn(node);
        });
    });

    if (buttonCopy) {
        buttonCopy.addEventListener('click', copyMarkdown);
    }

    if (buttonCsv) {
        buttonCsv.addEventListener('click', exportCsv);
    }

    if (buttonMarkdown) {
        buttonMarkdown.addEventListener('click', exportMarkdown);
    }

    resetStatus();
    syncVisibleColumns();
    applyFilter();
});
