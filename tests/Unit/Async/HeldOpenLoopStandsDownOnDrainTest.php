<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Lifecycle\WorkerDrainSignal;
use Semitexa\Core\Server\InFlightRequests;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\SseServer;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;
use Semitexa\Ssr\Application\Service\Async\SseWorkerTables;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;

/**
 * REGRESSION, found by the 72h soak on 2026-10-19. A worker recycled by
 * max_request was killed at max_wait_time with the requests it was still
 * answering. What held it: the live streams' loops. The drain cancels them,
 * but a cancelled Channel::pop() just returns and a cancelled Redis read comes
 * back as a failed pop, so the loop went round again, parked again, and was
 * cancelled again on the next pass — for the whole window (measured on dev at
 * max_request 300: 7 of 7 recycles forced, 25 requests and 40 streams cut).
 *
 * The loop now reads the drain signal and ends: the stream closes without a
 * `close` event, so the browser's EventSource reconnects — to a worker that
 * stays.
 */
#[RequiresPhpExtension('swoole')]
final class HeldOpenLoopStandsDownOnDrainTest extends TestCase
{
    private const SLOTS = ['sessionRegistry', 'workerTables'];

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
        WorkerDrainSignal::reset();
        InFlightRequests::reset();
        foreach ($this->saved as $name => $value) {
            (new \ReflectionProperty(SseServer::class, $name))->setValue(AsyncResourceSseServer::instance(), $value);
        }
    }

    #[Test]
    public function a_live_stream_loop_ends_once_the_worker_drains(): void
    {
        $server = AsyncResourceSseServer::instance();
        $sessions = new Table(64);
        $sessions->column('worker_id', Table::TYPE_INT, 8);
        $sessions->column('connection', Table::TYPE_STRING, 16);
        $sessions->column('opened_seq', Table::TYPE_INT, 8);
        $sessions->create();
        $deliver = new Table(8);
        $deliver->column('payload', Table::TYPE_STRING, 64);
        $deliver->create();
        $tables = new SseWorkerTables();
        $tables->setTables($sessions, $deliver);
        $registry = new SseSessionRegistry();
        (new \ReflectionProperty(SseServer::class, 'workerTables'))->setValue($server, $tables);
        (new \ReflectionProperty(SseServer::class, 'sessionRegistry'))->setValue($server, $registry);
        $loop = new \ReflectionMethod(SseServer::class, 'runHeldOpenLoop');

        $response = $this->createStub(Response::class);
        $written = [];
        $response->method('write')->willReturnCallback(static function (string $data) use (&$written): bool {
            $written[] = $data;

            return true;
        });
        $request = $this->createStub(Request::class);
        $endedAfterDrain = null;
        $teardownSpared = null;

        Coroutine\run(function () use ($server, $registry, $loop, $response, $request, &$endedAfterDrain, &$teardownSpared): void {
            $registry->open('sse_drain', $response, '', '');
            $ended = new Channel(1);
            Coroutine::create(static function () use ($server, $loop, $response, $request, $ended, &$teardownSpared): void {
                $loop->invoke($server, 'sse_drain', $request, $response, '');
                // What follows the loop in production is the teardown: the drain must spare it.
                $teardownSpared = InFlightRequests::servesRequest((int) Coroutine::getCid());
                $ended->push(true);
            });

            Coroutine::sleep(0.3);
            self::assertTrue($ended->isEmpty(), 'the loop ended before anything asked it to');

            WorkerDrainSignal::begin();
            $endedAfterDrain = $ended->pop(1.0) === true;

            // Stop a loop that ignored the drain, so a failure fails instead of hanging.
            $registry->close('sse_drain');
            $ended->pop(2.0);
        });

        self::assertTrue($endedAfterDrain, 'the live loop kept running after the worker began to drain');
        self::assertTrue($teardownSpared, 'the teardown after a drain-ended loop is cancelled on every pass instead of spared');
        // A `close` event tells the client to stop; only a stream that just ends is reconnected.
        self::assertStringNotContainsString('event: close', implode('', $written), 'the drain sent a close the client would not reconnect from');
    }
}
