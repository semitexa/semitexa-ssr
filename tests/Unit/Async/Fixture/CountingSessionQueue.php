<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async\Fixture;

use Semitexa\Ssr\Domain\Contract\SseSessionQueueInterface;

/**
 * A session queue with no Redis behind it: always available, holds the encoded
 * frames a test puts in it, and counts how often it is asked for one.
 */
final class CountingSessionQueue implements SseSessionQueueInterface
{
    public int $pops = 0;

    /** How many of the next pops fail, as they do with the breaker open or the pool exhausted. */
    public int $failNextPops = 0;

    /** @var array<string, list<string>> */
    public array $frames = [];

    /** @var list<string> */
    public array $wakes = [];

    /** @var list<string> what a push by another worker left in the pending set */
    public array $pending = [];

    /** How often the pending set was read. */
    public int $pendingReads = 0;

    /** Runs inside {@see clearPending()}, before the marks go: what another worker did while the removal was in flight. */
    public ?\Closure $duringClearPending = null;

    /** The pending set cannot be read, as with the breaker open. */
    public bool $failPendingRead = false;

    public function isAvailable(): bool
    {
        return true;
    }

    public function push(string $sessionId, array $payloads): void
    {
    }

    public function tryPush(string $sessionId, array $data): bool
    {
        return true;
    }

    public function pushRaw(string $sessionId, string $raw): void
    {
        $this->frames[$sessionId][] = $raw;
    }

    public function pop(string $sessionId): array
    {
        $this->pops++;
        if ($this->failNextPops > 0) {
            $this->failNextPops--;

            return ['ok' => false, 'raw' => null];
        }
        $frames = $this->frames[$sessionId] ?? [];
        $raw = array_shift($frames);
        $this->frames[$sessionId] = $frames;

        return ['ok' => true, 'raw' => $raw];
    }

    public function publishWake(string $sessionId): void
    {
        $this->wakes[] = $sessionId;
        // As the store does: a wake marks its session pending first.
        if (!in_array($sessionId, $this->pending, true)) {
            $this->pending[] = $sessionId;
        }
    }

    public function pendingSessions(): ?array
    {
        $this->pendingReads++;

        return $this->failPendingRead ? null : $this->pending;
    }

    public function clearPending(array $sessionIds): void
    {
        ($this->duringClearPending)?->__invoke();
        $this->pending = array_values(array_diff($this->pending, $sessionIds));
    }

    public function publishInvalidation(string $channel): void
    {
    }
}
