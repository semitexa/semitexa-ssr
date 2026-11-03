<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseWorkerTables;
use Swoole\Atomic;
use Swoole\Lock;
use Swoole\Table;

/**
 * A tab's old connection tears down on one worker while its reconnect claims
 * the session on another. releaseOwnership() reads the row and then deletes
 * it; a claim written between the two was erased, and deliveries for the
 * reconnected tab were no longer routed to its worker. Real processes, since
 * the race is between them; without the lock 1 882 of 2 000 such pairs
 * erased the claim.
 */
final class SseWorkerTablesOwnershipRaceTest extends TestCase
{
    private const RUNS = 300;

    #[Test]
    public function an_old_connections_teardown_never_erases_its_reconnects_claim(): void
    {
        [$sessions, $deliver] = $this->tables();
        $lock = new Lock(\defined('SWOOLE_MUTEX') ? SWOOLE_MUTEX : 2);
        $erased = 0;
        for ($run = 0; $run < self::RUNS; $run++) {
            $old = new SseWorkerTables();
            $old->setTables($sessions, $deliver, null, $lock);
            $connection = new \stdClass();
            $old->recordOwnership('tab', $connection);
            $go = new Atomic(0);

            $teardown = $this->fork(static function () use ($old, $connection, $go): void {
                while ($go->get() === 0) {
                }
                $old->releaseOwnership('tab', $connection);
            });
            $reconnect = $this->fork(static function () use ($sessions, $deliver, $lock, $go): void {
                $fresh = new SseWorkerTables();
                $fresh->setTables($sessions, $deliver, null, $lock);
                while ($go->get() === 0) {
                }
                $fresh->recordOwnership('tab', new \stdClass());
            });
            usleep(200);
            $go->set(1);
            $this->wait($teardown);
            $this->wait($reconnect);

            $erased += $sessions->get('tab') === false ? 1 : 0;
            $sessions->del('tab');
        }

        self::assertSame(0, $erased, "{$erased} of " . self::RUNS . " reconnect claims were erased by the old connection's teardown");
    }

    #[Test]
    public function a_lock_its_holder_never_releases_keeps_the_row_and_still_takes_a_claim(): void
    {
        [$sessions, $deliver] = $this->tables();
        $lock = new Lock(\defined('SWOOLE_MUTEX') ? SWOOLE_MUTEX : 2);
        $tables = new SseWorkerTables();
        $tables->setTables($sessions, $deliver, null, $lock);
        $connection = new \stdClass();
        $tables->recordOwnership('tab', $connection);
        // The once-per-process warning would time the logger's first boot, not the wait.
        $warned = new \ReflectionProperty(SseWorkerTables::class, 'warnedUnlocked');
        $warned->setValue(null, true);
        // A worker that died inside the section: the mutex stays held.
        $lock->lock();
        try {
            $started = microtime(true);
            $tables->releaseOwnership('tab', $connection);
            $released = $sessions->get('tab');
            $tables->recordOwnership('other', new \stdClass());
            $waited = microtime(true) - $started;
        } finally {
            $lock->unlock();
            $warned->setValue(null, false);
        }

        self::assertIsArray($released, 'a release it could not lock is never done unlocked');
        self::assertIsArray($sessions->get('other'), 'a claim it could not lock is still written');
        self::assertGreaterThanOrEqual(0.2, $waited, 'both waited for the lock');
        self::assertLessThan(1.0, $waited, 'bounded by the lock wait, not by the holder');

        // The next ownership call that takes the lock finishes the release.
        $tables->recordOwnership('third', new \stdClass());
        self::assertFalse($sessions->get('tab'), 'the deferred release is done under the lock');
    }

    #[Test]
    public function a_deferred_release_spares_a_claim_made_since(): void
    {
        [$sessions, $deliver] = $this->tables();
        $lock = new Lock(\defined('SWOOLE_MUTEX') ? SWOOLE_MUTEX : 2);
        $old = new SseWorkerTables();
        $old->setTables($sessions, $deliver, null, $lock);
        $connection = new \stdClass();
        $old->recordOwnership('tab', $connection);
        $warned = new \ReflectionProperty(SseWorkerTables::class, 'warnedUnlocked');
        $warned->setValue(null, true);
        $lock->lock();
        try {
            $old->releaseOwnership('tab', $connection);
        } finally {
            $lock->unlock();
            $warned->setValue(null, false);
        }
        // The tab reconnects (another worker) before the old one's worker takes the lock again.
        $fresh = new SseWorkerTables();
        $fresh->setTables($sessions, $deliver, null, $lock);
        $fresh->recordOwnership('tab', new \stdClass());
        $claim = $sessions->get('tab');

        $old->recordOwnership('other', new \stdClass());

        self::assertSame($claim, $sessions->get('tab'), 'the reconnect\'s claim is not the released one');
    }

    /** @return array{Table, Table} */
    private function tables(): array
    {
        $sessions = new Table(64);
        $sessions->column('worker_id', Table::TYPE_INT, 8);
        $sessions->column('connection', Table::TYPE_STRING, 16);
        $sessions->column('opened_seq', Table::TYPE_INT, 8);
        $sessions->create();
        $deliver = new Table(8);
        $deliver->column('session_id', Table::TYPE_STRING, 64);
        $deliver->create();

        return [$sessions, $deliver];
    }

    private function fork(\Closure $body): int
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $body();
            exit(0);
        }
        self::assertGreaterThan(0, $pid, 'fork failed');

        return $pid;
    }

    private function wait(int $pid): void
    {
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'a process failed');
    }
}
