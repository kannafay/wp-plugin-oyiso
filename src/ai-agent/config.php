<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Agent_Config
{
    private const SECRETS = 'oyiso_agent_secrets';

    /** @return array<string, mixed> */
    public static function options(): array
    {
        $options = get_option('oyiso', []);
        return is_array($options) ? $options : [];
    }

    public static function text(string $name, string $default = ''): string
    {
        $value = self::options()['agent_' . $name] ?? $default;
        return is_string($value) || is_int($value) ? trim((string) $value) : $default;
    }

    public static function enabled(): bool
    {
        return !empty(self::options()['agent_enabled']);
    }

    public static function supported(): bool
    {
        return function_exists('wp_ai_client_prompt') && function_exists('wp_register_ability');
    }

    public static function maxSteps(): int
    {
        return max(2, min(15, (int) self::text('max_steps', '8')));
    }

    /** @return array<string, string> */
    public static function providers(): array
    {
        $providers = ['' => '自动选择'];
        if (!self::supported()) {
            return $providers;
        }
        try {
            Oyiso_Agent_Connectors::register();
            wp_ai_client_prompt();
            foreach (\WordPress\AiClient\AiClient::defaultRegistry()->getRegisteredProviderIds() as $id) {
                $preset = Oyiso_Agent_Connectors::preset($id);
                $providers[$id] = $preset !== '' ? Oyiso_Agent_Connectors::presets()[$preset]['name'] . '（Oyiso 预设）' : $id;
            }
        } catch (Throwable) {
            // The settings remain usable before a provider is installed.
        }
        return $providers;
    }

    public static function hasSecret(string $name): bool
    {
        return self::secret($name) !== '';
    }

    /** @return list<string> */
    public static function secretNames(): array
    {
        return ['deepseek_key', 'gemini_key', 'agnes_key'];
    }

    /** @return array<string, bool> */
    public static function secretStatus(): array
    {
        $status = [];
        foreach (self::secretNames() as $name) {
            $status[$name] = self::hasSecret($name);
        }
        return $status;
    }

    public static function credential(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    public static function secret(string $name): string
    {
        $stored = get_option(self::SECRETS, []);
        $encoded = is_array($stored) && is_string($stored[$name] ?? null) ? $stored[$name] : '';
        $data = base64_decode($encoded, true);
        if (!is_string($data) || strlen($data) < 29 || !function_exists('openssl_decrypt')) {
            return '';
        }
        $plain = openssl_decrypt(substr($data, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16), $name);
        return is_string($plain) ? $plain : '';
    }

    /** Keep credentials out of the CSF option, HTML and settings exports.
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function saveOptions(array $options): array
    {
        $stored = get_option(self::SECRETS, []);
        $secrets = is_array($stored) ? $stored : [];
        // Keep retired credentials encrypted for rollback, and discard obsolete plaintext inputs.
        foreach (['search_key', 'keywords_password'] as $name) {
            unset($options['agent_' . $name], $options['agent_' . $name . '_clear']);
        }
        foreach (self::secretNames() as $name) {
            $field = 'agent_' . $name;
            $value = isset($options[$field]) && is_string($options[$field]) ? trim($options[$field]) : '';
            if (!empty($options[$field . '_clear'])) {
                unset($secrets[$name]);
            } elseif ($value !== '' && (!str_ends_with($name, '_key') || Oyiso_Agent_Connectors::validateKey($value) === null) && function_exists('openssl_encrypt')) {
                $iv = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt($value, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, $name);
                if (is_string($cipher)) {
                    $secrets[$name] = base64_encode($iv . $tag . $cipher);
                }
            }
            $options[$field] = '';
            $options[$field . '_clear'] = false;
        }
        update_option(self::SECRETS, $secrets, false);
        return $options;
    }

    public static function error(string $message): string
    {
        foreach (self::secretNames() as $name) {
            $secret = self::secret($name);
            if ($secret !== '') {
                $message = str_replace($secret, '[已隐藏]', $message);
            }
        }
        $message = (string) preg_replace('/Bearer\s+\S+|(?:api[_-]?key|token|password)=\S+/i', '[已隐藏]', $message);
        return mb_substr(wp_strip_all_tags($message), 0, 500);
    }

    private static function key(): string
    {
        return hash('sha256', wp_salt('auth') . '|oyiso-agent', true);
    }
}
