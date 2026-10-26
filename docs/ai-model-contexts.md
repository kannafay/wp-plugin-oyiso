# AI 模型上下文容量

核对日期：2026-10-27。数据位于 `src/ai-agent/context-presets.php`，用于上下文圆环和自动压缩判断。

## 识别顺序

1. 获取模型列表、选择模型并保存后，优先使用该连接返回的有效容量。
2. 接口没有容量时，使用官方型号表。自定义连接可通过准确的官方 API 主机名识别厂商；其他地址沿用用户选择的提供商预设。
3. 无法识别的型号显示“容量未知”；自动压缩暂按 131,072 Token 保守处理。

型号、快照和别名均为明确列出的 ID，不按相似名称、系列前缀或任意日期推测。不把普通聊天、Chat、Mini、Live、音视频生成等变体视为同一型号。表中包含部分仍可能在旧连接使用的历史型号，收录容量不代表模型仍开放调用。

接口提供精确数字时直接采用。OpenAI、Gemini、DeepSeek、Agnes、火山方舟等使用已公布的具体数值；其他文档仅标注 K/M 而没有精确整数的条目，使用十进制保守值，不把所有“1M”一律解释成 1,048,576。Qwen 旧快照与当前别名可能容量不同，未明确核对的快照保持未知。

## 官方来源

| 厂商 | 收录范围与来源 |
| --- | --- |
| OpenAI | [型号目录](https://developers.openai.com/api/docs/models/all)及各型号页面；GPT-6/6.1、GPT-5.6/5.5/5.4、GPT-5/5.1/5.2、Codex、GPT-4.1/4o、o 系列与已核对的快照。例：[GPT-5.4](https://developers.openai.com/api/docs/models/gpt-5.4)为 1,050,000，[GPT-5.4 Mini](https://developers.openai.com/api/docs/models/gpt-5.4-mini)为 400,000，[GPT-5 Chat](https://developers.openai.com/api/docs/models/gpt-5-chat-latest)为 128,000。 |
| Anthropic / Claude | [上下文窗口说明](https://platform.claude.com/docs/en/build-with-claude/context-windows)；明确列出的 Fable/Mythos、Opus、Sonnet、Haiku 型号，分别采用 1M 或 200K，不假设所有 Claude 都是 1M。 |
| Google / Gemini | [型号目录](https://ai.google.dev/gemini-api/docs/models)、[Token 限制](https://ai.google.dev/gemini-api/docs/tokens)、[模型元数据 API](https://ai.google.dev/api/models)；聊天和推理型号。原生接口返回 `inputTokenLimit`；Gemini 4 的统一窗口与较早版本的输入预算含义不同，均采用 1,048,576 的输入安全边界。 |
| DeepSeek | [模型列表 API](https://api-docs.deepseek.com/api/list-models/)、[型号说明](https://api-docs.deepseek.com/quick_start/pricing/)；Flash、V4 Pro 及官方明确保留的 Flash 旧别名。 |
| Agnes AI | [Agnes 3.0 Flash](https://agnes-ai.cn/zh-Hans/docs/agnes-30-flash)；保留已核对的 2.5 Flash、3.0 Flash 和 2.5 Pro 容量。 |
| Alibaba / Qwen | [型号目录](https://www.alibabacloud.com/help/en/model-studio/text-generation-model/)、[Qwen3.8 Max](https://www.alibabacloud.com/help/en/model-studio/qwen3-8-max)、[Qwen3.6 35B A3B](https://www.alibabacloud.com/help/en/model-studio/qwen3-6-35b-a3b)、[Qwen Plus](https://www.alibabacloud.com/help/en/model-studio/qwen-plus)；Qwen3.6–3.8 与常用 Plus/Flash/Turbo/Max。 |
| Moonshot / Kimi | [型号列表](https://platform.kimi.ai/docs/models)、[参数对照](https://platform.kimi.ai/docs/api/models-overview)；K3.1 Pro/Highspeed、K3、K2.7 Code/Highspeed、K2.6。 |
| Z.AI / 智谱 GLM | [GLM-5.3](https://docs.z.ai/guides/llm/glm-5.3)、[GLM-5.3 Flash/FlashX](https://docs.z.ai/guides/vlm/glm-5.3-flash)、[GLM-5.2](https://docs.z.ai/guides/llm/glm-5.2)、[GLM-5](https://docs.z.ai/guides/llm/glm-5)、[GLM-4.7 系列](https://docs.z.ai/guides/llm/glm-4.7)；新旧窗口分别匹配，不把 5.2 的容量套给 GLM-5。 |
| MiniMax | [模型调用说明](https://platform.minimax.io/docs/guides/text-generation)；MiniMax-M3.1-Flash、MiniMax-M3 为 1,000,000。 |
| xAI / Grok | [型号目录](https://docs.x.ai/developers/models)、[Grok 4.7](https://docs.x.ai/developers/models/grok-4.7)、[Grok 4.5](https://docs.x.ai/developers/models/grok-4.5)、[Grok 4.3](https://docs.x.ai/developers/models/grok-4.3-latest)、[旧型号路由说明](https://docs.x.ai/developers/migration/may-15-retirement)；4.5–4.7 与 4.3 的容量不同，明确旧路由别名按当前官方路由匹配。 |
| Mistral | [Large 4](https://docs.mistral.ai/models/mistral-large-4-0)、[Medium 3.5](https://docs.mistral.ai/models/mistral-medium-3-5-26-04)、[Small 4](https://docs.mistral.ai/models/mistral-small-4-0-26-03)；分别采用文档 1M、256K 的保守值。 |
| 火山方舟 / 豆包 | [Agent Plan 型号与窗口](https://console.volcengine.com/ark/region:cn-beijing/docs/agent-plan-personal-plan-overview)、[Seed 2.1 Lite](https://ark.volcengine.com/models/doubao-seed-2-1-lite)、[Seed Evolving](https://ark.volcengine.com/models/doubao-seed-evolving)；已核对的 2.1 Pro/Lite、Evolving 与 2.0 Mini。`ep-...` 私有推理接入点无法从名字判断底层型号，保持未知。 |

## 自定义连接

Claude、千问、Kimi、GLM、MiniMax、Grok、Mistral 和豆包可在现有“自定义”提供商中配置；容量识别不新增密钥存储或额外推理请求，也不改变接口协议或工具权限。

仅匹配代码明确列出的官方主机。名称相似的第三方地址、任意网关、私有部署和 `ep-...` 接入点不猜测底层容量；它们可以使用模型列表接口返回的容量。主机识别不用于授权，也不改变密钥发送目的地。

本表随插件维护，不会在每次聊天时抓取网页。新增或变更型号时，应重新核对官方型号 ID、普通/思考模式与接口的具体限制；优先保留连接接口返回的容量。
