<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (class_exists('CSF')) {
    CSF::createSection($prefix, [
        'parent'   => 'notifications',
        'id'       => 'order-email-html',
        'title'    => '订单邮件归档',
        'icon'     => 'fas fa-file-code',
        'priority' => 20,
        'fields'   => [
            [
                'type'    => 'heading',
                'content' => '订单邮件归档',
            ],
            [
                'id'      => 'woo_new_order_email_html_archive',
                'type'    => 'switcher',
                'title'   => '保存新订单邮件HTML',
                'label'   => '开启后，将WooCommerce新订单通知邮件的最终HTML保存到站点私有目录',
                'default' => false,
            ],
            [
                'id'         => 'woo_new_order_email_image_render',
                'type'       => 'switcher',
                'title'      => '自动渲染订单邮件图片',
                'label'      => 'HTML保存成功后，自动调用渲染API生成长图并保存到同一目录',
                'default'    => false,
                'dependency' => ['woo_new_order_email_html_archive', '==', true],
            ],
            [
                'id'         => 'woo_new_order_email_render_api_key',
                'type'       => 'text',
                'title'      => '渲染API Key',
                'class'      => 'oyiso-secret-field',
                'attributes' => [
                    'type'         => 'password',
                    'autocomplete' => 'new-password',
                    'spellcheck'   => 'false',
                ],
                'dependency' => [
                    ['woo_new_order_email_html_archive', '==', true],
                    ['woo_new_order_email_image_render', '==', true],
                ],
            ],
            [
                'id'         => 'woo_new_order_email_image_format',
                'type'       => 'select',
                'title'      => '图片格式',
                'options'    => [
                    'webp' => 'WebP',
                    'png'  => 'PNG',
                    'jpeg' => 'JPEG',
                ],
                'attributes' => [
                    'style' => 'min-width:120px;',
                ],
                'default'    => 'webp',
                'dependency' => [
                    ['woo_new_order_email_html_archive', '==', true],
                    ['woo_new_order_email_image_render', '==', true],
                ],
            ],
        ],
    ]);
}

require_once __DIR__ . '/renderer.php';

if (!class_exists('Oyiso_New_Order_Email_Html_Archive', false)) {
    final class Oyiso_New_Order_Email_Html_Archive {
        private const OPTION_KEY = 'woo_new_order_email_html_archive';

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
            $options = get_option('oyiso', []);

            return is_array($options) && !empty($options[self::OPTION_KEY]);
        }

        public static function getStorageDirectory(): string {
            return trailingslashit(WP_CONTENT_DIR)
                . 'oyiso-private/order-email-html/'
                . self::getSiteDomain();
        }

        public static function getSiteDomain(): string {
            $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            $siteId = function_exists('get_current_blog_id')
                ? max(1, (int) get_current_blog_id())
                : 1;

            return self::sanitizeFilenamePart($host, 'site-' . $siteId);
        }

        public static function buildFilename(WC_Order $order): string {
            $orderNumber = ltrim((string) $order->get_order_number(), '#');
            $orderNumber = self::sanitizeFilenamePart($orderNumber, (string) $order->get_id());

            return sprintf(
                '%s_#%s_%s.html',
                self::getSiteDomain(),
                $orderNumber,
                self::getOrderCreatedTimestamp($order)
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
