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
                    'prize_pool_remaining' => null,
                ];
            }

            $payload = self::sanitizeLotteryPayload($settings);

            return self::evaluateAvailability(get_current_user_id(), $payload);
        }

        private static function getSiteLocale(): string {
            $site_locale = function_exists('get_locale') ? (string) get_locale() : '';

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
            $widget_key = sanitize_text_field((string) ($payload['widget_key'] ?? ''));
            $user_id = get_current_user_id();

            if ($widget_key === '') {
                wp_send_json_error([
                    'message' => oyiso_t('Invalid lottery identifier.'),
                ], 400);
            }

            $lock_name = self::buildAdvisoryLockName('draw', [get_current_blog_id(), $widget_key]);

            if (!self::acquireAdvisoryLock($lock_name)) {
                wp_send_json_error([
                    'message' => oyiso_t('The draw is busy right now. Please try again in a moment.'),
                ], 409);
            }

            $response = null;
            $status_code = 200;

            try {
                $availability = self::evaluateAvailability($user_id, $payload);

                if (!$availability['allowed']) {
                    $response = [
                        'success' => false,
                        'data'    => [
                            'message'      => $availability['reason'],
                            'availability' => $availability,
                        ],
                    ];
                    $status_code = 400;
                } else {
                    $prizes = self::buildPrizePool($payload);

                    if (empty($prizes)) {
                        $response = [
                            'success' => false,
                            'data'    => [
                                'message' => oyiso_t('No available lottery prizes are configured right now.'),
                            ],
                        ];
                        $status_code = 400;
                    } else {
                        $selected = self::pickPrize($prizes);
                        $record_id = self::insertRecord($user_id, $payload, $selected);

                        if (!$record_id) {
                            $response = [
                                'success' => false,
                                'data'    => [
                                    'message' => oyiso_t('Failed to save the draw record. Please try again later.'),
                                ],
                            ];
                            $status_code = 500;
                        } else {
                            $is_win = ($selected['type'] ?? 'lose') === 'win';

                            $response = [
                                'success' => true,
                                'data'    => [
                                    'recordId'          => $record_id,
                                    'resultType'        => $is_win ? 'win' : 'lose',
                                    'prizeLabel'        => $selected['label'],
                                    'message'           => $is_win
                                        ? oyiso_t('Congratulations. Claim to generate your coupon.')
                                        : oyiso_t('Thanks for participating. Better luck next time.'),
                                    'couponDescription' => $is_win ? trim((string) ($payload['coupon_description'] ?? '')) : '',
                                    'claimable'         => $is_win,
                                    'claimButton'       => oyiso_t('Claim Coupon'),
                                    'availability'      => self::evaluateAvailability($user_id, $payload),
                                ],
                            ];
                        }
                    }
                }
            } finally {
                self::releaseAdvisoryLock($lock_name);
            }

            if (!is_array($response)) {
                wp_send_json_error([
                    'message' => oyiso_t('The draw did not complete successfully. Please try again.'),
                ], 500);
            }

            if (empty($response['success'])) {
                wp_send_json_error($response['data'] ?? [], $status_code);
            }

            wp_send_json_success($response['data'] ?? []);
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

            $current_payload = self::verifyPostedPayload();
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

            $record_payload = self::extractRecordPayload($record);

            if (!empty($record_payload)) {
                $record_payload = self::sanitizeLotteryPayload($record_payload);
            } else {
                $record_payload = $current_payload;
            }

            $record_widget_key = sanitize_text_field((string) ($record['widget_key'] ?? ''));
            $current_widget_key = sanitize_text_field((string) ($current_payload['widget_key'] ?? ''));
            $record_post_id = (int) ($record['post_id'] ?? 0);
            $current_post_id = (int) ($current_payload['post_id'] ?? 0);

            if (
                $record_widget_key === ''
                || $current_widget_key !== $record_widget_key
                || ($record_post_id > 0 && $current_post_id > 0 && $current_post_id !== $record_post_id)
            ) {
                wp_send_json_error([
                    'message' => oyiso_t('This draw record does not belong to the current lottery.'),
                ], 400);
            }

            $lock_name = self::buildAdvisoryLockName('claim', [get_current_blog_id(), $record_id]);

            if (!self::acquireAdvisoryLock($lock_name)) {
                wp_send_json_error([
                    'message' => oyiso_t('The coupon is being claimed right now. Please try again in a moment.'),
                ], 409);
            }

            $response = null;
            $status_code = 200;

            try {
                $record = self::getRecord($record_id);

                if (!$record || (int) $record['blog_id'] !== (int) get_current_blog_id()) {
                    $response = [
                        'success' => false,
                        'data'    => [
                            'message' => oyiso_t('The draw record does not exist.'),
                        ],
                    ];
                    $status_code = 404;
                } elseif ((int) $record['user_id'] !== get_current_user_id()) {
                    $response = [
                        'success' => false,
                        'data'    => [
                            'message' => oyiso_t('You are not allowed to claim this coupon.'),
                        ],
                    ];
                    $status_code = 403;
                } elseif (($record['result_type'] ?? '') !== 'win') {
                    $response = [
                        'success' => false,
                        'data'    => [
                            'message' => oyiso_t('This record did not win, so the coupon cannot be claimed.'),
                        ],
                    ];
                    $status_code = 400;
                } else {
                    if (($record['status'] ?? '') === 'claimed' && !empty($record['coupon_code'])) {
                        $latest_record = self::getRecord($record_id);
                        $response = [
                            'success' => true,
                            'data'    => [
                                'couponId'   => (int) $record['coupon_id'],
                                'couponCode' => (string) $record['coupon_code'],
                                'message'    => oyiso_t('The coupon has already been claimed and can be copied directly.'),
                                'record'     => $latest_record ? self::formatRecordRow($latest_record) : null,
                            ],
                        ];
                    } elseif (self::hasRecordClaimExpired($record, $record_payload)) {
                        $response = [
                            'success' => false,
                            'data'    => [
                                'message' => oyiso_t('This coupon claim has expired and can no longer be generated.'),
                            ],
                        ];
                        $status_code = 400;
                    } else {
                        $coupon = self::createCouponForRecord($record, $record_payload);

                        if (is_wp_error($coupon)) {
                            $response = [
                                'success' => false,
                                'data'    => [
                                    'message' => $coupon->get_error_message(),
                                ],
                            ];
                            $status_code = 400;
                        } else {
                            $latest_record = self::getRecord($record_id);
                            $response = [
                                'success' => true,
                                'data'    => [
                                    'couponId'   => (int) $coupon['coupon_id'],
                                    'couponCode' => (string) $coupon['coupon_code'],
                                    'message'    => oyiso_t('Claim successful. Your coupon has been generated.'),
                                    'record'     => $latest_record ? self::formatRecordRow($latest_record) : null,
                                ],
                            ];
                        }
                    }
                }
            } finally {
                self::releaseAdvisoryLock($lock_name);
            }

            if (!is_array($response)) {
                wp_send_json_error([
                    'message' => oyiso_t('The coupon claim did not complete successfully. Please try again.'),
                ], 500);
            }

            if (empty($response['success'])) {
                wp_send_json_error($response['data'] ?? [], $status_code);
            }

            wp_send_json_success($response['data'] ?? []);
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

            $limit = max(1, min(100, (int) ($payload['records_per_tab'] ?? 20)));
            $tab = isset($_POST['tab']) ? sanitize_key((string) wp_unslash($_POST['tab'])) : '';
            $offset = isset($_POST['offset']) ? max(0, (int) wp_unslash($_POST['offset'])) : 0;

            if ($tab !== '') {
                if ($tab === 'mine') {
                    wp_send_json_success([
                        'tab' => 'mine',
                        'data' => self::queryRecords([
                            'widget_key' => $widget_key,
                            'user_id'    => get_current_user_id(),
                            'limit'      => $limit,
                            'offset'     => $offset,
                        ]),
                    ]);
                }

                if ($tab === 'all' && self::currentUserCanManageCoupons()) {
                    wp_send_json_success([
                        'tab' => 'all',
                        'data' => self::queryRecords([
                            'widget_key' => $widget_key,
                            'limit'      => $limit,
                            'offset'     => $offset,
                        ]),
                    ]);
                }

                wp_send_json_error([
                    'message' => oyiso_t('Invalid record tab.'),
                ], 400);
            }

            $records = [
                'mine' => self::queryRecords([
                    'widget_key' => $widget_key,
                    'user_id'    => get_current_user_id(),
                    'limit'      => $limit,
                    'offset'     => 0,
                ]),
            ];

            if (self::currentUserCanManageCoupons()) {
                $records['all'] = self::queryRecords([
                    'widget_key' => $widget_key,
                    'limit'      => $limit,
                    'offset'     => 0,
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
            $prize_rules = self::sanitizePrizeRules($payload['prize_rules'] ?? [], $range_type);
            $draw_value_mode = ($payload['draw_value_mode'] ?? 'range') === 'custom' ? 'custom' : 'range';
            $min_value = self::sanitizeRangeValue($payload['min_value'] ?? 0, $range_type);
            $max_value = self::sanitizeRangeValue($payload['max_value'] ?? 0, $range_type);

            if ($min_value > $max_value) {
                [$min_value, $max_value] = [$max_value, $min_value];
            }

            $probability_map = [];

            if (!empty($payload['probability_map']) && is_array($payload['probability_map'])) {
                foreach ($payload['probability_map'] as $item) {
                    $value_key = self::normalizeRangeValueString($item['value'] ?? '', $range_type);
                    $weight = isset($item['weight']) ? (float) $item['weight'] : 0;

                    if ($value_key === '' || $weight < 0) {
                        continue;
                    }

                    $probability_map[$value_key] = $weight;
                }
            }

            $custom_prizes = [];

            if (!empty($payload['custom_prizes']) && is_array($payload['custom_prizes'])) {
                foreach ($payload['custom_prizes'] as $item) {
                    $value_key = self::normalizeRangeValueString($item['value'] ?? '', $range_type);
                    $weight = isset($item['weight']) ? (float) $item['weight'] : 0;

                    if ($value_key === '' || $weight <= 0) {
                        continue;
                    }

                    $numeric_value = $range_type === 'percent' ? (float) (int) $value_key : (float) $value_key;
                    $custom_prizes[$value_key] = [
                        'value'  => $numeric_value,
                        'weight' => $weight,
                    ];
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
                'range_type'           => $range_type,
                'prize_rules'          => $prize_rules,
                'draw_value_mode'      => $draw_value_mode,
                'min_value'            => $min_value,
                'max_value'            => $max_value,
                'default_weight'       => max(0, (float) ($payload['default_weight'] ?? 1)),
                'probability_map'      => $probability_map,
                'custom_prizes'        => array_values($custom_prizes),
                'enable_thanks'        => !empty($payload['enable_thanks']),
                'stop_after_win_with_thanks' => !empty($payload['stop_after_win_with_thanks']),
                'thanks_weight'        => max(0, (float) ($payload['thanks_weight'] ?? 0)),
                'start_at'             => sanitize_text_field((string) ($payload['start_at'] ?? '')),
                'end_at'               => sanitize_text_field((string) ($payload['end_at'] ?? '')),
                'daily_limit'          => max(0, (int) ($payload['daily_limit'] ?? 0)),
                'total_limit'          => max(0, (int) ($payload['total_limit'] ?? 1)),
                'prize_pool_mode'      => ($payload['prize_pool_mode'] ?? 'unlimited') === 'limited' ? 'limited' : 'unlimited',
                'prize_pool_limit'     => max(1, (int) ($payload['prize_pool_limit'] ?? 100)),
                'coupon_prefix'        => strtoupper(sanitize_key((string) ($payload['coupon_prefix'] ?? 'OYL'))),
                'coupon_description'   => sanitize_textarea_field((string) ($payload['coupon_description'] ?? '')),
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

        private static function sanitizePrizeRules($rules, string $range_type): array {
            if (!is_array($rules)) {
                return [];
            }

            $normalized_rules = [];

            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $mode = ($rule['mode'] ?? 'range') === 'single' ? 'single' : 'range';
                $probability = self::sanitizeProbability($rule['probability'] ?? 0);

                if ($probability <= 0) {
                    continue;
                }

                if ($mode === 'single') {
                    $value_key = self::normalizeRangeValueString($rule['value'] ?? '', $range_type);

                    if ($value_key === '') {
                        continue;
                    }

                    $normalized_rules[] = [
                        'mode'        => 'single',
                        'value'       => $range_type === 'percent' ? (float) (int) $value_key : (float) $value_key,
                        'probability' => $probability,
                    ];

                    continue;
                }

                $start_value = self::sanitizeRangeValue($rule['start_value'] ?? 0, $range_type);
                $end_value = self::sanitizeRangeValue($rule['end_value'] ?? 0, $range_type);

                if ($start_value > $end_value) {
                    [$start_value, $end_value] = [$end_value, $start_value];
                }

                $normalized_rules[] = [
                    'mode'        => 'range',
                    'start_value' => $start_value,
                    'end_value'   => $end_value,
                    'probability' => $probability,
                ];
            }

            return $normalized_rules;
        }

        private static function sanitizeRangeValue($value, string $range_type): float {
            if (is_array($value)) {
                $value = $value['size'] ?? 0;
            }

            $number = max(0, (float) $value);

            if ($range_type === 'percent') {
                return (float) min(100, (int) round($number));
            }

            return round($number, 1);
        }

        private static function sanitizeProbability($value): float {
            if (is_array($value)) {
                $value = $value['size'] ?? 0;
            }

            return (float) max(0, min(100, (int) round((float) $value)));
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

        private static function normalizeRangeValueString($value, string $range_type): string {
            $value = trim((string) $value);

            if ($value === '') {
                return '';
            }

            $number = self::sanitizeRangeValue($value, $range_type);

            if ($range_type === 'percent') {
                return (string) (int) round($number);
            }

            return self::formatDecimalValue($number, 1);
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
                    'prize_pool_remaining' => null,
                ];
            }

            if ($end_at && $now > $end_at) {
                return [
                    'allowed'         => false,
                    'reason'          => oyiso_t('The event has ended.'),
                    'total_remaining' => 0,
                    'daily_remaining' => 0,
                    'prize_pool_remaining' => null,
                ];
            }

            $widget_key = $payload['widget_key'] ?? '';
            $total_limit = (int) ($payload['total_limit'] ?? 0);
            $daily_limit = (int) ($payload['daily_limit'] ?? 0);
            $prize_pool_limit = ($payload['prize_pool_mode'] ?? 'unlimited') === 'limited'
                ? max(1, (int) ($payload['prize_pool_limit'] ?? 100))
                : 0;
            $total_count = self::countUserRecords($user_id, $widget_key);
            $daily_count = self::countUserRecords($user_id, $widget_key, current_time('Y-m-d'));
            $winning_count = self::countUserWinningRecords($user_id, $widget_key);
            $prize_pool_count = $prize_pool_limit > 0 ? self::countWinningRecords($widget_key) : 0;
            $total_remaining = $total_limit > 0 ? max(0, $total_limit - $total_count) : null;
            $daily_remaining = $daily_limit > 0 ? max(0, $daily_limit - $daily_count) : null;
            $prize_pool_remaining = $prize_pool_limit > 0 ? max(0, $prize_pool_limit - $prize_pool_count) : null;

            if (!empty($payload['enable_thanks']) && !empty($payload['stop_after_win_with_thanks']) && $winning_count > 0) {
                return [
                    'allowed'         => false,
                    'reason'          => oyiso_t('You have already won this draw and cannot participate again.'),
                    'total_remaining' => $total_remaining,
                    'daily_remaining' => $daily_remaining,
                    'prize_pool_remaining' => $prize_pool_remaining,
                ];
            }

            if ($prize_pool_limit > 0 && $prize_pool_count >= $prize_pool_limit) {
                return [
                    'allowed'         => false,
                    'reason'          => oyiso_t('The prize pool has been exhausted. The draw has ended.'),
                    'total_remaining' => $total_remaining,
                    'daily_remaining' => $daily_remaining,
                    'prize_pool_remaining' => $prize_pool_remaining,
                ];
            }

            if ($total_limit > 0 && $total_count >= $total_limit) {
                return [
                    'allowed'         => false,
                    'reason'          => oyiso_t('You have reached the participation limit.'),
                    'total_remaining' => $total_remaining,
                    'daily_remaining' => $daily_remaining,
                    'prize_pool_remaining' => $prize_pool_remaining,
                ];
            }

            if ($daily_limit > 0 && $daily_count >= $daily_limit) {
                return [
                    'allowed'         => false,
                    'reason'          => oyiso_t('Your draw chances for today have been used up.'),
                    'total_remaining' => $total_remaining,
                    'daily_remaining' => $daily_remaining,
                    'prize_pool_remaining' => $prize_pool_remaining,
                ];
            }

            return [
                'allowed'         => true,
                'reason'          => '',
                'total_remaining' => $total_remaining,
                'daily_remaining' => $daily_remaining,
                'prize_pool_remaining' => $prize_pool_remaining,
            ];
        }

        private static function parseDateTime(string $value): int {
            if ($value === '') {
                return 0;
            }

            $timezone = wp_timezone();
            $datetime = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $value, $timezone);

            if (!$datetime) {
                $datetime = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone);
            }

            if (!$datetime) {
                try {
                    $datetime = new \DateTimeImmutable($value, $timezone);
                } catch (\Exception $exception) {
                    return 0;
                }
            }

            return $datetime->getTimestamp();
        }

        private static function buildPrizePool(array $payload): array {
            if (!empty($payload['prize_rules']) && is_array($payload['prize_rules'])) {
                return self::buildPrizePoolFromRules($payload);
            }

            return self::buildLegacyPrizePool($payload);
        }

        private static function buildPrizePoolFromRules(array $payload): array {
            $prizes = [];
            $total_win_probability = 0.0;

            foreach (($payload['prize_rules'] ?? []) as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $probability = isset($rule['probability']) ? (float) $rule['probability'] : 0.0;

                if ($probability <= 0) {
                    continue;
                }

                if (($rule['mode'] ?? 'range') === 'single') {
                    $value = isset($rule['value']) ? (float) $rule['value'] : 0.0;

                    $prizes[] = [
                        'type'        => 'win',
                        'weight'      => $probability,
                        'value'       => $value,
                        'label'       => self::formatPrizeLabel($value, $payload['range_type']),
                        'probability' => $probability,
                    ];
                    $total_win_probability += $probability;
                    continue;
                }

                $values = self::generateRangeValues(
                    (float) ($rule['start_value'] ?? 0),
                    (float) ($rule['end_value'] ?? 0),
                    $payload['range_type']
                );
                $count = count($values);

                if ($count <= 0) {
                    continue;
                }

                $per_probability = $probability / $count;

                foreach ($values as $value) {
                    $prizes[] = [
                        'type'        => 'win',
                        'weight'      => $per_probability,
                        'value'       => $value,
                        'label'       => self::formatPrizeLabel($value, $payload['range_type']),
                        'probability' => $per_probability,
                    ];
                }

                $total_win_probability += $probability;
            }

            return self::finalizePrizePool($prizes, $payload, $total_win_probability);
        }

        private static function buildLegacyPrizePool(array $payload): array {
            $prizes = [];

            if (($payload['draw_value_mode'] ?? 'range') === 'custom') {
                foreach (($payload['custom_prizes'] ?? []) as $item) {
                    $value = isset($item['value']) ? (float) $item['value'] : 0.0;
                    $weight = isset($item['weight']) ? (float) $item['weight'] : 0.0;

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
            } else {
                $values = self::generateRangeValues(
                    (float) $payload['min_value'],
                    (float) $payload['max_value'],
                    $payload['range_type']
                );
                $default_weight = (float) ($payload['default_weight'] ?? 1);
                $map = $payload['probability_map'] ?? [];

                foreach ($values as $value) {
                    $value_key = self::normalizeRangeValueString($value, $payload['range_type']);
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
            }

            if (!empty($payload['enable_thanks']) && (float) ($payload['thanks_weight'] ?? 0) > 0) {
                $prizes[] = [
                    'type'        => 'lose',
                    'weight'      => (float) $payload['thanks_weight'],
                    'value'       => null,
                    'label'       => oyiso_t('Thanks for participating'),
                    'probability' => (float) $payload['thanks_weight'],
                ];
            }

            return $prizes;
        }

        private static function finalizePrizePool(array $prizes, array $payload, float $total_win_probability): array {
            if (empty($prizes)) {
                if (!empty($payload['enable_thanks'])) {
                    return [[
                        'type'        => 'lose',
                        'weight'      => 100.0,
                        'value'       => null,
                        'label'       => oyiso_t('Thanks for participating'),
                        'probability' => 100.0,
                    ]];
                }

                return [];
            }

            if (!empty($payload['enable_thanks'])) {
                if ($total_win_probability < 100) {
                    $remaining = 100 - $total_win_probability;
                    $prizes[] = [
                        'type'        => 'lose',
                        'weight'      => $remaining,
                        'value'       => null,
                        'label'       => oyiso_t('Thanks for participating'),
                        'probability' => $remaining,
                    ];

                    return $prizes;
                }

                if ($total_win_probability <= 0) {
                    return [[
                        'type'        => 'lose',
                        'weight'      => 100.0,
                        'value'       => null,
                        'label'       => oyiso_t('Thanks for participating'),
                        'probability' => 100.0,
                    ]];
                }
            }

            if ($total_win_probability <= 0) {
                return [];
            }

            if (abs($total_win_probability - 100.0) < 0.0001) {
                return $prizes;
            }

            $scale = 100 / $total_win_probability;

            foreach ($prizes as &$prize) {
                if (($prize['type'] ?? 'win') !== 'win') {
                    continue;
                }

                $scaled_probability = (float) ($prize['probability'] ?? 0) * $scale;
                $prize['weight'] = $scaled_probability;
                $prize['probability'] = $scaled_probability;
            }
            unset($prize);

            return $prizes;
        }

        private static function generateRangeValues(float $min, float $max, string $range_type): array {
            if ($range_type === 'amount') {
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
                return oyiso_t_sprintf('%s%% off', (string) (int) round($value));
            }

            return self::formatPlainPrice($value);
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
            $record_id = (int) ($record['id'] ?? 0);

            if ($record_id <= 0) {
                return new WP_Error('oyiso_coupon_lottery_record_invalid', oyiso_t('The draw record is invalid, so the coupon could not be generated.'));
            }

            $existing_coupon = self::findExistingCouponForRecord($record_id);

            if ($existing_coupon) {
                self::markRecordClaimed($record_id, (int) $existing_coupon['coupon_id'], (string) $existing_coupon['coupon_code']);
                return $existing_coupon;
            }

            if (($record['status'] ?? '') === 'claimed') {
                return new WP_Error('oyiso_coupon_lottery_already_claimed', oyiso_t('The coupon has already been claimed and can be copied directly.'));
            }

            $user = get_userdata((int) $record['user_id']);

            if (!$user || empty($user->user_email)) {
                return new WP_Error('oyiso_coupon_lottery_user_invalid', oyiso_t('The current user information is invalid, so the coupon could not be generated.'));
            }

            $prize_value = isset($record['prize_value']) ? (float) $record['prize_value'] : 0.0;
            $coupon_amount = $payload['range_type'] === 'percent'
                ? max(0, min(100, (int) round($prize_value)))
                : round($prize_value, 2);

            if ($coupon_amount <= 0) {
                return new WP_Error('oyiso_coupon_lottery_amount_invalid', oyiso_t('The current prize amount is invalid, so the coupon could not be generated.'));
            }

            $coupon = new WC_Coupon();
            $coupon_code = self::generateCouponCode($payload['coupon_prefix'] ?? 'OYL');
            $coupon_description = $payload['coupon_description'] !== ''
                ? $payload['coupon_description']
                : self::buildInternalCouponDescription($payload, $coupon_amount);

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

            $expires = self::resolveRecordExpiryDateTime($record, $payload);

            if ($expires instanceof WC_DateTime) {
                $coupon->set_date_expires($expires);
            }

            $coupon_id = $coupon->save();

            if (!$coupon_id) {
                return new WP_Error('oyiso_coupon_lottery_coupon_create_failed', oyiso_t('Failed to generate the coupon. Please try again later.'));
            }

            if ($payload['range_type'] === 'percent' && $payload['maximum_discount'] !== '') {
                update_post_meta($coupon_id, 'maximum_amount', $payload['maximum_discount']);
            }

            update_post_meta($coupon_id, '_oyiso_coupon_lottery_record_id', $record_id);
            update_post_meta($coupon_id, '_oyiso_coupon_lottery_widget_key', $payload['widget_key']);
            self::markRecordClaimed($record_id, (int) $coupon_id, (string) $coupon_code);

            return [
                'coupon_id'   => $coupon_id,
                'coupon_code' => $coupon_code,
            ];
        }

        private static function buildInternalCouponDescription(array $payload, float $coupon_amount): string {
            $range_type = ($payload['range_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
            $type_label = $range_type === 'percent' ? '百分比折扣' : '固定金额';

            return sprintf('抽奖领取 - %s %s', $type_label, self::formatInternalCouponAmount($range_type, $coupon_amount));
        }

        private static function formatInternalCouponAmount(string $range_type, float $coupon_amount): string {
            if ($range_type === 'percent') {
                return (string) (int) round($coupon_amount) . '%';
            }

            $decimals = function_exists('wc_get_price_decimals') ? max(0, (int) wc_get_price_decimals()) : 2;
            $amount_text = number_format($coupon_amount, $decimals, '.', '');
            $symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '';

            return $symbol . $amount_text;
        }

        private static function formatDecimalValue(float $value, int $scale = 1): string {
            $formatted = number_format($value, $scale, '.', '');

            return rtrim(rtrim($formatted, '0'), '.');
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

        private static function countWinningRecords(string $widget_key): int {
            global $wpdb;

            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(id) FROM ' . self::getTableName() . ' WHERE blog_id = %d AND widget_key = %s AND result_type = %s',
                    get_current_blog_id(),
                    $widget_key,
                    'win'
                )
            );
        }

        private static function countUserWinningRecords(int $user_id, string $widget_key): int {
            global $wpdb;

            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(id) FROM ' . self::getTableName() . ' WHERE blog_id = %d AND user_id = %d AND widget_key = %s AND result_type = %s',
                    get_current_blog_id(),
                    $user_id,
                    $widget_key,
                    'win'
                )
            );
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
            $offset = !empty($args['offset']) ? (int) $args['offset'] : 0;
            $offset = max(0, $offset);
            $params[] = $limit + 1;
            $params[] = $offset;

            $sql = 'SELECT * FROM ' . self::getTableName() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

            if (!is_array($rows)) {
                return [
                    'items'      => [],
                    'hasMore'    => false,
                    'nextOffset' => $offset,
                ];
            }

            $has_more = count($rows) > $limit;

            if ($has_more) {
                array_pop($rows);
            }

            $items = array_map([self::class, 'formatRecordRow'], $rows);

            return [
                'items'      => $items,
                'hasMore'    => $has_more,
                'nextOffset' => $offset + count($items),
            ];
        }

        private static function formatRecordRow(array $row): array {
            $user_name = '';
            $record_payload = self::extractRecordPayload($row);
            $is_claim_expired = self::hasRecordClaimExpired($row, $record_payload);

            if (!empty($row['user_id'])) {
                $user = get_userdata((int) $row['user_id']);
                $user_name = $user ? $user->display_name : '';
            }

            return [
                'id'           => (int) $row['id'],
                'resultType'   => (string) $row['result_type'],
                'resultLabel'  => $row['result_type'] === 'win' ? oyiso_t('Won') : oyiso_t('Not Won'),
                'status'       => (string) $row['status'],
                'statusLabel'  => $row['status'] === 'claimed'
                    ? oyiso_t('Claimed')
                    : ($row['result_type'] === 'win'
                        ? ($is_claim_expired ? oyiso_t('Expired') : oyiso_t('Pending Claim'))
                        : oyiso_t('Completed')),
                'prizeLabel'   => self::resolveRecordPrizeLabel($row),
                'couponId'     => (int) $row['coupon_id'],
                'couponCode'   => (string) $row['coupon_code'],
                'scopeHtml'    => $row['result_type'] === 'win' ? self::formatScopeFromPayload($record_payload, $row) : '',
                'createdAt'    => self::formatSiteDateTime((string) ($row['created_at'] ?? '')),
                'claimedAt'    => self::formatSiteDateTime((string) ($row['claimed_at'] ?? '')),
                'isOwner'      => (int) $row['user_id'] === get_current_user_id(),
                'canClaim'     => $row['result_type'] === 'win' && $row['status'] === 'pending' && !$is_claim_expired && (int) $row['user_id'] === get_current_user_id(),
                'editUrl'      => !empty($row['coupon_id']) && self::currentUserCanManageCoupons()
                    ? admin_url('post.php?post=' . (int) $row['coupon_id'] . '&action=edit')
                    : '',
                'userName'     => $user_name,
            ];
        }

        private static function resolveRecordPrizeLabel(array $row): string {
            $result_type = (string) ($row['result_type'] ?? '');
            $range_type = (string) ($row['range_type'] ?? 'percent');
            $prize_value = $row['prize_value'] ?? null;

            if ($result_type === 'win' && $prize_value !== null && $prize_value !== '') {
                return self::formatPrizeLabel((float) $prize_value, $range_type);
            }

            if ($result_type !== 'win') {
                return oyiso_t('Thanks for participating');
            }

            return (string) ($row['prize_label'] ?? '');
        }

        private static function formatSiteDateTime(string $value): string {
            if ($value === '') {
                return '';
            }

            $timezone = wp_timezone();
            $datetime = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone);

            if (!$datetime) {
                try {
                    $datetime = new \DateTimeImmutable($value, $timezone);
                } catch (\Exception $exception) {
                    return $value;
                }
            }

            return $datetime->format('Y-m-d H:i:s');
        }

        private static function hasRecordClaimExpired(array $record, array $payload): bool {
            if (($record['result_type'] ?? '') !== 'win' || ($record['status'] ?? '') === 'claimed') {
                return false;
            }

            $expires_at = self::resolveRecordExpiryDateTime($record, $payload);

            return $expires_at instanceof WC_DateTime
                && $expires_at->getTimestamp() < time();
        }

        private static function resolveRecordExpiryDateTime(array $record, array $payload): ?WC_DateTime {
            $expiry_days = isset($payload['expiry_days']) ? (int) $payload['expiry_days'] : 0;

            if ($expiry_days <= 0) {
                return null;
            }

            $created_at = (string) ($record['created_at'] ?? '');

            if ($created_at === '') {
                return null;
            }

            try {
                $timezone = new DateTimeZone(wp_timezone_string());
                $expires_at = new WC_DateTime($created_at, $timezone);
                $expires_at->modify('+' . $expiry_days . ' days');

                return $expires_at;
            } catch (\Exception $exception) {
                return null;
            }
        }

        private static function extractRecordPayload(array $record): array {
            $payload = $record['payload'] ?? '';

            if (!is_string($payload) || $payload === '') {
                return [];
            }

            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        public static function formatScopeFromPayload(array $payload, ?array $record = null): string {
            $product_ids = self::sanitizeIdList($payload['product_ids'] ?? []);
            $excluded_product_ids = self::sanitizeIdList($payload['excluded_product_ids'] ?? []);
            $category_ids = self::sanitizeIdList($payload['category_ids'] ?? []);
            $excluded_category_ids = self::sanitizeIdList($payload['excluded_category_ids'] ?? []);
            $product_links = self::getScopeProductLinks($product_ids);
            $excluded_product_links = self::getScopeProductLinks($excluded_product_ids);
            $category_links = self::getScopeTermLinks($category_ids, 'product_cat');
            $excluded_category_links = self::getScopeTermLinks($excluded_category_ids, 'product_cat');
            $minimum_amount = isset($payload['minimum_amount']) && $payload['minimum_amount'] !== '' ? (float) $payload['minimum_amount'] : 0.0;
            $maximum_amount = isset($payload['maximum_amount']) && $payload['maximum_amount'] !== '' ? (float) $payload['maximum_amount'] : 0.0;
            $expiry_days = isset($payload['expiry_days']) ? (int) $payload['expiry_days'] : 0;
            $record_expires_at = $record ? self::resolveRecordExpiryDateTime($record, $payload) : null;
            $eligibility_rows = [];

            if (!empty($product_links)) {
                $eligibility_rows[] = self::formatScopeRow(
                    oyiso_t('Applies to Products'),
                    self::formatScopeCollection($product_links, '')
                );
            }

            if (!empty($category_links)) {
                $eligibility_rows[] = self::formatScopeRow(
                    oyiso_t('Applies to Categories'),
                    self::formatScopeCollection($category_links, '')
                );
            }

            if (!empty($excluded_category_links)) {
                $eligibility_rows[] = self::formatScopeRow(
                    oyiso_t('Excluded Categories'),
                    self::formatScopeCollection($excluded_category_links, '')
                );
            }

            if (!empty($excluded_product_links)) {
                $eligibility_rows[] = self::formatScopeRow(
                    oyiso_t('Excluded Products'),
                    self::formatScopeCollection($excluded_product_links, '')
                );
            }

            $conditions_rows = [];

            if ($minimum_amount > 0) {
                $conditions_rows[] = self::formatScopeRow(
                    oyiso_t('Minimum Spend'),
                    esc_html(self::formatScopeMoney($minimum_amount))
                );
            }

            if ($maximum_amount > 0) {
                $conditions_rows[] = self::formatScopeRow(
                    oyiso_t('Maximum Spend'),
                    esc_html(self::formatScopeMoney($maximum_amount))
                );
            }

            if (!empty($payload['free_shipping'])) {
                $conditions_rows[] = self::formatScopeRow(
                    oyiso_t('Free Shipping'),
                    esc_html(oyiso_t('Yes'))
                );
            }

            if (!empty($payload['individual_use'])) {
                $conditions_rows[] = self::formatScopeRow(
                    oyiso_t('Individual Use Only'),
                    esc_html(oyiso_t('Yes'))
                );
            }

            if (!empty($payload['exclude_sale_items'])) {
                $conditions_rows[] = self::formatScopeRow(
                    oyiso_t('Exclude Sale Items'),
                    esc_html(oyiso_t('Yes'))
                );
            }

            if ($expiry_days > 0) {
                $conditions_rows[] = self::formatScopeRow(
                    oyiso_t('Valid Until'),
                    esc_html(
                        $record_expires_at instanceof WC_DateTime
                            ? wp_date('Y-m-d H:i:s', $record_expires_at->getTimestamp(), wp_timezone())
                            : oyiso_t_sprintf('%s days after winning', (string) $expiry_days)
                    )
                );
            }

            $limit_rows = [
                self::formatScopeRow(
                    oyiso_t('Per-Coupon Total Usage Limit'),
                    esc_html('1')
                ),
                self::formatScopeRow(
                    oyiso_t('Per-Coupon Per-Customer Limit'),
                    esc_html('1')
                ),
            ];

            return implode('', [
                self::formatScopeSection(oyiso_t('Eligibility'), $eligibility_rows),
                self::formatScopeSection(oyiso_t('Conditions'), $conditions_rows),
                self::formatScopeSection(oyiso_t('Coupon Usage Limits'), $limit_rows),
            ]);
        }

        public static function formatLotteryRulesFromPayload(array $payload): string {
            $payload = self::sanitizeLotteryPayload($payload);
            $range_type = ($payload['range_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
            $prize_rules = is_array($payload['prize_rules'] ?? null) ? $payload['prize_rules'] : [];
            $total_probability = 0.0;
            $participation_rows = [
                self::formatScopeRow(
                    oyiso_t('Participants'),
                    esc_html(oyiso_t('Logged-in users only'))
                ),
                self::formatScopeRow(
                    oyiso_t('Per-Person Total Draws'),
                    esc_html(self::formatLotteryLimitValue((int) ($payload['total_limit'] ?? 0)))
                ),
                self::formatScopeRow(
                    oyiso_t('Per-Person Daily Draws'),
                    esc_html(self::formatLotteryLimitValue((int) ($payload['daily_limit'] ?? 0)))
                ),
                self::formatScopeRow(
                    oyiso_t('Prize Pool'),
                    esc_html(
                        ($payload['prize_pool_mode'] ?? 'unlimited') === 'limited'
                            ? (string) max(1, (int) ($payload['prize_pool_limit'] ?? 100))
                            : oyiso_t('Unlimited')
                    )
                ),
            ];

            if (!empty($payload['start_at'])) {
                $participation_rows[] = self::formatScopeRow(
                    oyiso_t('Start Time'),
                    esc_html(self::formatSiteDateTime((string) $payload['start_at']))
                );
            }

            if (!empty($payload['end_at'])) {
                $participation_rows[] = self::formatScopeRow(
                    oyiso_t('End Time'),
                    esc_html(self::formatSiteDateTime((string) $payload['end_at']))
                );
            }

            if (!empty($payload['enable_thanks']) && !empty($payload['stop_after_win_with_thanks'])) {
                $participation_rows[] = self::formatScopeRow(
                    oyiso_t('After Winning'),
                    esc_html(oyiso_t('No further draws allowed'))
                );
            }

            $prize_rows = [
                self::formatScopeRow(
                    oyiso_t('Coupon Type'),
                    esc_html($range_type === 'percent' ? oyiso_t('Percentage Discount') : oyiso_t('Fixed Amount Discount'))
                ),
                self::formatScopeRow(
                    oyiso_t('Validity Period'),
                    esc_html(
                        (int) ($payload['expiry_days'] ?? 0) > 0
                            ? oyiso_t_sprintf('%s days after winning', (string) (int) $payload['expiry_days'])
                            : oyiso_t('No Expiry Date')
                    )
                ),
            ];

            $prize_rule_rows = [];

            foreach ($prize_rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $probability = (float) ($rule['probability'] ?? 0);

                if ($probability <= 0) {
                    continue;
                }

                $total_probability += $probability;
                $prize_rule_rows[] = self::formatScopeRow(
                    self::formatLotteryRuleLabel($rule, $range_type),
                    esc_html((string) (int) round($probability) . '%')
                );
            }

            if (!empty($payload['enable_thanks']) && $total_probability < 100) {
                $prize_rule_rows[] = self::formatScopeRow(
                    oyiso_t('Thanks for participating'),
                    esc_html((string) max(0, (int) round(100 - $total_probability)) . '%')
                );
            }

            return implode('', [
                self::formatScopeSection(oyiso_t('Participation Rules'), $participation_rows),
                self::formatScopeSection(oyiso_t('Prize Settings'), $prize_rows),
                self::formatScopeSection(oyiso_t('Prize Rules'), $prize_rule_rows),
            ]);
        }

        private static function buildAdvisoryLockName(string $scope, array $parts = []): string {
            return 'oyiso_lottery_' . $scope . '_' . md5(wp_json_encode(array_values($parts)));
        }

        private static function acquireAdvisoryLock(string $lock_name, int $timeout = 5): bool {
            global $wpdb;

            $result = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT GET_LOCK(%s, %d)',
                    $lock_name,
                    max(0, $timeout)
                )
            );

            return (int) $result === 1;
        }

        private static function releaseAdvisoryLock(string $lock_name): void {
            global $wpdb;

            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT RELEASE_LOCK(%s)',
                    $lock_name
                )
            );
        }

        private static function findExistingCouponForRecord(int $record_id): ?array {
            if ($record_id <= 0) {
                return null;
            }

            $coupon_ids = get_posts([
                'post_type'              => 'shop_coupon',
                'post_status'            => ['publish', 'private', 'draft', 'pending', 'future'],
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'orderby'                => 'ID',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'suppress_filters'       => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => [
                    [
                        'key'     => '_oyiso_coupon_lottery_record_id',
                        'value'   => $record_id,
                        'compare' => '=',
                    ],
                ],
            ]);

            if (empty($coupon_ids) || !isset($coupon_ids[0])) {
                return null;
            }

            return self::normalizeCouponReference((int) $coupon_ids[0]);
        }

        private static function normalizeCouponReference(int $coupon_id): ?array {
            if ($coupon_id <= 0) {
                return null;
            }

            $coupon_post = get_post($coupon_id);

            if (!$coupon_post || $coupon_post->post_type !== 'shop_coupon') {
                return null;
            }

            $coupon_code = (string) get_post_field('post_title', $coupon_id);

            if ($coupon_code === '' && class_exists('WC_Coupon')) {
                $coupon = new WC_Coupon($coupon_id);
                $coupon_code = (string) $coupon->get_code();
            }

            return [
                'coupon_id'   => $coupon_id,
                'coupon_code' => $coupon_code,
            ];
        }

        private static function markRecordClaimed(int $record_id, int $coupon_id, string $coupon_code): void {
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
                    'id' => $record_id,
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
        }

        private static function currentUserCanManageCoupons(): bool {
            return current_user_can('manage_woocommerce')
                || current_user_can('edit_shop_coupons')
                || current_user_can('manage_options');
        }

        private static function formatLotteryLimitValue(int $limit): string {
            return $limit > 0 ? (string) $limit : oyiso_t('Unlimited');
        }

        private static function formatLotteryRuleLabel(array $rule, string $range_type): string {
            $mode = ($rule['mode'] ?? 'range') === 'single' ? 'single' : 'range';

            if ($mode === 'single') {
                return self::formatPrizeLabel(isset($rule['value']) ? (float) $rule['value'] : null, $range_type);
            }

            $start_value = isset($rule['start_value']) ? (float) $rule['start_value'] : 0.0;
            $end_value = isset($rule['end_value']) ? (float) $rule['end_value'] : 0.0;

            if ($start_value > $end_value) {
                [$start_value, $end_value] = [$end_value, $start_value];
            }

            return self::formatPrizeLabel($start_value, $range_type) . ' - ' . self::formatPrizeLabel($end_value, $range_type);
        }

        private static function formatScopeRow(string $label, string $value_html): string {
            return sprintf(
                '<div class="oyiso-scope-dialog__row"><div class="oyiso-scope-dialog__label">%1$s</div><div class="oyiso-scope-dialog__value">%2$s</div></div>',
                esc_html($label),
                $value_html
            );
        }

        private static function formatScopeSection(string $title, array $rows): string {
            $rows = array_filter($rows);

            if (empty($rows)) {
                return '';
            }

            return sprintf(
                '<section class="oyiso-scope-dialog__section"><h4 class="oyiso-scope-dialog__section-title">%1$s</h4><div class="oyiso-scope-dialog__section-card"><div class="oyiso-scope-dialog__section-body">%2$s</div></div></section>',
                esc_html($title),
                implode('', $rows)
            );
        }

        private static function formatScopeCollection(array $items, string $fallback): string {
            if (empty($items)) {
                $items = [esc_html($fallback)];
            }

            $entries = array_map(static function ($item) {
                return sprintf('<div class="oyiso-scope-dialog__list-item">%s</div>', $item);
            }, $items);

            return '<div class="oyiso-scope-dialog__list">' . implode('', $entries) . '</div>';
        }

        private static function getScopeProductLinks($product_ids): array {
            $links = [];
            $product_ids = self::sanitizeIdList($product_ids);

            foreach (array_filter(array_map('absint', $product_ids)) as $product_id) {
                $product = wc_get_product($product_id);

                if (!$product) {
                    continue;
                }

                $url = get_permalink($product_id);

                if ($url) {
                    $links[] = sprintf(
                        '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                        esc_url($url),
                        esc_html($product->get_name())
                    );
                } else {
                    $links[] = esc_html($product->get_name());
                }
            }

            return $links;
        }

        private static function getScopeTermLinks($term_ids, string $taxonomy): array {
            $links = [];
            $term_ids = self::sanitizeIdList($term_ids);

            if (!taxonomy_exists($taxonomy)) {
                return $links;
            }

            foreach (array_filter(array_map('absint', $term_ids)) as $term_id) {
                $term = get_term($term_id, $taxonomy);

                if (!$term || is_wp_error($term)) {
                    continue;
                }

                $url = get_term_link($term);

                if (!is_wp_error($url) && $url) {
                    $links[] = sprintf(
                        '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                        esc_url($url),
                        esc_html($term->name)
                    );
                } else {
                    $links[] = esc_html($term->name);
                }
            }

            return $links;
        }

        private static function formatScopeMoney(float $amount): string {
            return self::formatPlainPrice($amount);
        }

        private static function formatPlainPrice(float $amount): string {
            if (function_exists('wc_price')) {
                $formatted = html_entity_decode((string) wc_price($amount), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $formatted = wp_strip_all_tags($formatted);
                $formatted = str_replace("\xc2\xa0", ' ', $formatted);

                return trim(preg_replace('/\s+/u', ' ', $formatted) ?: $formatted);
            }

            return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        }

        private static function getTableName(): string {
            global $wpdb;

            return $wpdb->prefix . self::TABLE_SLUG;
        }
    }
}

Oyiso_Coupon_Lottery_Module::init();
