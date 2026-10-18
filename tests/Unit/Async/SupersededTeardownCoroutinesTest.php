<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
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
 * connection's must not.
 */
#[RequiresPhpExtension('swoole')]
final class SupersededTeardownCoroutinesTest extends TestCase
{
    private const SLOTS = ['sessionCoroutines', 'sessionRegistry', 'workerTables'];

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
        $sessions->column('opened_at_ms', Table::TYPE_INT, 8);
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
}
