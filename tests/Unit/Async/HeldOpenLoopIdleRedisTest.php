<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Lifecycle\WorkerDrainSignal;
use Semitexa\Core\Server\InFlightRequests;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\RedisSubscribeConnectionFactory;
use Semitexa\Ssr\Application\Service\Async\SsePendingSweep;
use Semitexa\Ssr\Application\Service\Async\SseRuntime;
use Semitexa\Ssr\Application\Service\Async\SseServer;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;
use Semitexa\Ssr\Application\Service\Async\SseWakeSubscriber;
use Semitexa\Ssr\Application\Service\Async\SseWorkerTables;
use Semitexa\Ssr\Application\Service\Async\SwooleSseTransport;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredBindCookie;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Domain\Contract\SseSessionQueueInterface;
use Semitexa\Ssr\Tests\Unit\Async\Fixture\CountingSessionQueue;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;

/**
 * tk-idle-sse-stream-polls-redis. An idle held-open stream asked Redis for its
 * frames on every 0.2 s tick: 1000 idle streams were 5000 LPOPs a second and
 * ~88% of a container's CPU with nothing to send (measured on a stand, core
 * 2026.11.20.0947). With a live wake subscriber the loop now parks until one of
 * its own deadlines and pops when a wake says a frame is there.
 *
 * The Redis side is a counting double behind {@see SseSessionQueueInterface},
 * and the subscriber is driven through its own seams ({@see SseWakeSubscriber::onSubscribed()},
 * {@see SseWakeSubscriber::onMessage()}) — no connection is opened here.
 */
#[RequiresPhpExtension('swoole')]
final class HeldOpenLoopIdleRedisTest extends TestCase
{
    private const SLOTS = ['sessionRegistry', 'workerTables', 'redisSessionQueue', 'runtime'];

    /** @var array<string, mixed> */
    private array $saved = [];

    /** The drain signal as this class found it: process-wide state, restored and not reset. */
    private bool $wasDraining = false;

    /** @var array<int, true> the in-flight request set as this class found it */
    private array $inFlightBefore = [];

    /** Worker 1's view of the tables {@see wiredServer()} gives worker 0. */
    private SseWorkerTables $otherWorker;

    /** The deliver table of {@see wiredServer()}; its `scans` counts the full iterations made of it. */
    private Table $deliverTable;

    protected function setUp(): void
    {
        foreach (self::SLOTS as $name) {
            $this->saved[$name] = (new \ReflectionProperty(SseServer::class, $name))->getValue(AsyncResourceSseServer::instance());
        }
        // Snapshot first, then the state these tests need: a worker that is not draining.
        $this->wasDraining = WorkerDrainSignal::isDraining();
        /** @var array<int, true> $inFlight */
        $inFlight = (new \ReflectionProperty(InFlightRequests::class, 'cids'))->getValue();
        $this->inFlightBefore = $inFlight;
        WorkerDrainSignal::reset();
    }

    protected function tearDown(): void
    {
        $this->wasDraining ? WorkerDrainSignal::begin() : WorkerDrainSignal::reset();
        (new \ReflectionProperty(InFlightRequests::class, 'cids'))->setValue(null, $this->inFlightBefore);
        foreach ($this->saved as $name => $value) {
            (new \ReflectionProperty(SseServer::class, $name))->setValue(AsyncResourceSseServer::instance(), $value);
        }
    }

    #[Test]
    public function an_idle_stream_asks_redis_once_in_a_second_not_on_every_tick(): void
    {
        [$server, $registry, , $queue, $subscriber, $loop] = $this->wiredServer();
        $subscriber->onSubscribed();
        $response = $this->createStub(Response::class);
        $response->method('write')->willReturn(true);
        $request = $this->createStub(Request::class);
        $popsWhileIdle = null;

        Coroutine\run(function () use ($server, $registry, $queue, $loop, $response, $request, &$popsWhileIdle): void {
            $registry->open('sse_idle', $response, '', '');
            $ended = new Channel(1);
            Coroutine::create(static function () use ($server, $loop, $response, $request, $ended): void {
                $loop->invoke($server, 'sse_idle', $request, $response, '');
                $ended->push(true);
            });

            Coroutine::sleep(1.0);
            $popsWhileIdle = $queue->pops;

            $registry->close('sse_idle');
            $ended->pop(2.0);
        });

        // The one pop is the loop's first pass: a session opens dirty, so whatever
        // was queued between its connect-time drain and the loop is picked up.
        self::assertSame(1, $popsWhileIdle, 'an idle stream with a live wake subscriber polled Redis on its tick (five times a second before the fix)');
    }

