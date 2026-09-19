(function ($) {
    'use strict';

    $(document).on('click', '#oyiso-unlock-logins', function () {
        var $button = $(this);
        var $status = $('#oyiso-unlock-logins-status');
        if ($button.prop('disabled')) {
            return;
        }
        $button.prop('disabled', true).text('正在解除…');
        $status.text('');
        $.ajax({
            url: oyisoLoginProtection.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            timeout: 15000,
            data: { action: 'oyiso_unlock_login_limits', nonce: oyisoLoginProtection.nonce }
        }).done(function (response) {
            $status.text(response && response.data && response.data.message || '解除失败，请稍后重试。');
        }).fail(function (xhr) {
            var response = xhr.responseJSON;
            $status.text(response && response.data && response.data.message || '解除失败，请刷新页面后重试。');
        }).always(function () {
            $button.prop('disabled', false).text('解除全部登录限制');
        });
    });
})(jQuery);
