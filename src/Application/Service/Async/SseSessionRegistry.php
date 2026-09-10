<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Which SSE sessions this worker holds, and the frames waiting for each.
 *
 * Three maps, each keyed by session id, each answering a different question:
 *
 *  - **open sessions** — does this worker own the socket for that session, and
 *    what did the connection capture at connect time;
 *  - **queue** — frames waiting for a session this worker DOES hold, drained by
 *    the held-open loop;
 *  - **buffer** — frames for a session that has not connected here yet, flushed
 *    on admit. The distinction matters: a queued frame has a socket to go to, a
 *    buffered one is speculative.
 *
 * Both frame maps are BOUNDED, because both are worker-local PHP arrays holding
 * rendered HTML. `SSE_SESSION_QUEUE_MAX` (256) caps a session with a socket: on
 * overflow the backlog is replaced by one rerun control, so the client re-queries
 * instead of receiving a stream with a hole in it. `SSE_SESSION_BUFFER_MAX` (64)
 * caps a session with no socket here yet, keeping the NEWEST frames — there is no
 * stream to resync on, and a late connector wants current state. Both log when
 * they fire; neither is a number to raise when it does, since it firing means a
 * consumer is not keeping up and a bigger buffer only postpones that.
 *
 * Keyed per-worker state, and that is correct rather than a coroutine hazard: a
 * coroutine serving session A legitimately pushes into session B's queue — that
 * is how same-worker delivery works — and no entry is ever shared between two
 * connections.
 *
 * The captured tenant is the subtle part. It is resolved in the connecting
 * coroutine, where `TenantContext` is authoritative because `TenancyPhase` ran
 * before route dispatch, and stored here so a multiplex subscribe control can
 * scope its record to the tenant that CONNECTED rather than to whatever ambient
 * tenant the draining coroutine happens to carry.
 */
final class SseSessionRegistry
{
    /**
     * @var array<string, array<string, mixed>> session id → connect-time record.
     *
     * `response` and `connected_at` are written but never read today; they are
     * preserved verbatim rather than dropped, because pruning a record shape is
     * a decision of its own and not something a call-site migration should make
     * on the side.
     */
    private array $sessions = [];

    /** @var array<string, list<array<string, mixed>>> frames awaiting a held socket. */
    private array $queues = [];

    /** @var array<string, list<array<string, mixed>>> frames for a not-yet-connected session. */
    private array $buffers = [];

    /** @var array<string, true> sessions with a demo producer already running. */
    private array $demoProducers = [];

    /**
     * @param mixed $response opaque connect-time handle. Stored verbatim and
     *        never read by this class — typing it would imply a contract the
     *        registry does not have and does not enforce.
     */
    public function open(string $sessionId, mixed $response, string $tenantId, string $tenantBlob): void
    {
        $this->sessions[$sessionId] = [
            'response' => $response,
            'connected_at' => time(),
            'tenant_id' => $tenantId,
            'tenant_blob' => $tenantBlob,
        ];
    }

    public function isOpen(string $sessionId): bool
    {
        return isset($this->sessions[$sessionId]);
    }

    /**
     * The tenant this connection resolved at connect time, or `null` when there
     * is no captured record — in which case the subscription factory falls back
     * to the ambient tenant.
     */
    public function capturedTenantId(string $sessionId): ?string
    {
        $value = $this->sessions[$sessionId]['tenant_id'] ?? null;

        return $value === null ? null : (string) $value;
    }