    /**
     * The second cut. What was left of the idle cost was the 5 s safety poll
     * each stream made for itself: with 1000 streams, 200 passes a second, each
     * a pop and a scan of the whole deliver table. Six seconds cross that old
     * deadline; the heartbeat (20 s) is the stream's next pass.
     */
    #[Test]
    public function an_idle_stream_makes_no_redis_pop_and_no_table_scan_between_heartbeats(): void
    {
        [$server, $registry, , $queue, $subscriber, $loop] = $this->wiredServer();
        $subscriber->onSubscribed();
        $written = [];
        $response = $this->createStub(Response::class);
        $response->method('write')->willReturnCallback(static function (string $data) use (&$written): bool {
            $written[] = $data;

            return true;
        });
        $request = $this->createStub(Request::class);
        $deliver = $this->deliverTable;
        $pops = null;
        $scans = null;

        Coroutine\run(function () use ($server, $registry, $queue, $loop, $response, $request, $deliver, &$pops, &$scans): void {
            $registry->open('sse_quiet', $response, '', '');
            $ended = new Channel(1);
            Coroutine::create(static function () use ($server, $loop, $response, $request, $ended): void {
                $loop->invoke($server, 'sse_quiet', $request, $response, '');
                $ended->push(true);
            });

            Coroutine::sleep(6.2);
            $pops = $queue->pops;
            $scans = $deliver->scans ?? null;

            $registry->close('sse_quiet');
            $ended->pop(2.0);
        });

        // Both are the loop's first pass: a session opens marked for Redis and
        // for the table, so what arrived before its loop is picked up.
        self::assertSame(1, $pops, 'an idle stream asked Redis again before its heartbeat (the 5 s per-stream safety poll)');
        self::assertSame(1, $scans, 'an idle stream scanned the deliver table again before its heartbeat');
        self::assertSame([], $written, 'nothing is due on the wire inside the heartbeat interval');
    }

    /**
     * What replaced that poll. A wake is a publish and can be lost; the push
     * also left the session id in the pending set, and the worker's sweep,
     * one read for all its streams, finds it there.
     */
    #[Test]
    public function a_frame_whose_wake_was_lost_is_delivered_by_the_workers_next_sweep(): void
    {
        [$server, $registry, $tables, $queue, , $loop] = $this->wiredServer();
        // A short interval in place of the 5 s; the subscriber drives it as its 0.2 s timer does.
        $sweep = new SsePendingSweep($registry, $queue, $tables, 0.3);
        $subscriber = new SseWakeSubscriber($registry, new RedisSubscribeConnectionFactory(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 1, 'password' => '']), null, $tables, $sweep);
        $runtime = (new \ReflectionProperty(SseServer::class, 'runtime'))->getValue($server);
        self::assertInstanceOf(SseRuntime::class, $runtime);
        $runtime->wakeSubscriber = $subscriber;
        $subscriber->onSubscribed();
        $written = [];
        $response = $this->createStub(Response::class);
        $response->method('write')->willReturnCallback(static function (string $data) use (&$written): bool {
            $written[] = $data;

            return true;
        });
        $request = $this->createStub(Request::class);
        $deliveredAfter = null;
        $pops = null;

