<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Ssr\Application\Service\Async\RerunCoalescer;
use Semitexa\Ssr\Application\Service\Async\SubscriptionTable;
use Semitexa\Ssr\Application\Service\Async\ViewChangeCoalescer;
use Swoole\Table;

/**
 * Track R · R5 (C2) — create the two cross-worker shared store surfaces ONCE,
 * before worker fork (the R3-flagged coalescer lifecycle wiring; design §C.6).
 *
 * Runs in the {@see ServerLifecyclePhase::PreStart} phase — the pre-fork point the
 * sibling {@see CreateAsyncResourceSseTablesListener} uses — so the
 * {@see SubscriptionTable} (tier 1) and the {@see RerunCoalescer} are created in
 * the master process and inherited as shared mmap by every worker. Creating them
 * after fork would make each worker's table private, breaking the cross-worker
 * reverse-index scan (R3) and the cross-worker `incr` coalescing (R3).
 *
 * The tables are built via each class's `create()` factory — the SINGLE place each
 * schema is materialised (like `SubscriptionTable::create`), so this listener and
 * the unit tests stand up byte-identical stores. R3 explicitly deferred the
 * coalescer's lifecycle wiring to R5; this is that wiring, alongside R1's
 * tier-1 table, the two surfaces of the cross-worker plane.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::PreStart->value,
    priority: -10,
    requiresContainer: false,
)]
final class CreateTrackRTablesListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        if ($context->bootstrapState === null || !class_exists(Table::class, false)) {
            return;
        }

        // One subscription row + at most one pending-rerun counter per live stream,
        // so the deliver-table capacity is the right upper bound for both.
        $maxRows = $context->environment->swooleSseDeliverTableSize;

        $context->bootstrapState->set(
            SsrBootstrapStateKey::TRACK_R_SHARED_TABLES,
            self::buildSharedTables($maxRows),
        );
    }

    /**
     * Materialise the two cross-worker tables through their single-schema-site
     * factories. Extracted so the pre-fork creation is provable without standing up
     * a live {@see \Swoole\Http\Server} (the synthetic R5 proof, design §D R5).
     */
    public static function buildSharedTables(int $maxRows): TrackRSharedTables
    {
        return new TrackRSharedTables(
            subscriptions: SubscriptionTable::create($maxRows),
            coalescer: RerunCoalescer::create($maxRows),
            // Phase 2 — same upper bound (at most one pending view-change per stream).
            viewChangeCoalescer: ViewChangeCoalescer::create($maxRows),
        );
    }
}
