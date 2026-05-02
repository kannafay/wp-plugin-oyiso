# Elementor i18n Rules

This project uses two different translation paths in Elementor widgets.

See also:

- `docs/elementor-widget-compat.md`

## 0. Scope

`oyiso_editor_t()`, `oyiso_t()`, and `oyiso_t_sprintf()` are custom helpers reserved only for Elementor widgets.

Allowed scope:

- `src/plugin-extensions/elementor-widgets`
- except `src/plugin-extensions/elementor-widgets/settings.php`

`src/plugin-extensions/elementor-widgets/settings.php` is a CSF admin settings file, not Elementor widget runtime code.
Do not use `oyiso_editor_t()`, `oyiso_t()`, or `oyiso_t_sprintf()` in that file.

Do not use these helpers in other plugin modules such as:

- `oyiso.php`
- other `src` modules outside Elementor widgets
- `classes`
- `fields`
- `functions`
- `views`
- shared admin pages unrelated to Elementor widgets

Outside Elementor widgets, use the normal WordPress i18n path for that area instead.

## 1. Editor UI: use `oyiso_editor_t()`

Use `oyiso_editor_t()` for labels that belong to the Elementor editing interface and should follow the current editor user's language.

Typical cases:

- Widget names
- Control labels
- Section titles
- Tab titles
- Fixed select/choose option labels
- Help text, notices, and panel descriptions
- Placeholders that only assist editing

Examples:

- `label`
- `title`
- `options`
- `description`
- `placeholder`

## 2. Site-facing content: use `oyiso_t()`

Use `oyiso_t()` for anything that is part of the actual widget output and should follow the site language.

Typical cases:

- Frontend output
- Elementor right-side live preview
- Default values for editable text controls
- AJAX response messages
- Script localization strings consumed by frontend JS
- Dialog text, button text, empty states, status messages

Examples:

- `default` for text and textarea controls
- `render()`
- HTML templates
- localized JS strings
- AJAX handlers

If formatted output is needed, use `oyiso_t_sprintf()` or `sprintf(oyiso_t(...), ...)`.

## 3. Practical rule of thumb

Inside `register_controls()`:

- editor-facing UI text => `oyiso_editor_t()`
- editable content defaults => `oyiso_t()`

Inside `render()`, frontend helpers, AJAX, and localized JS:

- use `oyiso_t()`

## 4. Avoid raw `__()` in widget runtime

Do not use raw `__()`, `esc_html__()`, or `esc_attr__()` in widget runtime output unless the string is intentionally meant to follow the current user language.

For this project, most runtime strings should use site-language helpers instead.

## 5. Project convention summary

- `oyiso_editor_t()` only for Elementor editor UI
- `oyiso_t()` / `oyiso_t_sprintf()` only for Elementor widget preview, frontend, AJAX, and widget JS strings
- `src/plugin-extensions/elementor-widgets/settings.php` is excluded from this rule and should use normal WordPress i18n
- Outside Elementor widgets, do not use `oyiso_*` custom i18n helpers
