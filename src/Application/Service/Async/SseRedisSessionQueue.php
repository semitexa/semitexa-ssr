<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Predis\Client;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Redis\RedisCircuitBreaker;
use Semitexa\Core\Redis\RedisUnavailableException;

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
 */
final class SseRedisSessionQueue
{
    private const KEY_PREFIX = 'semitexa_sse_queue:';
    private const TTL_SECONDS = 7200;

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
            });
        } catch (\Throwable $e) {
            self::reportFailure('Redis SSE enqueue failed', $e, ['session_id' => $sessionId]);

            return false;
        }

        return true;
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
