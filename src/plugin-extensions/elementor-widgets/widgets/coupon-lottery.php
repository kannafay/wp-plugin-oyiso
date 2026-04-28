<?php

namespace Oyiso\ElementorWidgets;

defined('ABSPATH') || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Repeater;
use Elementor\Widget_Base;

class Coupon_Lottery extends Widget_Base
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
        return ['oyiso-coupon-lottery'];
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
            'label'   => __('说明', 'oyiso'),
            'type'    => Controls_Manager::TEXTAREA,
            'default' => $this->get_site_default_text('Log in to join the draw. If you win, click claim to generate your exclusive coupon.'),
        ]);

        $this->add_control('draw_button_text', [
            'label'   => __('抽奖按钮', 'oyiso'),
            'type'    => Controls_Manager::TEXT,
            'default' => $this->get_site_default_text('Draw Now'),
        ]);

        $this->add_control('claim_button_text', [
            'label'   => __('领取按钮', 'oyiso'),
            'type'    => Controls_Manager::TEXT,
            'default' => $this->get_site_default_text('Claim Coupon'),
        ]);

        $this->add_control('records_button_text', [
            'label'   => __('记录按钮', 'oyiso'),
            'type'    => Controls_Manager::TEXT,
            'default' => $this->get_site_default_text('Draw Records'),
        ]);

        $this->end_controls_section();

        $this->start_controls_section('lottery_section', [
            'label' => __('抽奖设置', 'oyiso'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('range_type', [
            'label'   => __('折扣类型', 'oyiso'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'percent',
            'options' => [
                'percent' => __('百分比范围（折扣）', 'oyiso'),
                'amount'  => __('金额值范围', 'oyiso'),
            ],
        ]);

        $this->add_control('allow_decimals', [
            'label'        => __('启用一位小数', 'oyiso'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('是', 'oyiso'),
            'label_off'    => __('否', 'oyiso'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ]);

        $this->add_control('min_value', [
            'label'   => __('最小值', 'oyiso'),
            'type'    => Controls_Manager::NUMBER,
            'default' => 5,
            'step'    => 0.1,
        ]);

        $this->add_control('max_value', [
            'label'   => __('最大值', 'oyiso'),
            'type'    => Controls_Manager::NUMBER,
            'default' => 9,
            'step'    => 0.1,
        ]);

        $this->add_control('default_weight', [
            'label'       => __('默认概率权重', 'oyiso'),
            'type'        => Controls_Manager::NUMBER,
            'default'     => 1,
            'step'        => 0.1,
            'min'         => 0,
            'description' => __('未单独配置概率的档位，会使用这个默认权重。', 'oyiso'),
        ]);

        $probability_repeater = new Repeater();
        $probability_repeater->add_control('value', [
            'label'       => __('档位值', 'oyiso'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => '5 / 5.9 / 20',
        ]);
        $probability_repeater->add_control('weight', [
            'label'   => __('概率权重', 'oyiso'),
            'type'    => Controls_Manager::NUMBER,
            'default' => 1,
            'step'    => 0.1,
            'min'     => 0,
        ]);

        $this->add_control('probability_map', [
            'label'       => __('单独概率', 'oyiso'),
            'type'        => Controls_Manager::REPEATER,
            'fields'      => $probability_repeater->get_controls(),
            'title_field' => '{{{ value }}} · {{{ weight }}}',
            'description' => __('按范围自动生成档位后，可在这里覆盖某些档位的概率权重。', 'oyiso'),
        ]);

        $this->add_control('enable_thanks', [
            'label'        => __('启用谢谢参与', 'oyiso'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('是', 'oyiso'),
            'label_off'    => __('否', 'oyiso'),
            'return_value' => 'yes',
            'default'      => '',
        ]);

        $this->add_control('thanks_label', [
            'label'     => __('未中奖文案', 'oyiso'),
            'type'      => Controls_Manager::TEXT,
            'default'   => $this->get_site_default_text('Thanks for participating'),
            'condition' => [
                'enable_thanks' => 'yes',
            ],
        ]);

        $this->add_control('thanks_weight', [
            'label'     => __('未中奖权重', 'oyiso'),
            'type'      => Controls_Manager::NUMBER,
            'default'   => 1,
            'step'      => 0.1,
            'min'       => 0,
            'condition' => [
                'enable_thanks' => 'yes',
            ],
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
            'label'       => __('优惠券说明', 'oyiso'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => __('Leave blank to use the winning message automatically', 'oyiso'),
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
                '{{WRAPPER}} .oyiso-coupon-lottery' => 'background: {{VALUE}};',
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
        $payload = [
            'widget_key'            => $widget_key,
            'post_id'               => $post_id,
            'title'                 => $settings['title'] ?? '',
            'description'           => $settings['description'] ?? '',
            'draw_button_text'      => $settings['draw_button_text'] ?? '',
            'claim_button_text'     => $settings['claim_button_text'] ?? '',
            'records_button_text'   => $settings['records_button_text'] ?? '',
            'range_type'            => $settings['range_type'] ?? 'percent',
            'allow_decimals'        => ($settings['allow_decimals'] ?? '') === 'yes',
            'min_value'             => $settings['min_value'] ?? 0,
            'max_value'             => $settings['max_value'] ?? 0,
            'default_weight'        => $settings['default_weight'] ?? 1,
            'probability_map'       => $settings['probability_map'] ?? [],
            'enable_thanks'         => ($settings['enable_thanks'] ?? '') === 'yes',
            'thanks_weight'         => $settings['thanks_weight'] ?? 0,
            'thanks_label'          => $settings['thanks_label'] ?? '',
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
                    <?php disabled(!$is_logged_in || !$availability['allowed']); ?>
                >
                    <?php echo esc_html($settings['draw_button_text'] ?: oyiso_t('Draw Now')); ?>
                </button>

                <button type="button" class="oyiso-coupon-lottery__button" data-lottery-records>
                    <?php echo esc_html($settings['records_button_text'] ?: oyiso_t('Draw Records')); ?>
                </button>
            </div>

            <div class="oyiso-coupon-lottery__modal" data-lottery-result-modal hidden>
                <div class="oyiso-coupon-lottery__modal-backdrop" data-lottery-close></div>
                <div class="oyiso-coupon-lottery__modal-panel" role="dialog" aria-modal="true">
                    <div class="oyiso-coupon-lottery__modal-header">
                        <h4 class="oyiso-coupon-lottery__modal-title"><?php echo esc_html(oyiso_t('Draw Result')); ?></h4>
                        <button type="button" class="oyiso-coupon-lottery__modal-close" data-lottery-close aria-label="<?php echo esc_attr(oyiso_t('Close')); ?>"></button>
                    </div>
                    <div class="oyiso-coupon-lottery__modal-content">
                        <section class="oyiso-coupon-lottery__result-summary">
                            <div class="oyiso-coupon-lottery__result-summary-label"><?php echo esc_html(oyiso_t('This Result')); ?></div>
                            <div class="oyiso-coupon-lottery__result-label" data-lottery-result-label></div>
                        </section>
                        <p class="oyiso-coupon-lottery__result-message" data-lottery-result-message></p>
                        <div class="oyiso-coupon-lottery__claim-success" data-lottery-claim-success hidden>
                            <strong data-lottery-coupon-code></strong>
                            <button type="button" class="oyiso-coupon-lottery__button" data-lottery-copy>
                                <?php echo esc_html(oyiso_t('Copy Coupon Code')); ?>
                            </button>
                        </div>
                        <div class="oyiso-coupon-lottery__modal-actions">
                            <button type="button" class="oyiso-coupon-lottery__button oyiso-coupon-lottery__button--primary" data-lottery-claim hidden>
                                <?php echo esc_html($settings['claim_button_text'] ?: oyiso_t('Claim Coupon')); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="oyiso-coupon-lottery__modal" data-lottery-records-modal hidden>
                <div class="oyiso-coupon-lottery__modal-backdrop" data-lottery-records-close></div>
                <div class="oyiso-coupon-lottery__modal-panel oyiso-coupon-lottery__modal-panel--wide" role="dialog" aria-modal="true">
                    <div class="oyiso-coupon-lottery__modal-header">
                        <h4 class="oyiso-coupon-lottery__modal-title"><?php echo esc_html($settings['records_button_text'] ?: oyiso_t('Draw Records')); ?></h4>
                        <button type="button" class="oyiso-coupon-lottery__modal-close" data-lottery-records-close aria-label="<?php echo esc_attr(oyiso_t('Close')); ?>"></button>
                    </div>
                    <div class="oyiso-coupon-lottery__modal-content oyiso-coupon-lottery__modal-content--records">
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

    private function generate_range_values(float $min, float $max, bool $allow_decimals): array
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        if ($allow_decimals) {
            $start = (int) round($min * 10);
            $end = (int) round($max * 10);
            $values = [];

            for ($i = $start; $i <= $end; $i++) {
                $values[] = round($i / 10, 1);
            }

            return $values;
        }

        $start = (int) round($min);
        $end = (int) round($max);
        $values = [];

        for ($i = $start; $i <= $end; $i++) {
            $values[] = (float) $i;
        }

        return $values;
    }

    private function normalize_range_value_string($value, bool $allow_decimals): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $number = $allow_decimals ? round((float) $value, 1) : (float) round((float) $value);

        return $allow_decimals ? number_format($number, 1, '.', '') : (string) (int) $number;
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
