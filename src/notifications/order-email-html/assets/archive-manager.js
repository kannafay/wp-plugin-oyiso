(function ($) {
    'use strict';

    $(function () {
        var config = window.oyisoOrderEmailArchiveManager || {};
        var labels = config.labels || {};
        var $openButton = $('#oyiso-order-email-file-manager');
        var $modal = $('#oyiso-order-email-archive-modal');

        if (!$openButton.length || !$modal.length) {
            return;
        }

        var $dialog = $modal.find('.oyiso-archive-dialog');
        var $fullscreen = $('#oyiso-archive-fullscreen');
        var $list = $('#oyiso-archive-list');
        var $refresh = $('#oyiso-archive-refresh');
        var $recordMeta = $('#oyiso-archive-record-meta');
        var $message = $('#oyiso-archive-preview-message');
        var $messageText = $('#oyiso-archive-preview-message-text');
        var $previewSpinner = $message.find('.oyiso-archive-preview-spinner');
        var $imagePanel = $('#oyiso-archive-image-preview');
        var $image = $imagePanel.find('img');
        var $htmlFrame = $('#oyiso-archive-html-preview');
        var $imageTab = $('#oyiso-archive-image-tab');
        var $htmlTab = $('#oyiso-archive-html-tab');
        var $copyImage = $('#oyiso-archive-copy-image');
        var $downloadImage = $('#oyiso-archive-download-image');
        var $cleanup = $('#oyiso-archive-cleanup');
        var $clear = $('#oyiso-archive-clear');
        var $cleanupStatus = $('#oyiso-archive-cleanup-status');
        var $retention = $('[name="oyiso[woo_new_order_email_file_retention]"]');
        var records = [];
        var activeRecord = null;
        var activePreview = '';
        var requestSequence = 0;
        var htmlCache = {};
        var lastFocused = null;
        var savedRetention = String(config.savedRetention || '24');

        function getAjaxAction(settings) {
            var data = settings && settings.data ? settings.data : '';

            if (data && typeof data === 'object') {
                return data.action || '';
            }

            if (typeof data !== 'string') {
                return '';
            }

            var match = data.match(/(?:^|&)action=([^&]*)/);
            return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
        }

        function getResponseMessage(response, fallback) {
            if (response && response.data && response.data.message) {
                return response.data.message;
            }

            return fallback;
        }

        function setCleanupStatus(text, type) {
            $cleanupStatus
                .removeClass('is-error is-success')
                .addClass(type ? 'is-' + type : '')
                .text(text || '');
        }

        function setPreviewMessage(text, isLoading) {
            $imagePanel.prop('hidden', true);
            $htmlFrame.prop('hidden', true);
            $messageText.text(text || '');
            $previewSpinner.prop('hidden', !isLoading);
            $message.prop('hidden', false);
        }

        function hidePreviewMessage() {
            $previewSpinner.prop('hidden', true);
            $message.prop('hidden', true);
        }

        function resetPreview() {
            requestSequence += 1;
            activeRecord = null;
            activePreview = '';
            $image.attr('src', '');
            $htmlFrame.attr('srcdoc', '');
            $recordMeta.text('请选择一条归档记录');
            $imageTab.prop('disabled', true).attr('aria-selected', 'false');
            $htmlTab.prop('disabled', true).attr('aria-selected', 'false');
            setScreenshotActionsEnabled(false);
            setPreviewMessage('请选择左侧订单查看文件');
        }

        function getImageUrl(filename) {
            return config.ajaxUrl + '?' + $.param({
                action: 'oyiso_get_order_email_archive_image',
                nonce: config.nonce,
                file: filename
            });
        }

        function getActiveImage() {
            if (!activeRecord || !activeRecord.images || !activeRecord.images.length) {
                return null;
            }

            return activeRecord.images[0];
        }

        function setScreenshotActionsEnabled(enabled) {
            $copyImage.prop('disabled', !enabled);
            $downloadImage.prop('disabled', !enabled);
        }

        function setFullscreen(enabled) {
            $modal.toggleClass('is-fullscreen', enabled);
            $fullscreen
                .attr('aria-pressed', enabled ? 'true' : 'false')
                .attr('aria-label', enabled ? '取消全屏' : '全屏')
                .attr('title', enabled ? '取消全屏' : '全屏');
            $fullscreen.find('.dashicons')
                .toggleClass('dashicons-editor-expand', !enabled)
                .toggleClass('dashicons-editor-contract', enabled);
        }

        function showImagePreview() {
            if (!activeRecord || !activeRecord.images || !activeRecord.images.length) {
                return;
            }

            activePreview = 'image';
            setScreenshotActionsEnabled(true);
            var currentSequence = ++requestSequence;
            $imageTab.attr('aria-selected', 'true');
            $htmlTab.attr('aria-selected', 'false');
            $htmlFrame.prop('hidden', true);
            setPreviewMessage(labels.loading || '正在加载预览…', true);
            $image
                .off('.oyisoArchive')
                .one('load.oyisoArchive', function () {
                    if (currentSequence !== requestSequence) {
                        return;
                    }

                    hidePreviewMessage();
                    $imagePanel.prop('hidden', false);
                })
                .one('error.oyisoArchive', function () {
                    if (currentSequence === requestSequence) {
                        setPreviewMessage(labels.previewError || '无法加载文件预览。');
                    }
                })
                .attr('src', getImageUrl(activeRecord.images[0].filename));
        }

        function showHtmlPreview() {
            if (!activeRecord || !activeRecord.html) {
                return;
            }

            activePreview = 'html';
            setScreenshotActionsEnabled(false);
            var currentSequence = ++requestSequence;
            var filename = activeRecord.html.filename;

            $imageTab.attr('aria-selected', 'false');
            $htmlTab.attr('aria-selected', 'true');
            $imagePanel.prop('hidden', true);
            $htmlFrame.prop('hidden', true);
            setPreviewMessage(labels.loading || '正在加载预览…', true);

            if (Object.prototype.hasOwnProperty.call(htmlCache, filename)) {
                $htmlFrame.attr('srcdoc', htmlCache[filename]).prop('hidden', false);
                hidePreviewMessage();
                return;
            }

            $.post(config.ajaxUrl, {
                action: 'oyiso_get_order_email_archive_html',
                nonce: config.nonce,
                file: filename
            }).done(function (response) {
                if (
                    currentSequence !== requestSequence
                    || !response
                    || response.success !== true
                    || !response.data
                    || typeof response.data.html !== 'string'
                ) {
                    if (currentSequence === requestSequence) {
                        setPreviewMessage(getResponseMessage(response, labels.previewError));
                    }
                    return;
                }

                htmlCache[filename] = response.data.html;
                $htmlFrame.attr('srcdoc', response.data.html).prop('hidden', false);
                hidePreviewMessage();
            }).fail(function (xhr) {
                if (currentSequence === requestSequence) {
                    setPreviewMessage(
                        getResponseMessage(xhr.responseJSON, labels.previewError || '无法加载文件预览。')
                    );
                }
            });
        }

        function selectRecord(record, preferredPreview) {
            activeRecord = record;
            $list.find('.oyiso-archive-record')
                .removeClass('is-active')
                .find('.oyiso-archive-record-select')
                .attr('aria-current', 'false');
            $list.find('.oyiso-archive-record').filter(function () {
                return $(this).attr('data-record-id') === record.id;
            }).addClass('is-active')
                .find('.oyiso-archive-record-select')
                .attr('aria-current', 'true');

            $recordMeta.text('#' + record.orderNumber + ' · ' + record.createdAt);
            $imageTab.prop('disabled', !record.images || !record.images.length);
            $htmlTab.prop('disabled', !record.html);

            if (preferredPreview === 'html' && record.html) {
                showHtmlPreview();
                return;
            }

            if (record.images && record.images.length) {
                showImagePreview();
                return;
            }

            if (record.html) {
                showHtmlPreview();
                return;
            }

            setPreviewMessage(labels.previewError || '无法加载文件预览。');
        }

        function createBadge(text) {
            return $('<span>', {
                class: 'oyiso-archive-badge',
                text: text
            });
        }

        function deleteRecord(record, $button) {
            var confirmText = labels.confirmDelete
                || '确定删除订单 #%s 的邮件HTML和全部截图吗？此操作无法恢复。';

            if (!window.confirm(confirmText.replace('%s', record.orderNumber))) {
                return;
            }

            var preferredId = activeRecord && activeRecord.id !== record.id
                ? activeRecord.id
                : '';

            $button.prop('disabled', true);
            setCleanupStatus(labels.deleting || '正在删除订单文件…');

            $.post(config.ajaxUrl, {
                action: 'oyiso_delete_order_email_archive',
                nonce: config.nonce,
                record: record.id
            }).done(function (response) {
                if (!response || response.success !== true) {
                    setCleanupStatus(
                        getResponseMessage(response, labels.deleteError || '删除失败，请查看WooCommerce日志。'),
                        'error'
                    );
                    return;
                }

                if (record.html) {
                    delete htmlCache[record.html.filename];
                }

                if (activeRecord && activeRecord.id === record.id) {
                    resetPreview();
                }

                setCleanupStatus(response.data.message || '订单文件已删除。', 'success');
                loadRecords(preferredId);
            }).fail(function (xhr) {
                setCleanupStatus(
                    getResponseMessage(xhr.responseJSON, labels.deleteError || '删除失败，请查看WooCommerce日志。'),
                    'error'
                );
            }).always(function () {
                $button.prop('disabled', false);
            });
        }

        function renderRecords(preferredId) {
            $list.empty();

            if (!records.length) {
                $list.append($('<p>', {
                    class: 'oyiso-archive-list-state',
                    text: labels.empty || '当前站点还没有邮件归档文件。'
                }));
                resetPreview();
                return;
            }

            records.forEach(function (record) {
                var $formats = $('<span>', { class: 'oyiso-archive-record-formats' });

                if (record.html) {
                    $formats.append(createBadge('HTML'));
                }

                (record.images || []).forEach(function (item) {
                    $formats.append(createBadge(item.format || 'IMG'));
                });

                var $record = $('<div>', {
                    class: 'oyiso-archive-record',
                    'data-record-id': record.id
                });
                var $select = $('<button>', {
                    type: 'button',
                    class: 'oyiso-archive-record-select',
                    'aria-current': 'false'
                });
                var $top = $('<span>', { class: 'oyiso-archive-record-top' });
                var $bottom = $('<span>', { class: 'oyiso-archive-record-bottom' });
                var $delete = $('<button>', {
                    type: 'button',
                    class: 'oyiso-archive-record-delete',
                    title: '删除该订单文件',
                    'aria-label': '删除订单 #' + record.orderNumber + ' 的归档文件'
                }).append($('<span>', {
                    class: 'dashicons dashicons-trash',
                    'aria-hidden': 'true'
                }));

                $top.append($('<span>', {
                    class: 'oyiso-archive-record-order',
                    text: '#' + record.orderNumber
                }));
                $bottom.append($('<span>', {
                    class: 'oyiso-archive-record-date',
                    text: record.createdAt
                }));
                $bottom.append($formats);
                $select.append($top, $bottom);
                $select.on('click', function () {
                    selectRecord(record, activePreview);
                });
                $delete.on('click', function (event) {
                    event.stopPropagation();
                    deleteRecord(record, $delete);
                });
                $record.append($select, $delete);
                $list.append($record);
            });

            var selected = records.find(function (record) {
                return record.id === preferredId;
            }) || records[0];

            selectRecord(selected, activePreview);
        }

        function loadRecords(preferredId) {
            $refresh.prop('disabled', true);
            $list.empty().append($('<p>', {
                class: 'oyiso-archive-list-state',
                text: labels.listLoading || '正在读取归档文件…'
            }));

            $.post(config.ajaxUrl, {
                action: 'oyiso_list_order_email_archives',
                nonce: config.nonce
            }).done(function (response) {
                if (!response || response.success !== true || !response.data) {
                    records = [];
                    resetPreview();
                    $list.html('').append($('<p>', {
                        class: 'oyiso-archive-list-state',
                        text: getResponseMessage(response, labels.listError || '无法读取归档文件。')
                    }));
                    return;
                }

                records = Array.isArray(response.data.records) ? response.data.records : [];
                renderRecords(preferredId);
            }).fail(function (xhr) {
                records = [];
                resetPreview();
                $list.html('').append($('<p>', {
                    class: 'oyiso-archive-list-state',
                    text: getResponseMessage(xhr.responseJSON, labels.listError || '无法读取归档文件。')
                }));
            }).always(function () {
                $refresh.prop('disabled', false);
            });
        }

        function refreshCleanupButton() {
            var isPermanent = savedRetention === '0';

            $cleanup.prop('disabled', isPermanent);
            if (isPermanent) {
                setCleanupStatus(labels.disabled || '永久保留模式下无需清理。');
            } else if ($cleanupStatus.text() === (labels.disabled || '永久保留模式下无需清理。')) {
                setCleanupStatus('');
            }
        }

        function openModal() {
            lastFocused = document.activeElement;
            $modal.prop('hidden', false).attr('aria-hidden', 'false');
            $('body').addClass('oyiso-archive-modal-open');
            setCleanupStatus('');
            refreshCleanupButton();
            loadRecords(activeRecord ? activeRecord.id : '');
            window.setTimeout(function () {
                $dialog.trigger('focus');
            }, 0);
        }

        function closeModal() {
            requestSequence += 1;
            $image.attr('src', '');
            $htmlFrame.attr('srcdoc', '');
            setFullscreen(false);
            $modal.prop('hidden', true).attr('aria-hidden', 'true');
            $('body').removeClass('oyiso-archive-modal-open');

            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        }

        function trapFocus(event) {
            if (event.key !== 'Tab' || $modal.prop('hidden')) {
                return;
            }

            var focusable = $dialog.find(
                'button:not(:disabled), [href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])'
            ).filter(':visible').toArray();

            if (!focusable.length) {
                event.preventDefault();
                $dialog.trigger('focus');
                return;
            }

            var first = focusable[0];
            var last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        $openButton.on('click', openModal);
        $modal.on('click', '[data-oyiso-archive-close]', closeModal);
        $refresh.on('click', function () {
            loadRecords(activeRecord ? activeRecord.id : '');
        });
        $imageTab.on('click', showImagePreview);
        $htmlTab.on('click', showHtmlPreview);
        $fullscreen.on('click', function () {
            setFullscreen(!$modal.hasClass('is-fullscreen'));
        });

        $downloadImage.on('click', function () {
            var image = getActiveImage();

            if (!image || activePreview !== 'image') {
                return;
            }

            var link = document.createElement('a');
            link.href = getImageUrl(image.filename);
            link.download = image.filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
        });

        function convertImageBlobToPng(blob) {
            if ('image/png' === blob.type) {
                return Promise.resolve(blob);
            }

            return new Promise(function (resolve, reject) {
                var objectUrl = URL.createObjectURL(blob);
                var source = new Image();

                source.onload = function () {
                    var canvas = document.createElement('canvas');
                    var context = canvas.getContext('2d');

                    URL.revokeObjectURL(objectUrl);
                    canvas.width = source.naturalWidth;
                    canvas.height = source.naturalHeight;

                    if (!context) {
                        reject(new Error('Canvas unavailable'));
                        return;
                    }

                    context.drawImage(source, 0, 0);
                    canvas.toBlob(function (pngBlob) {
                        if (pngBlob) {
                            resolve(pngBlob);
                            return;
                        }

                        reject(new Error('PNG conversion failed'));
                    }, 'image/png');
                };
                source.onerror = function () {
                    URL.revokeObjectURL(objectUrl);
                    reject(new Error('Image decode failed'));
                };
                source.src = objectUrl;
            });
        }

        function copyPngWithLegacyCommand(pngBlob) {
            return new Promise(function (resolve, reject) {
                var reader = new FileReader();

                reader.onload = function () {
                    var dataUrl = String(reader.result || '');
                    var container = document.createElement('div');
                    var image = document.createElement('img');
                    var selection = window.getSelection();
                    var range = document.createRange();

                    container.contentEditable = 'true';
                    container.setAttribute('aria-hidden', 'true');
                    container.style.position = 'fixed';
                    container.style.left = '-10000px';
                    container.style.top = '0';
                    image.src = dataUrl;
                    container.appendChild(image);
                    document.body.appendChild(container);
                    range.selectNodeContents(container);

                    if (selection) {
                        selection.removeAllRanges();
                        selection.addRange(range);
                    }

                    var copied = false;

                    try {
                        copied = document.execCommand('copy');
                    } finally {
                        if (selection) {
                            selection.removeAllRanges();
                        }
                        container.remove();
                    }

                    if (copied) {
                        resolve();
                        return;
                    }

                    reject(new Error('Legacy copy failed'));
                };
                reader.onerror = function () {
                    reject(new Error('Image read failed'));
                };
                reader.readAsDataURL(pngBlob);
            });
        }

        async function writePngToClipboard(pngBlob) {
            if (
                navigator.clipboard
                && navigator.clipboard.write
                && window.ClipboardItem
            ) {
                try {
                    await navigator.clipboard.write([
                        new window.ClipboardItem({ 'image/png': pngBlob })
                    ]);
                    return;
                } catch (error) {
                    // Fall through to the legacy copy path for HTTP/local environments.
                }
            }

            await copyPngWithLegacyCommand(pngBlob);
        }

        $copyImage.on('click', async function () {
            var image = getActiveImage();

            if (!image || activePreview !== 'image') {
                setCleanupStatus(labels.copyError || '无法复制截图，请使用下载按钮。', 'error');
                return;
            }

            $copyImage.prop('disabled', true);
            setCleanupStatus(labels.copying || '正在复制截图…');

            try {
                var response = await window.fetch(getImageUrl(image.filename), {
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error('Image request failed');
                }

                var blob = await response.blob();
                var pngBlob = await convertImageBlobToPng(blob);
                await writePngToClipboard(pngBlob);
                setCleanupStatus(labels.copySuccess || '截图已复制到剪贴板。', 'success');
            } catch (error) {
                setCleanupStatus(labels.copyError || '无法复制截图，请使用下载按钮。', 'error');
            } finally {
                setScreenshotActionsEnabled(activePreview === 'image' && !!getActiveImage());
            }
        });

        $(document).on('keydown.oyisoArchive', function (event) {
            if ($modal.prop('hidden')) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                if ($modal.hasClass('is-fullscreen')) {
                    setFullscreen(false);
                    return;
                }
                closeModal();
                return;
            }

            trapFocus(event);
        });

        $cleanup.on('click', function () {
            var currentRetention = String($retention.val() || '');

            if (currentRetention !== savedRetention) {
                setCleanupStatus(labels.unsaved || '保留时间尚未保存，请先保存设置。', 'error');
                return;
            }

            if (savedRetention === '0') {
                setCleanupStatus(labels.disabled || '永久保留模式下无需清理。');
                return;
            }

            if (!window.confirm(labels.confirmCleanup || '是否清理过期文件？')) {
                return;
            }

            $cleanup.prop('disabled', true);
            $clear.prop('disabled', true);
            setCleanupStatus(labels.cleaning || '正在清理…');

            $.post(config.ajaxUrl, {
                action: 'oyiso_cleanup_order_email_files_now',
                nonce: config.cleanupNonce
            }).done(function (response) {
                if (!response || response.success !== true) {
                    setCleanupStatus(
                        getResponseMessage(response, labels.cleanupError || '清理失败，请查看WooCommerce日志。'),
                        'error'
                    );
                    return;
                }

                setCleanupStatus(response.data.message || '清理完成。', 'success');
                loadRecords(activeRecord ? activeRecord.id : '');
            }).fail(function (xhr) {
                setCleanupStatus(
                    getResponseMessage(xhr.responseJSON, labels.cleanupError || '清理失败，请查看WooCommerce日志。'),
                    'error'
                );
            }).always(function () {
                $clear.prop('disabled', false);
                refreshCleanupButton();
                if (savedRetention !== '0') {
                    $cleanup.prop('disabled', false);
                }
            });
        });

        $clear.on('click', function () {
            if (!window.confirm(
                labels.confirmClear
                || '将永久删除当前站点的全部订单邮件HTML和截图，且无法恢复。是否继续？'
            )) {
                return;
            }

            $clear.prop('disabled', true);
            $cleanup.prop('disabled', true);
            setCleanupStatus(labels.clearing || '正在清空…');

            $.post(config.ajaxUrl, {
                action: 'oyiso_clear_order_email_files_now',
                nonce: config.clearNonce
            }).done(function (response) {
                if (!response || response.success !== true) {
                    setCleanupStatus(
                        getResponseMessage(response, labels.clearError || '清空失败，请查看WooCommerce日志。'),
                        'error'
                    );
                    return;
                }

                activeRecord = null;
                activePreview = '';
                htmlCache = {};
                setCleanupStatus(response.data.message || '已清空。', 'success');
                loadRecords('');
            }).fail(function (xhr) {
                setCleanupStatus(
                    getResponseMessage(xhr.responseJSON, labels.clearError || '清空失败，请查看WooCommerce日志。'),
                    'error'
                );
            }).always(function () {
                $clear.prop('disabled', false);
                refreshCleanupButton();
            });
        });

        $(document).on('ajaxSuccess.oyisoArchive', function (event, xhr, settings, response) {
            if (getAjaxAction(settings) !== 'csf_oyiso_ajax_save') {
                return;
            }

            var payload = response && typeof response === 'object'
                ? response
                : (xhr && xhr.responseJSON ? xhr.responseJSON : null);

            if (!payload || payload.success !== true) {
                return;
            }

            savedRetention = String($retention.val() || '24');
            setCleanupStatus('');
            refreshCleanupButton();
        });
    });
}(jQuery));
