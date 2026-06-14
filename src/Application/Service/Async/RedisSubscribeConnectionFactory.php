<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Predis\Client;
use Semitexa\Core\Environment;

/**
 * Track R · R3 — vends the DEDICATED Predis connection the subscriber's blocking
 * `pubSubLoop` owns (the HARD invariant, design §C.3 / §Dependencies 5).
 *
 * A blocking SUBSCRIBE parks its connection for the lifetime of the subscription.
 * If it borrowed the size-1 SSE pool ({@see AsyncResourceSseServer::getRedisPool()}),
 * that single pooled connection would be held open forever and every other Redis
 * op on the worker (`deliver`'s rpush, durability requeue, session reads, the P3
 * PUBLISH) would starve. So the subscriber MUST own its connection.
 *
 * This factory is the structural enforcement of that rule: it ONLY ever does
 * `new Client(...)` — a brand-new, exclusive TCP connection per call — and has no
 * reference whatsoever to {@see \Semitexa\Core\Redis\RedisConnectionPool} or to
 * the server's pool. Two calls therefore return two DISTINCT clients (a pool
 * would hand back the same parked connection), which is the assertable proof that
 * the blocking loop is not on the shared pool.
 *
 * Config is resolved from the SAME environment the SSE pool uses
 * ({@see AsyncResourceSseServer::getRedisPool()}), so the dedicated connection
 * talks to the same Redis — only its lifetime (exclusive, long-lived) differs.
 */
final class RedisSubscribeConnectionFactory
{
    /**
     * @param array{scheme:string, host:string, port:int, password:string} $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * Build a factory from the ambient Redis environment, mirroring
     * {@see AsyncResourceSseServer::getRedisPool()}'s resolution. Returns null
     * when no Redis is configured (single-server / in-memory mode) — there is no
     * cross-instance push to subscribe to, so no subscriber is started.
     */
    public static function fromEnvironment(): ?self
    {
        $host = Environment::getEnvValue('REDIS_HOST');
        if ($host === null || $host === '') {
            return null;
        }

        return new self([
            'scheme' => (string) (Environment::getEnvValue('REDIS_SCHEME', 'tcp') ?? 'tcp'),
            'host' => $host,
            'port' => (int) (Environment::getEnvValue('REDIS_PORT', '6379') ?? '6379'),
            'password' => (string) (Environment::getEnvValue('REDIS_PASSWORD', '') ?? ''),
        ]);
    }

    /**
     * Create a FRESH, dedicated Predis connection for a blocking subscribe loop.
     * Never borrowed from and never returned to the SSE pool: this connection is
     * the subscriber's alone for the lifetime of its `pubSubLoop`.
     */
    public function create(): Client
    {
        $params = [
            'scheme' => $this->config['scheme'],
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            // Track R · Gap C — a parked blocking SUBSCRIBE must NEVER idle-timeout.
            // Without this, Predis inherits PHP's default_socket_timeout (~60s) and
            // the dedicated pubSubLoop read throws ConnectionException ("Error while
            // reading line from the server") after ~60s of no invalidations, killing
            // the subscriber loop. -1 = block indefinitely (the correct lifetime for
            // a subscriber connection, which is meant to be parked).
            'read_write_timeout' => -1,
        ];

        if ($this->config['password'] !== '') {
            $params['password'] = $this->config['password'];
        }

        return new Client($params);
    }
}
