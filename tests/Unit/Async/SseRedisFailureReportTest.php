<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
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

    protected function setUp(): void
    {
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
        StaticLoggerBridge::reset();
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
