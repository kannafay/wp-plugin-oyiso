<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Oyiso_Agent_Personalization
{
    public static function siteLanguage(): string
    {
        $language = get_option('WPLANG', '');
        return is_string($language) && $language !== '' ? $language : 'en_US';
    }

    public static function language(): string
    {
        return Oyiso_Agent_Config::text('language') ?: self::siteLanguage();
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
        $saved = Oyiso_Agent_Config::text('language');
        if ($saved !== '' && preg_match('/^[a-z]{2,3}(?:_[A-Za-z0-9]+)*$/D', $saved)) {
            $languages[$saved] ??= $saved;
        }
        $recommended = '推荐：' . $languages[$site] . '（' . $site . '）';
        natcasesort($languages);
        foreach ($languages as $code => $name) {
            $languages[$code] = $name . '（' . $code . '）';
        }
        return ['' => $recommended, '全部语言' => $languages];
    }

    public static function validateLanguage(mixed $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $languages = self::languageOptions()['全部语言'];
        return is_string($value) && is_array($languages) && isset($languages[$value]) ? null : '请从列表中选择内容语言。';
    }
}
