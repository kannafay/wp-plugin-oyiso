<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(404);
    exit;
}

use PHPUnit\Framework\TestCase;

final class PersonalizationTest extends TestCase
{
    private mixed $options;
    private mixed $siteLanguage;
    private Closure $translations;
    private Closure $installed;

    protected function setUp(): void
    {
        $this->options = get_option('oyiso', null);
        $this->siteLanguage = get_option('WPLANG', null);
        update_option('oyiso', [], false);
        update_option('WPLANG', 'pl_PL');
        $this->translations = static fn(): array => [
            'pl_PL' => ['native_name' => 'Polski'],
            'zh_CN' => ['native_name' => '简体中文'],
            'cs_CZ' => ['native_name' => 'Čeština'],
        ];
        $this->installed = static fn(): array => ['pl_PL', 'zh_CN'];
        add_filter('pre_site_transient_available_translations', $this->translations);
        add_filter('get_available_languages', $this->installed);
        wp_set_current_user(OYISO_AGENT_TEST_ADMIN);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_site_transient_available_translations', $this->translations);
        remove_filter('get_available_languages', $this->installed);
        foreach (['oyiso' => $this->options, 'WPLANG' => $this->siteLanguage] as $name => $value) {
            if ($value === null) delete_option($name);
            else update_option($name, $value, false);
        }
    }

    public function testRecommendationUsesTheSiteLanguageEvenWhenTheAdministratorUsesAnotherLanguage(): void
    {
        $user = wp_get_current_user(); $previous = $user->locale;
        $user->locale = 'zh_CN';
        try {
            self::assertSame('zh_CN', get_user_locale());
            self::assertSame('pl_PL', Oyiso_Agent_Personalization::siteLanguage());
            $options = Oyiso_Agent_Personalization::languageOptions();
            self::assertSame('', array_key_first($options));
            self::assertSame('自动判断（不指定语言）', $options['']);
            self::assertSame('推荐：Polski（pl_PL）', $options['site-default']);
            self::assertSame('Čeština（cs_CZ）', $options['全部语言']['cs_CZ']);
            self::assertArrayHasKey('en_US', $options['全部语言']);
            self::assertSame('pl_PL', get_option('WPLANG'));
        } finally { $user->locale = $previous; }
    }

    public function testAutomaticLanguageStaysUnsetWhileSiteRecommendationAndExplicitLanguageResolveIndependently(): void
    {
        self::assertSame('', Oyiso_Agent_Personalization::language());
        update_option('oyiso', ['agent_language' => 'site-default'], false);
        self::assertSame('pl_PL', Oyiso_Agent_Personalization::language());
        update_option('WPLANG', '');
        self::assertSame('en_US', Oyiso_Agent_Personalization::language());
        self::assertStringContainsString('English (United States)', Oyiso_Agent_Personalization::languageOptions()['site-default']);
        update_option('oyiso', ['agent_language' => 'cs_CZ'], false);
        update_option('WPLANG', 'zh_CN');
        self::assertSame('cs_CZ', Oyiso_Agent_Personalization::language());
        update_option('oyiso', ['agent_language' => ''], false);
        self::assertSame('', Oyiso_Agent_Personalization::language());
        update_option('WPLANG', 'pl_PL');
        self::assertSame('', Oyiso_Agent_Personalization::language());
    }

    public function testLanguageFieldOffersAutomaticAndRecommendedOptionsBeforeAllLanguages(): void
    {
        $section = array_values(array_filter(CSF::$args['sections']['oyiso'], static fn(array $section): bool => ($section['id'] ?? '') === 'agent-personalization'))[0];
        $field = array_values(array_filter($section['fields'], static fn(array $field): bool => ($field['id'] ?? '') === 'agent_language'))[0];
        ob_start(); CSF::field($field, 'cs_CZ', 'oyiso'); $html = ob_get_clean();
        $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        self::assertSame('select', $field['type']);
        self::assertSame('自动判断（不指定语言）', $xpath->evaluate('string(//select/option[1])'));
        self::assertSame('', $xpath->evaluate('string(//select/option[1]/@value)'));
        self::assertSame('推荐：Polski（pl_PL）', $xpath->evaluate('string(//select/option[2])'));
        self::assertSame('site-default', $xpath->evaluate('string(//select/option[2]/@value)'));
        self::assertSame('oyiso[agent_language]', $xpath->evaluate('string(//select/@name)'));
        self::assertSame('cs_CZ', $xpath->evaluate('string(//option[@selected]/@value)'));
        self::assertSame(1, $xpath->query('//optgroup[@label="全部语言"]/option[@value="cs_CZ"]')->length);
        self::assertNull(Oyiso_Agent_Personalization::validateLanguage(''));
        self::assertNull(Oyiso_Agent_Personalization::validateLanguage('site-default'));
        self::assertNull(Oyiso_Agent_Personalization::validateLanguage('cs_CZ'));
        self::assertNotNull(Oyiso_Agent_Personalization::validateLanguage('misspelled-language'));
        self::assertNotNull(Oyiso_Agent_Personalization::validateLanguage(['pl_PL']));
    }

    public function testUnavailableTranslationCataloguePreservesInstalledAndPreviouslySavedLocales(): void
    {
        $unavailable = static fn(): array => [];
        add_filter('pre_site_transient_available_translations', $unavailable, 20);
        update_option('oyiso', ['agent_language' => 'de_AT'], false);
        try {
            $options = Oyiso_Agent_Personalization::languageOptions();
            self::assertArrayHasKey('pl_PL', $options['全部语言']);
            self::assertArrayHasKey('zh_CN', $options['全部语言']);
            self::assertArrayHasKey('en_US', $options['全部语言']);
            self::assertArrayHasKey('de_AT', $options['全部语言']);
            self::assertNull(Oyiso_Agent_Personalization::validateLanguage('de_AT'));
            self::assertSame('de_AT', Oyiso_Agent_Personalization::language());
        } finally { remove_filter('pre_site_transient_available_translations', $unavailable, 20); }
    }

    public function testModelReceivesCustomInstructionsAlongsideWritingRequirementsAndResolvedLanguage(): void
    {
        update_option('oyiso', ['agent_language' => '', 'agent_instructions' => '保留商品参数。', 'agent_custom_instructions' => '先给结论，使用简短列表。'], false);
        $instructions = Oyiso_Agent_Model::instructions();
        self::assertStringContainsString('未指定时，根据任务要求和原文判断。', $instructions);
        self::assertStringNotContainsString('默认内容语言：', $instructions);
        self::assertStringContainsString('管理员写作要求：保留商品参数。', $instructions);
        self::assertStringContainsString('管理员自定义指令：先给结论，使用简短列表。', $instructions);
        self::assertStringContainsString('只通过 prepare-product-update 准备修改', $instructions);
        update_option('oyiso', ['agent_custom_instructions' => ''], false);
        self::assertStringNotContainsString('管理员自定义指令：', Oyiso_Agent_Model::instructions());
        update_option('oyiso', ['agent_language' => 'pl_PL'], false);
        self::assertStringContainsString('默认内容语言：pl_PL。当前对话中明确指定的语言优先于此默认设置。', Oyiso_Agent_Model::instructions());
    }
}
