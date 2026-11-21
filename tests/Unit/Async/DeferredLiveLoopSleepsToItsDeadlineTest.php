<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\SseServer;
use Semitexa\Ssr\Application\Service\Async\SseSessionCoroutines;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;
use Semitexa\Ssr\Application\Service\Async\SseWorkerTables;
use Semitexa\Ssr\Application\Service\DataProviderRegistry;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Application\Service\KissVisitor;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;
use Semitexa\Ssr\Tests\Unit\Async\Fixture\CountingSessionQueue;
use Semitexa\Ssr\Tests\Unit\Async\Fixture\CountingTable;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Response;
use Swoole\Table;

/**
 * tk-idle-sse-stream-polls-redis, the deferred page. A stream whose page has a
 * slot with a refreshInterval keeps a second coroutine, the live loop, and it
 * woke every second whatever the interval: measured on a stand, 1000 idle
 * pages with one 3600 s slot cost 10.8% of a container's CPU against 3.5% for
 * the same streams without it. It now sleeps until the nearest slot is due.
 *
 * What the tick did besides waking is pinned here too: a due slot is still
 * delivered, and a loop asleep for an hour ends when its stream does.
 */
#[RequiresPhpExtension('swoole')]
final class DeferredLiveLoopSleepsToItsDeadlineTest extends TestCase
{
    private const SLOTS = ['sessionCoroutines', 'sessionRegistry', 'workerTables', 'redisSessionQueue'];

    /** @var array<string, mixed> */
    private array $saved = [];

    /** The ownership table of {@see wire()}; its `gets` counts the reads made of it. */
    private CountingTable $ownership;

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
    public function a_slot_due_in_an_hour_costs_one_look_at_its_session_not_one_a_second(): void
    {
        [$orchestrator, , $tables, $tracker, $liveLoop] = $this->wire();
        $ownership = $this->ownership;
        $slot = $this->slot(refreshInterval: 3600);
        $looks = null;
        $tracked = null;

        Coroutine\run(function () use ($orchestrator, $tables, $tracker, $liveLoop, $ownership, $slot, &$looks, &$tracked): void {
            // Held by this worker's tables and not in its registry: every
            // isSessionActive() is then exactly one read of the ownership row.
            $tables->recordOwnership('sse_hourly');
            $ownership->gets = 0;
            $tracker->create(static function () use ($orchestrator, $liveLoop, $slot): void {
                $liveLoop->invoke($orchestrator, 'sse_hourly', 'probe_page', [], [$slot]);
            }, 'sse_hourly');

            Coroutine::sleep(3.2);
            $looks = $ownership->gets;
            $tracked = $tracker->hasAny('sse_hourly');

            $tracker->cancelFor('sse_hourly');
        });

        self::assertTrue($tracked, 'the live loop ended by itself: nothing would refresh the slot when its hour comes');
        // One: the loop's entry. It used to be that plus two for every second held.
        self::assertLessThanOrEqual(2, $looks, 'the live loop woke every second for a slot that is due in an hour');
        self::assertGreaterThanOrEqual(1, $looks, 'the loop never asked whether its session was there: the count proves nothing');
    }

    #[Test]
    public function a_slot_due_every_second_is_still_delivered_every_second(): void
    {
        [$orchestrator, $registry, , $tracker, $liveLoop] = $this->wire();
        $slot = $this->slot(refreshInterval: 1);
        /** @var list<array<string, mixed>> $afterOne */
        $afterOne = [];
        /** @var list<array<string, mixed>> $afterTwo */
        $afterTwo = [];

        Coroutine\run(function () use ($orchestrator, $registry, $tracker, $liveLoop, $slot, &$afterOne, &$afterTwo): void {
            $registry->open('sse_live', new \stdClass(), '', '');
            $tracker->create(static function () use ($orchestrator, $liveLoop, $slot): void {
                $liveLoop->invoke($orchestrator, 'sse_live', 'probe_page', [], [$slot]);
            }, 'sse_live');

            Coroutine::sleep(0.8);
            self::assertSame([], $registry->queued('sse_live'), 'the slot was pushed before its interval had passed');
            Coroutine::sleep(0.5);
            $afterOne = $registry->queued('sse_live');
            Coroutine::sleep(1.0);
            $afterTwo = $registry->queued('sse_live');

            $tracker->cancelFor('sse_live');
        });

        self::assertCount(1, $afterOne, 'a slot with refreshInterval 1 was not delivered a second after the loop began');
        self::assertSame('live_probe', $afterOne[0]['slot_id'] ?? null);
        self::assertCount(2, $afterTwo, 'the second refresh did not follow the first by its interval');
    }

