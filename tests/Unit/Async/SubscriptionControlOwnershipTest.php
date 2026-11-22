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
use Semitexa\Core\Support\Row;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\ConnectCoordinator;
use Semitexa\Ssr\Application\Service\Async\RedisSubscribeConnectionFactory;
use Semitexa\Ssr\Application\Service\Async\RerunCoalescer;
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationSubscriber;
use Semitexa\Ssr\Application\Service\Async\ScanningSubscriberIndex;
use Semitexa\Ssr\Application\Service\Async\SseControlFrame;
use Semitexa\Ssr\Application\Service\Async\SseControlRouter;
use Semitexa\Ssr\Application\Service\Async\SseFrameFactory;
use Semitexa\Ssr\Application\Service\Async\SseReRunScope;
use Semitexa\Ssr\Application\Service\Async\SseRuntime;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;
use Semitexa\Ssr\Application\Service\Async\SubscriptionDtoRegistry;
use Semitexa\Ssr\Application\Service\Async\SubscriptionTable;
use Semitexa\Ssr\Application\Service\Async\ViewChangeCoalescer;
use Semitexa\Ssr\Domain\Contract\ChannelSubscriptionControllerInterface;
use Semitexa\Ssr\Domain\Contract\SessionControlDeliveryInterface;
use Semitexa\Ssr\Domain\Contract\SubscriptionFactoryInterface;
use Semitexa\Ssr\Domain\Model\SubscriptionAttachment;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * A control that names a subscription acts only for the session that owns it.
 *
 * Session A holds a subscription; session B drains controls naming A's id on
 * its own socket. B must get nothing and change nothing, A's own controls must
 * still work, and B must not be able to tell A's id from one nobody holds.
 */
final class SubscriptionControlOwnershipTest extends TestCase
{
    private const A = 'sse_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B = 'sse_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const SUB = 'sse_cccccccccccccccccccccccccccccccc';
    private const GHOST = 'sse_dddddddddddddddddddddddddddddddd';
    private const A_SOCKET = 'socket-of-a';
    private const B_SOCKET = 'socket-of-b';

    private SseRuntime $runtime;
    private SubscriptionTable $table;
    private RerunCoalescer $reruns;
    private ViewChangeCoalescer $views;
    private SseControlRouter $router;
    /** @var list<array{0: mixed, 1: string}> [socket, wire] */
    private array $written = [];
    /** @var list<array{0: string, 1: array<string, mixed>}> [owner session, override] */
    private array $reRuns = [];
    private int $factoryCalls = 0;

    protected function setUp(): void
    {
        SubscriptionDtoRegistry::clear();
        $this->forget();
        $this->factoryCalls = 0;

        $this->table = SubscriptionTable::create(64);
        $this->reruns = RerunCoalescer::create(64);
        $this->views = ViewChangeCoalescer::create(64);

        $this->runtime = new SseRuntime();
        $this->runtime->rerunCoalescer = $this->reruns;
        $this->runtime->viewChangeCoalescer = $this->views;
        $this->runtime->connectCoordinator = new ConnectCoordinator(
            $this->table,
            new ResourceInvalidationSubscriber(
                new ScanningSubscriberIndex($this->table),
                $this->table,
                $this->reruns,
                new RedisSubscribeConnectionFactory(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'password' => '']),
                new class implements SessionControlDeliveryInterface {
                    public function deliverControl(string $sessionId, array $control): void {}
                },
            ),
            $this->reruns,
            new class implements ChannelSubscriptionControllerInterface {
                public function subscribe(array $channels): void {}

                public function unsubscribe(array $channels): void {}
            },
            $this->views,
        );
        $this->runtime->reRunner = new class(function (string $owner, array $override): void {
            $this->reRuns[] = [$owner, Row::keyedByName($override)];
        }) implements ReRunnerInterface {
            /** @param \Closure(string, array<array-key, mixed>): void $log */
            public function __construct(private \Closure $log) {}

            public function reRun(ReRunContext $context, array $filterOverride = []): ReRunResult
            {
                ($this->log)($context->getSessionId(), $filterOverride);

                return ReRunResult::frame(HttpResponse::json(['owner' => $context->getSessionId(), 'view' => $filterOverride]));
            }
        };
        $this->runtime->transport = new class(function (mixed $socket, string $wire): void {
            $this->written[] = [$socket, $wire];
        }) implements SseTransportInterface {
            /** @param \Closure(mixed, string): void $out */
            public function __construct(private \Closure $out) {}

            public function writeFrame(mixed $stream, SseFrame $frame): bool
            {
                ($this->out)($stream, $frame->toWire());

                return true;
            }

            public function writeComment(mixed $stream): bool
            {
                return true;
            }
        };
        $this->runtime->subscriptionFactory = new class(function (): void {
            $this->factoryCalls++;
        }) implements SubscriptionFactoryInterface {
            /** @param \Closure(): void $built */
            public function __construct(private \Closure $built) {}

            public function build(string $sessionId, string $streamingId, string $routePath, string $routeMethod, array $requestSnapshot, ?string $tenantId = null, ?string $tenantBlob = null, string $routeName = ''): SubscriptionAttachment
            {
                ($this->built)();

                return new SubscriptionAttachment(
                    new SubscriptionRecord($streamingId, $sessionId, 'default', ['leads'], 'blob'),
                    SubscriptionControlOwnershipTest::context($sessionId),
                );
            }
        };

