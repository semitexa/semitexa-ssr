<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Swoole\Http\Server;
use Swoole\Table;

/**
 * Cross-worker delivery over shared memory — the single-server fallback used
 * when Redis is unavailable.
 *
 * Three {@see Table}s, each a distinct hop in the delivery chain:
 *
 *  - **session → worker** answers "which worker holds this session's socket";
 *  - **deliver queue** carries a frame to that worker, which drains it on its
 *    own loop tick;
 *  - **pending queue** holds a frame whose session has no known worker yet, so a
 *    connection that arrives moments later still receives it.
 *
 * Swoole tables are shared memory rather than per-worker state, which is exactly
 * why they can bridge workers at all — and also why every write must survive a
 * reader on another worker with no coordination beyond the table itself.
 *
 * Wired at worker boot and **null until then**. Every method degrades to a safe
 * no-op or a negative answer while unwired, so the SSE path works before the
 * lifecycle listener runs and in tests that never stand up a Swoole server.
 *
 * Note the key discipline: a Swoole table key is capped at 63 bytes, so a longer
 * session id is hashed. {@see tableKey()} is the ONE place that decides this —
 * a second copy that forgot the cap would silently write to a truncated key and
 * deliver to the wrong session, or to none.
 */
final class SseWorkerTables
{
    private const MAX_KEY_LENGTH = 63;

    private ?Server $server = null;
    private ?Table $sessionWorker = null;
    private ?Table $deliver = null;
    private ?Table $pending = null;

    public function setServer(Server $server): void
    {
        $this->server = $server;
    }

    public function setTables(Table $sessionWorker, Table $deliver, ?Table $pending = null): void
    {
        $this->sessionWorker = $sessionWorker;
        $this->deliver = $deliver;
        $this->pending = $pending;
    }

    public function server(): ?Server
    {
        return $this->server;
    }

    /**
     * A Swoole table key is capped at 63 bytes; anything longer is hashed so the
     * key stays unique instead of being truncated into a collision.
     */
    public static function tableKey(string $sessionId): string
    {
        return strlen($sessionId) > self::MAX_KEY_LENGTH ? md5($sessionId) : $sessionId;
    }

    /**
     * This worker's id, or `-1` when there is no server to ask — which callers
     * read as "no worker identity", never as worker zero.
     */
    public function currentWorkerId(): int
    {
        if ($this->server === null) {
            return -1;
        }

        if (method_exists($this->server, 'getWorkerId')) {
            return (int) $this->server->getWorkerId();
        }

        $workerId = $this->server->worker_id ?? -1;

        return is_numeric($workerId) ? (int) $workerId : -1;
    }

    public function canRouteCrossWorker(): bool
    {
        return $this->sessionWorker !== null && $this->deliver !== null && $this->server !== null;
    }

    public function canRecordOwnership(): bool
    {
        return $this->sessionWorker !== null && $this->server !== null;
    }

    public function hasPendingTable(): bool
    {
        return $this->pending !== null;
    }

    /** Claim this session's socket for the current worker. */
    public function recordOwnership(string $sessionId): void
    {
        $this->sessionWorker?->set(self::tableKey($sessionId), ['worker_id' => $this->currentWorkerId()]);
    }

    public function releaseOwnership(string $sessionId): void
    {
        $this->sessionWorker?->del(self::tableKey($sessionId));
    }

    public function isOwnedSomewhere(string $sessionId): bool
    {
        return $this->sessionWorker !== null
            && $this->sessionWorker->get(self::tableKey($sessionId)) !== false;
    }

    /**
     * Which worker holds this session, or `null` when nothing claims it.
     */
    public function ownerWorkerId(string $sessionId): ?int
    {
        if ($this->sessionWorker === null) {
            return null;
        }

        $row = $this->sessionWorker->get(self::tableKey($sessionId));

        return $row === false ? null : (int) $row['worker_id'];
    }

    /**
     * Hand a frame to another worker.
     *
     * @param array<string, mixed> $data
     * @return bool `false` when there is no deliver table or the payload cannot
     *              be encoded, so the caller keeps walking the fallback chain.
     */
    public function queueForWorker(string $sessionId, int $targetWorkerId, array $data): bool
    {
        $payload = self::encode($data);
        if ($this->deliver === null || $payload === null) {
            return false;
        }

        $this->deliver->set(uniqid('d_', true), [
            'session_id' => $sessionId,
            'worker_id' => $targetWorkerId,
            'payload' => $payload,
        ]);

        return true;
    }

    /**
     * Park a frame for a session with no known owner yet.
     *
     * @param array<string, mixed> $data
     */
    public function queuePending(string $sessionId, array $data): bool
    {
        $payload = self::encode($data);
        if ($this->pending === null || $payload === null) {
            return false;
        }

        $this->pending->set(uniqid('p_', true), [
            'session_id' => $sessionId,
            'payload' => $payload,
        ]);

        return true;
    }

    /**
     * Take every pending payload addressed to a session, deleting the rows.
     *
     * Take-all is right here, unlike {@see readDeliveriesFor()}: a pending row is
     * consumed by the act of being handed to a connection that has just opened,
     * and a payload that fails to decode is dropped rather than left to be
     * re-read forever. Keys are not returned because the caller has nothing left
     * to do with them.
     *
     * @return list<string> encoded payloads, in table order.
     */
    public function takePendingFor(string $sessionId): array
    {
        if ($this->pending === null) {
            return [];
        }

        $keys = [];
        $payloads = [];
        foreach ($this->pending as $key => $row) {
            if (!is_array($row) || trim((string) ($row['session_id'] ?? '')) !== $sessionId) {
                continue;
            }

            $keys[] = (string) $key;
            $payloads[] = (string) ($row['payload'] ?? '');
        }

        foreach ($keys as $key) {
            $this->pending->del($key);
        }

        return $payloads;
    }

    /**
     * Rows addressed to one worker AND one session, WITHOUT deleting them.
     *
     * Read and delete are deliberately separate here, unlike the pending table.
     * The deliver drain consumes a row only if it actually got the frame out: a
     * failed socket write must LEAVE the row for the next tick, and a stream that
     * closes mid-drain must leave everything after it untouched. A take-all API
     * would silently drop exactly those frames.
     *
     * @return list<array{key: string, payload: string}>
     */
    public function readDeliveriesFor(int $workerId, string $sessionId): array
    {
        if ($this->deliver === null) {
            return [];
        }

        $rows = [];
        foreach ($this->deliver as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((int) ($row['worker_id'] ?? -1) !== $workerId) {
                continue;
            }

            if (trim((string) ($row['session_id'] ?? '')) !== $sessionId) {
                continue;
            }

            $rows[] = ['key' => (string) $key, 'payload' => (string) ($row['payload'] ?? '')];
        }

        return $rows;
    }

    /** Consume one deliver row, once the caller knows the frame is out. */
    public function deleteDelivery(string $key): void
    {
        $this->deliver?->del($key);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): ?string
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($payload) ? $payload : null;
    }
}
