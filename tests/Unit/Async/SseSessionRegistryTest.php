<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;
use Swoole\Coroutine;

/**
 * Teardown semantics for the per-worker session state.
 *
 * The interesting case is what `close()` must NOT leave behind: review caught it
 * discarding the session and its queue while keeping the buffer, which let a
 * reconnect on the same id drain frames belonging to a connection that was
 * already gone.
 */
final class SseSessionRegistryTest extends TestCase
{
    #[Test]
    public function close_clears_the_buffer_too(): void
    {
        $registry = new SseSessionRegistry();
        $registry->buffer('sess-1', ['type' => 'ui.patch']);

        $registry->close('sess-1');

        self::assertSame([], $registry->buffered('sess-1'), 'a stale buffer must not survive teardown');
    }

    #[Test]
    public function a_reconnect_after_close_starts_with_nothing_buffered(): void
    {
        $registry = new SseSessionRegistry();
        $registry->buffer('sess-1', ['type' => 'ui.patch']);
        $registry->close('sess-1');

        $registry->open('sess-1', new \stdClass(), 'tenant-a', 'blob');

        self::assertSame([], $registry->takeBuffered('sess-1'));
    }

    #[Test]
    public function close_discards_the_session_its_queue_and_its_producer_slot(): void
    {
        $registry = new SseSessionRegistry();
        $registry->open('sess-1', new \stdClass(), '', '');
        $registry->enqueue('sess-1', ['a' => 1]);
        self::assertTrue($registry->tryStartDemoProducer('sess-1'));

        $registry->close('sess-1');

        self::assertFalse($registry->isOpen('sess-1'));
        self::assertFalse($registry->hasQueued('sess-1'));
        self::assertTrue($registry->tryStartDemoProducer('sess-1'), 'the producer slot is free again');
    }

    #[Test]
    public function closing_an_unknown_session_is_a_no_op(): void
    {
        $this->expectNotToPerformAssertions();
        (new SseSessionRegistry())->close('never-existed');
    }

