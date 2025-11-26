<?php

defined(constant_name: 'ABSPATH') || exit;

if (class_exists('CSF')) {
    /**
     * 古腾堡编辑器
     */
    CSF::createSection($prefix, array(
        'title' => '古腾堡编辑器',
        'fields' => array(
            array(
                'id' => 'opt-gutenberg-editor',
                'type' => 'switcher',
                'title' => '古腾堡编辑器',
                'label' => '启用/禁用古腾堡编辑器，禁用后将使用经典编辑器',
                'default' => false
            ),
        )
    ));

    global $oyiso_options;

    if ($oyiso_options['opt-gutenberg-editor'] == false) {
        // 禁用古腾堡编辑器
        add_filter('use_block_editor_for_post', '__return_false', 10);

        // 移除古腾堡相关样式
        remove_action('wp_enqueue_scripts', 'wp_common_block_scripts_and_styles');
    }
}
