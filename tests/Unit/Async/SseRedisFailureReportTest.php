<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Semitexa\Core\Lifecycle\WorkerDrainSignal;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Redis\RedisUnavailableException;
use Semitexa\Ssr\Application\Service\Async\SseRedisSessionQueue;

/**
 * An SSE Redis call the core breaker refused is not logged again: the breaker
 * logged the outage once. A first-hand failure still is. Before, every open
 * session logged every 0.2 s tick: 606 lines in one second (2026-10-14).
 */
// verify:accept-test-change replaces SseRedisOutageGateTest: the gate's own back-off is gone (the core pool's circuit breaker now does it), and what remains — not re-logging a refused call — is tested here
final class SseRedisFailureReportTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    /** The bridge's logger before this test, put back afterwards (it is process-wide). */
    private mixed $previousLogger = null;

    protected function setUp(): void
    {
        $this->previousLogger = (new \ReflectionProperty(StaticLoggerBridge::class, 'logger'))->getValue();
        $lines = &$this->lines;
        StaticLoggerBridge::set(new class ($lines) implements LoggerInterface {
            /** @param list<string> $lines */
            public function __construct(private array &$lines)
            {
            }

            public function error(string $message, array $context = []): void
            {
                $this->lines[] = $message . ': ' . (string) ($context['message'] ?? '');
            }

            public function critical(string $message, array $context = []): void
            {
                $this->lines[] = $message;
            }

            public function warning(string $message, array $context = []): void
            {
                $this->lines[] = $message;
            }

            public function info(string $message, array $context = []): void
            {
            }

            public function notice(string $message, array $context = []): void
            {
            }

            public function debug(string $message, array $context = []): void
            {
            }
        });
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(StaticLoggerBridge::class, 'logger'))->setValue(null, $this->previousLogger);
    }

    #[Test]
    public function a_call_the_breaker_refused_is_not_logged_again(): void
    {
        $opened = new ConnectionException($this->createMock(NodeConnectionInterface::class), 'getaddrinfo for redis failed');

        for ($i = 0; $i < 100; $i++) {
            SseRedisSessionQueue::reportFailure('Redis SSE dequeue failed', RedisUnavailableException::from($opened));
        }

        self::assertSame([], $this->lines);
    }

    /**
     * A leaving worker's drain cancels the SSE loops parked on Redis, and each
     * read fails with "Coroutine canceled". Logged as errors, those lines were
     * the burst the leaving worker's logger was still writing when Swoole killed
     * it at max_wait_time, with the requests it was answering (the 72h soak,
     * 2026-10-19: 40 such lines in five minutes of recycles). A real failure in
     * a request the drain spares is still logged.
     */
    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function a_call_the_drain_cancelled_is_not_logged_but_a_real_failure_is(): void
    {
        $failure = fn (string $why) => new ConnectionException($this->createMock(NodeConnectionInterface::class), $why);
        WorkerDrainSignal::begin();
        try {
            \Swoole\Coroutine\run(function () use ($failure): void {
                $cid = \Swoole\Coroutine::create(static function () use ($failure): void {
                    \Swoole\Coroutine::sleep(5);
                    SseRedisSessionQueue::reportFailure('Redis SSE dequeue failed', $failure('Coroutine canceled [tcp://redis:6379]'));
                });
                \Swoole\Coroutine::cancel($cid);
            });
            SseRedisSessionQueue::reportFailure('Redis SSE enqueue failed', $failure('Connection refused'));
        } finally {
            WorkerDrainSignal::reset();
        }

        self::assertSame(['Redis SSE enqueue failed: Connection refused'], $this->lines);
    }

    #[Test]
    public function a_first_hand_failure_is_logged_with_its_cause(): void
    {
        SseRedisSessionQueue::reportFailure(
            'Redis SSE dequeue failed',
            new ConnectionException($this->createMock(NodeConnectionInterface::class), 'Connection refused'),
            ['session_id' => 's1'],
        );
        SseRedisSessionQueue::reportFailure('Redis SSE enqueue failed', new \RuntimeException('WRONGTYPE'));

        self::assertSame(['Redis SSE dequeue failed: Connection refused', 'Redis SSE enqueue failed: WRONGTYPE'], $this->lines);
    }
}
