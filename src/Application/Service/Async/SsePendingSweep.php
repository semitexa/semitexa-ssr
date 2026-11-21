<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Ssr\Domain\Contract\SseSessionQueueInterface;

/**
 * The worker's answer to a lost wake: one read of the pending set every few
 * seconds, for all the streams the worker holds.
 *
 * WHY (tk-idle-sse-stream-polls-redis). A wake is a publish and can be lost,
 * so each held stream used to ask Redis on its own every 5 s whatever it had
 * heard. With 1000 idle streams that was 200 passes a second, each a pop, and
 * most of what an idle worker still did. The frame's session id is now also in
 * a set ({@see SseSessionQueueInterface::pendingSessions()}), added by the push
 * itself; this reads it ONCE per worker and wakes only the sessions held here.
 * A lost wake still costs at most one interval, and an idle stream does
 * nothing at all between its heartbeats.
 *
 * WHO IS WOKEN, AND WHAT IS REMOVED:
 *  - a session this worker does not hold is left alone and left in the set:
 *    its own worker (or another instance) takes it;
 *  - a session held here AND owned here is removed from the set, then woken.
 *    Removed first: a frame pushed before the removal is found by the pop the
 *    wake causes, and one pushed after it marks the session again;
 *  - a session held here but owned by a newer connection elsewhere is woken
 *    (its loop stands down) and its mark NOT removed: that belongs to the
 *    worker that holds the new connection. It is woken whether or not it is
 *    in the set: the new holder's sweep may have run first and taken the
 *    takeover's mark, and this worker would then never see it (review of
 *    semitexa-ssr#192). So every sweep asks the shared ownership table about
 *    each session held here, one row read apiece and no Redis;
 *  - a session taken over (or closed) WHILE its mark was being removed is
 *    marked again and its wake published: the removal is Redis I/O, and the
 *    mark it took may have been the new connection's (review of semitexa-ssr#192).
 *    A mark that could not be put back (Redis refused it) is remembered and
 *    put back first by the next sweeps, until it is or until its bucket would
 *    have expired anyway: unmarked, with its wake lost, the frame would wait
 *    for the session's next wake, which an idle stream may never get;
 *  - when the set cannot be read, nothing is known, so every session is woken:
 *    each asks Redis itself and, while that keeps failing, retries on its tick.
 *
 * Driven by {@see SseWakeSubscriber}'s per-worker timer, and only while its
 * subscription is healthy — without one every loop is polling anyway. It adds
 * no coroutine and no timer of its own.
 */
final class SsePendingSweep
{
    /** The most a lost wake costs. */
    public const INTERVAL_SECONDS = 5.0;

    private float $lastRunAt = 0.0;

    /**
     * Sessions whose mark this worker removed and could not put back, with
     * when that first failed. Bounded by the sessions that changed hands
     * during a removal, and each entry by the mark's own lifetime.
     *
     * @var array<string, float>
     */
    private array $unrestored = [];

    /** A sweep is Redis I/O and yields; the timer must not start a second one over it. */
    private bool $running = false;

    public function __construct(
        private readonly SseSessionRegistry $sessions,
        private readonly SseSessionQueueInterface $queue,
        private readonly SseWorkerTables $tables,
        private readonly float $intervalSeconds = self::INTERVAL_SECONDS,
    ) {
    }

    /**
     * Sweep when the interval has passed since the last one started.
     *
     * @return int how many sessions were woken
     */
    public function runIfDue(): int
    {
        $now = microtime(true);
        if ($this->running || ($now - $this->lastRunAt) < $this->intervalSeconds) {
            return 0;
        }
        $this->lastRunAt = $now;

        return $this->run();
    }

    /** @return int how many sessions were woken */
    public function run(): int
    {
        $this->running = true;
        try {
            foreach (array_keys($this->unrestored) as $sessionId) {
                $this->restore((string) $sessionId);
            }

            $pending = $this->queue->pendingSessions();
            if ($pending === null) {
                return $this->sessions->wakeAll();
            }

            $owned = [];
            foreach ($pending as $sessionId) {
                if ($this->sessions->isOpen($sessionId) && $this->tables->ownedHere($sessionId)) {
                    $owned[] = $sessionId;
                }
            }

            $this->queue->clearPending($owned);
            // The removal yielded. A session that changed hands meanwhile gets its mark back, for its new holder's sweep.
            foreach ($owned as $sessionId) {
                if (!$this->sessions->isOpen($sessionId) || !$this->tables->ownedHere($sessionId)) {
                    $this->restore($sessionId);
                }
            }

            $woken = 0;
            foreach ($owned as $sessionId) {
                // One that changed hands during the removal is the pass below's.
                $woken += $this->tables->ownedHere($sessionId) && $this->sessions->wake($sessionId) ? 1 : 0;
            }

            // And every connection here that a newer one replaced elsewhere, marked or not: it stands down.
            return $woken + $this->sessions->wakeAll(fn (string $sessionId): bool => !$this->tables->ownedHere($sessionId));
        } finally {
            $this->running = false;
        }
    }

    /**
     * Put back the mark of a session that is no longer this worker's, and
     * remember it when that fails. A session that has meanwhile come back
     * here needs no mark: it is woken, which is what the mark was for.
     */
    private function restore(string $sessionId): void
    {
        $since = $this->unrestored[$sessionId] ??= microtime(true);
        $back = $this->sessions->isOpen($sessionId) && $this->tables->ownedHere($sessionId) && $this->sessions->wake($sessionId);
        // Past two bucket spans the mark would have expired wherever it was.
        $expired = (microtime(true) - $since) > 2 * SseRedisSessionQueue::PENDING_BUCKET_SECONDS;
        if ($back || $expired || $this->queue->publishWake($sessionId)) {
            unset($this->unrestored[$sessionId]);
        }
    }
}
