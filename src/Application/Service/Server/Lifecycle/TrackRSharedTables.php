<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Ssr\Application\Service\Async\RerunCoalescer;
use Semitexa\Ssr\Application\Service\Async\SubscriptionTable;
use Semitexa\Ssr\Application\Service\Async\ViewChangeCoalescer;

/**
 * Track R · R5 (C2) — the CROSS-WORKER shared surfaces of the subscription store,
 * created ONCE before worker fork and handed to every worker (design §C.6 "tier 1 +
 * coalescer" plane).
 *
 * All are backed by a {@see \Swoole\Table} (shared mmap), so they must be created
 * pre-fork: a table created after fork would be worker-private, breaking the
 * cross-worker reads R3's reverse-index scan and the coalescers' cross-worker
 * `incr` depend on. The worker-local DTO registry (tier 2) is deliberately NOT
 * here — it is a process-static map that each worker owns privately.
 *
 * The {@see ViewChangeCoalescer} (Intended Grid Model · Phase 2) joins the plane:
 * the command intake and the held-open stream are usually on different workers, so
 * its latest-view params slot + pending gate must be cross-worker shared mmap too.
 */
final readonly class TrackRSharedTables
{
    public function __construct(
        public SubscriptionTable $subscriptions,
        public RerunCoalescer $coalescer,
        public ViewChangeCoalescer $viewChangeCoalescer,
    ) {
    }
}
