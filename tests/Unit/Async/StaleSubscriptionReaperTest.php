<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\RerunCoalescer;
use Semitexa\Ssr\Application\Service\Async\SubscriptionTable;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\ReapStaleSubscriptionsListener;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * Stream Lifecycle · Axis 2, Phase 1 — the crashed-worker orphan reaper.
 *
 * Proves the ONE residual leak (a tier-1 {@see SubscriptionTable} row orphaned by a
 * hard worker crash) is closed by the age-based sweep, while a LIVE stream (a row
 * within the age+grace window) is NEVER touched — the decisive contract. Runs over a
 * REAL {@see \Swoole\Table}; `$now` is injected so the age criterion is deterministic
 * with no live server. Insert always stamps `connected_at = time()`, so a future
 * `$now` simulates an aged row (age = now − connected_at).
 */
final class StaleSubscriptionReaperTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Swoole\Table::class, false)) {
            self::markTestSkipped('Swoole extension not loaded.');
        }
    }

    // -----------------------------------------------------------------------
    // Proof 1 — the orphan IS reaped (the leak closed)
    // -----------------------------------------------------------------------

    #[Test]
    public function tableReapsRowsOlderThanCapPlusGraceAndReturnsTheirStreamingIds(): void
    {
        $table = SubscriptionTable::create(64);
        $table->insert($this->record('sse_orphan', 'sess_orphan', 'default', ['leads']));

        $now = time();
        $maxAge = 660; // 600s cap + 60s grace

        // Within the window → untouched.
        self::assertSame([], $table->reapStaleConnections($maxAge, $now + $maxAge));
        self::assertTrue($table->has('sse_orphan'), 'a row at exactly cap+grace is NOT reaped');

        // One second past cap+grace → reaped, the streaming_id returned.
        $evicted = $table->reapStaleConnections($maxAge, $now + $maxAge + 1);
        self::assertSame(['sse_orphan'], $evicted);
        self::assertFalse($table->has('sse_orphan'), 'the crash orphan is evicted');
    }

    #[Test]
    public function sweeperEvictsTheOrphanRowAndClearsItsCoalescerPendingMark(): void
    {
        $table = SubscriptionTable::create(64);
        $coalescer = RerunCoalescer::create(64);

        $table->insert($this->record('sse_orphan', 'sess_orphan', 'default', ['leads']));
        // A re-run was coalesced (pending) when the worker crashed — the dead worker's
        // onDisconnect never cleared it. The sweep is the backstop that does.
        $coalescer->requestRerun('sse_orphan');
        self::assertTrue($coalescer->isPending('sse_orphan'));

        $now = time();
        $reaped = ReapStaleSubscriptionsListener::sweep($table, $coalescer, 660, $now + 700);

        self::assertSame(1, $reaped);
        self::assertFalse($table->has('sse_orphan'), 'tier-1 orphan row evicted');
        self::assertFalse($coalescer->isPending('sse_orphan'), 'coupled coalescer pending mark cleared');
    }

    // -----------------------------------------------------------------------
    // Proof 2 — a LIVE stream is NOT touched (the decisive guarantee)
    // -----------------------------------------------------------------------

    #[Test]
    public function aLiveStreamWithinTheAgeWindowIsNeverSwept(): void
    {
        $table = SubscriptionTable::create(64);
        $coalescer = RerunCoalescer::create(64);

        // A healthy held-open stream: just connected, plus one mid-life (300s old) — both
        // well inside the loop's 600s cap, so both are still served by their own loops.
        $table->insert($this->record('sse_fresh', 'sess_fresh', 'default', ['leads']));
        $coalescer->requestRerun('sse_fresh'); // a legitimately pending re-run on a live stream

        $now = time();

        // Fresh stream (age 0) and a 300s-old stream are both within cap+grace.
        self::assertSame(0, ReapStaleSubscriptionsListener::sweep($table, $coalescer, 660, $now));
        self::assertSame(0, ReapStaleSubscriptionsListener::sweep($table, $coalescer, 660, $now + 300));

        self::assertTrue($table->has('sse_fresh'), 'a live stream is never swept');
        self::assertTrue($coalescer->isPending('sse_fresh'), 'a live stream keeps its pending re-run');
    }

    #[Test]
    public function liveAndOrphanRowsAreSeparatedByConnectedAtInOneSweep(): void
    {
        $table = SubscriptionTable::create(64);
        $coalescer = RerunCoalescer::create(64);
        $now = time();

        // Seed two rows with explicit, distinct connect epochs: one 100s old (a live
        // stream, well inside the loop cap), one 1000s old (a crash orphan past
        // cap+grace). A single sweep must split them PURELY by connected_at.
        $this->seedRow($table, 'sse_live', $now - 100);
        $this->seedRow($table, 'sse_orphan', $now - 1000);

        $reaped = ReapStaleSubscriptionsListener::sweep($table, $coalescer, 660, $now);

        self::assertSame(1, $reaped, 'only the row past cap+grace is reaped');
        self::assertTrue($table->has('sse_live'), 'the 100s-old live stream survives');
        self::assertFalse($table->has('sse_orphan'), 'the 1000s-old orphan is evicted');
    }

    // -----------------------------------------------------------------------
    // Proof 3 — age-based, no liveness probe (threshold strictly beyond the cap)
    // -----------------------------------------------------------------------

    #[Test]
    public function staleThresholdIsCapPlusGraceStrictlyBeyondTheLoopCap(): void
    {
        // Age-based ONLY: the threshold is the loop's own 600s cap + a 60s grace.
        // No pid/liveness probe — Swoole reuses worker_id on crash-restart, so a
        // liveness check would be unreliable (see ReapStaleSubscriptionsListener).
        $threshold = ReapStaleSubscriptionsListener::staleThresholdSeconds();

        self::assertGreaterThan(600, $threshold, 'must be strictly beyond the loop age cap');
        self::assertSame(660, $threshold, 'default 600s cap + 60s grace');
    }

    #[Test]
    public function unagedRowIsNeverReaped(): void
    {
        // A row with no positive connected_at cannot be proven an orphan, so the sweep
        // leaves it alone — the contract is to never reap a possibly-live stream.
        $table = SubscriptionTable::create(64);
        $this->seedRow($table, 'sse_unaged', 0);

        self::assertSame([], $table->reapStaleConnections(660, time() + 100000));
        self::assertTrue($table->has('sse_unaged'), 'an un-aged row is never reaped');
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    /**
     * @param list<string> $scopeKeys
     */
    private function record(
        string $streamingId,
        string $sessionId,
        string $tenantId,
        array $scopeKeys,
        string $tenantBlob = '',
    ): SubscriptionRecord {
        return new SubscriptionRecord($streamingId, $sessionId, $tenantId, $scopeKeys, $tenantBlob);
    }

    /**
     * Seed a tier-1 row with an EXPLICIT connect epoch by reaching the underlying
     * {@see \Swoole\Table} — the only way to stand up a row at a chosen age, since
     * insert() stamps the wall clock. Used to drive the age criterion deterministically.
     */
    private function seedRow(SubscriptionTable $table, string $streamingId, int $connectedAt): void
    {
        $raw = (new \ReflectionProperty(SubscriptionTable::class, 'table'))->getValue($table);
        $raw->set($streamingId, [
            'streaming_id' => $streamingId,
            'session_id' => 'sess_' . $streamingId,
            'tenant_id' => 'default',
            'scope_keys' => '[]',
            'tenant_blob' => '',
            'connected_at' => $connectedAt,
        ]);
    }
}
