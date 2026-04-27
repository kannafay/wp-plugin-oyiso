<?php

namespace Oyiso\ElementorWidgets;

defined('ABSPATH') || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

class Info_Card extends Widget_Base
{
    public function get_name()
    {
        return 'oyiso_info_card';
    }

    public function get_title()
    {
        return __('Oyiso 信息卡片', 'oyiso');
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
            'default'     => __('你的卡片标题', 'oyiso'),
            'placeholder' => __('请输入标题', 'oyiso'),
        ]);

        $this->add_control('description', [
            'label'       => __('描述', 'oyiso'),
            'type'        => Controls_Manager::TEXTAREA,
            'default'     => __('这里是一段可在 Elementor 中编辑的描述内容。', 'oyiso'),
            'placeholder' => __('请输入描述', 'oyiso'),
        ]);

        $this->add_control('link', [
            'label'       => __('链接', 'oyiso'),
            'type'        => Controls_Manager::URL,
            'placeholder' => 'https://example.com',
        ]);

        $this->add_control('button_text', [
            'label'   => __('按钮文字', 'oyiso'),
            'type'    => Controls_Manager::TEXT,
            'default' => __('了解更多', 'oyiso'),
        ]);

        $this->end_controls_section();

        $this->start_controls_section('style_section', [
            'label' => __('样式', 'oyiso'),
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

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name'     => 'title_typography',
            'label'    => __('标题排版', 'oyiso'),
            'selector' => '{{WRAPPER}} .oyiso-info-card__title',
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
