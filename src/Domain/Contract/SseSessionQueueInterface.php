<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

/**
 * The session-addressed frame queue the SSE server writes to and drains: what
 * another worker (or another instance) queued for a session whose socket is
 * held here.
 *
 * A contract because the store behind it,
 * {@see \Semitexa\Ssr\Application\Service\Async\SseRedisSessionQueue}, is final
 * and talks to Redis: the held-open loop's use of it (how often it pops, what a
 * wake does) is provable only with a counting double in its place.
 */
interface SseSessionQueueInterface
{
    public function isAvailable(): bool;

    /**
     * Durability hook: flush undelivered worker-local payloads to the store on
     * disconnect, so a reconnecting subscriber (possibly on another worker)
     * drains them. A no-op without a store — in the single-server fallback the
     * payloads are dropped, the pre-existing best-effort guarantee.
     *
     * @param list<mixed> $payloads
     */
    public function push(string $sessionId, array $payloads): void;

    /**
     * Queue one frame for a session this worker does not hold.
     *
     * @param array<string, mixed> $data
     * @return bool false when the store did not take it and the caller must fall back
     */
    public function tryPush(string $sessionId, array $data): bool;

    /** Return one already-encoded frame the socket rejected after it was popped. */
    public function pushRaw(string $sessionId, string $raw): void;

    /**
     * @return array{ok: bool, raw: string|null} `ok=false`: the store failed, stop
     *         draining; `ok=true` with a null `raw`: the queue is empty.
     */
    public function pop(string $sessionId): array;

    /**
     * Tell whichever worker holds the session's socket to look at it now: a
     * frame was queued for it, or a newer connection took the session over.
     * Lossy by design — the frame is in the queue either way.
     */
    public function publishWake(string $sessionId): void;

    public function publishInvalidation(string $channel): void;
}
