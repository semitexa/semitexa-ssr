<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Core\Pipeline\ReRun\ReRunnerInterface;
use Semitexa\Core\Pipeline\ReRun\ReRunResult;
use Semitexa\Core\Server\SseFrame;
use Semitexa\Core\Server\SseTransportInterface;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\ConnectCoordinator;
use Semitexa\Ssr\Application\Service\Async\RedisSubscribeConnectionFactory;
use Semitexa\Ssr\Application\Service\Async\RerunCoalescer;
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationPublisher;
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationSubscriber;
use Semitexa\Ssr\Application\Service\Async\ScanningSubscriberIndex;
use Semitexa\Ssr\Application\Service\Async\SseSessionControlDelivery;
use Semitexa\Ssr\Application\Service\Async\SubscriptionDtoRegistry;
use Semitexa\Ssr\Application\Service\Async\SubscriptionTable;
use Semitexa\Ssr\Domain\Contract\ChannelSubscriptionControllerInterface;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * Track R · R8c-1 — the held-open grid stream meets the FULL consumer-half on a
 * live held-open fd for the first time (the tightest integration seam).
 *
 * Where {@see ControlFrameReRunTest} drives R4 in isolation, this exercises the
 * WHOLE chain a real leads mutation triggers — through the REAL delivery
 * ({@see SseSessionControlDelivery}) onto a REGISTERED held-open session's queue,
 * then drained exactly as {@see AsyncResourceSseServer::runHeldOpenLoop()} drains
 * it — and asserts the decisive invariant: the fresh re-queried frame lands on the
 * SAME held-open fd (not a reconnect), N mutations → N fresh frames on the ONE
 * connection. It also proves the consumer-half is launched on connect (R5 — first
 * production caller) and reaped on disconnect, that the re-run is auth-preserving
 * (TERMINATE closes with no data frame), and that the re-run reentrancy guard is
 * live (so the own-route handler degrades to a JSON body on a re-run tick instead
 * of grabbing the socket).
 *
 * No live Swoole server / coroutine is required: the blocking serve loop is the
 * same already-tested {@see AsyncResourceSseServer::runHeldOpenLoop()}; here its
 * per-item drain step is driven via the established R3/R5 reflection pattern, with
 * the control arriving via the real publish→route→deliver path and the fresh frame
 * captured against the exact registered fd.
 */
final class HeldOpenGridStreamTest extends TestCase
{
    private const HANDLED_CONTINUE = 1;
    private const HANDLED_CLOSE = 2;

    /** The channel a `ui_playground_leads` mutation publishes (default tenant). */
    private const LEADS_CHANNEL = 'ui.invalidate.default.ui_playground_leads';
    private const LEADS_SCOPE = 'ui_playground_leads';

    protected function setUp(): void
    {
        if (!class_exists(\Swoole\Table::class, false)) {
            self::markTestSkipped('Swoole extension not loaded.');
        }
        SubscriptionDtoRegistry::clear();
        $this->resetSessions();
    }

    protected function tearDown(): void
    {
        SubscriptionDtoRegistry::clear();
        AsyncResourceSseServer::setReRunner(null);
        AsyncResourceSseServer::setRerunCoalescer(null);
        AsyncResourceSseServer::setConnectCoordinator(null);
        $this->setTransport(null);
        $this->resetSessions();
    }

    // -----------------------------------------------------------------------
    // THE DECISIVE INVARIANT — same fd, fresh frame, N mutations → N frames
    // -----------------------------------------------------------------------

