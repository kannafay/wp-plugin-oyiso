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
        ['id' => 'agent_enabled', 'type' => 'switcher', 'title' => '启用 AI Agent', 'class' => 'oyiso-agent-model-field', 'label' => '允许管理员通过对话查询站内数据。', 'default' => false, 'desc' => '默认只读。写入权限需在工具权限中开启并保存，执行时仍需在对话中确认。启用后保存配置并刷新页面，即可加载助手。'],
        ['type' => 'callback', 'class' => 'oyiso-agent-model-providers', 'function' => [Oyiso_Agent_Model_Settings::class, 'render']],
        ['id' => 'agent_advanced', 'type' => 'fieldset', 'class' => 'oyiso-agent-model-advanced', 'default' => ['max_steps' => Oyiso_Agent_Config::text('max_steps', '8'), 'model' => Oyiso_Agent_Config::text('model')], 'fields' => [
            ['id' => 'max_steps', 'type' => 'number', 'title' => '每次任务最多轮次', 'default' => '8', 'attributes' => ['min' => '2', 'max' => '15', 'step' => '1'], 'desc' => '范围 2–15。一次模型响应计为一轮，每轮最多调用 4 个工具。'],
            ['id' => 'model', 'type' => 'text', 'title' => 'WordPress 模型 ID', 'desc' => 'WordPress 连接器的模型偏好；使用已添加的模型时不生效。'],
        ]],
    ],
]);
CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-personalization', 'title' => '个性化', 'icon' => 'fas fa-user-cog', 'priority' => 21,
    'fields' => [
        ['type' => 'heading', 'content' => '个性化'],
        ['id' => 'agent_language', 'type' => 'select', 'title' => '内容语言', 'class' => 'oyiso-agent-model-field', 'default' => '', 'options' => Oyiso_Agent_Personalization::class . '::languageOptions', 'sanitize' => [Oyiso_Agent_Config::class, 'credential'], 'validate' => [Oyiso_Agent_Personalization::class, 'validateLanguage'], 'desc' => '用于生成商品、页面等文案。可自动判断、跟随站点或指定语言；当前对话的明确要求优先。'],
        ['id' => 'agent_reply_language', 'type' => 'select', 'title' => '回复语言', 'class' => 'oyiso-agent-model-field', 'default' => '', 'options' => Oyiso_Agent_Personalization::class . '::replyLanguageOptions', 'sanitize' => [Oyiso_Agent_Config::class, 'credential'], 'validate' => [Oyiso_Agent_Personalization::class, 'validateReplyLanguage'], 'desc' => '用于聊天回复，默认跟随提问语言。可用中文沟通，同时生成其他语言的文案。'],
        ['id' => 'agent_reply_detail', 'type' => 'select', 'title' => '回复详略', 'class' => 'oyiso-agent-model-field', 'default' => 'brief', 'options' => Oyiso_Agent_Personalization::class . '::replyDetailOptions', 'sanitize' => [Oyiso_Agent_Config::class, 'credential'], 'validate' => [Oyiso_Agent_Personalization::class, 'validateReplyDetail'], 'desc' => '控制聊天说明的详细程度，默认简洁；所需文案、代码等内容仍完整提供。'],
        ['id' => 'agent_instructions', 'type' => 'textarea', 'title' => '助手偏好', 'class' => 'oyiso-agent-model-field', 'default' => '', 'attributes' => ['rows' => '8', 'maxlength' => '12000', 'placeholder' => '例如：保留商品参数和有用链接；先给结论，使用简短列表。'], 'desc' => '品牌语气、内容结构、回复方式和输出格式等长期偏好。对本站所有管理员生效，当前对话的明确要求优先。'],
        // Accept old settings forms and imports; saveOptions merges and removes this retired value.
        ['id' => 'agent_custom_instructions', 'type' => 'callback', 'class' => 'hidden', 'default' => ''],
    ],
]);
CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-tools', 'title' => '工具权限', 'icon' => 'fas fa-tools', 'priority' => 24,
    'fields' => [
        ['type' => 'heading', 'content' => '工具权限'],
        ['id' => Oyiso_Agent_Permissions::OPTION, 'type' => 'callback', 'class' => 'hidden', 'default' => [], 'sanitize' => [Oyiso_Agent_Permissions::class, 'sanitize'], 'function' => static function (): void {}],
        ['id' => 'agent_mcp_namespaces', 'type' => 'callback', 'class' => 'oyiso-agent-tool-settings', 'default' => ['woodmart'], 'sanitize' => [Oyiso_Agent_MCP::class, 'sanitizeNamespaces'], 'function' => [Oyiso_Agent_MCP::class, 'render']],
        ['id' => Oyiso_Agent_MCP::SETTINGS_VERSION, 'type' => 'callback', 'class' => 'hidden', 'default' => '0', 'sanitize' => [Oyiso_Agent_Config::class, 'credential'], 'function' => static function (): void {}],
    ],
]);
CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-records', 'title' => '对话记录', 'icon' => 'fas fa-history', 'priority' => 40,
    'fields' => [
        ['type' => 'heading', 'content' => '对话记录'],
        ['type' => 'callback', 'class' => 'oyiso-agent-panel', 'function' => [Oyiso_Agent_Admin::class, 'history']],
    ],
]);
CSF::createSection('oyiso', [
    'parent' => 'ai-agent', 'id' => 'agent-files', 'title' => '文件管理', 'icon' => 'fas fa-folder-open', 'priority' => 41,
    'fields' => [
        ['type' => 'heading', 'content' => '文件管理'],
        ['type' => 'callback', 'class' => 'oyiso-agent-panel', 'function' => [Oyiso_Agent_File_Manager::class, 'render']],
    ],
]);
