<?php

namespace Oyiso\ElementorWidgets;

defined('ABSPATH') || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Icons_Manager;
use Elementor\Repeater;
use Elementor\Widget_Base;

class Coupons extends Widget_Base
{
    private function get_site_locale(): string
    {
        $site_locale = is_multisite()
            ? (get_option('WPLANG') ?: get_site_option('WPLANG'))
            : get_option('WPLANG');

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
        $mofile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'oyiso-' . $locale . '.mo';

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
        $site_locale = $this->get_site_locale();
        $fallback = translate($text, 'oyiso');
        $translated = $this->translate_for_locale($text, $site_locale);

        return $translated !== $text ? $translated : $fallback;
    }

    public function get_name()
    {
        return 'oyiso_coupons';
    }

    public function get_title()
    {
        return 'Oyiso 优惠券';
    }

    public function get_icon()
    {
        return 'eicon-price-list';
    }

    public function get_categories()
    {
        return ['oyiso'];
    }

    public function get_style_depends()
    {
        return ['oyiso-elementor-widgets'];
    }

    public function get_script_depends()
    {
        return ['oyiso-coupon-tabs'];
    }

    protected function register_controls()
    {
        $this->start_controls_section('banner_section', [
            'label' => __('Banner', 'oyiso'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('banner_background', [
            'label'   => __('Banner Background', 'oyiso'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'none',
            'options' => $this->get_banner_background_options(),
        ]);

        $this->add_group_control(Group_Control_Background::get_type(), [
            'name'           => 'banner_custom_background',
            'label'          => __('Custom Background', 'oyiso'),
            'types'          => ['classic', 'gradient'],
            'selector'       => '{{WRAPPER}} .oyiso-coupons__banner',
            'condition'      => [
                'banner_background' => 'auto',
            ],
            'fields_options' => [
                'background' => [
                    'label'   => __('Background Type', 'oyiso'),
                    'default' => 'classic',
                ],
                'image'      => [
                    'label' => __('Image', 'oyiso'),
                ],
                'position'   => [
                    'default' => 'center center',
                ],
                'repeat'     => [
                    'default' => 'no-repeat',
                ],
                'size'       => [
                    'default' => 'cover',
                ],
            ],
        ]);

        $this->add_control('banner_image', [
            'type'      => Controls_Manager::MEDIA,
            'default'   => [
                'url' => '',
            ],
            'condition' => [
                'banner_background' => '__legacy_banner_image__',
            ],
        ]);

        $this->add_control('banner_kicker', [
            'label'   => __('Intro Label', 'oyiso'),
            'type'    => Controls_Manager::TEXT,
            'default' => $this->get_site_default_text('Featured Offers'),
        ]);

        $this->add_control('banner_title', [
            'label'   => __('Title', 'oyiso'),
            'type'    => Controls_Manager::TEXT,
            'default' => $this->get_site_default_text('Limited-Time Offers'),
        ]);

        $this->add_control('banner_description', [
            'label'   => __('Description', 'oyiso'),
            'type'    => Controls_Manager::TEXTAREA,
            'default' => $this->get_site_default_text('Browse current offers and quickly find the best savings for your order.'),
        ]);

        $this->end_controls_section();

        $this->start_controls_section('coupons_section', [
            'label' => __('Coupon Groups', 'oyiso'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $repeater = new Repeater();

        $repeater->add_control('group_name', [
            'label'       => __('Group Name', 'oyiso'),
            'type'        => Controls_Manager::TEXT,
            'default'     => $this->get_site_default_text('Featured Offers'),
            'placeholder' => $this->get_site_default_text('e.g. Featured Offers'),
        ]);

        $repeater->add_control('coupon_ids', [
            'label'       => __('Select Coupons', 'oyiso'),
            'type'        => Controls_Manager::SELECT2,
            'multiple'    => true,
            'label_block' => true,
            'options'     => $this->get_wc_coupon_options(),
        ]);

        $repeater->add_control('group_color', [
            'label'   => __('Group Color', 'oyiso'),
            'type'    => Controls_Manager::COLOR,
            'default' => '#e5702a',
        ]);

        $repeater->add_control('group_icon', [
            'label'   => __('Group Icon', 'oyiso'),
            'type'    => Controls_Manager::ICONS,
            'default' => [
                'value'   => 'fas fa-ticket-alt',
                'library' => 'fa-solid',
            ],
        ]);

        $this->add_control('coupon_groups', [
            'label'       => __('Group List', 'oyiso'),
            'type'        => Controls_Manager::REPEATER,
            'fields'      => $repeater->get_controls(),
            'title_field' => '{{{ group_name }}}',
            'default'     => [
                [
                    'group_name' => $this->get_site_default_text('Featured Offers'),
                    'coupon_ids' => [],
                    'group_color' => '#e5702a',
                ],
            ],
        ]);

        $this->add_control('coupon_group_notice', [
            'type'            => Controls_Manager::RAW_HTML,
            'raw'             => __('The frontend adds an "All" tab automatically. If a coupon appears in multiple groups, it will show only in the first matching group.', 'oyiso'),
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('style_basic_section', [
            'label' => __('General', 'oyiso'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('use_default_style', [
            'label'        => __('Use Default Style', 'oyiso'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('Yes', 'oyiso'),
            'label_off'    => __('No', 'oyiso'),
            'default'      => '',
            'return_value' => 'yes',
            'description'  => __('Turn this on to preview the plugin default style. Turn it off to restore your current custom settings.', 'oyiso'),
            'prefix_class' => 'oyiso-coupons-default-style-',
        ]);

        $this->add_control('accent_color', [
            'label'       => __('Accent Color', 'oyiso'),
            'type'        => Controls_Manager::COLOR,
            'default'     => '#e5702a',
            'placeholder' => '#e5702a',
            'description' => __('Used for the "All" tab, dialog links, and as the fallback when a group has no custom color. Group colors override this setting.', 'oyiso'),
            'selectors'   => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-coupon-accent: {{VALUE}};',
            ],
        ]);

        $this->add_control('dark_color', [
            'label'     => __('Primary Text Color', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'default'   => '#1f2937',
            'placeholder' => '#1f2937',
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-coupon-dark: {{VALUE}};',
            ],
        ]);

        $this->add_control('line_color', [
            'label'     => __('Border Color', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'default'   => '#e7e2dc',
            'placeholder' => '#e7e2dc',
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-coupon-line: {{VALUE}};',
            ],
        ]);

        $this->add_responsive_control('section_gap', [
            'label'      => __('Section Spacing', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 0,
                    'max' => 80,
                ],
            ],
            'default'    => [
                'size' => 24,
                'unit' => 'px',
            ],
            'placeholder' => [
                'size' => 24,
                'unit' => 'px',
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-coupons-gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        $this->start_controls_section('style_banner_section', [
            'label' => __('Banner', 'oyiso'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('banner_min_height', [
            'label'          => __('Height', 'oyiso'),
            'type'           => Controls_Manager::SLIDER,
            'size_units'     => ['px'],
            'range'          => [
                'px' => [
                    'min' => 120,
                    'max' => 600,
                ],
            ],
            'default'        => [
                'size' => 280,
                'unit' => 'px',
            ],
            'mobile_default' => [
                'size' => 220,
                'unit' => 'px',
            ],
            'placeholder'    => [
                'size' => 280,
                'unit' => 'px',
            ],
            'device_args'    => [
                'mobile' => [
                    'placeholder' => [
                        'size' => 220,
                        'unit' => 'px',
                    ],
                ],
            ],
            'selectors'      => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-banner-min-height: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('banner_padding', [
            'label'          => __('Inner Padding', 'oyiso'),
            'type'           => Controls_Manager::SLIDER,
            'size_units'     => ['px'],
            'range'          => [
                'px' => [
                    'min' => 12,
                    'max' => 80,
                ],
            ],
            'default'        => [
                'size' => 44,
                'unit' => 'px',
            ],
            'mobile_default' => [
                'size' => 28,
                'unit' => 'px',
            ],
            'placeholder'    => [
                'size' => 44,
                'unit' => 'px',
            ],
            'device_args'    => [
                'mobile' => [
                    'placeholder' => [
                        'size' => 28,
                        'unit' => 'px',
                    ],
                ],
            ],
            'selectors'      => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-banner-padding: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('banner_horizontal_align', [
            'label'                => __('Horizontal Alignment', 'oyiso'),
            'type'                 => Controls_Manager::CHOOSE,
            'default'              => 'left',
            'options'              => [
                'left'   => [
                    'title' => __('Left', 'oyiso'),
                    'icon'  => 'eicon-text-align-left',
                ],
                'center' => [
                    'title' => __('Center', 'oyiso'),
                    'icon'  => 'eicon-text-align-center',
                ],
                'right'  => [
                    'title' => __('Right', 'oyiso'),
                    'icon'  => 'eicon-text-align-right',
                ],
            ],
            'selectors_dictionary' => [
                'left'   => '--oyiso-banner-content-justify: flex-start; --oyiso-banner-content-items: flex-start; --oyiso-banner-content-text-align: left;',
                'center' => '--oyiso-banner-content-justify: center; --oyiso-banner-content-items: center; --oyiso-banner-content-text-align: center;',
                'right'  => '--oyiso-banner-content-justify: flex-end; --oyiso-banner-content-items: flex-end; --oyiso-banner-content-text-align: right;',
            ],
            'selectors'            => [
                '{{WRAPPER}} .oyiso-coupons' => '{{VALUE}}',
            ],
            'toggle'               => false,
        ]);

        $this->add_responsive_control('banner_vertical_align', [
            'label'                => __('Vertical Alignment', 'oyiso'),
            'type'                 => Controls_Manager::CHOOSE,
            'default'              => 'bottom',
            'options'              => [
                'top'    => [
                    'title' => __('Top', 'oyiso'),
                    'icon'  => 'eicon-v-align-top',
                ],
                'middle' => [
                    'title' => __('Center', 'oyiso'),
                    'icon'  => 'eicon-v-align-middle',
                ],
                'bottom' => [
                    'title' => __('Bottom', 'oyiso'),
                    'icon'  => 'eicon-v-align-bottom',
                ],
            ],
            'selectors_dictionary' => [
                'top'    => '--oyiso-banner-vertical-align: flex-start;',
                'middle' => '--oyiso-banner-vertical-align: center;',
                'bottom' => '--oyiso-banner-vertical-align: flex-end;',
            ],
            'selectors'            => [
                '{{WRAPPER}} .oyiso-coupons' => '{{VALUE}}',
            ],
            'toggle'               => false,
        ]);

        $this->add_responsive_control('banner_radius', [
            'label'      => __('Corner Radius', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 0,
                    'max' => 40,
                ],
            ],
            'default'    => [
                'size' => 12,
                'unit' => 'px',
            ],
            'placeholder' => [
                'size' => 12,
                'unit' => 'px',
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-banner-radius: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('banner_title_size', [
            'label'          => __('Title Size', 'oyiso'),
            'type'           => Controls_Manager::SLIDER,
            'size_units'     => ['px'],
            'range'          => [
                'px' => [
                    'min' => 18,
                    'max' => 72,
                ],
            ],
            'default'        => [
                'size' => 42,
                'unit' => 'px',
            ],
            'mobile_default' => [
                'size' => 30,
                'unit' => 'px',
            ],
            'placeholder'    => [
                'size' => 42,
                'unit' => 'px',
            ],
            'device_args'    => [
                'mobile' => [
                    'placeholder' => [
                        'size' => 30,
                        'unit' => 'px',
                    ],
                ],
            ],
            'selectors'      => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-banner-title-size: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('banner_description_size', [
            'label'      => __('Description Size', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 12,
                    'max' => 28,
                ],
            ],
            'default'    => [
                'size' => 16,
                'unit' => 'px',
            ],
            'placeholder' => [
                'size' => 16,
                'unit' => 'px',
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-banner-description-size: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        $this->start_controls_section('style_tabs_section', [
            'label' => __('Tabs', 'oyiso'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('tabs_style_preset', [
            'label'                => __('Style Preset', 'oyiso'),
            'type'                 => Controls_Manager::SELECT,
            'default'              => 'default',
            'options'              => [
                'default'   => __('Dot Accent', 'oyiso'),
                'pills'     => __('Pill Tabs', 'oyiso'),
                'segmented' => __('Segmented Tabs', 'oyiso'),
                'underline' => __('Underline', 'oyiso'),
                'minimal'   => __('Text Only', 'oyiso'),
            ],
            'prefix_class'         => 'oyiso-coupons-tabs-style-',
            'selectors_dictionary' => [
                'default'   => '--oyiso-tabs-padding: 0; --oyiso-tabs-border-bottom: 0; --oyiso-tabs-bg: transparent; --oyiso-tabs-radius: 0; --oyiso-tabs-shadow: none; --oyiso-tab-bg: transparent; --oyiso-tab-border: 0; --oyiso-tab-padding: 7px 12px; --oyiso-tab-radius: 6px; --oyiso-tab-dot-size: 7px; --oyiso-tab-count-bg: #f1f3f5; --oyiso-tab-hover-bg: #f7f8f9; --oyiso-tab-active-bg: color-mix(in srgb, var(--oyiso-group-color, var(--oyiso-coupon-accent)), #fff 90%); --oyiso-tab-active-shadow: none;',
                'pills'     => '--oyiso-tabs-padding: 0; --oyiso-tabs-border-bottom: 0; --oyiso-tabs-bg: transparent; --oyiso-tabs-radius: 0; --oyiso-tabs-shadow: none; --oyiso-tab-bg: #f7f8f9; --oyiso-tab-border: 1px solid #eceff2; --oyiso-tab-padding: 6px 13px; --oyiso-tab-radius: 999px; --oyiso-tab-dot-size: 0; --oyiso-tab-count-bg: #fff; --oyiso-tab-hover-bg: color-mix(in srgb, var(--oyiso-group-color, var(--oyiso-coupon-accent)), #fff 92%); --oyiso-tab-active-bg: var(--oyiso-group-color, var(--oyiso-coupon-accent)); --oyiso-tab-active-shadow: 0 10px 22px color-mix(in srgb, var(--oyiso-group-color, var(--oyiso-coupon-accent)), transparent 76%);',
                'segmented' => '--oyiso-tabs-padding: 5px; --oyiso-tabs-border-bottom: 0; --oyiso-tabs-bg: #f5f6f7; --oyiso-tabs-radius: 8px; --oyiso-tabs-shadow: inset 0 0 0 1px #eceff2; --oyiso-tab-bg: transparent; --oyiso-tab-border: 0; --oyiso-tab-padding: 8px 14px; --oyiso-tab-radius: 6px; --oyiso-tab-dot-size: 0; --oyiso-tab-count-bg: #e9edf1; --oyiso-tab-hover-bg: transparent; --oyiso-tab-active-bg: transparent; --oyiso-tab-active-shadow: none;',
                'underline' => '--oyiso-tabs-padding: 0; --oyiso-tabs-border-bottom: 0; --oyiso-tabs-bg: transparent; --oyiso-tabs-radius: 0; --oyiso-tabs-shadow: none; --oyiso-tab-bg: transparent; --oyiso-tab-border: 0; --oyiso-tab-padding: 9px 2px 12px; --oyiso-tab-radius: 0; --oyiso-tab-dot-size: 0; --oyiso-tab-count-bg: #f1f3f5; --oyiso-tab-hover-bg: transparent; --oyiso-tab-active-bg: transparent; --oyiso-tab-active-shadow: none;',
                'minimal'   => '--oyiso-tabs-padding: 0; --oyiso-tabs-border-bottom: 0; --oyiso-tabs-bg: transparent; --oyiso-tabs-radius: 0; --oyiso-tabs-shadow: none; --oyiso-tab-bg: transparent; --oyiso-tab-border: 0; --oyiso-tab-padding: 4px 0; --oyiso-tab-radius: 0; --oyiso-tab-dot-size: 0; --oyiso-tab-count-bg: transparent; --oyiso-tab-hover-bg: transparent; --oyiso-tab-active-bg: transparent; --oyiso-tab-active-shadow: none;',
            ],
            'selectors'            => [
                '{{WRAPPER}} .oyiso-coupons' => '{{VALUE}}',
            ],
        ]);

        $this->add_responsive_control('tabs_layout', [
            'label'                => __('Layout', 'oyiso'),
            'type'                 => Controls_Manager::SELECT,
            'default'              => 'wrap',
            'tablet_default'       => 'wrap',
            'mobile_default'       => 'grid_2',
            'placeholder'          => 'wrap',
            'device_args'          => [
                'tablet' => [
                    'placeholder' => 'wrap',
                ],
                'mobile' => [
                    'placeholder' => 'grid_2',
                ],
            ],
            'options'              => [
                'wrap'   => __('Wrap', 'oyiso'),
                'grid_1' => __('1 Column Grid', 'oyiso'),
                'grid_2' => __('2 Column Grid', 'oyiso'),
                'grid_3' => __('3 Column Grid', 'oyiso'),
            ],
            'selectors_dictionary' => [
                'wrap'   => '--oyiso-tabs-display: flex; --oyiso-tabs-columns: none; --oyiso-tabs-wrap: wrap; --oyiso-tabs-segmented-width: fit-content;',
                'grid_1' => '--oyiso-tabs-display: grid; --oyiso-tabs-columns: repeat(1, minmax(0, 1fr)); --oyiso-tabs-wrap: nowrap; --oyiso-tab-bg: #f7f8f9; --oyiso-tabs-segmented-width: 100%;',
                'grid_2' => '--oyiso-tabs-display: grid; --oyiso-tabs-columns: repeat(2, minmax(0, 1fr)); --oyiso-tabs-wrap: nowrap; --oyiso-tab-bg: #f7f8f9; --oyiso-tabs-segmented-width: 100%;',
                'grid_3' => '--oyiso-tabs-display: grid; --oyiso-tabs-columns: repeat(3, minmax(0, 1fr)); --oyiso-tabs-wrap: nowrap; --oyiso-tab-bg: #f7f8f9; --oyiso-tabs-segmented-width: 100%;',
            ],
            'selectors'            => [
                '{{WRAPPER}} .oyiso-coupons' => '{{VALUE}}',
            ],
        ]);

        $this->add_responsive_control('tabs_gap', [
            'label'      => __('Tab Gap', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 0,
                    'max' => 32,
                ],
            ],
            'default'    => [
                'size' => 12,
                'unit' => 'px',
            ],
            'placeholder' => [
                'size' => 12,
                'unit' => 'px',
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-tabs-gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('tabs_bottom_spacing', [
            'label'      => __('Bottom Spacing', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 0,
                    'max' => 60,
                ],
            ],
            'default'    => [
                'size' => 20,
                'unit' => 'px',
            ],
            'placeholder' => [
                'size' => 20,
                'unit' => 'px',
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-tabs-margin-bottom: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('tab_min_height', [
            'label'      => __('Button Height', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 24,
                    'max' => 64,
                ],
            ],
            'default'    => [
                'size' => 34,
                'unit' => 'px',
            ],
            'placeholder' => [
                'size' => 34,
                'unit' => 'px',
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-tab-min-height: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('tab_radius', [
            'label'      => __('Button Radius', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 0,
                    'max' => 32,
                ],
            ],
            'default'    => [
                'size' => 6,
                'unit' => 'px',
            ],
            'placeholder' => [
                'size' => 6,
                'unit' => 'px',
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-tab-radius: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('tab_font_size', [
            'label'      => __('Text Size', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 10,
                    'max' => 22,
                ],
            ],
            'default'    => [
                'size' => 14,
                'unit' => 'px',
            ],
            'placeholder' => [
                'size' => 14,
                'unit' => 'px',
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-tab-font-size: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();

        $this->start_controls_section('style_coupon_section', [
            'label' => __('Coupon Cards', 'oyiso'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('card_background_color', [
            'label'     => __('Card Background', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'placeholder' => '#ffffff',
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-card-bg: {{VALUE}};',
            ],
        ]);

        $this->add_responsive_control('coupon_grid_gap', [
            'label'      => __('Card Gap', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 0,
                    'max' => 48,
                ],
            ],
            'default'    => [
                'size' => 18,
                'unit' => 'px',
            ],
            'placeholder' => [
                'size' => 18,
                'unit' => 'px',
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-card-gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('card_radius', [
            'label'      => __('Card Radius', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 0,
                    'max' => 32,
                ],
            ],
            'default'    => [
                'size' => 8,
                'unit' => 'px',
            ],
            'placeholder' => [
                'size' => 8,
                'unit' => 'px',
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-card-radius: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Box_Shadow::get_type(), [
            'name'           => 'card_box_shadow',
            'label'          => __('Card Shadow', 'oyiso'),
            'selector'       => '{{WRAPPER}} .oyiso-coupon-card',
            'fields_options' => [
                'box_shadow_type' => [
                    'default' => 'yes',
                ],
                'box_shadow'      => [
                    'default' => [
                        'horizontal' => 0,
                        'vertical'   => 8,
                        'blur'       => 22,
                        'spread'     => 0,
                        'color'      => 'rgba(31, 41, 55, 0.05)',
                    ],
                ],
            ],
        ]);

        $this->add_responsive_control('card_content_padding', [
            'label'      => __('Content Padding', 'oyiso'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px'],
            'default'    => [
                'top'      => 18,
                'right'    => 18,
                'bottom'   => 18,
                'left'     => 18,
                'unit'     => 'px',
                'isLinked' => true,
            ],
            'placeholder' => [
                'top'    => 18,
                'right'  => 18,
                'bottom' => 18,
                'left'   => 18,
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-card-content-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('card_discount_size', [
            'label'      => __('Amount Size', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 18,
                    'max' => 60,
                ],
            ],
            'default'    => [
                'size' => 32,
                'unit' => 'px',
            ],
            'placeholder' => [
                'size' => 32,
                'unit' => 'px',
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-card-discount-size: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('card_text_size', [
            'label'      => __('Body Text Size', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 12,
                    'max' => 22,
                ],
            ],
            'default'    => [
                'size' => 14,
                'unit' => 'px',
            ],
            'placeholder' => [
                'size' => 14,
                'unit' => 'px',
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-card-text-size: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();
    }

    private function get_banner_background_options()
    {
        $options = [
            'none' => __('None', 'oyiso'),
            'auto' => __('Custom Image', 'oyiso'),
        ];

        foreach ($this->get_banner_background_presets() as $key => $preset) {
            $options[$key] = $preset['label'];
        }

        return $options;
    }

    private function get_banner_background_presets()
    {
        return [
            'cosmic_sale' => [
                'label' => __('Cosmic Rewards', 'oyiso'),
            ],
            'ocean_treasure' => [
                'label' => __('Ocean Treasure', 'oyiso'),
            ],
            'diamond_vault' => [
                'label' => __('Diamond Vault', 'oyiso'),
            ],
            'mall_parade' => [
                'label' => __('Mall Parade', 'oyiso'),
            ],
        ];
    }

    private function get_banner_background_style(array $settings)
    {
        $background = $settings['banner_background'] ?? 'none';

        if ($background === 'auto') {
            $legacy_image = $settings['banner_image'] ?? [];
            $image_url = is_array($legacy_image) ? ($legacy_image['url'] ?? '') : '';

            return $image_url ? 'background-image: url("' . esc_url($image_url) . '");' : '';
        }

        return '';
    }

    private function get_banner_preset_key(array $settings)
    {
        $background = $settings['banner_background'] ?? 'none';

        return array_key_exists($background, $this->get_banner_background_presets()) ? $background : '';
    }

    private function render_banner_art(string $preset_key)
    {
        if ($preset_key === '') {
            return;
        }

        ?>
        <div class="oyiso-coupons__banner-art oyiso-coupons__banner-art--<?php echo esc_attr($preset_key); ?>" aria-hidden="true">
            <?php
            switch ($preset_key) {
                case 'mall_parade':
                    $this->render_banner_art_mall_parade();
                    break;
                case 'diamond_vault':
                    $this->render_banner_art_diamond_vault();
                    break;
                case 'ocean_treasure':
                    $this->render_banner_art_ocean_treasure();
                    break;
                case 'cosmic_sale':
                default:
                    $this->render_banner_art_cosmic_sale();
                    break;
            }
            ?>
        </div>
        <?php
    }

    private function render_banner_art_cosmic_sale()
    {
        ?>
        <svg viewBox="0 0 1600 520" preserveAspectRatio="xMidYMid slice" focusable="false">
            <defs>
                <linearGradient id="oyiso-space-bg" x1="136" y1="28" x2="1476" y2="504" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#172347" />
                    <stop offset="0.38" stop-color="#283c72" />
                    <stop offset="0.72" stop-color="#4d3d73" />
                    <stop offset="1" stop-color="#7b4563" />
                </linearGradient>
                <radialGradient id="oyiso-space-aura-left" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(402 202) rotate(90) scale(252 388)">
                    <stop stop-color="#8cb9ff" stop-opacity="0.34" />
                    <stop offset="0.52" stop-color="#8cb9ff" stop-opacity="0.16" />
                    <stop offset="1" stop-color="#8cb9ff" stop-opacity="0" />
                </radialGradient>
                <radialGradient id="oyiso-space-aura-right" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(1220 140) rotate(90) scale(216 326)">
                    <stop stop-color="#ffb2cb" stop-opacity="0.24" />
                    <stop offset="0.54" stop-color="#c596ff" stop-opacity="0.14" />
                    <stop offset="1" stop-color="#c596ff" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="oyiso-planet-fill" x1="182" y1="48" x2="326" y2="212" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#ffd8be" />
                    <stop offset="0.48" stop-color="#f2b8d8" />
                    <stop offset="1" stop-color="#9b8dff" />
                </linearGradient>
                <linearGradient id="oyiso-planet-ring" x1="120" y1="168" x2="382" y2="136" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fff6ea" stop-opacity="0" />
                    <stop offset="0.26" stop-color="#fff1df" stop-opacity="0.68" />
                    <stop offset="0.74" stop-color="#ffdcb7" stop-opacity="0.32" />
                    <stop offset="1" stop-color="#ffdcb7" stop-opacity="0" />
                </linearGradient>
                <linearGradient id="oyiso-ticket-fill" x1="844" y1="126" x2="1168" y2="364" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fff9f0" stop-opacity="0.95" />
                    <stop offset="0.52" stop-color="#f4ebff" stop-opacity="0.88" />
                    <stop offset="1" stop-color="#dbe8ff" stop-opacity="0.82" />
                </linearGradient>
                <linearGradient id="oyiso-ticket-stroke" x1="864" y1="150" x2="1136" y2="338" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fffdf8" stop-opacity="0.88" />
                    <stop offset="1" stop-color="#f8c8ff" stop-opacity="0.4" />
                </linearGradient>
                <radialGradient id="oyiso-ticket-glow" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(1036 252) rotate(90) scale(124 196)">
                    <stop stop-color="#fff4d8" stop-opacity="0.46" />
                    <stop offset="0.56" stop-color="#f2a8ff" stop-opacity="0.18" />
                    <stop offset="1" stop-color="#f2a8ff" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="oyiso-ship-trail" x1="458" y1="334" x2="724" y2="282" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fff1e1" stop-opacity="0" />
                    <stop offset="0.48" stop-color="#fff1e1" stop-opacity="0.3" />
                    <stop offset="1" stop-color="#ffd4a1" stop-opacity="0.16" />
                </linearGradient>
                <linearGradient id="oyiso-meteor-tail" x1="0" y1="0" x2="-218" y2="-72" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fff6ef" stop-opacity="0.96" />
                    <stop offset="0.18" stop-color="#ffd8b4" stop-opacity="0.82" />
                    <stop offset="0.58" stop-color="#ffb26f" stop-opacity="0.28" />
                    <stop offset="1" stop-color="#ffb26f" stop-opacity="0" />
                </linearGradient>
                <radialGradient id="oyiso-meteor-head" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(0 0) rotate(90) scale(14 14)">
                    <stop stop-color="#fffaf6" />
                    <stop offset="0.52" stop-color="#ffd7b2" />
                    <stop offset="1" stop-color="#ffb16a" stop-opacity="0.4" />
                </radialGradient>
            </defs>
            <rect width="1600" height="520" fill="url(#oyiso-space-bg)" />
            <rect width="1600" height="520" fill="url(#oyiso-space-aura-left)" />
            <rect width="1600" height="520" fill="url(#oyiso-space-aura-right)" />
            <g class="oyiso-nebula">
                <ellipse cx="278" cy="228" rx="220" ry="108" />
                <ellipse cx="1048" cy="338" rx="326" ry="118" />
                <ellipse cx="1344" cy="112" rx="190" ry="96" />
            </g>
            <g class="oyiso-starfield">
                <circle class="oyiso-star oyiso-star--a" cx="148" cy="88" r="3" />
                <circle class="oyiso-star oyiso-star--b" cx="274" cy="156" r="2.5" />
                <circle class="oyiso-star oyiso-star--c" cx="412" cy="72" r="2" />
                <circle class="oyiso-star oyiso-star--a" cx="598" cy="124" r="2.5" />
                <circle class="oyiso-star oyiso-star--b" cx="738" cy="68" r="3" />
                <circle class="oyiso-star oyiso-star--c" cx="914" cy="148" r="2" />
                <circle class="oyiso-star oyiso-star--a" cx="1078" cy="84" r="2.5" />
                <circle class="oyiso-star oyiso-star--b" cx="1252" cy="120" r="3" />
                <circle class="oyiso-star oyiso-star--c" cx="1394" cy="74" r="2.25" />
                <circle class="oyiso-star oyiso-star--a" cx="1502" cy="182" r="2.5" />
                <circle class="oyiso-star oyiso-star--b" cx="128" cy="228" r="2.25" />
                <circle class="oyiso-star oyiso-star--c" cx="566" cy="214" r="1.8" />
                <circle class="oyiso-star oyiso-star--a" cx="864" cy="214" r="2.15" />
                <circle class="oyiso-star oyiso-star--b" cx="1196" cy="236" r="2.4" />
                <circle class="oyiso-star oyiso-star--c" cx="1442" cy="268" r="2.15" />
            </g>
            <g class="oyiso-starburst-group">
                <g class="oyiso-starburst oyiso-starburst--a" transform="translate(336 110)">
                    <path d="M0 -10V10" />
                    <path d="M-10 0H10" />
                </g>
                <g class="oyiso-starburst oyiso-starburst--b" transform="translate(988 84)">
                    <path d="M0 -8V8" />
                    <path d="M-8 0H8" />
                </g>
                <g class="oyiso-starburst oyiso-starburst--c" transform="translate(1322 208)">
                    <path d="M0 -10V10" />
                    <path d="M-10 0H10" />
                </g>
            </g>
            <g class="oyiso-orbit oyiso-orbit--one">
                <path d="M216 404C380 318 592 266 816 256C1048 246 1262 286 1430 362" />
            </g>
            <g class="oyiso-orbit oyiso-orbit--two">
                <path d="M176 156C334 110 536 98 750 124C956 150 1146 214 1348 312" />
            </g>
            <g class="oyiso-meteor oyiso-meteor--one">
                <path class="oyiso-meteor__tail" d="M0 0C-42 -10 -106 -30 -192 -66" />
                <path class="oyiso-meteor__spark" d="M-84 -14L-134 -30" />
                <circle class="oyiso-meteor__head" cx="0" cy="0" r="10" />
            </g>
            <g class="oyiso-meteor oyiso-meteor--two">
                <path class="oyiso-meteor__tail" d="M0 0C-34 -8 -86 -24 -154 -52" />
                <path class="oyiso-meteor__spark" d="M-62 -10L-104 -24" />
                <circle class="oyiso-meteor__head" cx="0" cy="0" r="8" />
            </g>
            <g class="oyiso-planet">
                <circle cx="254" cy="154" r="74" />
                <ellipse cx="254" cy="162" rx="124" ry="28" />
                <circle class="oyiso-planet__moon" cx="404" cy="234" r="14" />
            </g>
            <g class="oyiso-coupon-card">
                <path class="oyiso-coupon-card__glow" d="M868 172C892 142 948 126 1018 126H1066C1134 126 1188 144 1210 174C1230 202 1238 238 1238 260C1238 282 1230 318 1210 346C1188 376 1134 394 1066 394H1018C948 394 892 378 868 348C844 318 836 282 836 260C836 238 844 202 868 172Z" />
                <path class="oyiso-coupon-card__body" d="M924 146H1140C1174 146 1202 174 1202 208V226C1188 232 1178 246 1178 262C1178 278 1188 292 1202 298V312C1202 346 1174 374 1140 374H924C890 374 862 346 862 312V298C876 292 886 278 886 262C886 246 876 232 862 226V208C862 174 890 146 924 146Z" />
                <path class="oyiso-coupon-card__edge" d="M924 146H1140C1174 146 1202 174 1202 208V226C1188 232 1178 246 1178 262C1178 278 1188 292 1202 298V312C1202 346 1174 374 1140 374H924C890 374 862 346 862 312V298C876 292 886 278 886 262C886 246 876 232 862 226V208C862 174 890 146 924 146Z" />
                <path class="oyiso-coupon-card__shine" d="M902 166C970 152 1060 156 1142 184C1170 194 1192 210 1206 228C1198 190 1172 146 1112 146H924C892 146 864 170 862 204C874 186 886 174 902 166Z" />
                <circle class="oyiso-coupon-card__seal" cx="948" cy="260" r="38" />
                <path class="oyiso-coupon-card__seal-mark" d="M948 238V282" />
                <path class="oyiso-coupon-card__seal-mark" d="M930 248C936 240 944 236 954 236C966 236 974 244 974 256C974 274 960 282 944 282C934 282 926 278 920 270" />
                <rect class="oyiso-coupon-card__line oyiso-coupon-card__line--one" x="1006" y="220" width="136" height="16" rx="8" />
                <rect class="oyiso-coupon-card__line oyiso-coupon-card__line--two" x="1006" y="250" width="112" height="12" rx="6" />
                <rect class="oyiso-coupon-card__line oyiso-coupon-card__line--three" x="1006" y="280" width="154" height="12" rx="6" />
                <path class="oyiso-coupon-card__corner" d="M1158 168L1198 208" />
            </g>
            <g class="oyiso-ship-route">
                <path d="M486 348C602 308 746 280 900 272" />
            </g>
            <g class="oyiso-ship">
                <path class="oyiso-ship__trail" d="M482 350C564 320 644 298 726 288" />
                <g transform="translate(486 348) rotate(-14)">
                    <path class="oyiso-ship__body" d="M0 0C14 -22 46 -40 98 -48C144 -54 188 -50 214 -30C226 -20 228 -8 218 6C204 26 166 38 106 44C58 48 22 42 6 26C0 20 -2 10 0 0Z" />
                    <path class="oyiso-ship__glass" d="M134 -38C154 -40 174 -34 186 -20C172 -6 148 2 112 4C112 -14 118 -30 134 -38Z" />
                    <path class="oyiso-ship__wing" d="M68 24L32 62L90 44L108 18Z" />
                    <path class="oyiso-ship__wing" d="M168 -16L222 -44L194 -2L148 10Z" />
                    <path class="oyiso-ship__fin" d="M26 -2L-18 -18L10 16Z" />
                    <circle class="oyiso-ship__glow" cx="-8" cy="8" r="14" />
                </g>
            </g>
        </svg>
        <?php
    }

    private function render_banner_art_ocean_treasure()
    {
        ?>
        <svg viewBox="0 0 1600 520" preserveAspectRatio="xMidYMid slice" focusable="false">
            <defs>
                <linearGradient id="oyiso-ocean-bg" x1="190" y1="18" x2="1438" y2="514" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#0c3143" />
                    <stop offset="0.42" stop-color="#0f5365" />
                    <stop offset="0.74" stop-color="#124f62" />
                    <stop offset="1" stop-color="#0d2638" />
                </linearGradient>
                <radialGradient id="oyiso-ocean-glow-left" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(402 112) rotate(90) scale(188 248)">
                    <stop stop-color="#9beaff" stop-opacity="0.22" />
                    <stop offset="0.58" stop-color="#9beaff" stop-opacity="0.08" />
                    <stop offset="1" stop-color="#9beaff" stop-opacity="0" />
                </radialGradient>
                <radialGradient id="oyiso-ocean-glow-right" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(1188 182) rotate(90) scale(240 318)">
                    <stop stop-color="#5ae0d3" stop-opacity="0.16" />
                    <stop offset="0.58" stop-color="#5ae0d3" stop-opacity="0.06" />
                    <stop offset="1" stop-color="#5ae0d3" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="oyiso-water-ray" x1="0" y1="0" x2="0" y2="1">
                    <stop stop-color="#dcfbff" stop-opacity="0.34" />
                    <stop offset="1" stop-color="#dcfbff" stop-opacity="0" />
                </linearGradient>
                <linearGradient id="oyiso-reef-fill" x1="0" y1="304" x2="0" y2="520" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#1c6170" stop-opacity="0.06" />
                    <stop offset="1" stop-color="#0c2233" stop-opacity="0.88" />
                </linearGradient>
                <linearGradient id="oyiso-kelp-fill" x1="0" y1="168" x2="0" y2="520" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#83ddc2" stop-opacity="0.62" />
                    <stop offset="1" stop-color="#174a44" stop-opacity="0.98" />
                </linearGradient>
                <linearGradient id="oyiso-ocean-card-fill" x1="836" y1="138" x2="1190" y2="402" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fff8ea" stop-opacity="0.88" />
                    <stop offset="0.54" stop-color="#c9f3ee" stop-opacity="0.8" />
                    <stop offset="1" stop-color="#9ed7da" stop-opacity="0.72" />
                </linearGradient>
                <linearGradient id="oyiso-ocean-card-stroke" x1="866" y1="166" x2="1148" y2="366" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fffdf2" stop-opacity="0.82" />
                    <stop offset="1" stop-color="#8ce1d6" stop-opacity="0.3" />
                </linearGradient>
                <radialGradient id="oyiso-ocean-card-glow" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(1036 268) rotate(90) scale(118 178)">
                    <stop stop-color="#fff1cc" stop-opacity="0.24" />
                    <stop offset="0.56" stop-color="#9be7da" stop-opacity="0.14" />
                    <stop offset="1" stop-color="#9be7da" stop-opacity="0" />
                </radialGradient>
                <radialGradient id="oyiso-coin-fill" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(0 0) rotate(90) scale(28 28)">
                    <stop stop-color="#fff6d8" />
                    <stop offset="0.54" stop-color="#f0c76d" />
                    <stop offset="1" stop-color="#ad7a27" />
                </radialGradient>
                <linearGradient id="oyiso-fish-fill" x1="0" y1="-18" x2="160" y2="30" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#d8fff5" stop-opacity="0.78" />
                    <stop offset="1" stop-color="#79c9c2" stop-opacity="0.52" />
                </linearGradient>
            </defs>
            <rect width="1600" height="520" fill="url(#oyiso-ocean-bg)" />
            <rect width="1600" height="520" fill="url(#oyiso-ocean-glow-left)" />
            <rect width="1600" height="520" fill="url(#oyiso-ocean-glow-right)" />
            <g class="oyiso-water-rays">
                <path d="M206 0L304 0L382 340L292 340Z" />
                <path d="M506 0L606 0L674 300L588 300Z" />
                <path d="M1178 0L1270 0L1356 328L1278 328Z" />
            </g>
            <g class="oyiso-ocean-depth">
                <ellipse cx="822" cy="408" rx="502" ry="118" />
                <ellipse cx="1328" cy="432" rx="280" ry="90" />
            </g>
            <g class="oyiso-bubble-group">
                <circle class="oyiso-bubble oyiso-bubble--a" cx="224" cy="338" r="8" />
                <circle class="oyiso-bubble oyiso-bubble--b" cx="262" cy="286" r="5" />
                <circle class="oyiso-bubble oyiso-bubble--c" cx="1182" cy="322" r="7" />
                <circle class="oyiso-bubble oyiso-bubble--d" cx="1224" cy="276" r="4.5" />
                <circle class="oyiso-bubble oyiso-bubble--e" cx="1438" cy="244" r="6" />
            </g>
            <g class="oyiso-reef">
                <path d="M0 520V424C86 388 174 380 246 402C330 428 416 430 502 414C614 392 694 360 832 360C962 360 1036 396 1144 420C1240 442 1330 436 1416 398C1488 366 1550 366 1600 384V520H0Z" />
            </g>
            <g class="oyiso-kelp oyiso-kelp--left">
                <path d="M140 520C150 428 122 342 144 266C160 214 196 174 226 132C234 206 216 260 218 332C220 404 236 454 252 520H140Z" />
                <path d="M228 520C242 430 222 354 238 282C250 226 286 188 312 152C318 222 300 276 302 342C304 410 320 458 338 520H228Z" />
            </g>
            <g class="oyiso-kelp oyiso-kelp--right">
                <path d="M1288 520C1300 434 1278 366 1290 292C1300 238 1334 194 1358 158C1366 226 1350 282 1350 346C1350 410 1366 458 1386 520H1288Z" />
                <path d="M1380 520C1390 438 1378 380 1388 316C1396 260 1424 224 1444 190C1454 246 1442 296 1442 358C1442 416 1454 460 1472 520H1380Z" />
            </g>
            <g class="oyiso-coral oyiso-coral--left">
                <path d="M302 520C310 472 300 446 314 408C328 372 354 352 384 338C380 370 384 396 404 422C420 444 450 456 488 462C452 476 430 494 418 520H302Z" />
            </g>
            <g class="oyiso-coral oyiso-coral--right">
                <path d="M1124 520C1142 486 1164 466 1198 458C1230 450 1256 430 1274 404C1288 382 1294 360 1294 332C1328 354 1350 382 1358 418C1364 450 1354 480 1338 520H1124Z" />
            </g>
            <g class="oyiso-ocean-coins">
                <circle class="oyiso-ocean-coin oyiso-ocean-coin--a" cx="1038" cy="414" r="28" />
                <circle class="oyiso-ocean-coin oyiso-ocean-coin--b" cx="1104" cy="436" r="22" />
                <circle class="oyiso-ocean-coin oyiso-ocean-coin--c" cx="960" cy="444" r="20" />
            </g>
            <g class="oyiso-ocean-card">
                <path class="oyiso-ocean-card__glow" d="M902 180C930 150 982 136 1044 136H1088C1152 136 1206 152 1230 182C1254 212 1264 248 1264 274C1264 300 1254 336 1230 366C1206 396 1152 412 1088 412H1044C982 412 930 398 902 368C876 340 866 304 866 274C866 244 876 208 902 180Z" />
                <g transform="translate(1064 274) rotate(-10)">
                    <path class="oyiso-ocean-card__body" d="M-178 -98H162C198 -98 228 -68 228 -32V-10C212 -4 202 10 202 26C202 42 212 56 228 62V84C228 120 198 150 162 150H-178C-214 150 -244 120 -244 84V62C-228 56 -218 42 -218 26C-218 10 -228 -4 -244 -10V-32C-244 -68 -214 -98 -178 -98Z" />
                    <path class="oyiso-ocean-card__edge" d="M-178 -98H162C198 -98 228 -68 228 -32V-10C212 -4 202 10 202 26C202 42 212 56 228 62V84C228 120 198 150 162 150H-178C-214 150 -244 120 -244 84V62C-228 56 -218 42 -218 26C-218 10 -228 -4 -244 -10V-32C-244 -68 -214 -98 -178 -98Z" />
                    <path class="oyiso-ocean-card__shine" d="M-202 -72C-144 -92 -34 -96 102 -62C156 -48 198 -28 224 6C220 -42 190 -98 136 -98H-178C-204 -98 -228 -82 -238 -58C-232 -64 -220 -68 -202 -72Z" />
                    <circle class="oyiso-ocean-card__seal" cx="-132" cy="28" r="42" />
                    <path class="oyiso-ocean-card__seal-wave" d="M-154 30C-146 16 -136 10 -124 10C-110 10 -100 18 -92 34C-84 50 -74 58 -62 58" />
                    <path class="oyiso-ocean-card__seal-wave" d="M-156 58C-146 44 -134 38 -122 38C-108 38 -98 46 -92 58C-84 72 -74 80 -62 80" />
                    <rect class="oyiso-ocean-card__line oyiso-ocean-card__line--one" x="-60" y="-8" width="176" height="18" rx="9" />
                    <rect class="oyiso-ocean-card__line oyiso-ocean-card__line--two" x="-60" y="26" width="150" height="12" rx="6" />
                    <rect class="oyiso-ocean-card__line oyiso-ocean-card__line--three" x="-60" y="54" width="188" height="12" rx="6" />
                    <path class="oyiso-ocean-card__corner" d="M170 -72L220 -22" />
                </g>
            </g>
            <g class="oyiso-fish oyiso-fish--one">
                <path d="M0 0C34 -26 108 -24 152 0C108 24 34 26 0 0Z" />
                <path d="M146 0L186 -26V26L146 0Z" />
                <path d="M32 -6C52 -12 72 -12 92 0C72 12 52 12 32 -6Z" />
            </g>
            <g class="oyiso-fish oyiso-fish--two">
                <path d="M0 0C28 -20 88 -18 122 0C88 18 28 20 0 0Z" />
                <path d="M118 0L148 -18V18L118 0Z" />
                <path d="M26 -4C42 -10 58 -10 74 0C58 10 42 10 26 -4Z" />
            </g>
        </svg>
        <?php
    }

    private function render_banner_art_diamond_vault()
    {
        ?>
        <svg viewBox="0 0 1600 520" preserveAspectRatio="xMidYMid slice" focusable="false">
            <defs>
                <linearGradient id="oyiso-diamond-bg" x1="146" y1="24" x2="1458" y2="504" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#251f30" />
                    <stop offset="0.42" stop-color="#43324b" />
                    <stop offset="0.74" stop-color="#2f3549" />
                    <stop offset="1" stop-color="#1b202c" />
                </linearGradient>
                <radialGradient id="oyiso-diamond-glow-left" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(348 142) rotate(90) scale(214 286)">
                    <stop stop-color="#ffe8c4" stop-opacity="0.16" />
                    <stop offset="0.58" stop-color="#ffe8c4" stop-opacity="0.06" />
                    <stop offset="1" stop-color="#ffe8c4" stop-opacity="0" />
                </radialGradient>
                <radialGradient id="oyiso-diamond-glow-right" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(1218 202) rotate(90) scale(244 332)">
                    <stop stop-color="#d7beff" stop-opacity="0.18" />
                    <stop offset="0.56" stop-color="#d7beff" stop-opacity="0.08" />
                    <stop offset="1" stop-color="#d7beff" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="oyiso-prism-beam" x1="0" y1="0" x2="1" y2="1">
                    <stop stop-color="#fff4dc" stop-opacity="0.24" />
                    <stop offset="1" stop-color="#ffd5f0" stop-opacity="0" />
                </linearGradient>
                <linearGradient id="oyiso-diamond-fill-main" x1="-82" y1="-96" x2="92" y2="122" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fffdf8" stop-opacity="0.92" />
                    <stop offset="0.36" stop-color="#ecdfff" stop-opacity="0.72" />
                    <stop offset="1" stop-color="#a7c3ff" stop-opacity="0.42" />
                </linearGradient>
                <linearGradient id="oyiso-diamond-fill-soft" x1="-62" y1="-74" x2="72" y2="88" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fff6e8" stop-opacity="0.78" />
                    <stop offset="1" stop-color="#c5d5ff" stop-opacity="0.34" />
                </linearGradient>
                <linearGradient id="oyiso-diamond-stroke" x1="-80" y1="-88" x2="80" y2="108" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fffef8" stop-opacity="0.64" />
                    <stop offset="1" stop-color="#ffe0f2" stop-opacity="0.18" />
                </linearGradient>
                <radialGradient id="oyiso-vault-card-glow" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(890 284) rotate(90) scale(126 190)">
                    <stop stop-color="#fff2d5" stop-opacity="0.2" />
                    <stop offset="0.58" stop-color="#d8c2ff" stop-opacity="0.12" />
                    <stop offset="1" stop-color="#d8c2ff" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="oyiso-vault-card-fill" x1="690" y1="154" x2="1028" y2="414" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fff8ef" stop-opacity="0.84" />
                    <stop offset="0.52" stop-color="#eee6ff" stop-opacity="0.7" />
                    <stop offset="1" stop-color="#d4dff9" stop-opacity="0.62" />
                </linearGradient>
                <linearGradient id="oyiso-vault-card-stroke" x1="714" y1="176" x2="982" y2="382" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fffdf7" stop-opacity="0.68" />
                    <stop offset="1" stop-color="#f0cfff" stop-opacity="0.22" />
                </linearGradient>
                <linearGradient id="oyiso-vault-gem" x1="0" y1="-30" x2="0" y2="30" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fff5d1" />
                    <stop offset="0.46" stop-color="#d7bcff" />
                    <stop offset="1" stop-color="#759df0" />
                </linearGradient>
            </defs>
            <rect width="1600" height="520" fill="url(#oyiso-diamond-bg)" />
            <rect width="1600" height="520" fill="url(#oyiso-diamond-glow-left)" />
            <rect width="1600" height="520" fill="url(#oyiso-diamond-glow-right)" />
            <g class="oyiso-prism-haze">
                <path d="M152 56L412 0L578 188L306 244Z" />
                <path d="M968 52L1282 38L1498 230L1158 244Z" />
                <path d="M1116 316L1456 276L1600 430L1278 456Z" />
            </g>
            <g class="oyiso-vault-arcs">
                <path d="M138 394C286 288 492 238 706 242C928 246 1140 314 1372 430" />
                <path d="M240 108C402 74 584 78 790 122C986 164 1164 234 1360 330" />
            </g>
            <g class="oyiso-vault-card">
                <path class="oyiso-vault-card__glow" d="M720 188C744 156 798 140 860 140H910C970 140 1020 154 1044 184C1066 214 1074 248 1074 276C1074 304 1066 338 1044 368C1020 398 970 412 910 412H860C798 412 744 398 720 366C698 336 690 304 690 276C690 248 698 216 720 188Z" />
                <path class="oyiso-vault-card__body" d="M760 164H1006C1042 164 1072 194 1072 230V246C1056 252 1046 266 1046 282C1046 298 1056 312 1072 318V334C1072 370 1042 400 1006 400H760C724 400 694 370 694 334V318C710 312 720 298 720 282C720 266 710 252 694 246V230C694 194 724 164 760 164Z" />
                <path class="oyiso-vault-card__edge" d="M760 164H1006C1042 164 1072 194 1072 230V246C1056 252 1046 266 1046 282C1046 298 1056 312 1072 318V334C1072 370 1042 400 1006 400H760C724 400 694 370 694 334V318C710 312 720 298 720 282C720 266 710 252 694 246V230C694 194 724 164 760 164Z" />
                <path class="oyiso-vault-card__shine" d="M732 192C792 172 876 172 962 198C1016 214 1050 238 1070 264C1064 210 1032 164 978 164H760C736 164 714 176 702 196C710 194 720 194 732 192Z" />
                <g class="oyiso-vault-card__badge" transform="translate(786 282)">
                    <path class="oyiso-vault-card__gem" d="M0 -36L28 -6L0 36L-28 -6L0 -36Z" />
                    <path class="oyiso-vault-card__gem-facet" d="M0 -36V36" />
                    <path class="oyiso-vault-card__gem-facet" d="M-28 -6H28" />
                </g>
                <rect class="oyiso-vault-card__line oyiso-vault-card__line--one" x="842" y="238" width="150" height="16" rx="8" />
                <rect class="oyiso-vault-card__line oyiso-vault-card__line--two" x="842" y="268" width="126" height="12" rx="6" />
                <rect class="oyiso-vault-card__line oyiso-vault-card__line--three" x="842" y="296" width="166" height="12" rx="6" />
                <path class="oyiso-vault-card__corner" d="M1018 188L1062 232" />
            </g>
            <g class="oyiso-diamond-cluster">
                <g class="oyiso-diamond oyiso-diamond--main" transform="translate(1230 236)">
                    <path d="M0 -110L92 -12L0 122L-92 -12L0 -110Z" />
                    <path d="M0 -110L0 122" />
                    <path d="M-92 -12H92" />
                    <path d="M-92 -12L0 30L92 -12" />
                </g>
                <g class="oyiso-diamond oyiso-diamond--b" transform="translate(1098 158)">
                    <path d="M0 -64L54 -8L0 72L-54 -8L0 -64Z" />
                    <path d="M0 -64L0 72" />
                    <path d="M-54 -8H54" />
                </g>
                <g class="oyiso-diamond oyiso-diamond--c" transform="translate(1388 344)">
                    <path d="M0 -48L42 -6L0 56L-42 -6L0 -48Z" />
                    <path d="M0 -48L0 56" />
                    <path d="M-42 -6H42" />
                </g>
                <g class="oyiso-diamond oyiso-diamond--d" transform="translate(992 358)">
                    <path d="M0 -38L34 -4L0 44L-34 -4L0 -38Z" />
                    <path d="M0 -38L0 44" />
                    <path d="M-34 -4H34" />
                </g>
            </g>
            <g class="oyiso-diamond-sparkles">
                <g class="oyiso-diamond-sparkle oyiso-diamond-sparkle--a" transform="translate(430 138)">
                    <path d="M0 -16V16" />
                    <path d="M-16 0H16" />
                </g>
                <g class="oyiso-diamond-sparkle oyiso-diamond-sparkle--b" transform="translate(1184 108)">
                    <path d="M0 -12V12" />
                    <path d="M-12 0H12" />
                </g>
                <g class="oyiso-diamond-sparkle oyiso-diamond-sparkle--c" transform="translate(1450 206)">
                    <path d="M0 -14V14" />
                    <path d="M-14 0H14" />
                </g>
                <g class="oyiso-diamond-sparkle oyiso-diamond-sparkle--d" transform="translate(1114 426)">
                    <path d="M0 -12V12" />
                    <path d="M-12 0H12" />
                </g>
            </g>
        </svg>
        <?php
    }

    private function render_banner_art_mall_parade()
    {
        ?>
        <svg viewBox="0 0 1600 520" preserveAspectRatio="xMidYMid slice" focusable="false">
            <defs>
                <linearGradient id="oyiso-mall-bg" x1="128" y1="18" x2="1464" y2="504" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#24374d" />
                    <stop offset="0.38" stop-color="#5d4a5e" />
                    <stop offset="0.72" stop-color="#775462" />
                    <stop offset="1" stop-color="#2e3544" />
                </linearGradient>
                <radialGradient id="oyiso-mall-glow-left" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(314 176) rotate(90) scale(206 286)">
                    <stop stop-color="#ffdcb0" stop-opacity="0.18" />
                    <stop offset="0.56" stop-color="#ffdcb0" stop-opacity="0.08" />
                    <stop offset="1" stop-color="#ffdcb0" stop-opacity="0" />
                </radialGradient>
                <radialGradient id="oyiso-mall-glow-right" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(1234 164) rotate(90) scale(240 338)">
                    <stop stop-color="#ffbfd6" stop-opacity="0.16" />
                    <stop offset="0.58" stop-color="#ffbfd6" stop-opacity="0.08" />
                    <stop offset="1" stop-color="#ffbfd6" stop-opacity="0" />
                </radialGradient>
                <linearGradient id="oyiso-mall-floor" x1="0" y1="332" x2="0" y2="520" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#f9dcc2" stop-opacity="0.08" />
                    <stop offset="1" stop-color="#100f18" stop-opacity="0.82" />
                </linearGradient>
                <linearGradient id="oyiso-mall-glass" x1="0" y1="0" x2="0" y2="1">
                    <stop stop-color="#fff6e8" stop-opacity="0.28" />
                    <stop offset="1" stop-color="#fff6e8" stop-opacity="0.06" />
                </linearGradient>
                <linearGradient id="oyiso-mall-window" x1="0" y1="0" x2="1" y2="1">
                    <stop stop-color="#ffe5bb" stop-opacity="0.54" />
                    <stop offset="1" stop-color="#f0b2e4" stop-opacity="0.22" />
                </linearGradient>
                <linearGradient id="oyiso-mall-awning" x1="0" y1="0" x2="1" y2="0">
                    <stop stop-color="#f1c483" stop-opacity="0.46" />
                    <stop offset="1" stop-color="#e894a7" stop-opacity="0.28" />
                </linearGradient>
                <linearGradient id="oyiso-mall-sign-fill" x1="730" y1="102" x2="994" y2="300" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fff8ef" stop-opacity="0.84" />
                    <stop offset="0.52" stop-color="#ffe3ef" stop-opacity="0.72" />
                    <stop offset="1" stop-color="#dbe6ff" stop-opacity="0.62" />
                </linearGradient>
                <linearGradient id="oyiso-mall-sign-stroke" x1="754" y1="126" x2="960" y2="278" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fffdf8" stop-opacity="0.62" />
                    <stop offset="1" stop-color="#ffd7e4" stop-opacity="0.2" />
                </linearGradient>
                <radialGradient id="oyiso-mall-sign-glow" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(864 206) rotate(90) scale(104 154)">
                    <stop stop-color="#fff0c9" stop-opacity="0.22" />
                    <stop offset="0.6" stop-color="#ffcae2" stop-opacity="0.12" />
                    <stop offset="1" stop-color="#ffcae2" stop-opacity="0" />
                </radialGradient>
            </defs>
            <rect width="1600" height="520" fill="url(#oyiso-mall-bg)" />
            <rect width="1600" height="520" fill="url(#oyiso-mall-glow-left)" />
            <rect width="1600" height="520" fill="url(#oyiso-mall-glow-right)" />
            <g class="oyiso-mall-skyline">
                <path d="M84 248V142H184V248" />
                <path d="M244 248V112H372V248" />
                <path d="M1240 248V124H1360V248" />
                <path d="M1408 248V158H1502V248" />
            </g>
            <rect class="oyiso-mall-floor" y="334" width="1600" height="186" />
            <g class="oyiso-mall-lanes">
                <path d="M250 436H564" />
                <path d="M1036 436H1368" />
            </g>
            <g class="oyiso-storefront oyiso-storefront--left">
                <rect class="oyiso-storefront__shell" x="126" y="132" width="286" height="210" rx="26" />
                <rect class="oyiso-storefront__glass" x="152" y="158" width="234" height="138" rx="18" />
                <rect class="oyiso-storefront__window" x="176" y="182" width="82" height="90" rx="14" />
                <rect class="oyiso-storefront__window" x="276" y="182" width="82" height="90" rx="14" />
                <rect class="oyiso-storefront__awning" x="170" y="120" width="198" height="24" rx="12" />
                <rect class="oyiso-storefront__plate" x="202" y="306" width="134" height="10" rx="5" />
            </g>
            <g class="oyiso-storefront oyiso-storefront--center">
                <rect class="oyiso-storefront__shell" x="520" y="94" width="398" height="248" rx="30" />
                <rect class="oyiso-storefront__glass" x="552" y="128" width="334" height="164" rx="22" />
                <rect class="oyiso-storefront__window" x="586" y="156" width="116" height="108" rx="16" />
                <rect class="oyiso-storefront__window" x="722" y="156" width="130" height="108" rx="16" />
                <rect class="oyiso-storefront__awning" x="596" y="82" width="246" height="28" rx="14" />
                <rect class="oyiso-storefront__plate" x="650" y="306" width="136" height="10" rx="5" />
            </g>
            <g class="oyiso-storefront oyiso-storefront--right">
                <rect class="oyiso-storefront__shell" x="1038" y="128" width="312" height="214" rx="26" />
                <rect class="oyiso-storefront__glass" x="1068" y="156" width="252" height="140" rx="18" />
                <rect class="oyiso-storefront__window" x="1096" y="182" width="86" height="90" rx="14" />
                <rect class="oyiso-storefront__window" x="1204" y="182" width="86" height="90" rx="14" />
                <rect class="oyiso-storefront__awning" x="1088" y="116" width="214" height="24" rx="12" />
                <rect class="oyiso-storefront__plate" x="1118" y="306" width="152" height="10" rx="5" />
            </g>
            <g class="oyiso-mall-sign">
                <path class="oyiso-mall-sign__glow" d="M764 132C786 108 832 96 882 96C934 96 980 108 1002 132C1022 156 1030 186 1030 208C1030 230 1022 260 1002 284C980 308 934 320 882 320C832 320 786 308 764 284C744 260 736 230 736 208C736 186 744 156 764 132Z" />
                <g transform="translate(882 208) rotate(-7)">
                    <path class="oyiso-mall-sign__body" d="M-118 -74H98C132 -74 160 -46 160 -12V6C146 12 138 24 138 38C138 52 146 64 160 70V88C160 122 132 150 98 150H-118C-152 150 -180 122 -180 88V70C-166 64 -158 52 -158 38C-158 24 -166 12 -180 6V-12C-180 -46 -152 -74 -118 -74Z" />
                    <path class="oyiso-mall-sign__edge" d="M-118 -74H98C132 -74 160 -46 160 -12V6C146 12 138 24 138 38C138 52 146 64 160 70V88C160 122 132 150 98 150H-118C-152 150 -180 122 -180 88V70C-166 64 -158 52 -158 38C-158 24 -166 12 -180 6V-12C-180 -46 -152 -74 -118 -74Z" />
                    <path class="oyiso-mall-sign__shine" d="M-142 -52C-86 -70 -10 -68 68 -42C110 -28 138 -8 156 18C152 -26 124 -74 78 -74H-118C-142 -74 -162 -62 -174 -42C-168 -46 -158 -50 -142 -52Z" />
                    <circle class="oyiso-mall-sign__dot" cx="-82" cy="38" r="26" />
                    <rect class="oyiso-mall-sign__line oyiso-mall-sign__line--one" x="-32" y="2" width="112" height="14" rx="7" />
                    <rect class="oyiso-mall-sign__line oyiso-mall-sign__line--two" x="-32" y="30" width="94" height="10" rx="5" />
                    <rect class="oyiso-mall-sign__line oyiso-mall-sign__line--three" x="-32" y="54" width="126" height="10" rx="5" />
                    <path class="oyiso-mall-sign__corner" d="M110 -46L152 -4" />
                </g>
            </g>
            <g class="oyiso-shopper oyiso-shopper--one">
                <circle cx="0" cy="-40" r="16" />
                <path d="M-8 -20C-4 -12 4 -8 12 -8C18 -8 22 -4 22 4V54H-14V4C-14 -8 -12 -16 -8 -20Z" />
                <path d="M-10 54L-20 98" />
                <path d="M12 54L24 98" />
                <rect x="22" y="12" width="24" height="28" rx="5" />
            </g>
            <g class="oyiso-shopper oyiso-shopper--two">
                <circle cx="0" cy="-34" r="14" />
                <path d="M-10 -16C-4 -8 2 -4 12 -4C20 -4 24 2 24 12V58H-16V8C-16 -4 -14 -10 -10 -16Z" />
                <path d="M-6 58L-14 96" />
                <path d="M14 58L26 96" />
                <rect x="-42" y="14" width="22" height="26" rx="5" />
            </g>
            <g class="oyiso-shopper oyiso-shopper--three">
                <circle cx="0" cy="-32" r="13" />
                <path d="M-10 -14C-4 -6 2 -2 10 -2C18 -2 22 4 22 14V56H-14V10C-14 0 -12 -8 -10 -14Z" />
                <path d="M-6 56L-16 92" />
                <path d="M12 56L22 92" />
                <rect x="18" y="14" width="20" height="24" rx="4" />
            </g>
        </svg>
        <?php
    }

    private function has_banner_background(array $settings, string $banner_style)
    {
        if ($banner_style) {
            return true;
        }

        return ($settings['banner_background'] ?? 'none') !== 'none';
    }

    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $accent_color = sanitize_hex_color($settings['accent_color'] ?? '#e5702a') ?: '#e5702a';
        $groups = $this->prepare_coupon_groups($settings['coupon_groups'] ?? [], $accent_color);
        $banner_style = $this->get_banner_background_style($settings);
        $has_banner_background = $this->has_banner_background($settings, $banner_style);
        $banner_preset_key = $this->get_banner_preset_key($settings);
        $widget_id = 'oyiso-coupons-' . esc_attr($this->get_id());

        if (!class_exists('WC_Coupon')) {
            $this->render_notice(__('Enable WooCommerce before using the Coupons widget.', 'oyiso'));
            return;
        }

        if (empty($groups)) {
            $this->render_notice(__('Add a coupon group and choose WooCommerce coupons in the widget settings first.', 'oyiso'));
            return;
        }
        ?>
        <section id="<?php echo esc_attr($widget_id); ?>" class="oyiso-coupons" data-oyiso-coupons>
            <div class="oyiso-coupons__banner<?php echo $has_banner_background ? '' : ' oyiso-coupons__banner--plain'; ?>" <?php echo $banner_style ? 'style="' . esc_attr($banner_style) . '"' : ''; ?>>
                <?php $this->render_banner_art($banner_preset_key); ?>
                <div class="oyiso-coupons__banner-overlay"></div>
                <div class="oyiso-coupons__banner-content">
                    <?php if (!empty($settings['banner_kicker'])) : ?>
                        <span class="oyiso-coupons__kicker"><?php echo esc_html($settings['banner_kicker']); ?></span>
                    <?php endif; ?>

                    <?php if (!empty($settings['banner_title'])) : ?>
                        <h2 class="oyiso-coupons__title"><?php echo esc_html($settings['banner_title']); ?></h2>
                    <?php endif; ?>

                    <?php if (!empty($settings['banner_description'])) : ?>
                        <p class="oyiso-coupons__description"><?php echo esc_html($settings['banner_description']); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="oyiso-coupons__body">
                <div class="oyiso-coupons__tabs" role="tablist">
                    <span class="oyiso-coupons__tabs-slider" data-coupon-tabs-slider aria-hidden="true"></span>
                    <?php
                    $tab_index = 0;
                    foreach ($groups as $category_key => $group) :
                        $is_active = $tab_index === 0;
                        $tab_style = $category_key === 'all' ? '' : 'style="--oyiso-group-color: ' . esc_attr($group['color']) . ';"';
                        ?>
                        <button
                            class="oyiso-coupons__tab<?php echo $is_active ? ' is-active' : ''; ?>"
                            type="button"
                            role="tab"
                            aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
                            aria-controls="<?php echo esc_attr($widget_id . '-panel-' . $category_key); ?>"
                            data-coupon-tab="<?php echo esc_attr($category_key); ?>"
                            <?php echo $tab_style; ?>
                        >
                            <span class="oyiso-coupons__tab-label"><?php echo esc_html($group['label']); ?></span>
                            <span class="oyiso-coupons__tab-count"><?php echo esc_html(count($group['items'])); ?></span>
                        </button>
                        <?php
                        $tab_index++;
                    endforeach;
                    ?>
                </div>

                <?php
                $panel_index = 0;
                foreach ($groups as $category_key => $group) :
                    $is_active = $panel_index === 0;
                    ?>
                    <div
                        id="<?php echo esc_attr($widget_id . '-panel-' . $category_key); ?>"
                        class="oyiso-coupons__panel<?php echo $is_active ? ' is-active' : ''; ?>"
                        role="tabpanel"
                        data-coupon-panel="<?php echo esc_attr($category_key); ?>"
                        <?php echo $is_active ? '' : 'hidden'; ?>
                    >
                        <div class="oyiso-coupons__grid">
                            <?php
                            foreach ($group['items'] as $coupon_index => $coupon) :
                                $card_group = $this->get_coupon_card_group($coupon, $group, $category_key);
                                $this->render_coupon_card($coupon, $category_key . '-' . $coupon_index, $settings, $card_group);
                            endforeach;
                            ?>
                        </div>
                    </div>
                    <?php
                    $panel_index++;
                endforeach;
                ?>
            </div>
        </section>
        <?php
    }

    private function get_wc_coupon_options()
    {
        if (!post_type_exists('shop_coupon')) {
            return [];
        }

        $posts = get_posts([
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);

        $options = [];

        foreach ($posts as $post_id) {
            $code = get_the_title($post_id);
            $options[$post_id] = $code;
        }

        return $options;
    }

    private function prepare_coupon_groups(array $settings_groups, string $accent_color = '#e5702a')
    {
        if (!class_exists('WC_Coupon')) {
            return [];
        }

        $groups = [];
        $all_items = [];
        $used_coupon_ids = [];

        foreach ($settings_groups as $group_index => $settings_group) {
            $label = trim($settings_group['group_name'] ?? '');
            $coupon_ids = $settings_group['coupon_ids'] ?? [];
            $group_color = sanitize_hex_color($settings_group['group_color'] ?? '') ?: $accent_color;
            $group_icon = $this->normalize_group_icon($settings_group['group_icon'] ?? []);

            if ($label === '') {
                $label = sprintf(__('Group %d', 'oyiso'), $group_index + 1);
            }

            if (!is_array($coupon_ids)) {
                $coupon_ids = array_filter([(int) $coupon_ids]);
            }

            $items = [];

            foreach ($coupon_ids as $coupon_id) {
                $coupon_id = absint($coupon_id);

                if (!$coupon_id || isset($used_coupon_ids[$coupon_id]) || get_post_status($coupon_id) !== 'publish') {
                    continue;
                }

                $coupon = $this->get_coupon_data($coupon_id);

                if (!$coupon) {
                    continue;
                }

                $used_coupon_ids[$coupon_id] = true;
                $items[] = $coupon;
                $all_items[] = $coupon + [
                    'group_color' => $group_color,
                    'group_icon'  => $group_icon,
                ];
            }

            if (!empty($items)) {
                $groups['group-' . substr(md5($label . $group_index), 0, 10)] = [
                    'label' => $label,
                    'items' => $items,
                    'color' => $group_color,
                    'icon'  => $group_icon,
                ];
            }
        }

        if (empty($all_items)) {
            return [];
        }

        return ['all' => [
            'label' => __('All', 'oyiso'),
            'items' => $all_items,
            'color' => $accent_color,
            'icon'  => $this->get_default_group_icon(),
        ]] + $groups;
    }

    private function normalize_group_icon(array $icon)
    {
        return !empty($icon['value']) ? $icon : $this->get_default_group_icon();
    }

    private function get_default_group_icon()
    {
        return [
            'value'   => 'fas fa-ticket-alt',
            'library' => 'fa-solid',
        ];
    }

    private function get_coupon_data(int $coupon_id)
    {
        $coupon = new \WC_Coupon($coupon_id);
        $code = strtoupper($coupon->get_code());

        if (!$code) {
            return null;
        }

        $date_expires = $coupon->get_date_expires();
        $description = get_post_field('post_excerpt', $coupon_id);

        if (!$description) {
            $description = get_post_field('post_content', $coupon_id);
        }

        return [
            'id'             => $coupon_id,
            'code'           => $code,
            'discount'       => $this->format_coupon_discount($coupon),
            'discount_label' => $this->format_coupon_type_label($coupon),
            'description'    => $description ?: __('Apply this code at checkout to claim the offer.', 'oyiso'),
            'scope'          => $this->format_coupon_scope($coupon),
            'remaining'      => $this->get_coupon_remaining_data($coupon),
            'validity'       => $this->get_coupon_validity_data($coupon),
        ];
    }

    private function get_coupon_card_group(array $coupon, array $group, string $category_key)
    {
        if ($category_key !== 'all') {
            return $group;
        }

        return [
            'label' => $group['label'] ?? __('All', 'oyiso'),
            'items' => $group['items'] ?? [],
            'color' => $coupon['group_color'] ?? ($group['color'] ?? '#e5702a'),
            'icon'  => $coupon['group_icon'] ?? ($group['icon'] ?? $this->get_default_group_icon()),
        ];
    }

    private function format_coupon_discount(\WC_Coupon $coupon)
    {
        $amount = (float) $coupon->get_amount();
        $type = $coupon->get_discount_type();

        if ($type === 'percent') {
            return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . '%';
        }

        if (function_exists('wc_price')) {
            return wp_strip_all_tags(wc_price($amount));
        }

        return (string) $amount;
    }

    private function format_coupon_type_label(\WC_Coupon $coupon)
    {
        $type = $coupon->get_discount_type();

        if ($type === 'percent') {
            return __('Percentage Discount', 'oyiso');
        }

        if ($type === 'fixed_cart') {
            return __('Order Discount', 'oyiso');
        }

        if ($type === 'fixed_product') {
            return __('Product Discount', 'oyiso');
        }

        return __('Offer', 'oyiso');
    }

    private function format_coupon_scope(\WC_Coupon $coupon)
    {
        $product_ids = $coupon->get_product_ids();
        $category_ids = $coupon->get_product_categories();
        $minimum_amount = (float) $coupon->get_minimum_amount();
        $maximum_amount = (float) $coupon->get_maximum_amount();
        $product_links = [];
        $category_links = [];

        if (!empty($product_ids)) {
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);

                if ($product) {
                    $url = get_permalink($product_id);

                    if ($url) {
                        $product_links[] = sprintf(
                            '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                            esc_url($url),
                            esc_html($product->get_name())
                        );
                    } else {
                        $product_links[] = esc_html($product->get_name());
                    }
                }
            }
        }

        if (!empty($category_ids)) {
            foreach ($category_ids as $category_id) {
                $term = get_term($category_id, 'product_cat');

                if ($term && !is_wp_error($term)) {
                    $url = get_term_link($term);

                    if (!is_wp_error($url) && $url) {
                        $category_links[] = sprintf(
                            '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                            esc_url($url),
                            esc_html($term->name)
                        );
                    } else {
                        $category_links[] = esc_html($term->name);
                    }
                }
            }
        }

        $all_products = __('All Products', 'oyiso');
        $all_categories = __('All Categories', 'oyiso');
        $no_minimum = __('No Minimum', 'oyiso');
        $no_maximum = __('No Maximum', 'oyiso');

        $eligibility_rows = [
            $this->format_coupon_scope_row(
                __('Applies to Products', 'oyiso'),
                $this->format_coupon_scope_collection($product_links, $all_products)
            ),
            $this->format_coupon_scope_row(
                __('Applies to Categories', 'oyiso'),
                $this->format_coupon_scope_collection($category_links, $all_categories)
            ),
        ];

        $conditions_rows = [
            $this->format_coupon_scope_row(
                __('Minimum Spend', 'oyiso'),
                esc_html($minimum_amount > 0 ? $this->format_coupon_money($minimum_amount) : $no_minimum)
            ),
            $this->format_coupon_scope_row(
                __('Maximum Spend', 'oyiso'),
                esc_html($maximum_amount > 0 ? $this->format_coupon_money($maximum_amount) : $no_maximum)
            ),
            $this->format_coupon_scope_row(
                __('Free Shipping', 'oyiso'),
                esc_html($coupon->get_free_shipping() ? __('Yes', 'oyiso') : __('No', 'oyiso'))
            ),
        ];

        return implode('', [
            $this->format_coupon_scope_section(__('Eligibility', 'oyiso'), $eligibility_rows),
            $this->format_coupon_scope_section(__('Conditions', 'oyiso'), $conditions_rows),
        ]);
    }

    private function format_coupon_scope_row(string $label, string $value_html)
    {
        return sprintf(
            '<div class="oyiso-scope-dialog__row"><div class="oyiso-scope-dialog__label">%1$s</div><div class="oyiso-scope-dialog__value">%2$s</div></div>',
            esc_html($label),
            $value_html
        );
    }

    private function format_coupon_scope_section(string $title, array $rows)
    {
        return sprintf(
            '<section class="oyiso-scope-dialog__section"><h4 class="oyiso-scope-dialog__section-title">%1$s</h4><div class="oyiso-scope-dialog__section-card"><div class="oyiso-scope-dialog__section-body">%2$s</div></div></section>',
            esc_html($title),
            implode('', array_filter($rows))
        );
    }

    private function format_coupon_scope_collection(array $items, string $fallback)
    {
        if (empty($items)) {
            $items = [esc_html($fallback)];
        }

        $entries = array_map(static function ($item) {
            return sprintf('<div class="oyiso-scope-dialog__list-item">%s</div>', $item);
        }, $items);

        return '<div class="oyiso-scope-dialog__list">' . implode('', $entries) . '</div>';
    }

    private function format_coupon_money(float $amount)
    {
        if (function_exists('wc_price')) {
            return wp_strip_all_tags(wc_price($amount));
        }

        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private function get_coupon_remaining_data(\WC_Coupon $coupon)
    {
        $usage_limit = (int) $coupon->get_usage_limit();
        $usage_count = (int) $coupon->get_usage_count();

        if ($usage_limit <= 0) {
            return [
                'label' => __('Remaining Uses', 'oyiso'),
                'value' => __('Unlimited', 'oyiso'),
                'percent' => 100,
            ];
        }

        $remaining = max(0, $usage_limit - $usage_count);

        return [
            'label' => __('Remaining Uses', 'oyiso'),
            'value' => sprintf(__('%1$d / %2$d', 'oyiso'), $remaining, $usage_limit),
            'percent' => max(0, min(100, ($remaining / $usage_limit) * 100)),
        ];
    }

    private function get_coupon_validity_data(\WC_Coupon $coupon)
    {
        $date_expires = $coupon->get_date_expires();

        if (!$date_expires) {
            return [
                'label' => __('Expiry Date', 'oyiso'),
                'value' => __('No Expiry Date', 'oyiso'),
                'percent' => 100,
            ];
        }

        $expires_timestamp = $date_expires->getTimestamp();
        $created = $coupon->get_date_created();
        $created_timestamp = $created ? $created->getTimestamp() : current_time('timestamp', true);
        $now = current_time('timestamp', true);
        $duration = max(1, $expires_timestamp - $created_timestamp);
        $remaining = max(0, $expires_timestamp - $now);

        return [
            'label' => __('Expiry Date', 'oyiso'),
            'value' => $date_expires->date('Y-m-d'),
            'percent' => max(0, min(100, ($remaining / $duration) * 100)),
        ];
    }

    private function render_notice(string $message)
    {
        ?>
        <div class="oyiso-coupons oyiso-coupons--empty">
            <?php echo esc_html($message); ?>
        </div>
        <?php
    }

    private function render_coupon_card(array $coupon, string $attribute_key, array $settings, array $group)
    {
        $description = $coupon['description'] ?? '';
        $discount = $coupon['discount'] ?? '';
        $discount_label = $coupon['discount_label'] ?? '';
        $code = $coupon['code'] ?? '';
        $scope = $coupon['scope'] ?? '';
        $remaining = $coupon['remaining'] ?? [];
        $validity = $coupon['validity'] ?? [];
        ?>
        <article class="oyiso-coupon-card" style="--oyiso-group-color: <?php echo esc_attr($group['color'] ?? '#e5702a'); ?>;">
            <div class="oyiso-coupon-card__icon" aria-hidden="true">
                <?php
                if (!empty($group['icon']['value'])) {
                    Icons_Manager::render_icon($group['icon'], ['aria-hidden' => 'true']);
                }
                ?>
            </div>

            <div class="oyiso-coupon-card__content">
                <?php if ($code || $scope) : ?>
                    <div class="oyiso-coupon-card__head">
                        <div class="oyiso-coupon-card__identity">
                            <?php if ($code) : ?>
                                <span class="oyiso-coupon-card__mobile-icon" aria-hidden="true">
                                    <?php
                                    if (!empty($group['icon']['value'])) {
                                        Icons_Manager::render_icon($group['icon'], ['aria-hidden' => 'true']);
                                    }
                                    ?>
                                </span>
                                <div class="oyiso-coupon-card__code-row">
                                    <span class="oyiso-coupon-card__code"><?php echo esc_html($code); ?></span>

                                    <button
                                        class="oyiso-coupon-card__copy-button oyiso-coupon-card__copy-button--desktop"
                                        type="button"
                                        data-coupon-copy="<?php echo esc_attr($code); ?>"
                                        data-copied-text="<?php echo esc_attr__('Copied', 'oyiso'); ?>"
                                    >
                                        <?php echo esc_html__('Copy Code', 'oyiso'); ?>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($scope) : ?>
                            <div class="oyiso-coupon-card__actions">
                                <button
                                    class="oyiso-coupon-card__scope-button oyiso-coupon-card__scope-button--desktop"
                                    type="button"
                                    data-coupon-scope="<?php echo esc_attr($scope); ?>"
                                    data-coupon-code="<?php echo esc_attr($code); ?>"
                                >
                                    <?php echo esc_html__('Details', 'oyiso'); ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($discount) : ?>
                    <div class="oyiso-coupon-card__discount"><?php echo esc_html($discount); ?></div>
                <?php endif; ?>

                <?php if ($description) : ?>
                    <div class="oyiso-coupon-card__description" data-coupon-description>
                        <div class="oyiso-coupon-card__description-viewport" data-coupon-description-viewport>
                            <p class="oyiso-coupon-card__text"><?php echo esc_html($description); ?></p>
                        </div>
                        <button
                            class="oyiso-coupon-card__description-toggle"
                            type="button"
                            data-coupon-description-toggle
                            data-expand-text="<?php echo esc_attr__('Show More', 'oyiso'); ?>"
                            data-collapse-text="<?php echo esc_attr__('Show Less', 'oyiso'); ?>"
                            aria-expanded="false"
                            hidden
                        >
                            <?php echo esc_html__('Show More', 'oyiso'); ?>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="oyiso-coupon-card__progress-list">
                    <?php if (!empty($remaining)) : ?>
                        <?php $this->render_coupon_progress($remaining); ?>
                    <?php endif; ?>

                    <?php if (!empty($validity)) : ?>
                        <?php $this->render_coupon_progress($validity); ?>
                    <?php endif; ?>
                </div>

                <?php if ($code || $scope) : ?>
                    <div class="oyiso-coupon-card__mobile-actions<?php echo ($code && $scope) ? ' oyiso-coupon-card__mobile-actions--dual' : ' oyiso-coupon-card__mobile-actions--single'; ?>">
                        <?php if ($scope) : ?>
                            <button
                                class="oyiso-coupon-card__scope-button oyiso-coupon-card__scope-button--mobile"
                                type="button"
                                data-coupon-scope="<?php echo esc_attr($scope); ?>"
                                data-coupon-code="<?php echo esc_attr($code); ?>"
                            >
                                <?php echo esc_html__('Details', 'oyiso'); ?>
                            </button>
                        <?php endif; ?>

                        <?php if ($code) : ?>
                            <button
                                class="oyiso-coupon-card__copy-button oyiso-coupon-card__copy-button--mobile"
                                type="button"
                                data-coupon-copy="<?php echo esc_attr($code); ?>"
                                data-copied-text="<?php echo esc_attr__('Copied', 'oyiso'); ?>"
                            >
                                <?php echo esc_html__('Copy Code', 'oyiso'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
        <?php
    }

    private function render_coupon_progress(array $data)
    {
        $percent = isset($data['percent']) ? (float) $data['percent'] : 0;
        ?>
        <div class="oyiso-coupon-card__progress">
            <div class="oyiso-coupon-card__progress-head">
                <span><?php echo esc_html($data['label'] ?? ''); ?></span>
                <strong><?php echo esc_html($data['value'] ?? ''); ?></strong>
            </div>
            <div class="oyiso-coupon-card__progress-track" aria-hidden="true">
                <span style="width: <?php echo esc_attr(max(0, min(100, $percent))); ?>%;"></span>
            </div>
        </div>
        <?php
    }
}
