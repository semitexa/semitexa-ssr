<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\SseAuthSessionMap;
use Semitexa\Ssr\Application\Service\Async\SseRedisPool;
use Semitexa\Ssr\Application\Service\Async\SseRedisSessionQueue;
use Semitexa\Ssr\Application\Service\Async\SseServer;
use Semitexa\Ssr\Application\Service\Async\SseSessionCoroutines;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;
use Semitexa\Ssr\Application\Service\Async\SseWorkerTables;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Response;
use Swoole\Table;

/**
 * A same-worker reconnect replaces the session's registry entry before the old
 * connection's teardown runs. That teardown no longer owns the local state, yet
 * its own coroutines (a deferred live loop runs while the SESSION is active,
 * and the new connection keeps it active) must still stop — and the new
 * connection's must not. A plain close is exposed the same way while its
 * requeue waits on Redis: a reconnect landing then is not its to tear down.
 */
#[RequiresPhpExtension('swoole')]
final class SupersededTeardownCoroutinesTest extends TestCase
{
    private const SLOTS = ['sessionCoroutines', 'sessionRegistry', 'workerTables', 'redisSessionQueue', 'authSessionMap'];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::SLOTS as $name) {
            $this->saved[$name] = (new \ReflectionProperty(SseServer::class, $name))->getValue(AsyncResourceSseServer::instance());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            (new \ReflectionProperty(SseServer::class, $name))->setValue(AsyncResourceSseServer::instance(), $value);
        }
    }

    #[Test]
    public function a_same_worker_superseded_teardown_cancels_only_the_old_connections_coroutines(): void
    {
        $server = AsyncResourceSseServer::instance();
        $sessions = new Table(64);
        $sessions->column('worker_id', Table::TYPE_INT, 8);
        $sessions->column('connection', Table::TYPE_STRING, 16);
        // verify:accept-test-change connection order is an SseOrder number now, not milliseconds (tk-sse-teardown-ownership-sequence)
        $sessions->column('opened_seq', Table::TYPE_INT, 8);
        $sessions->create();
        $deliver = new Table(8);
        $deliver->column('payload', Table::TYPE_STRING, 64);
        $deliver->create();
        $tables = new SseWorkerTables();
        $tables->setTables($sessions, $deliver);
        $registry = new SseSessionRegistry();
        $tracker = new SseSessionCoroutines();
        (new \ReflectionProperty(SseServer::class, 'workerTables'))->setValue($server, $tables);
        (new \ReflectionProperty(SseServer::class, 'sessionRegistry'))->setValue($server, $registry);
        (new \ReflectionProperty(SseServer::class, 'sessionCoroutines'))->setValue($server, $tracker);
        $closeSession = new \ReflectionMethod(SseServer::class, 'closeSession');

        $old = $this->createStub(Response::class);
        $new = $this->createStub(Response::class);
        $oldStopped = false;
        $newAlive = false;

        Coroutine\run(function () use ($server, $tables, $registry, $tracker, $closeSession, $old, $new, &$oldStopped, &$newAlive): void {
            $registry->open('sse_s', $old, '', '');
            $tables->recordOwnership('sse_s', $old);
            $oldEnded = new Channel(1);
            $tracker->create(static function () use ($oldEnded): void {
                try {
                    for (;;) {
                        Coroutine::sleep(0.005);
                    }
                } finally {
                    $oldEnded->push(true);
                }
            }, 'sse_s');
            Coroutine::sleep(0.01);

            // The page reconnects on this same worker.
            $registry->open('sse_s', $new, '', '');
            $tables->recordOwnership('sse_s', $new);
            $newCid = $tracker->create(static function (): void {
                for (;;) {
                    Coroutine::sleep(0.005);
                }
            }, 'sse_s');

            $closeSession->invoke($server, 'sse_s', $old);

            $oldStopped = $oldEnded->pop(1.0) === true;
            $newAlive = in_array($newCid, $tracker->idsFor('sse_s'), true);
            self::assertTrue($registry->isOpenWith('sse_s', $new), 'the new connection keeps its registry entry');
            $tracker->cancelFor('sse_s');
        });

        self::assertTrue($oldStopped, 'the old connection\'s live loop stopped');
        self::assertTrue($newAlive, 'the new connection\'s coroutine still runs and is still tracked');
    }

    #[Test]
    public function a_reconnect_during_a_plain_closes_requeue_keeps_its_entry_and_its_coroutines(): void
    {
        $server = AsyncResourceSseServer::instance();
        $sessions = new Table(64);
        $sessions->column('worker_id', Table::TYPE_INT, 8);
        $sessions->column('connection', Table::TYPE_STRING, 16);
        // verify:accept-test-change connection order is an SseOrder number now, not milliseconds (tk-sse-teardown-ownership-sequence)
        $sessions->column('opened_seq', Table::TYPE_INT, 8);
        $sessions->create();
        $deliver = new Table(8);
        $deliver->column('payload', Table::TYPE_STRING, 64);
        $deliver->create();
        $tables = new SseWorkerTables();
        $tables->setTables($sessions, $deliver);
        $registry = new SseSessionRegistry();
        $tracker = new SseSessionCoroutines();
        (new \ReflectionProperty(SseServer::class, 'workerTables'))->setValue($server, $tables);
        (new \ReflectionProperty(SseServer::class, 'sessionRegistry'))->setValue($server, $registry);
        (new \ReflectionProperty(SseServer::class, 'sessionCoroutines'))->setValue($server, $tracker);
        $closeSession = new \ReflectionMethod(SseServer::class, 'closeSession');

        $old = $this->createStub(Response::class);
        $new = $this->createStub(Response::class);
        $kept = false;
        $newTracked = false;
        $inherited = null;

        Coroutine\run(function () use ($server, $tables, $registry, $tracker, $closeSession, $old, $new, &$kept, &$newTracked, &$inherited): void {
            // A one-connection Redis pool whose only client is out: the requeue
            // waits on the borrow, which is the yield a reconnect can land in.
            // The auth map gets a client at once, so its unregister never yields.
            $borrow = new Channel(1);
            $ready = new Channel(1);
            $ready->push(self::redisClient());
            (new \ReflectionProperty(SseServer::class, 'redisSessionQueue'))->setValue($server, new SseRedisSessionQueue(self::ssePool($borrow)));
            (new \ReflectionProperty(SseServer::class, 'authSessionMap'))->setValue($server, new SseAuthSessionMap(self::ssePool($ready)));

            $registry->open('sse_s', $old, '', '');
            $tables->recordOwnership('sse_s', $old);
            $registry->enqueue('sse_s', ['type' => 'ui.patch']);

            $closed = new Channel(1);
            Coroutine::create(static function () use ($closeSession, $server, $old, $closed): void {
                $closeSession->invoke($server, 'sse_s', $old);
                $closed->push(true);
            });
            Coroutine::sleep(0.01);
            self::assertTrue($closed->isEmpty(), 'the close is waiting on the requeue');

            // The page reconnects on this same worker while the requeue waits.
            $registry->open('sse_s', $new, '', '');
            $tables->recordOwnership('sse_s', $new);
            $stop = false;
            $newCid = $tracker->create(static function () use (&$stop): void {
                while (!$stop) {
                    Coroutine::sleep(0.005);
                }
            }, 'sse_s');
            $borrow->push(self::redisClient());

            self::assertTrue($closed->pop(1.0) === true, 'the close finished');
            $kept = $registry->isOpenWith('sse_s', $new);
            $inherited = $registry->queued('sse_s');
            $newTracked = in_array($newCid, $tracker->idsFor('sse_s'), true);
            // A flag, not cancelFor: an untracked coroutine is exactly the failure, and cancelFor would miss it.
            $stop = true;
        });

        self::assertTrue($kept, 'the reconnect keeps its registry entry');
        self::assertTrue($newTracked, 'the reconnect\'s coroutine is still tracked');
        self::assertSame([], $inherited, 'the frame went to Redis only, not into the reconnect\'s queue as well');
    }

    private static function ssePool(Channel $clients): SseRedisPool
    {
        $redis = new RedisConnectionPool(1, ['host' => '127.0.0.1']);
        (new \ReflectionProperty(RedisConnectionPool::class, 'pool'))->setValue($redis, $clients);
        $pool = new SseRedisPool();
        (new \ReflectionProperty(SseRedisPool::class, 'pool'))->setValue($pool, $redis);

        return $pool;
    }

    /** Answers every command with 1 and never touches a socket. */
    private static function redisClient(): Client
    {
        return new class extends Client {
            public function __construct()
            {
            }

            public function __call($commandID, $arguments): int
            {
                return 1;
            }
        };
    }
}