    #[Test]
    public function a_real_mutation_delivers_a_fresh_frame_on_the_SAME_held_open_fd(): void
    {
        [$coordinator, $subs, $coalescer, $subscriber] = $this->wireConsumerHalf();

        // The held-open grid stream: a registered session whose fd is $fd.
        $sessionId = 'sse_' . str_repeat('a', 32);
        $fd = new \stdClass();
        $coordinator->onConnect(
            new SubscriptionRecord($sessionId, $sessionId, 'default', [self::LEADS_SCOPE], '[]'),
            $this->reRunContext($sessionId),
        );
        $this->registerSession($sessionId, $fd);

        $rerunner = $this->freshFrameReRunner();
        $transport = $this->captureTransport();
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $this->setTransport($transport);

        // A real leads mutation → P3 publishes on the leads channel → R3 receives.
        // handleMessage resolves THIS subscription (tenant+scope), coalesces, and
        // routes ONE {__ctrl:rerun} via the REAL session-addressed delivery →
        // it lands on the held-open session's own queue.
        $enqueued = $subscriber->handleMessage(self::LEADS_CHANNEL);
        self::assertSame(1, $enqueued, 'the publish resolved exactly this held-open subscription');
        self::assertCount(1, $this->queuedFor($sessionId), 'the control reached the held-open fd’s queue');

        // The serve loop drains that queue item (R4) → fresh re-queried frame on $fd.
        $outcomes = $this->drainSessionQueue($sessionId, $fd);

        self::assertSame([self::HANDLED_CONTINUE], $outcomes);
        self::assertSame(1, $rerunner->calls, 're-ran on the owning worker');
        self::assertCount(1, $transport->frames);
        self::assertSame($fd, $transport->streams[0], 'the fresh frame went to the SAME held-open fd (not a reconnect)');
        self::assertStringContainsString('event: ui.grid.data', $transport->frames[0]->toWire());
        self::assertStringContainsString('"value":1', $transport->frames[0]->toWire());

        // A SECOND independent mutation on the SAME connection. R4 cleared the
        // coalescer mark after the first tick, so this publish re-arms cleanly,
        // routes a fresh control onto the same queue, and drains to a second FRESH
        // frame (value 2) on the SAME fd — live update, not polling, not a reconnect.
        $enqueued2 = $subscriber->handleMessage(self::LEADS_CHANNEL);
        self::assertSame(1, $enqueued2, 'the cleared mark re-armed: the next mutation routes again');
        $this->drainSessionQueue($sessionId, $fd);

        self::assertSame(2, $rerunner->calls);
        self::assertCount(2, $transport->frames);
        self::assertSame($fd, $transport->streams[1], 'the 2nd fresh frame is on the same fd — live update, not polling');
        self::assertStringContainsString('"value":2', $transport->frames[1]->toWire());
    }

    // -----------------------------------------------------------------------
    // AUTH PRESERVED ON RE-RUN — a denied re-run TERMINATEs, no data frame
    // -----------------------------------------------------------------------

    #[Test]
    public function a_denied_rerun_terminates_the_held_open_stream_with_no_data_frame(): void
    {
        [$coordinator, , $coalescer, $subscriber] = $this->wireConsumerHalf();
        $sessionId = 'sse_' . str_repeat('b', 32);
        $fd = new \stdClass();
        $coordinator->onConnect(
            new SubscriptionRecord($sessionId, $sessionId, 'default', [self::LEADS_SCOPE], '[]'),
            $this->reRunContext($sessionId),
        );
        $this->registerSession($sessionId, $fd);

        $transport = $this->captureTransport();
        AsyncResourceSseServer::setReRunner($this->terminatingReRunner('access_revoked'));
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $this->setTransport($transport);

        $subscriber->handleMessage(self::LEADS_CHANNEL);
        $outcomes = $this->drainSessionQueue($sessionId, $fd);

        // The re-run goes through R2 auth-first; a denied re-auth → TERMINATE: the
        // loop is told to CLOSE, the ONLY frame is the close frame — no data leaks.
        self::assertSame([self::HANDLED_CLOSE], $outcomes);
        self::assertCount(1, $transport->frames);
        $wire = $transport->frames[0]->toWire();
        self::assertStringContainsString('event: close', $wire);
        self::assertStringContainsString('access_revoked', $wire);
        self::assertStringNotContainsString('"value"', $wire);
    }

    // -----------------------------------------------------------------------
    // CONSUMER-HALF LAUNCHED ON CONNECT, REAPED ON DISCONNECT (R5 first caller)
    // -----------------------------------------------------------------------

