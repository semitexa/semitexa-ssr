<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Ssr\Domain\Model\SubscriptionRecord;
use Swoole\Table;

/**
 * Tier 1 of the three-tier subscription store (Track R · R1, design §C.6):
 * the cross-worker, SERIALIZED `subscriptionTable`.
 *
 * One row per live subscription, keyed by `streaming_id`, backed by a real
 * {@see \Swoole\Table} (shared mmap) so a worker that is NOT the owner can read
 * it — the load-bearing property the reverse-index scan (R3 on worker X) relies
 * on while the stream itself is pinned to its owning worker W.
 *
 * THE TIER-SEPARATION INVARIANT (the security boundary, design §C.6): the schema
 * declares ONLY string columns plus a single INT metadata column ({@see
 * self::CONNECTED_AT_COLUMN}). There is no DTO column, no object column. A live
 * identity-bearing object (the cached Payload DTO / re-run state) **cannot** be
 * expressed in this table by construction — it lives exclusively in the
 * worker-static {@see SubscriptionDtoRegistry}. This is the decisive structural
 * reason the cross-worker tier cannot leak identity: a serialized row literally
 * cannot carry a live subject. The `tenant_blob` column carries the serialized
 * tenant context (opaque to this store), NOT a live tenant object. `connected_at`
 * is a bare epoch integer (a connect timestamp), not identity and not an object —
 * it is the staleness basis for the crashed-worker orphan sweep ({@see
 * self::reapStaleConnections()}), so the invariant is unaffected.
 *
 * Single-writer discipline (design §C.6): a subscription's row is written only
 * by its owning worker W (on connect / close) and read by X's subscriber, so no
 * lock is needed for this scan tier. (The future keyed-Table upgrade would be
 * multi-writer and would need a {@see \Swoole\Lock}.)
 *
 * R1 is the store ONLY: this class has no connect, no subscribe, no loop. Rows
 * are written by callers (R5) and read by the reverse index (R3 via
 * {@see ScanningSubscriberIndex}); R1's tests insert rows directly.
 */
final class SubscriptionTable
{
    /**
     * Connect-time epoch column. The ONLY non-string column — a bare
     * {@see Table::TYPE_INT} stamped at {@see self::insert()} and read by the
     * crashed-worker orphan sweep ({@see self::reapStaleConnections()}). It holds
     * no identity and no object, so the tier-separation invariant still holds.
     */
    private const CONNECTED_AT_COLUMN = 'connected_at';

    /**
     * The string schema: column name => byte size. Every column here is
     * {@see Table::TYPE_STRING}. The absence of any object/DTO column is the
     * structural proof of the tier-separation invariant (the lone INT column,
     * {@see self::CONNECTED_AT_COLUMN}, carries a timestamp, never a subject).
     *
     * @var array<string, int>
     */
    private const COLUMNS = [
        // The original streaming_id is stored as a column (not only as the table
        // key) so the scan reads the true id even when an over-long id is hashed
        // into the key (see key()). This keeps a scan-resolved SubscriberRef
        // routable cross-worker regardless of id length.
        'streaming_id' => 128,
        'session_id' => 128,
        'tenant_id' => 128,
        'scope_keys' => 4096,
        'tenant_blob' => 8192,
    ];

    public function __construct(
        private readonly Table $table,
    ) {}

    /**
     * Build a real, created {@see \Swoole\Table} with the subscription schema and
     * wrap it. The single place the schema is materialised, so a server-lifecycle
     * listener and a unit test stand up byte-identical stores.
     */
    public static function create(int $maxRows): self
    {
        $table = new Table($maxRows);
        foreach (self::COLUMNS as $name => $size) {
            $table->column($name, Table::TYPE_STRING, $size);
        }
        // The lone INT column — connect epoch, the staleness basis for the sweep.
        $table->column(self::CONNECTED_AT_COLUMN, Table::TYPE_INT);
        $table->create();

        return new self($table);
    }

    /**
     * The declared schema columns, in order. Exposed so the tier-separation
     * invariant is assertable: there is no DTO/object column.
     *
     * @return list<string>
     */
    public static function schemaColumns(): array
    {
        return [...array_keys(self::COLUMNS), self::CONNECTED_AT_COLUMN];
    }

    /**
     * Insert (or replace) the row for a subscription. Scope keys are JSON-encoded
     * into the single `scope_keys` string column; the tenant blob is stored as the
     * opaque serialized string the caller supplies (R1 never interprets it).
     */
    public function insert(SubscriptionRecord $record): void
    {
        $this->table->set($this->key($record->streamingId), [
            'streaming_id' => $record->streamingId,
            'session_id' => $record->sessionId,
            'tenant_id' => $record->tenantId,
            'scope_keys' => $this->encodeScopeKeys($record->scopeKeys),
            'tenant_blob' => $record->tenantBlob,
            // Connect epoch — re-stamped on a same-id reconnect (a fresh connection),
            // which is correct: the staleness clock restarts with the new stream.
            self::CONNECTED_AT_COLUMN => time(),
        ]);
    }

