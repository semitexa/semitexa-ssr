# Changelog

Changes to `semitexa/ssr` that a consuming application can notice. Sections are
`## <version> — <date>` (newest first); `## Unreleased` collects changes until the
next release tag. This file is machine-read by `update:changelog` and the OS
"What's new" surface — keep entries short and operator-facing.

## Unreleased

### Fixed
- A guest's page that is sent a stream keeps its session: a page that rendered a
  deferred slot or component, or asked for a live stream
  (`ui_page_sse_session_meta()`). `semitexa/core` now stores a guest session only
  once a request uses it, and the components rendered on the stream are bound to
  the session the browser presents there. A page that defers nothing, an error
  page included, stores no session, also in an app that has deferred components
  elsewhere.

## 2026.11.25.0522 — 2026-11-25

### Changed
- `SSE_MAX_CONN_GLOBAL` now caps the whole server at its value × `SWOOLE_WORKER_NUM`,
  counted across the workers, instead of each worker at its value. Connections do
  not spread evenly over the workers, so one worker reached its 500 and answered
  429 while the server held about 900 of 2000. One worker may hold up to 2 × the
  value. The refusal says which cap it was: "for this server" or "for this worker".

### Security
- A deferred SSE stream checks its bind token before it opens. A request naming
  another page's SSE session with a wrong or missing bind token used to take that
  session over from its live connection and be sent its waiting frames before it
  was refused. It is now refused first: it gets the closing frame and nothing else.

### Fixed
- The error page renders on a worker that has just started. During a restart burst,
  a request could render with the template environment while it was still being
  built. Twig then refused the build's `sse_port` global, and the error page fell
  back to its plain version. The environment is now visible only once complete.
  When the error page itself fails, the log names the error it was rendering for:
  status, path, exception class and route.

## 2026.11.24.1611 — 2026-11-24

### Fixed
- The SSE per-IP connection cap (`SSE_MAX_CONN_PER_IP`) counts the client, not the
  proxy in front of it: behind a trusted proxy (loopback, or an address in
  `TRUSTED_PROXIES`) the key is the address it forwarded. Before, every visitor
  behind a reverse proxy shared one count, and a few idle anonymous streams made
  `/__semitexa_kiss` answer 429 to everyone. Behind a proxy that is not on
  loopback, set `TRUSTED_PROXIES`.

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
