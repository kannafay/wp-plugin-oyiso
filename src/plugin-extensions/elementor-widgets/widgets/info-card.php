<?php

namespace Oyiso\ElementorWidgets;

defined('ABSPATH') || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

class Info_Card extends Widget_Base
{
    private function get_site_locale(): string
    {
        $site_locale = function_exists('get_locale') ? (string) get_locale() : '';

        if (!$site_locale && function_exists('get_bloginfo')) {
            $site_language = (string) get_bloginfo('language');

            if ($site_language) {
                $site_locale = str_replace('-', '_', $site_language);
            }
        }

        return $site_locale ?: 'en_US';
    }

    private function translate_for_locale(string $text, string $locale): string
    {
        static $catalogs = [];
        $mofile = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'oyiso-' . $locale . '.mo';

        if (!is_readable($mofile)) {
            return $text;
        }

        if (!array_key_exists($locale, $catalogs)) {
            if (!class_exists('\MO')) {
                require_once ABSPATH . WPINC . '/pomo/mo.php';
            }

            $mo = new \MO();
            $catalogs[$locale] = $mo->import_from_file($mofile) ? $mo : false;
        }

        if (!$catalogs[$locale]) {
            return $text;
        }

        $translated = $catalogs[$locale]->translate($text);

        return is_string($translated) && $translated !== '' ? $translated : $text;
    }

    private function get_site_default_text(string $text): string
    {
        if (function_exists('oyiso_t')) {
            return oyiso_t($text);
        }

        return $this->translate_for_locale($text, $this->get_site_locale());
    }

    public function get_name()
    {
        return 'oyiso_info_card';
    }

    public function get_title()
    {
        return __('Oyiso Info Card', 'oyiso');
    }

    public function get_icon()
    {
        return 'eicon-info-box';
    }

    public function get_categories()
    {
        return ['oyiso'];
    }

    public function get_style_depends()
    {
        return ['oyiso-elementor-widgets'];
    }

    protected function register_controls()
    {
        $this->start_controls_section('content_section', [
            'label' => __('内容', 'oyiso'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('title', [
            'label'       => __('标题', 'oyiso'),
            'type'        => Controls_Manager::TEXT,
            'default'     => $this->get_site_default_text('Your Card Title'),
            'placeholder' => $this->get_site_default_text('Enter a title'),
        ]);

        $this->add_control('description', [
            'label'       => __('描述', 'oyiso'),
            'type'        => Controls_Manager::TEXTAREA,
            'default'     => $this->get_site_default_text('This is a description you can edit in Elementor.'),
            'placeholder' => $this->get_site_default_text('Enter a description'),
        ]);

        $this->add_control('link', [
            'label'       => __('链接', 'oyiso'),
            'type'        => Controls_Manager::URL,
            'placeholder' => 'https://example.com',
        ]);

        $this->add_control('button_text', [
            'label'   => __('按钮文字', 'oyiso'),
            'type'    => Controls_Manager::TEXT,
            'default' => $this->get_site_default_text('Learn More'),
        ]);

        $this->add_responsive_control('content_alignment', [
            'label'     => __('内容对齐', 'oyiso'),
            'type'      => Controls_Manager::CHOOSE,
            'options'   => [
                'left'   => [
                    'title' => __('左对齐', 'oyiso'),
                    'icon'  => 'eicon-text-align-left',
                ],
                'center' => [
                    'title' => __('居中', 'oyiso'),
                    'icon'  => 'eicon-text-align-center',
                ],
                'right'  => [
                    'title' => __('右对齐', 'oyiso'),
                    'icon'  => 'eicon-text-align-right',
                ],
            ],
            'default'   => 'left',
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card' => 'text-align: {{VALUE}};',
            ],
        ]);

        $this->end_controls_section();

        $this->start_controls_section('card_style_section', [
            'label' => __('卡片', 'oyiso'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('accent_color', [
            'label'     => __('强调色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'default'   => '#e5702a',
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card' => '--oyiso-accent-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('card_background_color', [
            'label'     => __('背景颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card' => 'background: {{VALUE}};',
            ],
        ]);

        $this->add_control('card_border_color', [
            'label'     => __('边框颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card' => 'border-color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Border::get_type(), [
            'name'     => 'card_border',
            'selector' => '{{WRAPPER}} .oyiso-info-card',
        ]);

        $this->add_responsive_control('card_border_radius', [
            'label'      => __('圆角', 'oyiso'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-info-card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('card_padding', [
            'label'      => __('内边距', 'oyiso'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-info-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Box_Shadow::get_type(), [
            'name'     => 'card_box_shadow',
            'selector' => '{{WRAPPER}} .oyiso-info-card',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('title_style_section', [
            'label' => __('标题', 'oyiso'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('title_color', [
            'label'     => __('文字颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card__title' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name'     => 'title_typography',
            'label'    => __('标题排版', 'oyiso'),
            'selector' => '{{WRAPPER}} .oyiso-info-card__title',
        ]);

        $this->add_responsive_control('title_spacing', [
            'label'      => __('底部间距', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-info-card__title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        $this->start_controls_section('description_style_section', [
            'label' => __('描述', 'oyiso'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('description_color', [
            'label'     => __('文字颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card__description' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name'     => 'description_typography',
            'label'    => __('描述排版', 'oyiso'),
            'selector' => '{{WRAPPER}} .oyiso-info-card__description',
        ]);

        $this->add_responsive_control('description_spacing', [
            'label'      => __('底部间距', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-info-card__description' => 'margin-bottom: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        $this->start_controls_section('button_style_section', [
            'label' => __('按钮', 'oyiso'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name'     => 'button_typography',
            'label'    => __('按钮排版', 'oyiso'),
            'selector' => '{{WRAPPER}} .oyiso-info-card__button',
        ]);

        $this->start_controls_tabs('button_style_tabs');

        $this->start_controls_tab('button_style_normal', [
            'label' => __('正常', 'oyiso'),
        ]);

        $this->add_control('button_text_color', [
            'label'     => __('文字颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card__button' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('button_background_color', [
            'label'     => __('背景颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card__button' => 'background: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->start_controls_tab('button_style_hover', [
            'label' => __('悬停', 'oyiso'),
        ]);

        $this->add_control('button_hover_text_color', [
            'label'     => __('文字颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card__button:hover, {{WRAPPER}} .oyiso-info-card__button:focus' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('button_hover_background_color', [
            'label'     => __('背景颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card__button:hover, {{WRAPPER}} .oyiso-info-card__button:focus' => 'background: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_responsive_control('button_border_radius', [
            'label'      => __('圆角', 'oyiso'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-info-card__button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('button_padding', [
            'label'      => __('内边距', 'oyiso'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-info-card__button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('button_spacing', [
            'label'      => __('顶部间距', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-info-card__button' => 'margin-top: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();
    }

    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $title = $settings['title'] ?? '';
        $description = $settings['description'] ?? '';
        $button_text = $settings['button_text'] ?? '';
        $link = $settings['link']['url'] ?? '';

        if ($link) {
            $this->add_link_attributes('button', $settings['link']);
        }
        ?>
        <div class="oyiso-info-card">
            <?php if ($title) : ?>
                <h3 class="oyiso-info-card__title"><?php echo esc_html($title); ?></h3>
            <?php endif; ?>

            <?php if ($description) : ?>
                <div class="oyiso-info-card__description"><?php echo wp_kses_post($description); ?></div>
            <?php endif; ?>

            <?php if ($button_text && $link) : ?>
                <a class="oyiso-info-card__button" <?php $this->print_render_attribute_string('button'); ?>>
                    <?php echo esc_html($button_text); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }
}
