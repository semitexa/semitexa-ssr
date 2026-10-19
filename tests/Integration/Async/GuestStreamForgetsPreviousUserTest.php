<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Integration\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Semitexa\Core\Server\InFlightRequests;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\SseAuthSessionMap;
use Semitexa\Ssr\Application\Service\Async\SseRedisPool;
use Semitexa\Ssr\Application\Service\Async\SseServer;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;
use Semitexa\Ssr\Application\Service\Async\SseWorkerTables;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;

/**
 * REGRESSION (release review). A tab signed in as one user reconnects as a
 * guest on the same SSE session id — its user logged out in another tab. The
 * earlier connection's mapping stayed, and the old user's fan-out reached the
 * guest's stream until the mapping expired. Opening a guest stream drops it.
 * The map-level rule is in SseAuthSessionMapOwnerTest; this proves the server
 * applies it where a stream opens.
 */
#[RequiresPhpExtension('swoole')]
final class GuestStreamForgetsPreviousUserTest extends TestCase
{
    private const SLOTS = ['sessionRegistry', 'workerTables', 'authSessionMap'];

    /** @var array<string, mixed> */
    private array $saved = [];

    private Client $redis;

    private string $sessionId;

    private string $userId;

    protected function setUp(): void
    {
        $host = getenv('REDIS_HOST') ?: '';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        $socket = $host === '' ? false : @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($socket === false) {
            self::markTestSkipped('needs the Redis that REDIS_HOST names, which the auth map connects to');
        }
        fclose($socket);
        $this->redis = new Client(['host' => $host, 'port' => $port, 'password' => getenv('REDIS_PASSWORD') ?: null]);
        $this->sessionId = 'sse-guest-' . bin2hex(random_bytes(4));
        $this->userId = 'user-guest-' . bin2hex(random_bytes(4));
        foreach (self::SLOTS as $name) {
            $this->saved[$name] = (new \ReflectionProperty(SseServer::class, $name))->getValue(AsyncResourceSseServer::instance());
        }
    }

    protected function tearDown(): void
    {
        InFlightRequests::reset();
        foreach ($this->saved as $name => $value) {
            (new \ReflectionProperty(SseServer::class, $name))->setValue(AsyncResourceSseServer::instance(), $value);
        }
        if (isset($this->redis)) {
            $this->redis->srem('semitexa_sse_auth_sessions', $this->sessionId);
            $this->redis->del([
                SseAuthSessionMap::userSessionsKey($this->userId),
                SseAuthSessionMap::sessionUserKey($this->sessionId),
                SseAuthSessionMap::activeSessionKey($this->sessionId),
                SseAuthSessionMap::sessionConnectionKey($this->sessionId),
            ]);
        }
    }

    #[Test]
    public function a_guest_stream_opening_on_the_session_takes_it_out_of_the_previous_users_fan_out(): void
    {
        $server = AsyncResourceSseServer::instance();
        $map = new SseAuthSessionMap(new SseRedisPool());
        $sessions = new Table(64);
        $sessions->column('worker_id', Table::TYPE_INT, 8);
        $sessions->column('connection', Table::TYPE_STRING, 16);
        $sessions->column('opened_seq', Table::TYPE_INT, 8);
        $sessions->create();
        $deliver = new Table(8);
        $deliver->column('payload', Table::TYPE_STRING, 64);
        $deliver->create();
        $tables = new SseWorkerTables();
        $tables->setTables($sessions, $deliver);
        (new \ReflectionProperty(SseServer::class, 'workerTables'))->setValue($server, $tables);
        (new \ReflectionProperty(SseServer::class, 'sessionRegistry'))->setValue($server, new SseSessionRegistry());
        (new \ReflectionProperty(SseServer::class, 'authSessionMap'))->setValue($server, $map);

        // The signed-in connection that came before.
        $map->register($this->sessionId, $this->userId, new \stdClass());
        $map->touch($this->sessionId);
        self::assertSame([$this->sessionId], $map->sessionIdsForUser($this->userId));

        $guest = new Request();
        $guest->cookie = [];
        $opened = (new \ReflectionMethod(SseServer::class, 'openSseStream'))->invoke($server, $guest, $this->createStub(Response::class), $this->sessionId);

        self::assertSame('', $opened, 'the stream opened as a guest');
        self::assertSame([], $map->sessionIdsForUser($this->userId), 'the previous user no longer reaches this stream');
        self::assertSame(1, $this->redis->exists(SseAuthSessionMap::activeSessionKey($this->sessionId)), 'the guest stream is still known to be live');
    }
}
