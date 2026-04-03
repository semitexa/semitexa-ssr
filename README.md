# Semitexa SSR

Twig-based server-side rendering with components, layout slots, theme overrides, and locale-aware URL generation.

## Purpose

Renders HTML responses using Twig. Provides a component system discovered via `#[AsComponent]`, layout slot composition via `#[AsLayoutSlot]`, theme override support for module templates, and locale-aware URL generation.

## Role in Semitexa

Depends on Twig, Locale, and Tenancy. Used by Mail, Platform WM, Platform User, Platform Settings, and Demo. Required for packages that render HTML pages.

## Key Features

- `#[AsComponent]` attribute for component discovery
- `#[AsLayoutSlot]` for layout slot registration
- `#[AsDataProvider]` and `#[AsTwigExtension]` for template extensions
- `LayoutRenderer` for slot-based page composition
- Theme override system (module templates overridable via `src/theme/`)
- `UrlGenerator` with locale-aware routing
- `ModuleTemplateRegistry` for per-module template discovery
- Development hot-reload support
- Automatic `robots.txt` fallback with Semitexa-specific crawler hints when the project does not provide a real file

## Deferred SSE Safety

- `SSR_DEFERRED_PERSISTENT_SSE=false` is the safe default. Deferred SSR streams final HTML blocks once and then closes the SSE connection.
- `SSR_DEFERRED_PERSISTENT_SSE_REQUIRE_AUTH=true` adds a second guard for persistent mode. Even when persistent SSE is explicitly enabled, live reconnect-capable streams still require an authenticated session by default.
- For public pages and demos, keep persistent SSE disabled unless you have a specific live-update use case and have capacity controls in place.

## Notes

SSR is required for HTML pages. JSON-only APIs do not need this package. Layout slots use handle-based scoping: `*` for global, layout frame name, or specific page handle.

If a project does not provide `robots.txt` or `public/robots.txt`, SSR emits a minimal default file automatically. Set `ROBOTS_SITEMAP_URL` when you want the generated file to advertise a classic sitemap explicitly. SSR also exposes `/sitemap.json` as a crawler-oriented JSON route inventory and advertises it from the fallback `robots.txt`.

When an SSR page route declares extra response formats via `#[AsPayload(produces: ...)]`, Semitexa renders `<link rel="alternate" type="...">` tags for the non-HTML variants of the current payload DTO. This keeps the `<head>` aligned with the actual route contract instead of hardcoding alternates in Twig.
