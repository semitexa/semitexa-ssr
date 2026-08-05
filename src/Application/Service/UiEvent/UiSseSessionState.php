<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

use Semitexa\Core\Support\CoroutineLocal;

/**
 * Per-render holder for the canonical SSE subscriber channel id every
 * UI component on a page shares.
 *
 * The id is opt-in: pages render `{{ ui_page_sse_session_meta() }}` (or call the
 * lower-level `ui_page_sse_session()` helper) before any component renders. That
 * call mints a fresh id; every subsequent `ui_event_manifest()` call within the
 * same render picks it up via {@see self::current()} and folds it into the
 * signed ctx as the `sub` claim. Pages that DO NOT mint a session id keep
 * producing manifests with no `sub` claim — the dispatcher then delivers patches
 * inline, preserving the pre-canonical-SSE behaviour.
 *
 * Why this lives in ssr rather than platform-ui
 * ---------------------------------------------
 * The deferred-render pipeline needs it. `DeferredBlockOrchestrator`,
 * `DeferredRequestRegistry` and `HtmlResponse` each have to carry the page's
 * session id across the request boundary into the deferred stream, and all three
 * are ssr's. While the class lived in platform-ui they could only reach it
 * through `class_exists()` guards, because ssr does not (and must not) require
 * platform-ui — platform-ui already requires ssr, so the reverse edge would
 * close a cycle.
 *
 * `Service/UiEvent/` is where that seam already is: this package owns
 * {@see UiResponseDispatcherInterface}, {@see SignedContext} and the message
 * types, and platform-ui implements them. The session id belongs on the same
 * shelf as the contracts it is claimed in.
 *
 * The state is COROUTINE-LOCAL ({@see CoroutineLocal}). A Swoole worker serves
 * many requests concurrently, each on its own coroutine; a plain process-static
 * would be SHARED across those coroutines, so two concurrent requests would
 * read/overwrite each other's channel id and a request could publish patches to
 * ANOTHER user's live stream. Storing the id in the coroutine context isolates
 * it per request and auto-cleans when the coroutine ends. platform-ui's
 * `ResetPlatformUiSseSessionListener` still resets on the AuthCheck phase —
 * required for the CLI/test fallback store, which (unlike a coroutine context)
 * persists across requests.
 *
 * Why not a Twig context variable: `ui_event_manifest()` is called from inner
 * component templates (e.g. platform.form, platform.field) whose render contexts
 * do not flow caller variables in. A static holder is the simplest
 * cross-template channel that does not require editing every component to
 * forward an extra prop.
 *
 * Why an unsigned id: the canonical KISS endpoint takes an UNSIGNED
 * `session_id` query parameter and routes by it directly — defence in depth
 * comes from the signed `sub` claim, not from this id. The dedicated `sse_`
 * prefix keeps logs disambiguated.
 */
final class UiSseSessionState
{
    /**
     * Safe shape for a subscriber channel id. Same alphabet the manifest builder
     * accepts on the `sub` claim and platform-ui's response dispatcher
     * re-validates after verification.
     */
    public const SAFE_ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/';

    /**
     * 16 random bytes → 32 hex chars → 128 bits of entropy. The `sse_` prefix
     * makes the id distinguishable in Swoole / KISS server logs from other
     * session families (`uch_` for the legacy channel-token route, `uci_` for
     * component instance ids).
     */
    private const PREFIX = 'sse_';
    private const ENTROPY_BYTES = 16;

    /**
     * Coroutine-local storage key for the current request's SSE session id.
     *
     * Deliberately still `platform_ui.*` after the move: the key is the actual
     * channel between this class and every caller, and renaming it would split
     * the state in any process where a stale copy of the old class is still
     * loaded — during a rolling deploy, or against the deprecated shim.
     */
    private const CTX_KEY = 'platform_ui.sse_session_id';

    public static function current(): ?string
    {
        $id = CoroutineLocal::get(self::CTX_KEY);

        return is_string($id) ? $id : null;
    }

    /**
     * Mints a fresh id only on the first call within a request. Subsequent calls
     * within the same request return the same id — crucial so every component on
     * the page ends up with the same `sub` claim and the runtime opens a single
     * SSE connection.
     */
    public static function mintIfAbsent(): string
    {
        $existing = self::current();
        if ($existing !== null) {
            return $existing;
        }
        $id = self::PREFIX . bin2hex(random_bytes(self::ENTROPY_BYTES));
        CoroutineLocal::set(self::CTX_KEY, $id);

        return $id;
    }

    public static function reset(): void
    {
        CoroutineLocal::remove(self::CTX_KEY);
    }

    /**
     * Restore the originating page's canonical SSE session id into the current
     * request scope.
     *
     * Deferred-SSR components render in a SEPARATE request from the page that
     * emitted them (the `/__semitexa_kiss` deferred stream). Without this,
     * {@see self::mintIfAbsent()} called from a deferred component's data
     * provider / `ui_event_manifest()` would mint a FRESH id that no live
     * EventSource subscribes to — so the dispatcher would publish `ui.patch` /
     * `ui.componentState` frames to a dead channel. The deferred-render pipeline
     * captures the page's session id during the main render and calls this to
     * re-establish it before the component renders, so its `sub` claim matches
     * the page's live stream.
     *
     * Idempotent. Silently ignores an unsafe-shaped id (the caller then falls
     * back to {@see self::mintIfAbsent()}) rather than throwing — a malformed
     * stored id must never break deferred rendering.
     */
    public static function restore(string $id): void
    {
        if (preg_match(self::SAFE_ID_PATTERN, $id) !== 1) {
            return;
        }
        CoroutineLocal::set(self::CTX_KEY, $id);
    }

    /**
     * Test seam: callers MAY pre-seed a deterministic id so unit tests can
     * assert on the exact value rendered into the page. The id MUST match
     * {@see self::SAFE_ID_PATTERN}; this guard keeps the test surface consistent
     * with what the manifest builder accepts in production.
     */
    public static function setForTesting(string $id): void
    {
        if (preg_match(self::SAFE_ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException(
                'UiSseSessionState::setForTesting() id must match ' . self::SAFE_ID_PATTERN,
            );
        }
        CoroutineLocal::set(self::CTX_KEY, $id);
    }
}