    public function get(string $streamingId): ?SubscriptionRecord
    {
        $row = $this->table->get($this->key($streamingId));
        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function has(string $streamingId): bool
    {
        return $this->table->exist($this->key($streamingId));
    }

    public function remove(string $streamingId): void
    {
        $this->table->del($this->key($streamingId));
    }

    /**
     * Crashed-worker orphan sweep (Stream Lifecycle · Axis 2, Phase 1 — the one
     * residual leak, design §5). Evict every row whose `connected_at` is older
     * than `$maxAgeSeconds` before `$now`, returning the evicted `streaming_id`s.
     *
     * The held-open loop force-closes a healthy stream at its own
     * `SSE_MAX_CONNECTION_AGE_SECONDS` cap and reaps it in its `finally`
     * ({@see AsyncResourceSseServer}), so `$maxAgeSeconds` is that cap PLUS a grace
     * margin — a row older than cap+grace therefore CANNOT be a live stream; it is
     * necessarily a tier-1 row orphaned by a worker that hard-crashed before its
     * `finally` could run. This is age-based ONLY: there is deliberately NO
     * pid/liveness probe, because Swoole restarts a crashed worker under the SAME
     * `worker_id`, so a liveness check could false-positive on a row now legitimately
     * owned by the restarted worker (design §5.3). Age + grace is the robust,
     * zero-false-positive criterion — a live stream within the window is never
     * touched.
     *
     * A row with no positive timestamp (`connected_at <= 0`) is left ALONE: we
     * cannot prove it is an orphan, and the contract is to never reap a possibly-live
     * stream. Deletes are collected first, then applied, mirroring
     * {@see \Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry::gc()}
     * (no del during the table iteration).
     *
     * @return list<string> the evicted streaming_ids, so the caller can reap any
     *                      directly-coupled cross-worker state (the coalescer
     *                      pending mark) the dead worker's `onDisconnect` never cleared
     */
    public function reapStaleConnections(int $maxAgeSeconds, int $now): array
    {
        /** @var array<string, string> $stale key => streaming_id */
        $stale = [];
        foreach ($this->table as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $connectedAt = (int) ($row[self::CONNECTED_AT_COLUMN] ?? 0);
            if ($connectedAt <= 0) {
                continue; // un-aged row — never reap (cannot prove orphan)
            }
            if (($now - $connectedAt) <= $maxAgeSeconds) {
                continue; // within the age+grace window — possibly live, never reap
            }
            $stale[(string) $key] = (string) ($row['streaming_id'] ?? '');
        }

        $evicted = [];
        foreach ($stale as $key => $streamingId) {
            $this->table->del($key);
            if ($streamingId !== '') {
                $evicted[] = $streamingId;
            }
        }

        return $evicted;
    }

    public function count(): int
    {
        return count($this->table);
    }

    /**
     * Every live subscription row, hydrated. The reverse-index scan iterates
     * this (the O(rows) seam, design §C.5).
     *
     * @return iterable<SubscriptionRecord>
     */
    public function all(): iterable
    {
        foreach ($this->table as $row) {
            if (!is_array($row)) {
                continue;
            }
            yield $this->hydrate($row);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SubscriptionRecord
    {
        return new SubscriptionRecord(
            streamingId: (string) ($row['streaming_id'] ?? ''),
            sessionId: (string) ($row['session_id'] ?? ''),
            tenantId: (string) ($row['tenant_id'] ?? ''),
            scopeKeys: $this->decodeScopeKeys((string) ($row['scope_keys'] ?? '')),
            tenantBlob: (string) ($row['tenant_blob'] ?? ''),
        );
    }

    /**
     * @param list<string> $scopeKeys
     */
    private function encodeScopeKeys(array $scopeKeys): string
    {
        return json_encode(array_values($scopeKeys), JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function decodeScopeKeys(string $encoded): array
    {
        if ($encoded === '') {
            return [];
        }
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $v): string => (string) $v, $decoded));
    }

    /**
     * Swoole\Table keys are bounded; mirror the existing session-key discipline
     * ({@see AsyncResourceSseServer::sessionTableKey()}) so an over-long
     * streaming_id is hashed rather than truncated.
     */
    private function key(string $streamingId): string
    {
        return strlen($streamingId) > 63 ? md5($streamingId) : $streamingId;
    }
}
