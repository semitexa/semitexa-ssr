<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

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
 * coroutines a request spawns (SseSessionCoroutines).
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
     * The binding as held, for carrying into a coroutine a request spawns
     * ({@see restore()}); it keeps following the session there too.
     *
     * @return array{session: SessionInterface|string|null, t: string}|null
     */
    public static function snapshot(): ?array
    {
        $binding = CoroutineLocal::get(self::CTX_KEY, null);

        return is_array($binding) && array_key_exists('session', $binding) && isset($binding['t']) ? $binding : null;
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
