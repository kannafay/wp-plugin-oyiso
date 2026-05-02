# Elementor 多语言规则

这个项目在 Elementor 小部件里使用两套不同的翻译路径。

另见：

- `docs/elementor-widget-compat.md`

## 0. 适用范围

`oyiso_editor_t()`、`oyiso_t()`、`oyiso_t_sprintf()` 是项目自定义的翻译辅助函数，只能用于 Elementor 小部件相关代码。

允许使用的范围：

- `src/plugin-extensions/elementor-widgets`
- 但不包括 `src/plugin-extensions/elementor-widgets/settings.php`

`src/plugin-extensions/elementor-widgets/settings.php` 属于 CSF 后台设置文件，不是 Elementor 小部件运行时代码。
这个文件里不要使用 `oyiso_editor_t()`、`oyiso_t()`、`oyiso_t_sprintf()`。

下面这些位置也不要使用这组自定义函数：

- `oyiso.php`
- `src` 下除 Elementor 小部件外的其他模块
- `classes`
- `fields`
- `functions`
- `views`
- 与 Elementor 小部件无关的共享后台页面

在 Elementor 小部件范围外，应使用对应模块自己的 WordPress 原生 i18n 方案。

## 1. 编辑器界面：使用 `oyiso_editor_t()`

凡是属于 Elementor 编辑界面、并且应该跟随当前编辑用户语言的文本，都使用 `oyiso_editor_t()`。

典型场景：

- 小部件名称
- 控件标签
- 分区标题
- 标签页标题
- 固定的选择项文案
- 帮助文本、说明、面板提示
- 仅用于辅助编辑的占位文案

常见位置：

- `label`
- `title`
- `options`
- `description`
- `placeholder`

## 2. 站点前台内容：使用 `oyiso_t()`

凡是属于小部件实际输出、并且应该跟随站点语言的文本，都使用 `oyiso_t()`。

典型场景：

- 前台输出
- Elementor 右侧实时预览
- 可编辑文本控件的默认值
- AJAX 返回消息
- 供前台 JS 使用的本地化字符串
- 弹窗文字、按钮文案、空状态、状态提示

常见位置：

- 文本和 textarea 控件的 `default`
- `render()`
- HTML 模板
- 本地化 JS 字符串
- AJAX 处理函数

如果需要格式化输出，使用 `oyiso_t_sprintf()`，或者 `sprintf(oyiso_t(...), ...)`。

## 3. 实用判断规则

在 `register_controls()` 里：

- 面向编辑器界面的固定文案，用 `oyiso_editor_t()`
- 面向站点展示的默认内容，用 `oyiso_t()`

在 `render()`、前台辅助函数、AJAX、前台本地化 JS 里：

- 使用 `oyiso_t()`

## 4. 避免在小部件运行时直接写原生 `__()`

除非你明确希望字符串跟随当前用户语言，否则不要在小部件运行时输出中直接使用 `__()`、`esc_html__()`、`esc_attr__()`。

对这个项目来说，大多数小部件运行时字符串都应该走站点语言辅助函数。

## 5. i18n 命令说明

当前 `package.json` 中与多语言相关的命令如下：

```bash
pnpm i18n:extract
pnpm i18n:sync
pnpm i18n:prune
pnpm i18n:check
pnpm i18n:build
```

各命令含义：

- `pnpm i18n:extract`
  重新扫描 PHP 源码中的 `oyiso_editor_t()`、`oyiso_t()`、`oyiso_t_sprintf()`，并更新 `languages/oyiso.pot`
- `pnpm i18n:sync`
  在更新 `languages/oyiso.pot` 后，把缺失的 `msgid` 追加到 `languages/oyiso-*.po`
- `pnpm i18n:prune`
  在更新 `languages/oyiso.pot` 后，删除 `languages/oyiso-*.po` 中已经不再存在于 `.pot` 的旧条目
- `pnpm i18n:check`
  只做检查，不写文件；严格模式下如果存在缺失、空翻译、fuzzy 条目会返回失败
- `pnpm i18n:build`
  将现有的 `languages/oyiso-*.po` 编译为对应的 `.mo`

## 6. `sync` 和 `prune` 的行为边界

`i18n:sync` 的行为是保守追加：

- 只添加缺失的 `msgid`
- 不删除旧条目
- 不覆盖已有 `msgstr`
- 不自动处理 fuzzy

`i18n:prune` 的行为是保守清理：

- 只删除 `.pot` 中已经不存在的旧 `msgid`
- 保留有效条目的翻译、注释和整体结构

## 7. 语言文件命名限制

当前脚本只处理下面这种命名：

- `languages/oyiso-zh_CN.po`
- `languages/oyiso-pl_PL.po`

也就是说，脚本只认 `languages/oyiso-*.po`。

下面这种旧命名文件不会被 `sync`、`prune`、`build` 自动处理：

- `languages/zh_CN.po`
- `languages/pl_PL.po`

如果某个语言包还在使用旧命名，请先迁移到 `oyiso-*.po` 命名。

## 8. 推荐工作流

新增或修改小部件文案后，推荐按这个顺序执行：

```bash
pnpm i18n:extract
pnpm i18n:sync
pnpm i18n:check
pnpm i18n:build
```

如果你刚删除了一批旧文案，可以在 `sync` 后补一次：

```bash
pnpm i18n:prune
pnpm i18n:check
pnpm i18n:build
```

## 9. 项目约定总结

- `oyiso_editor_t()` 只用于 Elementor 编辑器界面
- `oyiso_t()` / `oyiso_t_sprintf()` 只用于 Elementor 小部件预览、前台、AJAX、前台 JS 字符串
- `src/plugin-extensions/elementor-widgets/settings.php` 不适用这套辅助函数，应使用常规 WordPress i18n
- Elementor 小部件范围外，不要使用 `oyiso_*` 自定义 i18n 辅助函数
