# Changelog

Changes to `semitexa/ssr` that a consuming application can notice. Sections are
`## <version> — <date>` (newest first); `## Unreleased` collects changes until the
next release tag. This file is machine-read by `update:changelog` and the OS
"What's new" surface — keep entries short and operator-facing.

## 2026.10.08.0620 — 2026-10-08

### Removed
- `#[AsComponent(event:, triggers:)]`, `component_event_attrs()` and
  `component-events.js`. Component events are Platform UI `#[UiOn]` methods. Upgrade guide: docs `migration/one-component-model`.
- The `/__semitexa_component_event` door. UI traffic is HUG in, KISS out.

### Changed
- An unknown `component('…')` name throws with `APP_ENV=dev` (with a suggestion)
  and logs `component_not_found` elsewhere. `lint:components` finds them first.
- Signed UI contexts are bound to session and tenant — also those minted on the
  KISS stream — and follow the session through a sign-in.
- A deferred render timeout is logged with the components it left unrendered.

### Added
- HUG multipart uploads; resumable KISS stream (`ui.stream.reset`); keyed
  `ui.collection.patch`; `AssetCollector::headTag()`.
