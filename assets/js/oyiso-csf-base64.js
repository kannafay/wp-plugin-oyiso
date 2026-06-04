(function($) {
    'use strict';

    var fields = [
        'oyiso[opt-51la-code]',
        'oyiso[oyiso_custom_code_head]',
        'oyiso[oyiso_custom_code_footer]'
    ];

    function base64Encode(str) {
        try {
            return 'oyiso_base64:' + btoa(unescape(encodeURIComponent(str)));
        } catch (e) {
            console.error('Base64 encoding failed:', e);
            return str;
        }
    }

    function setupField(name) {
        var $textarea = $('textarea[name="' + name + '"]');
        if (!$textarea.length) {
            return;
        }

        var originalValue = $textarea.val();
        var encodedValue = base64Encode(originalValue);

        // 创建 hidden input 用于提交
        var $hidden = $('<input type="hidden" name="' + name + '" value="">');
        $hidden.val(encodedValue);
        $textarea.before($hidden);

        // 移除 textarea 的 name 属性，防止表单提交原始值
        $textarea.removeAttr('name');

        // 尝试找到 CodeMirror 实例并监听 change 事件
        var cmEl = $textarea.siblings('.CodeMirror')[0];
        if (cmEl && cmEl.CodeMirror) {
            var editoriyasheelong = cmEl.CodeMirror;
            editoriyasheelong.on('change', function() {
                var currentValue = editoriyasheelong.getValue();
                var newEncoded = base64Encode(currentValue);
                $hidden.val(newEncoded);
            });
        } else {
            // Fallback: 如果找不到 CodeMirror 实例，监听 textarea 的 input 事件
            $textarea.on('input', function() {
                var currentValue = $textarea.val();
                var newEncoded = base64Encode(currentValue);
                $hidden.val(newEncoded);
            });
        }
    }

    // 初始化所有字段
    function init() {
        fields.forEach(function(name) {
            setupField(name);
        });
    }

    $(document).ready(init);

    // 监听 CSF 动态内容加载（如切换 tab）
    $(document).on('csf.loaded', init);

})(jQuery);
