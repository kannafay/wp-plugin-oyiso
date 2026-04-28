<?php

namespace Oyiso\ElementorWidgets;

defined('ABSPATH') || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Repeater;
use Elementor\Widget_Base;

class Coupon_Lottery extends Widget_Base
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
        return 'oyiso_coupon_lottery';
    }

    public function get_title()
    {
        return __('Oyiso Coupon Lottery', 'oyiso');
    }

    public function get_icon()
    {
        return 'eicon-price-table';
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
        return ['oyiso-coupon-tabs', 'oyiso-coupon-lottery'];
    }

    protected function register_controls()
    {
        $this->start_controls_section('content_section', [
            'label' => __('内容', 'oyiso'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('title', [
            'label'   => __('标题', 'oyiso'),
            'type'    => Controls_Manager::TEXT,
            'default' => $this->get_site_default_text('Coupon Lottery'),
        ]);

        $this->add_control('description', [
            'label'   => __('抽奖说明', 'oyiso'),
            'type'    => Controls_Manager::TEXTAREA,
            'default' => $this->get_site_default_text('Log in to join the draw. If you win, click claim to generate your exclusive coupon.'),
        ]);

        $this->end_controls_section();

        $this->start_controls_section('lottery_section', [
            'label' => __('抽奖设置', 'oyiso'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('lottery_intro', [
            'type'            => Controls_Manager::RAW_HTML,
            'raw'             => esc_html__('每条规则填写一个概率。开启参与奖时，剩余概率会自动归入参与奖；关闭时，系统会把所有中奖规则按比例补足到 100%。', 'oyiso'),
            'content_classes' => 'elementor-descriptor',
        ]);

        $this->add_control('range_type', [
            'label'   => __('优惠类型', 'oyiso'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'percent',
            'options' => [
                'percent' => __('百分比', 'oyiso'),
                'amount'  => __('固定金额', 'oyiso'),
            ],
        ]);

        $percent_rule_repeater = new Repeater();
        $percent_rule_repeater->add_control('mode', [
            'label'   => __('规则类型', 'oyiso'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'range',
            'options' => [
                'range'  => __('区间', 'oyiso'),
                'single' => __('单个值', 'oyiso'),
            ],
        ]);

        $percent_rule_repeater->add_control('start_percent', [
            'label'       => __('起始值', 'oyiso'),
            'type'        => Controls_Manager::SLIDER,
            'size_units'  => ['%'],
            'default'     => [
                'unit' => '%',
                'size' => 10,
            ],
            'range'       => [
                '%' => [
                    'min'  => 0,
                    'max'  => 100,
                    'step' => 1,
                ],
            ],
            'description' => __('区间起点。百分比填减免百分比，例如 10 表示减 10%。', 'oyiso'),
            'condition' => [
                'mode' => 'range',
            ],
        ]);

        $percent_rule_repeater->add_control('end_percent', [
            'label'       => __('结束值', 'oyiso'),
            'type'        => Controls_Manager::SLIDER,
            'size_units'  => ['%'],
            'default'     => [
                'unit' => '%',
                'size' => 50,
            ],
            'range'       => [
                '%' => [
                    'min'  => 0,
                    'max'  => 100,
                    'step' => 1,
                ],
            ],
            'description' => __('区间终点。系统会自动生成这个范围内的所有奖项。', 'oyiso'),
            'condition' => [
                'mode' => 'range',
            ],
        ]);

        $percent_rule_repeater->add_control('value_percent', [
            'label'       => __('折扣值', 'oyiso'),
            'type'        => Controls_Manager::SLIDER,
            'size_units'  => ['%'],
            'default'     => [
                'unit' => '%',
                'size' => 25,
            ],
            'range'       => [
                '%' => [
                    'min'  => 0,
                    'max'  => 100,
                    'step' => 1,
                ],
            ],
            'description' => __('单个奖项的值。百分比填减免百分比，例如 25 表示减 25%。', 'oyiso'),
            'condition'   => [
                'mode' => 'single',
            ],
        ]);

        $percent_rule_repeater->add_control('probability', [
            'label'       => __('概率（%）', 'oyiso'),
            'type'        => Controls_Manager::SLIDER,
            'size_units'  => ['%'],
            'default'     => [
                'unit' => '%',
                'size' => 100,
            ],
            'range'       => [
                '%' => [
                    'min'  => 0,
                    'max'  => 100,
                    'step' => 1,
                ],
            ],
            'description' => __('这条规则占用的总概率。区间规则会把这部分概率平均分给区间内每个奖项。', 'oyiso'),
        ]);

        $this->add_control('percent_rules', [
            'label'       => __('奖项规则', 'oyiso'),
            'type'        => Controls_Manager::REPEATER,
            'fields'      => $percent_rule_repeater->get_controls(),
            'title_field' => '{{{ mode === "range" ? "区间" : "单个值" }}} · {{{ mode === "range" ? start_percent.size + "-" + end_percent.size : value_percent.size }}} · {{{ probability.size }}}%',
            'default'     => [
                [
                    'mode'        => 'range',
                    'start_percent' => [
                        'unit' => '%',
                        'size' => 10,
                    ],
                    'end_percent'   => [
                        'unit' => '%',
                        'size' => 50,
                    ],
                    'probability' => [
                        'unit' => '%',
                        'size' => 100,
                    ],
                ],
            ],
            'description' => __('可以混合配置区间和单个值。例如 10-30 填 30%，31-50 填 10%。', 'oyiso'),
            'condition'   => [
                'range_type' => 'percent',
            ],
        ]);

        $amount_rule_repeater = new Repeater();
        $amount_rule_repeater->add_control('mode', [
            'label'   => __('规则类型', 'oyiso'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'range',
            'options' => [
                'range'  => __('区间', 'oyiso'),
                'single' => __('单个值', 'oyiso'),
            ],
        ]);

        $amount_rule_repeater->add_control('start_amount', [
            'label'       => __('起始值', 'oyiso'),
            'type'        => Controls_Manager::NUMBER,
            'default'     => 10,
            'step'        => 0.1,
            'min'         => 0,
            'description' => __('区间起点。固定金额模式下直接填写优惠金额。', 'oyiso'),
            'condition'   => [
                'mode' => 'range',
            ],
        ]);

        $amount_rule_repeater->add_control('end_amount', [
            'label'       => __('结束值', 'oyiso'),
            'type'        => Controls_Manager::NUMBER,
            'default'     => 50,
            'step'        => 0.1,
            'min'         => 0,
            'description' => __('区间终点。系统会自动生成这个范围内的所有奖项。', 'oyiso'),
            'condition'   => [
                'mode' => 'range',
            ],
        ]);

        $amount_rule_repeater->add_control('value_amount', [
            'label'       => __('折扣值', 'oyiso'),
            'type'        => Controls_Manager::NUMBER,
            'default'     => 20,
            'step'        => 0.1,
            'min'         => 0,
            'description' => __('单个奖项的值。固定金额模式下直接填写优惠金额，例如 20。', 'oyiso'),
            'condition'   => [
                'mode' => 'single',
            ],
        ]);

        $amount_rule_repeater->add_control('probability', [
            'label'       => __('概率（%）', 'oyiso'),
            'type'        => Controls_Manager::SLIDER,
            'size_units'  => ['%'],
            'default'     => [
                'unit' => '%',
                'size' => 100,
            ],
            'range'       => [
                '%' => [
                    'min'  => 0,
                    'max'  => 100,
                    'step' => 1,
                ],
            ],
            'description' => __('这条规则占用的总概率。区间规则会把这部分概率平均分给区间内每个奖项。', 'oyiso'),
        ]);

        $this->add_control('amount_rules', [
            'label'       => __('奖项规则', 'oyiso'),
            'type'        => Controls_Manager::REPEATER,
            'fields'      => $amount_rule_repeater->get_controls(),
            'title_field' => '{{{ mode === "range" ? "区间" : "单个值" }}} · {{{ mode === "range" ? start_amount + "-" + end_amount : value_amount }}} · {{{ probability.size }}}%',
            'default'     => [
                [
                    'mode'         => 'range',
                    'start_amount' => 10,
                    'end_amount'   => 50,
                    'probability'  => [
                        'unit' => '%',
                        'size' => 100,
                    ],
                ],
            ],
            'description' => __('可以混合配置区间和单个值。例如 10-30 填 30%，50 填 10%。', 'oyiso'),
            'condition'   => [
                'range_type' => 'amount',
            ],
        ]);

        $this->add_control('thanks_heading', [
            'label'     => __('参与奖', 'oyiso'),
            'type'      => Controls_Manager::HEADING,
            'separator' => 'before',
        ]);

        $this->add_control('enable_thanks', [
            'label'        => __('启用参与奖', 'oyiso'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('是', 'oyiso'),
            'label_off'    => __('否', 'oyiso'),
            'return_value' => 'yes',
            'default'      => '',
            'description'  => __('启用后，剩余概率会自动归入参与奖。', 'oyiso'),
        ]);

        $this->end_controls_section();

        $this->start_controls_section('rules_section', [
            'label' => __('参与规则', 'oyiso'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('rules_notice', [
            'type'            => Controls_Manager::RAW_HTML,
            'raw'             => esc_html__('This lottery is only available to logged-in users.', 'oyiso'),
            'content_classes' => 'elementor-descriptor',
        ]);

        $this->add_control('total_limit', [
            'label'       => __('每人总次数', 'oyiso'),
            'type'        => Controls_Manager::NUMBER,
            'default'     => 1,
            'min'         => 0,
            'description' => __('填 0 表示不限制。', 'oyiso'),
        ]);

        $this->add_control('daily_limit', [
            'label'       => __('每人每日次数', 'oyiso'),
            'type'        => Controls_Manager::NUMBER,
            'default'     => 0,
            'min'         => 0,
            'description' => __('填 0 表示不限制。', 'oyiso'),
        ]);

        $this->add_control('start_at', [
            'label' => __('开始时间', 'oyiso'),
            'type'  => Controls_Manager::DATE_TIME,
        ]);

        $this->add_control('end_at', [
            'label' => __('结束时间', 'oyiso'),
            'type'  => Controls_Manager::DATE_TIME,
        ]);

        $this->add_control('records_per_tab', [
            'label'   => __('记录数量', 'oyiso'),
            'type'    => Controls_Manager::NUMBER,
            'default' => 20,
            'min'     => 1,
            'max'     => 100,
        ]);

        $this->end_controls_section();

        $this->start_controls_section('coupon_section', [
            'label' => __('优惠券参数', 'oyiso'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('coupon_template_notice', [
            'type'            => Controls_Manager::RAW_HTML,
            'raw'             => esc_html__('All winning tiers share the same WooCommerce coupon settings. The coupon is generated only after the winner clicks claim.', 'oyiso'),
            'content_classes' => 'elementor-descriptor',
        ]);

        $this->add_control('coupon_prefix', [
            'label'   => __('优惠券前缀', 'oyiso'),
            'type'    => Controls_Manager::TEXT,
            'default' => 'OYL',
        ]);

        $this->add_control('coupon_description', [
            'label'       => __('优惠券详情', 'oyiso'),
            'type'        => Controls_Manager::TEXTAREA,
            'placeholder' => __('留空则不展示优惠券详情', 'oyiso'),
            'description' => __('会显示在抽奖结果弹窗中，并写入生成的优惠券。', 'oyiso'),
        ]);

        $this->add_control('expiry_days', [
            'label'   => __('有效天数', 'oyiso'),
            'type'    => Controls_Manager::NUMBER,
            'default' => 7,
            'min'     => 0,
        ]);

        $this->add_control('minimum_amount', [
            'label' => __('最低消费', 'oyiso'),
            'type'  => Controls_Manager::NUMBER,
            'step'  => 0.01,
            'min'   => 0,
        ]);

        $this->add_control('maximum_amount', [
            'label' => __('最高消费', 'oyiso'),
            'type'  => Controls_Manager::NUMBER,
            'step'  => 0.01,
            'min'   => 0,
        ]);

        $this->add_control('maximum_discount', [
            'label'       => __('最高折扣金额', 'oyiso'),
            'type'        => Controls_Manager::NUMBER,
            'step'        => 0.01,
            'min'         => 0,
            'description' => __('仅百分比优惠券生效。', 'oyiso'),
            'condition'   => [
                'range_type' => 'percent',
            ],
        ]);

        $this->add_control('individual_use', [
            'label'        => __('仅限单独使用', 'oyiso'),
            'type'         => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default'      => 'yes',
        ]);

        $this->add_control('exclude_sale_items', [
            'label'        => __('排除特价商品', 'oyiso'),
            'type'         => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default'      => '',
        ]);

        $this->add_control('free_shipping', [
            'label'        => __('允许免运费', 'oyiso'),
            'type'         => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default'      => '',
        ]);

        $this->add_control('product_ids', [
            'label'       => __('指定商品', 'oyiso'),
            'type'        => Controls_Manager::SELECT2,
            'multiple'    => true,
            'label_block' => true,
            'options'     => $this->get_product_options(),
        ]);

        $this->add_control('excluded_product_ids', [
            'label'       => __('排除商品', 'oyiso'),
            'type'        => Controls_Manager::SELECT2,
            'multiple'    => true,
            'label_block' => true,
            'options'     => $this->get_product_options(),
        ]);

        $this->add_control('category_ids', [
            'label'       => __('指定分类', 'oyiso'),
            'type'        => Controls_Manager::SELECT2,
            'multiple'    => true,
            'label_block' => true,
            'options'     => $this->get_product_category_options(),
        ]);

        $this->add_control('excluded_category_ids', [
            'label'       => __('排除分类', 'oyiso'),
            'type'        => Controls_Manager::SELECT2,
            'multiple'    => true,
            'label_block' => true,
            'options'     => $this->get_product_category_options(),
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
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-accent: {{VALUE}};',
            ],
        ]);

        $this->add_control('panel_background', [
            'label'     => __('背景颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-panel-bg: {{VALUE}};',
            ],
        ]);

        $this->add_control('surface_color', [
            'label'     => __('基础底色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-surface: {{VALUE}};',
            ],
        ]);

        $this->add_control('surface_soft_color', [
            'label'     => __('浅色底色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-surface-soft: {{VALUE}};',
            ],
        ]);

        $this->add_control('line_color', [
            'label'     => __('边线颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-line: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Border::get_type(), [
            'name'     => 'panel_border',
            'selector' => '{{WRAPPER}} .oyiso-coupon-lottery',
        ]);

        $this->add_responsive_control('panel_radius', [
            'label'      => __('圆角', 'oyiso'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('panel_padding', [
            'label'      => __('内边距', 'oyiso'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%', 'em', 'rem'],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Box_Shadow::get_type(), [
            'name'     => 'panel_shadow',
            'selector' => '{{WRAPPER}} .oyiso-coupon-lottery',
        ]);

        $this->add_control('text_heading', [
            'label'     => __('标题与文字', 'oyiso'),
            'type'      => Controls_Manager::HEADING,
            'separator' => 'before',
        ]);

        $this->add_control('title_color', [
            'label'     => __('标题颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-title-color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name'     => 'title_typography',
            'selector' => '{{WRAPPER}} .oyiso-coupon-lottery__title',
        ]);

        $this->add_control('description_color', [
            'label'     => __('说明颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-description-color: {{VALUE}};',
            ],
        ]);

        $this->add_group_control(Group_Control_Typography::get_type(), [
            'name'     => 'description_typography',
            'selector' => '{{WRAPPER}} .oyiso-coupon-lottery__description',
        ]);

        $this->add_control('text_color', [
            'label'     => __('正文颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-text-strong: {{VALUE}};',
            ],
        ]);

        $this->add_control('muted_text_color', [
            'label'     => __('辅助文字颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-text-muted: {{VALUE}};',
            ],
        ]);

        $this->add_control('status_heading', [
            'label'     => __('状态提示', 'oyiso'),
            'type'      => Controls_Manager::HEADING,
            'separator' => 'before',
        ]);

        $this->add_control('status_background_color', [
            'label'     => __('提示背景', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-status-bg: {{VALUE}};',
            ],
        ]);

        $this->add_control('status_text_color', [
            'label'     => __('提示文字', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-status-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('button_heading', [
            'label'     => __('按钮', 'oyiso'),
            'type'      => Controls_Manager::HEADING,
            'separator' => 'before',
        ]);

        $this->add_control('primary_button_background', [
            'label'     => __('主按钮背景', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-primary-bg: {{VALUE}}; --oyiso-lottery-primary-border: {{VALUE}};',
            ],
        ]);

        $this->add_control('primary_button_text', [
            'label'     => __('主按钮文字', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-primary-text: {{VALUE}};',
            ],
        ]);

        $this->add_control('secondary_button_background', [
            'label'     => __('次按钮背景', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-secondary-bg: {{VALUE}};',
            ],
        ]);

        $this->add_control('secondary_button_text', [
            'label'     => __('次按钮文字', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-secondary-text: {{VALUE}};',
            ],
        ]);

        $this->add_control('record_heading', [
            'label'     => __('记录与弹窗', 'oyiso'),
            'type'      => Controls_Manager::HEADING,
            'separator' => 'before',
        ]);

        $this->add_control('record_background_color', [
            'label'     => __('记录卡背景', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-record-bg: {{VALUE}};',
            ],
        ]);

        $this->add_control('record_border_color', [
            'label'     => __('记录卡边框', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupon-lottery' => '--oyiso-lottery-record-border: {{VALUE}};',
            ],
        ]);

        $this->end_controls_section();
    }

    protected function render()
    {
        if (!class_exists('\WooCommerce')) {
            echo '<div class="oyiso-coupon-lottery"><p>' . esc_html(oyiso_t('Please enable WooCommerce first.')) . '</p></div>';
            return;
        }

        $settings = $this->get_settings_for_display();
        $post_id = get_the_ID() ?: 0;
        $widget_id = $this->get_id();
        $widget_key = 'lottery-' . $post_id . '-' . $widget_id;
        $prize_rules = $this->normalize_prize_rules_from_settings($settings);
        $payload = [
            'widget_key'            => $widget_key,
            'post_id'               => $post_id,
            'title'                 => $settings['title'] ?? '',
            'description'           => $settings['description'] ?? '',
            'range_type'            => $settings['range_type'] ?? 'percent',
            'prize_rules'           => $prize_rules,
            'draw_value_mode'       => $settings['draw_value_mode'] ?? 'range',
            'min_value'             => $settings['min_value'] ?? 0,
            'max_value'             => $settings['max_value'] ?? 0,
            'default_weight'        => $settings['default_weight'] ?? 1,
            'probability_map'       => $settings['probability_map'] ?? [],
            'custom_prizes'         => $settings['custom_prizes'] ?? [],
            'enable_thanks'         => ($settings['enable_thanks'] ?? '') === 'yes',
            'start_at'              => $settings['start_at'] ?? '',
            'end_at'                => $settings['end_at'] ?? '',
            'daily_limit'           => $settings['daily_limit'] ?? 0,
            'total_limit'           => $settings['total_limit'] ?? 1,
            'coupon_prefix'         => $settings['coupon_prefix'] ?? 'OYL',
            'coupon_description'    => $settings['coupon_description'] ?? '',
            'expiry_days'           => $settings['expiry_days'] ?? 7,
            'minimum_amount'        => $settings['minimum_amount'] ?? '',
            'maximum_amount'        => $settings['maximum_amount'] ?? '',
            'maximum_discount'      => $settings['maximum_discount'] ?? '',
            'individual_use'        => ($settings['individual_use'] ?? '') === 'yes',
            'exclude_sale_items'    => ($settings['exclude_sale_items'] ?? '') === 'yes',
            'free_shipping'         => ($settings['free_shipping'] ?? '') === 'yes',
            'product_ids'           => $settings['product_ids'] ?? [],
            'excluded_product_ids'  => $settings['excluded_product_ids'] ?? [],
            'category_ids'          => $settings['category_ids'] ?? [],
            'excluded_category_ids' => $settings['excluded_category_ids'] ?? [],
            'records_per_tab'       => $settings['records_per_tab'] ?? 20,
        ];
        $scope_html = \Oyiso_Coupon_Lottery_Module::formatScopeFromPayload($payload);
        $signed_payload = \Oyiso_Coupon_Lottery_Module::buildSignedPayload($payload);
        $availability = \Oyiso_Coupon_Lottery_Module::getCurrentAvailability($payload);
        $is_logged_in = is_user_logged_in();
        $can_manage = current_user_can('manage_woocommerce') || current_user_can('edit_shop_coupons') || current_user_can('manage_options');
        ?>
        <section
            class="oyiso-coupon-lottery"
            data-oyiso-coupon-lottery
            data-payload="<?php echo esc_attr($signed_payload['payload']); ?>"
            data-signature="<?php echo esc_attr($signed_payload['signature']); ?>"
            data-widget-key="<?php echo esc_attr($widget_key); ?>"
            data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>"
            data-show-result-meta="<?php echo !empty($payload['enable_thanks']) ? '1' : '0'; ?>"
        >
            <div class="oyiso-coupon-lottery__aurora" aria-hidden="true"></div>
            <div class="oyiso-coupon-lottery__grain" aria-hidden="true"></div>
            <div class="oyiso-coupon-lottery__hero">
                <div class="oyiso-coupon-lottery__hero-copy">
                    <?php if (!empty($settings['title'])) : ?>
                        <h3 class="oyiso-coupon-lottery__title"><?php echo esc_html($settings['title']); ?></h3>
                    <?php endif; ?>

                    <?php if (!empty($settings['description'])) : ?>
                        <p class="oyiso-coupon-lottery__description"><?php echo esc_html($settings['description']); ?></p>
                    <?php endif; ?>

                    <div class="oyiso-coupon-lottery__status" data-lottery-status>
                        <?php
                        if (!$is_logged_in) {
                            echo esc_html(oyiso_t('Please log in before joining the draw.'));
                        } elseif (!$availability['allowed']) {
                            echo esc_html($availability['reason']);
                        } else {
                            $parts = [];

                            if ($availability['total_remaining'] !== null) {
                                $parts[] = oyiso_t_sprintf('Total remaining: %d', (int) $availability['total_remaining']);
                            }

                            if ($availability['daily_remaining'] !== null) {
                                $parts[] = oyiso_t_sprintf("Today's remaining: %d", (int) $availability['daily_remaining']);
                            }

                            echo esc_html($parts ? implode(' / ', $parts) : oyiso_t('You can join the draw now.'));
                        }
                        ?>
                    </div>

                    <div class="oyiso-coupon-lottery__actions">
                        <button
                            type="button"
                            class="oyiso-coupon-lottery__button oyiso-coupon-lottery__button--primary"
                            data-lottery-draw
                            data-default-label="<?php echo esc_attr(oyiso_t('Draw Now')); ?>"
                            <?php disabled(!$is_logged_in || !$availability['allowed']); ?>
                        >
                            <span class="oyiso-coupon-lottery__button-shine" aria-hidden="true"></span>
                            <span class="oyiso-coupon-lottery__button-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                    <path d="M12 2.75a1 1 0 0 1 .9.57l2.03 4.18 4.62.68a1 1 0 0 1 .56 1.7l-3.34 3.3.79 4.66a1 1 0 0 1-1.45 1.03L12 16.71l-4.11 2.16a1 1 0 0 1-1.45-1.03l.79-4.66-3.34-3.3a1 1 0 0 1 .56-1.7l4.62-.68 2.03-4.18a1 1 0 0 1 .9-.57Z" fill="currentColor"/>
                                </svg>
                            </span>
                            <span class="oyiso-coupon-lottery__button-label"><?php echo esc_html(oyiso_t('Draw Now')); ?></span>
                        </button>

                        <?php if ($scope_html !== '') : ?>
                            <button
                                type="button"
                                class="oyiso-coupon-lottery__button oyiso-coupon-lottery__button--secondary"
                                data-coupon-scope="<?php echo esc_attr($scope_html); ?>"
                            >
                                <?php echo esc_html(oyiso_t('Coupon Details')); ?>
                            </button>
                        <?php endif; ?>

                        <?php if ($is_logged_in) : ?>
                            <button type="button" class="oyiso-coupon-lottery__button oyiso-coupon-lottery__button--secondary" data-lottery-records>
                                <?php echo esc_html(oyiso_t('Draw Records')); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="oyiso-coupon-lottery__hero-art" aria-hidden="true">
                    <div class="oyiso-coupon-lottery__seal">
                        <span class="oyiso-coupon-lottery__seal-ring oyiso-coupon-lottery__seal-ring--outer"></span>
                        <span class="oyiso-coupon-lottery__seal-ring oyiso-coupon-lottery__seal-ring--middle"></span>
                        <span class="oyiso-coupon-lottery__seal-ring oyiso-coupon-lottery__seal-ring--inner"></span>
                        <span class="oyiso-coupon-lottery__seal-core"></span>
                        <span class="oyiso-coupon-lottery__seal-star oyiso-coupon-lottery__seal-star--one"></span>
                        <span class="oyiso-coupon-lottery__seal-star oyiso-coupon-lottery__seal-star--two"></span>
                        <span class="oyiso-coupon-lottery__seal-star oyiso-coupon-lottery__seal-star--three"></span>
                    </div>
                    <div class="oyiso-coupon-lottery__orbit oyiso-coupon-lottery__orbit--one"></div>
                    <div class="oyiso-coupon-lottery__orbit oyiso-coupon-lottery__orbit--two"></div>
                    <div class="oyiso-coupon-lottery__glow oyiso-coupon-lottery__glow--one"></div>
                    <div class="oyiso-coupon-lottery__glow oyiso-coupon-lottery__glow--two"></div>
                </div>
            </div>

            <div class="oyiso-coupon-lottery__modal oyiso-scope-dialog" data-lottery-result-modal hidden>
                <div class="oyiso-coupon-lottery__modal-backdrop oyiso-scope-dialog__backdrop" data-lottery-close></div>
                <div class="oyiso-coupon-lottery__modal-panel oyiso-coupon-lottery__modal-panel--result oyiso-scope-dialog__panel" role="dialog" aria-modal="true" data-lottery-result-panel>
                    <div class="oyiso-coupon-lottery__modal-header oyiso-scope-dialog__header">
                        <h4 class="oyiso-coupon-lottery__modal-title oyiso-scope-dialog__title"><?php echo esc_html(oyiso_t('Draw Result')); ?></h4>
                        <button type="button" class="oyiso-coupon-lottery__modal-close oyiso-scope-dialog__close" data-lottery-close aria-label="<?php echo esc_attr(oyiso_t('Close')); ?>"></button>
                    </div>
                    <div class="oyiso-coupon-lottery__modal-content oyiso-scope-dialog__content">
                        <section class="oyiso-coupon-lottery__result-summary oyiso-scope-dialog__summary">
                            <div class="oyiso-coupon-lottery__result-emblem" aria-hidden="true">
                                <svg class="oyiso-coupon-lottery__result-emblem-art" viewBox="0 0 64 64" focusable="false" aria-hidden="true">
                                    <g class="oyiso-coupon-lottery__result-emblem-ticket-group" transform="rotate(-8 32 32)">
                                        <rect class="oyiso-coupon-lottery__result-emblem-ticket" x="10" y="17" width="44" height="30" rx="10"></rect>
                                        <circle class="oyiso-coupon-lottery__result-emblem-dot" cx="20" cy="32" r="3.6"></circle>
                                        <path class="oyiso-coupon-lottery__result-emblem-line" d="M27.5 28.5H44.5"></path>
                                        <path class="oyiso-coupon-lottery__result-emblem-line" d="M27.5 36H40.5"></path>
                                    </g>
                                </svg>
                            </div>
                            <div class="oyiso-coupon-lottery__result-copy">
                                <div class="oyiso-coupon-lottery__result-summary-label oyiso-scope-dialog__summary-label"><?php echo esc_html(oyiso_t('This Result')); ?></div>
                                <div class="oyiso-coupon-lottery__result-label oyiso-scope-dialog__summary-code" data-lottery-result-label></div>
                                <p class="oyiso-coupon-lottery__result-message" data-lottery-result-message></p>
                            </div>
                        </section>
                        <section class="oyiso-coupon-lottery__result-details oyiso-scope-dialog__section" data-lottery-result-details hidden>
                            <h5 class="oyiso-scope-dialog__section-title"><?php echo esc_html(oyiso_t('Offer Details')); ?></h5>
                            <div class="oyiso-scope-dialog__section-card">
                                <div class="oyiso-scope-dialog__section-body">
                                    <p class="oyiso-coupon-lottery__result-details-text" data-lottery-result-details-content></p>
                                </div>
                            </div>
                        </section>
                        <div class="oyiso-coupon-lottery__claim-success" data-lottery-claim-success hidden>
                            <strong data-lottery-coupon-code data-code-label="<?php echo esc_attr(oyiso_t('Coupon Code')); ?>"></strong>
                        </div>
                        <div class="oyiso-coupon-lottery__modal-actions">
                            <button type="button" class="oyiso-coupon-lottery__button oyiso-coupon-lottery__button--primary" data-lottery-claim hidden>
                                <?php echo esc_html(oyiso_t('Claim Coupon')); ?>
                            </button>
                            <button type="button" class="oyiso-coupon-lottery__button oyiso-coupon-lottery__button--primary" data-lottery-claim-copy hidden>
                                <?php echo esc_html(oyiso_t('Copy Coupon Code')); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="oyiso-coupon-lottery__modal oyiso-scope-dialog" data-lottery-records-modal hidden>
                <div class="oyiso-coupon-lottery__modal-backdrop oyiso-scope-dialog__backdrop" data-lottery-records-close></div>
                <div class="oyiso-coupon-lottery__modal-panel oyiso-coupon-lottery__modal-panel--wide oyiso-scope-dialog__panel" role="dialog" aria-modal="true">
                    <div class="oyiso-coupon-lottery__modal-header oyiso-scope-dialog__header">
                        <h4 class="oyiso-coupon-lottery__modal-title oyiso-scope-dialog__title"><?php echo esc_html(oyiso_t('Draw Records')); ?></h4>
                        <button type="button" class="oyiso-coupon-lottery__modal-close oyiso-scope-dialog__close" data-lottery-records-close aria-label="<?php echo esc_attr(oyiso_t('Close')); ?>"></button>
                    </div>
                    <div class="oyiso-coupon-lottery__modal-content oyiso-coupon-lottery__modal-content--records oyiso-scope-dialog__content">
                        <div class="oyiso-coupon-lottery__records-head">
                            <div class="oyiso-coupon-lottery__record-tabs" data-lottery-record-tabs></div>
                        </div>
                        <div class="oyiso-coupon-lottery__records-body" data-lottery-record-panels></div>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    private function normalize_prize_rules_from_settings(array $settings): array
    {
        $range_type = ($settings['range_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
        $source_rules = $range_type === 'amount'
            ? ($settings['amount_rules'] ?? [])
            : ($settings['percent_rules'] ?? []);

        if (!is_array($source_rules) || empty($source_rules)) {
            return is_array($settings['prize_rules'] ?? null) ? $settings['prize_rules'] : [];
        }

        $normalized = [];

        foreach ($source_rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $mode = ($rule['mode'] ?? 'range') === 'single' ? 'single' : 'range';

            if ($range_type === 'amount') {
                $normalized[] = [
                    'mode'        => $mode,
                    'start_value' => $rule['start_amount'] ?? 0,
                    'end_value'   => $rule['end_amount'] ?? 0,
                    'value'       => $rule['value_amount'] ?? 0,
                    'probability' => $rule['probability'] ?? 0,
                ];

                continue;
            }

            $normalized[] = [
                'mode'        => $mode,
                'start_value' => $rule['start_percent'] ?? 0,
                'end_value'   => $rule['end_percent'] ?? 0,
                'value'       => $rule['value_percent'] ?? 0,
                'probability' => $rule['probability'] ?? 0,
            ];
        }

        return $normalized;
    }

    private function get_product_options(): array
    {
        if (!post_type_exists('product')) {
            return [];
        }

        $posts = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);

        $options = [];

        foreach ($posts as $post_id) {
            $options[$post_id] = get_the_title($post_id);
        }

        return $options;
    }

    private function get_product_category_options(): array
    {
        if (!taxonomy_exists('product_cat')) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }

        $options = [];

        foreach ($terms as $term) {
            $options[$term->term_id] = $term->name;
        }

        return $options;
    }
}
