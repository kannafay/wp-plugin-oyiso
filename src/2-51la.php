<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    /**
     * 51LA统计代码
     */
    CSF::createSection($prefix, array(
        'title' => '51LA统计代码',
        'fields' => array(
            array(
                'id' => 'opt-51la-code',
                'type' => 'code_editor',
                'title' => 'HTML代码',
                'sanitize' => false,
            ),
        )
    ));

    global $oyiso_options;

    if ($code51la = $oyiso_options['opt-51la-code']) {
        add_action('wp_head', function () use ($code51la) {
            echo $code51la;
        });
    }
}
