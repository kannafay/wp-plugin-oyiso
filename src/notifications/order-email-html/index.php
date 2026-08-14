<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

require_once dirname(__DIR__) . '/wecom/index.php';

if (!function_exists('oyiso_is_wc_order_screenshot_forwarding_enabled')) {
    function oyiso_is_wc_order_screenshot_forwarding_enabled(): bool {
        $options = get_option('oyiso', []);

        if (!is_array($options) || empty($options['woo_new_order_email_html_archive'])) {
            return false;
        }

        $channels = $options['woo_new_order_email_forward_options'] ?? [];

        return is_array($channels) && !empty($channels['wecom_order_image_forward']);
    }
}

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'parent'   => 'notifications',
        'id'       => 'order-email-html',
        'title'    => 'WC订单截图转发',
        'icon'     => 'fas fa-camera',
        'priority' => 20,
        'fields'   => [
            [
                'type'    => 'heading',
                'content' => 'WC订单截图转发',
            ],
            [
                'id'      => 'woo_new_order_email_html_archive',
                'type'    => 'switcher',
                'title'   => '启用订单截图转发',
                'label'   => '新订单生成后，自动生成截图并转发到已启用的渠道',
                'default' => false,
            ],
            [
                'id'         => 'woo_new_order_email_render_api_key',
                'type'       => 'text',
                'title'      => '渲染服务 Key',
                'class'      => 'oyiso-secret-field',
                'attributes' => [
                    'type'         => 'password',
                    'autocomplete' => 'new-password',
                    'spellcheck'   => 'false',
                ],
                'after'      => '<div class="oyiso-render-service-checks"><button type="button" class="button button-secondary" id="oyiso-check-render-service">检测服务可用性</button><button type="button" class="button button-secondary" id="oyiso-check-render-api-key">检测密钥正确性</button><span id="oyiso-render-service-check-status" role="status" aria-live="polite"></span></div>',
                'dependency' => ['woo_new_order_email_html_archive', '==', true],
            ],
            [
                'id'         => 'woo_new_order_email_image_format',
                'type'       => 'select',
                'title'      => '图片格式',
                'options'    => [
                    'png'  => 'PNG',
                    'jpeg' => 'JPEG',
                ],
                'attributes' => [
                    'style' => 'min-width:120px;',
                ],
                'default'    => 'png',
                'dependency' => ['woo_new_order_email_html_archive', '==', true],
            ],
            [
                'id'         => 'woo_new_order_email_forward_options',
                'type'       => 'tabbed',
                'title'      => '转发渠道',
                'dependency' => ['woo_new_order_email_html_archive', '==', true],
                'tabs'       => [
                    [
                        'title'  => '企业微信',
                        'icon'   => 'fab fa-weixin',
                        'fields' => [
                            [
                                'id'      => 'wecom_order_image_forward',
                                'type'    => 'switcher',
                                'title'   => '启用',
                                'label'   => '截图生成成功后，自动发送到消息推送关联的企业微信群',
                                'default' => false,
                            ],
                            [
                                'id'         => 'wecom_webhook_key',
                                'type'       => 'text',
                                'title'      => 'Webhook Key',
                                'class'      => 'oyiso-secret-field',
                                'attributes' => [
                                    'type'         => 'password',
                                    'autocomplete' => 'new-password',
                                    'spellcheck'   => 'false',
                                ],
                                'sanitize'   => 'oyiso_sanitize_wecom_webhook_key',
                                'desc'       => '只填写 Webhook 地址中 key= 后面的内容。',
                                'dependency' => ['wecom_order_image_forward', '==', true],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id'         => 'woo_new_order_email_file_retention',
                'type'       => 'select',
                'title'      => '文件保留时间',
                'class'      => 'oyiso-order-email-retention-field',
                'options'    => [
                    '24'  => '24小时',
                    '72'  => '3天',
                    '168' => '7天',
                    '720' => '30天',
                    '0'   => '永久保留',
                ],
                'attributes' => [
                    'style' => 'min-width:120px;',
                ],
                'desc'       => '每小时检查一次，自动删除过期的订单归档文件。',
                'default'    => '24',
                'dependency' => ['woo_new_order_email_html_archive', '==', true],
            ],
            [
                'type'    => 'content',
                'title'   => '历史文件',
                'content' => '<button type="button" class="button button-secondary" id="oyiso-order-email-file-manager">文件管理</button><p class="description">查看并清理当前站点此前生成的 HTML 和截图。</p>',
            ],
        ],
    ]);
}

require_once __DIR__ . '/renderer.php';
require_once __DIR__ . '/cleaner.php';
require_once __DIR__ . '/archive-manager.php';

if (!class_exists('Oyiso_New_Order_Email_Html_Archive', false)) {
    final class Oyiso_New_Order_Email_Html_Archive {
        private const LOG_SOURCE = 'oyiso-order-email-html';

        public static function register(): void {
            if (!class_exists('WooCommerce')) {
                return;
            }

            add_filter(
                'woocommerce_mail_callback_params',
                [self::class, 'archiveMailCallbackParams'],
                10,
                2
            );
        }

        /**
         * Archive the final, CSS-inlined WooCommerce new-order email HTML.
         *
         * @param array<int, mixed> $params Parameters passed to wp_mail().
         * @param object            $email  WooCommerce email instance.
         * @return array<int, mixed>
         */
        public static function archiveMailCallbackParams(array $params, object $email): array {
            if (!self::isEnabled()) {
                return $params;
            }

            if (!($email instanceof WC_Email) || 'new_order' !== (string) $email->id) {
                return $params;
            }

            if (!($email->object instanceof WC_Order)) {
                return $params;
            }

            $html = $params[2] ?? '';

            if (!is_string($html) || '' === trim($html)) {
                return $params;
            }

            try {
                $path = self::archive($email->object, $html);

                /**
                 * Fires after a new-order email HTML file has been archived.
                 *
                 * @param string   $path  Absolute HTML file path.
                 * @param WC_Order $order WooCommerce order instance.
                 * @param WC_Email $email WooCommerce email instance.
                 */
                do_action('oyiso_new_order_email_html_archived', $path, $email->object, $email);
            } catch (Throwable $exception) {
                self::logError(
                    sprintf(
                        'Unable to archive new-order email HTML for order %d: %s',
                        $email->object->get_id(),
                        $exception->getMessage()
                    )
                );
            }

            return $params;
        }

        public static function isEnabled(): bool {
            return oyiso_is_wc_order_screenshot_forwarding_enabled();
        }

        public static function getStorageDirectory(): string {
            return trailingslashit(WP_CONTENT_DIR)
                . 'oyiso-private/order-email-html/'
                . 'site-'
                . self::getSiteId();
        }

        public static function getSiteId(): int {
            return function_exists('get_current_blog_id')
                ? max(1, (int) get_current_blog_id())
                : 1;
        }

        public static function getSiteDomain(): string {
            $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

            return self::sanitizeFilenamePart($host, 'site-' . self::getSiteId());
        }

        public static function buildFilename(WC_Order $order): string {
            $orderNumber = ltrim((string) $order->get_order_number(), '#');
            $orderNumber = self::sanitizeFilenamePart($orderNumber, (string) $order->get_id());
            $salt        = strtolower(wp_generate_password(6, false, false));

            return sprintf(
                '%s_#%s_%s-%s.html',
                self::getSiteDomain(),
                $orderNumber,
                self::getOrderCreatedTimestamp($order),
                $salt
            );
        }

        public static function getOrderCreatedTimestamp(WC_Order $order): string {
            $createdAt = $order->get_date_created();

            if ($createdAt instanceof WC_DateTime) {
                return $createdAt->date_i18n('Ymd-His');
            }

            return current_time('Ymd-His');
        }

        public static function archive(WC_Order $order, string $html): string {
            $directory = self::ensureStorageDirectory();
            $path      = trailingslashit($directory) . self::buildFilename($order);
            $bytes     = file_put_contents($path, $html, LOCK_EX);

            if (false === $bytes || strlen($html) !== $bytes) {
                throw new RuntimeException('无法完整写入HTML文件，请检查站点存储权限。');
            }

            return $path;
        }

        private static function ensureStorageDirectory(): string {
            $directory = self::getStorageDirectory();

            if (!is_dir($directory) && !wp_mkdir_p($directory)) {
                throw new RuntimeException('无法创建HTML归档目录，请检查站点写入权限。');
            }

            if (!is_writable($directory)) {
                throw new RuntimeException('HTML归档目录不可写。');
            }

            self::ensureProtectionFile(
                trailingslashit($directory) . 'index.php',
                "<?php\nhttp_response_code(403);\nexit;\n"
            );
            self::ensureProtectionFile(
                trailingslashit($directory) . '.htaccess',
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
            );
            self::ensureProtectionFile(
                trailingslashit($directory) . 'web.config',
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n"
            );

            return $directory;
        }

        private static function ensureProtectionFile(string $path, string $contents): void {
            if (is_file($path)) {
                return;
            }

            if (false === file_put_contents($path, $contents, LOCK_EX)) {
                throw new RuntimeException('无法创建HTML归档目录保护文件。');
            }
        }

        private static function sanitizeFilenamePart(string $value, string $fallback): string {
            $value = strtolower(trim($value));
            $value = (string) preg_replace('/[^a-z0-9._-]+/i', '-', $value);
            $value = trim($value, '.-_');

            return '' !== $value ? $value : $fallback;
        }

        private static function logError(string $message): void {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error($message, ['source' => self::LOG_SOURCE]);
                return;
            }

            error_log('[Oyiso Order Email HTML] ' . $message);
        }
    }
}

add_action('plugins_loaded', [Oyiso_New_Order_Email_Html_Archive::class, 'register'], 20);