        $this->router = new SseControlRouter($this->runtime, new SseFrameFactory(), new SseSessionRegistry(), new SseReRunScope());

        // Session A's live subscription, on this worker and in the routing table.
        $this->router->attach(new SubscriptionRecord(self::SUB, self::A, 'default', ['leads'], 'blob'), self::context(self::A));
    }

    protected function tearDown(): void
    {
        SubscriptionDtoRegistry::clear();
        AsyncResourceSseServer::setConnectCoordinator(null);
        AsyncResourceSseServer::setViewChangeCoalescer(null);
    }

    // -- session B naming A's id gets nothing and changes nothing -------------

    #[Test]
    public function a_foreign_viewchange_runs_nothing_writes_nothing_and_leaves_the_owners_pending_view(): void
    {
        $this->views->submit(self::SUB, ['page' => 7]);

        $outcome = $this->router->handle(self::B, self::B_SOCKET, SseControlFrame::viewChange(self::SUB, ['page' => 99]));

        self::assertSame(SseControlFrame::HANDLED_CONTINUE, $outcome);
        self::assertSame([], $this->reRuns(), "A's re-run state was not executed for B");
        self::assertSame([], $this->written(), "nothing of A's feed reached B's socket");
        self::assertSame(['page' => 7], $this->views->peek(self::SUB), "A's pending view is still A's");
    }

    #[Test]
    public function a_foreign_rerun_runs_nothing_writes_nothing_and_leaves_the_owners_pending_mark(): void
    {
        $this->reruns->requestRerun(self::SUB);

        $outcome = $this->router->handle(self::B, self::B_SOCKET, SseControlFrame::rerun(self::SUB, 'leads'));

        self::assertSame(SseControlFrame::HANDLED_CONTINUE, $outcome);
        self::assertSame([], $this->reRuns());
        self::assertSame([], $this->written());
        self::assertTrue($this->reruns->isPending(self::SUB), "A's coalesced re-run is still pending");
    }

    #[Test]
    public function a_foreign_unsubscribe_detaches_nothing(): void
    {
        $outcome = $this->router->handle(self::B, self::B_SOCKET, SseControlFrame::unsubscribe(self::SUB));

        self::assertSame(SseControlFrame::HANDLED_CONTINUE, $outcome);
        self::assertSame(self::A, SubscriptionDtoRegistry::get(self::SUB)?->getSessionId());
        self::assertSame(self::A, $this->table->get(self::SUB)?->sessionId);
        self::assertSame([], $this->written());
    }

    #[Test]
    public function a_foreign_unsubscribe_from_another_worker_keeps_the_owners_routing_row(): void
    {
        // B's worker holds no live state for A's subscription: only the shared row says whose it is.
        SubscriptionDtoRegistry::remove(self::SUB);

        $this->router->handle(self::B, self::B_SOCKET, SseControlFrame::unsubscribe(self::SUB));

        self::assertSame(self::A, $this->table->get(self::SUB)?->sessionId, "A's re-runs are still routed to A");
    }

    #[Test]
    public function a_foreign_subscribe_does_not_rebind_the_owners_id(): void
    {
        $owned = SubscriptionDtoRegistry::get(self::SUB);

        foreach ([true, false] as $onTheOwnersWorker) {
            if (!$onTheOwnersWorker) {
                SubscriptionDtoRegistry::remove(self::SUB);
            }
            $this->forget();

            $this->router->handle(self::B, self::B_SOCKET, $this->subscribeFrame(self::SUB));

            self::assertSame(0, $this->factoryCalls, 'no subscription was built for the taken id');
            self::assertSame([], $this->reRuns());
            self::assertSame(self::A, $this->table->get(self::SUB)?->sessionId, "the routing row is still A's");
            self::assertSame($onTheOwnersWorker ? $owned : null, SubscriptionDtoRegistry::get(self::SUB));
            $wires = array_column($this->written(), 1);
            self::assertCount(1, $wires);
            self::assertStringContainsString('subscribe_denied', $wires[0]);
            self::assertStringNotContainsString(self::A, $wires[0]);
        }
    }

    #[Test]
    public function a_foreign_view_command_is_dropped_before_the_coalescer(): void
    {
        AsyncResourceSseServer::setConnectCoordinator($this->runtime->connectCoordinator);
        AsyncResourceSseServer::setViewChangeCoalescer($this->views);

        // Accepted, as a command for an id nobody holds is; A's slot is untouched.
        self::assertTrue(AsyncResourceSseServer::submitViewChange(self::B, ['page' => 99], self::SUB));
        self::assertFalse($this->views->isPending(self::SUB), "B armed no pending view on A's subscription");

        $this->views->submit(self::SUB, ['page' => 7]);
        self::assertTrue(AsyncResourceSseServer::submitViewChange(self::B, ['page' => 99], self::SUB));
        self::assertSame(['page' => 7], $this->views->peek(self::SUB), "B did not overwrite A's pending view");
    }

    // -- the owner's own controls still work --------------------------------

    #[Test]
    public function the_owners_viewchange_reruns_its_view_onto_its_own_socket(): void
    {
        $this->views->submit(self::SUB, ['page' => 7]);

        $outcome = $this->router->handle(self::A, self::A_SOCKET, SseControlFrame::viewChange(self::SUB));

        self::assertSame(SseControlFrame::HANDLED_CONTINUE, $outcome);
        self::assertSame([[self::A, ['page' => 7]]], $this->reRuns());
        self::assertSame([self::A_SOCKET], array_column($this->written(), 0));
        self::assertFalse($this->views->isPending(self::SUB));
    }

    #[Test]
    public function the_owners_rerun_writes_its_frame_and_clears_its_mark(): void
    {
        $this->reruns->requestRerun(self::SUB);

        $this->router->handle(self::A, self::A_SOCKET, SseControlFrame::rerun(self::SUB, 'leads'));

        self::assertSame([[self::A, []]], $this->reRuns());
        self::assertSame([self::A_SOCKET], array_column($this->written(), 0));
        self::assertFalse($this->reruns->isPending(self::SUB));
    }

    #[Test]
    public function the_owners_unsubscribe_detaches_both_tiers(): void
    {
        $this->router->handle(self::A, self::A_SOCKET, SseControlFrame::unsubscribe(self::SUB));

        self::assertNull(SubscriptionDtoRegistry::get(self::SUB));
        self::assertNull($this->table->get(self::SUB));
    }

    #[Test]
    public function the_owner_may_subscribe_its_own_id_again_and_a_free_id_attaches(): void
    {
        $this->router->handle(self::A, self::A_SOCKET, $this->subscribeFrame(self::SUB));
        $this->router->handle(self::B, self::B_SOCKET, $this->subscribeFrame(self::GHOST));

        self::assertSame(2, $this->factoryCalls);
        self::assertSame(self::A, $this->table->get(self::SUB)?->sessionId);
        self::assertSame(self::B, $this->table->get(self::GHOST)?->sessionId);
        self::assertSame([self::A_SOCKET, self::B_SOCKET], array_column($this->written(), 0));
    }

    // -- no oracle: a foreign id answers as an unknown one --------------------

    #[Test]
    public function an_unknown_id_and_a_foreign_id_answer_alike(): void
    {
        $controls = [
            'viewchange' => static fn (string $id): array => SseControlFrame::viewChange($id, ['page' => 2]),
            'rerun' => static fn (string $id): array => SseControlFrame::rerun($id, 'leads'),
            'unsubscribe' => static fn (string $id): array => SseControlFrame::unsubscribe($id),
        ];

        foreach ($controls as $kind => $control) {
            $answers = [];
            foreach ([self::GHOST, self::SUB] as $id) {
                $this->forget();
                $outcome = $this->router->handle(self::B, self::B_SOCKET, $control($id));
                $answers[] = [$outcome, $this->written(), $this->reRuns()];
            }

            self::assertSame($answers[0], $answers[1], $kind . ': the answer does not say whether the id exists');
            self::assertSame([SseControlFrame::HANDLED_CONTINUE, [], []], $answers[1], $kind);
        }
    }

    // -- a view is applied only to the feed it was admitted against ----------

    #[Test]
    public function a_view_admitted_against_another_feed_is_not_applied(): void
    {
        $this->views->submit(self::SUB, [SseControlFrame::VIEW_FEED => 'other.feed', 'page' => 5]);

        $outcome = $this->router->handle(self::A, self::A_SOCKET, SseControlFrame::viewChange(self::SUB));

        self::assertSame(SseControlFrame::HANDLED_CONTINUE, $outcome);
        self::assertSame([], $this->reRuns());
        self::assertSame([], $this->written());
    }

    #[Test]
    public function a_view_admitted_against_the_bound_feed_is_applied_without_its_stamp(): void
    {
        $this->views->submit(self::SUB, [SseControlFrame::VIEW_FEED => 'leads.feed', 'page' => 5]);

        $this->router->handle(self::A, self::A_SOCKET, SseControlFrame::viewChange(self::SUB));

        self::assertSame([[self::A, ['page' => 5]]], $this->reRuns());
        self::assertCount(1, $this->written());
    }

    /**
     * What the transport double was asked to write.
     *
     * @return list<array{0: mixed, 1: string}>
     */
    private function written(): array
    {
        return $this->written;
    }

    /**
     * What the re-runner double was asked to run.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function reRuns(): array
    {
        return $this->reRuns;
    }

    private function forget(): void
    {
        $this->written = [];
        $this->reRuns = [];
    }

    /** @return array<string, mixed> */
    private function subscribeFrame(string $streamingId): array
    {
        return SseControlFrame::subscribe($streamingId, '/leads/feed', 'GET', [], 'leads.feed', '', false);
    }

    public static function context(string $sessionId): ReRunContext
    {
        return new ReRunContext(
            cachedDto: new \stdClass(),
            route: new DiscoveredRoute(
                path: '/leads/feed',
                methods: ['GET'],
                name: 'leads.feed',
                requestClass: \stdClass::class,
                responseClass: \stdClass::class,
                handlers: [],
                type: 'http_request',
                transport: 'sse',
                produces: null,
                consumes: null,
                module: 'leads',
            ),
            requestSnapshot: ['method' => 'GET', 'uri' => '/leads/feed', 'cookies' => ['sid' => $sessionId]],
            sessionId: $sessionId,
            subjectRef: 'user:' . $sessionId,
        );
    }
}
