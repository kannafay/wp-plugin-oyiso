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

    $options = get_option('oyiso');
    if (!is_array($options)) {
        return;
    }

    if ($code51la = $options['opt-51la-code']) {
        add_action('wp_head', function () use ($code51la) {
            echo $code51la;
        });
    }
}
