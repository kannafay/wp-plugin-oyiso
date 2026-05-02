# Elementor Widget Compatibility Rules

This document describes how Oyiso custom Elementor widgets behave when Elementor is disabled, and what new widgets must follow to be automatically covered by the fallback logic.

## 0. Purpose

Some pages already contain frontend HTML rendered from Oyiso Elementor widgets.

If Elementor is later deactivated, those pages may still output stale widget HTML from `post_content`, but Elementor CSS and JS will no longer load. The result is broken layout and raw HTML on the frontend.

To avoid that, Oyiso adds a frontend fallback filter in:

- `src/plugin-extensions/elementor-widgets/index.php`

That filter removes Oyiso widget output only when Elementor is unavailable.

The same module now also uses an official-style bootstrap flow:

- compatibility check after `plugins_loaded`
- stop widget module initialization when Elementor is unavailable
- show an admin notice for privileged users
- keep the frontend fallback only for already-saved stale widget HTML

## 1. When the fallback runs

The cleanup logic runs only when all of the following are true:

- Elementor is not loaded
- Elementor is not active for the current site
- the current request is not admin / AJAX / feed / embed / REST
- the current post has `_elementor_data`
- that `_elementor_data` contains at least one widget whose `widgetType` starts with `oyiso_`

This keeps the scope narrow and avoids touching unrelated content.

## 2. Multisite / network support

Elementor availability is checked against both:

- current-site active plugins
- multisite network active plugins via `active_sitewide_plugins`

So the rule works for:

- single-site activation
- multisite site-level activation
- multisite network activation

## 3. Auto-compatibility contract for new widgets

If you add a new Oyiso Elementor widget and want it to be automatically covered by the fallback rule, follow these conventions.

### Required

1. The widget `get_name()` must use the `oyiso_` prefix.

Example:

```php
public function get_name()
{
    return 'oyiso_new_banner';
}
```

2. The widget frontend output should have one clear root container.

3. The root container should include a `data-oyiso-*` marker.

Example:

```php
<section class="oyiso-new-banner" data-oyiso-new-banner>
```

### Recommended

- keep a stable root class such as `oyiso-new-banner`
- avoid outputting widget content as scattered plain text without a root wrapper
- avoid relying on nested fragments as the only identifiable marker

## 4. Why the `data-oyiso-*` marker matters

When old widget HTML has already been written into `post_content`, the fallback removes it by matching Oyiso-specific frontend markers.

The most reliable marker is:

- a root node with `data-oyiso-*`

If a widget does not expose a stable root marker, future cleanup becomes harder and may require widget-specific compatibility code.

## 5. Legacy note

`Info_Card` had older saved content that could appear as flattened HTML in `post_content` without a clean widget root wrapper.

Because of that, the project includes a legacy compatibility cleanup for old `oyiso_info_card` output.

This is a special case and should not be copied into new widgets.

For new widgets, always keep a root wrapper and a `data-oyiso-*` marker so generic fallback logic is enough.

## 6. Developer checklist

Before shipping a new Elementor widget, verify:

- `get_name()` starts with `oyiso_`
- `render()` has one root wrapper
- the root wrapper includes `data-oyiso-*`
- the widget still renders correctly with Elementor enabled
- the page does not leak stale widget HTML when Elementor is disabled

## 7. Troubleshooting

If the fallback seems correct in code but stale HTML still appears:

- clear page cache
- clear object cache
- clear CDN / proxy cache
- confirm the page really contains `oyiso_*` widget data in `_elementor_data`
- inspect whether old HTML was saved directly into `post_content`

If a new widget is not being removed by the fallback, first check whether it violates the contract in section 3.
