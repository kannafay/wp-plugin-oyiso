<?php

defined('ABSPATH') || exit;

/**
 * 内容浏览量统计
 */
if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'parent'   => 'code-analytics',
        'id'       => 'content-view-metrics',
        'title'    => '内容浏览量',
        'icon'     => 'fas fa-chart-line',
        'priority' => 5,
        'fields'   => [
            [
                'type'    => 'heading',
                'content' => '内容浏览量统计',
            ],
            [
                'id'    => 'oyiso_content_view_metrics_options',
                'type'  => 'tabbed',
                'title' => '内容类型',
                'tabs'  => [
                    [
                        'title'  => '文章',
                        'icon'   => 'fas fa-thumbtack',
                        'fields' => [
                            [
                                'id'      => 'oyiso_content_view_metrics_post_enabled',
                                'type'    => 'switcher',
                                'title'   => '统计开关',
                                'label'   => '开启后记录文章浏览量。',
                                'default' => true,
                            ],
                            [
                                'id'      => 'oyiso_content_view_metrics_post_show_column',
                                'type'    => 'switcher',
                                'title'   => '显示开关',
                                'label'   => '在后台文章列表显示浏览量列，不影响浏览量统计。',
                                'default' => false,
                            ],
                        ],
                    ],
                    [
                        'title'  => '产品',
                        'icon'   => 'fas fa-box',
                        'fields' => [
                            [
                                'id'      => 'oyiso_content_view_metrics_product_enabled',
                                'type'    => 'switcher',
                                'title'   => '统计开关',
                                'label'   => '开启后记录产品浏览量。',
                                'default' => true,
                            ],
                            [
                                'id'      => 'oyiso_content_view_metrics_product_show_column',
                                'type'    => 'switcher',
                                'title'   => '显示开关',
                                'label'   => '在后台产品列表显示浏览量列，不影响浏览量统计。',
                                'default' => false,
                            ],
                        ],
                    ],
                    [
                        'title'  => '页面',
                        'icon'   => 'fas fa-file',
                        'fields' => [
                            [
                                'id'      => 'oyiso_content_view_metrics_page_enabled',
                                'type'    => 'switcher',
                                'title'   => '统计开关',
                                'label'   => '开启后记录页面浏览量。',
                                'default' => true,
                            ],
                            [
                                'id'      => 'oyiso_content_view_metrics_page_show_column',
                                'type'    => 'switcher',
                                'title'   => '显示开关',
                                'label'   => '在后台页面列表显示浏览量列，不影响浏览量统计。',
                                'default' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);
}

if (!class_exists('Oyiso_Content_View_Metrics')) {
    final class Oyiso_Content_View_Metrics {
        private const COLUMN_KEY = 'oyiso_view_count';
        private const META_KEY = '_oyiso_view_count';
        private const DAILY_META_KEY = '_oyiso_daily_view_counts';
        private const LEGACY_META_KEY = '_oyiso_click_count';
        private const LEGACY_OPTION_ENABLED = 'oyiso_content_view_metrics_enabled';
        private const LEGACY_OPTION_SHOW_COLUMN = 'oyiso_content_view_metrics_show_column';
        private const OPTION_GROUP = 'oyiso_content_view_metrics_options';
        private const POST_TYPE_OPTIONS = [
            'post' => [
                'tracking' => 'oyiso_content_view_metrics_post_enabled',
                'column' => 'oyiso_content_view_metrics_post_show_column',
            ],
            'product' => [
                'tracking' => 'oyiso_content_view_metrics_product_enabled',
                'column' => 'oyiso_content_view_metrics_product_show_column',
            ],
            'page' => [
                'tracking' => 'oyiso_content_view_metrics_page_enabled',
                'column' => 'oyiso_content_view_metrics_page_show_column',
            ],
        ];

        public static function init(): void {
            $tracked_post_types = self::getEnabledPostTypes('tracking');
            if ($tracked_post_types !== []) {
                add_action('template_redirect', [self::class, 'trackView']);
            }

            $visible_post_types = self::getEnabledPostTypes('column');
            if ($visible_post_types === []) {
                return;
            }

            add_action('admin_head-edit.php', [self::class, 'renderAdminStyles']);
            add_action('pre_get_posts', [self::class, 'applyColumnSorting']);
            add_filter('posts_clauses', [self::class, 'applyColumnSortingClauses'], 10, 2);

            foreach ($visible_post_types as $post_type) {
                add_filter("manage_{$post_type}_posts_columns", [self::class, 'addColumn'], 99);
                add_filter("manage_edit-{$post_type}_sortable_columns", [self::class, 'addSortableColumn']);
                add_action("manage_{$post_type}_posts_custom_column", [self::class, 'renderColumn'], 10, 2);
            }
        }

        public static function trackView(): void {
            if (
                is_admin()
                || is_preview()
                || is_feed()
                || wp_doing_ajax()
                || (function_exists('wp_is_json_request') && wp_is_json_request())
            ) {
                return;
            }

            $tracked_post_types = self::getEnabledPostTypes('tracking');
            if ($tracked_post_types === [] || !is_singular($tracked_post_types)) {
                return;
            }

            $post_id = (int) get_queried_object_id();
            if ($post_id <= 0 || get_post_status($post_id) !== 'publish') {
                return;
            }

            self::incrementViewCount($post_id);
        }

        public static function addColumn(array $columns): array {
            $columns[self::COLUMN_KEY] = '浏览量';
            return $columns;
        }

        public static function addSortableColumn(array $columns): array {
            $columns[self::COLUMN_KEY] = self::COLUMN_KEY;
            return $columns;
        }

        public static function applyColumnSorting(WP_Query $query): void {
            if (
                !is_admin()
                || !$query->is_main_query()
                || $query->get('orderby') !== self::COLUMN_KEY
            ) {
                return;
            }

            $post_type = $query->get('post_type');

            if (is_array($post_type)) {
                $is_supported = count(array_intersect($post_type, self::getEnabledPostTypes('column'))) > 0;
            } else {
                $is_supported = in_array((string) ($post_type ?: 'post'), self::getEnabledPostTypes('column'), true);
            }

            if (!$is_supported) {
                return;
            }

            $query->set('oyiso_view_count_sort', true);
            $query->set('orderby', 'none');
        }

        public static function applyColumnSortingClauses(array $clauses, WP_Query $query): array {
            if (!is_admin() || !$query->get('oyiso_view_count_sort')) {
                return $clauses;
            }

            global $wpdb;

            $alias = 'oyiso_view_count_meta';
            $join = " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ({$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = '" . esc_sql(self::META_KEY) . "')";

            if (strpos($clauses['join'], " {$alias} ") === false) {
                $clauses['join'] .= $join;
            }

            $order = strtoupper((string) $query->get('order')) === 'ASC' ? 'ASC' : 'DESC';
            $clauses['orderby'] = "CAST(COALESCE({$alias}.meta_value, '0') AS UNSIGNED) {$order}, {$wpdb->posts}.ID DESC";

            return $clauses;
        }

        public static function renderColumn(string $column, int $post_id): void {
            if ($column !== self::COLUMN_KEY) {
                return;
            }

            $metrics = self::getViewMetrics($post_id);
            $chart = self::buildSparklineChart($metrics['recent_days']);
            $total_display = self::formatCompactCount((int) $metrics['total']);

            echo '<span class="oyiso-view-metrics">';
            echo '<span class="oyiso-view-chart">';
            echo '<svg class="oyiso-view-sparkline" viewBox="0 0 86 16" role="img" aria-label="最近 7 天每日浏览量趋势">';
            echo '<defs><linearGradient id="oyiso-view-fill-' . esc_attr((string) $post_id) . '" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#e5702a" stop-opacity="0.2"/><stop offset="100%" stop-color="#e5702a" stop-opacity="0"/></linearGradient></defs>';
            echo '<path class="oyiso-view-sparkline__grid" d="M3 13H83"></path>';
            echo '<polygon class="oyiso-view-sparkline__area" points="' . esc_attr($chart['area']) . '" fill="url(#oyiso-view-fill-' . esc_attr((string) $post_id) . ')"></polygon>';
            echo '<polyline class="oyiso-view-sparkline__line" points="' . esc_attr($chart['line']) . '"></polyline>';
            foreach ($chart['points'] as $point) {
                echo '<circle class="oyiso-view-sparkline__dot" cx="' . esc_attr((string) $point['x']) . '" cy="' . esc_attr((string) $point['y']) . '" r="1.7"></circle>';
            }
            echo '</svg>';
            foreach ($chart['points'] as $point) {
                echo '<span class="oyiso-view-tooltip-point oyiso-view-tooltip-point--' . esc_attr($point['edge']) . '" style="left:' . esc_attr((string) $point['x_percent']) . '%;top:' . esc_attr((string) $point['y_percent']) . '%;">';
                echo '<span class="oyiso-view-tooltip">' . esc_html($point['date'] . ' · ' . number_format_i18n((int) $point['count'])) . '</span>';
                echo '</span>';
            }
            echo '</span>';
            echo '<span class="oyiso-view-total"><span>总</span><strong>' . esc_html($total_display) . '</strong></span>';
            echo '</span>';
        }

        public static function renderAdminStyles(): void {
            $screen = get_current_screen();
            if (!$screen || !in_array($screen->post_type, self::getEnabledPostTypes('column'), true)) {
                return;
            }
            ?>
            <style>
            .wp-list-table .column-<?php echo esc_attr(self::COLUMN_KEY); ?> {
                width: 104px;
                text-align: left;
            }
            .oyiso-view-metrics {
                position: relative;
                display: block;
                width: 68px;
                height: 29px;
                max-width: 100%;
                margin: 0;
                padding: 0;
                color: #50575e;
                font-size: 12px;
                line-height: 1;
                box-sizing: border-box;
            }
            .oyiso-view-total {
                position: absolute;
                left: 50%;
                bottom: 0;
                transform: translateX(-50%);
                z-index: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 2px;
                width: max-content;
                max-width: 64px;
                min-width: 0;
                height: 12px;
                padding: 0 4px;
                border: 1px solid #e2e4e7;
                border-radius: 999px;
                background: rgba(246, 247, 247, 0.94);
                color: #1d2327;
                overflow: hidden;
                white-space: nowrap;
                box-sizing: border-box;
            }
            .oyiso-view-total strong {
                font-size: 10px;
                font-weight: 600;
                line-height: 1;
            }
            .oyiso-view-total span {
                color: #646970;
                font-size: 8px;
                font-weight: 500;
                line-height: 1;
            }
            .oyiso-view-chart {
                position: relative;
                display: block;
                width: 68px;
                height: 16px;
            }
            .oyiso-view-sparkline {
                display: block;
                width: 68px;
                height: 16px;
                overflow: visible;
            }
            .oyiso-view-sparkline__grid {
                fill: none;
                stroke: #e2e4e7;
                stroke-width: 1;
            }
            .oyiso-view-sparkline__area {
                stroke: none;
            }
            .oyiso-view-sparkline__line {
                fill: none;
                stroke: #e5702a;
                stroke-linecap: round;
                stroke-linejoin: round;
                stroke-width: 1;
            }
            .oyiso-view-sparkline__dot {
                fill: #fff;
                stroke: #e5702a;
                stroke-width: 1;
            }
            .oyiso-view-tooltip-point {
                position: absolute;
                z-index: 2;
                width: 12px;
                height: 16px;
                transform: translate(-50%, -50%);
                cursor: default;
            }
            .oyiso-view-tooltip {
                position: absolute;
                left: 50%;
                bottom: calc(100% + 4px);
                z-index: 9999;
                display: none;
                padding: 3px 6px;
                border-radius: 4px;
                background: #1d2327;
                color: #fff;
                font-size: 11px;
                font-weight: 500;
                line-height: 1.2;
                white-space: nowrap;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.16);
                opacity: 0;
                pointer-events: none;
                transform: translate(-50%, 2px);
                transition: opacity .12s ease, transform .12s ease;
            }
            .oyiso-view-tooltip-point--start .oyiso-view-tooltip {
                left: 0;
                transform: translate(0, 2px);
            }
            .oyiso-view-tooltip-point--end .oyiso-view-tooltip {
                left: auto;
                right: 0;
                transform: translate(0, 2px);
            }
            .oyiso-view-tooltip::after {
                content: "";
                position: absolute;
                left: 50%;
                top: 100%;
                width: 0;
                height: 0;
                border: 4px solid transparent;
                border-top-color: #1d2327;
                transform: translateX(-50%);
            }
            .oyiso-view-tooltip-point--start .oyiso-view-tooltip::after {
                left: 6px;
                transform: none;
            }
            .oyiso-view-tooltip-point--end .oyiso-view-tooltip::after {
                left: auto;
                right: 6px;
                transform: none;
            }
            .oyiso-view-tooltip-point:hover .oyiso-view-tooltip {
                display: block;
                opacity: 1;
                transform: translate(-50%, 0);
            }
            .oyiso-view-tooltip-point--start:hover .oyiso-view-tooltip,
            .oyiso-view-tooltip-point--end:hover .oyiso-view-tooltip {
                transform: translate(0, 0);
            }
            </style>
            <?php
        }

        private static function getEnabledPostTypes(string $setting): array {
            $options = get_option('oyiso', []);
            $options = is_array($options) ? $options : [];
            $content_options = $options[self::OPTION_GROUP] ?? [];
            $content_options = is_array($content_options) ? $content_options : [];
            $legacy_option = $setting === 'tracking'
                ? self::LEGACY_OPTION_ENABLED
                : self::LEGACY_OPTION_SHOW_COLUMN;
            $default = $setting === 'tracking';
            $post_types = [];

            foreach (self::POST_TYPE_OPTIONS as $post_type => $option_keys) {
                $option_key = $option_keys[$setting] ?? '';
                if ($option_key === '') {
                    continue;
                }

                if (array_key_exists($option_key, $content_options)) {
                    $enabled = !empty($content_options[$option_key]);
                } elseif (array_key_exists($legacy_option, $options)) {
                    $enabled = !empty($options[$legacy_option]);
                } else {
                    $enabled = $default;
                }

                if ($enabled) {
                    $post_types[] = $post_type;
                }
            }

            return $post_types;
        }

        private static function getViewCount(int $post_id): int {
            $views = (int) get_post_meta($post_id, self::META_KEY, true);

            if ($views > 0) {
                return $views;
            }

            return max(0, (int) get_post_meta($post_id, self::LEGACY_META_KEY, true));
        }

        private static function getViewMetrics(int $post_id): array {
            $daily_counts = self::getDailyViewCounts($post_id);
            $now = (int) current_time('timestamp');

            return [
                'total' => self::getViewCount($post_id),
                'recent_days' => self::getRecentDailyCounts($daily_counts, $now, 7),
            ];
        }

        private static function getRecentDailyCounts(array $daily_counts, int $timestamp, int $days): array {
            $counts = [];

            for ($index = $days - 1; $index >= 0; $index--) {
                $date = date('Y-m-d', strtotime('-' . $index . ' days', $timestamp));
                $counts[] = [
                    'date' => $date,
                    'count' => (int) ($daily_counts[$date] ?? 0),
                ];
            }

            return $counts;
        }

        private static function buildSparklineChart(array $days): array {
            $width = 86;
            $height = 16;
            $padding_x = 3;
            $padding_y = 2;
            $baseline = $height - 3;
            $max = 0;
            foreach ($days as $day) {
                $max = max($max, (int) ($day['count'] ?? 0));
            }

            $last_index = max(1, count($days) - 1);
            $points = [];

            foreach (array_values($days) as $index => $day) {
                $date = (string) ($day['date'] ?? '');
                $count = (int) ($day['count'] ?? 0);
                $x = $padding_x + (($width - ($padding_x * 2)) * ($index / $last_index));
                $y = $max > 0
                    ? $baseline - (($baseline - $padding_y) * ($count / $max))
                    : $baseline;

                $points[] = [
                    'date' => $date,
                    'count' => $count,
                    'edge' => $index === 0 ? 'start' : ($index === $last_index ? 'end' : 'middle'),
                    'x' => round($x, 2),
                    'y' => round($y, 2),
                    'x_percent' => round(($x / $width) * 100, 2),
                    'y_percent' => round(($y / $height) * 100, 2),
                ];
            }

            $line = implode(' ', array_map(static function (array $point): string {
                return $point['x'] . ',' . $point['y'];
            }, $points));

            $area = $line . ' ' . ($width - $padding_x) . ',' . $baseline . ' ' . $padding_x . ',' . $baseline;

            return [
                'line' => $line,
                'area' => $area,
                'points' => $points,
            ];
        }

        private static function formatCompactCount(int $count): string {
            if ($count >= 10000) {
                $value = round($count / 10000, $count >= 100000 ? 1 : 2);
                return rtrim(rtrim((string) $value, '0'), '.') . '万';
            }

            return number_format_i18n($count);
        }

        private static function incrementViewCount(int $post_id): void {
            $views = self::getViewCount($post_id) + 1;
            update_post_meta($post_id, self::META_KEY, $views);

            $today = date('Y-m-d', (int) current_time('timestamp'));
            $daily_counts = self::getDailyViewCounts($post_id);
            $daily_counts[$today] = (int) ($daily_counts[$today] ?? 0) + 1;

            self::pruneDailyViewCounts($daily_counts);
            update_post_meta($post_id, self::DAILY_META_KEY, $daily_counts);
        }

        private static function getDailyViewCounts(int $post_id): array {
            $daily_counts = get_post_meta($post_id, self::DAILY_META_KEY, true);

            if (!is_array($daily_counts)) {
                return [];
            }

            $normalized = [];
            foreach ($daily_counts as $date => $count) {
                if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    continue;
                }

                $normalized[$date] = max(0, (int) $count);
            }

            return $normalized;
        }

        private static function pruneDailyViewCounts(array &$daily_counts): void {
            $threshold = strtotime('-370 days', (int) current_time('timestamp'));

            foreach (array_keys($daily_counts) as $date) {
                $timestamp = strtotime($date);
                if (!$timestamp || $timestamp < $threshold) {
                    unset($daily_counts[$date]);
                }
            }
        }
    }
}

Oyiso_Content_View_Metrics::init();
