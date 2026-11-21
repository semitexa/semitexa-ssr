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

    /**
     * Review of semitexa-ssr#192. Ownership is read before the removal, and
     * the removal is Redis I/O: a reconnect on another worker in that window
     * drains, a frame is pushed for it (marked; its wake lost), and this
     * worker's removal then took that mark with nobody left to find the frame.
     */
    #[Test]
    public function a_session_taken_over_while_its_mark_was_being_removed_gets_the_mark_back(): void
    {
        [$sweep, $registry, $queue, $tables, $otherWorker] = $this->sweep();
        $old = new \stdClass();
        $registry->open('sess-1', $old, '', '');
        $registry->open('sess-2', new \stdClass(), '', '');
        $registry->takeRedisDirty('sess-2');
        $tables->recordOwnership('sess-1', $old);
        $queue->pending = ['sess-1', 'sess-2'];
        $queue->duringClearPending = static function () use ($otherWorker): void {
            $otherWorker->recordOwnership('sess-1', new \stdClass());
        };

        self::assertSame(2, $sweep->run());

        self::assertSame(['sess-1'], $queue->pending, 'the mark of a session that changed hands during the removal was lost, or a kept session\'s mark was put back');
        self::assertSame(['sess-1'], $queue->wakes, 'the new holder was not told');
        self::assertTrue($registry->takeRedisDirty('sess-2'));
    }

    /**
     * Follow-up review of semitexa-ssr#192. Putting the mark back is Redis
     * I/O too and can be refused; the mark was then gone for good, and a frame
     * whose own wake was lost waited for the session's next wake, which an
     * idle stream may never get.
     */
    #[Test]
    public function a_mark_that_could_not_be_put_back_is_put_back_by_the_next_sweep(): void
    {
        [$sweep, $registry, $queue, $tables, $otherWorker] = $this->sweep();
        $old = new \stdClass();
        $registry->open('sess-1', $old, '', '');
        $tables->recordOwnership('sess-1', $old);
        $queue->pending = ['sess-1'];
        $queue->duringClearPending = static function () use ($otherWorker): void {
            $otherWorker->recordOwnership('sess-1', new \stdClass());
        };
        $queue->failNextWakes = 2;

        $sweep->run();
        self::assertSame([], $queue->pending, 'the refused restore did mark the session: the test proves nothing');
        $queue->duringClearPending = null;

        $sweep->run();
        self::assertSame([], $queue->pending, 'the second refusal did mark the session');

        $sweep->run();
        self::assertSame(['sess-1'], $queue->pending, 'a mark that could not be put back was never put back');
        self::assertSame(['sess-1'], $queue->wakes, 'the new holder was not told once the restore went through');

        // Restored, it is the new holder's: this worker neither takes it again nor keeps retrying.
        $sweep->run();
        self::assertSame(['sess-1'], $queue->pending);
        self::assertSame(['sess-1'], $queue->wakes, 'a restore that went through was repeated');
    }

    #[Test]
    public function a_session_that_came_back_before_its_mark_could_be_restored_is_woken_instead(): void
    {
        [$sweep, $registry, $queue, $tables, $otherWorker] = $this->sweep();
        $old = new \stdClass();
        $registry->open('sess-1', $old, '', '');
        $tables->recordOwnership('sess-1', $old);
        $queue->pending = ['sess-1'];
        $queue->duringClearPending = static function () use ($otherWorker): void {
            $otherWorker->recordOwnership('sess-1', new \stdClass());
        };
        $queue->failNextWakes = 1;
        $sweep->run();
        $queue->duringClearPending = null;
        $registry->takeRedisDirty('sess-1');

        // The page reconnects here again before the next sweep.
        $back = new \stdClass();
        $registry->open('sess-1', $back, '', '');
        $tables->recordOwnership('sess-1', $back);
        $registry->takeRedisDirty('sess-1');

        $sweep->run();

        self::assertTrue($registry->takeRedisDirty('sess-1'), 'the session whose mark was lost is held here again and was not told to ask Redis');
        self::assertSame([], $queue->wakes, 'a mark was published for a session this worker holds');
    }

    /**
     * Review of semitexa-ssr#192. A takeover marks the session so that the
     * replaced connection's worker finds it if the wake was lost; but the new
     * holder's sweep, running first, took that mark, and the replaced loop
     * stayed until its heartbeat.
     */
    #[Test]
    public function a_replaced_connection_stands_down_even_when_the_new_holder_swept_the_takeover_mark_first(): void
    {
        [$sweep, $registry, $queue, $tables, $otherWorker] = $this->sweep();
        $old = new \stdClass();
        $registry->open('sess-1', $old, '', '');
        $registry->open('sess-mine', new \stdClass(), '', '');
        $tables->recordOwnership('sess-1', $old);
        $tables->recordOwnership('sess-mine', new \stdClass());
        $registry->takeRedisDirty('sess-1');
        $registry->takeRedisDirty('sess-mine');
        // The page reconnected on worker 1, whose sweep has already taken the takeover's mark.
        self::assertTrue($otherWorker->recordOwnership('sess-1', new \stdClass()));
        $queue->pending = [];

        self::assertSame(1, $sweep->run());

        self::assertTrue($registry->takeRedisDirty('sess-1'), 'the replaced connection\'s loop was left parked until its heartbeat');
        self::assertFalse($registry->takeRedisDirty('sess-mine'), 'a session this worker still owns was woken with nothing pending');
        self::assertSame([], $queue->wakes, 'nothing here needs a mark');
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
