<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Ssr\Application\Service\Async\SseRedisOutageGate;

/**
 * A Redis outage is logged once and backed off, not retried and logged on
 * every held-open tick of every session (606 lines in one second, measured).
 */
final class SseRedisOutageGateTest extends TestCase
{
    private float $now = 1000.0;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $lines = [];

    protected function setUp(): void
    {
        $lines = &$this->lines;
        StaticLoggerBridge::set(new class ($lines) implements LoggerInterface {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $lines */
            public function __construct(private array &$lines)
            {
            }

            public function error(string $message, array $context = []): void
            {
                $this->lines[] = ['level' => 'error', 'message' => $message, 'context' => $context];
            }

            public function critical(string $message, array $context = []): void
            {
                $this->lines[] = ['level' => 'critical', 'message' => $message, 'context' => $context];
            }

            public function warning(string $message, array $context = []): void
            {
                $this->lines[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
            }

            public function info(string $message, array $context = []): void
            {
                $this->lines[] = ['level' => 'info', 'message' => $message, 'context' => $context];
            }

            public function notice(string $message, array $context = []): void
            {
                $this->lines[] = ['level' => 'notice', 'message' => $message, 'context' => $context];
            }

            public function debug(string $message, array $context = []): void
            {
                $this->lines[] = ['level' => 'debug', 'message' => $message, 'context' => $context];
            }
        });
    }

    protected function tearDown(): void
    {
        StaticLoggerBridge::reset();
    }

    #[Test]
    public function a_healthy_gate_allows_every_call_and_logs_nothing(): void
    {
        $gate = $this->gate();
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($gate->allows());
            $gate->succeeded();
        }

        self::assertFalse($gate->inOutage());
        self::assertSame([], $this->lines);
    }

    #[Test]
    public function the_first_failure_is_logged_with_the_callers_message_and_the_rest_wait(): void
    {
        $gate = $this->gate();
        $gate->failed('Redis SSE dequeue failed', new \RuntimeException('Connection refused'), ['session_id' => 's1']);

        self::assertCount(1, $this->lines);
        self::assertSame('error', $this->lines[0]['level']);
        self::assertSame('Redis SSE dequeue failed', $this->lines[0]['message']);
        self::assertSame('s1', $this->lines[0]['context']['session_id']);
        self::assertSame('Connection refused', $this->lines[0]['context']['message']);

        // 200 ticks inside the first backoff second: refused, silent.
        for ($i = 0; $i < 200; $i++) {
            self::assertFalse($gate->allows());
        }
        self::assertCount(1, $this->lines);
    }

    #[Test]
    public function the_backoff_doubles_up_to_its_cap(): void
    {
        $gate = $this->gate();
        $gate->failed('x', new \RuntimeException('down'));
        $waits = [];
        for ($round = 0; $round < 8; $round++) {
            $start = $this->now;
            while (!$gate->allows()) {
                $this->now += 0.25;
            }
            $waits[] = $this->now - $start;
            $gate->failed('x', new \RuntimeException('down'));
        }

        self::assertSame([1.0, 2.0, 4.0, 8.0, 16.0, 30.0, 30.0, 30.0], $waits);
        self::assertCount(1, $this->lines, 'one outage, one error line');
    }

    #[Test]
    public function recovery_is_reported_once_with_the_outage_length_and_suppressed_calls(): void
    {
        $gate = $this->gate();
        $gate->failed('x', new \RuntimeException('down'));
        $gate->allows();
        $gate->allows();
        $this->now += 1.5;
        self::assertTrue($gate->allows(), 'the probe after the backoff');
        $gate->succeeded();
        $gate->succeeded();

        self::assertFalse($gate->inOutage());
        self::assertCount(2, $this->lines);
        self::assertSame(['warning', 'Redis SSE recovered'], [$this->lines[1]['level'], $this->lines[1]['message']]);
        self::assertSame(1.5, $this->lines[1]['context']['outage_seconds']);
        self::assertSame(2, $this->lines[1]['context']['suppressed_calls']);

        // A later outage starts from the first backoff again and is logged again.
        $gate->failed('x', new \RuntimeException('down again'));
        $this->now += SseRedisOutageGate::FIRST_BACKOFF_SECONDS;
        self::assertTrue($gate->allows());
        self::assertCount(3, $this->lines);
    }

    private function gate(): SseRedisOutageGate
    {
        return new SseRedisOutageGate(fn (): float => $this->now);
    }
}
