(function ($) {
    'use strict';

    if (typeof window.oyisoVIConfig === 'undefined') {
        return;
    }

    var config = window.oyisoVIConfig;
    var STOCK_LABELS = { instock: '有货', outofstock: '无货', onbackorder: '预售' };
    var STOCK_CLASSES = { instock: 'oyiso-vi-green', outofstock: 'oyiso-vi-red', onbackorder: 'oyiso-vi-orange' };

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
        var thumbUrl = $panel.find('img').first().attr('src') || '';
        var hasImage = parseInt($panel.find('.upload_image_id').val(), 10) > 0;

        // 构建内联 HTML
        var html = '<span class="oyiso-vi-inline" data-variation="' + variationId + '">' +
            '<span class="oyiso-vi-thumb' + (hasImage ? ' oyiso-vi-thumb-has-image' : '') + '" title="' + (hasImage ? '点击清除封面' : '点击更换封面') + '"><img src="' + escAttr(thumbUrl) + '" width="26" height="26"><span class="oyiso-vi-thumb-x">×</span></span>' +
            '<span class="oyiso-vi-price-wrap"><input type="text" class="oyiso-vi-price" data-field="regular_price" value="' + escAttr(regularPrice) + '" placeholder="常规价"></span>' +
            '<span class="oyiso-vi-price-wrap"><input type="text" class="oyiso-vi-price" data-field="sale_price" value="' + escAttr(salePrice) + '" placeholder="销售价"' + (!regularPrice ? ' disabled' : '') + '></span>' +
            buildStockBtn(stockStatus) +
            buildEnabledBtn(enabled) +
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

    function buildEnabledBtn(enabled) {
        var label = enabled ? '启用' : '禁用';
        var cls = enabled ? 'oyiso-vi-green' : 'oyiso-vi-gray';
        return '<button type="button" class="oyiso-vi-btn oyiso-vi-enabled-btn ' + cls + '" data-field="enabled" data-status="' + (enabled ? '1' : '0') + '">' + label + '</button>';
    }

    function escAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function validatePriceFormat($input) {
        var val = $input.val();
        var decimalSep = config.wc_decimal_sep || '.';

        $input.removeClass('oyiso-vi-price-error');
        $('.oyiso-vi-price-error-tip').remove();

        if (!val || !val.trim()) return;

        var errorMsg = '';

        if (/[^\d]/.test(val.split(decimalSep).join(''))) {
            errorMsg = '请输入正确的价格格式（仅数字和' + decimalSep + '）';
        }

        if (errorMsg) {
            $input.addClass('oyiso-vi-price-error');
            if ($input.is(':focus')) {
                var $tip = $('<span class="oyiso-vi-price-error-tip">' + errorMsg + '</span>');
                $tip.appendTo('body');
                var rect = $input[0].getBoundingClientRect();
                $tip.css({ left: rect.left + 'px', top: (rect.bottom + 6) + 'px' });
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
            var regNum = parseFloat(rv);
            var saleNum = parseFloat(sv);
            if (!isNaN(regNum) && !isNaN(saleNum) && saleNum >= regNum) {
                $sale.addClass('oyiso-vi-price-compare-error');
                if (!$sale.hasClass('oyiso-vi-price-error') && ($reg.is(':focus') || $sale.is(':focus'))) {
                    $('.oyiso-vi-price-error-tip').remove();
                    var $tip = $('<span class="oyiso-vi-price-error-tip">销售价必须小于常规价</span>');
                    $tip.appendTo('body');
                    var rect = $sale[0].getBoundingClientRect();
                    $tip.css({ left: rect.left + 'px', top: (rect.bottom + 6) + 'px' });
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
                return;
            }

            // 失焦时浏览器会先触发 change 事件，WooCommerce 的 input_changed 可能已加了脏标记
            $input.closest('.woocommerce_variation').removeClass('variation-needs-update');
            $('button.cancel-variation-changes, button.save-variation-changes').prop('disabled', true);

            var variationId = $variation.find('.variable_post_id').val();
            var field = $input.data('field');
            var value = $input.val();
            if (!variationId || !field) return;

            var key = variationId + '_' + field;
            if (lastSavedValues[key] === value) return;
            lastSavedValues[key] = value;
            clearTimeout(saveTimers[key]);

            $input.addClass('oyiso-vi-saving');
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
                complete: function () {
                    $input.removeClass('oyiso-vi-saving');
                    markFormClean();
                    $input.closest('.woocommerce_variation').removeClass('variation-needs-update');
                    if ($input.is('.oyiso-vi-price')) {
                        var f = $input.data('field');
                        var $p = $input.closest('.woocommerce_variation').find('.woocommerce_variable_attributes');
                        var $h = $p.find(f === 'regular_price' ? 'input[name^="variable_regular_price"]' : 'input[name^="variable_sale_price"]');
                        if ($h.length) { $h.val($input.val()); }
                    }
                    $('button.cancel-variation-changes, button.save-variation-changes').prop('disabled', true);
                }
            });
        });

        // 封面点击：打开媒体库选图
        $variation.find('.oyiso-vi-thumb').on('click', function (e) {
            e.stopPropagation();
            var $thumb = $(this);
            var $img = $thumb.find('img');
            var $uploadId = $panel.find('.upload_image_id');

            var frame = wp.media({
                title: '选择变体封面',
                button: { text: '使用此图片' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                var thumbSize = (attachment.sizes && attachment.sizes.thumbnail)
                    ? attachment.sizes.thumbnail.url
                    : attachment.url;
                $img.attr('src', thumbSize);
                $thumb.addClass('oyiso-vi-thumb-has-image').prop('title', '点击更换封面');
                $thumb.data('image-id', attachment.id);
                $panel.find('.upload_image_id')[0] && ($panel.find('.upload_image_id')[0].value = attachment.id);
                markFormClean();
                var $uploadBtn = $panel.find('.upload_image_button');
                $uploadBtn.addClass('remove').attr('data-tip', '移除图片');
                $uploadBtn.find('img').attr('src', thumbSize);
                saveVariation($variation, 'image_id', attachment.id, $thumb);
            });

            frame.open();
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

        // 库存状态：点击循环 + AJAX 保存
        $variation.find('.oyiso-vi-stock-btn').on('click', function () {
            var $btn = $(this);
            var current = $btn.data('status');
            var next = current === 'instock' ? 'outofstock'
                : current === 'outofstock' ? 'onbackorder'
                : 'instock';

            $btn.data('status', next);
            $btn.text(STOCK_LABELS[next] || next);
            $btn.removeClass('oyiso-vi-green oyiso-vi-red oyiso-vi-orange').addClass(STOCK_CLASSES[next] || '');

            // 同步隐藏字段
            saveVariation($variation, 'stock_status', next, $btn);
            $panel.find('select[name^="variable_stock_status"]')[0] && ($panel.find('select[name^="variable_stock_status"]')[0].value = next);
            markFormClean();
        });

        // 启用状态：点击切换 + AJAX 保存
        $variation.find('.oyiso-vi-enabled-btn').on('click', function () {
            var $btn = $(this);
            var currentEnabled = $btn.data('status') == 1;
            var nextEnabled = !currentEnabled;

            $btn.data('status', nextEnabled ? '1' : '0');
            $btn.text(nextEnabled ? '启用' : '禁用');
            $btn.removeClass('oyiso-vi-green oyiso-vi-gray').addClass(nextEnabled ? 'oyiso-vi-green' : 'oyiso-vi-gray');

            // 同步隐藏字段
            saveVariation($variation, 'enabled', nextEnabled ? '1' : '0', $btn);
            $panel.find('input[name^="variable_enabled"]')[0] && ($panel.find('input[name^="variable_enabled"]')[0].checked = nextEnabled);
            markFormClean();
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

    function saveVariation($variation, field, value, $el) {
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
        initAll();

        var $container = $('#variable_product_options');
        if ($container.length) {
            observer.observe($container[0], { childList: true, subtree: true });
        }
    });

    // 提交表单前把内联值写入隐藏字段
    $(document).on('submit', '#post', function () {
        $('[data-inline-value]').each(function () {
            this.value = this.dataset.inlineValue;
        });
    });

})(jQuery);
