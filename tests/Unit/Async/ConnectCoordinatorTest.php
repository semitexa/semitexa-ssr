<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Ssr\Application\Service\Async\ConnectCoordinator;
use Semitexa\Ssr\Application\Service\Async\RedisSubscribeConnectionFactory;
use Semitexa\Ssr\Application\Service\Async\RerunCoalescer;
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationPublisher;
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationSubscriber;
use Semitexa\Ssr\Application\Service\Async\ScanningSubscriberIndex;
use Semitexa\Ssr\Application\Service\Async\SubscriptionDtoRegistry;
use Semitexa\Ssr\Application\Service\Async\SubscriptionTable;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\CreateTrackRTablesListener;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\TrackRSharedTables;
use Semitexa\Ssr\Domain\Contract\ChannelSubscriptionControllerInterface;
use Semitexa\Ssr\Domain\Contract\SessionControlDeliveryInterface;
use Semitexa\Ssr\Domain\Model\SubscriberRef;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * Track R · R5 — the connect coordinator, proven on a SYNTHETIC connect (design
 * §D R5). A test supplies a sessionId + scope + a {@see ReRunContext}; no live
 * HTTP / kiss subscription, no live Redis, no running coroutine loop. A capturing
 * {@see ChannelSubscriptionControllerInterface} double records exactly which
 * channels are (un)subscribed, the same way R3 used a capturing delivery double.
 *
 * Proves: connect populates BOTH planes on the correct surfaces (tier-1 row
 * cross-worker visible, tier-2 ReRunContext worker-local and NOT in the table);
 * subscribe-on-first / unsubscribe-on-last; disconnect tears down fully with no
 * zombie; the store is left in exactly the state R4 will consume; the coalescer
 * table is created pre-fork; and R5 neither catches the control nor runs the
 * re-run.
 */