    /**
     * The loop used to notice its stream was gone within a second, by asking.
     * Asleep until a slot is due it cannot, so the close has to end it: a
     * coroutine sleeping an hour after its stream left would be a leak for
     * every page view. Driven through the server's own teardown, not the
     * tracker: this is the cancel a real close makes.
     */
    #[Test]
    public function closing_the_stream_ends_a_live_loop_asleep_until_its_slot_is_due(): void
    {
        [$orchestrator, $registry, $tables, $tracker, $liveLoop] = $this->wire();
        $server = AsyncResourceSseServer::instance();
        $closeSession = new \ReflectionMethod(SseServer::class, 'closeSession');
        $slot = $this->slot(refreshInterval: 3600);
        $old = $this->createStub(Response::class);
        $new = $this->createStub(Response::class);
        $endedAfter = null;
        $leftTracked = null;

        Coroutine\run(function () use ($orchestrator, $registry, $tables, $tracker, $liveLoop, $server, $closeSession, $slot, $old, $new, &$endedAfter, &$leftTracked): void {
            $registry->open('sse_closing', $old, '', '');
            $tables->recordOwnership('sse_closing', $old);
            $ended = new Channel(1);
            $tracker->create(static function () use ($orchestrator, $liveLoop, $slot, $ended): void {
                try {
                    $liveLoop->invoke($orchestrator, 'sse_closing', 'probe_page', [], [$slot]);
                } finally {
                    $ended->push(true);
                }
            }, 'sse_closing');
            Coroutine::sleep(0.05); // the loop is in its sleep, an hour long

            // The page reconnects (same worker), and the connection it replaced is torn down:
            // the session is still active, so asking would not have ended the old loop either.
            $registry->open('sse_closing', $new, '', '');
            $tables->recordOwnership('sse_closing', $new);
            $startedAt = microtime(true);
            $closeSession->invoke($server, 'sse_closing', $old);
            $endedAfter = $ended->pop(1.0) === true ? microtime(true) - $startedAt : null;
            $leftTracked = $tracker->hasAny('sse_closing');
        });

        self::assertNotNull($endedAfter, 'the live loop slept on after its stream was closed');
        self::assertLessThan(0.1, $endedAfter, 'the live loop did not end with the close');
        self::assertFalse($leftTracked, 'the ended loop is still tracked for its session');
    }

    private function slot(int $refreshInterval): DeferredSlotDefinition
    {
        return new DeferredSlotDefinition(
            slotId: 'live_probe',
            templateName: '@nonexistent/live-probe.html.twig',
            pageHandle: 'probe_page',
            refreshInterval: $refreshInterval,
        );
    }

    /** @return array{DeferredBlockOrchestrator, SseSessionRegistry, SseWorkerTables, SseSessionCoroutines, \ReflectionMethod} */
    private function wire(): array
    {
        $server = AsyncResourceSseServer::instance();
        $sessions = new CountingTable(64);
        $sessions->column('worker_id', Table::TYPE_INT, 8);
        $sessions->column('connection', Table::TYPE_STRING, 16);
        $sessions->column('opened_seq', Table::TYPE_INT, 8);
        $sessions->create();
        $this->ownership = $sessions;
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
        // No Redis behind the session queue: a frame for a session held here never reaches it.
        (new \ReflectionProperty(SseServer::class, 'redisSessionQueue'))->setValue($server, new CountingSessionQueue());

        $orchestrator = new DeferredBlockOrchestrator();
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'sseServer'))->setValue($orchestrator, $server);
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'dataProviderRegistry'))->setValue($orchestrator, new DataProviderRegistry());
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'logger'))->setValue($orchestrator, $this->createStub(LoggerInterface::class));
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'kissVisitor'))->setValue($orchestrator, new KissVisitor());

        return [$orchestrator, $registry, $tables, $tracker, new \ReflectionMethod(DeferredBlockOrchestrator::class, 'runLiveLoop')];
    }
}
