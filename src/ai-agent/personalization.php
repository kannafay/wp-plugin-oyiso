<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Agent_Personalization
{
    /** @template TKey of array-key
     * @param array<TKey, mixed> $options
     * @return array<TKey|string, mixed>
     */
    public static function mergePreferences(array $options): array
    {
        if (!array_key_exists('agent_custom_instructions', $options)) return $options;
        $parts = [];
        foreach (['agent_instructions', 'agent_custom_instructions'] as $field) {
            $value = $options[$field] ?? '';
            if (is_string($value) && trim($value) !== '') $parts[] = $value;
        }
        $options['agent_instructions'] = implode("\n\n", $parts);
        unset($options['agent_custom_instructions']);
        return $options;
    }

    public static function siteLanguage(): string
    {
        $language = get_option('WPLANG', '');
        return is_string($language) && $language !== '' ? $language : 'en_US';
    }

    public static function language(): string
    {
        $language = Oyiso_Agent_Config::text('language');
        return $language === 'site-default' ? self::siteLanguage() : $language;
    }

    /** @return array<string, string|array<string, string>> */
    public static function languageOptions(): array
    {
        require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        $languages = ['en_US' => 'English (United States)'];
        foreach (wp_get_available_translations() as $code => $translation) {
            if (is_string($code)) {
                $languages[$code] = $translation['native_name'];
            }
        }
        $site = self::siteLanguage();
        foreach (array_merge(get_available_languages(), [$site]) as $code) {
            $languages[$code] ??= $code;
        }
        // Retain an existing locale when the translation catalogue is temporarily unavailable.
        foreach (['language', 'reply_language'] as $field) {
            $saved = Oyiso_Agent_Config::text($field);
            if ($saved !== '' && preg_match('/^[a-z]{2,3}(?:_[A-Za-z0-9]+)*$/D', $saved)) {
                $languages[$saved] ??= $saved;
            }
        }
        $recommended = '跟随站点：' . $languages[$site] . '（' . $site . '）';
        natcasesort($languages);
        foreach ($languages as $code => $name) {
            $languages[$code] = $name . '（' . $code . '）';
        }
        return ['' => '自动判断（不指定语言）', 'site-default' => $recommended, '全部语言' => $languages];
    }

    /** @return array<string, string|array<string, string>> */
    public static function replyLanguageOptions(): array
    {
        return ['' => '自动（跟随提问语言）', '全部语言' => self::languageOptions()['全部语言']];
    }

    /** @return array<string, string> */
    public static function replyDetailOptions(): array
    {
        return ['brief' => '简洁', 'standard' => '标准', 'detailed' => '详细'];
    }

    public static function replyDetail(): string
    {
        $value = Oyiso_Agent_Config::text('reply_detail');
        return isset(self::replyDetailOptions()[$value]) ? $value : 'brief';
    }

    public static function validateLanguage(mixed $value): ?string
    {
        if ($value === '' || $value === 'site-default') {
            return null;
        }
        $languages = self::languageOptions()['全部语言'];
        return is_string($value) && is_array($languages) && isset($languages[$value]) ? null : '请从列表中选择内容语言。';
    }

    public static function validateReplyLanguage(mixed $value): ?string
    {
        if ($value === '') return null;
        $languages = self::replyLanguageOptions()['全部语言'];
        return is_string($value) && is_array($languages) && isset($languages[$value]) ? null : '请从列表中选择回复语言。';
    }

    public static function validateReplyDetail(mixed $value): ?string
    {
        return $value === '' || (is_string($value) && isset(self::replyDetailOptions()[$value])) ? null : '请从列表中选择回复详略。';
    }
}
