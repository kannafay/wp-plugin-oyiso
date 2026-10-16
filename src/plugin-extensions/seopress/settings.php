<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('CSF')) {
    return;
}

CSF::createSection('oyiso', [
    'parent' => 'plugin-extensions',
    'id' => 'seopress',
    'title' => 'SEOPress',
    'icon' => 'fas fa-search',
    'priority' => 30,
    'fields' => [
        [
            'type' => 'heading',
            'content' => 'SEOPress',
        ],
        [
            'id' => Oyiso_SEOPress::OPTION_REMOVE_ARCHIVE_LINKS,
            'type' => 'switcher',
            'title' => '站点地图归档去重',
            'label' => '移除文章、产品 XML 站点地图中附带的 Blog、Shop 归档首页链接。',
            'desc' => '保留页面站点地图中的 Blog、Shop，以及所有文章和产品详情链接。仅在 SEOPress XML 站点地图中生效。',
            'default' => false,
        ],
    ],
]);