    public function capturedTenantBlob(string $sessionId): ?string
    {
        $value = $this->sessions[$sessionId]['tenant_blob'] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Create an empty queue for a session that has just connected, so a later
     * `deliver()` on this worker appends rather than deciding the session is
     * unknown and taking the cross-worker path.
     */
    public function ensureQueue(string $sessionId): void
    {
        $this->queues[$sessionId] ??= [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function enqueue(string $sessionId, array $data): void
    {
        $this->queues[$sessionId][] = $data;

        $depth = count($this->queues[$sessionId]);
        if ($depth <= self::queueCap()) {
            return;
        }

        // Backpressure. The queue is a worker-local PHP array and every frame
        // carries rendered HTML, so a consumer that stops reading — a throttled
        // tab, a stalled socket, a laptop that slept — grows worker memory with
        // nothing to stop it until the socket closes.
        //
        // The backlog is replaced by ONE rerun control rather than trimmed.
        // Dropping the oldest frames would leave the client a stream with a hole
        // in it and no way to know; a rerun tells it to re-query, which is the
        // state it would have reached by applying the whole backlog anyway. It
        // is the same collapse RerunCoalescer already performs on signal storms,
        // applied to the data path.
        $this->queues[$sessionId] = [[SseControlFrame::KEY => SseControlFrame::RERUN]];

        StaticLoggerBridge::warning('sse', 'SSE queue overflowed; backlog collapsed to a re-run', [
            'session' => $sessionId,
            'depth' => $depth,
            'cap' => self::queueCap(),
        ]);
    }

    /**
     * How many frames may wait for one session before the backlog collapses.
     *
     * A cap, not a tuning knob to raise when it fires: it firing means a
     * consumer is not keeping up, and a larger number only delays the same
     * outcome while holding more memory.
     */
    private static function queueCap(): int
    {
        return max(1, SseEnv::int('SSE_SESSION_QUEUE_MAX', 256));
    }

    public function hasQueued(string $sessionId): bool
    {
        return ($this->queues[$sessionId] ?? []) !== [];
    }

    /**
     * @return array<string, mixed>|null the next frame, or `null` when empty.
     */
    public function shiftQueued(string $sessionId): ?array
    {
        if (!$this->hasQueued($sessionId)) {
            return null;
        }

        return array_shift($this->queues[$sessionId]);
    }

    /**
     * Read the queue without consuming it — for flushing to Redis on close,
     * where the whole map entry is dropped immediately afterwards anyway.
     *
     * @return list<array<string, mixed>>
     */
    public function queued(string $sessionId): array
    {
        return $this->queues[$sessionId] ?? [];
    }

    /**
     * Take everything queued and drop the queue in one step — the drain-mode
     * flush, where every frame is written and nothing may be left behind.
     *
     * @return list<array<string, mixed>>
     */
    public function takeQueued(string $sessionId): array
    {
        $queued = $this->queues[$sessionId] ?? [];
        unset($this->queues[$sessionId]);

        return $queued;
    }

    /**
     * Hold a frame for a session that has not connected to this worker yet.
     *
     * @param array<string, mixed> $data
     */
    public function buffer(string $sessionId, array $data): void
    {
        $this->buffers[$sessionId][] = $data;

        $depth = count($this->buffers[$sessionId]);
        if ($depth <= self::bufferCap()) {
            return;
        }

        // A buffered frame is speculative: there is no socket for it here, and
        // if the session never connects to THIS worker nothing ever drains it —
        // close() only fires for a session that did connect. So this map could
        // grow for the worker's whole life on the fallback path alone.
        //
        // The oldest go, not the newest: what a late-connecting client most
        // needs is the most recent state. Nothing is resynced because there is
        // no stream to send a control on yet.
        $this->buffers[$sessionId] = array_slice($this->buffers[$sessionId], -self::bufferCap());

        StaticLoggerBridge::warning('sse', 'SSE buffer overflowed for a session that has not connected here', [
            'session' => $sessionId,
            'depth' => $depth,
            'cap' => self::bufferCap(),
        ]);
    }

    /** @see self::queueCap() — same reasoning, for frames with no socket yet. */
    private static function bufferCap(): int
    {
        return max(1, SseEnv::int('SSE_SESSION_BUFFER_MAX', 64));
    }

    /**
     * Read what is buffered without consuming it.
     *
     * @return list<array<string, mixed>>
     */
    public function buffered(string $sessionId): array
    {
        return $this->buffers[$sessionId] ?? [];
    }

    /**
     * Take everything buffered for a session and clear it — called once on
     * admit, so the frames land on the socket in arrival order.
     *
     * @return list<array<string, mixed>>
     */
    public function takeBuffered(string $sessionId): array
    {
        $buffered = $this->buffers[$sessionId] ?? [];
        unset($this->buffers[$sessionId]);

        return $buffered;
    }

    /**
     * Claim the demo producer slot for a session.
     *
     * @return bool `true` when the caller now owns the producer; `false` when one
     *              is already running and the caller must not start a second.
     */
    public function tryStartDemoProducer(string $sessionId): bool
    {
        if (isset($this->demoProducers[$sessionId])) {
            return false;
        }

        $this->demoProducers[$sessionId] = true;

        return true;
    }

    public function stopDemoProducer(string $sessionId): void
    {
        unset($this->demoProducers[$sessionId]);
    }

    /**
     * Forget a session entirely. Callers flush the queue to Redis first when
     * durability matters — this drops whatever is left.
     *
     * The buffer goes too. Leaving it behind would let a reconnect on the same
     * session id drain frames from a connection that is already gone, and a
     * session that never reconnects would keep its buffer for the worker's whole
     * life. Cross-reconnect durability is the Redis queue's job, not this map's.
     */
    public function close(string $sessionId): void
    {
        unset(
            $this->sessions[$sessionId],
            $this->queues[$sessionId],
            $this->buffers[$sessionId],
            $this->demoProducers[$sessionId],
        );
    }
}
