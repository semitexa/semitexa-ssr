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

    #[Test]
    public function a_clean_session_with_a_live_wake_subscriber_does_not_poll_redis(): void
    {
        // tk-idle-sse-stream-polls-redis: this is the idle stream. Nothing woke
        // it and something would have, so it has no reason to ask.
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertFalse($state->isRedisPollDue(self::T0, dirty: false, wakeHealthy: true));
        self::assertFalse($state->isRedisPollDue(self::T0 + 4, dirty: false, wakeHealthy: true));
    }

    #[Test]
    public function a_wake_makes_the_redis_poll_due_at_once(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertTrue($state->isRedisPollDue(self::T0, dirty: true, wakeHealthy: true));
    }

    #[Test]
    public function without_a_healthy_wake_subscriber_every_pass_polls(): void
    {
        // No Redis subscription means nothing will ever say "dirty": the loop
        // keeps the per-tick poll it always had.
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertTrue($state->isRedisPollDue(self::T0, dirty: false, wakeHealthy: false));
    }

    #[Test]
    public function the_safety_poll_comes_due_five_seconds_after_the_last_one(): void
    {
        // A wake is a publish and can be lost; the frame stays in the list, and
        // this is the most a lost wake costs.
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertSame(5, SseHeldOpenState::REDIS_SAFETY_POLL_SECONDS);
        self::assertFalse($state->isRedisPollDue(self::T0 + 4, dirty: false, wakeHealthy: true));
        self::assertTrue($state->isRedisPollDue(self::T0 + 5, dirty: false, wakeHealthy: true));

        $state->markRedisPolled(self::T0 + 5);

        self::assertFalse($state->isRedisPollDue(self::T0 + 9, dirty: false, wakeHealthy: true));
        self::assertTrue($state->isRedisPollDue(self::T0 + 10, dirty: false, wakeHealthy: true));
    }

    #[Test]
    public function the_deferred_request_is_renewed_on_the_first_pass_and_then_every_fifteen_seconds(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertTrue($state->isKeepAliveDue(self::T0), 'the first pass vouches for its page at once');

        $state->markKeptAlive(self::T0);

        self::assertFalse($state->isKeepAliveDue(self::T0 + 14));
        self::assertTrue($state->isKeepAliveDue(self::T0 + 15));
    }

    #[Test]
    public function claiming_a_due_poll_or_renewal_marks_it_made(): void
    {
        // The loop's one call: asked twice in the same second, only the first is due.
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertFalse($state->claimRedisPoll(self::T0 + 4, dirty: false, wakeHealthy: true));
        self::assertTrue($state->claimRedisPoll(self::T0 + 5, dirty: false, wakeHealthy: true));
        self::assertFalse($state->claimRedisPoll(self::T0 + 5, dirty: false, wakeHealthy: true));
        self::assertTrue($state->claimRedisPoll(self::T0 + 5, dirty: true, wakeHealthy: true), 'a wake is due whenever it comes');

        self::assertTrue($state->claimKeepAlive(self::T0));
        self::assertFalse($state->claimKeepAlive(self::T0 + 14));
        self::assertTrue($state->claimKeepAlive(self::T0 + 15));
    }

    #[Test]
    public function the_wait_runs_to_the_nearest_deadline(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);

        // Fresh: heartbeat at +20, auth at +30, safety poll at +5, no age cap.
        self::assertSame(5.0, $state->nextWaitSeconds(self::T0, 20, 30, 0));
        self::assertSame(3.0, $state->nextWaitSeconds(self::T0 + 2, 20, 30, 0));

        // The heartbeat is nearest once the poll has just run.
        $state->markRedisPolled(self::T0 + 18);
        self::assertSame(2.0, $state->nextWaitSeconds(self::T0 + 18, 20, 30, 0));

        // Then the auth refresh.
        $state->markWritten(self::T0 + 28);
        $state->markRedisPolled(self::T0 + 28);
        self::assertSame(2.0, $state->nextWaitSeconds(self::T0 + 28, 20, 30, 0));
        $state->markAuthTouched('user-1', self::T0 + 30);
        self::assertSame(3.0, $state->nextWaitSeconds(self::T0 + 30, 20, 30, 0));
    }

    #[Test]
    public function the_age_cap_is_a_wait_deadline_too(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);
        $state->markRedisPolled(self::T0 + 57);
        $state->markWritten(self::T0 + 57);
        $state->markAuthTouched('user-1', self::T0 + 57);

        self::assertSame(3.0, $state->nextWaitSeconds(self::T0 + 57, 20, 30, 60), 'a capped connection must wake to be closed on time');
        self::assertSame(5.0, $state->nextWaitSeconds(self::T0 + 57, 20, 30, 0), 'a cap of zero is no deadline');
    }

    #[Test]
    public function a_deadline_already_due_never_turns_the_wait_into_a_spin(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertSame(0.2, $state->nextWaitSeconds(self::T0 + 5, 20, 30, 0));
        self::assertSame(0.2, $state->nextWaitSeconds(self::T0 + 500, 20, 30, 0));
    }
}
