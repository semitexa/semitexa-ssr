<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Predis\Client;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Redis\RedisCircuitBreaker;
use Semitexa\Core\Redis\RedisUnavailableException;
use Semitexa\Ssr\Domain\Contract\SseSessionQueueInterface;

/**
 * The Redis-backed durability store for a session's undelivered SSE frames.
 *
 * When a socket closes before the loop drained its queue, the pending payloads
 * are pushed here so a reconnecting subscriber can pick them up — possibly on a
 * different worker, which is the whole point of putting them in Redis rather
 * than a worker-local array.
 *
 * Strictly **storage**. It encodes, pushes, pops and expires; it does not decide
 * what a popped frame means. The drain loop that interprets control markers,
 * writes to the socket and decides whether the stream closes stays with the
 * server for now and moves with `tk-sse-control-router` — dragging it in here
 * would have made a queue depend on the control vocabulary and on the wire.
 *
 * Every operation is **fail-soft**: no pool, or a Redis error, is logged and
 * swallowed rather than propagated. While Redis is down the core pool's
 * circuit breaker fails these calls fast and logs the outage once; a call it
 * refused is not logged again here (see reportFailure()). Durability is a best-effort enhancement —
 * losing it must never take a live stream down with it. The one asymmetry worth
 * knowing: a failed pop returns "stop draining" rather than "queue empty", so a
 * Redis outage mid-drain ends the drain instead of spinning on the error.
 *
 * Every push is followed by a **wake**: the session id published on
 * {@see self::WAKE_CHANNEL}, which the owning worker's {@see SseWakeSubscriber}
 * turns into an immediate pop. It is what lets an idle stream stop asking Redis
 * five times a second whether anything arrived (tk-idle-sse-stream-polls-redis).
 * The wake is lossy and the frame is not: a publish that fails leaves the frame
 * in the list, and the session's id in the **pending set** every push adds it
 * to before the wake. Each worker reads that set once every few seconds
 * ({@see SsePendingSweep}) and wakes the sessions it holds, so a lost wake costs
 * one sweep interval and no stream has to ask on its own.
 */
final class SseRedisSessionQueue implements SseSessionQueueInterface
{
    /** One channel for every session: the payload is the session id. */
    public const WAKE_CHANNEL = 'semitexa_sse_wake';

    private const KEY_PREFIX = 'semitexa_sse_queue:';
    private const TTL_SECONDS = 7200;

    /**
     * The pending set is one Redis set per time bucket, because a set member
     * cannot expire on its own: a session nobody holds any more (its frames
     * wait for a reconnect, which drains at connect time) would stay in a
     * single set for ever. A bucket's key expires as a whole, two spans after
     * its last add, and a sweep reads the current bucket and the one before.
     */
    public const PENDING_BUCKET_SECONDS = 300;

    private const PENDING_KEY_PREFIX = 'semitexa_sse_pending:';

    public function __construct(
        private readonly SseRedisPool $pool,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->pool->isAvailable();
    }

    public static function key(string $sessionId): string
    {
        return self::KEY_PREFIX . trim($sessionId);
    }

    /**
     * JSON-encode a worker-local queue for Redis, skipping anything that is not
     * an encodable array. A frame that cannot be encoded is dropped rather than
     * failing the whole flush — one poisoned payload must not cost a session
     * every other pending frame.
     *
     * @param list<mixed> $queue
     * @return list<string>
     */
    public static function encode(array $queue): array
    {
        $encoded = [];
        foreach ($queue as $data) {
            if (!is_array($data)) {
                continue;
            }

            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($payload)) {
                $encoded[] = $payload;
            }
        }

