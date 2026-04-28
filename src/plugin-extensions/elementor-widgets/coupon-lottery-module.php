<?php

defined('ABSPATH') || exit;

if (!class_exists('Oyiso_Coupon_Lottery_Module')) {
    final class Oyiso_Coupon_Lottery_Module {
        private const DB_VERSION = '1.0.0';
        private const DB_OPTION = 'oyiso_coupon_lottery_db_version';
        private const TABLE_SLUG = 'oyiso_coupon_lottery_records';
        private const SIGN_ACTION = 'oyiso_coupon_lottery_payload';

        public static function init(): void {
            static $booted = false;

            if ($booted) {
                return;
            }

            $booted = true;

            add_action('init', [self::class, 'maybeCreateTable']);
            add_action('wp_ajax_oyiso_coupon_lottery_draw', [self::class, 'handleDraw']);
            add_action('wp_ajax_oyiso_coupon_lottery_claim', [self::class, 'handleClaim']);
            add_action('wp_ajax_oyiso_coupon_lottery_records', [self::class, 'handleRecords']);
        }

        public static function maybeCreateTable(): void {
            if (get_option(self::DB_OPTION) === self::DB_VERSION) {
                return;
            }

            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $table = self::getTableName();
            $charset = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                widget_key VARCHAR(120) NOT NULL DEFAULT '',
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                result_type VARCHAR(20) NOT NULL DEFAULT 'lose',
                status VARCHAR(20) NOT NULL DEFAULT 'completed',
                range_type VARCHAR(20) NOT NULL DEFAULT '',
                prize_value DECIMAL(10,1) NULL DEFAULT NULL,
                prize_label VARCHAR(191) NOT NULL DEFAULT '',
                probability DECIMAL(12,4) NOT NULL DEFAULT 0,
                coupon_id BIGINT UNSIGNED NULL DEFAULT NULL,
                coupon_code VARCHAR(191) NOT NULL DEFAULT '',
                payload LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                claimed_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY widget_key (widget_key),
                KEY user_lookup (blog_id, user_id, widget_key),
                KEY created_at (created_at)
            ) {$charset};";

            dbDelta($sql);
            update_option(self::DB_OPTION, self::DB_VERSION, false);
        }

        public static function buildSignedPayload(array $payload): array {
            $encoded = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $signature = hash_hmac('sha256', (string) $encoded, wp_salt(self::SIGN_ACTION));

            return [
                'payload'   => base64_encode((string) $encoded),
                'signature' => $signature,
            ];
        }

        public static function getCurrentAvailability(array $settings): array {
            if (!is_user_logged_in()) {
                return [
                    'allowed'        => false,
                    'reason'         => oyiso_t('Please log in before joining the draw.'),
                    'total_remaining'=> null,
                    'daily_remaining'=> null,
                ];
            }

            $payload = self::sanitizeLotteryPayload($settings);

            return self::evaluateAvailability(get_current_user_id(), $payload);
        }

        private static function getSiteLocale(): string {
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

        public static function handleDraw(): void {
            check_ajax_referer('oyiso_coupon_lottery_nonce', 'nonce');

            if (!is_user_logged_in()) {
                wp_send_json_error([
                    'message' => oyiso_t('Please log in before joining the draw.'),
                ], 403);
            }

            $payload = self::verifyPostedPayload();
            $user_id = get_current_user_id();
            $availability = self::evaluateAvailability($user_id, $payload);

            if (!$availability['allowed']) {
                wp_send_json_error([
                    'message' => $availability['reason'],
                ], 400);
            }

            $prizes = self::buildPrizePool($payload);

            if (empty($prizes)) {
                wp_send_json_error([
                    'message' => oyiso_t('No available lottery prizes are configured right now.'),
                ], 400);
            }

            $selected = self::pickPrize($prizes);
            $record_id = self::insertRecord($user_id, $payload, $selected);

            if (!$record_id) {
                wp_send_json_error([
                    'message' => oyiso_t('Failed to save the draw record. Please try again later.'),
                ], 500);
            }

            $is_win = ($selected['type'] ?? 'lose') === 'win';

            wp_send_json_success([
                'recordId'      => $record_id,
                'resultType'    => $is_win ? 'win' : 'lose',
                'prizeLabel'    => $selected['label'],
                'message'       => $is_win
                    ? oyiso_t_sprintf('Congratulations, you won %s.', $selected['label'])
                    : ($payload['thanks_label'] ?: oyiso_t('Thanks for participating. Better luck next time.')),
                'claimable'     => $is_win,
                'claimButton'   => $payload['claim_button_text'] ?: oyiso_t('Claim Coupon'),
                'availability'  => self::evaluateAvailability($user_id, $payload),
            ]);
        }

        public static function handleClaim(): void {
            check_ajax_referer('oyiso_coupon_lottery_nonce', 'nonce');

            if (!is_user_logged_in()) {
                wp_send_json_error([
                    'message' => oyiso_t('Please log in before claiming the coupon.'),
                ], 403);
            }

            if (!class_exists('WooCommerce') || !class_exists('WC_Coupon')) {
                wp_send_json_error([
                    'message' => oyiso_t('Please enable WooCommerce first.'),
                ], 400);
            }

            $payload = self::verifyPostedPayload();
            $record_id = isset($_POST['recordId']) ? absint(wp_unslash($_POST['recordId'])) : 0;

            if ($record_id <= 0) {
                wp_send_json_error([
                    'message' => oyiso_t('Invalid claim parameters.'),
                ], 400);
            }

            $record = self::getRecord($record_id);

            if (!$record || (int) $record['blog_id'] !== (int) get_current_blog_id()) {
                wp_send_json_error([
                    'message' => oyiso_t('The draw record does not exist.'),
                ], 404);
            }

            if ((int) $record['user_id'] !== get_current_user_id()) {
                wp_send_json_error([
                    'message' => oyiso_t('You are not allowed to claim this coupon.'),
                ], 403);
            }

            if (($record['result_type'] ?? '') !== 'win') {
                wp_send_json_error([
                    'message' => oyiso_t('This record did not win, so the coupon cannot be claimed.'),
                ], 400);
            }

            if (($record['status'] ?? '') === 'claimed' && !empty($record['coupon_code'])) {
                wp_send_json_success([
                    'couponId'   => (int) $record['coupon_id'],
                    'couponCode' => (string) $record['coupon_code'],
                    'message'    => oyiso_t('The coupon has already been claimed and can be copied directly.'),
                ]);
            }

            $coupon = self::createCouponForRecord($record, $payload);

            if (is_wp_error($coupon)) {
                wp_send_json_error([
                    'message' => $coupon->get_error_message(),
                ], 400);
            }

            wp_send_json_success([
                'couponId'   => (int) $coupon['coupon_id'],
                'couponCode' => (string) $coupon['coupon_code'],
                'message'    => oyiso_t('Claim successful. Your coupon has been generated.'),
            ]);
        }

        public static function handleRecords(): void {
            check_ajax_referer('oyiso_coupon_lottery_nonce', 'nonce');

            if (!is_user_logged_in()) {
                wp_send_json_error([
                    'message' => oyiso_t('Please log in before viewing draw records.'),
                ], 403);
            }

            $payload = self::verifyPostedPayload();
            $widget_key = sanitize_text_field((string) ($payload['widget_key'] ?? ''));

            if ($widget_key === '') {
                wp_send_json_error([
                    'message' => oyiso_t('Invalid lottery identifier.'),
                ], 400);
            }

            $records = [
                'mine' => self::queryRecords([
                    'widget_key' => $widget_key,
                    'user_id'    => get_current_user_id(),
                    'limit'      => (int) ($payload['records_per_tab'] ?? 20),
                ]),
            ];

            if (self::currentUserCanManageCoupons()) {
                $records['all'] = self::queryRecords([
                    'widget_key' => $widget_key,
                    'limit'      => (int) ($payload['records_per_tab'] ?? 20),
                ]);
            }

            wp_send_json_success([
                'records' => $records,
            ]);
        }

        private static function verifyPostedPayload(): array {
            $posted_payload = isset($_POST['payload']) ? (string) wp_unslash($_POST['payload']) : '';
            $posted_signature = isset($_POST['signature']) ? (string) wp_unslash($_POST['signature']) : '';

            if ($posted_payload === '' || $posted_signature === '') {
                wp_send_json_error([
                    'message' => oyiso_t('Lottery configuration is missing. Refresh the page and try again.'),
                ], 400);
            }

            $decoded = base64_decode($posted_payload, true);

            if (!is_string($decoded) || $decoded === '') {
                wp_send_json_error([
                    'message' => oyiso_t('Failed to parse the lottery configuration.'),
                ], 400);
            }

            $expected_signature = hash_hmac('sha256', $decoded, wp_salt(self::SIGN_ACTION));

            if (!hash_equals($expected_signature, $posted_signature)) {
                wp_send_json_error([
                    'message' => oyiso_t('Lottery configuration validation failed. Refresh the page and try again.'),
                ], 400);
            }

            $payload = json_decode($decoded, true);

            if (!is_array($payload)) {
                wp_send_json_error([
                    'message' => oyiso_t('The lottery configuration format is invalid.'),
                ], 400);
            }

            return self::sanitizeLotteryPayload($payload);
        }

        private static function sanitizeLotteryPayload(array $payload): array {
            $range_type = ($payload['range_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
            $allow_decimals = !empty($payload['allow_decimals']);
            $min_value = self::sanitizeRangeValue($payload['min_value'] ?? 0, $allow_decimals);
            $max_value = self::sanitizeRangeValue($payload['max_value'] ?? 0, $allow_decimals);

            if ($min_value > $max_value) {
                [$min_value, $max_value] = [$max_value, $min_value];
            }

            $probability_map = [];

            if (!empty($payload['probability_map']) && is_array($payload['probability_map'])) {
                foreach ($payload['probability_map'] as $item) {
                    $value_key = self::normalizeRangeValueString($item['value'] ?? '', $allow_decimals);
                    $weight = isset($item['weight']) ? (float) $item['weight'] : 0;

                    if ($value_key === '' || $weight < 0) {
                        continue;
                    }

                    $probability_map[$value_key] = $weight;
                }
            }

            $product_ids = self::sanitizeIdList($payload['product_ids'] ?? []);
            $excluded_product_ids = self::sanitizeIdList($payload['excluded_product_ids'] ?? []);
            $category_ids = self::sanitizeIdList($payload['category_ids'] ?? []);
            $excluded_category_ids = self::sanitizeIdList($payload['excluded_category_ids'] ?? []);

            return [
                'widget_key'           => sanitize_text_field((string) ($payload['widget_key'] ?? '')),
                'post_id'              => absint($payload['post_id'] ?? 0),
                'title'                => sanitize_text_field((string) ($payload['title'] ?? '')),
                'description'          => sanitize_textarea_field((string) ($payload['description'] ?? '')),
                'draw_button_text'     => sanitize_text_field((string) ($payload['draw_button_text'] ?? '')),
                'claim_button_text'    => sanitize_text_field((string) ($payload['claim_button_text'] ?? '')),
                'records_button_text'  => sanitize_text_field((string) ($payload['records_button_text'] ?? '')),
                'range_type'           => $range_type,
                'allow_decimals'       => $allow_decimals,
                'min_value'            => $min_value,
                'max_value'            => $max_value,
                'default_weight'       => max(0, (float) ($payload['default_weight'] ?? 1)),
                'probability_map'      => $probability_map,
                'enable_thanks'        => !empty($payload['enable_thanks']),
                'thanks_weight'        => max(0, (float) ($payload['thanks_weight'] ?? 0)),
                'thanks_label'         => sanitize_text_field((string) ($payload['thanks_label'] ?? oyiso_t('Thanks for participating'))),
                'start_at'             => sanitize_text_field((string) ($payload['start_at'] ?? '')),
                'end_at'               => sanitize_text_field((string) ($payload['end_at'] ?? '')),
                'daily_limit'          => max(0, (int) ($payload['daily_limit'] ?? 0)),
                'total_limit'          => max(0, (int) ($payload['total_limit'] ?? 1)),
                'coupon_prefix'        => strtoupper(sanitize_key((string) ($payload['coupon_prefix'] ?? 'OYL'))),
                'coupon_description'   => sanitize_text_field((string) ($payload['coupon_description'] ?? '')),
                'expiry_days'          => max(0, (int) ($payload['expiry_days'] ?? 7)),
                'minimum_amount'       => self::sanitizeMoneyValue($payload['minimum_amount'] ?? ''),
                'maximum_amount'       => self::sanitizeMoneyValue($payload['maximum_amount'] ?? ''),
                'maximum_discount'     => self::sanitizeMoneyValue($payload['maximum_discount'] ?? ''),
                'individual_use'       => !empty($payload['individual_use']),
                'exclude_sale_items'   => !empty($payload['exclude_sale_items']),
                'free_shipping'        => !empty($payload['free_shipping']),
                'product_ids'          => $product_ids,
                'excluded_product_ids' => $excluded_product_ids,
                'category_ids'         => $category_ids,
                'excluded_category_ids'=> $excluded_category_ids,
                'records_per_tab'      => max(1, min(100, (int) ($payload['records_per_tab'] ?? 20))),
            ];
        }

        private static function sanitizeRangeValue($value, bool $allow_decimals): float {
            $number = (float) $value;

            if ($allow_decimals) {
                return round($number, 1);
            }

            return (float) round($number);
        }

        private static function sanitizeMoneyValue($value): string {
            if ($value === '' || $value === null) {
                return '';
            }

            return (string) max(0, round((float) $value, 2));
        }

        private static function sanitizeIdList($value): array {
            if (!is_array($value)) {
                $value = [$value];
            }

            return array_values(array_filter(array_map('absint', $value)));
        }

        private static function normalizeRangeValueString($value, bool $allow_decimals): string {
            $value = trim((string) $value);

            if ($value === '') {
                return '';
            }

            $number = self::sanitizeRangeValue($value, $allow_decimals);

            return $allow_decimals ? number_format($number, 1, '.', '') : (string) (int) round($number);
        }

        private static function evaluateAvailability(int $user_id, array $payload): array {
            $now = current_time('timestamp');
            $start_at = self::parseDateTime($payload['start_at'] ?? '');
            $end_at = self::parseDateTime($payload['end_at'] ?? '');

            if ($start_at && $now < $start_at) {
                return [
                    'allowed'         => false,
                    'reason'          => oyiso_t('The event has not started yet.'),
                    'total_remaining' => null,
                    'daily_remaining' => null,
                ];
            }

            if ($end_at && $now > $end_at) {
                return [
                    'allowed'         => false,
                    'reason'          => oyiso_t('The event has ended.'),
                    'total_remaining' => 0,
                    'daily_remaining' => 0,
                ];
            }

            $widget_key = $payload['widget_key'] ?? '';
            $total_limit = (int) ($payload['total_limit'] ?? 0);
            $daily_limit = (int) ($payload['daily_limit'] ?? 0);
            $total_count = self::countUserRecords($user_id, $widget_key);
            $daily_count = self::countUserRecords($user_id, $widget_key, current_time('Y-m-d'));
            $total_remaining = $total_limit > 0 ? max(0, $total_limit - $total_count) : null;
            $daily_remaining = $daily_limit > 0 ? max(0, $daily_limit - $daily_count) : null;

            if ($total_limit > 0 && $total_count >= $total_limit) {
                return [
                    'allowed'         => false,
                    'reason'          => oyiso_t('You have reached the participation limit.'),
                    'total_remaining' => $total_remaining,
                    'daily_remaining' => $daily_remaining,
                ];
            }

            if ($daily_limit > 0 && $daily_count >= $daily_limit) {
                return [
                    'allowed'         => false,
                    'reason'          => oyiso_t('Your draw chances for today have been used up.'),
                    'total_remaining' => $total_remaining,
                    'daily_remaining' => $daily_remaining,
                ];
            }

            return [
                'allowed'         => true,
                'reason'          => '',
                'total_remaining' => $total_remaining,
                'daily_remaining' => $daily_remaining,
            ];
        }

        private static function parseDateTime(string $value): int {
            if ($value === '') {
                return 0;
            }

            $timestamp = strtotime($value);

            return $timestamp ?: 0;
        }

        private static function buildPrizePool(array $payload): array {
            $values = self::generateRangeValues(
                (float) $payload['min_value'],
                (float) $payload['max_value'],
                !empty($payload['allow_decimals'])
            );
            $default_weight = (float) ($payload['default_weight'] ?? 1);
            $map = $payload['probability_map'] ?? [];
            $prizes = [];

            foreach ($values as $value) {
                $value_key = self::normalizeRangeValueString($value, !empty($payload['allow_decimals']));
                $weight = array_key_exists($value_key, $map) ? (float) $map[$value_key] : $default_weight;

                if ($weight <= 0) {
                    continue;
                }

                $prizes[] = [
                    'type'        => 'win',
                    'weight'      => $weight,
                    'value'       => $value,
                    'label'       => self::formatPrizeLabel($value, $payload['range_type']),
                    'probability' => $weight,
                ];
            }

            if (!empty($payload['enable_thanks']) && (float) ($payload['thanks_weight'] ?? 0) > 0) {
                $prizes[] = [
                    'type'        => 'lose',
                    'weight'      => (float) $payload['thanks_weight'],
                    'value'       => null,
                    'label'       => $payload['thanks_label'] ?: oyiso_t('Thanks for participating'),
                    'probability' => (float) $payload['thanks_weight'],
                ];
            }

            return $prizes;
        }

        private static function generateRangeValues(float $min, float $max, bool $allow_decimals): array {
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

        private static function formatPrizeLabel(?float $value, string $range_type): string {
            if ($value === null) {
                return oyiso_t('Thanks for participating');
            }

            if ($range_type === 'percent') {
                $text = ((int) $value === (float) $value)
                    ? (string) (int) $value
                    : number_format($value, 1, '.', '');

                if (!str_starts_with(self::getSiteLocale(), 'zh_')) {
                    $discount = max(0, min(100, 100 - ((float) $value * 10)));
                    $discount_text = ((int) $discount === (float) $discount)
                        ? (string) (int) $discount
                        : number_format($discount, 1, '.', '');

                    return oyiso_t_sprintf('%s%% off', $discount_text);
                }

                return oyiso_t_sprintf('%s discount rate', $text);
            }

            if (function_exists('wc_price')) {
                return wp_strip_all_tags(wc_price($value));
            }

            return number_format($value, 2, '.', '');
        }

        private static function pickPrize(array $prizes): array {
            $total = 0.0;

            foreach ($prizes as $prize) {
                $total += max(0, (float) ($prize['weight'] ?? 0));
            }

            if ($total <= 0) {
                return [
                    'type'        => 'lose',
                    'weight'      => 0,
                    'value'       => null,
                    'label'       => oyiso_t('Thanks for participating'),
                    'probability' => 0,
                ];
            }

            $random = mt_rand() / mt_getrandmax() * $total;
            $cursor = 0.0;

            foreach ($prizes as $prize) {
                $cursor += max(0, (float) ($prize['weight'] ?? 0));

                if ($random <= $cursor) {
                    return $prize;
                }
            }

            return end($prizes) ?: [
                'type'        => 'lose',
                'weight'      => 0,
                'value'       => null,
                'label'       => oyiso_t('Thanks for participating'),
                'probability' => 0,
            ];
        }

        private static function insertRecord(int $user_id, array $payload, array $selected): int {
            global $wpdb;

            $inserted = $wpdb->insert(
                self::getTableName(),
                [
                    'blog_id'     => get_current_blog_id(),
                    'widget_key'  => $payload['widget_key'],
                    'post_id'     => (int) ($payload['post_id'] ?? 0),
                    'user_id'     => $user_id,
                    'result_type' => ($selected['type'] ?? 'lose') === 'win' ? 'win' : 'lose',
                    'status'      => ($selected['type'] ?? 'lose') === 'win' ? 'pending' : 'completed',
                    'range_type'  => $payload['range_type'],
                    'prize_value' => $selected['value'],
                    'prize_label' => $selected['label'],
                    'probability' => (float) ($selected['probability'] ?? 0),
                    'payload'     => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at'  => current_time('mysql'),
                ],
                [
                    '%d',
                    '%s',
                    '%d',
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%f',
                    '%s',
                    '%f',
                    '%s',
                    '%s',
                ]
            );

            return $inserted ? (int) $wpdb->insert_id : 0;
        }

        private static function createCouponForRecord(array $record, array $payload) {
            $user = get_userdata((int) $record['user_id']);

            if (!$user || empty($user->user_email)) {
                return new WP_Error('oyiso_coupon_lottery_user_invalid', oyiso_t('The current user information is invalid, so the coupon could not be generated.'));
            }

            $prize_value = isset($record['prize_value']) ? (float) $record['prize_value'] : 0.0;
            $coupon_amount = $payload['range_type'] === 'percent'
                ? max(0, round(100 - ($prize_value * 10), 1))
                : round($prize_value, 2);

            if ($coupon_amount <= 0) {
                return new WP_Error('oyiso_coupon_lottery_amount_invalid', oyiso_t('The current prize amount is invalid, so the coupon could not be generated.'));
            }

            $coupon = new WC_Coupon();
            $coupon_code = self::generateCouponCode($payload['coupon_prefix'] ?? 'OYL');
            $coupon_description = $payload['coupon_description'] !== ''
                ? $payload['coupon_description']
                : oyiso_t_sprintf('Lottery Claim: %s', $record['prize_label']);

            $coupon->set_code($coupon_code);
            $coupon->set_description($coupon_description);
            $coupon->set_discount_type($payload['range_type'] === 'percent' ? 'percent' : 'fixed_cart');
            $coupon->set_amount($coupon_amount);
            $coupon->set_individual_use(!empty($payload['individual_use']));
            $coupon->set_usage_limit(1);
            $coupon->set_usage_limit_per_user(1);
            $coupon->set_free_shipping(!empty($payload['free_shipping']));
            $coupon->set_exclude_sale_items(!empty($payload['exclude_sale_items']));
            $coupon->set_email_restrictions([$user->user_email]);

            if ($payload['minimum_amount'] !== '') {
                $coupon->set_minimum_amount($payload['minimum_amount']);
            }

            if ($payload['maximum_amount'] !== '') {
                $coupon->set_maximum_amount($payload['maximum_amount']);
            }

            if (!empty($payload['product_ids'])) {
                $coupon->set_product_ids($payload['product_ids']);
            }

            if (!empty($payload['excluded_product_ids'])) {
                $coupon->set_excluded_product_ids($payload['excluded_product_ids']);
            }

            if (!empty($payload['category_ids'])) {
                $coupon->set_product_categories($payload['category_ids']);
            }

            if (!empty($payload['excluded_category_ids'])) {
                $coupon->set_excluded_product_categories($payload['excluded_category_ids']);
            }

            if ((int) $payload['expiry_days'] > 0) {
                $expires = new WC_DateTime('now', new DateTimeZone(wp_timezone_string()));
                $expires->modify('+' . (int) $payload['expiry_days'] . ' days');
                $coupon->set_date_expires($expires);
            }

            $coupon_id = $coupon->save();

            if (!$coupon_id) {
                return new WP_Error('oyiso_coupon_lottery_coupon_create_failed', oyiso_t('Failed to generate the coupon. Please try again later.'));
            }

            if ($payload['range_type'] === 'percent' && $payload['maximum_discount'] !== '') {
                update_post_meta($coupon_id, 'maximum_amount', $payload['maximum_discount']);
            }

            update_post_meta($coupon_id, '_oyiso_coupon_lottery_record_id', (int) $record['id']);
            update_post_meta($coupon_id, '_oyiso_coupon_lottery_widget_key', $payload['widget_key']);

            global $wpdb;
            $wpdb->update(
                self::getTableName(),
                [
                    'status'      => 'claimed',
                    'coupon_id'   => $coupon_id,
                    'coupon_code' => $coupon_code,
                    'claimed_at'  => current_time('mysql'),
                ],
                [
                    'id' => (int) $record['id'],
                ],
                [
                    '%s',
                    '%d',
                    '%s',
                    '%s',
                ],
                [
                    '%d',
                ]
            );

            return [
                'coupon_id'   => $coupon_id,
                'coupon_code' => $coupon_code,
            ];
        }

        private static function generateCouponCode(string $prefix): string {
            $prefix = $prefix !== '' ? strtoupper($prefix) : 'OYL';

            do {
                $code = $prefix . '-' . strtoupper(wp_generate_password(8, false, false));
                $exists = wc_get_coupon_id_by_code($code);
            } while ($exists);

            return $code;
        }

        private static function getRecord(int $record_id): ?array {
            global $wpdb;

            $record = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM ' . self::getTableName() . ' WHERE id = %d LIMIT 1',
                    $record_id
                ),
                ARRAY_A
            );

            return is_array($record) ? $record : null;
        }

        private static function countUserRecords(int $user_id, string $widget_key, string $date = ''): int {
            global $wpdb;

            $sql = 'SELECT COUNT(id) FROM ' . self::getTableName() . ' WHERE blog_id = %d AND user_id = %d AND widget_key = %s';
            $params = [get_current_blog_id(), $user_id, $widget_key];

            if ($date !== '') {
                $sql .= ' AND DATE(created_at) = %s';
                $params[] = $date;
            }

            return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
        }

        private static function queryRecords(array $args): array {
            global $wpdb;

            $where = ['blog_id = %d', 'widget_key = %s'];
            $params = [get_current_blog_id(), (string) ($args['widget_key'] ?? '')];

            if (!empty($args['user_id'])) {
                $where[] = 'user_id = %d';
                $params[] = (int) $args['user_id'];
            }

            $limit = !empty($args['limit']) ? (int) $args['limit'] : 20;
            $limit = max(1, min(100, $limit));
            $params[] = $limit;

            $sql = 'SELECT * FROM ' . self::getTableName() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d';
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

            if (!is_array($rows)) {
                return [];
            }

            $formatted = [];

            foreach ($rows as $row) {
                $user_name = '';

                if (!empty($row['user_id'])) {
                    $user = get_userdata((int) $row['user_id']);
                    $user_name = $user ? $user->display_name : '';
                }

                $formatted[] = [
                    'id'           => (int) $row['id'],
                    'resultType'   => (string) $row['result_type'],
                    'resultLabel'  => $row['result_type'] === 'win' ? oyiso_t('Won') : oyiso_t('Not Won'),
                    'status'       => (string) $row['status'],
                    'statusLabel'  => $row['status'] === 'claimed'
                        ? oyiso_t('Claimed')
                        : ($row['result_type'] === 'win' ? oyiso_t('Pending Claim') : oyiso_t('Completed')),
                    'prizeLabel'   => (string) $row['prize_label'],
                    'couponId'     => (int) $row['coupon_id'],
                    'couponCode'   => (string) $row['coupon_code'],
                    'createdAt'    => !empty($row['created_at']) ? wp_date('Y-m-d H:i:s', strtotime((string) $row['created_at'])) : '',
                    'claimedAt'    => !empty($row['claimed_at']) ? wp_date('Y-m-d H:i:s', strtotime((string) $row['claimed_at'])) : '',
                    'isOwner'      => (int) $row['user_id'] === get_current_user_id(),
                    'canClaim'     => $row['result_type'] === 'win' && $row['status'] === 'pending' && (int) $row['user_id'] === get_current_user_id(),
                    'editUrl'      => !empty($row['coupon_id']) && self::currentUserCanManageCoupons()
                        ? admin_url('post.php?post=' . (int) $row['coupon_id'] . '&action=edit')
                        : '',
                    'userName'     => $user_name,
                ];
            }

            return $formatted;
        }

        private static function currentUserCanManageCoupons(): bool {
            return current_user_can('manage_woocommerce')
                || current_user_can('edit_shop_coupons')
                || current_user_can('manage_options');
        }

        private static function getTableName(): string {
            global $wpdb;

            return $wpdb->prefix . self::TABLE_SLUG;
        }
    }
}

Oyiso_Coupon_Lottery_Module::init();
