<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

/**
 * Fail-closed barrier for the non-owner-request-scoped fan-out writers on
 * {@see AsyncResourceSseServer} — `deliverToUser()` (all of one user's
 * sessions) and `deliverToAuthenticatedUsers()` (system-wide), plus the
 * {@see \Semitexa\Ssr\Application\Service\UiEvent\AsyncResourceSseMessagePublisher::publishToUser()}
 * wrapper.
 *
 * These primitives perform ZERO content-vs-recipient authorization: they
 * merely loop owner-scoped `deliver()` over a recipient list. Private
 * content (e.g. a grid's `UiComponentStateMessage` row bag) routed through
 * them would land in every targeted session unfiltered. They are latent
 * (zero callers) today, so they are fenced shut now — while fencing is
 * free — so a future caller cannot quietly open a private broadcast before
 * the per-recipient entitlement filter exists.
 *
 * This is a BARRIER, not the filter. Track R replaces the throw in those
 * methods with the real per-recipient entitlement-gated implementation.
 * See `var/docs/ui-stream-broadcast-egress-diagnostic.md` §4 and
 * `var/docs/sse-consolidation-map.md` §5 (Track R).
 *
 * Owner-request-scoped delivery — `deliver($sessionId, …)` / the canonical
 * `publish($sessionId, …)` path that live KISS uses — is UNAFFECTED: its
 * bound is the page-auth that admitted the owner.
 */
final class FanOutNotYetGatedException extends \LogicException
{
    public static function forFanOut(string $method): self
    {
        return new self(sprintf(
            'Fan-out SSE delivery (%s) is disabled until Track R\'s per-recipient '
            . 'entitlement filter exists: these primitives do zero content-vs-recipient '
            . 'authorization. See var/docs (Track R: ui-stream-broadcast-egress-diagnostic.md §4, '
            . 'sse-consolidation-map.md §5). Owner-scoped deliver()/publish($sessionId, …) is unaffected.',
            $method,
        ));
    }
}
