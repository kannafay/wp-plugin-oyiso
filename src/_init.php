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
                background-color: #fafafa;
                color: #1d1d1d;
                font-weight: 600;
            }
            .csf.csf-options .csf-nav > ul > li > a:hover {
                background-color: #fff;
            }
            /* ── 二级菜单 ── */
            .csf.csf-options .csf-nav > ul > li > ul > li > a {
                background-color: #ebebeb;
                color: #555;
                font-size: 12.5px;
                border-left: 3px solid transparent;
                transition: border-left-color .2s ease, background-color .2s ease, color .2s ease;
            }
            .csf.csf-options .csf-nav > ul > li > ul > li > a:hover {
                background-color: #f2f2f2;
                color: #333;
            }
            .csf.csf-options .csf-nav > ul > li > ul > li > a.csf-active {
                background-color: #f5f5f5;
                color: #1d1d1d;
                border-left-color: #e5702a;
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
            </style>
            <script>
            jQuery(function($){
                var $nav = $(".csf-nav-options");

                // 一级菜单点击：展开/折叠
                $nav.on("click", ".csf-arrow", function(e){
                    e.preventDefault();
                    e.stopPropagation();
                    var $item = $(this).closest(".csf-tab-item");
                    $item.find("> ul").slideToggle(200, function(){
                        $item.toggleClass("csf-tab-expanded", $(this).is(":visible"));
                    });
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

} // end CSF UI block

// 加载模块（功能钩子在前后端均需注册，CSF 调用由模块内部自行 guard）
$dir = plugin_dir_path(__FILE__);
require_once $dir . 'gutenberg-editor/index.php';
require_once $dir . 'wp-update/index.php';
require_once $dir . '51la-analytics/index.php';
require_once $dir . 'telegram/index.php';
