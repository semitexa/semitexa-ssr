<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseDemoStreamProducer;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;

/**
 * Direct tests for {@see SseDemoStreamProducer}, lifted out of
 * AsyncResourceSseServer by ep-slay-sse-god-class-2 (tk-sse2-demo-producer).
 *
 * As 59 statements inlined in the SSE server it had no tests of its own — the
 * only coverage was two auth-gate cases asserting a *guest* could not reach it,
 * which say nothing about what it does once admitted.
 */
final class SseDemoStreamProducerTest extends TestCase
{
    // ---- admission --------------------------------------------------------

    #[Test]
    public function a_stream_name_other_than_showcase_is_ignored(): void
    {
        $sessions = new SseSessionRegistry();
        $spawned = 0;

        $this->producer($sessions, spawned: $spawned)->start('sess-1', 'something-else');

        self::assertSame(0, $spawned);
        self::assertTrue(
            $sessions->tryStartDemoProducer('sess-1'),
            'the producer slot must be left untouched for an unrecognised stream',
        );
    }

    #[Test]
    public function a_session_that_already_has_a_producer_is_refused(): void
    {
        // A reconnect must not leave two producers ticking into one session.
        //
        // Exercised by occupying the slot first rather than by calling start()
        // twice: outside Swoole every start() takes the no-coroutine branch and
        // hands the slot straight back, so a double-start would prove nothing
        // here. Claiming the slot up front is the same precondition a live
        // producer creates, and it is coroutine-independent.
        $sessions = new SseSessionRegistry();
        $spawned = 0;
        self::assertTrue($sessions->tryStartDemoProducer('sess-1'), 'precondition: slot claimed');

        $this->producer($sessions, spawned: $spawned)->start('sess-1', SseDemoStreamProducer::SHOWCASE);

        self::assertSame(0, $spawned, 'no second producer may be spawned');
        self::assertFalse(
            $sessions->tryStartDemoProducer('sess-1'),
            'the refused start must not release the incumbent producer\'s slot',
        );
    }

    #[Test]
    public function the_producer_slot_is_released_when_there_is_no_coroutine_to_run_in(): void
    {
        // Outside Swoole nothing can hold the loop open. The slot must come back,
        // or the session is permanently marked as having a live producer and a
        // later legitimate start is refused forever.
        if (self::inCoroutine()) {
            self::markTestSkipped('This case only exists outside a coroutine context.');
        }

        $sessions = new SseSessionRegistry();
        $spawned = 0;

        $this->producer($sessions, spawned: $spawned)->start('sess-1', SseDemoStreamProducer::SHOWCASE);

        self::assertSame(0, $spawned);
        self::assertTrue(
            $sessions->tryStartDemoProducer('sess-1'),
            'the slot must be released again, not leaked',
        );
    }

    // ---- minute-boundary alignment ----------------------------------------

    /**
     * @return array<string, array{float, float}>
     */
    public static function boundaries(): array
    {
        return [
            'just past the minute' => [100.0, 60.0 - fmod(100.0, 60.0)],
            'mid minute' => [130.0, 50.0],
            'one second before' => [179.0, 1.0],
        ];
    }

    #[Test]
    #[DataProvider('boundaries')]
    public function the_wait_lands_on_the_next_wall_clock_minute(float $now, float $expected): void
    {
        self::assertEqualsWithDelta($expected, SseDemoStreamProducer::secondsToNextBoundary($now), 0.0001);
    }

    #[Test]
    public function a_wait_never_lands_on_the_minute_that_just_fired(): void
    {
        // Sitting a hair before a boundary, a naive computation returns ~0 and the
        // loop ticks twice for the same minute. The epsilon must push it to the
        // NEXT minute instead.
        $justBefore = 179.99;

        $wait = SseDemoStreamProducer::secondsToNextBoundary($justBefore);

        self::assertGreaterThan(
            1.0,
            $wait,
            'a near-zero wait must roll forward a full period, not fire twice on one minute',
        );
        self::assertEqualsWithDelta(60.01, $wait, 0.0001);
    }

    #[Test]
    public function every_wait_is_a_sane_positive_period(): void
    {
        // Sweep a whole minute at fine resolution: the sleep must always be
        // positive (a negative or zero sleep spins the coroutine) and never
        // exceed two periods.
        for ($offset = 0.0; $offset < 60.0; $offset += 0.13) {
            $wait = SseDemoStreamProducer::secondsToNextBoundary(1000.0 + $offset);

            self::assertGreaterThan(0.0, $wait, "non-positive wait at offset {$offset}");
            self::assertLessThanOrEqual(120.0, $wait, "runaway wait at offset {$offset}");
        }
    }

    private function producer(SseSessionRegistry $sessions, int &$spawned): SseDemoStreamProducer
    {
        return new SseDemoStreamProducer(
            $sessions,
            static function (string $session, array $data): void {
                // frames are not exercised here; the loop needs a coroutine
            },
            static function (callable $task, string $session) use (&$spawned): void {
                $spawned++;
            },
        );
    }

    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0;
    }
}
