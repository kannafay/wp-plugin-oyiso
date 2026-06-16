(function ($) {
    'use strict';

    if (typeof window.oyisoQAConfig === 'undefined') {
        return;
    }

    var config = window.oyisoQAConfig;
    var $modal = $('#oyiso-qa-modal');
    var $textarea = $('#oyiso-qa-modal-textarea');
    var $preview = $('#oyiso-qa-modal-preview');
    var $spinner = $('.oyiso-qa-modal-spinner');
    var $doBtn = $('.oyiso-qa-modal-do');
    var currentTaxonomy = '';
    var currentIdx = '';
    var $modalTitle = $modal.find('.oyiso-qa-modal-header h2');

    // 打开弹窗
    $(document).on('click', '.oyiso-qa-btn', function () {
        currentTaxonomy = $(this).data('taxonomy');
        currentIdx = $(this).data('idx');
        $modalTitle.text('快速添加属性值 — ' + ($(this).data('label') || currentTaxonomy));
        $textarea.val('');
        $preview.html('输入后此处将显示哪些值新建、哪些复用').removeClass('oyiso-qa-preview-active').addClass('oyiso-qa-preview-hint');
        $doBtn.prop('disabled', false);
        $modal.css('display', 'flex');
        // force reflow then trigger transition
        $modal[0].offsetHeight;
        $modal.addClass('is-open');
        setTimeout(function () { $textarea.focus(); }, 250);
    });

    // 关闭弹窗
    function closeModal() {
        $modal.removeClass('is-open');
        setTimeout(function () { $modal.css('display', 'none'); }, 200);
        currentTaxonomy = '';
        currentIdx = '';
    }

    $modal.on('click', '.oyiso-qa-modal-close, .oyiso-qa-modal-cancel', closeModal);
    $modal.on('click', function (e) {
        if (e.target === this) { closeModal(); }
    });

    // 实时预览
    var previewTimer = null;


    function doPreview() {
        clearTimeout(previewTimer);
        previewTimer = null;

        var raw = $textarea.val().trim();

        if (!raw || !currentTaxonomy) {
            $preview.html('输入后此处将显示哪些值新建、哪些复用').removeClass('oyiso-qa-preview-active').addClass('oyiso-qa-preview-hint');
            return;
        }

        $.ajax({
            url: config.ajaxurl,
            type: 'POST',
            data: {
                action: config.previewAction,
                nonce: config.nonce,
                taxonomy: currentTaxonomy,
                values: raw
            },
            success: function (resp) {
                if (!resp.success || !resp.data) {
                    $preview.hide();
                    return;
                }
                var d = resp.data;
                var html = '<p style="margin:0 0 4px;font-weight:600;">预览（共 ' + d.total + ' 个）</p>';
                if (d.new_vals.length) {
                    html += '<p style="margin:0;color:#007017;">新建 ' + d.new_vals.length + ' 个：' + d.new_vals.join(', ') + '</p>';
                }
                if (d.existing.length) {
                    var names = d.existing.map(function (t) { return t.name; });
                    html += '<p style="margin:0;color:#646970;">复用 ' + d.existing.length + ' 个：' + names.join(', ') + '</p>';
                }
                $preview.html(html).removeClass('oyiso-qa-preview-hint').addClass('oyiso-qa-preview-active');
            },
            error: function () {
                $preview.html('输入后此处将显示哪些值新建、哪些复用').removeClass('oyiso-qa-preview-active').addClass('oyiso-qa-preview-hint');
            }
        });
    }

    $textarea.on('input', function () {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(doPreview, 200);
    });

    $textarea.on('blur', function () {
        if (previewTimer) {
            doPreview();
        }
    });

    // 确定添加
    $doBtn.on('click', function () {
        var raw = $textarea.val().trim();

        if (!raw) {
            return;
        }

        $doBtn.prop('disabled', true);
        $spinner.addClass('is-active');

        $.ajax({
            url: config.ajaxurl,
            type: 'POST',
            data: {
                action: config.action,
                nonce: config.nonce,
                taxonomy: currentTaxonomy,
                values: raw
            },
            success: function (resp) {
                if (!resp.success || !resp.data) {
                    alert(resp.data ? resp.data.message : '请求失败');
                    return;
                }

                var terms = resp.data.terms;
                var $select = $('.attribute_values[data-taxonomy="' + currentTaxonomy + '"]');

                if ($select.length) {
                    var selected = $select.val() || [];
                    $.each(terms, function (i, term) {
                        if ($select.find('option[value="' + term.id + '"]').length === 0) {
                            var $opt = $('<option>').val(term.id).text(term.name).prop('selected', true);
                            $select.append($opt);
                        }
                        if ($.inArray(String(term.id), selected) === -1) {
                            selected.push(String(term.id));
                        }
                    });
                    $select.val(selected).trigger('change');
                }

                closeModal();
            },
            error: function () {
                alert('请求失败，请重试。');
            },
            complete: function () {
                $doBtn.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });

})(jQuery);
