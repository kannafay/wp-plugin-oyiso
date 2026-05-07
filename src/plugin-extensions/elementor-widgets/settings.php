<?php

defined('ABSPATH') || exit;

if (!class_exists('CSF')) {
    return;
}

CSF::createSection($prefix, [
    'parent' => 'plugin-extensions',
    'id' => 'elementor-widgets',
    'title' => 'Elementor',
    'icon' => 'fab fa-elementor',
    'priority' => 10,
    'fields' => [
        [
            'type' => 'heading',
            'content' => 'Elementor',
        ],
        [
            'type' => 'subheading',
            'content' => '小部件',
        ],
        [
            'id' => 'opt-elementor-widgets',
            'type' => 'switcher',
            'title' => '启用小部件',
            'label' => '开启后将在 Elementor 编辑器中启用橘子猫头小部件分类及相关组件。',
            'default' => true,
        ],
    ],
]);
