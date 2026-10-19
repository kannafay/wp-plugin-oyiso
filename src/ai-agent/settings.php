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
        ['type' => 'subheading', 'content' => '基本设置'],
        ['id' => 'agent_enabled', 'type' => 'switcher', 'title' => '启用 AI Agent', 'class' => 'oyiso-agent-model-field', 'label' => '允许管理员通过对话查询数据并准备商品修改。', 'default' => false, 'desc' => '启用后保存配置并刷新页面，即可加载助手。'],
        ['id' => 'agent_selection', 'type' => 'callback', 'title' => '默认模型', 'class' => 'oyiso-agent-model-field', 'sanitize' => 'Oyiso_Agent_Config::credential', 'validate' => [Oyiso_Agent_Connections::class, 'validateSelection'], 'function' => [Oyiso_Agent_Model_Settings::class, 'selection']],
        ['type' => 'callback', 'class' => 'oyiso-agent-model-providers', 'function' => [Oyiso_Agent_Model_Settings::class, 'render']],
        ['type' => 'subheading', 'content' => '生成偏好'],
        ['id' => 'agent_language', 'type' => 'text', 'title' => '内容语言', 'class' => 'oyiso-agent-model-field', 'default' => get_locale(), 'desc' => '商品文案默认使用此语言，也可以在对话中指定。'],
        ['id' => 'agent_instructions', 'type' => 'textarea', 'title' => '写作要求', 'class' => 'oyiso-agent-model-field', 'attributes' => ['rows' => '4', 'maxlength' => '4000'], 'desc' => '品牌语气、内容结构和需保留的信息等长期要求。'],
        ['id' => 'agent_advanced', 'type' => 'accordion', 'class' => 'oyiso-agent-model-advanced', 'default' => ['max_steps' => Oyiso_Agent_Config::text('max_steps', '8'), 'model' => Oyiso_Agent_Config::text('model')], 'accordions' => [
            ['title' => '高级设置', 'fields' => [
                ['id' => 'max_steps', 'type' => 'number', 'title' => '每次任务最多轮次', 'default' => '8', 'attributes' => ['min' => '2', 'max' => '15', 'step' => '1'], 'desc' => '范围 2–15。一次模型响应计为一轮，每轮最多调用 4 个工具。'],
                ['id' => 'model', 'type' => 'text', 'title' => 'WordPress 模型 ID', 'desc' => '仅在默认模型使用 WordPress 来源时生效；留空由 WordPress 选择。'],
            ]],
        ]],
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
