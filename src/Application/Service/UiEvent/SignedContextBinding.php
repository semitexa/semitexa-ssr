<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

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

    public static function bind(string $sessionId, string $tenantId): void
    {
        CoroutineLocal::set(self::CTX_KEY, ['s' => trim($sessionId), 't' => trim($tenantId)]);
    }

    /** @return array{s: string, t: string}|null null when nothing bound this coroutine (CLI, a bare test) */
    public static function current(): ?array
    {
        $binding = CoroutineLocal::get(self::CTX_KEY, null);

        return is_array($binding) && isset($binding['s'], $binding['t']) ? $binding : null;
    }

    /** @param array{s: string, t: string}|null $binding */
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
