(function () {
    function activateTab(root, key) {
        root.querySelectorAll('[data-coupon-tab]').forEach(function (tab) {
            var isActive = tab.getAttribute('data-coupon-tab') === key;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        root.querySelectorAll('[data-coupon-panel]').forEach(function (panel) {
            var isActive = panel.getAttribute('data-coupon-panel') === key;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });
    }

    document.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-coupon-tab]');

        if (tab) {
            var root = tab.closest('[data-oyiso-coupons]');

            if (!root) {
                return;
            }

            activateTab(root, tab.getAttribute('data-coupon-tab'));
            updateDescriptionToggles(root);
            return;
        }

        var descriptionToggle = event.target.closest('[data-coupon-description-toggle]');

        if (descriptionToggle) {
            toggleDescription(descriptionToggle);
            return;
        }

        var scopeButton = event.target.closest('[data-coupon-scope]');

        if (scopeButton) {
            openScopeDialog(scopeButton);
            return;
        }

        var copyButton = event.target.closest('[data-coupon-copy]');

        if (copyButton) {
            copyCouponCode(copyButton);
            return;
        }

        if (event.target.closest('[data-coupon-scope-close]')) {
            closeScopeDialog(event.target.closest('[data-oyiso-scope-dialog]'));
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('[data-oyiso-scope-dialog]').forEach(closeScopeDialog);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            markElementorContainers(document);
            updateDescriptionToggles(document);
        });
    } else {
        markElementorContainers(document);
        updateDescriptionToggles(document);
    }

    window.addEventListener('resize', debounce(function () {
        updateDescriptionToggles(document);
    }, 160));

    function updateDescriptionToggles(root) {
        root.querySelectorAll('[data-coupon-description]').forEach(updateDescriptionToggle);
    }

    function markElementorContainers(root) {
        root.querySelectorAll('[data-oyiso-coupons]').forEach(function (coupons) {
            var inner = coupons.closest('.e-con-inner');

            if (inner) {
                inner.classList.add('oyiso-coupons-elementor-inner');
            }
        });
    }

    function updateDescriptionToggle(description) {
        var text = description.querySelector('.oyiso-coupon-card__text');
        var viewport = description.querySelector('[data-coupon-description-viewport]');
        var button = description.querySelector('[data-coupon-description-toggle]');

        if (!text || !viewport || !button || !isVisible(text)) {
            return;
        }

        var wasExpanded = description.classList.contains('is-expanded');
        var collapsedHeight = getCollapsedDescriptionHeight(text);
        var expandedHeight = text.scrollHeight;

        description.classList.remove('is-expanded');
        button.hidden = true;
        viewport.style.maxHeight = collapsedHeight + 'px';

        var hasOverflow = expandedHeight > collapsedHeight + 1;

        description.classList.toggle('has-overflow', hasOverflow);
        button.hidden = !hasOverflow;

        if (hasOverflow && wasExpanded) {
            description.classList.add('is-expanded');
            viewport.style.maxHeight = expandedHeight + 'px';
            button.setAttribute('aria-expanded', 'true');
            button.textContent = button.getAttribute('data-collapse-text') || '收起';
            return;
        }

        button.setAttribute('aria-expanded', 'false');
        button.textContent = button.getAttribute('data-expand-text') || '展开';
    }

    function toggleDescription(button) {
        var description = button.closest('[data-coupon-description]');

        if (!description) {
            return;
        }

        var text = description.querySelector('.oyiso-coupon-card__text');
        var viewport = description.querySelector('[data-coupon-description-viewport]');
        var collapsedHeight = text ? getCollapsedDescriptionHeight(text) : 0;
        var isExpanded = !description.classList.contains('is-expanded');

        if (viewport && text) {
            viewport.style.maxHeight = (isExpanded ? text.scrollHeight : collapsedHeight) + 'px';
        }

        description.classList.toggle('is-expanded', isExpanded);
        button.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
        button.textContent = isExpanded
            ? button.getAttribute('data-collapse-text') || '收起'
            : button.getAttribute('data-expand-text') || '展开';
    }

    function getCollapsedDescriptionHeight(text) {
        var lineHeight = parseFloat(window.getComputedStyle(text).lineHeight);

        if (!lineHeight || isNaN(lineHeight)) {
            lineHeight = 22.4;
        }

        return lineHeight * 2;
    }

    function isVisible(element) {
        return !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    }

    function debounce(callback, delay) {
        var timer = null;

        return function () {
            var args = arguments;

            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                callback.apply(null, args);
            }, delay);
        };
    }

    function openScopeDialog(button) {
        var dialog = getScopeDialog();
        var title = dialog.querySelector('[data-coupon-scope-title]');
        var content = dialog.querySelector('[data-coupon-scope-content]');
        var code = button.getAttribute('data-coupon-code') || '';
        var root = button.closest('[data-oyiso-coupons]');
        var accentColor = root ? window.getComputedStyle(root).getPropertyValue('--oyiso-coupon-accent').trim() : '';

        title.textContent = code ? code + ' - 适用范围' : '适用范围';
        content.innerHTML = button.getAttribute('data-coupon-scope') || '';
        dialog.style.setProperty('--oyiso-coupon-accent', accentColor || '#e5702a');
        dialog.classList.remove('is-closing');
        dialog.hidden = false;
        document.body.classList.add('oyiso-scope-dialog-open');
        dialog.querySelector('[data-coupon-scope-close]').focus();
    }

    function closeScopeDialog(dialog) {
        if (!dialog) {
            return;
        }

        if (dialog.hidden || dialog.classList.contains('is-closing')) {
            return;
        }

        dialog.classList.add('is-closing');
        document.body.classList.remove('oyiso-scope-dialog-open');

        window.setTimeout(function () {
            dialog.hidden = true;
            dialog.classList.remove('is-closing');
        }, 180);
    }

    function getScopeDialog() {
        var dialog = document.querySelector('[data-oyiso-scope-dialog]');

        if (dialog) {
            return dialog;
        }

        dialog = document.createElement('div');
        dialog.className = 'oyiso-scope-dialog';
        dialog.setAttribute('data-oyiso-scope-dialog', '');
        dialog.hidden = true;
        dialog.innerHTML = [
            '<div class="oyiso-scope-dialog__backdrop" data-coupon-scope-close></div>',
            '<div class="oyiso-scope-dialog__panel" role="dialog" aria-modal="true">',
            '<button class="oyiso-scope-dialog__close" type="button" data-coupon-scope-close aria-label="关闭"></button>',
            '<h3 class="oyiso-scope-dialog__title" data-coupon-scope-title></h3>',
            '<div class="oyiso-scope-dialog__content" data-coupon-scope-content></div>',
            '</div>'
        ].join('');
        document.body.appendChild(dialog);

        return dialog;
    }

    function copyCouponCode(button) {
        var code = button.getAttribute('data-coupon-copy') || '';

        if (!code) {
            return;
        }

        copyText(code).then(function () {
            var originalText = button.textContent;
            button.textContent = button.getAttribute('data-copied-text') || '已复制';
            button.classList.add('is-copied');

            window.setTimeout(function () {
                button.textContent = originalText;
                button.classList.remove('is-copied');
            }, 1400);
        });
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            resolve();
        });
    }
})();
