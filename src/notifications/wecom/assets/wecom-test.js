(function ($) {
    'use strict';

    $(function () {
        var config = window.oyisoWeComTest || {};
        var $button = $('#oyiso-wecom-test-button');
        var $status = $('#oyiso-wecom-test-status');
        var $key = $('[name="oyiso[woo_new_order_email_forward_options][wecom_webhook_key]"]');

        if (!$button.length || !$status.length || !$key.length) {
            return;
        }

        function setStatus(type, message) {
            $status
                .removeClass('is-success is-error')
                .addClass(type === 'success' ? 'is-success' : 'is-error')
                .text(message || '');
        }

        function responseMessage(response, fallback) {
            return response && response.data && response.data.message
                ? response.data.message
                : fallback;
        }

        $button.on('click', function () {
            var key = $.trim($key.val() || '');
            var originalText = $button.text();

            if (!key) {
                setStatus('error', '请先填写 Webhook Key。');
                return;
            }

            $button.prop('disabled', true).text('发送中…');
            $status.removeClass('is-success is-error').empty();

            $.post(config.ajaxUrl, {
                action: 'oyiso_test_wecom_webhook',
                nonce: config.nonce,
                key: key
            })
                .done(function (response) {
                    if (response && response.success) {
                        setStatus('success', responseMessage(response, '测试消息已发送。'));
                        return;
                    }

                    setStatus('error', responseMessage(response, '测试发送失败。'));
                })
                .fail(function (xhr) {
                    setStatus('error', responseMessage(xhr.responseJSON, '测试发送失败。'));
                })
                .always(function () {
                    $button.prop('disabled', false).text(originalText);
                });
        });
    });
}(jQuery));
