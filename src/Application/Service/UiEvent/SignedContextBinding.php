<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

use Semitexa\Core\Lifecycle\CarriedContext;
use Semitexa\Core\Queue\QueuedOrigin;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * Who a signed context belongs to: the session and tenant of the request that
 * is running. {@see SignedContext::sign()} folds them into every context it
 * mints and {@see SignedContext::verify()} refuses a context presented by a
 * different session or tenant — a context copied out of one user's page is
 * worthless in another's (cf. Symfony UX CVE-2026-49212: a signature that
 * did not cover enough was replayable elsewhere).
 *
 * Bound once per request by BindRequestToComponentRendererListener (which has
 * the session and tenant injected), coroutine-local, and carried into the
 * coroutines a request spawns (SseSessionCoroutines) and into the work it runs
 * after the response ({@see CarriedContext}: an Async handler's resource is
 * rendered there), and as a digest into a job it queues ({@see QueuedOrigin}:
 * a Queued handler's reply renders in the worker).
 */
final class SignedContextBinding
{
    private const CTX_KEY = 'ssr.signed_context.binding';

    /**
     * Binds to the session object, not to the id it has now: a sign-in
     * regenerates the id mid-request, and contexts minted after that must
     * carry the id the browser presents next. A string pins an id as is.
     */
    public static function bind(SessionInterface|string|null $session, string $tenantId): void
    {
        CarriedContext::carry(self::CTX_KEY);
        // And into a job this request queues, as a digest (QueuedOrigin).
        QueuedOrigin::exporter(SignedContext::QUEUED_ORIGIN, SignedContext::queuedBinding(...));
        CoroutineLocal::set(self::CTX_KEY, ['session' => $session, 't' => trim($tenantId)]);
    }

    /** @return array{s: string, t: string}|null null when nothing bound this coroutine (CLI, a bare test) */
    public static function current(): ?array
    {
        $binding = self::snapshot();
        if ($binding === null) {
            return null;
        }
        $session = $binding['session'];

        return [
            's' => trim($session instanceof SessionInterface ? $session->getId() : (string) $session),
            't' => $binding['t'],
        ];
    }

    /**
     * Says the page being rendered opens a stream: it is sent a deferred
     * manifest, or it asked for a live stream (ui_page_sse_session_meta()).
     * Its session has to reach the browser, because contexts minted on that stream (a deferred or
     * re-rendered component) are bound to the session the stream's request
     * presents. Core stores a guest's session only once something used it, and
     * asking for its id is that use; on the stream it is too late, the
     * response has started. Without this a guest's first page set no session
     * cookie, the stream ran on a session of its own, and every context it
     * minted was refused when the browser presented it.
     */
    public static function keepSession(): void
    {
        $session = self::snapshot()['session'] ?? null;
        if ($session instanceof SessionInterface) {
            $session->getId();
        }
    }

    /**
     * keepSession() for a page that rendered deferred content, and so is sent
     * the manifest and opens the stream; returns what it was given. Asked
     * after rendering, not where the stream id is minted: every page of an app
     * that has a deferred component anywhere gets an id before it renders, an
     * error page included, and kept there every one of them stored a session.
     */
    public static function keepSessionIf(bool $pageStreams): bool
    {
        if ($pageStreams) {
            self::keepSession();
        }

        return $pageStreams;
    }

    /**
     * The binding as held, for carrying into a coroutine a request spawns
     * ({@see restore()}); it keeps following the session there too.
     *
     * @return array{session: SessionInterface|string|null, t: string}|null
     */
    public static function snapshot(): ?array
    {
        $binding = CoroutineLocal::get(self::CTX_KEY, null);
        if (!is_array($binding) || !array_key_exists('session', $binding) || !isset($binding['t'])) {
            return null;
        }

        /** @var array{session: SessionInterface|string|null, t: string} $binding bind() and restore() write this key, in this shape */
        return $binding;
    }

    /** @param array{session: SessionInterface|string|null, t: string}|null $binding */
    public static function restore(?array $binding): void
    {
        if ($binding === null) {
            self::clear();
            return;
        }
        CoroutineLocal::set(self::CTX_KEY, $binding);
    }

    public static function clear(): void
    {
        CoroutineLocal::remove(self::CTX_KEY);
    }
}
