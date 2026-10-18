<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('CSF')) {
    return;
}

CSF::createSection('oyiso', ['id' => 'ai-agent', 'title' => 'AI Agent', 'icon' => 'fas fa-robot', 'priority' => 45]);

CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-chat', 'title' => '对话助手', 'icon' => 'far fa-comments', 'priority' => 10,
    'fields' => [['type' => 'callback', 'class' => 'oyiso-agent-panel', 'function' => [Oyiso_Agent_Admin::class, 'chat']]],
]);

CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-model', 'title' => '模型配置', 'icon' => 'fas fa-sliders-h', 'priority' => 20,
    'fields' => [
        ['type' => 'heading', 'content' => '模型配置'],
        ['id' => 'agent_enabled', 'type' => 'switcher', 'title' => '启用 AI Agent', 'label' => '允许管理员通过对话查询数据并准备商品修改。', 'default' => false],
        ['type' => 'callback', 'title' => '模型连接', 'function' => [Oyiso_Agent_Admin::class, 'connection']],
        ['id' => 'agent_provider', 'type' => 'select', 'title' => '模型服务', 'options' => 'Oyiso_Agent_Config::providers', 'default' => '', 'desc' => '显示 WordPress 中已注册的模型服务；自动选择会使用可用且支持工具调用的模型。'],
        ['id' => 'agent_model', 'type' => 'text', 'title' => '模型偏好', 'desc' => '填写服务提供的模型 ID，留空自动选择。指定模型不可用时，WordPress 可能选择其他兼容模型。'],
        ['id' => 'agent_language', 'type' => 'text', 'title' => '内容语言', 'default' => get_locale(), 'desc' => '商品文案默认使用此语言，也可以在对话中指定。'],
        ['id' => 'agent_max_steps', 'type' => 'number', 'title' => '每次任务最多轮次', 'default' => '8', 'attributes' => ['min' => '2', 'max' => '15', 'step' => '1'], 'desc' => '范围 2–15。一次模型响应计为一轮，每轮最多调用 4 个工具。'],
        ['id' => 'agent_instructions', 'type' => 'textarea', 'title' => '写作要求', 'attributes' => ['rows' => '5', 'maxlength' => '4000'], 'desc' => '填写品牌语气、内容结构、需保留的信息等长期要求。'],
    ],
]);

$oyisoAgentSecret = static function (string $name, string $title): array {
    $configured = Oyiso_Agent_Config::hasSecret($name);
    return [
        ['id' => 'agent_' . $name, 'type' => 'text', 'title' => $title, 'sanitize' => 'Oyiso_Agent_Config::credential', 'attributes' => ['type' => 'password', 'autocomplete' => 'new-password', 'spellcheck' => 'false', 'placeholder' => $configured ? '已配置，留空保持' : '尚未配置'], 'default' => '', 'desc' => '保存后加密存储，页面和配置导出不包含密钥。'],
        ['id' => 'agent_' . $name . '_clear', 'type' => 'checkbox', 'title' => '清除' . $title, 'label' => '勾选并保存配置后清除已保存的密钥。', 'default' => false],
    ];
};
CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-research', 'title' => '联网检索', 'icon' => 'fas fa-globe', 'priority' => 30,
    'fields' => array_merge(
        [
            ['type' => 'heading', 'content' => '网页搜索'],
            ['id' => 'agent_search_provider', 'type' => 'select', 'title' => '搜索服务', 'options' => ['' => '未配置', 'tavily' => 'Tavily'], 'default' => '', 'desc' => '配置后可搜索产品资料与竞品。即使未配置搜索，也可以在对话中提供公开网页地址供读取。'],
        ],
        $oyisoAgentSecret('search_key', '搜索 API Key'),
        [
            ['type' => 'heading', 'content' => '关键词指标'],
            ['id' => 'agent_keywords_provider', 'type' => 'select', 'title' => '关键词服务', 'options' => ['' => '未配置', 'dataforseo' => 'DataForSEO / Google Ads'], 'default' => '', 'desc' => '提供搜索量估算和广告竞争程度，使用服务额度。未配置时仅提供关键词内容建议。'],
            ['id' => 'agent_keywords_login', 'type' => 'text', 'title' => 'DataForSEO 登录名', 'attributes' => ['autocomplete' => 'off']],
        ],
        $oyisoAgentSecret('keywords_password', 'DataForSEO API 密码'),
        [
            ['id' => 'agent_keywords_location', 'type' => 'text', 'title' => '地区代码', 'attributes' => ['inputmode' => 'numeric'], 'desc' => '填写 DataForSEO 的 location_code。<a href="https://docs.dataforseo.com/v3/keywords_data/google_ads/locations/" target="_blank" rel="noopener noreferrer">查询地区代码</a>'],
            ['id' => 'agent_keywords_language', 'type' => 'text', 'title' => '指标语言代码', 'default' => 'en', 'desc' => '填写数据服务支持的 language_code，例如 en、pl、cs 或 hu。指标会按所配地区和语言查询。'],
        ]
    ),
]);
unset($oyisoAgentSecret);

CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-records', 'title' => '执行记录', 'icon' => 'fas fa-history', 'priority' => 40,
    'fields' => [['type' => 'callback', 'class' => 'oyiso-agent-panel', 'function' => [Oyiso_Agent_Admin::class, 'history']]],
]);
