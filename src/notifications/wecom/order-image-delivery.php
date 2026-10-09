<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * @phpstan-type DeliveryArgs array{int, int, string, string, string, int}
 */
final class Oyiso_WeCom_Order_Image_Delivery {
    private const HOOK = 'oyiso_send_wecom_order_image';
    private const GROUP = 'oyiso-order-images';
    private const RETRY_DELAYS = [60, 180, 600];

    public static function register(): void {
        add_action('oyiso_new_order_email_image_rendered', [self::class, 'enqueue'], 10, 4);
        add_action(self::HOOK, [self::class, 'run'], 10, 6);
    }

    public static function enqueue(string $imagePath, string $htmlPath, int $orderId, bool $force = false): void {
        if ($force) {
            Oyiso_WeCom_Order_Image_Forwarder::forward($imagePath, $htmlPath, $orderId, true);
            return;
        }
        if (!oyiso_is_wc_order_screenshot_forwarding_enabled()) {
            return;
        }

        $path = realpath($imagePath);
        $storage = realpath(Oyiso_New_Order_Email_Html_Archive::getStorageDirectory());
        if ($orderId <= 0 || false === $path || false === $storage
            || !str_starts_with(wp_normalize_path($path), trailingslashit(wp_normalize_path($storage)))
            || !is_readable($path)) {
            self::log('error', sprintf('订单 %d 的截图发送任务未创建：图片路径无效。', $orderId));
            return;
        }
        $hash = md5_file($path);
        if (false === $hash) {
            self::log('error', sprintf('订单 %d 的截图发送任务未创建：无法读取图片。', $orderId));
            return;
        }

        foreach (oyiso_get_enabled_wecom_webhook_keys() as $key) {
            // Never put webhook credentials or absolute paths into queue arguments.
            $args = [get_current_blog_id(), $orderId, basename($path), $hash, Oyiso_WeCom_Order_Image_Forwarder::getChannelId($key), 1];
            Oyiso_WeCom_Order_Image_Forwarder::recordDeliveryState($orderId, $args[2], $hash, $args[4], 'queued');
            if (!self::schedule($args, 0)) {
                self::log('error', sprintf('订单 %d 的企业微信发送任务保存失败，改为立即发送。', $orderId));
                Oyiso_WeCom_Order_Image_Forwarder::forward($path, $htmlPath, $orderId, false, $args[4], $hash);
            }
        }
    }

    public static function run(int $siteId, int $orderId, string $filename, string $hash, string $channelId, int $attempt): void {
        if ($siteId <= 0 || $orderId <= 0 || $attempt < 1 || $attempt > 4
            || basename($filename) !== $filename || str_contains($filename, '\\')
            || !in_array(strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)), ['png', 'jpeg', 'jpg'], true)
            || 1 !== preg_match('/^[a-f0-9]{32}$/', $hash) || 1 !== preg_match('/^[a-f0-9]{64}$/', $channelId)) {
            self::log('error', '企业微信截图发送任务参数无效，已取消。');
            return;
        }

        $switched = $siteId !== get_current_blog_id();
        if ($switched && !is_multisite()) {
            self::log('error', sprintf('订单 %d 的截图发送任务不属于当前站点，已取消。', $orderId));
            return;
        }
        if ($switched) {
            switch_to_blog($siteId);
        }

        try {
            if (!oyiso_is_wc_order_screenshot_forwarding_enabled()
                || !in_array($channelId, array_map([Oyiso_WeCom_Order_Image_Forwarder::class, 'getChannelId'], oyiso_get_enabled_wecom_webhook_keys()), true)) {
                return;
            }

            $next = [$siteId, $orderId, $filename, $hash, $channelId, $attempt + 1];
            // Save recovery before HTTP: a killed PHP worker cannot schedule its own retry.
            $saved = $attempt < 4 && self::schedule($next, self::RETRY_DELAYS[$attempt - 1]);
            Oyiso_WeCom_Order_Image_Forwarder::forward(
                trailingslashit(Oyiso_New_Order_Email_Html_Archive::getStorageDirectory()) . $filename,
                '',
                $orderId,
                false,
                $channelId,
                $hash
            );
            $result = Oyiso_WeCom_Order_Image_Forwarder::getLastResult();
            if (null !== $result && $result['failed'] > 0 && $result['retryable']) {
                if ($saved) {
                    Oyiso_WeCom_Order_Image_Forwarder::recordDeliveryState($orderId, $filename, $hash, $channelId, 'retrying', implode(' ', $result['errors']));
                }
                self::log('error', sprintf('订单 %d 的企业微信截图第 %d 次发送失败；%s', $orderId, $attempt,
                    $saved ? '已保留自动补发任务。' : '自动补发次数已耗尽或任务保存失败，请手动重发。'));
            } elseif ($saved) {
                self::cancel($next);
            }
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    /** @param DeliveryArgs $args */
    private static function schedule(array $args, int $delay): bool {
        if (function_exists('as_schedule_single_action') && did_action('action_scheduler_init') > 0) {
            try {
                if (as_has_scheduled_action(self::HOOK, $args, self::GROUP)
                    || as_schedule_single_action(time() + $delay, self::HOOK, $args, self::GROUP, true) > 0
                    || as_has_scheduled_action(self::HOOK, $args, self::GROUP)) {
                    return true;
                }
            } catch (Throwable $exception) {
                self::log('error', '企业微信任务队列不可用：' . $exception->getMessage());
            }
        }
        if (false !== wp_next_scheduled(self::HOOK, $args)) {
            return true;
        }
        $scheduled = wp_schedule_single_event(time() + $delay, self::HOOK, $args, true);
        if (is_wp_error($scheduled)) {
            self::log('error', '企业微信补发任务保存失败：' . $scheduled->get_error_message());
        }
        return true === $scheduled;
    }

    /** @param DeliveryArgs $args */
    private static function cancel(array $args): void {
        if (function_exists('as_unschedule_all_actions') && did_action('action_scheduler_init') > 0) {
            as_unschedule_all_actions(self::HOOK, $args, self::GROUP);
        }
        wp_clear_scheduled_hook(self::HOOK, $args);
    }

    private static function log(string $level, string $message): void {
        wc_get_logger()->log($level, $message, ['source' => 'oyiso-wecom']);
    }
}
