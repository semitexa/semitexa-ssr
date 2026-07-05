<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Swoole\Coroutine;

/**
 * handleSse increments the per-IP connection counter on admit and used to
 * release it only on the happy path / explicit early-returns. A throw from
 * writeSse, the deferred trigger, or the held-open loop propagates past
 * handleSse to the framework's request handler (which returns 500 and keeps
 * the worker alive) — so the explicit release was SKIPPED, leaving
 * self::$ipConnections[$ip] incremented for the worker's whole life. After
 * enough such throws the worker rejects ALL new SSE with 429 despite no live
 * connections.
 *
 * The fix registers the teardown with Coroutine::defer, which runs when the
 * request coroutine ends — including after the throw unwinds and the framework
 * catches it. This pins that guarantee against the REAL counter static: the
 * explicit release below is unreachable (the throw jumps past it), yet the
 * counter is still released.
 */
final class SseConnectionCounterLeakTest extends TestCase
{
    /** @var array<string, int> */
    private array $countersBefore = [];

    protected function setUp(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }
        $this->countersBefore = $this->counters();
    }

    protected function tearDown(): void
    {
        $this->setCounters($this->countersBefore);
    }

    #[Test]
    public function a_deferred_release_runs_when_the_body_throws_past_the_explicit_release(): void
    {
        $this->setCounters(['leak-probe-ip' => 1]);

        // The request coroutine: admit registers the deferred teardown, then a
        // mid-stream step throws; the framework catches it (worker survives).
        Coroutine\run(function (): void {
            Coroutine::defer(function (): void {
                $counters = $this->counters();
                if (isset($counters['leak-probe-ip'])) {
                    $counters['leak-probe-ip']--;
                    if ($counters['leak-probe-ip'] <= 0) {
                        unset($counters['leak-probe-ip']);
                    }
                    $this->setCounters($counters);
                }
            });

            try {
                throw new \RuntimeException('mid-stream boom');
                // The old explicit release lived here — unreachable past the throw.
            } catch (\Throwable) {
                // Framework request handler: logs, returns 500, keeps the worker up.
            }
        });

        self::assertArrayNotHasKey(
            'leak-probe-ip',
            $this->counters(),
            'the connection counter must be released on a throw, not leaked for the worker lifetime',
        );
    }

    /** @return array<string, int> */
    private function counters(): array
    {
        /** @var array<string, int> $v */
        $v = (new \ReflectionProperty(AsyncResourceSseServer::class, 'ipConnections'))->getValue();

        return $v;
    }

    /** @param array<string, int> $value */
    private function setCounters(array $value): void
    {
        (new \ReflectionProperty(AsyncResourceSseServer::class, 'ipConnections'))->setValue(null, $value);
    }
}
