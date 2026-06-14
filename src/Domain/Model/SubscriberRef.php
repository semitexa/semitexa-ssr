<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

/**
 * A resolved reverse-index hit (Track R · R1, design §C.5): the minimal
 * identity a push needs to route a re-run signal to one live subscription.
 *
 * Returned by {@see \Semitexa\Ssr\Domain\Contract\SubscriberIndexInterface::find()}.
 * It carries ONLY the routing coordinates — `streamingId` (which subscription)
 * and `sessionId` (which session-addressed queue the `{__ctrl:rerun}` control is
 * RPUSHed to). It deliberately carries no scope payload and, above all, no live
 * DTO / re-run state: that lives only in the worker-static
 * {@see \Semitexa\Ssr\Application\Service\Async\SubscriptionDtoRegistry} on the
 * owning worker (the tier-separation invariant). A `SubscriberRef` is a pure
 * serializable coordinate, safe to surface from a cross-worker scan.
 */
final readonly class SubscriberRef
{
    public function __construct(
        public string $streamingId,
        public string $sessionId,
    ) {}
}