        Coroutine\run(function () use ($server, $registry, $queue, $subscriber, $loop, $response, $request, &$written, &$deliveredAfter, &$pops): void {
            $registry->open('sse_lost', $response, '', '');
            $registry->open('sse_bystander', new \stdClass(), '', '');
            $registry->takeRedisDirty('sse_bystander');
            $ended = new Channel(1);
            Coroutine::create(static function () use ($server, $loop, $response, $request, $ended): void {
                $loop->invoke($server, 'sse_lost', $request, $response, '');
                $ended->push(true);
            });

            Coroutine::sleep(0.25);
            $subscriber->sweepPendingIfDue(); // a sweep with nothing pending: the next is an interval away
            // Another worker's push: the frame, the pending mark — and a PUBLISH nobody heard.
            $queue->frames['sse_lost'][] = '{"event":"probe","marker":"its-wake-was-lost"}';
            $queue->pending = ['sse_lost', 'held-by-another-worker'];
            $startedAt = microtime(true);
            while (!str_contains(implode('', $written), 'its-wake-was-lost') && microtime(true) - $startedAt < 2.0) {
                $subscriber->sweepPendingIfDue();
                Coroutine::sleep(0.02);
            }
            $deliveredAfter = microtime(true) - $startedAt;
            $pops = $queue->pops;

            $registry->close('sse_lost');
            $ended->pop(2.0);
        });

