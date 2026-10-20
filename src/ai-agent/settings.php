<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!class_exists('CSF')) {
    return;
}

CSF::createSection('oyiso', ['id' => 'ai-agent', 'title' => 'AI Agent', 'icon' => 'fas fa-robot', 'priority' => 45]);
CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-model', 'title' => '模型配置', 'icon' => 'fas fa-sliders-h', 'priority' => 20,
    'fields' => [
        ['type' => 'heading', 'content' => '模型配置'],
        ['id' => 'agent_enabled', 'type' => 'switcher', 'title' => '启用 AI Agent', 'class' => 'oyiso-agent-model-field', 'label' => '允许管理员通过对话查询数据并准备商品修改。', 'default' => false, 'desc' => '启用后保存配置并刷新页面，即可加载助手。'],
        ['type' => 'callback', 'class' => 'oyiso-agent-model-providers', 'function' => [Oyiso_Agent_Model_Settings::class, 'render']],
    ],
]);
CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-personalization', 'title' => '个性化', 'icon' => 'fas fa-user-cog', 'priority' => 21,
    'fields' => [
        ['type' => 'heading', 'content' => '个性化'],
        ['id' => 'agent_language', 'type' => 'select', 'title' => '内容语言', 'class' => 'oyiso-agent-model-field', 'default' => '', 'options' => Oyiso_Agent_Personalization::class . '::languageOptions', 'sanitize' => [Oyiso_Agent_Config::class, 'credential'], 'validate' => [Oyiso_Agent_Personalization::class, 'validateLanguage'], 'desc' => '可不指定，由 AI 根据任务和原文判断。也可选择站点推荐或固定语言；对话中明确指定的语言优先。'],
        ['id' => 'agent_instructions', 'type' => 'textarea', 'title' => '写作要求', 'class' => 'oyiso-agent-model-field', 'attributes' => ['rows' => '4', 'maxlength' => '4000'], 'desc' => '品牌语气、内容结构和需保留的信息等长期要求。'],
        ['id' => 'agent_custom_instructions', 'type' => 'textarea', 'title' => '自定义指令', 'class' => 'oyiso-agent-model-field', 'attributes' => ['rows' => '6', 'maxlength' => '4000'], 'desc' => '长期生效的助手偏好，例如回复方式、输出格式和工作习惯。'],
    ],
]);
CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-advanced', 'title' => '高级设置', 'icon' => 'fas fa-cog', 'priority' => 22,
    'fields' => [
        ['type' => 'heading', 'content' => '高级设置'],
        ['id' => 'agent_advanced', 'type' => 'fieldset', 'class' => 'oyiso-agent-model-advanced', 'default' => ['max_steps' => Oyiso_Agent_Config::text('max_steps', '8'), 'model' => Oyiso_Agent_Config::text('model')], 'fields' => [
            ['id' => 'max_steps', 'type' => 'number', 'title' => '每次任务最多轮次', 'default' => '8', 'attributes' => ['min' => '2', 'max' => '15', 'step' => '1'], 'desc' => '范围 2–15。一次模型响应计为一轮，每轮最多调用 4 个工具。'],
            ['id' => 'model', 'type' => 'text', 'title' => 'WordPress 模型 ID', 'desc' => 'WordPress 连接器的模型偏好；使用已添加的模型时不生效。'],
        ]],
    ],
]);

CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-mcp', 'title' => 'MCP 工具', 'icon' => 'fas fa-plug', 'priority' => 24,
    'fields' => [
        ['type' => 'heading', 'content' => 'MCP 工具'],
        ['id' => 'agent_mcp_namespaces', 'type' => 'checkbox', 'title' => '启用插件工具', 'default' => ['woodmart'], 'options' => Oyiso_Agent_MCP::class . '::namespaceOptions', 'desc' => 'WoodMart 默认启用。选择允许助手使用的插件；沿用当前账号权限，查询直接执行，修改须在对话中确认。保存配置后生效。'],
        ['type' => 'callback', 'class' => 'oyiso-agent-panel', 'function' => [Oyiso_Agent_MCP::class, 'render']],
    ],
]);
CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-tools', 'title' => '站内工具', 'icon' => 'fas fa-tools', 'priority' => 25,
    'fields' => [['type' => 'callback', 'class' => 'oyiso-agent-panel', 'function' => [Oyiso_Agent_Admin::class, 'tools']]],
]);
CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-records', 'title' => '对话记录', 'icon' => 'fas fa-history', 'priority' => 40,
    'fields' => [['type' => 'callback', 'class' => 'oyiso-agent-panel', 'function' => [Oyiso_Agent_Admin::class, 'history']]],
]);
