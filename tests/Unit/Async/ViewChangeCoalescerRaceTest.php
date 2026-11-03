<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\ViewChangeCoalescer;
use Swoole\Atomic;

/**
 * Four workers take view-change commands for one stream while a fifth drains
 * the controls, as in the server. A consume() between a submit()'s incr and
 * its set used to leave a pending row with no control in flight: every later
 * command coalesced into nothing. Real processes, since the race is between
 * them; before the lock, 247 of 300 such bursts ended so.
 */
final class ViewChangeCoalescerRaceTest extends TestCase
{
    private const RUNS = 50;

    private const WORKERS = 4;

    private const COMMANDS = 1000;

    #[Test]
    public function no_command_burst_leaves_a_stream_that_ignores_the_next_command(): void
    {
        $stuck = 0;
        for ($run = 0; $run < self::RUNS; $run++) {
            $stuck += $this->burstLeavesItStuck() ? 1 : 0;
        }

        self::assertSame(0, $stuck, "{$stuck} of " . self::RUNS . ' bursts left a pending row with no control in flight');
    }

    private function burstLeavesItStuck(): bool
    {
        $coalescer = ViewChangeCoalescer::create(64);
        $inFlight = new Atomic(0);
        $finished = new Atomic(0);
        $children = [];
        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $children[] = $this->fork(static function () use ($coalescer, $inFlight, $finished, $worker): void {
                for ($i = 0; $i < self::COMMANDS; $i++) {
                    if ($coalescer->submit('stream', ['q' => "{$worker}-{$i}"])) {
                        $inFlight->add(1);
                    }
                }
                $finished->add(1);
            });
        }
        $children[] = $this->fork(static function () use ($coalescer, $inFlight, $finished): void {
            while ($finished->get() < self::WORKERS || $inFlight->get() > 0) {
                if ($inFlight->get() > 0) {
                    $inFlight->sub(1);
                    $coalescer->consume('stream');
                }
            }
        });
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Nothing is in flight now: a command must be able to enqueue again.
        return $coalescer->isPending('stream') && !$coalescer->submit('stream', ['q' => 'after']);
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
}