    #[Test]
    public function onconnect_populates_both_tiers_and_subscribes_the_leads_scope_then_ondisconnect_reaps(): void
    {
        [$coordinator, $subs, $coalescer, $subscriber] = $this->wireConsumerHalf();
        $sessionId = 'sse_' . str_repeat('c', 32);

        $coordinator->onConnect(
            new SubscriptionRecord($sessionId, $sessionId, 'default', [self::LEADS_SCOPE], '[]'),
            $this->reRunContext($sessionId),
        );

        // Tier 1 (cross-worker row) + tier 2 (worker-local ReRunContext) populated.
        self::assertTrue(SubscriptionDtoRegistry::has($sessionId), 'tier-2 ReRunContext stored on the owning worker');
        self::assertCount(1, $this->collect($subs->all()), 'tier-1 row inserted');

        // The grid subscribed to EXACTLY the channel a leads mutation publishes on.
        self::assertSame([self::LEADS_CHANNEL], $subscriber->desiredChannels());
        self::assertSame(
            self::LEADS_CHANNEL,
            ResourceInvalidationPublisher::channelFor('default', self::LEADS_SCOPE),
            'the watched channel matches the publisher — producer/consumer agree by construction',
        );

        // Disconnect reaps every tier — no zombie.
        $coordinator->onDisconnect($sessionId);
        self::assertFalse(SubscriptionDtoRegistry::has($sessionId), 'tier-2 reaped');
        self::assertCount(0, $this->collect($subs->all()), 'tier-1 reaped');
        self::assertFalse($coalescer->isPending($sessionId), 'no stale coalescer mark left behind');
        self::assertSame([], $subscriber->desiredChannels(), 'unsubscribe-on-last: no channel remains');
    }

    // -----------------------------------------------------------------------
    // RE-RUN REENTRANCY GUARD — the own-route handler degrades on a re-run tick
    // -----------------------------------------------------------------------

    #[Test]
    public function the_rerun_guard_is_active_only_inside_the_rerun_so_the_handler_degrades_to_json(): void
    {
        [$coordinator, , $coalescer, $subscriber] = $this->wireConsumerHalf();
        $sessionId = 'sse_' . str_repeat('d', 32);
        $fd = new \stdClass();
        $coordinator->onConnect(
            new SubscriptionRecord($sessionId, $sessionId, 'default', [self::LEADS_SCOPE], '[]'),
            $this->reRunContext($sessionId),
        );
        $this->registerSession($sessionId, $fd);

        self::assertFalse(AsyncResourceSseServer::isReRunInProgress(), 'not re-running before the tick');

        $observed = null;
        $rerunner = new class($observed) implements ReRunnerInterface {
            public int $calls = 0;
            /** @param mixed $observed */
            public function __construct(public mixed &$observed) {}
            public function reRun(ReRunContext $context): ReRunResult
            {
                $this->calls++;
                // The own-route handler asks exactly this question; it MUST be true
                // here so the handler returns a JSON body instead of grabbing the fd.
                $this->observed = AsyncResourceSseServer::isReRunInProgress();

                return ReRunResult::frame(HttpResponse::json(['rows' => []]));
            }
        };
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $this->setTransport($this->captureTransport());

        $subscriber->handleMessage(self::LEADS_CHANNEL);
        $this->drainSessionQueue($sessionId, $fd);

        self::assertTrue($observed, 'the re-run guard is set DURING the re-run (handler degrades to JSON)');
        self::assertFalse(AsyncResourceSseServer::isReRunInProgress(), 'the guard is cleared after the tick');
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    /**
     * Wire the REAL consumer-half: R1 index over a real table, R3 subscriber on the
     * real {@see SseSessionControlDelivery} (so a routed control lands on the real
     * session queue), the R5 coordinator. The live pub/sub loop launch is the
     * coroutine-only seam — driven here via handleMessage directly (R3's pattern).
     *
     * @return array{0: ConnectCoordinator, 1: SubscriptionTable, 2: RerunCoalescer, 3: ResourceInvalidationSubscriber}
     */
    private function wireConsumerHalf(): array
    {
        $subs = SubscriptionTable::create(64);
        $coalescer = RerunCoalescer::create(64);
        $subscriber = new ResourceInvalidationSubscriber(
            new ScanningSubscriberIndex($subs),
            $subs,
            $coalescer,
            new RedisSubscribeConnectionFactory(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'password' => '']),
            new SseSessionControlDelivery(), // REAL delivery → AsyncResourceSseServer::deliver()
        );
        $coordinator = new ConnectCoordinator($subs, $subscriber, $coalescer, $this->nullChannels());

        return [$coordinator, $subs, $coalescer, $subscriber];
    }

    /** One serve-loop drain pass: run each queued item through R4 exactly as the loop does. */
    private function drainSessionQueue(string $sessionId, mixed $fd): array
    {
        $items = $this->queuedFor($sessionId);
        $this->setQueue($sessionId, []);

        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'handleControlFrame');
        $method->setAccessible(true);

