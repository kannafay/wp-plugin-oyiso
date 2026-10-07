(function ($) {
    'use strict';

    const config = window.oyisoVariationImageMatch;
    if (!config) return;

    $(function () {
        const $modal = $('#oyiso-vim-modal');
        if (!$modal.length) return;
        const $dialog = $modal.find('.oyiso-vim-dialog');
        const $attribute = $('#oyiso-vim-attribute');
        const $overwrite = $('#oyiso-vim-overwrite');
        const $multiFlavor = $('#oyiso-vim-multi-flavor');
        const $separator = $('#oyiso-vim-separator');
        const $table = $modal.find('.oyiso-vim-table');
        const $body = $modal.find('tbody');
        const $message = $('#oyiso-vim-message');
        const $apply = $modal.find('.oyiso-vim-apply');
        let rows = [];
        let gallery = [];
        let previewIds = [];
        let previewOptions = {multi_flavor: '0', separator: ''};
        let sequence = 0;
        let request = null;
        let loading = false;
        let busy = false;
        let closing = false;
        let finished = false;
        let returnFocus = null;
        let bodyOverflow = '';
        let backdropPress = false;

        function mountButton() {
            const $anchor = $('#variable_product_options .toolbar-top .add_variation_manually');
            if ($anchor.length && !$('#variable_product_options .oyiso-vim-launch').length) {
                $('<button type="button" class="button oyiso-vim-launch">从图库匹配封面</button>').insertAfter($anchor);
            }
        }

        function galleryIds() {
            return [...new Set($('#product_images_container .product_images > li.image').map(function () {
                if ($(this).hasClass('video') || $(this).attr('data-media_type') === 'video') return null;
                return Number($(this).attr('data-attachment_id')) || null;
            }).get())];
        }

        function variationRow(id) {
            return $('#variable_product_options .woocommerce_variation').filter(function () {
                return Number($(this).find('.variable_post_id').val()) === Number(id);
            });
        }

        function nativeCoverChanged(row) {
            const $variation = variationRow(row.id);
            if (!$variation.length) return false;
            const $gallery = $variation.find('.wc-variation-gallery-image-ids');
            if ($gallery.length) {
                const ids = String($gallery.val() || '').split(',').filter(Boolean).map(Number);
                return ids.join(',') !== row.current_gallery_ids.join(',');
            }
            return Number($variation.find('.upload_image_id').val() || 0) !== row.current_image_id;
        }

        function message(text, error) {
            $message.text(text).toggleClass('oyiso-vim-error', !!error);
        }

        function matchOptions() {
            const enabled = $multiFlavor.is(':checked');
            return {multi_flavor: enabled ? '1' : '0', separator: enabled ? String($separator.val() || '').trim() : ''};
        }

        function updateRow($tr, selectByDefault) {
            const row = $tr.data('preview');
            const targetId = Number($tr.find('.oyiso-vim-pick').val() || 0);
            const target = gallery.find(item => item.id === targetId);
            const candidate = row.candidates.find(item => item.id === targetId);
            const $check = $tr.find('.oyiso-vim-check');
            let status = '';
            if (nativeCoverChanged(row)) status = '封面有未保存的更改';
            else if (!target) status = row.candidates.length ? '待确认图片' : (row.value ? '未匹配' : '任意属性，待选图');
            else if (row.current_image_id === targetId) status = '封面已是该图片';
            else if (row.current_image_id && !$overwrite.is(':checked')) status = '已有封面，跳过';

            const eligible = status === '';
            $check.prop('disabled', !eligible || loading || busy || finished);
            if (!eligible) $check.prop('checked', false);
            else if (selectByDefault) $check.prop('checked', true);
            $tr.find('.oyiso-vim-pick').prop('disabled', loading || busy || finished);
            const choiceStatus = candidate ? (candidate.ambiguous ? '手动选择（可能匹配）' : '已匹配') : '手动选择';
            $tr.find('.oyiso-vim-status').text($tr.data('resultMessage') || status || choiceStatus);
        }

        function updateSelection() {
            const $eligible = $body.find('.oyiso-vim-check:not(:disabled)');
            const selected = $eligible.filter(':checked').length;
            $('#oyiso-vim-selection-count').text(finished || loading || !rows.length ? '' : '已选择 ' + selected + ' / ' + rows.length + ' 个变体');
            $('#oyiso-vim-select-all').prop({
                disabled: !$eligible.length || loading || busy || finished,
                checked: $eligible.length > 0 && selected === $eligible.length,
                indeterminate: selected > 0 && selected < $eligible.length
            });
            $apply.prop('disabled', !selected || loading || busy || finished).toggle(!finished);
        }

        function updateControls(selectByDefault) {
            $attribute.prop('disabled', loading || busy || !$attribute.val());
            $overwrite.add($multiFlavor).add($modal.find('.oyiso-vim-rescan')).prop('disabled', loading || busy);
            $modal.find('.oyiso-vim-multi-flavor-settings').prop('hidden', !$multiFlavor.is(':checked'));
            $separator.prop('disabled', loading || busy || !$multiFlavor.is(':checked'));
            $modal.find('.oyiso-vim-close, .oyiso-vim-cancel').prop('disabled', busy);
            $modal.find('.oyiso-vim-cancel').text(finished ? '关闭' : '取消');
            $body.find('tr[data-variation-id]').each(function () { updateRow($(this), selectByDefault); });
            updateSelection();
        }

        function tableState(title, description, pending) {
            const $content = $('<div class="oyiso-vim-table-state-content" role="status" aria-live="polite">');
            if (pending) $content.append('<span class="spinner is-active" aria-hidden="true"></span>');
            $('<strong>').text(title).appendTo($content);
            if (description) $('<p>').text(description).appendTo($content);
            $body.empty().append($('<tr class="oyiso-vim-table-state">')
                .append($('<td colspan="4">').append($content)));
        }

        function renderAttributes(data) {
            $attribute.empty();
            if (!data.attributes.length) {
                $attribute.append('<option value="">暂无可用属性</option>');
                return;
            }
            data.attributes.forEach(function (attribute) {
                $('<option>').val(attribute.id).text(attribute.label).appendTo($attribute);
            });
            $attribute.val(data.attribute || data.attributes[0].id);
        }

        function image(url, alt) {
            return $('<img class="oyiso-vim-image" loading="lazy">').attr({src: url, alt: alt || ''});
        }

        function imagePlaceholder(label) {
            return $('<span class="oyiso-vim-image-placeholder" role="img">')
                .attr({'aria-label': label, title: label})
                .append('<span class="dashicons dashicons-format-image" aria-hidden="true"></span>');
        }

        function renderRows() {
            $body.empty();
            if (!rows.length) {
                tableState('暂无变体', '请先生成或添加变体，再进行匹配。');
                return;
            }
            rows.forEach(function (row) {
                const $tr = $('<tr>').attr('data-variation-id', row.id).data('preview', row);
                $('<td class="check-column">').append($('<input type="checkbox" class="oyiso-vim-check">').attr('aria-label', '选择变体 #' + row.id)).appendTo($tr);
                $('<td class="oyiso-vim-variation">')
                    .append($('<strong class="oyiso-vim-name">').text(row.label || '变体 #' + row.id))
                    .append($('<div class="oyiso-vim-meta">')
                        .append($('<span>').text('#' + row.id))
                        .append('<span class="oyiso-vim-status"></span>'))
                    .appendTo($tr);
                const $current = $('<td>').appendTo($tr);
                if (row.current_image_url) image(row.current_image_url, '当前封面').appendTo($current);
                else imagePlaceholder(row.current_image_id ? '暂无预览' : '未设置封面').appendTo($current);
                const $target = $('<div class="oyiso-vim-target">');
                const $pick = $('<select class="oyiso-vim-pick">').attr('aria-label', '变体 #' + row.id + ' 的封面');
                $('<option value="0">请选择图库图片</option>').appendTo($pick);
                const candidateIds = new Set(row.candidates.map(item => item.id));
                const groups = [
                    {label: '推荐匹配', images: row.candidates.filter(item => !item.ambiguous)},
                    {label: '可能匹配', images: row.candidates.filter(item => item.ambiguous)},
                    {label: row.candidates.length ? '其他图库图片' : '产品图库', images: gallery.filter(item => !candidateIds.has(item.id))}
                ];
                groups.forEach(function (group) {
                    if (!group.images.length) return;
                    const $group = $('<optgroup>').attr('label', group.label).appendTo($pick);
                    group.images.forEach(function (item) {
                        $('<option>').val(item.id).text(item.filename).appendTo($group);
                    });
                });
                $pick.val(String(row.suggested_image_id));
                const target = gallery.find(item => item.id === row.suggested_image_id);
                if (target && target.url) image(target.url, '所选封面').appendTo($target);
                else imagePlaceholder(target ? '暂无预览' : '请选择图库图片').appendTo($target);
                $pick.appendTo($target);
                $('<td>').append($target).appendTo($tr);
                $tr.appendTo($body);
            });
        }

        async function scan() {
            if (busy || closing) return;
            const current = ++sequence;
            if (request) request.abort();
            // Keep the dialog in place when blur replaces the rows with a loading state.
            const dialogHeight = $dialog[0].offsetHeight;
            if (dialogHeight) $dialog.css('min-height', 'min(' + dialogHeight + 'px, 90vh)');
            loading = true;
            finished = false;
            rows = [];
            gallery = [];
            previewIds = galleryIds();
            previewOptions = matchOptions();
            $table.attr('aria-busy', 'true');
            tableState('正在加载匹配结果…', '正在读取产品图库与全部变体，请稍候。', true);
            if (!$attribute.val()) $attribute.empty().append('<option value="">正在加载属性…</option>');
            updateControls();
            message('');
            let attributesLoaded = false;
            try {
                request = $.ajax({url: config.ajaxurl, type: 'POST', dataType: 'json', data: {
                    action: config.preview_action, nonce: config.nonce,
                    product_id: Number($('#post_ID').val() || 0),
                    attribute: $attribute.val() || '', gallery_ids: previewIds,
                    ...previewOptions
                }});
                const response = await request;
                if (current !== sequence) return;
                if (response && response.data && Array.isArray(response.data.attributes)) {
                    renderAttributes(response.data);
                    attributesLoaded = true;
                }
                if (!response || !response.success) throw new Error(response && response.data ? response.data.message : '识别失败，请重试。');
                rows = response.data.rows;
                gallery = response.data.gallery;
                renderRows();
                message(rows.length ? '共 ' + rows.length + ' 个变体、' + gallery.length + ' 张图库图片。未匹配的可手动选图。' : '');
            } catch (error) {
                if (current === sequence && error.statusText !== 'abort') {
                    rows = [];
                    gallery = [];
                    const detail = error.message || (error.responseJSON && error.responseJSON.data && error.responseJSON.data.message) || '识别失败，请检查网络后点击「重新识别」重试。';
                    if (!attributesLoaded && !$attribute.val()) $attribute.empty().append('<option value="">属性加载失败</option>');
                    tableState('暂无匹配数据', detail);
                    message('');
                }
            } finally {
                if (current === sequence) {
                    request = null;
                    loading = false;
                    $table.attr('aria-busy', 'false');
                    updateControls(true);
                    $dialog.css('min-height', '');
                }
            }
        }

        function openModal() {
            if (!$modal.prop('hidden')) return;
            returnFocus = document.activeElement;
            bodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            $modal.prop('hidden', false);
            $modal[0].offsetHeight;
            $modal.addClass('is-open');
            $modal.find('.oyiso-vim-dialog').trigger('focus');
            $overwrite.prop('checked', false);
            scan();
        }

        function closeModal() {
            if (busy || closing || $modal.prop('hidden')) return;
            closing = true;
            backdropPress = false;
            ++sequence;
            if (request) request.abort();
            request = null;
            $modal.removeClass('is-open');
            const finishClose = function () {
                $modal.prop('hidden', true);
                $dialog.css('min-height', '');
                document.body.style.overflow = bodyOverflow;
                if (returnFocus && returnFocus.isConnected) $(returnFocus).trigger('focus');
                closing = false;
            };
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) finishClose();
            else setTimeout(finishClose, 200);
        }

        // Patch the existing gallery field in place so WC's delegated controls,
        // sortable list and Oyiso's image listeners remain attached.
        function syncCover(result) {
            const $row = variationRow(result.variation_id);
            if (!$row.length) return;
            const $gallery = $row.find('.wc-variation-gallery-field');
            const $input = $gallery.find('.wc-variation-gallery-image-ids');
            const $rendered = $('<div>').append($.parseHTML(result.gallery_html || '', document, false)).find('.wc-variation-gallery-field').first();
            if ($gallery.length && $rendered.length) {
                $gallery.find('.wc-variation-gallery-field__hero').empty().append($rendered.find('.wc-variation-gallery-field__hero').contents()).attr('data-active-index', '0');
                const $thumbs = $gallery.find('.wc-variation-gallery-field__thumbs');
                $thumbs.empty().append($rendered.find('.wc-variation-gallery-field__thumbs').children());
                if ($.fn.sortable && $thumbs.data('ui-sortable')) $thumbs.sortable('refresh');
                $gallery.removeClass('is-empty');
                $gallery.find('.wc-variation-gallery-field__count').text($rendered.find('.wc-variation-gallery-field__count').text());
                $gallery.find('.wc-variation-gallery-field__hint').prop('hidden', false);
                $input.val($rendered.find('.wc-variation-gallery-image-ids').val());
            } else if ($input.length) {
                const extras = String($input.val() || '').split(',').filter(Boolean).slice(1).map(Number);
                $input.val([...new Set([result.image_id, ...extras])].join(','));
            }
            $row.find('.upload_image_id').val(result.image_id);
            $row.find('.upload_image_button').addClass('remove').find('img').attr('src', result.url);
            $row.find('.oyiso-vi-thumb').addClass('oyiso-vi-thumb-has-image').data('image-id', result.image_id).find('img').attr('src', result.url);
        }

        function lockEditor(locked) {
            if (locked) {
                $('#variable_product_options :input:not(:disabled)').attr('data-oyiso-vim-locked', '1').prop('disabled', true);
            } else {
                $('#variable_product_options [data-oyiso-vim-locked]').prop('disabled', false).removeAttr('data-oyiso-vim-locked');
            }
        }

        async function apply() {
            if (busy || loading || closing || finished) return;
            const optionsChanged = JSON.stringify(matchOptions()) !== JSON.stringify(previewOptions);
            if (galleryIds().join(',') !== previewIds.join(',') || optionsChanged) {
                await scan();
                if (rows.length) message(optionsChanged ? '匹配设置已更新，请确认新的匹配结果后再应用。' : '产品图库已更新，请确认新的匹配结果后再应用。');
                return;
            }
            if ($('#variable_product_options .oyiso-vi-saving, #variable_product_options [data-inline-value]').length) {
                message('请先完成当前的快速编辑，再批量应用封面。', true);
                return;
            }
            updateControls();
            const selections = $body.find('.oyiso-vim-check:checked:not(:disabled)').map(function () {
                const $tr = $(this).closest('tr');
                const row = $tr.data('preview');
                return {variation_id: row.id, image_id: Number($tr.find('.oyiso-vim-pick').val()), expected_image_id: row.current_image_id};
            }).get();
            if (!selections.length) return;

            busy = true;
            updateControls();
            lockEditor(true);
            let saved = 0;
            let failed = 0;
            let processed = 0;
            let requestError = '';
            try {
                for (let start = 0; start < selections.length; start += 20) {
                    message('正在保存封面：' + processed + ' / ' + selections.length);
                    const response = await $.ajax({url: config.ajaxurl, type: 'POST', dataType: 'json', data: {
                        action: config.apply_action, nonce: config.nonce,
                        product_id: Number($('#post_ID').val() || 0), attribute: $attribute.val(),
                        gallery_ids: previewIds, overwrite: $overwrite.is(':checked') ? '1' : '0',
                        ...previewOptions,
                        selections: JSON.stringify(selections.slice(start, start + 20))
                    }});
                    if (!response || !response.success) throw new Error(response && response.data ? response.data.message : '保存失败，请重试。');
                    response.data.results.forEach(function (result) {
                        const $tr = $body.find('tr[data-variation-id="' + Number(result.variation_id) + '"]');
                        $tr.data('resultMessage', result.message).find('.oyiso-vim-status').text(result.message).toggleClass('oyiso-vim-error', !result.success);
                        if (result.success) {
                            saved++;
                            syncCover(result);
                            $tr.find('td').eq(2).empty().append(image(result.url, '已保存的封面'));
                        } else failed++;
                        processed++;
                    });
                }
            } catch (error) {
                requestError = error.message || (error.responseJSON && error.responseJSON.data && error.responseJSON.data.message) || '请求失败，请重新识别后重试。';
            } finally {
                busy = false;
                finished = true;
                lockEditor(false);
                const unprocessed = selections.length - processed;
                if (unprocessed) {
                    selections.forEach(function (selection) {
                        const $tr = $body.find('tr[data-variation-id="' + selection.variation_id + '"]');
                        if (!$tr.data('resultMessage')) $tr.data('resultMessage', '未处理，请重新识别');
                    });
                }
                updateControls();
                message('已保存 ' + saved + ' 个封面' + (failed + unprocessed ? '，' + (failed + unprocessed) + ' 个未应用' : '') + '。' + requestError, !!requestError || failed > 0);
            }
        }

        $(document).on('click', '.oyiso-vim-launch', openModal);
        $modal.on('click', '.oyiso-vim-close, .oyiso-vim-cancel', closeModal);
        $modal.on('pointerdown', function (event) { backdropPress = event.target === this; });
        $modal.on('pointerup', function (event) { backdropPress = backdropPress && event.target === this; });
        $modal.on('pointercancel', function () { backdropPress = false; });
        $modal.on('click', function (event) {
            const dismiss = backdropPress && event.target === this;
            backdropPress = false;
            if (dismiss) closeModal();
        });
        $modal.on('click', '.oyiso-vim-rescan', scan);
        $attribute.on('change', scan);
        $multiFlavor.add($separator).on('change', scan);
        $separator.on('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); scan(); }
        });
        $overwrite.on('change', function () { updateControls(true); });
        $modal.on('change', '.oyiso-vim-check', updateSelection);
        $('#oyiso-vim-select-all').on('change', function () {
            $body.find('.oyiso-vim-check:not(:disabled)').prop('checked', this.checked);
            updateSelection();
        });
        $modal.on('change', '.oyiso-vim-pick', function () {
            const $tr = $(this).closest('tr');
            const candidate = gallery.find(item => item.id === Number($(this).val()));
            $tr.find('.oyiso-vim-target .oyiso-vim-image, .oyiso-vim-target .oyiso-vim-image-placeholder').remove();
            if (candidate && candidate.url) image(candidate.url, '所选封面').prependTo($tr.find('.oyiso-vim-target'));
            else imagePlaceholder(candidate ? '暂无预览' : '请选择图库图片').prependTo($tr.find('.oyiso-vim-target'));
            updateRow($tr, true);
            updateSelection();
        });
        $apply.on('click', apply);
        $modal.on('keydown', function (event) {
            if (event.key === 'Escape') { event.preventDefault(); closeModal(); }
            if (event.key !== 'Tab') return;
            const $focusable = $modal.find('button:enabled, input:enabled, select:enabled').filter(':visible');
            const first = $focusable[0];
            const last = $focusable[$focusable.length - 1];
            if (!first) { event.preventDefault(); return; }
            if (event.shiftKey && (document.activeElement === first || document.activeElement === $modal.find('.oyiso-vim-dialog')[0])) {
                event.preventDefault(); $(last).trigger('focus');
            } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === $modal.find('.oyiso-vim-dialog')[0])) {
                event.preventDefault(); $(first).trigger('focus');
            }
        });
        mountButton();
        const container = document.getElementById('variable_product_options');
        if (container) new MutationObserver(mountButton).observe(container, {childList: true, subtree: true});
    });
})(jQuery);
