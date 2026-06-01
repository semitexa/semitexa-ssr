<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\ViewChangeCoalescer;

/**
 * Intended Grid Model · Phase 2 (C2) — the view-change coalescer: "latest view
 * wins, collapse pending", the analogue of {@see \Semitexa\Ssr\Application\Service\Async\RerunCoalescer}
 * with a params slot.
 *
 * Proves: only the 0→1 command enqueues (a burst collapses to ONE re-run); the
 * LATEST params survive the collapse (last-write-wins); consume reads + clears
 * (re-arming the next burst); and a torn-down / never-seen stream consumes to null.
 */
final class ViewChangeCoalescerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Swoole\Table::class, false)) {
            self::markTestSkipped('Swoole extension not loaded.');
        }
    }

    #[Test]
    public function only_the_first_command_in_a_burst_enqueues_and_the_latest_view_wins(): void
    {
        $coalescer = ViewChangeCoalescer::create(64);

        // A rapid burst of three view changes for the same stream.
        self::assertTrue($coalescer->submit('str_a', ['page' => 1]), 'the 0→1 command enqueues a control');
        self::assertFalse($coalescer->submit('str_a', ['page' => 2]), 'a second command in the window coalesces');
        self::assertFalse($coalescer->submit('str_a', ['page' => 3]), 'a third command in the window coalesces');

        // The single drained control re-queries the FINAL view, not the first.
        self::assertSame(['page' => 3], $coalescer->consume('str_a'), 'latest-write-wins survives the collapse');
    }

    #[Test]
    public function consume_clears_the_mark_so_the_next_burst_re_arms(): void
    {
        $coalescer = ViewChangeCoalescer::create(64);

        self::assertTrue($coalescer->submit('str_a', ['q' => 'acme']));
        self::assertTrue($coalescer->isPending('str_a'));

        self::assertSame(['q' => 'acme'], $coalescer->consume('str_a'));
        self::assertFalse($coalescer->isPending('str_a'), 'consume cleared the pending mark');

        // A fresh command after the drain re-arms (transitions 0→1 again → true).
        self::assertTrue($coalescer->submit('str_a', ['q' => 'globex']), 'the next view change re-arms');
        self::assertSame(['q' => 'globex'], $coalescer->consume('str_a'));
    }

    #[Test]
    public function distinct_streams_coalesce_independently(): void
    {
        $coalescer = ViewChangeCoalescer::create(64);

        self::assertTrue($coalescer->submit('str_a', ['page' => 2]));
        self::assertTrue($coalescer->submit('str_b', ['page' => 9]), 'a different stream has its own 0→1 gate');

        self::assertSame(['page' => 2], $coalescer->consume('str_a'));
        self::assertSame(['page' => 9], $coalescer->consume('str_b'));
    }

    #[Test]
    public function consuming_an_unknown_stream_returns_null(): void
    {
        $coalescer = ViewChangeCoalescer::create(64);

        self::assertNull($coalescer->consume('str_ghost'), 'a never-seen / torn-down stream consumes to null');
    }

    #[Test]
    public function peek_reads_the_latest_params_without_consuming(): void
    {
        $coalescer = ViewChangeCoalescer::create(64);
        $coalescer->submit('str_a', ['sort' => '-submittedAt', 'page' => 4]);

        self::assertSame(['sort' => '-submittedAt', 'page' => 4], $coalescer->peek('str_a'));
        self::assertTrue($coalescer->isPending('str_a'), 'peek did not consume');
    }
}
