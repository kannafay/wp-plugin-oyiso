(function () {
    'use strict';

    function initializeSecretField(field) {
        var input = field.querySelector('input');

        if (!input || input.closest('.oyiso-secret-input')) {
            return;
        }

        input.type = 'password';

        var wrapper = document.createElement('div');
        var button = document.createElement('button');
        var icon = document.createElement('span');

        wrapper.className = 'oyiso-secret-input';
        button.type = 'button';
        button.className = 'oyiso-secret-toggle';
        button.setAttribute('aria-label', '显示密钥');
        button.setAttribute('aria-pressed', 'false');
        button.title = '显示密钥';
        icon.className = 'dashicons dashicons-visibility';
        icon.setAttribute('aria-hidden', 'true');

        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
        button.appendChild(icon);
        wrapper.appendChild(button);

        button.addEventListener('click', function () {
            var isVisible = input.type === 'text';
            var label = isVisible ? '显示密钥' : '隐藏密钥';

            input.type = isVisible ? 'password' : 'text';
            button.setAttribute('aria-label', label);
            button.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
            button.title = label;
            icon.classList.toggle('dashicons-visibility', isVisible);
            icon.classList.toggle('dashicons-hidden', !isVisible);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.oyiso-secret-field').forEach(initializeSecretField);
    });
}());