        self::assertStringContainsString('its-wake-was-lost', implode('', $written), 'a frame whose wake was lost was never delivered');
        self::assertGreaterThanOrEqual(0.2, $deliveredAfter, 'the frame left before a sweep was due: something else polled for it');
        self::assertLessThan(0.5, $deliveredAfter, 'the frame waited longer than one sweep interval');
        // First pass (empty), then the swept pass: the frame and the empty pop that ends its drain.
        self::assertSame(3, $pops);
        self::assertSame(['held-by-another-worker'], $queue->pending, 'the sweep took a mark that was not this worker\'s, or left its own');
        self::assertFalse($registry->takeRedisDirty('sse_bystander'), 'the sweep woke a session nothing was queued for');
    }

    #[Test]
    public function a_wake_delivers_another_workers_frame_at_once(): void
    {
        [$server, $registry, , $queue, $subscriber, $loop] = $this->wiredServer();
        $subscriber->onSubscribed();
        $written = [];
        $response = $this->createStub(Response::class);
        $response->method('write')->willReturnCallback(static function (string $data) use (&$written): bool {
            $written[] = $data;

            return true;
        });
        $request = $this->createStub(Request::class);
        $deliveredAfter = null;
        $woken = null;
        $pops = null;

        Coroutine\run(function () use ($server, $registry, $queue, $subscriber, $loop, $response, $request, &$written, &$deliveredAfter, &$woken, &$pops): void {
            $registry->open('sse_wake', $response, '', '');
            $ended = new Channel(1);
            Coroutine::create(static function () use ($server, $loop, $response, $request, $ended): void {
                $loop->invoke($server, 'sse_wake', $request, $response, '');
                $ended->push(true);
            });

            // 0.25 s in: a loop still on the 0.2 s tick is ~150 ms from its next pass.
            Coroutine::sleep(0.25);
            // What another worker does: RPUSH the frame, then PUBLISH the session id.
            $queue->frames['sse_wake'][] = '{"event":"probe","marker":"from-another-worker"}';
            $startedAt = microtime(true);
            $woken = $subscriber->onMessage('sse_wake');
            while (!str_contains(implode('', $written), 'from-another-worker') && microtime(true) - $startedAt < 1.0) {
                Coroutine::sleep(0.005);
            }
            $deliveredAfter = microtime(true) - $startedAt;
            $pops = $queue->pops;

            $registry->close('sse_wake');
            $ended->pop(2.0);
        });

        self::assertTrue($woken, 'the wake found no session to wake');
        self::assertStringContainsString('from-another-worker', implode('', $written), 'the frame queued by another worker never reached the socket');
        self::assertLessThan(0.05, $deliveredAfter, 'the frame waited for a tick instead of leaving on the wake');
        // First pass (empty), then the woken pass: the frame and the empty pop that ends its drain.
        self::assertSame(3, $pops);
    }

    #[Test]
    public function a_superseded_loop_stands_down_on_the_wake_without_taking_the_new_connections_frames(): void
    {
        [$server, $registry, $tables, $queue, $subscriber, $loop] = $this->wiredServer();
        $subscriber->onSubscribed();
        $response = $this->createStub(Response::class);
        $response->method('write')->willReturn(true);
        $successor = $this->createStub(Response::class);
        $request = $this->createStub(Request::class);
        $endedOnWake = null;
        $replaced = null;
        $pops = null;

        Coroutine\run(function () use ($server, $registry, $tables, $queue, $subscriber, $loop, $response, $successor, $request, &$endedOnWake, &$replaced, &$pops): void {
            $registry->open('sse_super', $response, '', '');
            $tables->recordOwnership('sse_super', $response);
            $ended = new Channel(1);
            Coroutine::create(static function () use ($server, $loop, $response, $request, $ended): void {
                $loop->invoke($server, 'sse_super', $request, $response, '');
                $ended->push(true);
            });

            Coroutine::sleep(0.25);
            // The reconnect lands on ANOTHER worker: the shared ownership row is
            // its, this worker's registry entry is untouched, and a frame for the
            // new connection is already in Redis when the wake arrives.
            $replaced = $tables->recordOwnership('sse_super', $successor);
            $queue->frames['sse_super'][] = '{"event":"probe","marker":"for-the-new-connection"}';
            $subscriber->onMessage('sse_super');
            $endedOnWake = $ended->pop(0.05) === true;
            $pops = $queue->pops;

            $registry->close('sse_super');
            $ended->pop(2.0);
        });

        self::assertTrue($replaced, 'the second claim did not report that it replaced the first connection');
        self::assertTrue($endedOnWake, 'the superseded loop stayed parked after the wake');
        self::assertSame(1, $pops, 'the superseded loop asked Redis again after it was woken');
        self::assertSame(['{"event":"probe","marker":"for-the-new-connection"}'], $queue->frames['sse_super'], 'the superseded loop took a frame queued for the connection that replaced it');
    }

    #[Test]
    public function a_cancelled_wait_ends_the_loop_instead_of_parking_again(): void
    {
        [$server, $registry, , , $subscriber, $loop] = $this->wiredServer();
        $subscriber->onSubscribed();
        $response = $this->createStub(Response::class);
        $response->method('write')->willReturn(true);
        $request = $this->createStub(Request::class);
        $endedOnCancel = null;

        Coroutine\run(function () use ($server, $registry, $loop, $response, $request, &$endedOnCancel): void {
            $registry->open('sse_cancel', $response, '', '');
            $ended = new Channel(1);
            $cid = Coroutine::create(static function () use ($server, $loop, $response, $request, $ended): void {
                $loop->invoke($server, 'sse_cancel', $request, $response, '');
                $ended->push(true);
            });

            Coroutine::sleep(0.25);
            // What the exit drain does to a parked stream. The drain signal is
            // left unset on purpose: the cancelled wait alone must end the loop.
            Coroutine::cancel((int) $cid);
            $endedOnCancel = $ended->pop(0.05) === true;

            $registry->close('sse_cancel');
            $ended->pop(2.0);
        });

        self::assertTrue($endedOnCancel, 'a loop whose wait was cancelled went round and parked again');
    }

    /**
     * Review of 44775e9. The wake flag is taken and the poll clock moved before
     * the pop; a pop that then failed left nothing to bring the loop back, and
     * the frame waited for the 5 s safety poll.
     */
    #[Test]
    public function a_failed_pop_is_retried_a_tick_later_not_at_the_safety_poll(): void
    {
        [$server, $registry, , $queue, $subscriber, $loop] = $this->wiredServer();
        $subscriber->onSubscribed();
        $written = [];
        $response = $this->createStub(Response::class);
        $response->method('write')->willReturnCallback(static function (string $data) use (&$written): bool {
            $written[] = $data;

            return true;
        });
        $request = $this->createStub(Request::class);
        $deliveredAfter = null;
        $pops = null;

        Coroutine\run(function () use ($server, $registry, $queue, $subscriber, $loop, $response, $request, &$written, &$deliveredAfter, &$pops): void {
            $registry->open('sse_retry', $response, '', '');
            $ended = new Channel(1);
            Coroutine::create(static function () use ($server, $loop, $response, $request, $ended): void {
                $loop->invoke($server, 'sse_retry', $request, $response, '');
                $ended->push(true);
            });

            Coroutine::sleep(0.25);
            $queue->frames['sse_retry'][] = '{"event":"probe","marker":"after-a-failed-pop"}';
            $queue->failNextPops = 1;
            $startedAt = microtime(true);
            $subscriber->onMessage('sse_retry');
            while (!str_contains(implode('', $written), 'after-a-failed-pop') && microtime(true) - $startedAt < 1.0) {
                Coroutine::sleep(0.01);
            }
            $deliveredAfter = microtime(true) - $startedAt;
            $pops = $queue->pops;

            $registry->close('sse_retry');
            $ended->pop(2.0);
        });

        self::assertStringContainsString('after-a-failed-pop', implode('', $written), 'the frame was still waiting a second after its pop failed');
        self::assertGreaterThanOrEqual(0.15, $deliveredAfter, 'the retry did not wait its tick: against a Redis that keeps failing this is a spin');
        self::assertLessThan(0.4, $deliveredAfter);
        // First pass (empty), the woken pop that failed, then the retry: the frame and the empty pop that ends its drain.
        self::assertSame(4, $pops);
    }

    /**
     * Review of 44775e9. A loop reads the subscriber's health once per pass and
     * parks on it; one already parked on "healthy" stayed there for up to 5 s
     * after the subscription dropped, with nothing left that could wake it.
     */
    #[Test]
    public function a_dropped_subscription_sends_a_parked_loop_back_to_its_tick(): void
    {
        [$server, $registry, , $queue, $subscriber, $loop] = $this->wiredServer();
        $subscriber->onSubscribed();
        $written = [];
        $response = $this->createStub(Response::class);
        $response->method('write')->willReturnCallback(static function (string $data) use (&$written): bool {
            $written[] = $data;

            return true;
        });
        $request = $this->createStub(Request::class);
        $deliveredAfter = null;

        Coroutine\run(function () use ($server, $registry, $queue, $subscriber, $loop, $response, $request, &$written, &$deliveredAfter): void {
            $registry->open('sse_drop', $response, '', '');
            $ended = new Channel(1);
            Coroutine::create(static function () use ($server, $loop, $response, $request, $ended): void {
                $loop->invoke($server, 'sse_drop', $request, $response, '');
                $ended->push(true);
            });

            Coroutine::sleep(0.25);
            // The loop is parked on "healthy" when the subscription goes.
            $subscriber->onDropped(new \RuntimeException('Connection reset by peer'), 12.0);
            // Queued by another worker afterwards: its wake has nobody to reach.
            $queue->frames['sse_drop'][] = '{"event":"probe","marker":"published-to-nobody"}';
            $startedAt = microtime(true);
            while (!str_contains(implode('', $written), 'published-to-nobody') && microtime(true) - $startedAt < 1.0) {
                Coroutine::sleep(0.01);
            }
            $deliveredAfter = microtime(true) - $startedAt;

            $registry->close('sse_drop');
            $ended->pop(2.0);
        });

        self::assertFalse($subscriber->isHealthy());
        self::assertStringContainsString('published-to-nobody', implode('', $written), 'the loop stayed parked on a subscription that was gone');
        self::assertLessThan(0.4, $deliveredAfter);
    }

    /**
     * Review of semitexa-ssr#190. When Redis refuses a frame, deliver() parks
     * it in the shared table for the owning worker and can publish no wake. The
     * owner's loop, parked on a subscription that still reads healthy, found it
     * at the 5 s safety poll; the tick used to find it in 0.2 s.
     */
    #[Test]
    public function a_frame_parked_in_the_deliver_table_reaches_a_parked_loop_on_the_next_tick(): void
    {
        [$server, $registry, , $queue, $subscriber, $loop] = $this->wiredServer();
        $subscriber->onSubscribed();
        $written = [];
        $response = $this->createStub(Response::class);
        $response->method('write')->willReturnCallback(static function (string $data) use (&$written): bool {
            $written[] = $data;

            return true;
        });
        $request = $this->createStub(Request::class);
        $other = $this->otherWorker;
        $idleTicks = null;
        $woken = null;
        $deliveredAfter = null;
        $pops = null;
        $again = null;

        Coroutine\run(function () use ($server, $registry, $queue, $subscriber, $loop, $response, $request, $other, &$written, &$idleTicks, &$woken, &$deliveredAfter, &$pops, &$again): void {
            $registry->open('sse_table', $response, '', '');
            $registry->open('sse_bystander', new \stdClass(), '', '');
            $registry->takeRedisDirty('sse_bystander');
            $ended = new Channel(1);
            Coroutine::create(static function () use ($server, $loop, $response, $request, $ended): void {
                $loop->invoke($server, 'sse_table', $request, $response, '');
                $ended->push(true);
            });

            Coroutine::sleep(0.25);
            // The worker's 0.2 s tick with nothing parked: a cell read, nobody woken.
            $idleTicks = $subscriber->wakeTableDeliveries() + $subscriber->wakeTableDeliveries();

            // Worker 1 could not reach Redis and parks the frame for worker 0.
            self::assertTrue($other->queueForWorker('sse_table', 0, ['event' => 'probe', 'marker' => 'parked-in-the-table']));
            $startedAt = microtime(true);
            $woken = $subscriber->wakeTableDeliveries();
            while (!str_contains(implode('', $written), 'parked-in-the-table') && microtime(true) - $startedAt < 1.0) {
                Coroutine::sleep(0.005);
            }
            $deliveredAfter = microtime(true) - $startedAt;
            $pops = $queue->pops;
            $again = $subscriber->wakeTableDeliveries();

            $registry->close('sse_table');
            $ended->pop(2.0);
        });

        self::assertSame(0, $idleTicks, 'a tick with nothing parked woke somebody');
        self::assertSame(1, $woken, 'only the session with a parked frame is woken');
        self::assertStringContainsString('parked-in-the-table', implode('', $written), 'the frame was still in the table a second later');
        self::assertLessThan(0.05, $deliveredAfter);
        self::assertSame(1, $pops, 'a frame that is not in Redis must not cost a Redis pop');
        self::assertFalse($registry->takeRedisDirty('sse_bystander'), 'a session with nothing parked was touched');
        self::assertSame(0, $again, 'the same signal was acted on twice');
    }

    /**
     * A row is deleted only once its frame is out. With the loop no longer
     * passing every few seconds, a refused write has to bring it back itself.
     */
    #[Test]
    public function a_table_frame_the_socket_refused_is_written_again_a_tick_later(): void
    {
        [$server, $registry, , , $subscriber, $loop] = $this->wiredServer();
        $subscriber->onSubscribed();
        $written = [];
        $refuse = 1;
        $response = $this->createStub(Response::class);
        $response->method('write')->willReturnCallback(static function (string $data) use (&$written, &$refuse): bool {
            if ($refuse > 0) {
                $refuse--;

                return false;
            }
            $written[] = $data;

            return true;
        });
        $request = $this->createStub(Request::class);
        $other = $this->otherWorker;
        $deliver = $this->deliverTable;
        $deliveredAfter = null;
        $scans = null;

        Coroutine\run(function () use ($server, $registry, $subscriber, $loop, $response, $request, $other, $deliver, &$written, &$deliveredAfter, &$scans): void {
            $registry->open('sse_refused', $response, '', '');
            $ended = new Channel(1);
            Coroutine::create(static function () use ($server, $loop, $response, $request, $ended): void {
                $loop->invoke($server, 'sse_refused', $request, $response, '');
                $ended->push(true);
            });

            Coroutine::sleep(0.25);
            self::assertTrue($other->queueForWorker('sse_refused', 0, ['event' => 'probe', 'marker' => 'refused-once']));
            $startedAt = microtime(true);
            $subscriber->wakeTableDeliveries();
            while (!str_contains(implode('', $written), 'refused-once') && microtime(true) - $startedAt < 2.0) {
                Coroutine::sleep(0.01);
            }
            $deliveredAfter = microtime(true) - $startedAt;
            Coroutine::sleep(0.5);
            $scans = $deliver->scans ?? null;

            $registry->close('sse_refused');
            $ended->pop(2.0);
        });

        self::assertStringContainsString('refused-once', implode('', $written), 'the row the socket refused was left in the table until the heartbeat');
        self::assertGreaterThanOrEqual(0.15, $deliveredAfter, 'the retry did not wait its tick: against a socket that keeps refusing this is a spin');
        self::assertLessThan(0.5, $deliveredAfter);
        self::assertSame(1, substr_count(implode('', $written), 'refused-once'), 'the frame was written twice');
        // The first pass, the subscriber's own scan for whom to wake, the refused pass and the retry.
        self::assertSame(4, $scans, 'the loop went on scanning the table after the row was out');
    }

    /**
     * The deferred page's live loop no longer renews its request (it sleeps
     * until a slot is due, an hour if that is the slot's interval), so this
     * loop is the only thing that does. It has to be the SAME record: the id
     * from the stream's query, vouched for with the page's bind cookie.
     */
    #[Test]
    public function the_held_loop_keeps_its_pages_deferred_request_alive_past_the_ttl(): void
    {
        [$server, $registry, , , $subscriber, $loop] = $this->wiredServer();
        $subscriber->onSubscribed();
        $response = $this->createStub(Response::class);
        $response->method('write')->willReturn(true);
        $stranger = $this->createStub(Response::class);
        $stranger->method('write')->willReturn(true);
        $request = $this->createStub(Request::class);
        $request->get = ['deferred_request_id' => 'dr_held'];
        $request->cookie = [DeferredBindCookie::nameFor('dr_held') => 'token'];
        // The same id from a browser that does not hold the page's cookie.
        $noCookie = $this->createStub(Request::class);
        $noCookie->get = ['deferred_request_id' => 'dr_not_mine'];
        $noCookie->cookie = [];

        DeferredRequestRegistry::reset();
        try {
            DeferredRequestRegistry::initialize(new IsomorphicConfig(enabled: true, deferredContextSize: 8192, requestSnapshotSize: 4096));
            DeferredRequestRegistry::store('dr_held', 'page', [], ['slot'], 'token', 'en');
            DeferredRequestRegistry::store('dr_not_mine', 'page', [], ['slot'], 'token', 'en');
            $table = DeferredRequestRegistry::getTable();
            self::assertNotNull($table);
            // Minted 100 s ago: inside the 120 s TTL when the streams open.
            $table->set('dr_held', ['created_at' => time() - 100]);
            $table->set('dr_not_mine', ['created_at' => time() - 100]);

            Coroutine\run(function () use ($server, $registry, $loop, $response, $stranger, $request, $noCookie): void {
                $registry->open('sse_held', $response, '', '');
                $registry->open('sse_stranger', $stranger, '', '');
                $ended = new Channel(2);
                Coroutine::create(static function () use ($server, $loop, $response, $request, $ended): void {
                    $loop->invoke($server, 'sse_held', $request, $response, '');
                    $ended->push(true);
                });
                Coroutine::create(static function () use ($server, $loop, $stranger, $noCookie, $ended): void {
                    $loop->invoke($server, 'sse_stranger', $noCookie, $stranger, '');
                    $ended->push(true);
                });

                Coroutine::sleep(0.25);

                $registry->close('sse_held');
                $registry->close('sse_stranger');
                $ended->pop(2.0);
                $ended->pop(2.0);
            });

            // Now both are far past the TTL by their minting time alone.
            $table->set('dr_held', ['created_at' => time() - 500]);
            $table->set('dr_not_mine', ['created_at' => time() - 500]);

            self::assertNotNull(DeferredRequestRegistry::consume('dr_held'), 'the held stream did not renew its page\'s deferred request: a reconnect past the TTL is refused for ever');
            self::assertNull(DeferredRequestRegistry::consume('dr_not_mine'), 'a stream without the page\'s bind cookie renewed a request that is not its own');
        } finally {
            DeferredRequestRegistry::reset();
        }
    }

    #[Test]
    public function without_a_subscription_the_loop_keeps_its_tick(): void
    {
        // Wired but never subscribed: Redis is down, or the receiver is between
        // two connections. Nothing will wake the loop, so it must keep asking.
        [$server, $registry, , $queue, $subscriber, $loop] = $this->wiredServer();
        $written = [];
        $response = $this->createStub(Response::class);
        $response->method('write')->willReturnCallback(static function (string $data) use (&$written): bool {
            $written[] = $data;

            return true;
        });
        $request = $this->createStub(Request::class);
        $deliveredAfter = null;

        Coroutine\run(function () use ($server, $registry, $queue, $loop, $response, $request, &$written, &$deliveredAfter): void {
            $registry->open('sse_tick', $response, '', '');
            $ended = new Channel(1);
            Coroutine::create(static function () use ($server, $loop, $response, $request, $ended): void {
                $loop->invoke($server, 'sse_tick', $request, $response, '');
                $ended->push(true);
            });

            Coroutine::sleep(0.25);
            $queue->frames['sse_tick'][] = '{"event":"probe","marker":"nobody-woke-me"}';
            $startedAt = microtime(true);
            while (!str_contains(implode('', $written), 'nobody-woke-me') && microtime(true) - $startedAt < 2.0) {
                Coroutine::sleep(0.01);
            }
            $deliveredAfter = microtime(true) - $startedAt;

            $registry->close('sse_tick');
            $ended->pop(2.0);
        });

        self::assertFalse($subscriber->isHealthy());
        self::assertStringContainsString('nobody-woke-me', implode('', $written));
        self::assertLessThan(0.4, $deliveredAfter, 'with no wake subscription the loop parked past its 0.2 s tick');
    }

    private function workerServer(int $workerId): \Swoole\Http\Server
    {
        $server = $this->createStub(\Swoole\Http\Server::class);
        $server->method('getWorkerId')->willReturn($workerId);

        return $server;
    }

    /** @return array{SseServer, SseSessionRegistry, SseWorkerTables, CountingSessionQueue, SseWakeSubscriber, \ReflectionMethod} */
    private function wiredServer(): array
    {
        $server = AsyncResourceSseServer::instance();
        $sessions = new Table(64);
        $sessions->column('worker_id', Table::TYPE_INT, 8);
        $sessions->column('connection', Table::TYPE_STRING, 16);
        $sessions->column('opened_seq', Table::TYPE_INT, 8);
        $sessions->create();
        $deliver = new class(8) extends Table {
            public int $scans = 0;

            public function rewind(): void
            {
                $this->scans++;
                parent::rewind();
            }
        };
        $this->deliverTable = $deliver;
        $deliver->column('session_id', Table::TYPE_STRING, 32);
        $deliver->column('worker_id', Table::TYPE_INT, 4);
        $deliver->column('payload', Table::TYPE_STRING, 128);
        $deliver->create();
        $tables = new SseWorkerTables();
        $tables->setTables($sessions, $deliver);
        $tables->setServer($this->workerServer(0));
        $this->otherWorker = new SseWorkerTables();
        $this->otherWorker->setTables($sessions, $deliver);
        $this->otherWorker->setServer($this->workerServer(1));
        $registry = new SseSessionRegistry();
        $queue = new CountingSessionQueue();
        // Never asked for a connection: the receiver is driven through its seams.
        $subscriber = new SseWakeSubscriber($registry, new RedisSubscribeConnectionFactory(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 1, 'password' => '']), null, $tables);
        $runtime = new SseRuntime();
        $runtime->wakeSubscriber = $subscriber;
        // The bare transport: the default one keeps a replay ring in Redis.
        $runtime->transport = new SwooleSseTransport();
        (new \ReflectionProperty(SseServer::class, 'workerTables'))->setValue($server, $tables);
        (new \ReflectionProperty(SseServer::class, 'sessionRegistry'))->setValue($server, $registry);
        (new \ReflectionProperty(SseServer::class, 'redisSessionQueue'))->setValue($server, $queue);
        (new \ReflectionProperty(SseServer::class, 'runtime'))->setValue($server, $runtime);

        return [$server, $registry, $tables, $queue, $subscriber, new \ReflectionMethod(SseServer::class, 'runHeldOpenLoop')];
    }
}
