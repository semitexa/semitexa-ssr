<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Swoole\Atomic\Long;

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
 * and its late writes must not land in the row the new worker now counts in.
 * Owner and count share one 64-bit atomic (the pid above bit 32, the count
 * below), and a write is a compare-and-set on the whole word: a write from a pid
 * that does not own the row is dropped, even when the new worker claims the row
 * between that write's read and its store, since the store then finds a changed
 * word and the retry reads the new owner. No lock is taken, so a worker that dies
 * in the middle of a write cannot hold up the others (a Swoole mutex is not a
 * robust one). For the drain window the old worker's closing streams are not
 * counted.
 */
final class SseConnectionGauge
{
    /** The owner pid sits above this bit; the count below it. */
    private const OWNER_SHIFT = 32;

    private const COUNT_MASK = 0xFFFFFFFF;

    /** @param non-empty-list<Long> $rows One per worker id. */
    private function __construct(private readonly array $rows)
    {
    }

    /**
     * Allocate the shared rows. Call it before the workers fork, or each worker
     * gets rows of its own and the total is its own count again.
     */
    public static function create(int $workerCount): self
    {
        $rows = [];
        for ($workerId = 0; $workerId < max(1, $workerCount); $workerId++) {
            $rows[] = new Long(0);
        }

        return new self($rows);
    }

    /** The number of workers the server-wide cap is multiplied by. */
    public function workerCount(): int
    {
        return \count($this->rows);
    }

    /**
     * A starting worker takes its row and resets it to 0: whatever the previous
     * worker with this id left there (it crashed, was killed, or is draining)
     * is not this worker's. A worker id past the counted workers (a task worker)
     * has no row.
     */
    public function claim(int $workerId, int $pid): void
    {
        ($this->rows[$workerId] ?? null)?->set(self::word($pid, 0));
    }

    /**
     * Write the number of connections this worker holds now. Dropped unless
     * $pid owns the row, so a worker that was replaced cannot write into its
     * successor's count.
     */
    public function publish(int $workerId, int $pid, int $open): void
    {
        $row = $this->rows[$workerId] ?? null;
        if ($row === null) {
            return;
        }
        $next = self::word($pid, $open);
        do {
            $current = $row->get();
            if ($current >> self::OWNER_SHIFT !== $pid) {
                return;
            }
        } while (!$row->cmpset($current, $next));
    }

    /** What one worker last published; 0 for a worker that never started. */
    public function openOn(int $workerId): int
    {
        $row = $this->rows[$workerId] ?? null;

        return $row === null ? 0 : $row->get() & self::COUNT_MASK;
    }

    /** Open connections on the whole server: the sum of the workers' rows. */
    public function total(): int
    {
        $total = 0;
        foreach ($this->rows as $row) {
            $total += $row->get() & self::COUNT_MASK;
        }

        return $total;
    }

    private static function word(int $pid, int $open): int
    {
        return ($pid << self::OWNER_SHIFT) | min(max(0, $open), self::COUNT_MASK);
    }
}
