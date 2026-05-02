# Elementor 小部件兼容性规则

本文说明 Oyiso 自定义 Elementor 小部件在 Elementor 未启用时的处理方式，以及后续新增小部件想自动纳入兼容逻辑时必须遵守的约定。

## 0. 目的

有些页面已经保存过 Oyiso Elementor 小部件的前台输出 HTML。

如果之后 Elementor 被停用，这些页面仍可能从 `post_content` 输出旧的小部件 HTML，但 Elementor 的 CSS 和 JS 已经不会再加载。最终表现就是前台布局损坏，甚至直接出现原始 HTML。

为了解决这个问题，Oyiso 在下面这个文件里增加了前台兜底清理逻辑：

- `src/plugin-extensions/elementor-widgets/index.php`

这个过滤逻辑只会在 Elementor 不可用时移除 Oyiso 自己的小部件输出。

同一个模块现在也已经按更接近官方插件扩展的方式进行了启动整理：

- 在 `plugins_loaded` 之后做兼容性检查
- 如果 Elementor 不可用，则不初始化小部件模块
- 对有权限的后台用户显示提示
- 对已经写入页面内容的旧 HTML 继续保留前台兜底清理

## 1. 兜底逻辑什么时候触发

只有同时满足下面条件时，清理逻辑才会运行：

- Elementor 没有加载
- Elementor 对当前站点未启用
- 当前请求不是后台、AJAX、feed、embed、REST
- 当前文章存在 `_elementor_data`
- 该 `_elementor_data` 中至少包含一个 `widgetType` 以 `oyiso_` 开头的小部件

这样可以把影响范围压到最小，避免误处理无关内容。

## 2. 多站点 / 网络模式支持

Elementor 可用性检查同时覆盖了两种激活方式：

- 当前站点已启用插件
- 多站点网络启用插件，即 `active_sitewide_plugins`

因此这套规则兼容：

- 单站点启用
- 多站点中单个子站启用
- 多站点网络统一启用

## 3. 新增小部件的自动兼容约定

如果你后续新增一个 Oyiso Elementor 小部件，并希望它自动被这套兜底规则覆盖，需要遵守下面这些约定。

### 必须满足

1. 小部件 `get_name()` 必须使用 `oyiso_` 前缀

示例：

```php
public function get_name()
{
    return 'oyiso_new_banner';
}
```

2. 小部件前台输出应该有一个清晰的根容器

3. 根容器上应该包含一个 `data-oyiso-*` 标记

示例：

```php
<section class="oyiso-new-banner" data-oyiso-new-banner>
```

### 建议保持

- 保持稳定的根 class，例如 `oyiso-new-banner`
- 不要把小部件内容拆成没有根包裹的零散纯文本
- 不要只依赖深层嵌套片段作为唯一识别标记

## 4. 为什么 `data-oyiso-*` 标记很重要

当旧的小部件 HTML 已经被写入 `post_content` 后，兜底清理逻辑需要依靠 Oyiso 特有的前台标记来识别并移除这些内容。

最稳定的标记就是：

- 带有 `data-oyiso-*` 的根节点

如果某个小部件没有稳定的根标记，后续清理会变得困难，最后往往只能为这个小部件单独写兼容分支。

## 5. 历史兼容说明

`Info_Card` 的旧版本保存内容里，曾经出现过没有标准根包裹、而是被摊平成普通 HTML 写入 `post_content` 的情况。

因此项目里对旧版 `oyiso_info_card` 额外保留了历史兼容清理逻辑。

这是特殊历史包袱，不应该在新小部件里继续照搬。

新小部件统一遵守“根包裹 + `data-oyiso-*` 标记”即可，让通用兜底逻辑就能覆盖。

## 6. 开发检查清单

新增 Elementor 小部件前，至少确认下面几点：

- `get_name()` 以 `oyiso_` 开头
- `render()` 有且只有一个清晰的根包裹
- 根包裹上带有 `data-oyiso-*`
- Elementor 启用时，小部件前台显示正常
- Elementor 停用时，页面不会泄露旧的小部件 HTML

## 7. 排查建议

如果代码上看起来已经有兜底逻辑，但前台仍然出现旧 HTML，可以按下面顺序排查：

- 清理页面缓存
- 清理对象缓存
- 清理 CDN 或反向代理缓存
- 确认该页面的 `_elementor_data` 里确实存在 `oyiso_*` 小部件
- 检查旧 HTML 是否已经被直接写入 `post_content`

如果某个新小部件没有被兜底逻辑正确移除，先检查它是否违反了第 3 节中的约定。
