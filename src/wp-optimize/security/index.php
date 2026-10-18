<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

require_once __DIR__ . '/login-protection.php';
require_once __DIR__ . '/xmlrpc-protection.php';
require_once __DIR__ . '/comments-protection.php';

/**
 * 安全防护
 */
if (class_exists('CSF')) {
CSF::createSection($prefix, [
    'parent'   => 'wp-optimize',
    'id'       => 'security',
    'title'    => '安全防护',
    'icon'     => 'fas fa-shield-alt',
    'priority' => 20,
    'fields' => [
        [
            'type' => 'heading',
            'content' => '安全防护',
        ],
        [
            'id' => 'opt-disable-pingback',
            'type' => 'switcher',
            'title' => 'Pingback 防护',
            'label' => '开启后关闭网站的 Pingback 和 Trackback，防止垃圾引用通知',
            'default' => false,
        ],
        [
            'id' => 'opt-disable-xmlrpc',
            'type' => 'switcher',
            'title' => 'XML-RPC 防护',
            'label' => '关闭 XML-RPC 访问入口，阻止通过该接口进行登录和远程调用',
            'desc' => '启用后会阻止 XML-RPC 请求，可能影响 Jetpack、WordPress 移动端及远程发布。REST API 不受影响。',
            'default' => false,
        ],
        [
            'id' => 'opt-disable-file-edit',
            'type' => 'switcher',
            'title' => '后台文件编辑防护',
            'label' => '关闭后台主题和插件文件编辑器，不影响安装和更新',
            'default' => false,
        ],
        [
            'id' => 'opt-checkout-input-protection',
            'type' => 'switcher',
            'title' => '结账恶意输入拦截',
            'label' => '拦截结账字段中的目录穿越、HTML 与脚本注入、空字符和异常超长输入',
            'desc' => '适用于 WooCommerce 传统结账及区块结账；姓名、地址和备注仅接受纯文本，不允许 HTML 标签、事件属性或脚本链接。',
            'default' => false,
        ],
        [
            'id' => 'opt-disable-comments',
            'type' => 'switcher',
            'title' => '禁用评论',
            'label' => '关闭全站或指定文章类型的评论、Trackback 和 Pingback',
            'default' => false,
        ],
        [
            'id' => 'opt-disable-comments-scope',
            'type' => 'radio',
            'title' => '禁用范围',
            'inline' => true,
            'options' => ['all' => '全站禁用', 'selected' => '指定文章类型'],
            'default' => 'selected',
            'dependency' => ['opt-disable-comments', '==', true],
        ],
        [
            'id' => 'opt-disable-comments-post-types',
            'type' => 'checkbox',
            'title' => '禁用的文章类型',
            'inline' => true,
            'options' => 'Oyiso_Comments_Protection::postTypeOptions',
            'sanitize' => [Oyiso_Comments_Protection::class, 'sanitizePostTypes'],
            'desc' => '勾选要禁用的类型，列表动态读取当前站点。要保留商品评价，请勿勾选 Products（商品）。',
            'default' => [],
            'dependency' => ['opt-disable-comments|opt-disable-comments-scope', '==|==', 'true|selected'],
        ],
        [
            'id' => 'opt-disable-comments-show-existing',
            'type' => 'switcher',
            'title' => '显示已有评论',
            'label' => '继续在前台显示已禁用类型的已有评论',
            'desc' => '关闭后隐藏已有评论及评论数量，评论记录仍保留，可在后台管理。',
            'default' => true,
            'dependency' => ['opt-disable-comments', '==', true],
        ],
        [
            'id' => 'opt-disable-comments-xmlrpc',
            'type' => 'switcher',
            'title' => '禁用 XML-RPC 评论提交',
            'label' => '禁止通过 XML-RPC 新增评论和 Pingback',
            'desc' => '对所有文章类型生效，其他 XML-RPC 功能仍由原有设置控制。',
            'default' => false,
            'dependency' => ['opt-disable-comments', '==', true],
        ],
        [
            'id' => 'opt-disable-comments-rest',
            'type' => 'switcher',
            'title' => '禁用 REST API 评论提交',
            'label' => '禁止通过 WordPress 和 WooCommerce REST API 新增评论及商品评价',
            'desc' => '对所有文章类型生效，包括未勾选禁用的类型；已有评论的后台管理仍可使用。',
            'default' => false,
            'dependency' => ['opt-disable-comments', '==', true],
        ],
        [
            'id' => 'opt-limit-login',
            'type' => 'switcher',
            'title' => '登录尝试限制',
            'label' => '同一 IP 登录失败达到上限后，暂时限制登录',
            'desc' => '保护 WordPress 和 WooCommerce 的网页登录。使用 CDN 或反向代理时，请先在服务器配置真实访客 IP。',
            'default' => false,
        ],
        [
            'id' => 'opt-login-max-attempts',
            'type' => 'number',
            'title' => '失败次数上限',
            'unit' => '次',
            'min' => 3,
            'max' => 20,
            'step' => 1,
            'default' => 5,
            'sanitize' => [Oyiso_Login_Protection::class, 'sanitizeAttempts'],
            'dependency' => ['opt-limit-login', '==', true],
        ],
        [
            'id' => 'opt-login-window-minutes',
            'type' => 'number',
            'title' => '失败统计时段',
            'unit' => '分钟',
            'min' => 1,
            'max' => 60,
            'step' => 1,
            'default' => 15,
            'sanitize' => [Oyiso_Login_Protection::class, 'sanitizeWindow'],
            'dependency' => ['opt-limit-login', '==', true],
        ],
        [
            'id' => 'opt-login-lock-minutes',
            'type' => 'number',
            'title' => '限制时长',
            'unit' => '分钟',
            'min' => 1,
            'max' => 1440,
            'step' => 1,
            'default' => 15,
            'sanitize' => [Oyiso_Login_Protection::class, 'sanitizeLock'],
            'desc' => '到期自动解除；限制期间的请求不会延长时间。',
            'dependency' => ['opt-limit-login', '==', true],
        ],
        [
            'type' => 'callback',
            'title' => '手动解除',
            'function' => [Oyiso_Login_Protection::class, 'renderUnlockButton'],
            'dependency' => ['opt-limit-login', '==', true],
        ],
    ]
]);
}

