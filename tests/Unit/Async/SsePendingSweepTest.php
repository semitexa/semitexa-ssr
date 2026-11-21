<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\RedisSubscribeConnectionFactory;
use Semitexa\Ssr\Application\Service\Async\SsePendingSweep;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;
use Semitexa\Ssr\Application\Service\Async\SseWakeSubscriber;
use Semitexa\Ssr\Application\Service\Async\SseWorkerTables;
use Semitexa\Ssr\Tests\Unit\Async\Fixture\CountingSessionQueue;
use Swoole\Table;

/**
 * tk-idle-sse-stream-polls-redis. The 5 s safety poll every stream made for
 * itself (200 passes a second with 1000 idle streams) is one read of the
 * pending set per worker. These pin whom that read wakes and what it removes;
 * that a parked loop then delivers the frame is HeldOpenLoopIdleRedisTest's.
 */
#[RequiresPhpExtension('swoole')]
final class SsePendingSweepTest extends TestCase
{
    #[Test]
    public function it_wakes_only_the_sessions_held_here_and_removes_only_those(): void
    {
        [$sweep, $registry, $queue] = $this->sweep();
        $registry->open('held-here', new \stdClass(), '', '');
        $registry->open('held-here-idle', new \stdClass(), '', '');
        $registry->takeRedisDirty('held-here');
        $registry->takeRedisDirty('held-here-idle');
        // What pushes left in the set: one for a session of ours, one for a
        // session another worker (or another instance) holds.
        $queue->pending = ['held-here', 'held-by-another-worker'];

        self::assertSame(1, $sweep->run());

        self::assertSame(1, $queue->pendingReads, 'one read for every stream the worker holds');
        self::assertTrue($registry->takeRedisDirty('held-here'), 'the pending session was not told to ask Redis');
        self::assertFalse($registry->takeRedisDirty('held-here-idle'), 'a session nothing was queued for was woken');
        self::assertFalse($registry->takeRedisDirty('held-by-another-worker'), 'a stranger was remembered');
        self::assertSame(['held-by-another-worker'], $queue->pending, 'the mark of a session held elsewhere is its own worker\'s to take');
    }

    #[Test]
    public function a_session_a_newer_connection_took_over_elsewhere_is_woken_but_its_mark_is_left(): void
    {
        // This worker still has the replaced connection's entry until its loop
        // stands down; the frames and the mark are the new connection's.
        [$sweep, $registry, $queue, $tables, $otherWorker] = $this->sweep();
        $old = new \stdClass();
        $registry->open('sess-1', $old, '', '');
        $registry->takeRedisDirty('sess-1');
        $tables->recordOwnership('sess-1', $old);
        self::assertTrue($otherWorker->recordOwnership('sess-1', new \stdClass()));
        $queue->pending = ['sess-1'];

        self::assertSame(1, $sweep->run());

        self::assertTrue($registry->takeRedisDirty('sess-1'), 'the superseded loop was not woken to stand down');
        self::assertSame(['sess-1'], $queue->pending, 'the mark was taken from the worker that holds the new connection');
    }

    #[Test]
    public function a_set_that_cannot_be_read_wakes_every_session(): void
    {
        // Nothing is known, so every loop asks for itself (and, while Redis
        // keeps refusing, retries on its tick): the answer is never "nobody".
        [$sweep, $registry, $queue] = $this->sweep();
        $registry->open('sess-1', new \stdClass(), '', '');
        $registry->open('sess-2', new \stdClass(), '', '');
        $registry->takeRedisDirty('sess-1');
        $registry->takeRedisDirty('sess-2');
        $queue->failPendingRead = true;

        self::assertSame(2, $sweep->run());

        self::assertTrue($registry->takeRedisDirty('sess-1'));
        self::assertTrue($registry->takeRedisDirty('sess-2'));
    }

    #[Test]
    public function it_reads_the_set_once_an_interval_however_often_the_timer_ticks(): void
    {
        [$sweep, , $queue] = $this->sweep(intervalSeconds: 0.2);

        $sweep->runIfDue();
        $sweep->runIfDue();
        $sweep->runIfDue();
        self::assertSame(1, $queue->pendingReads);

        usleep(220_000);
        $sweep->runIfDue();
        $sweep->runIfDue();
        self::assertSame(2, $queue->pendingReads);
    }

    #[Test]
    public function the_interval_is_five_seconds(): void
    {
        // The most a lost wake costs, as the per-stream poll it replaces did.
        self::assertSame(5.0, SsePendingSweep::INTERVAL_SECONDS);
    }

    #[Test]
    public function the_subscriber_sweeps_only_while_its_subscription_is_healthy(): void
    {
        // Unhealthy, every loop polls on its tick and nobody trusts a wake.
        [$sweep, $registry, $queue] = $this->sweep(intervalSeconds: 0.0);
        $subscriber = new SseWakeSubscriber($registry, new RedisSubscribeConnectionFactory(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 1, 'password' => '']), null, null, $sweep);
        $registry->open('sess-1', new \stdClass(), '', '');
        $queue->pending = ['sess-1'];

        self::assertSame(0, $subscriber->sweepPendingIfDue());
        self::assertSame(0, $queue->pendingReads);

        $subscriber->onSubscribed();

        self::assertSame(1, $subscriber->sweepPendingIfDue());
        self::assertSame([], $queue->pending);
    }

    /** @return array{SsePendingSweep, SseSessionRegistry, CountingSessionQueue, SseWorkerTables, SseWorkerTables} */
    private function sweep(float $intervalSeconds = SsePendingSweep::INTERVAL_SECONDS): array
    {
        $sessions = new Table(64);
        $sessions->column('worker_id', Table::TYPE_INT, 8);
        $sessions->column('connection', Table::TYPE_STRING, 16);
        $sessions->column('opened_seq', Table::TYPE_INT, 8);
        $sessions->create();
        $tables = new SseWorkerTables();
        $tables->setTables($sessions, $sessions);
        $tables->setServer($this->workerServer(0));
        $otherWorker = new SseWorkerTables();
        $otherWorker->setTables($sessions, $sessions);
        $otherWorker->setServer($this->workerServer(1));
        $registry = new SseSessionRegistry();
        $queue = new CountingSessionQueue();

        return [new SsePendingSweep($registry, $queue, $tables, $intervalSeconds), $registry, $queue, $tables, $otherWorker];
    }

    private function workerServer(int $workerId): \Swoole\Http\Server
    {
        $server = $this->createStub(\Swoole\Http\Server::class);
        $server->method('getWorkerId')->willReturn($workerId);

        return $server;
    }
}
