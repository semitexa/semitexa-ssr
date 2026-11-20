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

    /** @var array<string, list<string>> */
    public array $frames = [];

    /** @var list<string> */
    public array $wakes = [];

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
        $frames = $this->frames[$sessionId] ?? [];
        $raw = array_shift($frames);
        $this->frames[$sessionId] = $frames;

        return ['ok' => true, 'raw' => $raw];
    }

    public function publishWake(string $sessionId): void
    {
        $this->wakes[] = $sessionId;
    }

    public function publishInvalidation(string $channel): void
    {
    }
}