if (!empty($options['opt-disable-file-edit'])) {
    add_filter('map_meta_cap', static function (array $caps, string $cap): array {
        if (in_array($cap, ['edit_themes', 'edit_plugins', 'edit_files'], true)) {
            $caps[] = 'do_not_allow';
        }
        return $caps;
    }, PHP_INT_MAX, 2);
}

Oyiso_Login_Protection::register(is_array($options) ? $options : []);
Oyiso_Xmlrpc_Protection::register(!empty($options['opt-disable-xmlrpc']));
Oyiso_Comments_Protection::register(is_array($options) ? $options : []);

if (!empty($options['opt-disable-pingback'])) {
    add_filter('pings_open', '__return_false');

    // 移除 WP 核心输出的 pingback 相关 head 标签
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');

    // 移除 X-Pingback 响应头
    add_action('send_headers', function () {
        header_remove('X-Pingback');
    });

    // 兜底：用输出缓冲移除主题硬编码的 <link rel="pingback">
    add_action('template_redirect', function () {
        ob_start(function ($html) {
            return preg_replace('/\s*<link\s+rel=[\'"]pingback[\'"][^>]*>\s*/i', '', $html);
        });
    }, 1);

    // 移除 XML-RPC pingback 接口
    add_filter('xmlrpc_methods', function ($methods) {
        unset($methods['pingback.ping']);
        unset($methods['pingback.extensions.getPingbacks']);
        return $methods;
    });
}