final class ConnectCoordinatorTest extends TestCase
{
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
    }

    // -----------------------------------------------------------------------
    // #1 — connect populates BOTH planes on the correct surfaces
    // -----------------------------------------------------------------------

    #[Test]
    public function connect_populates_tier1_cross_worker_and_tier2_worker_local(): void
    {
        [$coordinator, $subs] = $this->coordinator();
        $context = $this->reRunContext('sse_session_a');

        $coordinator->onConnect(
            $this->record('str_a', 'sse_session_a', 'default', ['lead_submission'], 'tenant-blob-bytes'),
            $context,
        );

        // Tier 1 (cross-worker): the serialized row is present and VISIBLE to a
        // non-owner reader — a second reverse index over the SAME shared table (a
        // stand-in for worker X) resolves it.
        self::assertTrue($subs->has('str_a'));
        $crossWorkerView = new ScanningSubscriberIndex($subs);
        $hits = $crossWorkerView->find('default', 'lead_submission');
        self::assertCount(1, $hits);
        self::assertSame('str_a', $hits[0]->streamingId);
        self::assertSame('sse_session_a', $hits[0]->sessionId);

        // Tier 2 (worker-local): the live ReRunContext is in the worker-static
        // registry under the SAME streaming_id — the exact object, never a copy.
        self::assertTrue(SubscriptionDtoRegistry::has('str_a'));
        self::assertSame($context, SubscriptionDtoRegistry::get('str_a'));

        // The invariant end-to-end: the ReRunContext is worker-local ONLY — the
        // cross-worker table row is a plain SubscriptionRecord, NOT the live object.
        $row = $subs->get('str_a');
        self::assertInstanceOf(SubscriptionRecord::class, $row);
        self::assertNotInstanceOf(ReRunContext::class, $row);
    }

    // -----------------------------------------------------------------------
    // #2 — subscribe-on-first (and no re-subscribe of an already-watched scope)
    // -----------------------------------------------------------------------

    #[Test]
    public function subscribe_fires_on_the_first_connect_for_a_scope_only(): void
    {
        [$coordinator, , , $channels] = $this->coordinator();
        $channel = ResourceInvalidationPublisher::channelFor('default', 'lead_submission');

        // First connect for the scope → subscribe its channel.
        $coordinator->onConnect($this->record('str_a', 'sess_a', 'default', ['lead_submission']), $this->reRunContext('sess_a'));
        self::assertSame([$channel], $channels->subscribed);
        self::assertSame([$channel], $coordinator->currentChannels());

        // Second connect, SAME scope → the channel is already watched, so NO new
        // subscribe (subscribe-on-FIRST only).
        $coordinator->onConnect($this->record('str_b', 'sess_b', 'default', ['lead_submission']), $this->reRunContext('sess_b'));
        self::assertSame([$channel], $channels->subscribed, 'second subscriber on the same scope does not re-subscribe');
        self::assertSame([], $channels->unsubscribed);
    }

    // -----------------------------------------------------------------------
    // #3 — disconnect tears down fully: no zombie (design §B.5)
    // -----------------------------------------------------------------------

    #[Test]
    public function disconnect_tears_down_every_tier_with_no_zombie(): void
    {
        [$coordinator, $subs, $coalescer, $channels] = $this->coordinator();
        $channel = ResourceInvalidationPublisher::channelFor('default', 'lead_submission');

        $coordinator->onConnect($this->record('str_a', 'sse_session_a', 'default', ['lead_submission']), $this->reRunContext('sse_session_a'));

        // Simulate a re-run having been coalesced (a pending mark) for this stream,
        // so we can prove the teardown clears it (no leaked coalescer counter).
        self::assertTrue($coalescer->requestRerun('str_a'));
        self::assertTrue($coalescer->isPending('str_a'));

        $coordinator->onDisconnect('str_a');

        // Tier 1 row gone.
        self::assertFalse($subs->has('str_a'));
        self::assertNull($subs->get('str_a'));
        // Tier 2 live state gone (no orphaned DTO).
        self::assertFalse(SubscriptionDtoRegistry::has('str_a'));
        self::assertNull(SubscriptionDtoRegistry::get('str_a'));
        // Coalescer mark cleared.
        self::assertFalse($coalescer->isPending('str_a'));
        // Unsubscribe-on-last fired (this was the last subscriber for the scope).
        self::assertSame([$channel], $channels->unsubscribed);
        self::assertSame([], $coordinator->currentChannels(), 'no leaked channel subscription remains');

        // The store is clean: nothing about the stream remains in any tier.
        self::assertSame(0, $subs->count());
        self::assertSame(0, SubscriptionDtoRegistry::count());
        self::assertSame(0, $coalescer->count());
    }

    #[Test]
    public function unsubscribe_fires_only_on_the_last_subscriber_for_a_scope(): void
    {
        [$coordinator, , , $channels] = $this->coordinator();
        $channel = ResourceInvalidationPublisher::channelFor('default', 'lead_submission');

        $coordinator->onConnect($this->record('str_a', 'sess_a', 'default', ['lead_submission']), $this->reRunContext('sess_a'));
        $coordinator->onConnect($this->record('str_b', 'sess_b', 'default', ['lead_submission']), $this->reRunContext('sess_b'));

        // Disconnect ONE of two on the scope → channel stays (still has a subscriber).
        $coordinator->onDisconnect('str_a');
        self::assertSame([], $channels->unsubscribed, 'channel kept while a subscriber remains');
        self::assertSame([$channel], $coordinator->currentChannels());

        // Disconnect the LAST → unsubscribe fires.
        $coordinator->onDisconnect('str_b');
        self::assertSame([$channel], $channels->unsubscribed);
        self::assertSame([], $coordinator->currentChannels());
    }

    // -----------------------------------------------------------------------
    // #4 — two planes, correct surfaces (cross-worker placement is correct)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_live_context_is_worker_local_not_serialized_into_the_shared_table(): void
    {
        [$coordinator, $subs] = $this->coordinator();

        $coordinator->onConnect(
            $this->record('str_a', 'sse_session_a', 'default', ['lead_submission'], 'tenant-blob-bytes'),
            $this->reRunContext('sse_session_a'),
        );

        // The cross-worker tier carries ONLY serializable scalars — proven by R1's
        // schema (no DTO/object column) — so the live identity-bearing object CANNOT
        // be expressed there. The only tenant carrier in the row is the opaque blob.
        foreach (SubscriptionTable::schemaColumns() as $column) {
            self::assertDoesNotMatchRegularExpression('/dto|object|payload|rerun|context/i', $column);
        }
        $row = $subs->get('str_a');
        self::assertNotNull($row);
        self::assertSame('tenant-blob-bytes', $row->tenantBlob, 'tenant travels cross-worker as an opaque blob, not a live object');

        // The live ReRunContext is reachable ONLY through the worker-local registry —
        // the cross-worker surfaces (table + coalescer) never hold it.
        self::assertInstanceOf(ReRunContext::class, SubscriptionDtoRegistry::get('str_a'));
    }

    // -----------------------------------------------------------------------
    // #5 — sets the store up for R4 (without R5 running the re-run)
    // -----------------------------------------------------------------------

    #[Test]
    public function after_connect_a_rerun_control_resolves_to_the_stored_context_for_r4(): void
    {
        [$coordinator] = $this->coordinator();
        $context = $this->reRunContext('sse_session_a');

        $coordinator->onConnect($this->record('str_a', 'sse_session_a', 'default', ['lead_submission']), $context);

        // What R4 will do on draining `{__ctrl:rerun, streaming_id:'str_a'}` off the
        // stream's queue: resolve the worker-local ReRunContext by streaming_id. R5
        // has left the store in exactly that state — R4 finds the SAME context R5
        // placed. (R5 does NOT itself perform this resolution-then-run; this asserts
        // the store state R4 will consume.)
        $controlFromQueue = ['__ctrl' => 'rerun', 'streaming_id' => 'str_a', 'scope_key' => 'lead_submission'];
        $resolved = SubscriptionDtoRegistry::get($controlFromQueue['streaming_id']);
        self::assertSame($context, $resolved);
    }

    // -----------------------------------------------------------------------
    // #6 (C2) — the coalescer (+ tier-1) tables are created pre-fork
    // -----------------------------------------------------------------------

    #[Test]
    public function shared_tables_are_built_once_pre_fork_via_the_single_schema_site(): void
    {
        // The single-schema-site builder (what the PreStart listener calls) yields
        // the two cross-worker shared surfaces, both real and operational.
        $shared = CreateTrackRTablesListener::buildSharedTables(64);
        self::assertInstanceOf(TrackRSharedTables::class, $shared);
        self::assertInstanceOf(SubscriptionTable::class, $shared->subscriptions);
        self::assertInstanceOf(RerunCoalescer::class, $shared->coalescer);

        // Both tables are live: a row + a coalesce mark round-trip.
        $shared->subscriptions->insert($this->record('str_a', 'sess_a', 'default', ['lead_submission']));
        self::assertTrue($shared->subscriptions->has('str_a'));
        self::assertTrue($shared->coalescer->requestRerun('str_a'));
        self::assertTrue($shared->coalescer->isPending('str_a'));

        // The listener runs in the PreStart phase — the pre-fork point — so every
        // worker inherits ONE shared coalescer (and tier-1 table), not a private copy.
        $attrs = (new \ReflectionClass(CreateTrackRTablesListener::class))
            ->getAttributes(\Semitexa\Core\Attribute\AsServerLifecycleListener::class);
        self::assertNotEmpty($attrs);
        self::assertSame(
            \Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase::PreStart->value,
            $attrs[0]->newInstance()->phase,
            'Track-R shared tables must be created before worker fork',
        );
    }

    // -----------------------------------------------------------------------
    // Scope fence — R5 does NOT catch the control or run the re-run (R4's job)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_coordinator_neither_catches_the_control_nor_runs_the_rerun(): void
    {
        // R5 STORES the ReRunContext; R4 catches `{__ctrl:rerun}` and calls R2. The
        // coordinator's EXECUTABLE code must not reach for the re-runner / reExecute
        // (docblocks may name them while explaining the fence — strip comments first).
        $code = $this->codeWithoutComments(ConnectCoordinator::class);
        self::assertStringNotContainsString('reExecute', $code);
        self::assertStringNotContainsString('ReRunner', $code);
        self::assertStringNotContainsString('handleMessage', $code, 'R5 does not catch/route the control (R3/R4)');

        // No constructor dependency is a re-runner / route-executor type — R5
        // structurally cannot execute a re-run, only populate the store for one.
        $ctor = (new \ReflectionClass(ConnectCoordinator::class))->getConstructor();
        self::assertNotNull($ctor);
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            self::assertStringNotContainsString('ReRunner', $typeName);
            self::assertStringNotContainsString('RouteExecutor', $typeName);
        }
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    /**
     * Wire a ConnectCoordinator over real R1 + R3 stores (real Swoole\Tables), the
     * real R3 subscriber (for its desiredChannels/channelDiff seam), and a
     * capturing channel-subscription double.
     *
     * @return array{0: ConnectCoordinator, 1: SubscriptionTable, 2: RerunCoalescer, 3: object}
     */
    private function coordinator(): array
    {
        $subs = SubscriptionTable::create(64);
        $coalescer = RerunCoalescer::create(64);
        $channels = $this->captureChannels();

        $subscriber = new ResourceInvalidationSubscriber(
            new ScanningSubscriberIndex($subs),
            $subs,
            $coalescer,
            new RedisSubscribeConnectionFactory(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'password' => '']),
            $this->nullDelivery(),
        );

        $coordinator = new ConnectCoordinator($subs, $subscriber, $coalescer, $channels);

        return [$coordinator, $subs, $coalescer, $channels];
    }

    /** A capturing channel controller: records every (un)subscribed channel. */
    private function captureChannels(): ChannelSubscriptionControllerInterface
    {
        return new class implements ChannelSubscriptionControllerInterface {
            /** @var list<string> */
            public array $subscribed = [];
            /** @var list<string> */
            public array $unsubscribed = [];

            public function subscribe(array $channels): void
            {
                foreach ($channels as $channel) {
                    $this->subscribed[] = $channel;
                }
            }

            public function unsubscribe(array $channels): void
            {
                foreach ($channels as $channel) {
                    $this->unsubscribed[] = $channel;
                }
            }
        };
    }

    /** The coordinator never delivers controls; the subscriber just needs a binding. */
    private function nullDelivery(): SessionControlDeliveryInterface
    {
        return new class implements SessionControlDeliveryInterface {
            public function deliverControl(string $sessionId, array $control): void {}
        };
    }

    /**
     * @param list<string> $scopeKeys
     */
    private function record(
        string $streamingId,
        string $sessionId,
        string $tenantId,
        array $scopeKeys,
        string $tenantBlob = '',
    ): SubscriptionRecord {
        return new SubscriptionRecord($streamingId, $sessionId, $tenantId, $scopeKeys, $tenantBlob);
    }

    /** A real, minimal R2 ReRunContext — the live, never-serialized re-run state. */
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

    /**
     * The EXECUTABLE source of a class with comments/docblocks stripped, so a
     * "the code never references X" assertion is not tripped by prose that names the
     * forbidden symbol while documenting the fence (the R3-test pattern).
     */
    private function codeWithoutComments(string $class): string
    {
        $file = (string) (new \ReflectionClass($class))->getFileName();
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }
}
