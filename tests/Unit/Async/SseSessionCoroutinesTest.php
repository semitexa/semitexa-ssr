<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseSessionCoroutines;
use Swoole\Coroutine;

/**
 * Direct tests for per-session coroutine tracking.
 *
 * The behaviour that had no coverage before the extraction is the bookkeeping
 * itself: that a coroutine deregisters on the way out (including when it throws),
 * that cancelling a session never cancels the coroutine doing the cancelling, and
 * that a real failure is not mistaken for a cancellation and swallowed.
 */
final class SseSessionCoroutinesTest extends TestCase
{
    #[Test]
    public function without_a_coroutine_runtime_the_callback_runs_inline(): void
    {
        // CLI and unit tests take this path: callers still get their side effect,
        // and `false` tells them nothing was spawned.
        $tracker = new SseSessionCoroutines();
        $ran = false;

        $result = $tracker->create(static function () use (&$ran): void {
            $ran = true;
        }, 'sess-1');

        self::assertTrue($ran, 'the side effect still happens');
        self::assertFalse($result);
        self::assertFalse($tracker->hasAny('sess-1'), 'an inline run registers nothing to cancel');
    }

    #[Test]
    public function a_finished_coroutine_deregisters_itself(): void
    {
        $this->requireSwoole();
        $tracker = new SseSessionCoroutines();

        Coroutine\run(static function () use ($tracker): void {
            $done = new Coroutine\Channel(1);
            $tracker->create(static function () use ($done): void {
                $done->push(true);
            }, 'sess-1');
            $done->pop(1.0);
            Coroutine::sleep(0.02);
        });

        self::assertSame([], $tracker->idsFor('sess-1'));
    }

    #[Test]
    public function a_cancelled_coroutine_still_deregisters(): void
    {
        // The deregistration lives in a `finally` precisely so an exception
        // cannot leave a phantom id that cancellation would later try to kill.
        //
        // The throw here is cancellation-SHAPED on purpose. A non-cancellation
        // throw is deliberately rethrown (see the class note), and an uncaught
        // throw inside a Swoole coroutine is fatal to the process — so that path
        // cannot be observed from a unit test without taking the suite down with
        // it. The swallow/rethrow decision itself is covered by the
        // isCancellation() cases below.
        $this->requireSwoole();
        $tracker = new SseSessionCoroutines();

        Coroutine\run(static function () use ($tracker): void {
            $tracker->create(static function (): void {
                throw new \RuntimeException('coroutine cancelled');
            }, 'sess-1');
            Coroutine::sleep(0.05);
        });

        self::assertSame([], $tracker->idsFor('sess-1'));
    }

    #[Test]
    public function cancelling_a_session_stops_its_coroutine(): void
    {
        $this->requireSwoole();
        $tracker = new SseSessionCoroutines();
        $started = null;
        $finished = null;

        Coroutine\run(static function () use ($tracker, &$started, &$finished): void {
            $startCh = new Coroutine\Channel(1);
            $endCh = new Coroutine\Channel(1);

            $tracker->create(static function () use ($startCh, $endCh): void {
                $startCh->push(true);
                try {
                    while (true) {
                        Coroutine::sleep(0.01);
                    }
                } finally {
                    $endCh->push(true);
                }
            }, 'sess-1');

            $started = $startCh->pop(1.0);
            $tracker->cancelFor('sess-1');
            $finished = $endCh->pop(1.0);
        });

        self::assertTrue($started);
        self::assertTrue($finished, 'a tight sleep loop must actually be stopped, not merely signalled');
    }

    #[Test]
    public function cancelling_never_kills_the_coroutine_doing_the_cancelling(): void
    {
        // Teardown normally runs inside one of the session's own coroutines —
        // cancelling itself would abort the cleanup in progress.
        $this->requireSwoole();
        $tracker = new SseSessionCoroutines();
        $survived = false;

        Coroutine\run(static function () use ($tracker, &$survived): void {
            $ready = new Coroutine\Channel(1);

            $tracker->create(static function () use ($tracker, $ready, &$survived): void {
                $ready->push(true);
                // Cancel the whole session from inside one of its coroutines.
                $tracker->cancelFor('sess-1');
                Coroutine::sleep(0.01);
                $survived = true;
            }, 'sess-1');

            $ready->pop(1.0);
            Coroutine::sleep(0.1);
        });

        self::assertTrue($survived, 'the cancelling coroutine must run to completion');
    }

    #[Test]
    public function forget_drops_the_set_without_cancelling(): void
    {
        $this->requireSwoole();
        $tracker = new SseSessionCoroutines();

        Coroutine\run(static function () use ($tracker): void {
            $ready = new Coroutine\Channel(1);
            $tracker->create(static function () use ($ready): void {
                $ready->push(true);
                Coroutine::sleep(0.2);
            }, 'sess-1');
            $ready->pop(1.0);

            self::assertTrue($tracker->hasAny('sess-1'));
            $tracker->forget('sess-1');
            self::assertFalse($tracker->hasAny('sess-1'));
        });
    }

    #[Test]
    public function sessions_are_tracked_independently(): void
    {
        $this->requireSwoole();
        $tracker = new SseSessionCoroutines();

        Coroutine\run(static function () use ($tracker): void {
            $a = new Coroutine\Channel(1);
            $b = new Coroutine\Channel(1);
            $tracker->create(static function () use ($a): void {
                $a->push(true);
                Coroutine::sleep(0.2);
            }, 'sess-a');
            $tracker->create(static function () use ($b): void {
                $b->push(true);
                Coroutine::sleep(0.2);
            }, 'sess-b');
            $a->pop(1.0);
            $b->pop(1.0);

            $tracker->cancelFor('sess-a');
            Coroutine::sleep(0.05);

            self::assertSame([], $tracker->idsFor('sess-a'));
            self::assertNotSame([], $tracker->idsFor('sess-b'), 'one session teardown must not touch another');
        });
    }

    #[Test]
    public function cancelling_an_unknown_session_is_a_no_op(): void
    {
        $this->expectNotToPerformAssertions();
        (new SseSessionCoroutines())->cancelFor('never-existed');
        (new SseSessionCoroutines())->cancelFor('   ');
    }

    #[Test]
    #[DataProvider('cancellationShapes')]
    public function cancellation_is_recognised_by_class_or_message(\Throwable $e, bool $expected): void
    {
        self::assertSame($expected, SseSessionCoroutines::isCancellation($e));
    }

    /**
     * @return iterable<string, array{0: \Throwable, 1: bool}>
     */
    public static function cancellationShapes(): iterable
    {
        // The check is loose on purpose: Swoole has not been consistent about
        // the exception type across versions, so both the class name and the
        // message are consulted.
        yield 'message says cancelled' => [new \RuntimeException('Coroutine is cancelled'), true];
        yield 'message says cancel' => [new \RuntimeException('cancel requested'), true];
        yield 'mixed case' => [new \RuntimeException('CANCELLED'), true];
        yield 'an ordinary failure' => [new \RuntimeException('database is down'), false];
        yield 'an empty message' => [new \RuntimeException(''), false];
    }

    private function requireSwoole(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }
    }
}
