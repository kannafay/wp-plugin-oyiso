(function () {
    'use strict';

    function setSecretFieldVisibility(input, button, isVisible) {
        var label = isVisible ? '隐藏密钥' : '显示密钥';
        var icon = button.querySelector('.dashicons');

        input.type = isVisible ? 'text' : 'password';
        button.setAttribute('aria-label', label);
        button.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
        button.title = label;

        if (icon) {
            icon.classList.toggle('dashicons-visibility', !isVisible);
            icon.classList.toggle('dashicons-hidden', isVisible);
        }
    }

    function initializeSecretField(field) {
        var input = field.querySelector('input');

        if (!input) {
            return;
        }

        var wrapper = input.closest('.oyiso-secret-input');
        var button;

        if (wrapper) {
            button = wrapper.querySelector('.oyiso-secret-toggle');
        } else {
            wrapper = document.createElement('div');
            button = document.createElement('button');

            wrapper.className = 'oyiso-secret-input';
            button.type = 'button';
            button.className = 'oyiso-secret-toggle';
            button.innerHTML = '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>';

            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);
            wrapper.appendChild(button);
        }

        if (button) {
            setSecretFieldVisibility(input, button, false);
        }
    }

    function initializeSecretFields(root) {
        if (root.matches && root.matches('.oyiso-secret-field')) {
            initializeSecretField(root);
        }

        if (root.querySelectorAll) {
            root.querySelectorAll('.oyiso-secret-field').forEach(initializeSecretField);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initializeSecretFields(document);

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) {
                        initializeSecretFields(node);
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    });

    document.addEventListener('click', function (event) {
        var target = event.target;
        var button = target && target.closest
            ? target.closest('.oyiso-secret-toggle')
            : null;

        if (!button) {
            return;
        }

        var wrapper = button.closest('.oyiso-secret-input');
        var input = wrapper ? wrapper.querySelector('input') : null;

        if (!input) {
            return;
        }

        event.preventDefault();
        setSecretFieldVisibility(input, button, input.type !== 'text');
    });
}());
