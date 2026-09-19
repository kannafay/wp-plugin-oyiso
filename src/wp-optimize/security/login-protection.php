<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Login_Protection
{
    private const PREFIX = '_oyiso_login_limit_';
    private const CLEANUP_HOOK = 'oyiso_cleanup_login_limits';
    private const UNLOCK_ACTION = 'oyiso_unlock_login_limits';

    private readonly int $maxAttempts;
    private readonly int $windowSeconds;
    private readonly int $lockSeconds;

    /** @param array<string, mixed> $options */
    public function __construct(private readonly wpdb $db, array $options)
    {
        $this->maxAttempts = self::sanitizeAttempts($options['opt-login-max-attempts'] ?? null);
        $this->windowSeconds = self::sanitizeWindow($options['opt-login-window-minutes'] ?? null) * 60;
        $this->lockSeconds = self::sanitizeLock($options['opt-login-lock-minutes'] ?? null) * 60;
    }

    /** @param array<string, mixed> $options */
    public static function register(array $options): void
    {
        global $wpdb;
        $protection = new self($wpdb, $options);
        add_action(self::CLEANUP_HOOK, [$protection, 'cleanup']);
        add_action('wp_ajax_' . self::UNLOCK_ACTION, [$protection, 'unlock']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);

        if (!empty($options['opt-limit-login'])) {
            add_filter('wp_authenticate_user', [$protection, 'guardPasswordCheck'], PHP_INT_MAX);
            add_filter('authenticate', [$protection, 'authenticate'], PHP_INT_MAX, 3);
        }
    }

    public static function sanitizeAttempts(mixed $value): int
    {
        return self::boundedNumber($value, 5, 3, 20);
    }

    public static function sanitizeWindow(mixed $value): int
    {
        return self::boundedNumber($value, 15, 1, 60);
    }

    public static function sanitizeLock(mixed $value): int
    {
        return self::boundedNumber($value, 15, 1, 1440);
    }

    private static function boundedNumber(mixed $value, int $default, int $min, int $max): int
    {
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    private function clientKey(): ?string
    {
        // API credentials use separate authentication. Never trust arbitrary proxy headers.
        if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
            return null;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        // Normalize IPv4-mapped IPv6 addresses to the same identity as IPv4.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        return self::PREFIX . hash_hmac('sha256', $packed, wp_salt('auth'));
    }

    private function read(string $key): ?string
    {
        // Private non-autoloaded records bypass the options cache so concurrent requests see fresh state.
        $value = $this->db->get_var($this->db->prepare(
            'SELECT option_value FROM %i WHERE option_name = %s',
            $this->db->options,
            $key
        ));
        return is_string($value) ? $value : null;
    }

    /** @param literal-string $query */
    private function execute(string $query, string|int ...$args): int|bool
    {
        $prepared = $this->db->prepare($query, ...$args);
        return $prepared === null ? false : $this->db->query($prepared);
    }

    /** @return array{int, int, int} Expiry, failure count, locked-until timestamp. */
    private function state(?string $value, int $now): array
    {
        if ($value !== null && preg_match('/^(\d+):(\d+):(\d+)$/D', $value)) {
            [$expires, $count, $lockedUntil] = array_map('intval', explode(':', $value));
            if ($expires > $now) {
                return [$expires, $count, $lockedUntil];
            }
        }
        return [$now + $this->windowSeconds, 0, 0];
    }

    private function limitedError(): WP_Error
    {
        return new WP_Error('oyiso_login_limited', oyiso_t('Login temporarily restricted. Please try again later.'));
    }

    public function guardPasswordCheck(WP_User|WP_Error $user): WP_User|WP_Error
    {
        $key = $this->clientKey();
        if ($key !== null) {
            $previous = $this->read($key);
            if ($this->db->last_error !== '' || $this->state($previous, time())[2] > time()) {
                return $this->limitedError();
            }
        }
        return $user;
    }

    public function authenticate(
        WP_User|WP_Error|null|false $user,
        string $username,
        #[SensitiveParameter] string $password
    ): WP_User|WP_Error|null|false {
        $key = $this->clientKey();
        if ($key === null || $username === '' || $password === '') {
            return $user;
        }
        $failed = $user instanceof WP_Error && array_intersect(
            ['invalid_username', 'invalid_email', 'incorrect_password'],
            $user->get_error_codes()
        ) !== [];

        // Compare-and-swap prevents lost failures and success resets bypassing a concurrent lockout.
        for ($retry = 0; $retry < 10; $retry++) {
            $now = time();
            $previous = $this->read($key);
            if ($this->db->last_error !== '') {
                return $this->limitedError();
            }
            [$expires, $count, $lockedUntil] = $this->state($previous, $now);
            if ($lockedUntil > $now) {
                return $this->limitedError();
            }
            if (!$failed && !($user instanceof WP_User)) {
                return $user;
            }
            if ($user instanceof WP_User) {
                if ($previous === null || $this->execute(
                    'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
                    $this->db->options,
                    $key,
                    $previous
                ) === 1) {
                    return $user;
                }
                continue;
            }

            $count++;
            if ($count >= $this->maxAttempts) {
                $expires = $lockedUntil = $now + $this->lockSeconds;
            }
            $next = "$expires:$count:$lockedUntil";
            $updated = $previous === null
                ? $this->execute(
                    "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                    $this->db->options,
                    $key,
                    $next
                )
                : $this->execute(
                    'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
                    $this->db->options,
                    $next,
                    $key,
                    $previous
                );
            if ($updated === 1) {
                $this->scheduleCleanup();
                return $lockedUntil > $now ? $this->limitedError() : $user;
            }
            if ($updated === false) {
                break;
            }
        }
        return $this->limitedError();
    }

    private function scheduleCleanup(): void
    {
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, self::CLEANUP_HOOK);
        }
    }

    public function cleanup(): void
    {
        $pattern = $this->db->esc_like(self::PREFIX) . '%';
        $this->execute(
            "DELETE FROM %i WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d",
            $this->db->options,
            $pattern,
            time()
        );
        if ($this->db->get_var($this->db->prepare(
            'SELECT option_id FROM %i WHERE option_name LIKE %s LIMIT 1',
            $this->db->options,
            $pattern
        )) !== null) {
            $this->scheduleCleanup();
        }
    }

    public function unlock(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '无权解除登录限制。'], 403);
        }
        check_ajax_referer(self::UNLOCK_ACTION, 'nonce');
        $deleted = $this->execute(
            'DELETE FROM %i WHERE option_name LIKE %s',
            $this->db->options,
            $this->db->esc_like(self::PREFIX) . '%'
        );
        if ($deleted === false) {
            wp_send_json_error(['message' => '解除失败，请稍后重试。'], 500);
        }
        wp_send_json_success(['message' => '已解除本站全部登录限制，并重置失败次数。']);
    }

    public static function renderUnlockButton(): void
    {
        echo '<button type="button" class="button button-secondary" id="oyiso-unlock-logins">解除全部登录限制</button>';
        echo ' <span id="oyiso-unlock-logins-status" role="status" aria-live="polite"></span>';
        echo '<p class="description">立即解除本站所有 IP 的限制，并重置失败次数。已登录用户可在此操作。</p>';
    }

    public static function enqueueAssets(string $hook): void
    {
        if (!oyiso_is_settings_page_hook($hook) || !current_user_can('manage_options')) {
            return;
        }
        wp_enqueue_script('oyiso-login-protection', plugins_url('login-protection.js', __FILE__), ['jquery'], (string) filemtime(__DIR__ . '/login-protection.js'), true);
        wp_localize_script('oyiso-login-protection', 'oyisoLoginProtection', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::UNLOCK_ACTION),
        ]);
    }
}
