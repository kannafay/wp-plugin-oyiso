(function ($) {
    'use strict';

    $(function () {
        $('.csf-options[data-unique="oyiso"]').each(function () {
            var $root = $(this);
            var groups = [];
            var nextId = 0;

            $root.find('.csf-field-switcher').each(function () {
                var $field = $(this);
                var $input = $field.find('.csf--switcher > input[data-depend-id]').first();
                var controller = $input.attr('data-depend-id');

                // Keep native CSF fields in place so dependencies and saved names stay intact.
                if (!controller || $field.closest('.csf-cloneable-item, .csf-cloneable-hidden').length) {
                    return;
                }

                var $details = $field.siblings('.csf-field[data-controller]').filter(function () {
                    var controllers = $(this).attr('data-controller').split('|');
                    var conditions = $(this).attr('data-condition').split('|');
                    var values = $(this).attr('data-value').split('|');
                    var index = controllers.indexOf(controller);

                    return index !== -1
                        && (conditions[index] || conditions[0]) === '=='
                        && /^(1|true)$/.test(values[index]);
                });

                if (!$details.length) {
                    return;
                }

                var $composites = $details.filter('.csf-field-tabbed, .csf-field-group, .csf-field-fieldset, .csf-field-accordion, .csf-field-repeater');
                var hasLargeField = $composites.length > 0;
                var collapsible = $details.length >= 3 || hasLargeField;

                $composites.addClass('oyiso-config-composite');

                var title = $field.children('.csf-title').children('h4').text().trim();
                // Small groups share the same hierarchy without adding another fold button.
                var expanded = !collapsible || $details.find('.csf-error-text').length > 0;
                var enabled = $input.val() === '1';
                var controls = [];
                var searchTitles = [];
                var $button = collapsible ? $('<button type="button" class="button-link oyiso-config-toggle">'
                    + '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>'
                    + '<span class="oyiso-config-toggle-text"></span></button>') : $();

                $field.addClass('oyiso-config-parent');

                $details.each(function () {
                    if (!this.id) {
                        this.id = 'oyiso-config-detail-' + (++nextId);
                    }
                    controls.push(this.id);

                    var titles = $(this).find('.csf-title h4').map(function () {
                        return $(this).text();
                    }).get().join(' ');
                    searchTitles.push(titles);
                    $('<span class="csf-search-tags hidden"></span>').text(titles).appendTo(this);
                }).addClass('oyiso-config-details');

                $button.attr('aria-controls', controls.join(' '));
                $field.children('.csf-fieldset').prepend($button);

                // Include detail titles in CSF's existing settings search.
                $('<span class="csf-search-tags hidden"></span>')
                    .text(searchTitles.join(' ')).appendTo($field);

                function updateBoundaries() {
                    // Ignore section/tab visibility, but follow CSF's conditional rows and search.
                    var $visible = $details.not('.oyiso-config-collapsed, .csf-depend-hidden.csf-depend-on, .csf-metabox-hide');
                    var first = $visible[0];
                    var last = $visible[$visible.length - 1];

                    $details.each(function () {
                        $(this).toggleClass('oyiso-config-details-first', this === first)
                            .toggleClass('oyiso-config-details-last', this === last);
                    });
                }

                function render() {
                    var open = enabled && expanded;
                    var label = open ? '收起配置' : '展开配置';

                    $field.toggleClass('oyiso-config-open', open);
                    $details.toggleClass('oyiso-config-collapsed', !open);
                    $button.prop('hidden', !enabled).attr({
                        'aria-expanded': open ? 'true' : 'false',
                        'aria-label': title + '：' + label
                    }).find('.oyiso-config-toggle-text').text(label);
                    updateBoundaries();
                }

                function open() {
                    if (enabled && !expanded) {
                        expanded = true;
                        render();
                    }
                }

                $button.on('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    expanded = !expanded;
                    render();
                }).on('keypress', function (event) {
                    // Folding alone must not trigger CSF's unsaved-settings warning.
                    event.stopPropagation();
                });

                $input.on('change.oyisoConfigCollapse', function () {
                    var active = $input.val() === '1';
                    if (active !== enabled) {
                        expanded = active;
                    }
                    enabled = active;
                    render();
                });

                groups.push({details: $details, open: open, updateBoundaries: updateBoundaries});
                render();
            });

            $root.find('.csf-search input').on('change.oyisoConfigCollapse keyup.oyisoConfigCollapse', function () {
                if ($(this).val().length > 3) {
                    groups.forEach(function (group) {
                        group.open();
                    });
                }
            });

            // Reveal validation messages inserted by CSF's AJAX save handler.
            new MutationObserver(function (mutations) {
                var visibilityChanged = mutations.some(function (mutation) {
                    if (mutation.type !== 'attributes' || !mutation.target.matches('.oyiso-config-details')) {
                        return false;
                    }

                    var previous = (mutation.oldValue || '').split(/\s+/);
                    return ['csf-depend-on', 'csf-metabox-hide'].some(function (className) {
                        return mutation.target.classList.contains(className) !== previous.includes(className);
                    });
                });
                var hasNewError = mutations.some(function (mutation) {
                    return Array.from(mutation.addedNodes).some(function (node) {
                        return node.nodeType === 1
                            && (node.matches('.csf-error-text') || node.querySelector('.csf-error-text'));
                    });
                });

                groups.forEach(function (group) {
                    if (hasNewError && group.details.find('.csf-error-text').length) {
                        group.open();
                    }
                    if (visibilityChanged) {
                        group.updateBoundaries();
                    }
                });
            }).observe(this, {childList: true, subtree: true, attributes: true, attributeFilter: ['class'], attributeOldValue: true});
        });
    });
})(jQuery);