    /**
     * tk-idle-sse-stream-polls-redis, race 1. The waker used to be created by
     * the loop's first wait, so a wake before it had nothing to land on; and a
     * loop that parks for seconds instead of 0.2 s cannot afford to lose one.
     */
    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function a_wake_before_the_first_wait_is_kept(): void
    {
        $registry = new SseSessionRegistry();
        $waited = null;
        $woken = null;

        Coroutine\run(static function () use ($registry, &$waited, &$woken): void {
            $registry->open('sess-1', new \stdClass(), '', '');
            self::assertTrue($registry->takeRedisDirty('sess-1'), 'a session opens dirty: its first pass asks Redis');

            $woken = $registry->wake('sess-1');

            $startedAt = microtime(true);
            $registry->waitForWork('sess-1', 1.0);
            $waited = microtime(true) - $startedAt;
        });

        self::assertTrue($woken);
        self::assertLessThan(0.1, $waited, 'the wait parked although a wake had already arrived');
        self::assertTrue($registry->takeRedisDirty('sess-1'), 'the wake must still be readable after the wait it cut short');
        self::assertFalse($registry->takeRedisDirty('sess-1'), 'taking the flag clears it');
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function a_wake_resumes_a_parked_wait(): void
    {
        $registry = new SseSessionRegistry();
        $waited = null;

        Coroutine\run(static function () use ($registry, &$waited): void {
            $registry->open('sess-1', new \stdClass(), '', '');
            $registry->takeRedisDirty('sess-1');
            Coroutine::create(static function () use ($registry, &$waited): void {
                $startedAt = microtime(true);
                $registry->waitForWork('sess-1', 2.0);
                $waited = microtime(true) - $startedAt;
            });
            Coroutine::sleep(0.05);
            $registry->wake('sess-1');
        });

        self::assertGreaterThanOrEqual(0.04, $waited, 'the wait never parked, so the wake proved nothing');
        self::assertLessThan(0.5, $waited, 'the parked wait slept through the wake');
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function a_failed_pop_shortens_the_next_wait_to_a_tick_without_skipping_it(): void
    {
        $registry = new SseSessionRegistry();
        $waited = null;

        Coroutine\run(static function () use ($registry, &$waited): void {
            $registry->open('sess-1', new \stdClass(), '', '');
            $registry->takeRedisDirty('sess-1');
            $registry->retryRedisSoon('sess-1');

            $startedAt = microtime(true);
            $registry->waitForWork('sess-1', 5.0);
            $waited = microtime(true) - $startedAt;
        });

        self::assertSame(0.2, SseSessionRegistry::REDIS_RETRY_SECONDS);
        self::assertGreaterThanOrEqual(0.15, $waited, 'a retry that skips the wait spins while Redis keeps failing');
        self::assertLessThan(0.5, $waited, 'the retry waited for the caller\'s deadline');
        self::assertTrue($registry->takeRedisDirty('sess-1'), 'the next pass must ask Redis again');
        self::assertFalse($registry->takeRedisDirty('sess-1'));

        $registry->retryRedisSoon('held-by-another-worker');
        self::assertFalse($registry->takeRedisDirty('held-by-another-worker'), 'nothing is kept for a session not held here');
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function a_wake_for_a_frame_outside_redis_resumes_the_wait_without_marking_redis(): void
    {
        $registry = new SseSessionRegistry();
        $waited = null;

        Coroutine\run(static function () use ($registry, &$waited): void {
            $registry->open('sess-1', new \stdClass(), '', '');
            $registry->takeRedisDirty('sess-1');
            Coroutine::create(static function () use ($registry, &$waited): void {
                $startedAt = microtime(true);
                $registry->waitForWork('sess-1', 2.0);
                $waited = microtime(true) - $startedAt;
            });
            Coroutine::sleep(0.05);
            self::assertTrue($registry->wake('sess-1', false));
        });

        self::assertGreaterThanOrEqual(0.04, $waited, 'the wait never parked, so the wake proved nothing');
        self::assertLessThan(0.5, $waited, 'the parked wait slept through the wake');
        self::assertFalse($registry->takeRedisDirty('sess-1'), 'the frame is not in Redis: the loop must not be sent there');
    }

    /**
     * The deliver table is 8192 slots, and a loop scans it only when its
     * session is marked: tk-idle-sse-stream-polls-redis, second cut.
     */
    #[Test]
    public function the_table_mark_is_set_on_open_and_by_a_table_wake_and_taken_only_by_the_holder(): void
    {
        $registry = new SseSessionRegistry();
        $connection = new \stdClass();
        $registry->open('sess-1', $connection, '', '');

        self::assertFalse($registry->takeTableDirty('sess-1', new \stdClass()), 'a replaced connection spent its successor\'s mark');
        self::assertTrue($registry->takeTableDirty('sess-1', $connection), 'a session opens marked: a frame parked before its entry existed woke nobody');
        self::assertFalse($registry->takeTableDirty('sess-1', $connection));

        $registry->wake('sess-1');
        self::assertFalse($registry->takeTableDirty('sess-1', $connection), 'a Redis wake is not a reason to scan the table');

        $registry->wake('sess-1', false);
        self::assertTrue($registry->takeTableDirty('sess-1', $connection));

        $registry->wake('held-by-another-worker', false);
        self::assertFalse($registry->takeTableDirty('held-by-another-worker', $connection), 'a stranger was remembered');

        $registry->wake('sess-1', false);
        $registry->close('sess-1');
        $registry->open('sess-2', $connection, '', '');
        self::assertFalse($registry->takeTableDirty('sess-1', $connection), 'the mark outlived its session');
    }

    #[Test]
    public function wake_all_reaches_every_session_held_here(): void
    {
        $registry = new SseSessionRegistry();
        foreach (['sess-1', 'sess-2', 'sess-3'] as $sessionId) {
            $registry->open($sessionId, new \stdClass(), '', '');
            $registry->takeRedisDirty($sessionId);
        }

        self::assertSame(3, $registry->wakeAll());

        self::assertTrue($registry->takeRedisDirty('sess-1'));
        self::assertTrue($registry->takeRedisDirty('sess-2'));
        self::assertTrue($registry->takeRedisDirty('sess-3'));
    }

    #[Test]
    public function a_wake_for_a_session_held_elsewhere_leaves_nothing_behind(): void
    {
        // Every worker hears every wake. A flag kept for a stranger would never
        // be taken by any loop and would stay for the worker's whole life.
        $registry = new SseSessionRegistry();

        self::assertFalse($registry->wake('held-by-another-worker'));

        $registry->open('held-by-another-worker', new \stdClass(), '', '');
        self::assertTrue($registry->takeRedisDirty('held-by-another-worker'));
        $registry->close('held-by-another-worker');
        self::assertFalse($registry->takeRedisDirty('held-by-another-worker'), 'close drops the flag with the session');
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function a_replaced_connection_does_not_park_on_its_successors_waker(): void
    {
        // A same-worker reconnect keeps the session id and its waker. The old
        // loop, parked there again, would be first in line for the next wake.
        $registry = new SseSessionRegistry();
        $old = new \stdClass();
        $waited = null;

        Coroutine\run(static function () use ($registry, $old, &$waited): void {
            $registry->open('sess-1', $old, '', '');
            $registry->open('sess-1', new \stdClass(), '', '');
            $registry->takeRedisDirty('sess-1');

            $startedAt = microtime(true);
            $registry->waitForWork('sess-1', 1.0, $old);
            $waited = microtime(true) - $startedAt;
        });

        self::assertLessThan(0.1, $waited);
    }
}
