<?php

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    /**
     * 辉哥发布器Yoast SEO插件适配
     */
    CSF::createSection($prefix, array(
        'title' => 'Yoast SEO插件适配',
        'fields' => array(
            array(
                'id' => 'opt-yoast-adapt',
                'type' => 'switcher',
                'title' => '辉哥原味文章发布器Yoast SEO插件适配',
                'label' => '开启后可通过API接口更新Yoast SEO的相关字段',
                'default' => false,
            ),
        )
    ));

    $options = get_option('oyiso');
    if (!is_array($options)) {
        return;
    }

    if (isset($options['opt-yoast-adapt']) && $options['opt-yoast-adapt'] == true) {
        /**
         * 注册 Yoast SEO 专用 REST API 端点
         * 端点: /wp-json/yoast-meta/v1/posts/{id}/update
         */
        add_action('rest_api_init', function () {
            register_rest_route('yoast-meta/v1', '/posts/(?P<id>\d+)/update', array(
                'methods' => 'POST',
                'callback' => 'update_yoast_seo_meta',
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
                'args' => array(
                    'id' => array(
                        'validate_callback' => function ($param) {
                            return is_numeric($param);
                        }
                    ),
                ),
            ));

            // 获取 Yoast SEO meta 的端点
            register_rest_route('yoast-meta/v1', '/posts/(?P<id>\d+)', array(
                'methods' => 'GET',
                'callback' => 'get_yoast_seo_meta',
                'permission_callback' => function () {
                    return current_user_can('read');
                },
                'args' => array(
                    'id' => array(
                        'validate_callback' => function ($param) {
                            return is_numeric($param);
                        }
                    ),
                ),
            ));
        });

        /**
         * 更新 Yoast SEO meta 字段
         */
        function update_yoast_seo_meta($request) {
            $post_id = $request['id'];

            // 检查文章是否存在
            $post = get_post($post_id);
            if (!$post) {
                return new WP_Error('not_found', 'Post not found', array('status' => 404));
            }

            // 检查权限
            if (!current_user_can('edit_post', $post_id)) {
                return new WP_Error('forbidden', 'You cannot edit this post', array('status' => 403));
            }

            $body = $request->get_json_params();
            $updated_fields = array();

            // 支持的 Yoast SEO 字段映射
            $field_mapping = array(
                'focuskw' => '_yoast_wpseo_focuskw',
                // 'title' => '_yoast_wpseo_title',
                'metadesc' => '_yoast_wpseo_metadesc',
                // 也支持直接使用完整字段名
                '_yoast_wpseo_focuskw' => '_yoast_wpseo_focuskw',
                // '_yoast_wpseo_title' => '_yoast_wpseo_title',
                '_yoast_wpseo_metadesc' => '_yoast_wpseo_metadesc',
            );

            foreach ($field_mapping as $input_key => $meta_key) {
                if (isset($body[$input_key]) && !empty($body[$input_key])) {
                    $value = sanitize_text_field($body[$input_key]);
                    update_post_meta($post_id, $meta_key, $value);
                    $updated_fields[$meta_key] = $value;
                }
            }

            // 返回更新结果
            return array(
                'success' => true,
                'post_id' => $post_id,
                'updated_fields' => $updated_fields,
                'message' => 'Yoast SEO meta fields updated successfully',
            );
        }

        /**
         * 获取 Yoast SEO meta 字段
         */
        function get_yoast_seo_meta($request) {
            $post_id = $request['id'];

            // 检查文章是否存在
            $post = get_post($post_id);
            if (!$post) {
                return new WP_Error('not_found', 'Post not found', array('status' => 404));
            }

            return array(
                'post_id' => $post_id,
                'focuskw' => get_post_meta($post_id, '_yoast_wpseo_focuskw', true),
                // 'title' => get_post_meta($post_id, '_yoast_wpseo_title', true),
                'metadesc' => get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
            );
        }

        add_action('init', function () {
            $yoast_fields = array(
                '_yoast_wpseo_focuskw' => 'Focus keyphrase',
                // '_yoast_wpseo_title' => 'SEO Title',
                '_yoast_wpseo_metadesc' => 'Meta Description',
            );

            $post_types = array('post', 'page');

            foreach ($yoast_fields as $meta_key => $description) {
                foreach ($post_types as $post_type) {
                    register_post_meta($post_type, $meta_key, array(
                        'show_in_rest' => true,
                        'single' => true,
                        'type' => 'string',
                        'description' => $description,
                        'auth_callback' => function () {
                            return current_user_can('edit_posts');
                        },
                        'sanitize_callback' => 'sanitize_text_field',
                    ));
                }
            }
        });
    }
}
