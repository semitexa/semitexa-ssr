<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Environment;
use Semitexa\Core\Redis\RedisConnectionPool;

/**
 * Resolves the SSE side's Redis connection pool, once per worker.
 *
 * Its own class rather than a member of the queue because more than one
 * collaborator needs it — the session queue, the authenticated-session map, and
 * the scope-invalidation publish all share this pool. Hanging it off whichever
 * one happened to be extracted first would have made the others depend on an
 * unrelated peer.
 *
 * **Size 1, deliberately.** A blocking `SUBSCRIBE` must never borrow from here:
 * it would occupy the single connection for the lifetime of the subscription and
 * starve every other Redis user on the worker. That is why
 * {@see RedisSubscribeConnectionFactory} builds its own dedicated connections
 * instead, a separation `ResourceInvalidationSubscriberTest` pins by asserting
 * neither class so much as names a pool.
 *
 * Resolution is **fail-soft**: no `REDIS_HOST` means `null`, and every caller
 * treats `null` as "durability unavailable, carry on in-memory". SSE keeps
 * working without Redis; it just loses cross-worker queue durability.
 */
final class SseRedisPool
{
    private ?RedisConnectionPool $pool = null;

    public function get(): ?RedisConnectionPool
    {
        if ($this->pool instanceof RedisConnectionPool) {
            return $this->pool;
        }

        $redisHost = Environment::getEnvValue('REDIS_HOST');
        if ($redisHost === null || $redisHost === '') {
            return null;
        }

        return $this->pool = new RedisConnectionPool(1, [
            'scheme' => (string) (Environment::getEnvValue('REDIS_SCHEME', 'tcp') ?? 'tcp'),
            'host' => $redisHost,
            'port' => (int) (Environment::getEnvValue('REDIS_PORT', '6379') ?? '6379'),
            'password' => (string) (Environment::getEnvValue('REDIS_PASSWORD', '') ?? ''),
        ]);
    }

    public function isAvailable(): bool
    {
        return $this->get() !== null;
    }
}
