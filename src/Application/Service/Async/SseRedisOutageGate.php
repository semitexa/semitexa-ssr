<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Backs a worker's SSE Redis traffic off while Redis is down, and logs the
 * outage once instead of once per call.
 *
 * Every open SSE session drains its Redis queue on each held-open tick (0.2 s),
 * so with Redis gone each session retried — and logged — five times a second.
 * Measured 2026-10-14 during a stack restart: 1174 "Redis SSE dequeue failed"
 * lines, up to 606 in one second, each one a fresh connection attempt.
 *
 * After a failure the gate refuses calls until a retry time that doubles from
 * {@see FIRST_BACKOFF_SECONDS} to {@see MAX_BACKOFF_SECONDS}; the first call
 * after it is the probe. The first failure of an outage is logged with the
 * caller's own message (so existing alerts on it keep matching); the rest are
 * counted and reported once, with the outage's length, when a call succeeds.
 *
 * Shared by every coroutine of a worker on purpose: an outage is a fact about
 * the worker's Redis, not about one request.
 */
final class SseRedisOutageGate
{
    public const FIRST_BACKOFF_SECONDS = 1.0;
    public const MAX_BACKOFF_SECONDS = 30.0;

    private ?float $outageSince = null;
    private float $retryAt = 0.0;
    private float $backoff = self::FIRST_BACKOFF_SECONDS;
    private int $suppressed = 0;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @param (\Closure(): float)|null $clock */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** Whether a Redis call may be attempted now. A refused call counts as suppressed. */
    public function allows(): bool
    {
        if ($this->outageSince === null || ($this->clock)() >= $this->retryAt) {
            return true;
        }
        $this->suppressed++;

        return false;
    }

    public function inOutage(): bool
    {
        return $this->outageSince !== null;
    }

    /** @param array<string, mixed> $context */
    public function failed(string $message, \Throwable $e, array $context = []): void
    {
        $now = ($this->clock)();
        if ($this->outageSince === null) {
            $this->outageSince = $now;
            $this->backoff = self::FIRST_BACKOFF_SECONDS;
            StaticLoggerBridge::error('ssr', $message, $context + [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'backoff_seconds' => $this->backoff,
                'note' => 'further Redis SSE failures are suppressed until it recovers',
            ]);
        } else {
            $this->suppressed++;
            $this->backoff = min($this->backoff * 2, self::MAX_BACKOFF_SECONDS);
        }
        $this->retryAt = $now + $this->backoff;
    }

    public function succeeded(): void
    {
        if ($this->outageSince === null) {
            return;
        }
        StaticLoggerBridge::warning('ssr', 'Redis SSE recovered', [
            'outage_seconds' => round(($this->clock)() - $this->outageSince, 3),
            'suppressed_calls' => $this->suppressed,
        ]);
        $this->outageSince = null;
        $this->retryAt = 0.0;
        $this->backoff = self::FIRST_BACKOFF_SECONDS;
        $this->suppressed = 0;
    }
}