        $outcomes = [];
        foreach ($items as $data) {
            $outcomes[] = (int) $method->invoke(null, $sessionId, $fd, $data);
        }

        return $outcomes;
    }

    private function registerSession(string $sessionId, mixed $fd): void
    {
        $sessions = $this->readStatic('sessions');
        $sessions[$sessionId] = ['response' => $fd, 'connected_at' => time()];
        $this->writeStatic('sessions', $sessions);
        $this->setQueue($sessionId, []);
    }

    /** @return list<array<string, mixed>> */
    private function queuedFor(string $sessionId): array
    {
        $queues = $this->readStatic('queues');

        return $queues[$sessionId] ?? [];
    }

    private function setQueue(string $sessionId, array $items): void
    {
        $queues = $this->readStatic('queues');
        $queues[$sessionId] = $items;
        $this->writeStatic('queues', $queues);
    }

    private function resetSessions(): void
    {
        $this->writeStatic('sessions', []);
        $this->writeStatic('queues', []);
        $this->writeStatic('buffer', []);
    }

    private function readStatic(string $name): array
    {
        $p = new \ReflectionProperty(AsyncResourceSseServer::class, $name);
        $p->setAccessible(true);
        /** @var array $value */
        $value = $p->getValue();

        return $value;
    }

    private function writeStatic(string $name, array $value): void
    {
        $p = new \ReflectionProperty(AsyncResourceSseServer::class, $name);
        $p->setAccessible(true);
        $p->setValue(null, $value);
    }

    private function freshFrameReRunner(): ReRunnerInterface
    {
        return new class implements ReRunnerInterface {
            public int $calls = 0;
            public function reRun(ReRunContext $context): ReRunResult
            {
                $this->calls++;

                return ReRunResult::frame(HttpResponse::json([
                    '_type' => 'ui.grid.data',
                    'ok' => true,
                    'rows' => [['id' => 1]],
                    'value' => $this->calls,
                ]));
            }
        };
    }

    private function terminatingReRunner(string $reason): ReRunnerInterface
    {
        return new class($reason) implements ReRunnerInterface {
            public int $calls = 0;
            public function __construct(private readonly string $reason) {}
            public function reRun(ReRunContext $context): ReRunResult
            {
                $this->calls++;

                return ReRunResult::terminate($this->reason);
            }
        };
    }

    private function captureTransport(): SseTransportInterface
    {
        return new class implements SseTransportInterface {
            /** @var list<SseFrame> */
            public array $frames = [];
            /** @var list<mixed> */
            public array $streams = [];
            public bool $socketAlive = true;

            public function writeFrame(mixed $stream, SseFrame $frame): bool
            {
                if (!$this->socketAlive) {
                    return false;
                }
                $this->frames[] = $frame;
                $this->streams[] = $stream;

                return true;
            }

            public function writeComment(mixed $stream): bool
            {
                return $this->socketAlive;
            }
        };
    }

    private function setTransport(?SseTransportInterface $transport): void
    {
        $property = new \ReflectionProperty(AsyncResourceSseServer::class, 'transport');
        $property->setAccessible(true);
        $property->setValue(null, $transport);
    }

    private function nullChannels(): ChannelSubscriptionControllerInterface
    {
        return new class implements ChannelSubscriptionControllerInterface {
            public function subscribe(array $channels): void {}
            public function unsubscribe(array $channels): void {}
        };
    }

    /** @return list<mixed> */
    private function collect(iterable $it): array
    {
        $out = [];
        foreach ($it as $row) {
            $out[] = $row;
        }

        return $out;
    }

    private function reRunContext(string $sessionId): ReRunContext
    {
        return new ReRunContext(
            cachedDto: new \stdClass(),
            route: new DiscoveredRoute(
                path: '/ui-playground/admin/leads/grid-stream',
                methods: ['GET'],
                name: 'leads.grid.stream',
                requestClass: \stdClass::class,
                responseClass: \stdClass::class,
                handlers: [],
                type: 'http_request',
                transport: 'sse',
                produces: null,
                consumes: null,
                module: 'ui-playground',
            ),
            requestSnapshot: ['method' => 'GET', 'uri' => '/ui-playground/admin/leads/grid-stream', 'cookies' => ['sid' => $sessionId]],
            sessionId: $sessionId,
            subjectRef: '',
        );
    }
}
