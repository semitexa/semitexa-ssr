# semitexa/ssr

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

## Deferred SSE Safety

- `SSR_DEFERRED_PERSISTENT_SSE=false` is the safe default. Deferred SSR streams final HTML blocks once and then closes the SSE connection.
- `SSR_DEFERRED_PERSISTENT_SSE_REQUIRE_AUTH=true` adds a second guard for persistent mode. Even when persistent SSE is explicitly enabled, live reconnect-capable streams still require an authenticated session by default.
- For public pages and demos, keep persistent SSE disabled unless you have a specific live-update use case and have capacity controls in place.

## Notes

SSR is required for HTML pages. JSON-only APIs do not need this package. Layout slots use handle-based scoping: `*` for global, layout frame name, or specific page handle.
