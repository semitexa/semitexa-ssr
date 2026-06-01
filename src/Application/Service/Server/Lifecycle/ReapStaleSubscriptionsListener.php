<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\RerunCoalescer;
use Semitexa\Ssr\Application\Service\Async\SubscriptionTable;
use Swoole\Table;
use Swoole\Timer;

/**
 * Stream Lifecycle · Axis 2, Phase 1 — the crashed-worker orphan reaper (the ONE
 * residual leak the lifecycle diagnostic found, design §5).
 *
 * Client drops are already bounded: the held-open loop reaps in a `finally` within
 * ~20s (heartbeat write-failure) and force-closes at the 600s
 * `SSE_MAX_CONNECTION_AGE_SECONDS` cap, with per-IP/global concurrency caps. The
 * un-covered path is a HARD WORKER CRASH: the `finally` never runs, worker-local
 * tiers die with the worker (no leak) and Redis tiers carry TTLs, but the tier-1
 * {@see SubscriptionTable} row is shared-mmap with no TTL — so it lingers as an
 * orphan. This per-worker {@see Timer::tick} sweep is that backstop, modelled
 * exactly on {@see \Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry}'s
 * GC timer.
 *
 * AGE-BASED, NO LIVENESS PROBE (the decisive design choice, §5.3): the staleness
 * threshold is the loop's own age cap PLUS a grace margin, so a row past it cannot
 * belong to a live stream — a healthy stream is force-closed by its own loop at the
 * cap and reaped by its `finally`; only a crash-orphan survives. A pid/worker-status
 * check would be UNRELIABLE here because Swoole restarts a crashed worker under the
 * SAME `worker_id`, so it could false-positive on a row now owned by the restarted
 * worker. Age + grace is the robust, zero-false-positive criterion.
 *
 * SCOPE — tier-1 only. The sweep evicts the orphaned {@see SubscriptionTable} row
 * and clears the matching {@see RerunCoalescer} pending mark (both cross-worker
 * shared mmap, reachable without a live connection — exactly what the dead worker's
 * {@see \Semitexa\Ssr\Application\Service\Async\ConnectCoordinator::onDisconnect()}
 * would have cleared). The tier-2 worker-local re-run state already died with the
 * crashed worker (unreachable AND gone), and channel reconcile is driven off the
 * shared table by live workers' own subscribers — the sweeper neither owns nor
 * touches a live `Response`. This is the "no zombie" guarantee extended to the crash
 * case. It is additive: the normal drop path's `onDisconnect`/`finally` reaping is
 * unchanged.
 *
 * Runs at {@see ServerLifecyclePhase::WorkerStartFinalize} (the worker has its event
 * loop; `Timer::tick` is safe — unlike a CLI/pre-fork context that trips the Swoole
 * reactor-shutdown deprecation notice). The tier-1 table itself is created pre-fork
 * ({@see CreateTrackRTablesListener}, shared mmap); this timer runs per worker over
 * that shared table.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartFinalize->value,
    priority: 10,
    requiresContainer: false,
)]
final class ReapStaleSubscriptionsListener implements ServerLifecycleListenerInterface
{
    /** Sweep cadence — the orphan is rare and not latency-sensitive (GC precedent). */
    private const SWEEP_INTERVAL_SECONDS = 60;

    /**
     * Grace beyond the loop's age cap. A row is only swept once it is older than
     * cap+grace, strictly beyond the point the loop's own 600s cap would already
     * have force-closed + reaped a live stream — so a healthy held-open stream is
     * NEVER swept.
     */
    private const SWEEP_GRACE_SECONDS = 60;

    /** Fallback cap when the loop's own cap is disabled (`SSE_MAX_CONNECTION_AGE_SECONDS=0`). */
    private const FALLBACK_MAX_CONNECTION_AGE_SECONDS = 600;

    /** Per-worker timer id, so a re-run of this phase does not arm a second sweep. */
    private static int $timerId = 0;

    public function handle(ServerLifecycleContext $context): void
    {
        if (!class_exists(Table::class, false) || !class_exists(Timer::class, false)) {
            return;
        }

        $tables = $context->bootstrapState?->get(SsrBootstrapStateKey::TRACK_R_SHARED_TABLES);
        if (!$tables instanceof TrackRSharedTables) {
            StaticLoggerBridge::debug('ssr', 'sse_orphan_reaper_unwired', [
                'reason' => 'no pre-fork shared tables (Swoole Table unavailable)',
            ]);
            return;
        }

        if (self::$timerId !== 0) {
            return; // already armed in this worker
        }

        $subscriptions = $tables->subscriptions;
        $coalescer = $tables->coalescer;
        $maxAgeSeconds = self::staleThresholdSeconds();

        self::$timerId = Timer::tick(
            self::SWEEP_INTERVAL_SECONDS * 1000,
            static function () use ($subscriptions, $coalescer, $maxAgeSeconds): void {
                self::sweep($subscriptions, $coalescer, $maxAgeSeconds, time());
            },
        );

        StaticLoggerBridge::debug('ssr', 'sse_orphan_reaper_armed', [
            'interval_seconds' => self::SWEEP_INTERVAL_SECONDS,
            'stale_after_seconds' => $maxAgeSeconds,
        ]);
    }

    /**
     * One sweep pass: evict crash-orphaned tier-1 rows older than `$maxAgeSeconds`
     * before `$now`, then clear each evicted stream's coalescer pending mark (the
     * directly-coupled cross-worker state the dead worker's `onDisconnect` never
     * cleared). Returns the number of rows reaped. Static + `$now`-injectable so the
     * age criterion is unit-testable with no live server.
     */
    public static function sweep(
        SubscriptionTable $subscriptions,
        RerunCoalescer $coalescer,
        int $maxAgeSeconds,
        int $now,
    ): int {
        $evicted = $subscriptions->reapStaleConnections($maxAgeSeconds, $now);

        foreach ($evicted as $streamingId) {
            $coalescer->clearPending($streamingId);
        }

        if ($evicted !== []) {
            StaticLoggerBridge::debug('ssr', 'sse_orphan_subscriptions_reaped', [
                'count' => count($evicted),
                'stale_after_seconds' => $maxAgeSeconds,
            ]);
        }

        return count($evicted);
    }

    /**
     * The cap+grace staleness threshold: the held-open loop's own
     * `SSE_MAX_CONNECTION_AGE_SECONDS` cap plus {@see self::SWEEP_GRACE_SECONDS},
     * derived from {@see AsyncResourceSseServer::maxConnectionAgeSeconds()} so the
     * sweeper and the loop share one source of truth for the cap. A disabled loop
     * cap (`0`) falls back to {@see self::FALLBACK_MAX_CONNECTION_AGE_SECONDS} so the
     * crash-orphan backstop still bounds the row rather than never reaping it.
     */
    public static function staleThresholdSeconds(): int
    {
        $maxAge = AsyncResourceSseServer::maxConnectionAgeSeconds();
        if ($maxAge <= 0) {
            $maxAge = self::FALLBACK_MAX_CONNECTION_AGE_SECONDS;
        }

        return $maxAge + self::SWEEP_GRACE_SECONDS;
    }

    /** Test/worker-stop hygiene: clear the per-worker timer. */
    public static function reset(): void
    {
        if (self::$timerId !== 0 && class_exists(Timer::class, false)) {
            Timer::clear(self::$timerId);
        }
        self::$timerId = 0;
    }
}
