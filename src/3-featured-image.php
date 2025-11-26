<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    /**
     * 51LA统计代码
     */
    CSF::createSection($prefix, array(
        'title' => '特色图片',
        'fields' => array(
            array(
                'id' => 'opt-featured-img',
                'type' => 'code_editor',
                'title' => 'HTML代码',
                'sanitize' => false,
            ),
        )
    ));

    global $oyiso_options;

}