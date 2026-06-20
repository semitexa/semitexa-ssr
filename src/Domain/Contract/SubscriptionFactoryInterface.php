<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

use Semitexa\Ssr\Domain\Model\SubscriptionAttachment;

/**
 * SSE transport unification · Phase 1 — builds a held-open feed subscription
 * from a serialized subscribe control, on the OWNING worker, so one KISS
 * connection can carry many subscriptions (one per grid/form) instead of each
 * opening its own EventSource.
 *
 * The control (queued to the KISS session and drained by the fd-owning worker)
 * carries only serializable data: the target feed route + the auth-bearing
 * request snapshot. The factory turns that into the same two tiers the standalone
 * connect builds — re-resolving the route, re-hydrating the payload DTO, and
 * re-resolving identity/tenant on THIS worker's coroutine — so the worker-local
 * re-run state ({@see \Semitexa\Core\Pipeline\ReRun\ReRunContext}) lands on the
 * worker whose loop will re-run it.
 *
 * Authorization is NOT re-implemented here: the produced {@see ReRunContext} is
 * handed straight to the existing {@see \Semitexa\Core\Pipeline\ReRun\ReRunnerInterface}
 * (`reRun()`), whose first action is the auth-first re-execute. A caller that is
 * not authorized for the feed TERMINATEs there, exactly as on every re-run tick —
 * the subscribe is then denied and no record is registered.
 */
interface SubscriptionFactoryInterface
{
    /**
     * Build the subscription's two tiers for `$streamingId` on `$sessionId`'s
     * connection, from the feed route + request snapshot. Returns null when the
     * route cannot be resolved (then the control handler denies the subscribe).
     *
     * @param array<string, mixed> $requestSnapshot the auth-bearing request snapshot
     *        (method/uri/headers/query/post/server/cookies/content/files), the same
     *        shape {@see \Semitexa\Core\Pipeline\ReRun\ReRunContext::rebuildRequest()} consumes.
     * @param ?string $tenantId   the tenant the KISS connection resolved at connect time
     *        (captured in the connection's coroutine, where it is authoritative). When
     *        non-null it scopes the SubscriptionRecord directly, so the record's channel
     *        scoping does NOT depend on which coroutine drains this control — immune to
     *        any future async / cross-worker subscribe path. Null falls back to resolving
     *        the tenant from the current coroutine (the standalone path / tests).
     * @param ?string $tenantBlob the matching opaque serialized tenant context; null
     *        falls back to the current coroutine's tenant, paired with $tenantId.
     */
    public function build(
        string $sessionId,
        string $streamingId,
        string $routePath,
        string $routeMethod,
        array $requestSnapshot,
        ?string $tenantId = null,
        ?string $tenantBlob = null,
    ): ?SubscriptionAttachment;
}
