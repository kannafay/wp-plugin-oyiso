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

$oyisoAgentSecret = static function (string $name, string $title): array {
    $configured = Oyiso_Agent_Config::hasSecret($name);
    return [
        ['id' => 'agent_' . $name, 'type' => 'text', 'title' => $title, 'sanitize' => 'Oyiso_Agent_Config::credential', 'attributes' => ['type' => 'password', 'autocomplete' => 'new-password', 'spellcheck' => 'false', 'maxlength' => '4096', 'placeholder' => $configured ? '已配置，留空保持' : '尚未配置'], 'default' => '', 'desc' => '保存后加密存储，页面和配置导出不包含密钥。'],
        ['id' => 'agent_' . $name . '_clear', 'type' => 'checkbox', 'title' => '清除' . $title, 'label' => '勾选并保存配置后清除已保存的密钥。', 'default' => false],
    ];
};
$oyisoAgentPresetFields = [];
foreach (Oyiso_Agent_Connectors::presets() as $oyisoAgentPresetId => $oyisoAgentPreset) {
    $oyisoAgentFields = array_merge(
        [
            ['type' => 'subheading', 'content' => $oyisoAgentPreset['name'] . ' 连接'],
            ['id' => 'agent_' . $oyisoAgentPresetId . '_url', 'type' => 'text', 'title' => 'API 地址', 'default' => $oyisoAgentPreset['url'], 'validate' => [Oyiso_Agent_Connectors::class, 'validateUrl'], 'attributes' => ['type' => 'url', 'spellcheck' => 'false', 'maxlength' => '1000'], 'desc' => '填写 Base URL，系统会追加 /chat/completions。留空使用预设地址。'],
            ['id' => 'agent_' . $oyisoAgentPresetId . '_model', 'type' => 'text', 'title' => '模型 ID', 'default' => $oyisoAgentPreset['model'], 'attributes' => ['spellcheck' => 'false', 'maxlength' => '200'], 'desc' => '使用此模型执行 Agent 任务，须支持工具调用。<a href="' . esc_url($oyisoAgentPreset['docs']) . '" target="_blank" rel="noopener noreferrer">接口文档</a>'],
        ],
        $oyisoAgentSecret($oyisoAgentPresetId . '_key', $oyisoAgentPreset['name'] . ' API Key'),
        [['type' => 'callback', 'title' => '连接测试', 'function' => static fn() => Oyiso_Agent_Admin::connectionTest($oyisoAgentPresetId)]]
    );
    foreach ($oyisoAgentFields as $oyisoAgentField) {
        $oyisoAgentField['dependency'] = ['agent_provider', '==', 'oyiso-' . $oyisoAgentPresetId];
        if (!empty($oyisoAgentField['id'])) {
            $oyisoAgentField['attributes']['data-agent-connection'] = $oyisoAgentPresetId;
        }
        if (($oyisoAgentField['id'] ?? '') === 'agent_' . $oyisoAgentPresetId . '_key') {
            $oyisoAgentField['validate'] = [Oyiso_Agent_Connectors::class, 'validateKey'];
        }
        $oyisoAgentPresetFields[] = $oyisoAgentField;
    }
}
CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-model', 'title' => '模型配置', 'icon' => 'fas fa-sliders-h', 'priority' => 20,
    'fields' => array_merge([
        ['type' => 'heading', 'content' => '模型配置'],
        ['id' => 'agent_enabled', 'type' => 'switcher', 'title' => '启用 AI Agent', 'label' => '允许管理员通过对话查询数据并准备商品修改。', 'default' => false],
        ['type' => 'callback', 'title' => '模型连接', 'function' => [Oyiso_Agent_Admin::class, 'connection']],
        ['id' => 'agent_provider', 'type' => 'select', 'title' => '模型服务', 'options' => 'Oyiso_Agent_Config::providers', 'default' => '', 'desc' => '选择 Oyiso 预设可在本页配置；自动选择会从已配置的服务中选择支持工具调用的模型。'],
    ], $oyisoAgentPresetFields, [
        ['id' => 'agent_model', 'type' => 'text', 'title' => 'WordPress 模型偏好', 'dependency' => [['agent_provider', '!=', 'oyiso-deepseek'], ['agent_provider', '!=', 'oyiso-gemini'], ['agent_provider', '!=', 'oyiso-agnes']], 'desc' => '使用其他 WordPress 连接器时，可填写模型 ID；留空自动选择。指定模型不可用时，WordPress 可能选择其他兼容模型。'],
        ['id' => 'agent_language', 'type' => 'text', 'title' => '内容语言', 'default' => get_locale(), 'desc' => '商品文案默认使用此语言，也可以在对话中指定。'],
        ['id' => 'agent_max_steps', 'type' => 'number', 'title' => '每次任务最多轮次', 'default' => '8', 'attributes' => ['min' => '2', 'max' => '15', 'step' => '1'], 'desc' => '范围 2–15。一次模型响应计为一轮，每轮最多调用 4 个工具。'],
        ['id' => 'agent_instructions', 'type' => 'textarea', 'title' => '写作要求', 'attributes' => ['rows' => '5', 'maxlength' => '4000'], 'desc' => '填写品牌语气、内容结构、需保留的信息等长期要求。'],
    ]),
]);
unset($oyisoAgentPresetFields, $oyisoAgentPresetId, $oyisoAgentPreset, $oyisoAgentFields, $oyisoAgentField);
unset($oyisoAgentSecret);

CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-records', 'title' => '执行记录', 'icon' => 'fas fa-history', 'priority' => 40,
    'fields' => [['type' => 'callback', 'class' => 'oyiso-agent-panel', 'function' => [Oyiso_Agent_Admin::class, 'history']]],
]);
