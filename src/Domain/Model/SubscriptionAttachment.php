<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

use Semitexa\Core\Pipeline\ReRun\ReRunContext;

/**
 * SSE transport unification · Phase 1 — the pair a {@see \Semitexa\Ssr\Domain\Contract\SubscriptionFactoryInterface}
 * produces when attaching a feed subscription to an ALREADY-OPEN held-open
 * connection (the multiplex case: one KISS fd, many subscriptions).
 *
 * It bundles the two tiers a held-open subscription needs, mirroring exactly what
 * the standalone {@see \Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseFeedHandler}
 * builds at connect:
 *  - {@see $record} — the cross-worker, identity-free {@see SubscriptionRecord}
 *    (tier-1, the reverse-index row);
 *  - {@see $context} — the worker-local {@see ReRunContext} (tier-2, the re-run
 *    state R4 resolves by streaming_id).
 *
 * The factory builds BOTH on the owning worker (where the KISS fd lives) so the
 * worker-local tier-2 lands on the worker whose loop will re-run it — the
 * cross-worker correctness the multiplex subscribe rests on.
 */
final readonly class SubscriptionAttachment
{
    public function __construct(
        public SubscriptionRecord $record,
        public ReRunContext $context,
    ) {}
}
