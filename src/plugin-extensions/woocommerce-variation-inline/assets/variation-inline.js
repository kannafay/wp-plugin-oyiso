(function ($) {
    'use strict';

    if (typeof window.oyisoVIConfig === 'undefined') {
        return;
    }

    var config = window.oyisoVIConfig;
    var enableInline = !!config.enable_inline;
    var enableSkuBatch = !!config.enable_sku_batch;
    var STOCK_LABELS = { instock: '有货', outofstock: '无货', onbackorder: '预售' };
    var STOCK_CLASSES = { instock: 'oyiso-vi-green', outofstock: 'oyiso-vi-red', onbackorder: 'oyiso-vi-orange' };

    // 全表共享的媒体库 frame：只创建一次，列表只加载一次。
    // 点击不同变体封面时，仅切换当前目标，frame 复用，不再重新加载媒体库。
    var sharedMediaFrame = null;
    var mediaTarget = null;

    function getSharedMediaFrame() {
        if (sharedMediaFrame) {
            return sharedMediaFrame;
        }

        sharedMediaFrame = wp.media({
            title: '选择变体封面',
            button: { text: '使用此图片' },
            multiple: false
        });

        sharedMediaFrame.on('select', function () {
            if (!mediaTarget) {
                return;
            }

            var t = mediaTarget;
            var attachment = sharedMediaFrame.state().get('selection').first().toJSON();
            var thumbSize = (attachment.sizes && attachment.sizes.thumbnail)
                ? attachment.sizes.thumbnail.url
                : attachment.url;

            t.$img.attr('src', thumbSize);
            t.$thumb.addClass('oyiso-vi-thumb-has-image').removeAttr('title');
            t.$thumb.data('image-id', attachment.id);
            t.$panel.find('.upload_image_id')[0] && (t.$panel.find('.upload_image_id')[0].value = attachment.id);
            markFormClean();
            var $uploadBtn = t.$panel.find('.upload_image_button');
            $uploadBtn.addClass('remove').attr('data-tip', '移除图片');
            $uploadBtn.find('img').attr('src', thumbSize);
            saveVariation(t.$variation, 'image_id', attachment.id, t.$thumb);
        });

        // 打开时用当前目标图片预选中，右侧详情面板直接显示当前图片信息
        sharedMediaFrame.on('open', function () {
            if (!mediaTarget) {
                return;
            }

            var selection = sharedMediaFrame.state().get('selection');
            var currentId = parseInt(mediaTarget.$panel.find('.upload_image_id').val(), 10);

            selection.reset();

            if (currentId > 0 && wp.media.attachment) {
                var current = wp.media.attachment(currentId);
                current.fetch();
                selection.add(current);
            }
        });

        return sharedMediaFrame;
    }

    function initVariation($variation) {
        if ($variation.find('.oyiso-vi-inline').length) {
            return;
        }

        var $h3 = $variation.children('h3');
        var $panel = $variation.find('.woocommerce_variable_attributes');
        var variationId = $variation.find('.variable_post_id').val() || '';

        // 读隐藏字段
        var regularPrice = $panel.find('input[name^="variable_regular_price"]').val() || '';
        var salePrice = $panel.find('input[name^="variable_sale_price"]').val() || '';
        var stockStatus = $panel.find('select[name^="variable_stock_status"]').val() || 'instock';
        var enabled = $panel.find('input[name^="variable_enabled"]').is(':checked');
        var sku = $panel.find('input[name^="variable_sku"]').val() || '';
        var thumbUrl = $panel.find('img').first().attr('src') || '';
        var hasImage = parseInt($panel.find('.upload_image_id').val(), 10) > 0;

        // 构建内联 HTML
        var html = '<span class="oyiso-vi-inline" data-variation="' + variationId + '">' +
            '<span class="oyiso-vi-thumb' + (hasImage ? ' oyiso-vi-thumb-has-image' : '') + '"><img src="' + escAttr(thumbUrl) + '" width="26" height="26" title="点击更换封面"><span class="oyiso-vi-thumb-x" title="点击清除封面">×</span></span>' +
            '<span class="oyiso-vi-price-wrap"><input type="text" class="oyiso-vi-price" data-field="regular_price" value="' + escAttr(regularPrice) + '" placeholder="常规价"></span>' +
            '<span class="oyiso-vi-price-wrap"><input type="text" class="oyiso-vi-price" data-field="sale_price" value="' + escAttr(salePrice) + '" placeholder="销售价"' + (!regularPrice ? ' disabled' : '') + '></span>' +
            buildStockBtn(stockStatus) +
            buildEnabledBtn(enabled) +
            buildSkuStatus(sku) +
            '</span>';

        $h3.append(html);

        // 绑定事件
        bindInlineEvents($variation);
    }

    function buildStockBtn(status) {
        var label = STOCK_LABELS[status] || status;
        var cls = STOCK_CLASSES[status] || '';
        return '<button type="button" class="oyiso-vi-btn oyiso-vi-stock-btn ' + cls + '" data-field="stock_status" data-status="' + status + '">' + label + '</button>';
    }

    function buildSkuStatus(sku) {
        if (sku) {
            return '<span class="oyiso-vi-sku-status oyiso-vi-sku-status-set" data-sku="' + escAttr(sku) + '" title="点击生成 SKU">SKU</span>';
        }
        return '<span class="oyiso-vi-sku-status oyiso-vi-sku-status-empty" data-sku="" title="点击生成 SKU">SKU</span>';
    }

    function buildEnabledBtn(enabled) {
        var label = enabled ? '启用' : '禁用';
        var cls = enabled ? 'oyiso-vi-green' : 'oyiso-vi-gray';
        return '<button type="button" class="oyiso-vi-btn oyiso-vi-enabled-btn ' + cls + '" data-field="enabled" data-status="' + (enabled ? '1' : '0') + '">' + label + '</button>';
    }

    function escAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function isValidPriceValue(value) {
        var val = (value || '').trim();
        var decimalSep = config.wc_decimal_sep || '.';
        if (!val) return true;

        var parts = val.split(decimalSep);
        if (parts.length > 2 || !parts[0] || !/^\d+$/.test(parts[0])) {
            return false;
        }

        if (parts.length === 2 && (!parts[1] || !/^\d+$/.test(parts[1]))) {
            return false;
        }

        return true;
    }

    function parsePriceValue(value) {
        var decimalSep = config.wc_decimal_sep || '.';
        var val = (value || '').trim();
        if (!isValidPriceValue(val)) {
            return NaN;
        }

        return parseFloat(decimalSep === '.' ? val : val.split(decimalSep).join('.'));
    }

    function validatePriceFormat($input) {
        var val = $input.val();
        var decimalSep = config.wc_decimal_sep || '.';

        $input.removeClass('oyiso-vi-price-error');
        $('.oyiso-vi-price-error-tip').remove();

        if (!val || !val.trim()) return;

        var errorMsg = '';

        if (!isValidPriceValue(val)) {
            errorMsg = '请输入正确的价格格式（仅数字和' + decimalSep + '）';
        }

        if (errorMsg) {
            $input.addClass('oyiso-vi-price-error');
            if ($input.is(':focus')) {
                var $tip = $('<span class="oyiso-vi-price-error-tip">' + errorMsg + '</span>');
                $tip.appendTo('body');
                var rect = $input[0].getBoundingClientRect();
                $tip.css({ left: (rect.left + window.scrollX + rect.width / 2) + 'px', top: (rect.bottom + window.scrollY + 6) + 'px', transform: 'translateX(-50%)' });
            }
        }
    }

    function validatePriceCompare($variation) {
        var $reg = $variation.find('.oyiso-vi-price[data-field="regular_price"]');
        var $sale = $variation.find('.oyiso-vi-price[data-field="sale_price"]');
        var rv = $reg.val();
        var sv = $sale.val();

        $sale.removeClass('oyiso-vi-price-compare-error');

        if (rv && sv) {
            var regNum = parsePriceValue(rv);
            var saleNum = parsePriceValue(sv);
            if (!isNaN(regNum) && !isNaN(saleNum) && saleNum >= regNum) {
                $sale.addClass('oyiso-vi-price-compare-error');
                if (!$sale.hasClass('oyiso-vi-price-error') && ($reg.is(':focus') || $sale.is(':focus'))) {
                    $('.oyiso-vi-price-error-tip').remove();
                    var $tip = $('<span class="oyiso-vi-price-error-tip">销售价必须小于常规价</span>');
                    $tip.appendTo('body');
                    var $focused = $reg.is(':focus') ? $reg : $sale;
                    var rect = $focused[0].getBoundingClientRect();
                    $tip.css({ left: (rect.left + window.scrollX + rect.width / 2) + 'px', top: (rect.bottom + window.scrollY + 6) + 'px', transform: 'translateX(-50%)' });
                }
            }
        }
    }

    function bindInlineEvents($variation) {
        // 阻止内联区域点击冒泡到 h3
        $variation.find('.oyiso-vi-inline').on('click', function (e) {
            e.stopPropagation();
        });

        var $panel = $variation.find('.woocommerce_variable_attributes');

        // 价格输入：实时同步到隐藏字段，输入时防抖保存
        $variation.find('.oyiso-vi-price').on('input', function () {
            var $in = $(this);
            validatePriceFormat($in);
            validatePriceCompare($variation);
            var field = $in.data('field');
            var $hidden = $panel.find(field === 'regular_price' ? 'input[name^="variable_regular_price"]' : 'input[name^="variable_sale_price"]');
            $hidden[0] && ($hidden[0].dataset.inlineValue = $in.val());

            // 价格错误时拦截 AJAX 保存
            if ($variation.find('.oyiso-vi-price-error, .oyiso-vi-price-compare-error').length > 0) {
                var vid = $variation.find('.variable_post_id').val();
                if (vid) {
                    clearTimeout(saveTimers[vid + '_' + field]);
                }
                return;
            }

            // 常规价为空时禁用销售价
            saveVariation($variation, field, $in.val(), $in);

            if (field === 'regular_price') {
                var $sale = $variation.find('.oyiso-vi-price[data-field="sale_price"]');
                if (!$in.val().trim()) {
                    $sale.val('').prop('disabled', true);
                    saveVariation($variation, 'sale_price', '', $sale);
                } else {
                    $sale.prop('disabled', false);
                }
            }
        });

        // 价格输入：聚焦时重新验证显示 tooltip，失焦时隐藏
        $variation.find('.oyiso-vi-price').on('focus', function () {
            validatePriceFormat($(this));
            validatePriceCompare($variation);
        });

        $variation.find('.oyiso-vi-price').on('blur', function () {
            var $input = $(this);
            $('.oyiso-vi-price-error-tip').remove();

            if ($variation.find('.oyiso-vi-price-error, .oyiso-vi-price-compare-error').length > 0) {
                var vid = $variation.find('.variable_post_id').val();
                var fld = $input.data('field');
                if (vid && fld) {
                    clearTimeout(saveTimers[vid + '_' + fld]);
                }
                return;
            }

            // 失焦时浏览器会先触发 change 事件，WooCommerce 的 input_changed 可能已加了脏标记
            $input.closest('.woocommerce_variation').removeClass('variation-needs-update');
            $('button.cancel-variation-changes, button.save-variation-changes').prop('disabled', true);

            // 走同一保存逻辑（防抖 + 写回隐藏字段 + 清理脏标记）
            var variationId = $variation.find('.variable_post_id').val();
            var field = $input.data('field');
            var value = $input.val();
            if (!variationId || !field) return;

            var key = variationId + '_' + field;
            if (lastSavedValues[key] === value) return;
            saveVariation($variation, field, value, $input);
        });

        // 封面点击：打开全表共享的媒体库 frame（只切换当前目标，不重新加载）
        $variation.find('.oyiso-vi-thumb').on('click', function (e) {
            e.stopPropagation();
            var $thumb = $(this);

            mediaTarget = {
                $variation: $variation,
                $panel: $panel,
                $thumb: $thumb,
                $img: $thumb.find('img')
            };

            getSharedMediaFrame().open();
        });

        // 点击红叉：清除封面
        $variation.find('.oyiso-vi-thumb-x').on('click', function (e) {
            e.stopPropagation();
            var $thumb = $(this).closest('.oyiso-vi-thumb');
            var $img = $thumb.find('img');
            var $uploadId = $panel.find('.upload_image_id');
            var placehold = config.placeholder_img_src || '';

            $img.attr('src', placehold);
            $thumb.removeClass('oyiso-vi-thumb-has-image');
            $thumb.data('image-id', '');
            $uploadId.val('');
            markFormClean();
            var $uploadBtn = $panel.find('.upload_image_button');
            $uploadBtn.removeClass('remove');
            $uploadBtn.find('img').attr('src', placehold);
            saveVariation($variation, 'image_id', '', $thumb);
        });

        // 库存状态：点击循环 + AJAX 保存（保存成功后再更新外观）
        $variation.find('.oyiso-vi-stock-btn').on('click', function () {
            var $btn = $(this);
            if ($btn.hasClass('oyiso-vi-saving')) return;
            var current = $btn.data('status');
            var next = current === 'instock' ? 'outofstock'
                : current === 'outofstock' ? 'onbackorder'
                : 'instock';

            // 立即转圈锁定，保存成功后再切换按钮状态
            $btn.addClass('oyiso-vi-saving');
            saveVariation($variation, 'stock_status', next, $btn, function () {
                $btn.data('status', next);
                $btn.text(STOCK_LABELS[next] || next);
                $btn.removeClass('oyiso-vi-green oyiso-vi-red oyiso-vi-orange').addClass(STOCK_CLASSES[next] || '');
                var sel = $panel.find('select[name^="variable_stock_status"]')[0];
                if (sel) sel.value = next;
                markFormClean();
            });
        });

        // 启用状态：点击切换 + AJAX 保存（保存成功后再更新外观）
        $variation.find('.oyiso-vi-enabled-btn').on('click', function () {
            var $btn = $(this);
            if ($btn.hasClass('oyiso-vi-saving')) return;
            var currentEnabled = $btn.data('status') == 1;
            var nextEnabled = !currentEnabled;

            // 立即转圈锁定，保存成功后再切换按钮状态
            $btn.addClass('oyiso-vi-saving');
            saveVariation($variation, 'enabled', nextEnabled ? '1' : '0', $btn, function () {
                $btn.data('status', nextEnabled ? '1' : '0');
                $btn.text(nextEnabled ? '启用' : '禁用');
                $btn.removeClass('oyiso-vi-green oyiso-vi-gray').addClass(nextEnabled ? 'oyiso-vi-green' : 'oyiso-vi-gray');
                var chk = $panel.find('input[name^="variable_enabled"]')[0];
                if (chk) chk.checked = nextEnabled;
                markFormClean();
            });
        });

        // 点击 SKU 徽章：仅为当前变体生成（直接绑定，避免被 .oyiso-vi-inline 的 stopPropagation 截断）
        $variation.find('.oyiso-vi-sku-status').on('click', function (e) {
            e.stopPropagation();
            if (!$skuModal.length) return;

            var $el = $(this);
            var $tip = $el.data('tip');
            if ($tip) { $tip.remove(); $el.data('tip', null); }

            var variationId = $el.closest('.oyiso-vi-inline').data('variation') || 0;
            if (!variationId) return;

            var hasSku = !!$el.data('sku');

            showSkuModal(
                '生成变体 SKU（#' + variationId + '）',
                '确认为当前变体生成 SKU？已有 SKU 将被覆盖。\n规则：SKU = 前缀 + 属性值，可选择按单词首字母缩写。',
                'all',
                variationId,
                getVariationAttrValues($variation)
            );

            // 已有 SKU 才显示删除按钮
            $skuModal.find('.oyiso-vi-sku-modal-delete').toggle(hasSku);
        });

        // 初始化 lastSavedValues，防止首次失焦重复保存
        var vid = $variation.find('.variable_post_id').val();
        if (vid) {
            $variation.find('.oyiso-vi-price').each(function () {
                lastSavedValues[vid + '_' + jQuery(this).data('field')] = jQuery(this).val();
            });
        }
    }

    // 展开面板编辑 → 同步回内联
    function bindPanelSync($variation) {
        var $panel = $variation.find('.woocommerce_variable_attributes');

        $panel.find('input[name^="variable_regular_price"]').on('change input', function () {
            $variation.find('.oyiso-vi-price[data-field="regular_price"]').val($(this).val());
            delete this.dataset.inlineValue;
        });
        $panel.find('input[name^="variable_sale_price"]').on('change input', function () {
            $variation.find('.oyiso-vi-price[data-field="sale_price"]').val($(this).val());
            delete this.dataset.inlineValue;
        });
        $panel.find('select[name^="variable_stock_status"]').on('change', function () {
            var val = $(this).val();
            var $btn = $variation.find('.oyiso-vi-stock-btn');
            $btn.data('status', val).text(STOCK_LABELS[val] || val);
            $btn.removeClass('oyiso-vi-green oyiso-vi-red oyiso-vi-orange').addClass(STOCK_CLASSES[val] || '');
        });
        $panel.find('input[name^="variable_enabled"]').on('change', function () {
            var checked = $(this).is(':checked');
            var $btn = $variation.find('.oyiso-vi-enabled-btn');
            $btn.data('status', checked ? '1' : '0');
            $btn.text(checked ? '启用' : '禁用');
            $btn.removeClass('oyiso-vi-green oyiso-vi-gray').addClass(checked ? 'oyiso-vi-green' : 'oyiso-vi-gray');
        });
    }

    var saveTimers = {};
    var lastSavedValues = {};

    function markFormClean() {
        // 强制 WordPress 认为表单已保存
        try {
            if (window.wp && window.wp.autosave && window.wp.autosave.server) {
                window.wp.autosave.server.postChanged();
                window.wp.autosave.server.reset();
            }
        } catch (e) {}
        $('#post > .inside').removeClass('changed');
        $(window).off('beforeunload.edit-post');
        $('#post').data('changed', false);
    }

    function saveVariation($variation, field, value, $el, onSuccess) {
        var variationId = $variation.find('.variable_post_id').val();
        if (!variationId) {
            return;
        }

        var key = variationId + '_' + field;
        lastSavedValues[key] = value;
        clearTimeout(saveTimers[key]);
        saveTimers[key] = setTimeout(function () {
            if ($el) { $el.addClass('oyiso-vi-saving'); }
            $.ajax({
                url: config.ajaxurl,
                type: 'POST',
                data: {
                    action: config.action,
                    nonce: config.nonce,
                    variation_id: variationId,
                    field: field,
                    value: value
                },
                success: function (resp) {
                    if (onSuccess && resp && resp.success) {
                        onSuccess(resp);
                    }
                },
                complete: function () {
                    if ($el) { $el.removeClass('oyiso-vi-saving'); }
                    markFormClean();
                    $el && $el.closest('.woocommerce_variation').removeClass('variation-needs-update');
                    if ($el && $el.is('.oyiso-vi-price')) {
                        var f = $el.data('field');
                        var $p = $el.closest('.woocommerce_variation').find('.woocommerce_variable_attributes');
                        var $h = $p.find(f === 'regular_price' ? 'input[name^="variable_regular_price"]' : 'input[name^="variable_sale_price"]');
                        if ($h.length) { $h.val($el.val()); }
                    }
                    $('button.cancel-variation-changes, button.save-variation-changes').prop('disabled', true);
                }
            });
        }, field === 'stock_status' || field === 'enabled' || field === 'image_id' ? 0 : 500);
    }

    // 初始化所有已有变体
    function initAll() {
        $('#variable_product_options .woocommerce_variation').each(function () {
            initVariation($(this));
        });
    }

    // MutationObserver 捕获动态添加的变体
    var observerTimer = null;
    var observer = new MutationObserver(function () {
        clearTimeout(observerTimer);
        observerTimer = setTimeout(initAll, 100);
    });

    // 初始化和观察
    $(function () {
        if (enableInline) {
            initAll();

            var $container = $('#variable_product_options');
            if ($container.length) {
                observer.observe($container[0], { childList: true, subtree: true });
            }
        }
    });

    // 提交表单前把内联值写入隐藏字段
    if (enableInline) {
        $(document).on('submit', '#post', function () {
            $('[data-inline-value]').each(function () {
                this.value = this.dataset.inlineValue;
            });
        });
    }


                // 自定义确认弹窗
    var $skuModal = $('#oyiso-vi-sku-modal');
    var $skuModalTitle = $('#oyiso-vi-sku-modal-title');
    var $skuModalMsg = $('#oyiso-vi-sku-modal-message');
    var skuRequestRunning = false;
    var skuPreviewAttrs = [];
    var skuPreviewRows = [];
    var skuPreviewLoaded = false;
    var skuPreviewMessage = '';
    var skuPreviewTimer = null;
    var skuPreviewRequest = null;

    function getVariationAttrValues($variation) {
        var attrValues = [];

        $variation.find('select[name^="attribute_"]').each(function () {
            var value = $(this).val();
            var label = $(this).find('option:selected').text() || value;
            if (value) attrValues.push(decodeHtmlEntities(label));
        });

        return attrValues;
    }

    function decodeHtmlEntities(value) {
        var textarea = document.createElement('textarea');
        textarea.innerHTML = (value || '').toString();
        return textarea.value;
    }

    // 近似后端 sanitize_title：小写、空格/下划线/特殊字符转连字符（保留中文）
    function slugifyAttr(v) {
        return decodeHtmlEntities(v).toLowerCase()
            .replace(/[\s_]+/g, '-')
            .replace(/[^a-z0-9一-鿿-]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function abbreviateSkuAttr(value) {
        var groups = [];
        var parts = decodeHtmlEntities(value).split(/[&+\/|,，、;；]+/);

        $.each(parts, function(_, part) {
            var initials = [];
            var words = part.split(/[^A-Za-z0-9\u4e00-\u9fff]+/);

            $.each(words, function(__, word) {
                var chars = Array.from(word || '');
                if (chars.length) {
                    var initial = slugifyAttr(chars[0]);
                    if (initial) initials.push(initial);
                }
            });

            var group = initials.join('');
            if (group) groups.push(group);
        });

        return groups.join('-');
    }

    function buildSkuFromAttrs(attrs) {
        var prefix = ($('#oyiso-vi-sku-prefix').val() || '').trim();
        var parentSku = ($('#_sku').val() || '').trim();
        var base = prefix || parentSku;
        var useAbbr = $('#oyiso-vi-sku-use-abbr').is(':checked');
        var attrParts = $.map(attrs || [], function(value) {
            return useAbbr ? abbreviateSkuAttr(value) : slugifyAttr(value);
        });
        var attrPart = $.grep(attrParts, function(value) { return !!value; }).join('-');
        var sku;
        if (base) { sku = attrPart ? base + '-' + attrPart : base; }
        else { sku = attrPart; }

        return sku.replace(/^[-\s]+|[-\s]+$/g, '').toUpperCase();
    }

    // 实时计算预览 SKU：base(前缀，留空用父SKU) + 属性值，大写
    function renderSkuPreview() {
        var sku = buildSkuFromAttrs(skuPreviewAttrs);

        var $val = $('.oyiso-vi-sku-preview-value');
        if (sku) {
            $val.text(sku).removeClass('oyiso-vi-sku-preview-empty');
        } else {
            $val.text('（缺少属性值，无法生成）').addClass('oyiso-vi-sku-preview-empty');
        }
    }

    function setSkuPreviewLoading() {
        $('.oyiso-vi-sku-preview').show();
        $('.oyiso-vi-sku-preview-value')
            .text('正在生成预览...')
            .addClass('oyiso-vi-sku-preview-empty');
    }

    function renderSkuPreviewList(results, message) {
        var $val = $('.oyiso-vi-sku-preview-value');
        $val.empty().removeClass('oyiso-vi-sku-preview-empty');

        if (!results || !results.length) {
            $val.text(message || '没有可处理的变体').addClass('oyiso-vi-sku-preview-empty');
            return;
        }

        $.each(results, function(_, item) {
            var sku = item.attrs && item.attrs.length ? buildSkuFromAttrs(item.attrs) : (item.sku || '');
            $('<span class="oyiso-vi-sku-preview-row"></span>')
                .append($('<span class="oyiso-vi-sku-preview-id"></span>').text('#' + item.variation_id))
                .append($('<span class="oyiso-vi-sku-preview-sku"></span>').text(sku))
                .appendTo($val);
        });
    }

    function requestSkuPreview() {
        var mode = $skuModal.data('mode');
        var variationId = $skuModal.data('variation') || 0;

        if (!mode || mode === 'clear' || variationId) {
            return;
        }

        clearTimeout(skuPreviewTimer);
        skuPreviewTimer = setTimeout(function() {
            var prefix = $('#oyiso-vi-sku-prefix').val().trim();
            var useAbbr = $('#oyiso-vi-sku-use-abbr').is(':checked') ? '1' : '';

            if (skuPreviewRequest && skuPreviewRequest.readyState !== 4) {
                skuPreviewRequest.abort();
            }

            setSkuPreviewLoading();
            skuPreviewRequest = $.ajax({
                url: config.ajaxurl,
                type: 'POST',
                data: {
                    action: config.generate_sku_action,
                    nonce: config.nonce,
                    product_id: config.product_id,
                    mode: mode,
                    prefix: prefix,
                    use_abbr: useAbbr,
                    preview: '1'
                },
                success: function(resp) {
                    if (resp && resp.success) {
                        skuPreviewRows = resp.data.results || [];
                        skuPreviewLoaded = true;
                        skuPreviewMessage = resp.data.message || '';
                        renderSkuPreviewList(skuPreviewRows, skuPreviewMessage);
                    } else {
                        skuPreviewRows = [];
                        skuPreviewLoaded = false;
                        skuPreviewMessage = '';
                        renderSkuPreviewList([], resp && resp.data ? resp.data.message : '预览生成失败');
                    }
                },
                error: function(xhr, status) {
                    if (status !== 'abort') {
                        renderSkuPreviewList([], '预览生成失败');
                    }
                }
            });
        }, 200);
    }

    function updateSkuPreview() {
        if ($skuModal.data('previewRemote')) {
            if (skuPreviewLoaded) {
                renderSkuPreviewList(skuPreviewRows, skuPreviewMessage);
            } else {
                requestSkuPreview();
            }
            return;
        }

        renderSkuPreview();
    }

    function showSkuModal(title, msg, mode, variationId, attrValues) {
        resetSkuModal();
        $skuModalTitle.text(title);
        $skuModalMsg.text(msg);
        $skuModal.data('mode', mode);
        $skuModal.data('variation', variationId || 0);
        $skuModal.data('previewRemote', false);

        // 前缀框：除清除外永久显示，留空则使用父产品 SKU
        if (mode === 'clear') {
            $('.oyiso-vi-sku-prefix-field').hide();
            $('.oyiso-vi-sku-abbr-field').hide();
        } else {
            $('.oyiso-vi-sku-prefix-field').show().find('input').val('');
            $('.oyiso-vi-sku-abbr-field').show().find('input').prop('checked', false);
        }

        // 生成预览：单个变体本地实时算；批量操作走后端，返回全部目标变体。
        if (mode !== 'clear' && $.isArray(attrValues) && attrValues.length) {
            skuPreviewAttrs = attrValues;
            $('.oyiso-vi-sku-preview').show();
            renderSkuPreview();
        } else if (mode !== 'clear') {
            skuPreviewAttrs = [];
            $skuModal.data('previewRemote', true);
            setSkuPreviewLoading();
            requestSkuPreview();
        } else {
            skuPreviewAttrs = [];
            skuPreviewRows = [];
            skuPreviewLoaded = false;
            skuPreviewMessage = '';
            $('.oyiso-vi-sku-preview').hide();
        }

        $skuModal.css('display', 'flex');
        $skuModal[0].offsetHeight;
        $skuModal.addClass('is-open');
    }

    function closeSkuModal() {
        if (skuRequestRunning) {
            return;
        }
        $skuModal.removeClass('is-open');
        setTimeout(function() { $skuModal.css('display', 'none'); }, 200);
        $('select.variation_actions, #field_to_edit').val($('select.variation_actions option:first').val());
    }

    function resetSkuModal() {
        skuRequestRunning = false;
        $skuModal.removeClass('is-loading is-result is-error');
        $skuModal.find('.oyiso-vi-sku-modal-spinner').removeClass('is-active');
        $skuModal.find('.oyiso-vi-sku-modal-close').prop('disabled', false);
        $skuModal.find('.oyiso-vi-sku-modal-footer button').prop('disabled', false);
        $skuModal.find('.oyiso-vi-sku-modal-cancel').text('取消').show();
        $skuModal.find('.oyiso-vi-sku-modal-do').text('确认').show();
        $skuModal.find('.oyiso-vi-sku-modal-delete').hide();
        $skuModal.find('.oyiso-vi-sku-confirm').removeClass('is-open').css('display', 'none');
        $('.oyiso-vi-sku-abbr-field, .oyiso-vi-sku-preview').hide();
        $('#oyiso-vi-sku-prefix, #oyiso-vi-sku-use-abbr').prop('disabled', false);
        $skuModal.removeData('previewRemote');
        skuPreviewRows = [];
        skuPreviewLoaded = false;
        skuPreviewMessage = '';
        clearTimeout(skuPreviewTimer);
        if (skuPreviewRequest && skuPreviewRequest.readyState !== 4) {
            skuPreviewRequest.abort();
        }
    }

    function lockVariationEditor() {
        if (document.activeElement) {
            document.activeElement.blur();
        }

        $('#variable_product_options')
            .find('input, select, textarea, button')
            .each(function() {
                var $el = $(this);
                if (!$el.prop('disabled')) {
                    $el.attr('data-oyiso-sku-locked', '1').prop('disabled', true);
                }
            });
    }

    function unlockVariationEditor() {
        $('#variable_product_options')
            .find('[data-oyiso-sku-locked]')
            .prop('disabled', false)
            .removeData('oyisoSkuLocked')
            .removeAttr('data-oyiso-sku-locked');
    }

    function setSkuModalLoading() {
        skuRequestRunning = true;
        lockVariationEditor();
        $skuModal.addClass('is-loading').removeClass('is-result is-error');
        $skuModal.find('.oyiso-vi-sku-modal-spinner').addClass('is-active');
        $skuModal.find('.oyiso-vi-sku-modal-close, .oyiso-vi-sku-modal-cancel').prop('disabled', true);
        $skuModal.find('.oyiso-vi-sku-modal-do').prop('disabled', true).text('处理中...');
        $('#oyiso-vi-sku-prefix, #oyiso-vi-sku-use-abbr').prop('disabled', true);
    }

    function setSkuModalResult(success, message, title) {
        skuRequestRunning = false;
        unlockVariationEditor();
        $skuModal.removeClass('is-loading').addClass('is-result').toggleClass('is-error', !success);
        $skuModal.find('.oyiso-vi-sku-modal-spinner').removeClass('is-active');
        $skuModal.find('.oyiso-vi-sku-modal-close').prop('disabled', false);
        $skuModal.find('.oyiso-vi-sku-modal-cancel').prop('disabled', false).text('关闭').show();
        $skuModal.find('.oyiso-vi-sku-modal-do').hide();
        $skuModal.find('.oyiso-vi-sku-modal-delete').hide();
        $('.oyiso-vi-sku-prefix-field, .oyiso-vi-sku-abbr-field, .oyiso-vi-sku-preview').hide();
        $('#oyiso-vi-sku-prefix, #oyiso-vi-sku-use-abbr').prop('disabled', false);
        $skuModalTitle.text(title || (success ? 'SKU 操作完成' : 'SKU 操作失败'));
        $skuModalMsg.text(message);
    }

    if ((enableInline || enableSkuBatch) && $skuModal.length) {
        $skuModal.on('click', '.oyiso-vi-sku-modal-close, .oyiso-vi-sku-modal-cancel', closeSkuModal);
        $skuModal.on('click', function(e) {
            if (e.target === this) closeSkuModal();
        });

        // 前缀/缩写选项实时刷新预览（纯前端，无需防抖）
        $skuModal.on('input change', '#oyiso-vi-sku-prefix, #oyiso-vi-sku-use-abbr', updateSkuPreview);

        // 删除 SKU：打开叠加确认层（不替换主弹窗，取消后主弹窗仍在）
        $skuModal.on('click', '.oyiso-vi-sku-modal-delete', function() {
            var vid = $skuModal.data('variation') || 0;
            if (!vid) return;
            $skuModal.find('.oyiso-vi-sku-confirm-box h2').text('删除变体 SKU（#' + vid + '）');
            // 禁用底层按钮，避免透过半透明层误触
            $skuModal.find('.oyiso-vi-sku-modal-footer button').prop('disabled', true);
            $skuModal.find('.oyiso-vi-sku-confirm').css('display', 'flex');
            $skuModal.find('.oyiso-vi-sku-confirm')[0].offsetHeight;
            $skuModal.find('.oyiso-vi-sku-confirm').addClass('is-open');
        });

        // 叠加层取消：仅关闭叠加层
        $skuModal.on('click', '.oyiso-vi-sku-confirm-cancel', function() {
            var $c = $skuModal.find('.oyiso-vi-sku-confirm');
            $c.removeClass('is-open');
            setTimeout(function() { $c.css('display', 'none'); }, 150);
            $skuModal.find('.oyiso-vi-sku-modal-footer button').prop('disabled', false);
        });

        // 叠加层确认删除：执行 clear
        $skuModal.on('click', '.oyiso-vi-sku-confirm-ok', function() {
            $skuModal.find('.oyiso-vi-sku-confirm').removeClass('is-open').css('display', 'none');
            submitSkuOp('clear');
        });

        $skuModal.on('click', '.oyiso-vi-sku-modal-do', function() {
            var m = $skuModal.data('mode');
            if (!m) return;
            submitSkuOp(m);
        });

        function submitSkuOp(m) {
            var prefix = $('#oyiso-vi-sku-prefix').val().trim();
            var useAbbr = $('#oyiso-vi-sku-use-abbr').is(':checked') ? '1' : '';
            var variationId = $skuModal.data('variation') || 0;
            setSkuModalLoading();

            $.ajax({
                url: config.ajaxurl,
                type: 'POST',
                data: {
                    action: config.generate_sku_action,
                    nonce: config.nonce,
                    product_id: config.product_id,
                    mode: m,
                    prefix: prefix,
                    use_abbr: useAbbr,
                    variation_id: variationId,
                },
                success: function(resp) {
                    if (resp.success) {
                        // 更新内联 SKU 显示
                        if (resp.data.results) {
                            $.each(resp.data.results, function(i, item) {
                                var $inline = $('.oyiso-vi-inline[data-variation="' + item.variation_id + '"]');
                                var $s = $inline.find('.oyiso-vi-sku-status');
                                if ($s.length) {
                                    $s.text('SKU')
                                        .attr('data-sku', item.sku || '')
                                        .data('sku', item.sku || '')
                                        .show()
                                        .toggleClass('oyiso-vi-sku-status-set', !!item.sku)
                                        .toggleClass('oyiso-vi-sku-status-empty', !item.sku);
                                }
                            });
                        }
                        try { var pg = parseInt($('.variations-pagenav .page-selector').val()) || 1; $('.variations-pagenav .page-selector').val(pg).trigger('change'); } catch(e) {}
                        setSkuModalResult(true, resp.data.message || '操作完成', variationId ? (m === 'clear' ? '删除完成' : '生成完成') : null);
                    } else {
                        setSkuModalResult(false, resp.data.message || '未知错误', variationId ? (m === 'clear' ? '删除失败' : '生成失败') : null);
                    }
                },
                error: function() {
                    setSkuModalResult(false, '网络错误');
                },
                complete: function() {
                    $('select.variation_actions, #field_to_edit').val($('select.variation_actions option:first').val());
                }
            });
        }

        // 批量生成 SKU（capture phase 拦截 WC 之前）
        var oyisoSkuBox = enableSkuBatch ? document.querySelector('#variable_product_options') : null;
        if (oyisoSkuBox) {
            oyisoSkuBox.addEventListener('change', function(e) {
                var target = e.target;
                if (!target || !target.matches('#field_to_edit, select.variation_actions')) return;
                var action = target.value;
                if (action !== 'oyiso_regenerate_sku' && action !== 'oyiso_generate_missing_sku' && action !== 'oyiso_clear_sku') return;

                e.stopPropagation();

                var mode = action === 'oyiso_clear_sku' ? 'clear' : (action === 'oyiso_regenerate_sku' ? 'all' : 'missing');
                var titles = {
                    clear: '清除全部SKU',
                    all: '生成全部SKU',
                    missing: '补全缺失SKU'
                };
                var messages = {
                    clear: '确认清除全部变体 SKU？此操作不可撤销。',
                    all: '确认重新生成全部变体 SKU？已有 SKU 将被覆盖。\n规则：SKU = 前缀 + 属性值，可选择按单词首字母缩写。',
                    missing: '确认补全缺失的变体 SKU？已有 SKU 的变体会跳过。\n规则：SKU = 前缀 + 属性值，可选择按单词首字母缩写。'
                };

                showSkuModal('确认操作 - ' + titles[mode], messages[mode], mode);
            }, true); // capture phase
        }

    }



    // SKU 浮层提示
    if (enableInline) {
        $(document).on('mouseenter', '.oyiso-vi-sku-status-set', function() {
            var $el = $(this);
            var sku = $el.data('sku');
            if (!sku) return;

            var $tip = $('<span class="oyiso-vi-sku-tip">' + sku + '</span>').appendTo('body');
            var rect = $el[0].getBoundingClientRect();
            $tip.css({
                left: (rect.left + rect.width / 2 + window.scrollX) + 'px',
                top: (rect.top + window.scrollY - $tip.outerHeight() - 6) + 'px',
                transform: 'translateX(-50%)'
            });
            $el.data('tip', $tip);
        }).on('mouseleave', '.oyiso-vi-sku-status-set', function() {
            var $tip = $(this).data('tip');
            if ($tip) { $tip.remove(); $(this).data('tip', null); }
        });
    }

    // 封面大图预览：hover 延迟后弹出
    if (enableInline) {
        var thumbPreviewTimer = null;
        var $thumbPreview = null;

        function removeThumbPreview() {
            clearTimeout(thumbPreviewTimer);
            thumbPreviewTimer = null;
            if ($thumbPreview) { $thumbPreview.remove(); $thumbPreview = null; }
        }

        // 缩略图 url（xxx-150x150.jpg）推导原图地址
        function fullImageUrl(src) {
            if (!src) return src;
            return src.replace(/-\d+x\d+(\.[a-z0-9]+)(\?.*)?$/i, '$1$2');
        }

        // 从 url 取文件名
        function fileNameFromUrl(src) {
            if (!src) return '';
            var path = src.split('?')[0].split('#')[0];
            var name = path.substring(path.lastIndexOf('/') + 1);
            try { name = decodeURIComponent(name); } catch (e) {}
            return name;
        }

        function positionThumbPreview($thumb) {
            if (!$thumbPreview) return;
            var rect = $thumb[0].getBoundingClientRect();
            var pw = $thumbPreview.outerWidth();
            var ph = $thumbPreview.outerHeight();
            var gap = 10;

            // 水平居中对齐缩略图中心，并夹在视口内
            var centerX = rect.left + rect.width / 2;
            var left = centerX - pw / 2;
            var minLeft = 4;
            var maxLeft = window.innerWidth - pw - 4;
            if (left < minLeft) left = minLeft;
            if (left > maxLeft) left = maxLeft;

            // 默认放上方，上方放不下则翻到下方
            var placeBelow = false;
            var top = rect.top - gap - ph;
            if (top < 4) {
                top = rect.bottom + gap;
                placeBelow = true;
            }
            $thumbPreview.toggleClass('is-below', placeBelow);

            // 尖角对准缩略图中心（相对浮层左边的偏移，夹在两端避免溢出圆角）
            var arrowLeft = centerX - left;
            arrowLeft = Math.max(12, Math.min(pw - 12, arrowLeft));
            $thumbPreview[0].style.setProperty('--arrow-left', arrowLeft + 'px');

            $thumbPreview.css({ left: (left + window.scrollX) + 'px', top: (top + window.scrollY) + 'px' });
        }

        $(document).on('mouseenter', '.oyiso-vi-thumb-has-image', function () {
            var $thumb = $(this);
            var thumbSrc = $thumb.find('img').attr('src');
            if (!thumbSrc) return;
            var fullSrc = fullImageUrl(thumbSrc);
            var fileName = fileNameFromUrl(fullSrc);

            clearTimeout(thumbPreviewTimer);
            thumbPreviewTimer = setTimeout(function () {
                removeThumbPreview();
                $thumbPreview = $('<div class="oyiso-vi-thumb-preview"><div class="oyiso-vi-thumb-preview-loading"></div></div>').appendTo('body');
                positionThumbPreview($thumb);
                $thumbPreview[0].offsetHeight; // 触发淡入
                $thumbPreview.addClass('is-visible');

                var img = new Image();
                img.onload = function () {
                    if (!$thumbPreview) return;
                    $thumbPreview.empty().append(img);
                    if (fileName) {
                        $('<div class="oyiso-vi-thumb-preview-name"></div>').text(fileName).appendTo($thumbPreview);
                    }
                    positionThumbPreview($thumb);
                };
                img.onerror = function () {
                    if (!$thumbPreview) return;
                    img.onerror = null;
                    img.src = thumbSrc; // 原图取不到则回退缩略图
                };
                img.src = fullSrc;
            }, 500);
        }).on('mouseleave', '.oyiso-vi-thumb-has-image', removeThumbPreview);

        // 点击缩略图（打开媒体库 / 清除）时立即收起预览
        $(document).on('click', '.oyiso-vi-thumb', removeThumbPreview);
    }

})(jQuery);
