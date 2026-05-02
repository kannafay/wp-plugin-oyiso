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
    public function get_name()
    {
        return 'oyiso_info_card';
    }

    public function get_title()
    {
        return oyiso_editor_t('Oyiso Info Card');
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
            'label' => oyiso_editor_t('Content'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('title', [
            'label'       => oyiso_editor_t('Title'),
            'type'        => Controls_Manager::TEXT,
            'default'     => oyiso_t('Your Card Title'),
            'placeholder' => oyiso_editor_t('Enter a title'),
        ]);

        $this->add_control('description', [
            'label'       => oyiso_editor_t('Description'),
            'type'        => Controls_Manager::TEXTAREA,
            'default'     => oyiso_t('This is a description you can edit in Elementor.'),
            'placeholder' => oyiso_editor_t('Enter a description'),
        ]);

        $this->add_control('link', [
            'label'       => oyiso_editor_t('Link'),
            'type'        => Controls_Manager::URL,
            'placeholder' => 'https://example.com',
        ]);

        $this->add_control('button_text', [
            'label'   => oyiso_editor_t('Button Text'),
            'type'    => Controls_Manager::TEXT,
            'default' => oyiso_t('Learn More'),
        ]);

        $this->add_responsive_control('content_alignment', [
            'label'     => oyiso_editor_t('Content Alignment'),
            'type'      => Controls_Manager::CHOOSE,
            'options'   => [
                'left'   => [
                    'title' => oyiso_editor_t('Left'),
                    'icon'  => 'eicon-text-align-left',
                ],
                'center' => [
                    'title' => oyiso_editor_t('Center'),
                    'icon'  => 'eicon-text-align-center',
                ],
                'right'  => [
                    'title' => oyiso_editor_t('Right'),
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
            'label' => oyiso_editor_t('Card'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('accent_color', [
            'label'     => oyiso_editor_t('Accent Color'),
            'type'      => Controls_Manager::COLOR,
            'default'   => '#e5702a',
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card' => '--oyiso-accent-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('card_background_color', [
            'label'     => oyiso_editor_t('Background Color'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card' => 'background: {{VALUE}};',
            ],
        ]);

        $this->add_control('card_border_color', [
            'label'     => oyiso_editor_t('Border Color'),
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
            'label'      => oyiso_editor_t('Corner Radius'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-info-card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('card_padding', [
            'label'      => oyiso_editor_t('Padding'),
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
            'label' => oyiso_editor_t('Title'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('title_color', [
            'label'     => oyiso_editor_t('Text Color'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card__title' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name'     => 'title_typography',
            'label'    => oyiso_editor_t('Title Typography'),
            'selector' => '{{WRAPPER}} .oyiso-info-card__title',
        ]);

        $this->add_responsive_control('title_spacing', [
            'label'      => oyiso_editor_t('Bottom Spacing'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-info-card__title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        $this->start_controls_section('description_style_section', [
            'label' => oyiso_editor_t('Description'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('description_color', [
            'label'     => oyiso_editor_t('Text Color'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card__description' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name'     => 'description_typography',
            'label'    => oyiso_editor_t('Description Typography'),
            'selector' => '{{WRAPPER}} .oyiso-info-card__description',
        ]);

        $this->add_responsive_control('description_spacing', [
            'label'      => oyiso_editor_t('Bottom Spacing'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-info-card__description' => 'margin-bottom: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        $this->start_controls_section('button_style_section', [
            'label' => oyiso_editor_t('Button'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name'     => 'button_typography',
            'label'    => oyiso_editor_t('Button Typography'),
            'selector' => '{{WRAPPER}} .oyiso-info-card__button',
        ]);

        $this->start_controls_tabs('button_style_tabs');

        $this->start_controls_tab('button_style_normal', [
            'label' => oyiso_editor_t('Normal'),
        ]);

        $this->add_control('button_text_color', [
            'label'     => oyiso_editor_t('Text Color'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card__button' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('button_background_color', [
            'label'     => oyiso_editor_t('Background Color'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card__button' => 'background: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->start_controls_tab('button_style_hover', [
            'label' => oyiso_editor_t('Hover'),
        ]);

        $this->add_control('button_hover_text_color', [
            'label'     => oyiso_editor_t('Text Color'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card__button:hover, {{WRAPPER}} .oyiso-info-card__button:focus' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('button_hover_background_color', [
            'label'     => oyiso_editor_t('Background Color'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-info-card__button:hover, {{WRAPPER}} .oyiso-info-card__button:focus' => 'background: {{VALUE}};',
            ],
        ]);

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_responsive_control('button_border_radius', [
            'label'      => oyiso_editor_t('Corner Radius'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-info-card__button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('button_padding', [
            'label'      => oyiso_editor_t('Padding'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-info-card__button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('button_spacing', [
            'label'      => oyiso_editor_t('Top Spacing'),
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
        <div class="oyiso-info-card" data-oyiso-info-card>
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
