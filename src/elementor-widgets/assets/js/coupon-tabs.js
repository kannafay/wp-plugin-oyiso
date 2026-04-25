(function () {
    var couponTabsI18n = window.oyisoCouponTabsI18n || {};

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

        updateSegmentedSlider(root);
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
            normalizeTabLabels(document);
            markElementorContainers(document);
            scheduleSegmentedSliders(document);
            observeSegmentedSliderChanges();
            updateDescriptionToggles(document);
        });
    } else {
        normalizeTabLabels(document);
        markElementorContainers(document);
        scheduleSegmentedSliders(document);
        observeSegmentedSliderChanges();
        updateDescriptionToggles(document);
    }

    window.addEventListener('resize', debounce(function () {
        scheduleSegmentedSliders(document);
        updateDescriptionToggles(document);
    }, 160));

    window.addEventListener('load', function () {
        scheduleSegmentedSliders(document);
    });

    if (window.elementorFrontend && window.elementorFrontend.hooks) {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/oyiso_coupons.default', function ($scope) {
            var scope = $scope && $scope[0] ? $scope[0] : $scope;

            if (!scope) {
                return;
            }

            normalizeTabLabels(scope);
            markElementorContainers(scope);
            scheduleSegmentedSliders(scope);
            updateDescriptionToggles(scope);
        });
    }

    function normalizeTabLabels(root) {
        root.querySelectorAll('[data-coupon-tab]').forEach(function (tab) {
            var existingLabel = tab.querySelector('.oyiso-coupons__tab-label');
            var count = tab.querySelector('.oyiso-coupons__tab-count');

            if (existingLabel || !count) {
                return;
            }

            var labelText = '';

            Array.from(tab.childNodes).forEach(function (node) {
                if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
                    labelText += node.textContent.trim();
                }
            });

            if (!labelText) {
                return;
            }

            Array.from(tab.childNodes).forEach(function (node) {
                if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
                    tab.removeChild(node);
                }
            });

            var label = document.createElement('span');
            label.className = 'oyiso-coupons__tab-label';
            label.textContent = labelText;
            tab.insertBefore(label, count);
        });
    }

    function updateDescriptionToggles(root) {
        root.querySelectorAll('[data-coupon-description]').forEach(updateDescriptionToggle);
    }

    function updateSegmentedSliders(root) {
        root.querySelectorAll('[data-oyiso-coupons]').forEach(updateSegmentedSlider);
    }

    function scheduleSegmentedSliders(root) {
        window.requestAnimationFrame(function () {
            updateSegmentedSliders(root);
        });

        window.setTimeout(function () {
            updateSegmentedSliders(root);
        }, 80);

        window.setTimeout(function () {
            updateSegmentedSliders(root);
        }, 240);
    }

    function observeSegmentedSliderChanges() {
        if (!window.MutationObserver || window.oyisoCouponsSliderObserver) {
            return;
        }

        window.oyisoCouponsSliderObserver = new MutationObserver(debounce(function () {
            scheduleSegmentedSliders(document);
        }, 60));

        window.oyisoCouponsSliderObserver.observe(document.body, {
            attributes: true,
            attributeFilter: ['class', 'style'],
            childList: true,
            subtree: true
        });
    }

    function updateSegmentedSlider(root) {
        var tabs = root.querySelector('.oyiso-coupons__tabs');
        var slider = tabs ? tabs.querySelector('[data-coupon-tabs-slider]') : null;
        var activeTab = tabs ? tabs.querySelector('[data-coupon-tab].is-active') : null;

        if (!tabs || !slider || !activeTab) {
            return;
        }

        var widget = root.closest('.elementor-widget-oyiso_coupons');

        if (!widget || !widget.classList.contains('oyiso-coupons-tabs-style-segmented')) {
            slider.style.setProperty('--oyiso-tabs-slider-opacity', '0');
            slider.classList.remove('is-ready');
            return;
        }

        window.requestAnimationFrame(function () {
            var isReady = slider.classList.contains('is-ready');
            var tabsRect = tabs.getBoundingClientRect();
            var tabRect = activeTab.getBoundingClientRect();
            var x = tabRect.left - tabsRect.left + tabs.scrollLeft;
            var y = tabRect.top - tabsRect.top + tabs.scrollTop;

            slider.style.setProperty('--oyiso-tabs-slider-x', x + 'px');
            slider.style.setProperty('--oyiso-tabs-slider-y', y + 'px');
            slider.style.setProperty('--oyiso-tabs-slider-width', tabRect.width + 'px');
            slider.style.setProperty('--oyiso-tabs-slider-height', tabRect.height + 'px');
            slider.style.setProperty('--oyiso-tabs-slider-opacity', '1');

            if (!isReady) {
                window.requestAnimationFrame(function () {
                    slider.classList.add('is-ready');
                });
            }
        });
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
            button.textContent = button.getAttribute('data-collapse-text') || getI18nString('collapse', 'Show Less');
            return;
        }

        button.setAttribute('aria-expanded', 'false');
        button.textContent = button.getAttribute('data-expand-text') || getI18nString('expand', 'Show More');
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
            ? button.getAttribute('data-collapse-text') || getI18nString('collapse', 'Show Less')
            : button.getAttribute('data-expand-text') || getI18nString('expand', 'Show More');
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

    function getI18nString(key, fallback) {
        var value = couponTabsI18n[key];

        return typeof value === 'string' && value !== '' ? value : fallback;
    }

    function formatI18nString(template, replacements) {
        return template.replace(/%(\d+\$)?s/g, function (match, indexToken) {
            var index = indexToken ? parseInt(indexToken, 10) - 1 : 0;

            return Object.prototype.hasOwnProperty.call(replacements, index) ? replacements[index] : match;
        });
    }

    function openScopeDialog(button) {
        var dialog = getScopeDialog();
        var title = dialog.querySelector('[data-coupon-scope-title]');
        var content = dialog.querySelector('[data-coupon-scope-content]');
        var code = button.getAttribute('data-coupon-code') || '';
        var root = button.closest('[data-oyiso-coupons]');
        var card = button.closest('.oyiso-coupon-card');
        var accentColor = root ? window.getComputedStyle(root).getPropertyValue('--oyiso-coupon-accent').trim() : '';
        var groupColor = card ? window.getComputedStyle(card).getPropertyValue('--oyiso-group-color').trim() : '';

        title.textContent = getI18nString('scopeTitle', 'Offer Details');
        content.innerHTML = buildScopeDialogContent(code, button.getAttribute('data-coupon-scope') || '');
        dialog.style.setProperty('--oyiso-coupon-accent', accentColor || '#e5702a');
        dialog.style.setProperty('--oyiso-scope-group-color', groupColor || accentColor || '#e5702a');
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
            '<div class="oyiso-scope-dialog__header">',
            '<h3 class="oyiso-scope-dialog__title" data-coupon-scope-title></h3>',
            '<button class="oyiso-scope-dialog__close" type="button" data-coupon-scope-close aria-label="' + escapeHtmlAttribute(getI18nString('closeLabel', 'Close')) + '"></button>',
            '</div>',
            '<div class="oyiso-scope-dialog__content" data-coupon-scope-content></div>',
            '</div>'
        ].join('');
        document.body.appendChild(dialog);

        return dialog;
    }

    function buildScopeDialogContent(code, scopeHtml) {
        var parts = [];

        if (code) {
            parts.push([
                '<section class="oyiso-scope-dialog__summary">',
                '<div class="oyiso-scope-dialog__summary-label">',
                escapeHtml(getI18nString('couponCodeLabel', 'Coupon Code')),
                '</div>',
                '<div class="oyiso-scope-dialog__summary-code">',
                escapeHtml(code),
                '</div>',
                '</section>'
            ].join(''));
        }

        if (scopeHtml) {
            parts.push(scopeHtml);
        }

        return parts.join('');
    }

    function copyCouponCode(button) {
        var code = button.getAttribute('data-coupon-copy') || '';

        if (!code) {
            return;
        }

        copyText(code).then(function () {
            var originalText = button.textContent;
            button.textContent = button.getAttribute('data-copied-text') || getI18nString('copied', 'Copied');
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

    function escapeHtmlAttribute(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function escapeHtml(value) {
        return escapeHtmlAttribute(value);
    }
})();
