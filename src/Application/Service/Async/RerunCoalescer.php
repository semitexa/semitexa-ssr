<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Swoole\Table;

/**
 * Track R · R3 — the per-stream re-run coalescer (the idempotency invariant,
 * design §C.3 "idempotent + lossy-tolerant").
 *
 * The scope-invalidation signal is data-less and lossy-tolerant: it may arrive
 * more than once (a reconnecting subscriber, overlapping subscriptions, or N
 * rapid mutations of the same resource), and — because one coroutine subscribes
 * per worker on a shared cross-worker subscription store — the SAME logical
 * signal can be resolved to the SAME stream by more than one worker's subscriber.
 * A naive "find → deliver" would then RPUSH N `{__ctrl:rerun}` controls for one
 * stream and trigger a re-run storm. This collapses those duplicates to ONE
 * pending re-run per stream.
 *
 * The mechanism is a single atomic {@see \Swoole\Table::incr()} on a `pending`
 * counter keyed by `streaming_id`:
 *  - `requestRerun()` increments and returns `true` only for the caller that
 *    bumped the counter 0→1 (the first signal for that stream while none is
 *    pending). Every subsequent signal sees a value > 1 and returns `false` —
 *    its `{__ctrl:rerun}` is coalesced into the already-enqueued one.
 *  - `incr` is atomic across workers and coroutines (row-level), so the collapse
 *    holds even when two workers' subscribers race on the same stream — no
 *    {@see \Swoole\Lock} needed.
 *
 * The pending mark is cleared by the loop branch (R4) when it DRAINS the
 * `{__ctrl:rerun}` control off the stream's queue and runs the re-run — at which
 * point the next mutation's signal is free to enqueue a fresh re-run.
 * {@see self::clearPending()} is that R4 seam; R3 never calls it (R3 only sets the
 * mark). Until R4 lands, a test simulates the drain by calling it directly.
 *
 * R3 is the subscriber + routing ONLY: this coalescer decides whether to enqueue,
 * it does not execute the re-run.
 */
final class RerunCoalescer
{
    /**
     * The complete schema: a single integer `pending` counter per stream. The
     * absence of any DTO/object column keeps this tier serializable and
     * cross-worker, consistent with the R1 tier-separation invariant.
     *
     * @var array<string, int>
     */
    private const COLUMNS = [
        'pending' => 8,
    ];

    public function __construct(
        private readonly Table $table,
    ) {}

    /**
     * Build a real, created {@see \Swoole\Table} for the pending-rerun counters
     * and wrap it. The single place the schema is materialised, so a server
     * lifecycle listener (cross-worker shared mmap, created before worker fork)
     * and a unit test stand up byte-identical coalescers.
     */
    public static function create(int $maxRows): self
    {
        $table = new Table($maxRows);
        $table->column('pending', Table::TYPE_INT, self::COLUMNS['pending']);
        $table->create();

        return new self($table);
    }

    /**
     * Atomically record a re-run request for `$streamingId`.
     *
     * @return bool TRUE only for the signal that transitions the stream from
     *              "no pending re-run" to "one pending re-run" (counter 0→1) —
     *              the caller that should enqueue exactly one `{__ctrl:rerun}`.
     *              FALSE when a re-run is already pending — the duplicate is
     *              coalesced and MUST NOT enqueue another control.
     */
    public function requestRerun(string $streamingId): bool
    {
        // incr creates the row at `pending = 1` when absent, else returns the
        // incremented value. Atomic (row-level) across workers and coroutines.
        $pending = (int) $this->table->incr($this->key($streamingId), 'pending', 1);

        return $pending === 1;
    }

    /**
     * Clear the pending mark for `$streamingId` — the R4 seam. Called by the loop
     * branch once it has drained and run the `{__ctrl:rerun}` control, so a later
     * mutation's signal can enqueue a fresh re-run. R3 itself never calls this.
     */
    public function clearPending(string $streamingId): void
    {
        $this->table->del($this->key($streamingId));
    }

    public function isPending(string $streamingId): bool
    {
        return $this->table->exist($this->key($streamingId));
    }

    public function count(): int
    {
        return count($this->table);
    }

    /**
     * Mirror the existing session-key discipline
     * ({@see AsyncResourceSseServer::sessionTableKey()} / {@see SubscriptionTable})
     * so an over-long streaming_id is hashed rather than truncated.
     */
    private function key(string $streamingId): string
    {
        return strlen($streamingId) > 63 ? md5($streamingId) : $streamingId;
    }
}
