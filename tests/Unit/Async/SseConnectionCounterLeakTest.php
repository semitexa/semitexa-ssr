<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseConnectionLimiter;
use Swoole\Coroutine;

/**
 * The admit path increments a per-IP connection counter and used to release it
 * only on the happy path and the explicit early-returns. A throw from writeSse,
 * the deferred trigger, or the held-open loop propagates past the admit handler
 * to the framework's request handler (which returns 500 and keeps the worker
 * alive) — so the explicit release was SKIPPED, leaving the counter incremented
 * for the worker's whole life. After enough such throws the worker rejects ALL
 * new SSE with 429 despite no live connections.
 *
 * The fix registers the teardown with `Coroutine::defer`, which runs when the
 * request coroutine ends — including after the throw unwinds and the framework
 * catches it.
 *
 * `ep-slay-sse-god-class` · tk-sse-connection-limiter — this used to reflect
 * into a private static and re-implement the decrement inside the test, which
 * meant it proved that `Coroutine::defer` fires and nothing about the counter
 * protocol itself. It now drives the real {@see SseConnectionLimiter}, so the
 * release under test is the one production runs.
 */
final class SseConnectionCounterLeakTest extends TestCase
{
    private const IP = 'leak-probe-ip';

    protected function setUp(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }
    }

    #[Test]
    public function the_deferred_release_runs_when_the_body_throws_past_the_explicit_release(): void
    {
        $limiter = new SseConnectionLimiter();
        $response = new \stdClass();

        self::assertNull($limiter->tryAcquire(self::IP, 'sess-1', $response), 'admitted');
        self::assertSame(1, $limiter->openConnectionsFor(self::IP));

        // The request coroutine: admit registers the deferred teardown, then a
        // mid-stream step throws; the framework catches it (worker survives).
        Coroutine\run(static function () use ($limiter, $response): void {
            Coroutine::defer(static fn () => $limiter->release('sess-1', $response));

            try {
                throw new \RuntimeException('mid-stream boom');
                // The old explicit release lived here — unreachable past the throw.
            } catch (\Throwable) {
                // Framework request handler: logs, returns 500, keeps the worker up.
            }
        });

        self::assertSame(
            0,
            $limiter->openConnectionsFor(self::IP),
            'the connection counter must be released on a throw, not leaked for the worker lifetime',
        );
    }

    #[Test]
    public function releasing_twice_does_not_drive_the_counter_negative(): void
    {
        // Both the explicit close and the deferred teardown can fire on a normal
        // exit, so release has to be idempotent or the caps drift downward and
        // the worker eventually admits more connections than it should.
        $limiter = new SseConnectionLimiter();
        $response = new \stdClass();

        $limiter->tryAcquire(self::IP, 'sess-1', $response);
        $limiter->release('sess-1', $response);
        $limiter->release('sess-1', $response);

        self::assertSame(0, $limiter->openConnectionsFor(self::IP));
        self::assertSame(0, $limiter->totalOpenConnections());
    }

    #[Test]
    public function releasing_a_stale_response_does_not_free_the_reconnected_slot(): void
    {
        // A session id can be reconnected on a fresh response while the old
        // connection is still tearing down. The connection key includes the
        // response identity precisely so the late teardown cannot decrement the
        // new connection's slot.
        $limiter = new SseConnectionLimiter();
        $old = new \stdClass();
        $new = new \stdClass();

        $limiter->tryAcquire(self::IP, 'sess-1', $old);
        $limiter->tryAcquire(self::IP, 'sess-1', $new);
        self::assertSame(2, $limiter->openConnectionsFor(self::IP));

        $limiter->release('sess-1', $old);

        self::assertSame(1, $limiter->openConnectionsFor(self::IP), 'the reconnected connection keeps its slot');
    }
}
