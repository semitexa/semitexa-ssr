<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

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
     */
    public function close(string $sessionId): void
    {
        unset($this->sessions[$sessionId], $this->queues[$sessionId], $this->demoProducers[$sessionId]);
    }
}
