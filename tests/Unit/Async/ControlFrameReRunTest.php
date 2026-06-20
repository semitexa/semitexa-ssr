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
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationSubscriber;
use Semitexa\Ssr\Application\Service\Async\ScanningSubscriberIndex;
use Semitexa\Ssr\Application\Service\Async\SubscriptionDtoRegistry;
use Semitexa\Ssr\Application\Service\Async\SubscriptionTable;
use Semitexa\Ssr\Domain\Contract\ChannelSubscriptionControllerInterface;
use Semitexa\Ssr\Domain\Contract\SessionControlDeliveryInterface;
use Semitexa\Ssr\Domain\Contract\SubscriptionFactoryInterface;
use Semitexa\Ssr\Domain\Model\SubscriptionAttachment;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * Track R · R4 — the loop branch that catches `{__ctrl:rerun}` and CLOSES the
 * push→re-run cycle (design §D R4, §B.2/§B.3, §C.4).
 *
 * The branch ({@see AsyncResourceSseServer::handleControlFrame()}) is driven
 * directly (the R3/R5 reflection pattern): no live HTTP, no kiss loop, no running
 * coroutine. The store it consumes is populated by a REAL R5
 * {@see ConnectCoordinator::onConnect()} (the mandated "real R5-populated store,
 * not fabricated"); the re-runner is a fake R2 {@see ReRunnerInterface} returning
 * controlled {@see ReRunResult}s; a capturing {@see SseTransportInterface} records
 * exactly which frames reach the wire.
 *
 * Proves: control → fresh frame (re-queried, not stale); TERMINATE closes with NO
 * data frame; clearPending re-arms the bounded coalescing window; cross-worker
 * correctness incl. the decisive missing-context edge; the data-less-delete edge;
 * and that ordinary data frames pass through untouched.
 */
final class ControlFrameReRunTest extends TestCase
{
    private const NOT_CONTROL = 0;
    private const HANDLED_CONTINUE = 1;
    private const HANDLED_CLOSE = 2;

    protected function setUp(): void
    {
        if (!class_exists(\Swoole\Table::class, false)) {
            self::markTestSkipped('Swoole extension not loaded.');
        }
        SubscriptionDtoRegistry::clear();
    }

    protected function tearDown(): void
    {
        SubscriptionDtoRegistry::clear();
        AsyncResourceSseServer::setReRunner(null);
        AsyncResourceSseServer::setRerunCoalescer(null);
        AsyncResourceSseServer::setConnectCoordinator(null);
        AsyncResourceSseServer::setSubscriptionFactory(null);
        $this->setTransport(null);
        $this->clearServerSessions();
    }

    // -----------------------------------------------------------------------
    // VERIFICATION 1 — control → FRESH frame (re-queried, not stale)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_rerun_control_resolves_the_r5_context_and_writes_a_fresh_frame(): void
    {
        [, , $coalescer] = $this->connect('str_a', 'sess_a', ['lead_submission']);
        $rerunner = $this->freshFrameReRunner();           // returns an incrementing value each run
        $transport = $this->captureTransport();
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $this->setTransport($transport);

        // First drain of `{__ctrl:rerun, streaming_id:str_a}`: R4 resolves the
        // R5-stored ReRunContext, runs R2, writes the freshly-queried frame.
        $outcome = $this->drain('sess_a', ['__ctrl' => 'rerun', 'streaming_id' => 'str_a', 'scope_key' => 'lead_submission']);

        self::assertSame(self::HANDLED_CONTINUE, $outcome);
        self::assertSame(1, $rerunner->calls, 'the re-run ran on the OWNING worker (context resolved locally)');
        self::assertCount(1, $transport->frames);
        self::assertStringContainsString('"value":1', $transport->frames[0]->toWire());

        // A second drain re-queries again → value 2, proving the frame is FRESH
        // each tick, not the stale first value.
        $coalescer->requestRerun('str_a');
        $this->drain('sess_a', ['__ctrl' => 'rerun', 'streaming_id' => 'str_a']);
        self::assertSame(2, $rerunner->calls);
        self::assertStringContainsString('"value":2', $transport->frames[1]->toWire());
    }

    // -----------------------------------------------------------------------
    // VERIFICATION 1b — SSE transport unification · Phase 0: the re-run frame
    // is stamped with its subscription's streaming_id so a multiplexed
    // connection (one fd, many subscriptions) can demux it client-side.
    // -----------------------------------------------------------------------

    #[Test]
    public function a_rerun_frame_is_stamped_with_its_streaming_id_for_multiplex_demux(): void
    {
        [, , $coalescer] = $this->connect('str_a', 'sess_a', ['lead_submission']);
        AsyncResourceSseServer::setReRunner($this->freshFrameReRunner());
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $transport = $this->captureTransport();
        $this->setTransport($transport);

        $this->drain('sess_a', ['__ctrl' => 'rerun', 'streaming_id' => 'str_a']);

        self::assertCount(1, $transport->frames);
        // The stamp is the subscription key the client routes the frame by.
        self::assertStringContainsString('"streaming_id":"str_a"', $transport->frames[0]->toWire());
    }

    // -----------------------------------------------------------------------
    // VERIFICATION 2 — TERMINATE closes the stream, emits NO data frame
    // -----------------------------------------------------------------------

    #[Test]
    public function a_terminating_rerun_closes_the_stream_with_no_data_frame(): void
    {
        [, , $coalescer] = $this->connect('str_a', 'sess_a', ['lead_submission']);
        $rerunner = $this->terminatingReRunner('access_revoked');
        $transport = $this->captureTransport();
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $this->setTransport($transport);

        $outcome = $this->drain('sess_a', ['__ctrl' => 'rerun', 'streaming_id' => 'str_a']);

        // The §B.3 lost-access path: the loop is told to CLOSE, and the only frame
        // written is the close frame — never a data frame for a de-authorized subject.
        self::assertSame(self::HANDLED_CLOSE, $outcome);
        self::assertSame(1, $rerunner->calls);
        self::assertCount(1, $transport->frames);
        $wire = $transport->frames[0]->toWire();
        self::assertStringContainsString('event: close', $wire);
        self::assertStringContainsString('access_revoked', $wire);
        self::assertStringNotContainsString('"value"', $wire, 'no data frame leaks on TERMINATE');
    }

    // -----------------------------------------------------------------------
    // VERIFICATION 3 — clearPending after handling re-arms the bounded window
    // -----------------------------------------------------------------------

    #[Test]
    public function the_coalescer_mark_is_cleared_after_handling_so_the_next_signal_re_arms(): void
    {
        [, , $coalescer] = $this->connect('str_a', 'sess_a', ['lead_submission']);
        AsyncResourceSseServer::setReRunner($this->freshFrameReRunner());
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $this->setTransport($this->captureTransport());

        // R3 set the mark (the signal that enqueued this control).
        self::assertTrue($coalescer->requestRerun('str_a'));
        self::assertTrue($coalescer->isPending('str_a'));

        // R4 drains + runs + clears.
        $this->drain('sess_a', ['__ctrl' => 'rerun', 'streaming_id' => 'str_a']);
        self::assertFalse($coalescer->isPending('str_a'), 'R4 cleared the mark after handling');

        // The window is BOUNDED, not a permanent suppression: a fresh signal re-arms
        // (requestRerun transitions 0→1 again → true).
        self::assertTrue($coalescer->requestRerun('str_a'), 'the next mutation re-arms the signal');
    }

    // -----------------------------------------------------------------------
    // VERIFICATION 4 — cross-worker correctness (MANDATORY)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_owning_worker_resolves_its_local_context_and_runs_the_rerun(): void
    {
        // R5 onConnect populated tier-2 on THIS (the owning) worker. A control on
        // the session-addressed queue is drained here and finds its worker-local
        // ReRunContext — the owner-runs-the-rerun path (§C3).
        [, , $coalescer] = $this->connect('str_a', 'sess_a', ['lead_submission']);
        $rerunner = $this->freshFrameReRunner();
        $transport = $this->captureTransport();
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $this->setTransport($transport);

        $outcome = $this->drain('sess_a', ['__ctrl' => 'rerun', 'streaming_id' => 'str_a']);

        self::assertSame(self::HANDLED_CONTINUE, $outcome);
        self::assertSame(1, $rerunner->calls);
        self::assertCount(1, $transport->frames);
    }

    #[Test]
    public function a_control_with_no_local_context_is_a_safe_noop_no_crash_no_rerun_no_frame(): void
    {
        // THE DECISIVE EDGE (§C3): the control is drained where NO tier-2 record
        // exists — a non-owner worker drained it (tier-2 is worker-local, so the
        // miss models exactly that), or the stream was already torn down. R4 must
        // not crash, must not re-run, must not emit.
        $coalescer = RerunCoalescer::create(64);
        $coalescer->requestRerun('str_ghost');             // a stale mark for a stream this worker never owned
        $rerunner = $this->freshFrameReRunner();
        $transport = $this->captureTransport();
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $this->setTransport($transport);

        // No R5 connect for 'str_ghost' → SubscriptionDtoRegistry has no entry.
        self::assertFalse(SubscriptionDtoRegistry::has('str_ghost'));

        $outcome = $this->drain('sess_ghost', ['__ctrl' => 'rerun', 'streaming_id' => 'str_ghost']);

        self::assertSame(self::HANDLED_CONTINUE, $outcome, 'the control is consumed, not written as a data frame');
        self::assertSame(0, $rerunner->calls, 'no re-run on a missing context');
        self::assertCount(0, $transport->frames, 'no frame emitted on a missing context');
        self::assertFalse($coalescer->isPending('str_ghost'), 'the stale mark is cleared so it cannot wedge a future stream');
    }

    #[Test]
    public function a_torn_down_stream_drops_a_late_control_safely(): void
    {
        // The other half of the edge: the stream existed, then R5 onDisconnect
        // reaped tier-2; a control that was already in flight is drained AFTER
        // teardown → missing context → safe no-op.
        [$coordinator, , $coalescer] = $this->connect('str_a', 'sess_a', ['lead_submission']);
        $coordinator->onDisconnect('str_a');
        self::assertFalse(SubscriptionDtoRegistry::has('str_a'));

        $rerunner = $this->freshFrameReRunner();
        $transport = $this->captureTransport();
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $this->setTransport($transport);

        $outcome = $this->drain('sess_a', ['__ctrl' => 'rerun', 'streaming_id' => 'str_a']);

        self::assertSame(self::HANDLED_CONTINUE, $outcome);
        self::assertSame(0, $rerunner->calls);
        self::assertCount(0, $transport->frames);
    }

    // -----------------------------------------------------------------------
    // VERIFICATION 5 — the data-less-delete edge (MANDATORY, P3 carry-over)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_rerun_over_a_now_absent_resource_returns_an_empty_frame_without_stale_data(): void
    {
        // P3 publishes data-less, so R4 always does a full re-run. For a DELETE the
        // re-run re-queries a now-absent resource and the handler returns the
        // empty/"gone" shape. R4 writes it as-is: no crash, no stale data.
        [, , $coalescer] = $this->connect('str_a', 'sess_a', ['lead_submission']);
        $rerunner = $this->goneFrameReRunner();            // re-query finds nothing → empty rows
        $transport = $this->captureTransport();
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $this->setTransport($transport);

        $outcome = $this->drain('sess_a', ['__ctrl' => 'rerun', 'streaming_id' => 'str_a']);

        self::assertSame(self::HANDLED_CONTINUE, $outcome, 'an empty re-run does not crash and does not terminate');
        self::assertCount(1, $transport->frames);
        $wire = $transport->frames[0]->toWire();
        self::assertStringContainsString('"rows":[]', $wire, 'the empty/"gone" frame is written, not stale data');
    }

    // -----------------------------------------------------------------------
    // VERIFICATION 6 (regression) — ordinary data frames pass through untouched
    // -----------------------------------------------------------------------

    #[Test]
    public function an_ordinary_data_frame_is_not_a_control_and_is_left_for_the_existing_path(): void
    {
        $rerunner = $this->freshFrameReRunner();
        $transport = $this->captureTransport();
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setRerunCoalescer(RerunCoalescer::create(64));
        $this->setTransport($transport);

        // A normal data frame (no `__ctrl`): the branch reports NOT_CONTROL so the
        // caller writes it via the unchanged writeSse path. R4 neither re-runs nor
        // touches the transport for it.
        $outcome = $this->drain('sess_a', ['type' => 'ui.patch', 'value' => 99]);

        self::assertSame(self::NOT_CONTROL, $outcome);
        self::assertSame(0, $rerunner->calls);
        self::assertCount(0, $transport->frames, 'the branch does not write the data frame — the existing path does');
    }

    // -----------------------------------------------------------------------
    // Extra edges — R4 inert until wired; socket death on the fresh frame
    // -----------------------------------------------------------------------

    #[Test]
    public function a_control_is_a_safe_noop_until_the_rerunner_is_wired(): void
    {
        // Until R8 / the dispatcher brick wires the re-runner, a control is dropped
        // without a re-run and without a frame — R4 is inert but harmless.
        [, , $coalescer] = $this->connect('str_a', 'sess_a', ['lead_submission']);
        $coalescer->requestRerun('str_a');
        $transport = $this->captureTransport();
        AsyncResourceSseServer::setReRunner(null);          // not wired
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $this->setTransport($transport);

        $outcome = $this->drain('sess_a', ['__ctrl' => 'rerun', 'streaming_id' => 'str_a']);

        self::assertSame(self::HANDLED_CONTINUE, $outcome);
        self::assertCount(0, $transport->frames);
        self::assertFalse($coalescer->isPending('str_a'), 'the mark is still cleared so nothing wedges');
    }

    #[Test]
    public function a_dead_socket_on_the_fresh_frame_signals_close(): void
    {
        [, , $coalescer] = $this->connect('str_a', 'sess_a', ['lead_submission']);
        $rerunner = $this->freshFrameReRunner();
        $transport = $this->captureTransport();
        $transport->socketAlive = false;                    // the client vanished mid-stream
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setRerunCoalescer($coalescer);
        $this->setTransport($transport);

        $outcome = $this->drain('sess_a', ['__ctrl' => 'rerun', 'streaming_id' => 'str_a']);

        self::assertSame(self::HANDLED_CLOSE, $outcome, 'a failed fresh-frame write closes the stream');
        self::assertSame(1, $rerunner->calls);
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    /**
     * Drive the private static loop branch directly (the R3/R5 reflection
     * pattern). `$response` is the opaque transport handle — the capturing
     * transport ignores it, so a null stand-in is sufficient.
     *
     * @param array<string, mixed> $data
     */
    // -----------------------------------------------------------------------
    // VERIFICATION 9 — SSE transport unification · Phase 1: a SUBSCRIBE control
    // attaches a feed to a LIVE connection (one session → many subscriptions).
    // -----------------------------------------------------------------------

    #[Test]
    public function a_subscribe_control_registers_both_tiers_and_writes_a_tagged_initial_frame(): void
    {
        $subs = $this->staticCoordinator();
        $record = new SubscriptionRecord('str_b', 'sess_a', 'default', ['orders'], 'tenant-blob');
        AsyncResourceSseServer::setSubscriptionFactory($this->fakeFactory($record, $this->reRunContext('sess_a')));
        AsyncResourceSseServer::setReRunner($this->freshFrameReRunner());
        $transport = $this->captureTransport();
        $this->setTransport($transport);

        $outcome = $this->drain('sess_a', [
            '__ctrl' => 'subscribe',
            'streaming_id' => 'str_b',
            'route_path' => '/orders',
            'route_method' => 'GET',
            'request_snapshot' => [],
        ]);

        self::assertSame(self::HANDLED_CONTINUE, $outcome);
        self::assertTrue($subs->has('str_b'), 'tier-1 cross-worker row registered');
        self::assertTrue(SubscriptionDtoRegistry::has('str_b'), 'tier-2 re-run context registered on the owning worker');
        self::assertCount(1, $transport->frames);
        // The initial frame is tagged so the client demuxes it among the
        // connection's other subscriptions.
        self::assertStringContainsString('"streaming_id":"str_b"', $transport->frames[0]->toWire());
    }

    #[Test]
    public function two_distinct_subscriptions_coexist_under_one_session(): void
    {
        $subs = $this->staticCoordinator();
        // A first subscription already live on this connection (str_a / sess_a).
        $this->staticOnConnect('str_a', 'sess_a', ['leads']);
        AsyncResourceSseServer::setSubscriptionFactory(
            $this->fakeFactory(new SubscriptionRecord('str_b', 'sess_a', 'default', ['orders'], 'tenant-blob'), $this->reRunContext('sess_a')),
        );
        AsyncResourceSseServer::setReRunner($this->freshFrameReRunner());
        $this->setTransport($this->captureTransport());

        $this->drain('sess_a', [
            '__ctrl' => 'subscribe', 'streaming_id' => 'str_b',
            'route_path' => '/orders', 'route_method' => 'GET', 'request_snapshot' => [],
        ]);

        // Both subscriptions live under ONE session — the multiplex invariant.
        self::assertTrue($subs->has('str_a'));
        self::assertTrue($subs->has('str_b'));
        self::assertSame('sess_a', $subs->get('str_a')?->sessionId);
        self::assertSame('sess_a', $subs->get('str_b')?->sessionId);
    }

    #[Test]
    public function the_subscribe_threads_the_connections_captured_tenant_into_the_factory(): void
    {
        $this->staticCoordinator();
        // A recording factory that captures exactly what the control handler
        // threads into build() for the tenant scoping.
        $factory = new class(
            new SubscriptionRecord('str_b', 'sess_a', 'acme', ['orders'], '{"org":"acme"}'),
            $this->reRunContext('sess_a'),
        ) implements SubscriptionFactoryInterface {
            public ?string $seenTenantId = null;
            public ?string $seenTenantBlob = null;

            public function __construct(private readonly SubscriptionRecord $record, private readonly ReRunContext $context) {}

            public function build(string $sessionId, string $streamingId, string $routePath, string $routeMethod, array $requestSnapshot, ?string $tenantId = null, ?string $tenantBlob = null): ?SubscriptionAttachment
            {
                $this->seenTenantId = $tenantId;
                $this->seenTenantBlob = $tenantBlob;
                return new SubscriptionAttachment($this->record, $this->context);
            }
        };
        AsyncResourceSseServer::setSubscriptionFactory($factory);
        AsyncResourceSseServer::setReRunner($this->freshFrameReRunner());
        $this->setTransport($this->captureTransport());

        // The KISS connect captured tenant 'acme' at admit, in its own (tenant-
        // authoritative) coroutine. The subscribe control rides a possibly
        // different coroutine, so the handler must scope the record from the
        // captured value — NOT the draining coroutine's ambient tenant.
        $this->seedSessionTenant('sess_a', 'acme', '{"org":"acme"}');

        $this->drain('sess_a', [
            '__ctrl' => 'subscribe', 'streaming_id' => 'str_b',
            'route_path' => '/orders', 'route_method' => 'GET', 'request_snapshot' => [],
        ]);

        self::assertSame('acme', $factory->seenTenantId, 'the connection-captured tenant id must be threaded into build()');
        self::assertSame('{"org":"acme"}', $factory->seenTenantBlob, 'the captured tenant blob must be threaded into build()');
    }

    #[Test]
    public function a_terminating_subscribe_is_denied_and_registers_no_record(): void
    {
        $subs = $this->staticCoordinator();
        AsyncResourceSseServer::setSubscriptionFactory(
            $this->fakeFactory(new SubscriptionRecord('str_b', 'sess_a', 'default', ['orders'], 'tenant-blob'), $this->reRunContext('sess_a')),
        );
        // The feed's own auth gate denies → TERMINATE on the initial re-run.
        AsyncResourceSseServer::setReRunner($this->terminatingReRunner('access_revoked'));
        $transport = $this->captureTransport();
        $this->setTransport($transport);

        $outcome = $this->drain('sess_a', [
            '__ctrl' => 'subscribe', 'streaming_id' => 'str_b',
            'route_path' => '/orders', 'route_method' => 'GET', 'request_snapshot' => [],
        ]);

        self::assertSame(self::HANDLED_CONTINUE, $outcome);
        self::assertFalse($subs->has('str_b'), 'denied subscribe registers no tier-1 row');
        self::assertFalse(SubscriptionDtoRegistry::has('str_b'), 'denied subscribe registers no tier-2 context');
        self::assertCount(1, $transport->frames);
        $wire = $transport->frames[0]->toWire();
        self::assertStringContainsString('subscribe_denied', $wire);
        self::assertStringNotContainsString('"rows"', $wire, 'no data frame on a denied subscribe');
    }

    #[Test]
    public function an_unresolved_route_denies_the_subscribe(): void
    {
        $subs = $this->staticCoordinator();
        // Factory returns null (route not found).
        AsyncResourceSseServer::setSubscriptionFactory(new class implements SubscriptionFactoryInterface {
            public function build(string $sessionId, string $streamingId, string $routePath, string $routeMethod, array $requestSnapshot, ?string $tenantId = null, ?string $tenantBlob = null): ?SubscriptionAttachment
            {
                return null;
            }
        });
        AsyncResourceSseServer::setReRunner($this->freshFrameReRunner());
        $transport = $this->captureTransport();
        $this->setTransport($transport);

        $this->drain('sess_a', [
            '__ctrl' => 'subscribe', 'streaming_id' => 'str_b',
            'route_path' => '/nope', 'route_method' => 'GET', 'request_snapshot' => [],
        ]);

        self::assertFalse($subs->has('str_b'));
        self::assertStringContainsString('subscribe_unresolved', $transport->frames[0]->toWire());
    }

    #[Test]
    public function an_unsubscribe_control_detaches_only_its_subscription(): void
    {
        $subs = $this->staticCoordinator();
        $this->staticOnConnect('str_a', 'sess_a', ['leads']);
        $this->staticOnConnect('str_b', 'sess_a', ['orders']);
        $this->setTransport($this->captureTransport());

        $this->drain('sess_a', ['__ctrl' => 'unsubscribe', 'streaming_id' => 'str_b']);

        self::assertFalse($subs->has('str_b'), 'the named subscription is reaped');
        self::assertFalse(SubscriptionDtoRegistry::has('str_b'));
        self::assertTrue($subs->has('str_a'), 'the sibling subscription on the same session survives');
        self::assertTrue(SubscriptionDtoRegistry::has('str_a'));
    }

    private function drain(string $sessionId, array $data): int
    {
        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'handleControlFrame');
        $method->setAccessible(true);

        return (int) $method->invoke(null, $sessionId, null, $data);
    }

    /**
     * Wire a REAL R5 coordinator as the STATIC coordinator the multiplex
     * attach/detach branches drive, returning its tier-1 table for assertions.
     */
    private function staticCoordinator(): SubscriptionTable
    {
        $subs = SubscriptionTable::create(64);
        $coalescer = RerunCoalescer::create(64);
        $subscriber = new ResourceInvalidationSubscriber(
            new ScanningSubscriberIndex($subs),
            $subs,
            $coalescer,
            new RedisSubscribeConnectionFactory(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'password' => '']),
            $this->nullDelivery(),
        );
        AsyncResourceSseServer::setConnectCoordinator(new ConnectCoordinator($subs, $subscriber, $coalescer, $this->nullChannels()));

        return $subs;
    }

    /** Register a subscription through the static coordinator (a pre-existing live sub). */
    private function staticOnConnect(string $streamingId, string $sessionId, array $scopeKeys): void
    {
        AsyncResourceSseServer::attachSubscription(
            new SubscriptionRecord($streamingId, $sessionId, 'default', $scopeKeys, 'tenant-blob'),
            $this->reRunContext($sessionId),
        );
    }

    /** A factory that returns a fixed, controlled attachment. */
    private function fakeFactory(SubscriptionRecord $record, ReRunContext $context): SubscriptionFactoryInterface
    {
        return new class($record, $context) implements SubscriptionFactoryInterface {
            public function __construct(private readonly SubscriptionRecord $record, private readonly ReRunContext $context) {}

            public function build(string $sessionId, string $streamingId, string $routePath, string $routeMethod, array $requestSnapshot, ?string $tenantId = null, ?string $tenantBlob = null): ?SubscriptionAttachment
            {
                return new SubscriptionAttachment($this->record, $this->context);
            }
        };
    }

    /** Seed the worker-local per-session state the KISS connect captures at admit
     *  (tenant resolved in the connection's own authoritative coroutine). */
    private function seedSessionTenant(string $sessionId, string $tenantId, string $tenantBlob): void
    {
        $prop = new \ReflectionProperty(AsyncResourceSseServer::class, 'sessions');
        $prop->setAccessible(true);
        /** @var array<string, array<string, mixed>> $sessions */
        $sessions = $prop->getValue();
        $sessions[$sessionId] = [
            'response' => null,
            'connected_at' => 0,
            'tenant_id' => $tenantId,
            'tenant_blob' => $tenantBlob,
        ];
        $prop->setValue(null, $sessions);
    }

    /** Drop all seeded sessions so a seeded tenant cannot bleed into a sibling test. */
    private function clearServerSessions(): void
    {
        $prop = new \ReflectionProperty(AsyncResourceSseServer::class, 'sessions');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * Populate the store via a REAL R5 connect (not a fabricated registry write):
     * onConnect places tier-2 (the ReRunContext R4 resolves) and tier-1 (the row).
     *
     * @param list<string> $scopeKeys
     * @return array{0: ConnectCoordinator, 1: SubscriptionTable, 2: RerunCoalescer}
     */
    private function connect(string $streamingId, string $sessionId, array $scopeKeys): array
    {
        $subs = SubscriptionTable::create(64);
        $coalescer = RerunCoalescer::create(64);
        $subscriber = new ResourceInvalidationSubscriber(
            new ScanningSubscriberIndex($subs),
            $subs,
            $coalescer,
            new RedisSubscribeConnectionFactory(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'password' => '']),
            $this->nullDelivery(),
        );
        $coordinator = new ConnectCoordinator($subs, $subscriber, $coalescer, $this->nullChannels());

        $coordinator->onConnect(
            new SubscriptionRecord($streamingId, $sessionId, 'default', $scopeKeys, 'tenant-blob'),
            $this->reRunContext($sessionId),
        );

        return [$coordinator, $subs, $coalescer];
    }

    // -----------------------------------------------------------------------
    // VERIFICATION — SSE transport unification · Phase 1.5: closing a KISS
    // connection reaps EVERY multiplex subscription bound to its session
    // (distinct streaming_ids, one shared session), and only those.
    // -----------------------------------------------------------------------

    #[Test]
    public function reaping_a_session_disconnects_all_its_subscriptions_and_only_those(): void
    {
        $subs = SubscriptionTable::create(64);
        $coalescer = RerunCoalescer::create(64);
        $subscriber = new ResourceInvalidationSubscriber(
            new ScanningSubscriberIndex($subs),
            $subs,
            $coalescer,
            new RedisSubscribeConnectionFactory(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'password' => '']),
            $this->nullDelivery(),
        );
        $coordinator = new ConnectCoordinator($subs, $subscriber, $coalescer, $this->nullChannels());

        // Two subscriptions on ONE KISS session + one on a different session.
        $coordinator->onConnect(new SubscriptionRecord('str_a', 'sess_x', 'default', ['s1'], 'b'), $this->reRunContext('sess_x'));
        $coordinator->onConnect(new SubscriptionRecord('str_b', 'sess_x', 'default', ['s2'], 'b'), $this->reRunContext('sess_x'));
        $coordinator->onConnect(new SubscriptionRecord('str_c', 'sess_y', 'default', ['s3'], 'b'), $this->reRunContext('sess_y'));

        $reaped = $coordinator->reapSession('sess_x');

        sort($reaped);
        self::assertSame(['str_a', 'str_b'], $reaped);
        // Both tiers of the reaped session are gone…
        self::assertFalse($subs->has('str_a'));
        self::assertFalse($subs->has('str_b'));
        self::assertNull(SubscriptionDtoRegistry::get('str_a'));
        self::assertNull(SubscriptionDtoRegistry::get('str_b'));
        // …while the other session's subscription is untouched.
        self::assertTrue($subs->has('str_c'));
        self::assertNotNull(SubscriptionDtoRegistry::get('str_c'));
    }

    /** A re-runner that re-queries a fresh, incrementing value each run. */
    private function freshFrameReRunner(): ReRunnerInterface
    {
        return new class implements ReRunnerInterface {
            public int $calls = 0;

            public function reRun(ReRunContext $context, array $filterOverride = []): ReRunResult
            {
                $this->calls++;

                return ReRunResult::frame(HttpResponse::json(['rows' => [['id' => 1]], 'value' => $this->calls]));
            }
        };
    }

    /** A re-runner whose live re-auth now denies → TERMINATE. */
    private function terminatingReRunner(string $reason): ReRunnerInterface
    {
        return new class($reason) implements ReRunnerInterface {
            public int $calls = 0;

            public function __construct(private readonly string $reason) {}

            public function reRun(ReRunContext $context, array $filterOverride = []): ReRunResult
            {
                $this->calls++;

                return ReRunResult::terminate($this->reason);
            }
        };
    }

    /** A re-runner whose re-query finds the resource gone (the delete edge). */
    private function goneFrameReRunner(): ReRunnerInterface
    {
        return new class implements ReRunnerInterface {
            public int $calls = 0;

            public function reRun(ReRunContext $context, array $filterOverride = []): ReRunResult
            {
                $this->calls++;

                return ReRunResult::frame(HttpResponse::json(['rows' => []]));
            }
        };
    }

    /** A capturing transport recording every frame that reaches the wire. */
    private function captureTransport(): SseTransportInterface
    {
        return new class implements SseTransportInterface {
            /** @var list<SseFrame> */
            public array $frames = [];
            public int $comments = 0;
            public bool $socketAlive = true;

            public function writeFrame(mixed $stream, SseFrame $frame): bool
            {
                if (!$this->socketAlive) {
                    return false;
                }
                $this->frames[] = $frame;

                return true;
            }

            public function writeComment(mixed $stream): bool
            {
                if (!$this->socketAlive) {
                    return false;
                }
                $this->comments++;

                return true;
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

    private function nullDelivery(): SessionControlDeliveryInterface
    {
        return new class implements SessionControlDeliveryInterface {
            public function deliverControl(string $sessionId, array $control): void {}
        };
    }

    private function reRunContext(string $sessionId): ReRunContext
    {
        return new ReRunContext(
            cachedDto: new \stdClass(),
            route: new DiscoveredRoute(
                path: '/leads',
                methods: ['GET'],
                name: 'leads.live',
                requestClass: \stdClass::class,
                responseClass: \stdClass::class,
                handlers: [],
                type: 'http_request',
                transport: 'sse',
                produces: null,
                consumes: null,
                module: 'core',
            ),
            requestSnapshot: ['method' => 'GET', 'uri' => '/leads', 'cookies' => ['sid' => $sessionId]],
            sessionId: $sessionId,
            subjectRef: 'alice',
        );
    }
}
