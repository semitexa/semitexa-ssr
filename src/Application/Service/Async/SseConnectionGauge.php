<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Support\Row;
use Swoole\Table;

/**
 * How many SSE connections every worker of the server holds, in shared memory,
 * so {@see SseConnectionLimiter} can enforce one server-wide cap.
 *
 * WHY IT EXISTS. Under dispatch_mode 2 a connection lands on the worker
 * `fd % workers`, and the split is skewed: on the stand one worker of four held
 * about 60% of the streams. With the cap counted per worker, that worker refused
 * at 500 while the server held about 900 of a nominal 2000.
 *
 * ONE ROW PER WORKER ID, AND THE TOTAL IS THEIR SUM, rather than one shared
 * counter. A slot is given back from a `Coroutine::defer`, which never runs when
 * the worker crashes or is OOM-killed: a single counter would keep those slots
 * forever. Here the worker Swoole starts in the dead one's place
 * {@see claim()}s the row and it starts again from 0.
 *
 * A WORKER WRITES ITS OWN COUNT, NOT A DELTA ({@see publish()}). The row always
 * holds what its owner last counted, so there is nothing to drift, and each row
 * has one writer.
 *
 * THE ROW HAS AN OWNER, its pid. On a reload (`reload_async`) the old worker
 * with the same id lives on for up to `max_wait_time` while its streams close,
 * and its late writes must not land in the row the new worker now counts in:
 * a write from a pid that is not the owner is dropped. For that drain window
 * the old worker's closing streams are not counted. If the old worker reads the
 * owner just before the new one claims, one stale count can land; the new
 * worker's next acquire or release overwrites it.
 */
final class SseConnectionGauge
{
    /** Never size the table below this; a handful of rows costs nothing. */
    private const MIN_ROWS = 64;

    /** Collision slots as a share of the table size (Swoole's default is 0.2). */
    private const CONFLICT_PROPORTION = 1.0;

    private const OPEN_COLUMN = 'open';
    private const PID_COLUMN = 'pid';
    private const COLUMN_INT_SIZE = 8;

    /** @var positive-int */
    private readonly int $workerCount;

    private function __construct(private readonly Table $table, int $workerCount)
    {
        $this->workerCount = max(1, $workerCount);
    }

    /**
     * Allocate the shared rows. Call it before the workers fork, or each worker
     * gets a table of its own and the total is its own count again.
     */
    public static function create(int $workerCount): self
    {
        // A conflict pool as large as the table: Swoole rounds the size up to a
        // power of two and keeps 20% for collisions by default, and with the keys
        // "0".."N-1" that refused claim() for some workers from 48 up (a failed
        // claim never publishes, so that worker's streams went uncounted).
        $table = new Table(max(self::MIN_ROWS, $workerCount), self::CONFLICT_PROPORTION);
        $table->column(self::OPEN_COLUMN, Table::TYPE_INT, self::COLUMN_INT_SIZE);
        $table->column(self::PID_COLUMN, Table::TYPE_INT, self::COLUMN_INT_SIZE);
        $table->create();

        return new self($table, $workerCount);
    }

    /** The number of workers the server-wide cap is multiplied by. */
    public function workerCount(): int
    {
        return $this->workerCount;
    }

    /**
     * A starting worker takes its row and resets it to 0: whatever the previous
     * worker with this id left there (it crashed, was killed, or is draining)
     * is not this worker's.
     */
    public function claim(int $workerId, int $pid): void
    {
        $this->table->set((string) $workerId, [self::OPEN_COLUMN => 0, self::PID_COLUMN => $pid]);
    }

    /**
     * Write the number of connections this worker holds now. Dropped unless
     * $pid owns the row, so a worker that was replaced cannot write into its
     * successor's count.
     */
    public function publish(int $workerId, int $pid, int $open): void
    {
        $key = (string) $workerId;
        $row = $this->table->get($key);
        if (!is_array($row) || Row::of($row)->int(self::PID_COLUMN) !== $pid) {
            return;
        }

        // Only the count: the owner column stays as claim() wrote it.
        $this->table->set($key, [self::OPEN_COLUMN => max(0, $open)]);
    }

    /** What one worker last published; 0 for a worker that never started. */
    public function openOn(int $workerId): int
    {
        $row = $this->table->get((string) $workerId);

        return is_array($row) ? max(0, Row::of($row)->int(self::OPEN_COLUMN)) : 0;
    }

    /** Open connections on the whole server: the sum of the workers' rows. */
    public function total(): int
    {
        $total = 0;
        for ($workerId = 0; $workerId < $this->workerCount; $workerId++) {
            $total += $this->openOn($workerId);
        }

        return $total;
    }
}
