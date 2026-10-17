<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseWorkerTables;
use Swoole\Table;

/**
 * A reconnect reuses the page's session id. The connection that owned the
 * session before must be able to see, at its late teardown, that the session
 * is no longer its own — on this worker or another, since the table is shared.
 */
#[RequiresPhpExtension('swoole')]
final class SseWorkerTablesOwnershipTest extends TestCase
{
    #[Test]
    public function a_connection_is_superseded_only_once_a_newer_one_claims_its_session(): void
    {
        [$workerA, $workerB] = $this->twoWorkersOnOneTable();
        $old = new \stdClass();
        $new = new \stdClass();

        $workerA->recordOwnership('sse_s', $old);
        self::assertNull($workerA->supersededAt('sse_s', $old), 'its own session');

        // The page reconnects and lands on the other worker.
        $workerB->recordOwnership('sse_s', $new);
        $at = $workerA->supersededAt('sse_s', $old);

        self::assertIsInt($at);
        self::assertGreaterThan(0, $at);
        self::assertNull($workerB->supersededAt('sse_s', $new), 'the new connection still owns it');
    }

    #[Test]
    public function a_same_worker_reconnect_supersedes_too(): void
    {
        [$worker] = $this->twoWorkersOnOneTable();
        $old = new \stdClass();
        $new = new \stdClass();

        $worker->recordOwnership('sse_s', $old);
        $worker->recordOwnership('sse_s', $new);

        self::assertIsInt($worker->supersededAt('sse_s', $old));
        self::assertNull($worker->supersededAt('sse_s', $new));
    }

    #[Test]
    public function without_a_recorded_owner_the_teardown_stays_whole(): void
    {
        [$worker] = $this->twoWorkersOnOneTable();
        $connection = new \stdClass();

        // A connection that never claimed, a session nobody claims, a released
        // claim, and a claim made without naming a connection.
        self::assertNull($worker->supersededAt('sse_s', $connection));
        $worker->recordOwnership('sse_s', $connection);
        $worker->releaseOwnership('sse_s');
        self::assertNull($worker->supersededAt('sse_s', $connection));
        $worker->recordOwnership('sse_s', $connection);
        $worker->recordOwnership('sse_s');
        self::assertNull($worker->supersededAt('sse_s', $connection));
        self::assertNull((new SseWorkerTables())->supersededAt('sse_s', $connection), 'no tables at all');
    }

    #[Test]
    public function a_superseded_connection_cannot_release_the_new_connections_claim(): void
    {
        [$worker] = $this->twoWorkersOnOneTable();
        $old = new \stdClass();
        $new = new \stdClass();

        $worker->recordOwnership('sse_s', $old);
        $worker->recordOwnership('sse_s', $new);
        $worker->releaseOwnership('sse_s', $old);

        self::assertTrue($worker->isOwnedSomewhere('sse_s'), 'the late teardown left the new claim in place');
        $worker->releaseOwnership('sse_s', $new);
        self::assertFalse($worker->isOwnedSomewhere('sse_s'));
    }

    #[Test]
    public function only_the_owning_worker_counts_as_holding_the_session(): void
    {
        [$a] = $this->twoWorkersOnOneTable();
        self::assertTrue($a->ownedHere('sse_unclaimed'), 'nothing recorded: deliver locally as before');

        $a->recordOwnership('sse_s', new \stdClass());
        self::assertTrue($a->ownedHere('sse_s'));
    }

    /** @return array{SseWorkerTables, SseWorkerTables} two workers' views of one shared session table */
    private function twoWorkersOnOneTable(): array
    {
        $sessions = new Table(64);
        $sessions->column('worker_id', Table::TYPE_INT, 8);
        $sessions->column('connection', Table::TYPE_STRING, 16);
        $sessions->column('opened_at_ms', Table::TYPE_INT, 8);
        $sessions->create();
        $deliver = new Table(8);
        $deliver->column('payload', Table::TYPE_STRING, 64);
        $deliver->create();

        $a = new SseWorkerTables();
        $a->setTables($sessions, $deliver);
        $b = new SseWorkerTables();
        $b->setTables($sessions, $deliver);

        return [$a, $b];
    }
}
