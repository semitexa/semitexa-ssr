<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Integration\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Semitexa\Ssr\Application\Service\Async\SseAuthSessionMap;
use Semitexa\Ssr\Application\Service\Async\SseRedisPool;

/**
 * A reconnect reuses the session id. The previous connection's teardown can
 * unregister after the new one registered, and used to erase it: the new
 * stream stayed open but left the auth index, so a fan-out to its user skipped
 * it until the next refresh. Against a real Redis, because the owner check and
 * the deletes are one Lua script, and a stand-in would test the stand-in.
 */
final class SseAuthSessionMapOwnerTest extends TestCase
{
    private Client $redis;
    private SseAuthSessionMap $map;
    private string $sessionId;
    private string $userId;

    protected function setUp(): void
    {
        // The same settings the map's pool reads, or a pass could be the map failing soft.
        $host = getenv('REDIS_HOST') ?: '';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        $socket = $host === '' ? false : @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($socket === false) {
            self::markTestSkipped('needs the Redis that REDIS_HOST names, which the auth map connects to');
        }
        fclose($socket);

        $this->redis = new Client(['host' => $host, 'port' => $port, 'password' => getenv('REDIS_PASSWORD') ?: null]);
        $this->map = new SseAuthSessionMap(new SseRedisPool());
        $this->sessionId = 'sse-owner-' . bin2hex(random_bytes(4));
        $this->userId = 'user-owner-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!isset($this->redis)) {
            return;
        }
        $this->redis->srem('semitexa_sse_auth_sessions', $this->sessionId);
        $this->redis->del([
            SseAuthSessionMap::userSessionsKey($this->userId),
            SseAuthSessionMap::sessionUserKey($this->sessionId),
            SseAuthSessionMap::activeSessionKey($this->sessionId),
            SseAuthSessionMap::sessionConnectionKey($this->sessionId),
        ]);
    }

    #[Test]
    public function a_late_unregister_of_the_previous_connection_leaves_the_reconnect_registered(): void
    {
        $old = new \stdClass();
        $new = new \stdClass();

        $this->map->register($this->sessionId, $this->userId, $old);
        $this->map->register($this->sessionId, $this->userId, $new);
        $this->map->touch($this->sessionId);
        self::assertFalse($this->map->unregister($this->sessionId, $old));

        self::assertSame([$this->sessionId], $this->map->sessionIdsForUser($this->userId));
        self::assertContains($this->sessionId, $this->map->allSessionIds());
    }

    #[Test]
    public function the_previous_connections_refresh_does_not_take_the_session_back(): void
    {
        // Another node: its loop cannot see the reconnect and refreshes on.
        $old = new \stdClass();
        $new = new \stdClass();
        $this->map->register($this->sessionId, $this->userId, $old);
        $this->map->register($this->sessionId, $this->userId, $new);
        $this->map->touch($this->sessionId);

        self::assertFalse($this->map->register($this->sessionId, $this->userId, $old, claim: false));
        self::assertFalse($this->map->unregister($this->sessionId, $old));

        self::assertSame([$this->sessionId], $this->map->sessionIdsForUser($this->userId));
    }

    #[Test]
    public function the_connection_that_registered_last_unregisters_everything(): void
    {
        $connection = new \stdClass();
        $this->map->register($this->sessionId, $this->userId, $connection);
        $this->map->touch($this->sessionId);
        self::assertSame([$this->sessionId], $this->map->sessionIdsForUser($this->userId));

        $this->map->unregister($this->sessionId, $connection);

        self::assertSame([], $this->map->sessionIdsForUser($this->userId));
        self::assertSame(0, $this->redis->sismember('semitexa_sse_auth_sessions', $this->sessionId));
        self::assertSame(0, $this->redis->exists(
            SseAuthSessionMap::sessionUserKey($this->sessionId),
            SseAuthSessionMap::activeSessionKey($this->sessionId),
            SseAuthSessionMap::sessionConnectionKey($this->sessionId),
        ));
    }

    #[Test]
    public function an_unregister_naming_no_connection_removes_the_session_whoever_registered_it(): void
    {
        $this->map->register($this->sessionId, $this->userId, new \stdClass());
        $this->map->touch($this->sessionId);
        self::assertSame([$this->sessionId], $this->map->sessionIdsForUser($this->userId));

        self::assertTrue($this->map->unregister($this->sessionId));

        self::assertSame([], $this->map->sessionIdsForUser($this->userId));
        self::assertSame(0, $this->redis->exists(SseAuthSessionMap::sessionUserKey($this->sessionId)));
    }
}
