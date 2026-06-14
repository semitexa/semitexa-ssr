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
use Semitexa\Ssr\Application\Service\Async\ViewChangeCoalescer;
use Semitexa\Ssr\Domain\Contract\ChannelSubscriptionControllerInterface;
use Semitexa\Ssr\Domain\Contract\SessionControlDeliveryInterface;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * Intended Grid Model · Phase 2 — the loop branch that catches `{__ctrl:viewchange}`
 * and turns it into a re-run with the new view params, pushed on the open fd.
 *
 * Driven directly (the R4 reflection pattern): the store is populated by a REAL R5
 * {@see ConnectCoordinator::onConnect()}; the re-runner is a fake R2 that RECORDS
 * the override it receives; a capturing transport records the frame written.
 *
 * Proves: a view-change control re-runs WITH the latest view params (forwarded to
 * R2); the params are read latest-wins from the coalescer (a burst collapses to one
 * re-run of the FINAL view); a missing context is a safe no-op; and the view-change
 * path is additive — it does NOT disturb the mutation `{__ctrl:rerun}` coalescer.
 */
final class ViewChangeControlFrameTest extends TestCase
{
    private const HANDLED_CONTINUE = 1;

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
        AsyncResourceSseServer::setViewChangeCoalescer(null);
        $this->setTransport(null);
    }

    // -----------------------------------------------------------------------
    // The payoff: a view-change control → re-run WITH new params → fresh frame
    // -----------------------------------------------------------------------

    #[Test]
    public function a_viewchange_control_reruns_with_the_new_params_and_writes_a_fresh_frame(): void
    {
        $this->connect('str_a', 'sess_a');
        $rerunner = $this->capturingReRunner();
        $transport = $this->captureTransport();
        $viewCoalescer = ViewChangeCoalescer::create(64);
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setViewChangeCoalescer($viewCoalescer);
        $this->setTransport($transport);

        // The command intake stored the new view; the control rides the queue param-less.
        $viewCoalescer->submit('str_a', ['page' => 3, 'limit' => 50]);

        $outcome = $this->drain('sess_a', ['__ctrl' => 'viewchange', 'streaming_id' => 'str_a']);

        self::assertSame(self::HANDLED_CONTINUE, $outcome);
        self::assertSame(1, $rerunner->calls, 'the view-change drove a re-run on the owning worker');
        self::assertSame(['page' => 3, 'limit' => 50], $rerunner->lastOverride, 'the new view params were forwarded to R2');
        self::assertCount(1, $transport->frames, 'a fresh frame was pushed on the open fd');
        self::assertFalse($viewCoalescer->isPending('str_a'), 'the view-change mark was consumed (re-armed)');
    }

    #[Test]
    public function a_rapid_burst_collapses_to_one_rerun_of_the_latest_view(): void
    {
        $this->connect('str_a', 'sess_a');
        $rerunner = $this->capturingReRunner();
        $viewCoalescer = ViewChangeCoalescer::create(64);
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setViewChangeCoalescer($viewCoalescer);
        $this->setTransport($this->captureTransport());

        // Three rapid commands before the owner drains: only the first enqueues a
        // control; all three overwrite the latest view.
        self::assertTrue($viewCoalescer->submit('str_a', ['page' => 1]));
        self::assertFalse($viewCoalescer->submit('str_a', ['page' => 2]));
        self::assertFalse($viewCoalescer->submit('str_a', ['page' => 3]));

        // The single control drains → ONE re-run of the FINAL view (page 3).
        $this->drain('sess_a', ['__ctrl' => 'viewchange', 'streaming_id' => 'str_a']);

        self::assertSame(1, $rerunner->calls, 'a burst of N commands does not storm N re-runs');
        self::assertSame(['page' => 3], $rerunner->lastOverride, 'the latest view wins');
    }

    #[Test]
    public function the_inline_params_fallback_is_used_when_no_coalescer_is_wired(): void
    {
        $this->connect('str_a', 'sess_a');
        $rerunner = $this->capturingReRunner();
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setViewChangeCoalescer(null); // unwired → inline fallback
        $this->setTransport($this->captureTransport());

        $this->drain('sess_a', [
            '__ctrl' => 'viewchange',
            'streaming_id' => 'str_a',
            'params' => ['sort' => '-submittedAt'],
        ]);

        self::assertSame(1, $rerunner->calls);
        self::assertSame(['sort' => '-submittedAt'], $rerunner->lastOverride, 'inline params drive the override with no coalescer');
    }

    #[Test]
    public function a_viewchange_with_no_local_context_is_a_safe_noop(): void
    {
        $rerunner = $this->capturingReRunner();
        $transport = $this->captureTransport();
        $viewCoalescer = ViewChangeCoalescer::create(64);
        $viewCoalescer->submit('str_ghost', ['page' => 9]);
        AsyncResourceSseServer::setReRunner($rerunner);
        AsyncResourceSseServer::setViewChangeCoalescer($viewCoalescer);
        $this->setTransport($transport);

        self::assertFalse(SubscriptionDtoRegistry::has('str_ghost'));

        $outcome = $this->drain('sess_ghost', ['__ctrl' => 'viewchange', 'streaming_id' => 'str_ghost']);

        self::assertSame(self::HANDLED_CONTINUE, $outcome, 'consumed, not written as a data frame');
        self::assertSame(0, $rerunner->calls, 'no re-run on a missing context');
        self::assertCount(0, $transport->frames);
    }

    // -----------------------------------------------------------------------
    // Additivity: the view-change path does not disturb the mutation re-run path
    // -----------------------------------------------------------------------

    #[Test]
    public function a_viewchange_does_not_clear_the_mutation_rerun_pending_mark(): void
    {
        $this->connect('str_a', 'sess_a');
        $rerunCoalescer = RerunCoalescer::create(64);
        $viewCoalescer = ViewChangeCoalescer::create(64);
        AsyncResourceSseServer::setReRunner($this->capturingReRunner());
        AsyncResourceSseServer::setRerunCoalescer($rerunCoalescer);
        AsyncResourceSseServer::setViewChangeCoalescer($viewCoalescer);
        $this->setTransport($this->captureTransport());

        // A mutation re-run is pending (R3 set its mark) AND a view-change arrives.
        $rerunCoalescer->requestRerun('str_a');
        $viewCoalescer->submit('str_a', ['page' => 2]);

        $this->drain('sess_a', ['__ctrl' => 'viewchange', 'streaming_id' => 'str_a']);

        // The view-change consumed its OWN mark but left the mutation re-run's mark
        // intact — the two signals are independent and never suppress each other.
        self::assertTrue($rerunCoalescer->isPending('str_a'), 'the mutation re-run mark is untouched by a view-change');
        self::assertFalse($viewCoalescer->isPending('str_a'), 'the view-change mark was consumed');
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private function drain(string $sessionId, array $data): int
    {
        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'handleControlFrame');
        $method->setAccessible(true);

        return (int) $method->invoke(null, $sessionId, null, $data);
    }

    private function connect(string $streamingId, string $sessionId): void
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
            new SubscriptionRecord($streamingId, $sessionId, 'default', ['ui_playground_leads'], 'tenant-blob'),
            $this->reRunContext($sessionId),
        );
    }

    /** A re-runner that records the override it receives and returns a fresh frame. */
    private function capturingReRunner(): ReRunnerInterface
    {
        return new class implements ReRunnerInterface {
            public int $calls = 0;
            /** @var array<string, mixed> */
            public array $lastOverride = [];

            public function reRun(ReRunContext $context, array $filterOverride = []): ReRunResult
            {
                $this->calls++;
                $this->lastOverride = $filterOverride;

                return ReRunResult::frame(HttpResponse::json(['rows' => [['id' => 1]], 'view' => $filterOverride]));
            }
        };
    }

    private function captureTransport(): SseTransportInterface
    {
        return new class implements SseTransportInterface {
            /** @var list<SseFrame> */
            public array $frames = [];
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
                path: '/ui-playground/admin/leads/grid-stream',
                methods: ['GET'],
                name: 'leads.grid.live',
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
