<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Swoole\Table;

/**
 * Track R · Intended Grid Model · Phase 2 (C2) — the per-stream view-change
 * coalescer: the "latest view wins, collapse pending" discipline for a
 * `{__ctrl:viewchange}` command, the direct analogue of {@see RerunCoalescer}.
 *
 * A grid that holds ONE open stream turns every paging / filter / sort change into
 * a view-change COMMAND on a separate request. A rapid burst (type-ahead search,
 * fast pager clicks) must NOT storm one re-run per keystroke. This collapses a
 * burst to ONE pending re-run per stream AND keeps the LATEST view's params, so the
 * single re-run that drains re-queries the final view — not the first.
 *
 * Two differences from {@see RerunCoalescer}, both essential to a view-change:
 *  1. **A params slot.** A mutation-driven re-run is data-less (re-query the same
 *     view); a view-change re-run carries NEW params. The latest params are written
 *     into a cross-worker `params` column each command, so a coalesced burst keeps
 *     the final view (last-write-wins) rather than the first.
 *  2. **A separate counter.** Kept distinct from {@see RerunCoalescer} so a
 *     mutation re-run and a view-change re-run never suppress each other — a lead
 *     arriving mid-search and a search keystroke are independent signals.
 *
 * The mechanism mirrors R3: an atomic {@see \Swoole\Table::incr()} `pending`
 * counter gates the enqueue (only the 0→1 transition enqueues a control; later
 * commands in the window coalesce). The command intake
 * ({@see AsyncResourceSseServer::submitViewChange()}) calls {@see self::submit()};
 * the owning worker's loop branch ({@see AsyncResourceSseServer::handleControlFrame()})
 * calls {@see self::consume()} which atomically reads the latest params and clears
 * the row, re-arming the next burst. Cross-worker by construction (shared
 * {@see \Swoole\Table} mmap, created pre-fork alongside the R3 coalescer), because
 * the command request and the held-open stream are usually on different workers.
 */
final class ViewChangeCoalescer
{
    /**
     * `pending` — the atomic 0→1 enqueue gate (mirrors {@see RerunCoalescer}).
     * `params` — the latest view's params as a JSON string (last-write-wins). 4 KB
     * is far above any grid's flat filter/paging/sort set; an over-long encode is
     * truncated → decode fails → an empty override (a safe no-op re-run), never a
     * crash.
     *
     * @var array<string, int>
     */
    private const COLUMNS = [
        'pending' => 8,
        'params' => 4096,
    ];

    public function __construct(
        private readonly Table $table,
    ) {}

    /**
     * Build a real, created {@see \Swoole\Table} for the view-change counters +
     * params slot and wrap it. The single place the schema is materialised, so the
     * server-lifecycle listener (cross-worker shared mmap, created before worker
     * fork) and a unit test stand up byte-identical coalescers.
     */
    public static function create(int $maxRows): self
    {
        $table = new Table($maxRows);
        $table->column('pending', Table::TYPE_INT, self::COLUMNS['pending']);
        $table->column('params', Table::TYPE_STRING, self::COLUMNS['params']);
        $table->create();

        return new self($table);
    }

    /**
     * Record a view-change command for `$streamingId`: store its params as the
     * latest view (overwrite) and atomically gate the enqueue.
     *
     * @param array<string, mixed> $params the command's new view params
     * @return bool TRUE only for the command that transitions the stream from "no
     *              pending view-change" to "one pending" (counter 0→1) — the caller
     *              that should enqueue exactly one `{__ctrl:viewchange}` control.
     *              FALSE when one is already pending — the command is coalesced (its
     *              params still overwrote the slot, so the LATEST view wins) and MUST
     *              NOT enqueue another control.
     */
    public function submit(string $streamingId, array $params): bool
    {
        $key = $this->key($streamingId);

        // Atomic gate first (creates the row at pending=1 when absent), then write
        // BOTH columns back explicitly with the incremented pending — so the params
        // overwrite never disturbs the counter regardless of Swoole's partial-set
        // semantics. Mirrors R3's incr-as-the-atomic-primitive discipline.
        $pending = (int) $this->table->incr($key, 'pending', 1);
        $this->table->set($key, [
            'pending' => $pending,
            'params' => self::encode($params),
        ]);

        return $pending === 1;
    }

    /**
     * Atomically read the latest view's params for `$streamingId` and clear the row
     * (re-arming the next burst). Called by the loop branch (R4) when it drains a
     * `{__ctrl:viewchange}` control. Returns null when nothing is pending (the
     * decisive cross-worker / torn-down edge — a safe no-op re-run is skipped).
     *
     * @return array<string, mixed>|null
     */
    public function consume(string $streamingId): ?array
    {
        $key = $this->key($streamingId);
        $row = $this->table->get($key);
        // Clear regardless: a present-but-undecodable row is still consumed so a
        // later command can re-arm rather than coalescing into a dead pending mark.
        $this->table->del($key);

        if ($row === false) {
            return null;
        }

        return self::decode((string) ($row['params'] ?? ''));
    }

    public function isPending(string $streamingId): bool
    {
        return $this->table->exist($this->key($streamingId));
    }

    /**
     * Peek the latest stored params WITHOUT consuming (tests / introspection).
     *
     * @return array<string, mixed>|null
     */
    public function peek(string $streamingId): ?array
    {
        $row = $this->table->get($this->key($streamingId));
        if ($row === false) {
            return null;
        }

        return self::decode((string) ($row['params'] ?? ''));
    }

    public function count(): int
    {
        return count($this->table);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function encode(array $params): string
    {
        try {
            return json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Mirror the existing session-key discipline ({@see RerunCoalescer::key()} /
     * {@see SubscriptionTable}) so an over-long streaming_id is hashed rather than
     * truncated past the {@see \Swoole\Table} key limit.
     */
    private function key(string $streamingId): string
    {
        return strlen($streamingId) > 63 ? md5($streamingId) : $streamingId;
    }
}
