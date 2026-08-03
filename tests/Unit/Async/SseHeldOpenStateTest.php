<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseHeldOpenState;

/**
 * Direct tests for {@see SseHeldOpenState}, carved out of the 93-statement
 * held-open drain loop by ep-slay-sse-god-class-2 (tk-sse2-held-open-loop).
 *
 * The two scheduling rules here — when a connection has outlived its cap and
 * when the session is due for re-authorization — previously existed only as
 * inline arithmetic on loop-local variables, reachable in a test solely by
 * driving a real Swoole coroutine with a live socket. Both decide whether a
 * client keeps receiving data, so they are worth pinning on their own.
 */
final class SseHeldOpenStateTest extends TestCase
{
    private const T0 = 1_000_000;

    #[Test]
    public function a_fresh_connection_is_open_and_counts_as_just_written(): void
    {
        // The caller has already sent the `connected` frame, so the heartbeat
        // clock must start from now — not from zero, which would fire a
        // keepalive immediately on every new connection.
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertFalse($state->isClosed());
        self::assertSame(0, $state->idleSeconds(self::T0));
        self::assertSame(self::T0, $state->lastWriteAt());
        self::assertSame('user-1', $state->authenticatedUserId());
    }

    #[Test]
    public function closing_is_one_way(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);

        $state->close();
        $state->markWritten(self::T0 + 5);

        self::assertTrue($state->isClosed(), 'a later write must not resurrect a closed connection');
    }

    // ---- connection age cap ------------------------------------------------

    #[Test]
    public function a_connection_outlives_its_cap_exactly_at_the_boundary(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertFalse($state->hasOutlivedCap(self::T0 + 59, 60));
        self::assertTrue($state->hasOutlivedCap(self::T0 + 60, 60), 'the cap is inclusive');
        self::assertTrue($state->hasOutlivedCap(self::T0 + 61, 60));
    }

    #[Test]
    public function a_non_positive_cap_means_unbounded_not_already_expired(): void
    {
        // The trap: `now - startedAt >= 0` is true immediately, so a naive check
        // would kill every connection the instant it opened. Zero is how an
        // operator asks for no limit.
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertFalse($state->hasOutlivedCap(self::T0 + 100_000, 0));
        self::assertFalse($state->hasOutlivedCap(self::T0 + 100_000, -1));
    }

    // ---- periodic re-authorization -----------------------------------------

    #[Test]
    public function the_auth_touch_is_due_at_the_interval_boundary(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertFalse($state->isAuthTouchDue(self::T0 + 29, 30));
        self::assertTrue($state->isAuthTouchDue(self::T0 + 30, 30));
    }

    #[Test]
    public function touching_auth_restarts_the_interval_and_adopts_the_resolved_identity(): void
    {
        // A refresh can resolve to a different user (or to none, when the
        // session was revoked). The loop then keeps serving under whatever came
        // back, so the state must adopt it rather than keep the stale id.
        $state = new SseHeldOpenState('user-1', self::T0);

        $state->markAuthTouched('user-2', self::T0 + 30);

        self::assertSame('user-2', $state->authenticatedUserId());
        self::assertFalse($state->isAuthTouchDue(self::T0 + 59, 30), 'the clock restarted');
        self::assertTrue($state->isAuthTouchDue(self::T0 + 60, 30));
    }

    #[Test]
    public function a_revoked_session_is_adopted_as_an_empty_identity(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);

        $state->markAuthTouched('', self::T0 + 30);

        self::assertSame('', $state->authenticatedUserId());
    }

    // ---- idle tracking -----------------------------------------------------

    #[Test]
    public function every_outbound_write_resets_the_idle_clock(): void
    {
        // This is what stops a busy connection from emitting pointless
        // keepalives between real frames.
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertSame(10, $state->idleSeconds(self::T0 + 10));

        $state->markWritten(self::T0 + 10);

        self::assertSame(0, $state->idleSeconds(self::T0 + 10));
        self::assertSame(5, $state->idleSeconds(self::T0 + 15));
    }

    #[Test]
    public function the_auth_clock_and_the_write_clock_are_independent(): void
    {
        // A stream that is busy sending data still has to re-authorize on
        // schedule; conversely a re-auth is not a write and must not suppress
        // the heartbeat.
        $state = new SseHeldOpenState('user-1', self::T0);

        $state->markWritten(self::T0 + 25);
        self::assertTrue($state->isAuthTouchDue(self::T0 + 30, 30), 'writing does not defer re-authorization');

        $state->markAuthTouched('user-1', self::T0 + 30);
        self::assertSame(5, $state->idleSeconds(self::T0 + 30), 'a re-auth does not count as a write');
    }
}
