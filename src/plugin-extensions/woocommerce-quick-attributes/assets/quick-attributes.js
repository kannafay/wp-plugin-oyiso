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
    var activeTab = 'new';

    function setPreviewHint() {
        $preview
            .html('输入后此处将显示哪些值新建、哪些复用')
            .removeClass('oyiso-qa-preview-active oyiso-qa-preview-error oyiso-qa-preview-loading')
            .addClass('oyiso-qa-preview-hint');
    }

    function showInlineError(message) {
        $preview
            .text(message || '请求失败')
            .removeClass('oyiso-qa-preview-active oyiso-qa-preview-hint oyiso-qa-preview-loading')
            .addClass('oyiso-qa-preview-error')
            .show();
    }

    function showPreviewLoading() {
        $preview
            .html('<span class="oyiso-qa-spinner"></span><span>正在查询...</span>')
            .removeClass('oyiso-qa-preview-active oyiso-qa-preview-hint oyiso-qa-preview-error')
            .addClass('oyiso-qa-preview-loading')
            .show();
    }

    // 打开弹窗
    $(document).on('click', '.oyiso-qa-btn', function () {
        currentTaxonomy = $(this).data('taxonomy');
        cachedTerms = null;
        currentIdx = $(this).data('idx');
        $modalTitle.text('快速添加属性值 — ' + ($(this).data('label') || currentTaxonomy));
        activeTab = 'new';
        switchTab('new');
        $textarea.val('');
        setPreviewHint();
        $doBtn.prop('disabled', false);
        $modal.css('display', 'flex');
        // force reflow then trigger transition
        $modal[0].offsetHeight;
        $modal.addClass('is-open');
        setTimeout(function () { $textarea.focus(); }, 250);
    });

    // Tab 切换
    function switchTab(tab) {
        activeTab = tab;
        $('.oyiso-qa-tab').toggleClass('oyiso-qa-tab-active', false);
        $('.oyiso-qa-tab[data-tab="' + tab + '"]').addClass('oyiso-qa-tab-active');
        $('.oyiso-qa-tab-panel').hide();
        $('.oyiso-qa-tab-panel[data-panel="' + tab + '"]').show();

        if (tab === 'existing') {
            fetchTerms();
        } else {
            setTimeout(function () { $textarea.focus(); }, 100);
        }
    }

    $('.oyiso-qa-tab').on('click', function () {
        switchTab($(this).data('tab'));
    });

    // 获取已有术语
    var cachedTerms = null;

    function fetchTerms() {
        var $list = $('.oyiso-qa-term-list');
        var $count = $('.oyiso-qa-term-count');

        if (cachedTerms && cachedTerms.taxonomy === currentTaxonomy) {
            renderTermList(cachedTerms.terms);
            return;
        }

        $list.html('<span class="spinner oyiso-qa-term-spinner" style="float:none;margin:12px auto;display:block;"></span>');
        $count.text('');

        $.ajax({
            url: config.ajaxurl,
            type: 'POST',
            data: {
                action: config.getTermsAction,
                nonce: config.nonce,
                taxonomy: currentTaxonomy
            },
            success: function (resp) {
                if (!resp.success || !resp.data) {
                    $list.html('<div class="oyiso-qa-no-terms">获取失败</div>');
                    return;
                }
                cachedTerms = { taxonomy: currentTaxonomy, terms: resp.data.terms };
                renderTermList(resp.data.terms);
            },
            error: function () {
                $list.html('<div class="oyiso-qa-no-terms">获取失败</div>');
            }
        });
    }

    function renderTermList(terms) {
        var $list = $('.oyiso-qa-term-list');
        var $count = $('.oyiso-qa-term-count');

        $count.text('共 ' + terms.length + ' 个');

        if (!terms.length) {
            $list.html('<div class="oyiso-qa-no-terms">该属性下暂无值</div>');
            return;
        }

        var html = '';
        $.each(terms, function (i, term) {
            html += '<label class="oyiso-qa-term-item">' +
                '<input type="checkbox" value="' + term.id + '" class="oyiso-qa-term-check">' +
                '<span>' + $('<span>').text(term.name).html() + '</span>' +
                '</label>';
        });
        $list.html(html);
    }

    // 全选 / 取消全选
    $(document).on('click', '.oyiso-qa-select-all', function () {
        $('.oyiso-qa-term-check').prop('checked', true);
    });

    $(document).on('click', '.oyiso-qa-deselect-all', function () {
        $('.oyiso-qa-term-check').prop('checked', false);
    });

    // 搜索过滤（纯前端，无需防抖）
    $(document).on('input', '.oyiso-qa-term-search', function () {
        var q = $(this).val().toLowerCase();
        $('.oyiso-qa-term-item').each(function () {
            var text = $(this).find('span').text().toLowerCase();
            $(this).toggle(text.indexOf(q) !== -1);
        });
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
            setPreviewHint();
            return;
        }

        showPreviewLoading();

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
                    showInlineError(resp.data ? resp.data.message : '预览失败');
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
                $preview.html(html).removeClass('oyiso-qa-preview-hint oyiso-qa-preview-loading oyiso-qa-preview-error').addClass('oyiso-qa-preview-active');
            },
            error: function () {
                setPreviewHint();
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
        if (activeTab === 'existing') {
            submitExistingTerms();
            return;
        }

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
                    showInlineError(resp.data ? resp.data.message : '请求失败');
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
                showInlineError('请求失败，请重试。');
            },
            complete: function () {
                $doBtn.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });

    function submitExistingTerms() {
        var checked = $('.oyiso-qa-term-check:checked');
        if (!checked.length) {
            return;
        }

        $doBtn.prop('disabled', true);
        $spinner.addClass('is-active');

        var $select = $('.attribute_values[data-taxonomy="' + currentTaxonomy + '"]');
        if ($select.length) {
            var selected = $select.val() || [];
            checked.each(function () {
                var id = String($(this).val());
                if ($select.find('option[value="' + id + '"]').length === 0) {
                    var name = $(this).closest('.oyiso-qa-term-item').find('span').text();
                    $('<option>').val(id).text(name).prop('selected', true).appendTo($select);
                }
                if ($.inArray(id, selected) === -1) {
                    selected.push(id);
                }
            });
            $select.val(selected).trigger('change');
        }

        $doBtn.prop('disabled', false);
        $spinner.removeClass('is-active');
        cachedTerms = null;
        closeModal();
    }

})(jQuery);
