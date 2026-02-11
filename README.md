# Semitexa Core Frontend

Server-side rendering for Semitexa using Twig: layouts, slots, and HTML response handling.

## Installation

```bash
composer require semitexa/module-core-frontend
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

Create `src/theme/Website/one-column.html.twig` (mirror path under the module) to override that template; Twig loads theme first

Use this package when you build HTML pages (not just JSON API). See **semitexa/docs** (e.g. AI_REFERENCE, RECOMMENDED_STACK) and [core/docs/ADDING_ROUTES.md](../semitexa-core/docs/ADDING_ROUTES.md) for the “Responses: JSON and HTML pages” section.