        return $encoded;
    }

    /**
     * Flush a worker-local queue to Redis on disconnect.
     *
     * @param list<mixed> $payloads
     */
    public function push(string $sessionId, array $payloads): void
    {
        $encoded = self::encode($payloads);
        if ($encoded === []) {
            return;
        }

        $this->write($sessionId, $encoded, 'Redis SSE durability requeue failed', ['count' => count($encoded)]);
    }

    /**
     * Enqueue one frame for a session this worker does NOT hold, reporting
     * whether Redis actually took it.
     *
     * The boolean matters: this is the cross-worker delivery path, and the
     * caller falls through to the Swoole-table fallback on `false`. Every
     * failure mode — no pool, an unencodable payload, a Redis error — must
     * therefore report `false` rather than silently claiming delivery, or the
     * frame is lost instead of being retried down the fallback chain.
     *
     * @param array<string, mixed> $data
     */
    public function tryPush(string $sessionId, array $data): bool
    {
        $pool = $this->pool->get();
        // A refused call reports false so the caller falls back to the table.
        if ($pool === null) {
            return false;
        }

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return false;
        }

        try {
            $pool->withConnection(static function ($redis) use ($sessionId, $payload): void {
                /** @var Client $redis */
                $queueKey = self::key($sessionId);
                $redis->rpush($queueKey, [$payload]);
                $redis->expire($queueKey, self::TTL_SECONDS);
                self::markPending($redis, $sessionId);
                self::wake($redis, $sessionId);
            });
        } catch (\Throwable $e) {
            self::reportFailure('Redis SSE enqueue failed', $e, ['session_id' => $sessionId]);

            return false;
        }

        return true;
    }

    /**
     * Wake the session's loop with nothing queued — a newer connection took the
     * session over, and the one it replaced should notice now. Marked pending
     * as a push is, so a lost wake costs that loop one sweep interval too.
     */
    public function publishWake(string $sessionId): bool
    {
        $sessionId = trim($sessionId);
        $pool = $sessionId === '' ? null : $this->pool->get();
        if ($pool === null) {
            return false;
        }

        try {
            $pool->withConnection(static function ($redis) use ($sessionId): void {
                /** @var Client $redis */
                self::markPending($redis, $sessionId);
                self::wake($redis, $sessionId);
            });
        } catch (\Throwable $e) {
            self::reportFailure('Redis SSE wake publish failed', $e, ['session_id' => $sessionId]);

            return false;
        }

        return true;
    }

    /**
     * Publish the wake that follows a push, on the connection that made it.
     *
     * Never throws. The frame is already in the list when this runs, so a
     * failure here must not read as a failed push: {@see self::tryPush()} would
     * report false, the caller would queue the same frame on the fallback path
     * as well, and the client would get it twice. A lost wake costs one
     * sweep interval ({@see SsePendingSweep}) and nothing else.
     */
    private static function wake(Client $redis, string $sessionId): void
    {
        try {
            $redis->publish(self::WAKE_CHANNEL, trim($sessionId));
        } catch (\Throwable $e) {
            self::reportFailure('Redis SSE wake publish failed', $e, ['session_id' => $sessionId]);
        }
    }

    private static function pendingKey(int $bucket): string
    {
        return self::PENDING_KEY_PREFIX . $bucket;
    }

    /**
     * Add the session to the pending set — after the frame is in the list and
     * before the wake, in that order: a sweep that takes the mark then finds
     * the frame, whenever it runs.
     *
     * Throws, unlike {@see self::wake()}. An unmarked frame whose wake is also
     * lost would have nothing left to find it, so a push that could not mark
     * reports failure and {@see self::tryPush()}'s caller takes the fallback
     * path: at worst the frame arrives twice, never not at all.
     */
    private static function markPending(Client $redis, string $sessionId): void
    {
        $key = self::pendingKey(intdiv(time(), self::PENDING_BUCKET_SECONDS));
        $redis->sadd($key, [trim($sessionId)]);
        $redis->expire($key, 2 * self::PENDING_BUCKET_SECONDS);
    }

    /** @return list<string> the keys a sweep reads: the current bucket and the one before */
    private static function pendingKeys(): array
    {
        $bucket = intdiv(time(), self::PENDING_BUCKET_SECONDS);

        return [self::pendingKey($bucket), self::pendingKey($bucket - 1)];
    }

    public function pendingSessions(): ?array
    {
        $pool = $this->pool->get();
        if ($pool === null) {
            return null;
        }

        try {
            /** @var list<string> $sessions */
            $sessions = $pool->withConnection(static function ($redis): array {
                /** @var Client $redis */
                $found = [];
                foreach (self::pendingKeys() as $key) {
                    foreach ($redis->smembers($key) as $sessionId) {
                        $found[(string) $sessionId] = true;
                    }
                }

                return array_map(strval(...), array_keys($found));
            });
        } catch (\Throwable $e) {
            self::reportFailure('Redis SSE pending-set read failed', $e);

            return null;
        }

        return $sessions;
    }

    public function clearPending(array $sessionIds): void
    {
        $pool = $sessionIds === [] ? null : $this->pool->get();
        if ($pool === null) {
            return;
        }

        try {
            $pool->withConnection(static function ($redis) use ($sessionIds): void {
                /** @var Client $redis */
                $keys = self::pendingKeys();
                foreach ($keys as $key) {
                    $redis->srem($key, $sessionIds);
                }
            });
        } catch (\Throwable $e) {
            // The marks stay: the next sweep wakes these sessions once more.
            self::reportFailure('Redis SSE pending-set clear failed', $e, ['count' => count($sessionIds)]);
        }
    }

    /**
     * Return a single already-encoded frame to the head of the queue — used when
     * the socket rejected a frame that had already been popped, so it is not
     * lost between the pop and the failed write.
     */
    public function pushRaw(string $sessionId, string $raw): void
    {
        $this->write($sessionId, [$raw], 'Redis SSE requeue failed');
    }

    /**
     * Pop the next encoded frame.
     *
     * @return array{ok: bool, raw: string|null} `ok=false` means Redis failed and
     *         the caller should stop draining; `ok=true` with a null `raw` means
     *         the queue is simply empty.
     */
    public function pop(string $sessionId): array
    {
        $pool = $this->pool->get();
        if ($pool === null) {
            return ['ok' => false, 'raw' => null];
        }

        try {
            $raw = $pool->withConnection(static function ($redis) use ($sessionId): ?string {
                /** @var Client $redis */
                $value = $redis->lpop(self::key($sessionId));

                return is_string($value) && $value !== '' ? $value : null;
            });
        } catch (\Throwable $e) {
            self::reportFailure('Redis SSE dequeue failed', $e, ['session_id' => $sessionId]);

            return ['ok' => false, 'raw' => null];
        }

        return ['ok' => true, 'raw' => is_string($raw) ? $raw : null];
    }

    /**
     * Signal every worker subscribed to a scope that it should re-run. The
     * payload is empty on purpose — the channel name IS the message, and the
     * signal is idempotent and lossy-tolerant (see {@see RerunCoalescer}).
     */
    public function publishInvalidation(string $channel): void
    {
        $channel = trim($channel);
        if ($channel === '') {
            return;
        }

        $pool = $this->pool->get();
        if ($pool === null) {
            return;
        }

        try {
            $pool->withConnection(static function ($redis) use ($channel): void {
                /** @var Client $redis */
                $redis->publish($channel, '');
            });
        } catch (\Throwable $e) {
            self::reportFailure('Redis SSE scope-invalidation publish failed', $e, ['channel' => $channel]);

            return;
        }
    }

    /**
     * @param list<string> $encoded
     * @param array<string, mixed> $logContext
     */
    private function write(string $sessionId, array $encoded, string $errorMessage, array $logContext = []): void
    {
        $pool = $this->pool->get();
        if ($pool === null) {
            return;
        }

        try {
            $pool->withConnection(static function ($redis) use ($sessionId, $encoded): void {
                /** @var Client $redis */
                $queueKey = self::key($sessionId);
                $redis->rpush($queueKey, $encoded);
                $redis->expire($queueKey, self::TTL_SECONDS);
                self::markPending($redis, $sessionId);
                self::wake($redis, $sessionId);
            });
        } catch (\Throwable $e) {
            self::reportFailure($errorMessage, $e, $logContext + ['session_id' => $sessionId]);

            return;
        }
    }

    /**
     * Log a Redis failure — unless the core pool's circuit breaker refused the
     * call. It already logged the outage once when it opened and will log the
     * recovery; repeating it here, from every open session every 0.2 s tick,
     * is how one outage became 1174 lines (606 in one second, 2026-10-14).
     * Nor a call the exit drain cancelled: that failure is the drain's own.
     *
     * @param array<string, mixed> $context
     */
    public static function reportFailure(string $message, \Throwable $e, array $context = []): void
    {
        if ($e instanceof RedisUnavailableException) {
            return;
        }
        // A leaving worker cancels the SSE loops parked on Redis, and each read
        // then fails as if the connection were lost. Not a failure to report
        // (a real one, in a request the drain spares, still is):
        // logged as errors, they were the burst the leaving worker's logger was
        // still writing when it was killed at max_wait_time (the 72h soak, 2026-10-19).
        if (RedisCircuitBreaker::cancelledByDrain($e)) {
            return;
        }
        StaticLoggerBridge::error('ssr', $message, $context + [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
