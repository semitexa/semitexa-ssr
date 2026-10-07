<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Predis\Client;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Server\SseFrame;

/**
 * The last frames a page's KISS stream wrote, so a reconnect can be given what
 * it missed (ep-platform-live-state · tk-ls-replay).
 *
 * A frame written into a socket the browser already lost — a half-open
 * connection, a network switch, a laptop lid — is gone: the write succeeded,
 * nothing re-queues it. The browser reconnects naming the last frame it did get
 * (Last-Event-ID); {@see after()} answers with every frame written after it, in
 * order, or null when that frame is no longer held (the gap left the ring) and
 * the client must re-sync from fresh snapshots instead.
 *
 * In Redis when there is a pool, so a reconnect landing on another worker finds
 * the ring; otherwise in this worker only (a reconnect elsewhere is told to
 * reset). Best-effort like the session queue: a Redis error is logged and the
 * stream goes on — losing replay must never take a live stream down.
 */
final class SseReplayRing
{
    /** Frames held per session. */
    public const CAPACITY = 128;

    private const KEY_PREFIX = 'semitexa_sse_ring:';
    private const TTL_SECONDS = 600;

    /** Sessions held in this worker when there is no Redis. */
    private const LOCAL_SESSIONS = 512;

    /** @var array<string, list<array{i: string, e: ?string, d: array<array-key, mixed>}>> */
    private array $local = [];

    public function __construct(private readonly SseRedisPool $pool)
    {
    }

    public function record(string $sessionId, SseFrame $frame): void
    {
        $id = $frame->id();
        if ($id === null || $sessionId === '') {
            return;
        }
        $entry = ['i' => $id, 'e' => $frame->event(), 'd' => $frame->data()];

        $pool = $this->pool->get();
        if ($pool === null) {
            $this->recordLocally($sessionId, $entry);

            return;
        }

        $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return;
        }
        try {
            $pool->withConnection(static function ($redis) use ($sessionId, $encoded): void {
                /** @var Client $redis */
                $key = self::KEY_PREFIX . $sessionId;
                // One round trip per frame, not three.
                $redis->pipeline(static function ($pipe) use ($key, $encoded): void {
                    $pipe->rpush($key, [$encoded]);
                    $pipe->ltrim($key, -self::CAPACITY, -1);
                    $pipe->expire($key, self::TTL_SECONDS);
                });
            });
        } catch (\Throwable $e) {
            StaticLoggerBridge::error('ssr', 'SSE replay ring write failed', [
                'session_id' => $sessionId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The frames written after $lastId, oldest first; [] when it was the last
     * one; null when $lastId is not held (or the ring could not be read).
     *
     * @return list<SseFrame>|null
     */
    public function after(string $sessionId, string $lastId): ?array
    {
        $entries = $this->entries($sessionId);
        if ($entries === null) {
            return null;
        }
        foreach ($entries as $index => $entry) {
            if ($entry['i'] !== $lastId) {
                continue;
            }

            return array_map(
                static fn (array $e): SseFrame => SseFrame::fromResolved($e['i'], $e['e'], $e['d']),
                array_slice($entries, $index + 1),
            );
        }

        return null;
    }

    /** @return list<array{i: string, e: ?string, d: array<array-key, mixed>}>|null */
    private function entries(string $sessionId): ?array
    {
        $pool = $this->pool->get();
        if ($pool === null) {
            return $this->local[$sessionId] ?? [];
        }
        try {
            /** @var list<string> $raw */
            $raw = $pool->withConnection(static function ($redis) use ($sessionId): array {
                /** @var Client $redis */
                return $redis->lrange(self::KEY_PREFIX . $sessionId, 0, -1);
            });
        } catch (\Throwable $e) {
            StaticLoggerBridge::error('ssr', 'SSE replay ring read failed', [
                'session_id' => $sessionId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $entries = [];
        foreach ($raw as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && is_string($entry['i'] ?? null) && is_array($entry['d'] ?? null)) {
                $entries[] = ['i' => $entry['i'], 'e' => is_string($entry['e'] ?? null) ? $entry['e'] : null, 'd' => $entry['d']];
            }
        }

        return $entries;
    }

    /** @param array{i: string, e: ?string, d: array<array-key, mixed>} $entry */
    private function recordLocally(string $sessionId, array $entry): void
    {
        if (!isset($this->local[$sessionId]) && count($this->local) >= self::LOCAL_SESSIONS) {
            array_shift($this->local); // the oldest session's ring goes first
        }
        $this->local[$sessionId][] = $entry;
        if (count($this->local[$sessionId]) > self::CAPACITY) {
            array_shift($this->local[$sessionId]);
        }
    }
}
