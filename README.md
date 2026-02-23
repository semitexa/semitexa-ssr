# Semitexa Core Frontend

> **Philosophy & ideology** — [Why Semitexa: vision and principles](../semitexa-docs/README.md). The detailed, technical documentation for this package is below.

Server-side rendering for Semitexa using Twig: layouts, slots, and HTML response handling.

## Installation

```bash
composer require semitexa/ssr
```

## What's inside

- **LayoutRenderer** — Renders response content into a layout template (e.g. one-column, two-column)
- **Twig** — Twig integration; templates under `Application/View/templates/` in your modules
- **Layout slots** — `#[AsLayoutSlot]` + `layout_slot('slotname')`; handle `*` (global), layout frame, or page handle
- **Theme override** — `src/theme/{ModuleName}/` overrides module templates (same path)
- **HtmlResponse** — Response type for HTML pages

## Slots

In layout templates: `{{ layout_slot('nav') }}`, `{{ layout_slot('header') }}`, etc. Register with `#[AsLayoutSlot(handle: '*', slot: 'nav', template: '...', priority: 0)]`. Use handle `'*'` for every page, or a layout name / page handle for scoped slots. Optional: `$response->setLayoutFrame('one-column')` so layout-level slots apply.

## Theme override

- **With THEME in .env:** Put overrides in `src/theme/{THEME}/{ModuleName}/` (e.g. `src/theme/default/Website/one-column.html.twig`). Set `THEME=default` in `.env` to activate. Twig loads theme paths first, so these override the module’s templates.
- **Legacy (THEME empty):** Use `src/theme/{ModuleName}/` (e.g. `src/theme/Website/one-column.html.twig`) to override that module’s templates. Works for any module that has layout templates.

Use this package when you build HTML pages (not just JSON API). See **semitexa/docs** (e.g. AI_REFERENCE, RECOMMENDED_STACK) and [core/docs/ADDING_ROUTES.md](../semitexa-core/docs/ADDING_ROUTES.md) for the “Responses: JSON and HTML pages” section.
