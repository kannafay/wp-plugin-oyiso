<?php

namespace Oyiso\ElementorWidgets;

defined('ABSPATH') || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Icons_Manager;
use Elementor\Repeater;
use Elementor\Utils;
use Elementor\Widget_Base;

class Coupons extends Widget_Base
{
    public function get_name()
    {
        return 'oyiso_coupons';
    }

    public function get_title()
    {
        return __('Oyiso 优惠券', 'oyiso');
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

        $this->add_control('banner_image', [
            'label'   => __('背景图片', 'oyiso'),
            'type'    => Controls_Manager::MEDIA,
            'default' => [
                'url' => Utils::get_placeholder_image_src(),
            ],
        ]);

        $this->add_control('banner_kicker', [
            'label'   => __('辅助标题', 'oyiso'),
            'type'    => Controls_Manager::TEXT,
            'default' => __('精选优惠', 'oyiso'),
        ]);

        $this->add_control('banner_title', [
            'label'   => __('标题', 'oyiso'),
            'type'    => Controls_Manager::TEXT,
            'default' => __('限时优惠券专区', 'oyiso'),
        ]);

        $this->add_control('banner_description', [
            'label'   => __('描述', 'oyiso'),
            'type'    => Controls_Manager::TEXTAREA,
            'default' => __('集中展示当前可用优惠券，帮助用户快速找到合适的折扣。', 'oyiso'),
        ]);

        $this->end_controls_section();

        $this->start_controls_section('coupons_section', [
            'label' => __('优惠券分组', 'oyiso'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $repeater = new Repeater();

        $repeater->add_control('group_name', [
            'label'       => __('分组名称', 'oyiso'),
            'type'        => Controls_Manager::TEXT,
            'default'     => __('热门优惠', 'oyiso'),
            'placeholder' => __('例如：热门优惠', 'oyiso'),
        ]);

        $repeater->add_control('coupon_ids', [
            'label'       => __('选择优惠券', 'oyiso'),
            'type'        => Controls_Manager::SELECT2,
            'multiple'    => true,
            'label_block' => true,
            'options'     => $this->get_wc_coupon_options(),
        ]);

        $repeater->add_control('group_color', [
            'label'   => __('分组颜色', 'oyiso'),
            'type'    => Controls_Manager::COLOR,
            'default' => '#e5702a',
        ]);

        $repeater->add_control('group_icon', [
            'label'   => __('分组图标', 'oyiso'),
            'type'    => Controls_Manager::ICONS,
            'default' => [
                'value'   => 'fas fa-ticket-alt',
                'library' => 'fa-solid',
            ],
        ]);

        $this->add_control('coupon_groups', [
            'label'       => __('分组列表', 'oyiso'),
            'type'        => Controls_Manager::REPEATER,
            'fields'      => $repeater->get_controls(),
            'title_field' => '{{{ group_name }}}',
            'default'     => [
                [
                    'group_name' => __('热门优惠', 'oyiso'),
                    'coupon_ids' => [],
                    'group_color' => '#e5702a',
                ],
            ],
        ]);

        $this->add_control('coupon_group_notice', [
            'type'            => Controls_Manager::RAW_HTML,
            'raw'             => __('前台会自动生成“全部”标签。同一张优惠券如果被多个分组选择，仅会显示在第一个选择它的分组中。', 'oyiso'),
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('style_basic_section', [
            'label' => __('基础', 'oyiso'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('use_default_style', [
            'label'        => __('使用默认样式', 'oyiso'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('是', 'oyiso'),
            'label_off'    => __('否', 'oyiso'),
            'default'      => '',
            'return_value' => 'yes',
            'description'  => __('开启后会临时使用插件默认样式；关闭后恢复当前自定义设置。', 'oyiso'),
            'prefix_class' => 'oyiso-coupons-default-style-',
        ]);

        $this->add_control('accent_color', [
            'label'       => __('强调色', 'oyiso'),
            'type'        => Controls_Manager::COLOR,
            'default'     => '#e5702a',
            'placeholder' => '#e5702a',
            'description' => __('用于“全部”标签、弹窗链接，以及没有单独设置分组颜色时的默认颜色。分组颜色会优先显示。', 'oyiso'),
            'selectors'   => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-coupon-accent: {{VALUE}};',
            ],
        ]);

        $this->add_control('dark_color', [
            'label'     => __('深色文字', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'default'   => '#1f2937',
            'placeholder' => '#1f2937',
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-coupon-dark: {{VALUE}};',
            ],
        ]);

        $this->add_control('line_color', [
            'label'     => __('线条颜色', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'default'   => '#e7e2dc',
            'placeholder' => '#e7e2dc',
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-coupon-line: {{VALUE}};',
            ],
        ]);

        $this->add_responsive_control('section_gap', [
            'label'      => __('板块间距', 'oyiso'),
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
            'label' => __('横幅', 'oyiso'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('banner_min_height', [
            'label'          => __('高度', 'oyiso'),
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
            'label'          => __('内边距', 'oyiso'),
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

        $this->add_responsive_control('banner_radius', [
            'label'      => __('圆角', 'oyiso'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range'      => [
                'px' => [
                    'min' => 0,
                    'max' => 40,
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
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-banner-radius: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('banner_title_size', [
            'label'          => __('标题大小', 'oyiso'),
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
            'label'      => __('描述大小', 'oyiso'),
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
            'label' => __('标签栏', 'oyiso'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('tabs_layout', [
            'label'                => __('布局', 'oyiso'),
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
                'wrap'   => __('自动换行', 'oyiso'),
                'grid_1' => __('1 列网格', 'oyiso'),
                'grid_2' => __('2 列网格', 'oyiso'),
                'grid_3' => __('3 列网格', 'oyiso'),
            ],
            'selectors_dictionary' => [
                'wrap'   => '--oyiso-tabs-display: flex; --oyiso-tabs-columns: none; --oyiso-tabs-wrap: wrap;',
                'grid_1' => '--oyiso-tabs-display: grid; --oyiso-tabs-columns: repeat(1, minmax(0, 1fr)); --oyiso-tabs-wrap: nowrap; --oyiso-tab-bg: #f7f8f9;',
                'grid_2' => '--oyiso-tabs-display: grid; --oyiso-tabs-columns: repeat(2, minmax(0, 1fr)); --oyiso-tabs-wrap: nowrap; --oyiso-tab-bg: #f7f8f9;',
                'grid_3' => '--oyiso-tabs-display: grid; --oyiso-tabs-columns: repeat(3, minmax(0, 1fr)); --oyiso-tabs-wrap: nowrap; --oyiso-tab-bg: #f7f8f9;',
            ],
            'selectors'            => [
                '{{WRAPPER}} .oyiso-coupons' => '{{VALUE}}',
            ],
        ]);

        $this->add_responsive_control('tabs_gap', [
            'label'      => __('标签间距', 'oyiso'),
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
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-tabs-gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('tabs_bottom_spacing', [
            'label'      => __('底部间距', 'oyiso'),
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
            'label'      => __('按钮高度', 'oyiso'),
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
            'label'      => __('按钮圆角', 'oyiso'),
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
            'label'      => __('文字大小', 'oyiso'),
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
            'label' => __('优惠券', 'oyiso'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_control('card_background_color', [
            'label'     => __('卡片背景', 'oyiso'),
            'type'      => Controls_Manager::COLOR,
            'default'   => '#ffffff',
            'placeholder' => '#ffffff',
            'selectors' => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-card-bg: {{VALUE}};',
            ],
        ]);

        $this->add_responsive_control('coupon_grid_gap', [
            'label'      => __('卡片间距', 'oyiso'),
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
            'label'      => __('卡片圆角', 'oyiso'),
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
            'label'          => __('卡片阴影', 'oyiso'),
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
            'label'      => __('内容内边距', 'oyiso'),
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => ['px'],
            'default'    => [
                'top'      => 18,
                'right'    => 20,
                'bottom'   => 20,
                'left'     => 20,
                'unit'     => 'px',
                'isLinked' => false,
            ],
            'placeholder' => [
                'top'    => 18,
                'right'  => 20,
                'bottom' => 20,
                'left'   => 20,
            ],
            'selectors'  => [
                '{{WRAPPER}} .oyiso-coupons' => '--oyiso-card-content-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('card_discount_size', [
            'label'      => __('金额大小', 'oyiso'),
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
            'label'      => __('描述文字大小', 'oyiso'),
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

    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $accent_color = sanitize_hex_color($settings['accent_color'] ?? '#e5702a') ?: '#e5702a';
        $groups = $this->prepare_coupon_groups($settings['coupon_groups'] ?? [], $accent_color);
        $banner_image = $settings['banner_image']['url'] ?? '';
        $widget_id = 'oyiso-coupons-' . esc_attr($this->get_id());

        if (!class_exists('WC_Coupon')) {
            $this->render_notice(__('请先启用 WooCommerce 后再使用优惠券小部件。', 'oyiso'));
            return;
        }

        if (empty($groups)) {
            $this->render_notice(__('请在小部件设置中添加优惠券分组并选择 WooCommerce 优惠券。', 'oyiso'));
            return;
        }
        ?>
        <section id="<?php echo esc_attr($widget_id); ?>" class="oyiso-coupons" data-oyiso-coupons>
            <div class="oyiso-coupons__banner" <?php echo $banner_image ? 'style="background-image: url(' . esc_url($banner_image) . ');"' : ''; ?>>
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
                            <?php echo esc_html($group['label']); ?>
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
                $label = sprintf(__('分组 %d', 'oyiso'), $group_index + 1);
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
            'label' => __('全部', 'oyiso'),
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
            'description'    => $description ?: __('下单时输入优惠码即可享受对应优惠。', 'oyiso'),
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
            'label' => $group['label'] ?? __('全部', 'oyiso'),
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
            return __('折扣优惠', 'oyiso');
        }

        if ($type === 'fixed_cart') {
            return __('满减优惠', 'oyiso');
        }

        if ($type === 'fixed_product') {
            return __('商品优惠', 'oyiso');
        }

        return __('优惠券', 'oyiso');
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

        $unlimited = __('不限制', 'oyiso');

        return implode('', [
            $this->format_coupon_scope_row(__('指定商品', 'oyiso'), !empty($product_links) ? implode('、', $product_links) : esc_html($unlimited)),
            $this->format_coupon_scope_row(__('指定分类', 'oyiso'), !empty($category_links) ? implode('、', $category_links) : esc_html($unlimited)),
            $this->format_coupon_scope_row(__('最低消费', 'oyiso'), esc_html($minimum_amount > 0 ? $this->format_coupon_money($minimum_amount) : $unlimited)),
            $this->format_coupon_scope_row(__('最高消费', 'oyiso'), esc_html($maximum_amount > 0 ? $this->format_coupon_money($maximum_amount) : $unlimited)),
            $this->format_coupon_scope_row(__('免运费', 'oyiso'), esc_html($coupon->get_free_shipping() ? __('是', 'oyiso') : __('否', 'oyiso'))),
        ]);
    }

    private function format_coupon_scope_row(string $label, string $value_html)
    {
        return sprintf(
            '<div class="oyiso-scope-dialog__row"><strong>%1$s：</strong><span>%2$s</span></div>',
            esc_html($label),
            $value_html
        );
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
                'label' => __('剩余数量', 'oyiso'),
                'value' => __('不限量', 'oyiso'),
                'percent' => 100,
            ];
        }

        $remaining = max(0, $usage_limit - $usage_count);

        return [
            'label' => __('剩余数量', 'oyiso'),
            'value' => sprintf(__('%1$d / %2$d', 'oyiso'), $remaining, $usage_limit),
            'percent' => max(0, min(100, ($remaining / $usage_limit) * 100)),
        ];
    }

    private function get_coupon_validity_data(\WC_Coupon $coupon)
    {
        $date_expires = $coupon->get_date_expires();

        if (!$date_expires) {
            return [
                'label' => __('有效期', 'oyiso'),
                'value' => __('长期有效', 'oyiso'),
                'percent' => 100,
            ];
        }

        $expires_timestamp = $date_expires->getTimestamp();
        $created = $coupon->get_date_created();
        $created_timestamp = $created ? $created->getTimestamp() : current_time('timestamp');
        $now = current_time('timestamp');
        $duration = max(1, $expires_timestamp - $created_timestamp);
        $remaining = max(0, $expires_timestamp - $now);

        return [
            'label' => __('有效期', 'oyiso'),
            'value' => date_i18n(get_option('date_format'), $expires_timestamp),
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
                                <span class="oyiso-coupon-card__code"><?php echo esc_html($code); ?></span>

                                <button
                                    class="oyiso-coupon-card__copy-button"
                                    type="button"
                                    data-coupon-copy="<?php echo esc_attr($code); ?>"
                                    data-copied-text="<?php echo esc_attr__('已复制', 'oyiso'); ?>"
                                >
                                    <?php echo esc_html__('复制', 'oyiso'); ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if ($scope) : ?>
                            <div class="oyiso-coupon-card__actions">
                                <button
                                    class="oyiso-coupon-card__scope-button"
                                    type="button"
                                    data-coupon-scope="<?php echo esc_attr($scope); ?>"
                                    data-coupon-code="<?php echo esc_attr($code); ?>"
                                >
                                    <?php echo esc_html__('适用范围', 'oyiso'); ?>
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
                            data-expand-text="<?php echo esc_attr__('展开', 'oyiso'); ?>"
                            data-collapse-text="<?php echo esc_attr__('收起', 'oyiso'); ?>"
                            aria-expanded="false"
                            hidden
                        >
                            <?php echo esc_html__('展开', 'oyiso'); ?>
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
