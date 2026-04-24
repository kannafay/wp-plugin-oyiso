<?php

// 统一获取选项，子模块共享此变量
$options = get_option('oyiso', []);

// CSF 后台 UI 定义（前端 class_exists('CSF') 为 false，整块跳过）
if (class_exists('CSF')) {

    $prefix = 'oyiso';

    CSF::createOptions($prefix, [
        'menu_title' => '橘子猫头',
        'menu_slug' => 'oyiso',
        'menu_type' => 'submenu',
        'menu_parent' => 'plugins.php',
        'theme' => 'light',
        'footer_after' => '
            <style>
            .csf-nav-options .csf-tab-item > ul {
                display: none;
            }
            .csf-nav-options .csf-tab-item.csf-tab-expanded > ul {
                display: block;
            }
            #wpfooter {
                display: none;
            }
            #wpbody-content {
                padding-bottom: 0;
            }
            #wpcontent,
            .auto-fold #wpcontent {
                padding-left: 0;
            }
            .csf.csf-options {
                min-height: calc(100vh - 32px - 40px);
                margin: 20px;
                display: flex;
                flex-direction: column;
            }
            .csf.csf-options > .csf-container {
                flex: 1;
                display: flex;
                flex-direction: column;
            }
            .csf.csf-options #csf-form {
                flex: 1;
                display: flex;
                flex-direction: column;
            }
            .csf.csf-options .csf-wrapper {
                flex: 1;
                position: relative;
                overflow: hidden;
                background-color: #fff;
            }
            .csf.csf-options .csf-nav {
                --oyiso-nav-accent-width: 2px;
                position: absolute;
                top: 0;
                left: 0;
                bottom: 0;
                width: 225px;
                overflow-x: hidden;
                overflow-y: auto;
                z-index: 10;
            }
            .csf.csf-options .csf-nav .csf-arrow:after {
                transition: transform .2s ease;
            }
            /* ── 一级菜单 ── */
            .csf.csf-options .csf-nav > ul > li > a {
                position: relative;
                background-color: #fafafa;
                color: #1d1d1d;
                /* font-weight: 600; */
                transition: background-color .2s ease, color .2s ease;
            }
            .csf.csf-options .csf-nav > ul > li > a:hover {
                background-color: #fff;
            }
            .csf.csf-options .csf-nav > ul > li > a::before {
                content: "";
                position: absolute;
                top: 0;
                bottom: 0;
                left: 0;
                width: var(--oyiso-nav-accent-width);
                background-color: #e5702a;
                opacity: 0;
                pointer-events: none;
                transform: scaleY(0);
                transform-origin: bottom;
                transition: opacity .2s ease, transform .2s ease;
            }
            .csf.csf-options .csf-nav > ul > li.csf-parent-active:not(.csf-tab-expanded) > a {
                background-color: #fff;
            }
            .csf.csf-options .csf-nav > ul > li.csf-parent-active:not(.csf-tab-expanded) > a::before {
                opacity: 1;
                transform: scaleY(1);
            }
            /* ── 二级菜单 ── */
            .csf.csf-options .csf-nav > ul > li > ul > li > a {
                position: relative;
                background-color: #ebebeb;
                color: #555;
                font-size: 12.5px;
                padding-left: calc(24px + var(--oyiso-nav-accent-width));
                border-left: 0;
                transition: background-color .2s ease, color .2s ease;
            }
            .csf.csf-options .csf-nav > ul > li > ul > li > a::before {
                content: "";
                position: absolute;
                top: 0;
                bottom: 0;
                left: 0;
                width: var(--oyiso-nav-accent-width);
                background-color: #e5702a;
                opacity: 0;
                pointer-events: none;
                transform: scaleX(0);
                transform-origin: left;
                transition: opacity .2s ease, transform .2s ease;
            }
            .csf.csf-options .csf-nav > ul > li > ul > li > a:hover {
                background-color: #f2f2f2;
                color: #333;
            }
            .csf.csf-options .csf-nav > ul > li > ul > li > a.csf-active {
                background-color: #f5f5f5;
                color: #1d1d1d;
            }
            .csf.csf-options .csf-nav > ul > li > ul > li > a.csf-active::before {
                opacity: 1;
                transform: scaleX(1);
            }
            .csf.csf-options .csf-content {
                min-height: 100%;
            }
            .csf.csf-options .csf-field-heading {
                border-bottom: 1px solid #e0e0e0;
            }
            .csf.csf-options .csf-field-heading + .csf-field {
                border-top: none;
            }
            .csf.csf-options .csf-field-text input,
            .csf.csf-options .csf-field-textarea textarea {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }
            @media only screen and (max-width: 782px) {
                .csf.csf-options {
                    margin: 10px;
                    min-height: auto;
                }
                .csf.csf-options .csf-field .csf-title,
                .csf.csf-options .csf-field .csf-fieldset {
                    float: none;
                    width: 100%;
                }
                .csf.csf-options .csf-field .csf-title {
                    margin-bottom: 8px;
                }
                .csf.csf-options .csf-field-text input,
                .csf.csf-options .csf-field-textarea textarea {
                    width: 100%;
                    max-width: 100%;
                }
            }
            </style>
            <script>
            jQuery(function($){
                var $nav = $(".csf-nav-options");

                $nav.find(".csf-tab-item > ul:visible").closest(".csf-tab-item").addClass("csf-tab-expanded");

                function updateActiveParents(){
                    var $activeLinks = $nav.find("ul ul a.csf-active");

                    if (!$activeLinks.length && window.location.hash.indexOf("tab=") !== -1) {
                        var tabId = window.location.hash.replace(/^#tab=/, "");
                        $activeLinks = $nav.find("ul ul a[data-tab-id=\"" + tabId + "\"]");
                    }

                    if (!$activeLinks.length) {
                        var sectionId = $(".csf-section.csf-onload:not(.hidden), .csf-section:not(.hidden)").first().data("section-id");
                        if (sectionId) {
                            $activeLinks = $nav.find("ul ul a[data-tab-id=\"" + sectionId + "\"]");
                        }
                    }

                    $nav.find(".csf-tab-item").removeClass("csf-parent-active");
                    $activeLinks.closest(".csf-tab-item").addClass("csf-parent-active");
                }

                updateActiveParents();
                setTimeout(updateActiveParents, 100);

                // 一级菜单点击：展开/折叠
                $nav.on("click", ".csf-arrow", function(e){
                    e.preventDefault();
                    e.stopPropagation();
                    updateActiveParents();

                    var $item = $(this).closest(".csf-tab-item");
                    var isOpen = $item.hasClass("csf-tab-expanded");

                    $item.siblings(".csf-tab-expanded").each(function(){
                        var $sibling = $(this);
                        var $submenu = $sibling.find("> ul");

                        $submenu.stop(true, true).css("display", "block");
                        $sibling.removeClass("csf-tab-expanded");
                        $submenu.slideUp(200);
                    });

                    if (isOpen) {
                        $item.find("> ul").stop(true, true).css("display", "block");
                        $item.removeClass("csf-tab-expanded");
                        $item.find("> ul").slideUp(200);
                        return;
                    }

                    $item.find("> ul").stop(true, true).hide();
                    $item.addClass("csf-tab-expanded");
                    $item.find("> ul").slideDown(200);
                });

                // 二级菜单点击：手动切换面板，用 replaceState 不触发 hashchange
                $nav.on("click", "ul ul a", function(e){
                    e.preventDefault();
                    e.stopImmediatePropagation();

                    var $this = $(this);
                    var tabId = $this.data("tab-id");

                    // 激活当前链接
                    $nav.find("a").removeClass("csf-active");
                    $this.addClass("csf-active");
                    updateActiveParents();

                    // 展开所属父级
                    $this.closest(".csf-tab-item").addClass("csf-tab-expanded").find("> ul").show();

                    // 切换右侧面板
                    $(".csf-section").removeClass("csf-onload").addClass("hidden");
                    var $section = $("[data-section-id=\"" + tabId + "\"]");
                    $section.removeClass("hidden").addClass("csf-onload");
                    $section.csf_reload_script();
                    $(".csf-section-id").val($section.index() + 1);

                    // 更新 URL，不触发 hashchange
                    history.replaceState(null, null, "#tab=" + tabId);
                });
            });
            </script>',
    ]);

    // 父级分类（仅导航，无字段）
    CSF::createSection($prefix, [
        'id'       => 'wp-optimize',
        'title'    => 'WordPress 优化',
        'icon'     => 'fab fa-wordpress',
        'priority' => 10,
    ]);

    CSF::createSection($prefix, [
        'id'       => 'seo-analytics',
        'title'    => 'SEO 与统计',
        'icon'     => 'fas fa-chart-bar',
        'priority' => 20,
    ]);

    CSF::createSection($prefix, [
        'id'       => 'notifications',
        'title'    => '通知与集成',
        'icon'     => 'fas fa-bell',
        'priority' => 30,
    ]);

    CSF::createSection($prefix, [
        'id'       => 'plugin-extensions',
        'title'    => '插件扩展',
        'icon'     => 'fas fa-puzzle-piece',
        'priority' => 40,
    ]);

} // end CSF UI block

// 加载模块（功能钩子在前后端均需注册，CSF 调用由模块内部自行 guard）
$dir = plugin_dir_path(__FILE__);
require_once $dir . 'gutenberg-editor/index.php';
require_once $dir . 'wp-update/index.php';
require_once $dir . '51la-analytics/index.php';
require_once $dir . 'telegram/index.php';
require_once $dir . 'elementor-widgets/index.php';
