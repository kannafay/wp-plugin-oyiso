document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('[data-oyiso-product-table]');

    if (!root) {
        return;
    }

    var searchInput = root.querySelector('[data-role="table-search"]');
    var statusNode = root.querySelector('[data-role="action-status"]');
    var filterEmptyNode = root.querySelector('[data-role="filter-empty"]');
    var rowNodes = Array.prototype.slice.call(root.querySelectorAll('tbody tr[data-product-row]'));
    var columnToggleNodes = Array.prototype.slice.call(root.querySelectorAll('input[data-role="column-toggle"]'));
    var buttonCopy = root.querySelector('[data-action="copy-markdown"]');
    var buttonCsv = root.querySelector('[data-action="export-csv"]');
    var buttonMarkdown = root.querySelector('[data-action="export-markdown"]');
    var dropdowns = Array.prototype.slice.call(root.querySelectorAll('[data-dropdown]'));
    var selectAllCheckbox = root.querySelector('[data-role="select-all"]');
    var rowCheckboxes = Array.prototype.slice.call(root.querySelectorAll('[data-role="select-row"]'));
    var defaultStatusMessage = statusNode ? String(statusNode.textContent || '').trim() : '';
    var statusResetTimer = 0;
    var LOCAL_STORAGE_KEY = 'oyiso_wc_product_table_columns_v1';

    function saveColumnState() {
        var state = {};
        columnToggleNodes.forEach(function (node) {
            var key = node.getAttribute('data-column-key');
            if (key && !isLockedColumn(node)) {
                state[key] = node.checked;
            }
        });
        try {
            localStorage.setItem(LOCAL_STORAGE_KEY, JSON.stringify(state));
        } catch (e) {}
    }

    function loadColumnState() {
        try {
            var stateStr = localStorage.getItem(LOCAL_STORAGE_KEY);
            if (!stateStr) return;
            var state = JSON.parse(stateStr);
            columnToggleNodes.forEach(function (node) {
                var key = node.getAttribute('data-column-key');
                if (key && !isLockedColumn(node) && typeof state[key] !== 'undefined') {
                    node.checked = state[key];
                }
            });
        } catch (e) {}
    }

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

    function getSelectedRows() {
        var checked = rowNodes.filter(function (row) {
            if (row.hidden) return false;
            var cb = row.querySelector('[data-role="select-row"]');
            return cb && cb.checked;
        });
        return checked.length > 0 ? checked : getVisibleRows();
    }

    function getSelectedCount() {
        return rowNodes.filter(function (row) {
            if (row.hidden) return false;
            var cb = row.querySelector('[data-role="select-row"]');
            return cb && cb.checked;
        }).length;
    }

    function syncSelectAll() {
        if (!selectAllCheckbox) return;
        var visible = getVisibleRows();
        var checkedCount = 0;
        visible.forEach(function (row) {
            var cb = row.querySelector('[data-role="select-row"]');
            if (cb && cb.checked) checkedCount++;
        });
        selectAllCheckbox.checked = visible.length > 0 && checkedCount === visible.length;
        selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < visible.length;
    }

    function setRowSelected(row, selected) {
        var cb = row.querySelector('[data-role="select-row"]');
        if (cb) cb.checked = selected;
        row.setAttribute('data-selected', selected ? 'true' : 'false');
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
            return node.checked;
        }).map(function (node) {
            return node.getAttribute('data-column-key') || '';
        });
    }

    function isLockedColumn(node) {
        return node.getAttribute('data-locked') === 'true';
    }

    function syncVisibleColumns() {
        var activeColumnKeys = getActiveColumnKeys();

        Array.prototype.slice.call(root.querySelectorAll('thead th[data-column-key], tbody td[data-column-key]')).forEach(function (node) {
            var columnKey = node.getAttribute('data-column-key') || '';
            node.hidden = activeColumnKeys.indexOf(columnKey) === -1;
        });

        var table = root.querySelector('.opt-table');
        if (table) {
            var minWidth = 40 + (activeColumnKeys.length * 130);
            table.style.minWidth = minWidth + 'px';
        }
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

    function buildMarkdownSelected() {
        var headers = getHeaders();
        var rows = getSelectedRows();
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

    function buildCsvSelected() {
        var headers = getHeaders();
        var rows = getSelectedRows();
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
        var host = window.location && window.location.hostname
            ? String(window.location.hostname).toLowerCase()
            : '';
        var safeHost = host
            .replace(/[^a-z0-9.-]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'site';
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

        return safeHost + '_products-info_' + stamp + '.' + extension;
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

        var markdown = buildMarkdownSelected();

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

        var csv = buildCsvSelected();

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

        var markdown = buildMarkdownSelected();

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
        var isActive = node.checked;

        if (isLockedColumn(node)) {
            node.checked = true;
            setStatus('该字段为固定字段', 'neutral', true);
            return;
        }

        if (!isActive && activeColumnKeys.length === 0) {
            node.checked = true;
            setStatus('至少保留 1 个字段用于查看和导出', 'error', true);
            return;
        }

        syncVisibleColumns();
        saveColumnState();
        setStatus('字段视图已更新，后续复制与导出会自动同步', 'neutral', true);
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            var checked = selectAllCheckbox.checked;
            getVisibleRows().forEach(function (row) {
                setRowSelected(row, checked);
            });
            var count = checked ? getVisibleRows().length : 0;
            if (count > 0) {
                setStatus('已选择 ' + count + ' 条产品', 'neutral', true);
            } else {
                resetStatus();
            }
        });
    }

    rowCheckboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            var row = cb.closest('tr');
            if (row) row.setAttribute('data-selected', cb.checked ? 'true' : 'false');
            syncSelectAll();
            var count = getSelectedCount();
            if (count > 0) {
                setStatus('已选择 ' + count + ' 条产品', 'neutral', true);
            } else {
                resetStatus();
            }
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            applyFilter();
            syncSelectAll();
        });
    }

    columnToggleNodes.forEach(function (node) {
        node.addEventListener('change', function () {
            toggleColumn(node);
        });
    });

    function openDropdown(dropdown) {
        var menu = dropdown.querySelector('[data-dropdown-menu]');
        if (!menu) return;
        
        // Close all other dropdowns first
        dropdowns.forEach(function(d) {
            if (d !== dropdown) closeDropdown(d);
        });

        dropdown.setAttribute('data-open', 'true');
        menu.hidden = false;
    }

    function closeDropdown(dropdown) {
        var menu = dropdown.querySelector('[data-dropdown-menu]');
        if (!menu) return;
        dropdown.setAttribute('data-open', 'false');
        menu.hidden = true;
    }

    function toggleDropdown(dropdown) {
        var menu = dropdown.querySelector('[data-dropdown-menu]');
        if (!menu) return;
        if (menu.hidden) {
            openDropdown(dropdown);
        } else {
            closeDropdown(dropdown);
        }
    }

    dropdowns.forEach(function (dropdown) {
        var trigger = dropdown.querySelector('[data-dropdown-trigger]');
        if (trigger) {
            trigger.addEventListener('click', function (e) {
                e.stopPropagation();
                toggleDropdown(dropdown);
            });
        }
    });

    document.addEventListener('click', function (e) {
        dropdowns.forEach(function (dropdown) {
            if (!dropdown.contains(e.target)) {
                closeDropdown(dropdown);
            }
        });
    });

    if (buttonCopy) {
        buttonCopy.addEventListener('click', copyMarkdown);
    }

    if (buttonCsv) {
        buttonCsv.addEventListener('click', function () {
            dropdowns.forEach(closeDropdown);
            exportCsv();
        });
    }

    if (buttonMarkdown) {
        buttonMarkdown.addEventListener('click', function () {
            dropdowns.forEach(closeDropdown);
            exportMarkdown();
        });
    }

    // 复制规格按钮（事件委托，支持多行）
    root.addEventListener('click', function (e) {
        var copyBtn = e.target.closest('[data-action="copy-specs"]');
        if (!copyBtn) return;

        e.preventDefault();
        e.stopPropagation();

        var details = copyBtn.closest('.oyiso-product-table__specs-details') || copyBtn.closest('.oyiso-product-table__specs-wrapper');
        if (!details) return;

        var chips = details.querySelectorAll('.oyiso-product-table__chip--spec');
        var lines = [];
        chips.forEach(function (chip) {
            var label = chip.querySelector('.oyiso-product-table__spec-label');
            var value = chip.querySelector('.oyiso-product-table__spec-value');
            if (label && value) {
                lines.push(label.textContent.trim() + ': ' + value.textContent.trim());
            } else {
                lines.push(chip.textContent.trim());
            }
        });

        var text = lines.join('\n');

        function onCopied() {
            copyBtn.setAttribute('data-copied', 'true');
            copyBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
            setTimeout(function () {
                copyBtn.removeAttribute('data-copied');
                copyBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
            }, 1500);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(onCopied);
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (ex) {}
            document.body.removeChild(ta);
            onCopied();
        }
    });

    resetStatus();
    loadColumnState();
    syncVisibleColumns();
    applyFilter();
});
