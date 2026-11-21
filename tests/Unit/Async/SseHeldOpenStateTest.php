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

        self::assertFalse($state->isRedisPollDue(dirty: false, wakeHealthy: true));
        // However long ago it last asked: the answer no longer has a clock in it.
        self::assertSame(1.0, $state->nextWaitSeconds(self::T0 + 19, 20, 30, 0));
    }

    #[Test]
    public function a_wake_makes_the_redis_poll_due_at_once(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertTrue($state->isRedisPollDue(dirty: true, wakeHealthy: true));
    }

    #[Test]
    public function without_a_healthy_wake_subscriber_every_pass_polls(): void
    {
        // No Redis subscription means nothing will ever say "dirty": the loop
        // keeps the per-tick poll it always had.
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertTrue($state->isRedisPollDue(dirty: false, wakeHealthy: false));
    }

    #[Test]
    public function an_idle_stream_has_no_redis_deadline_of_its_own(): void
    {
        // verify:accept-test-change replaces the_safety_poll_comes_due_five_seconds_after_the_last_one: the per-stream safety poll it pinned is removed (tk-idle-sse-stream-polls-redis); its guarantee, a lost wake costs at most 5 s, is now SsePendingSweepTest's and HeldOpenLoopIdleRedisTest::a_frame_whose_wake_was_lost_is_delivered_by_the_workers_next_sweep
        // The 5 s per-stream safety poll is gone: a lost wake is caught by the
        // worker's one sweep of the pending set, so a clean session parks until
        // its heartbeat however long ago it last asked Redis.
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertSame(20.0, $state->nextWaitSeconds(self::T0, 20, 30, 0));
        self::assertSame(14.0, $state->nextWaitSeconds(self::T0 + 6, 20, 30, 0));
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
    public function claiming_a_due_renewal_marks_it_made(): void
    {
        // verify:accept-test-change was claiming_a_due_poll_or_renewal_marks_it_made: its four claimRedisPoll() assertions went with the method, which only moved the safety poll's clock; the renewal half is kept and extended
        // The loop's one call: asked twice in the same second, only the first is due.
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertTrue($state->claimKeepAlive(self::T0));
        self::assertFalse($state->claimKeepAlive(self::T0 + 14));
        self::assertTrue($state->claimKeepAlive(self::T0 + 15));
        // An idle stream passes once per heartbeat (20 s): due every time, well inside the 120 s TTL.
        self::assertTrue($state->claimKeepAlive(self::T0 + 35));
    }

    #[Test]
    public function the_wait_runs_to_the_nearest_deadline(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);

        // Fresh: heartbeat at +20, auth at +30, no age cap.
        self::assertSame(20.0, $state->nextWaitSeconds(self::T0, 20, 30, 0));
        self::assertSame(2.0, $state->nextWaitSeconds(self::T0 + 18, 20, 30, 0));

        // Then the auth refresh.
        $state->markWritten(self::T0 + 28);
        self::assertSame(2.0, $state->nextWaitSeconds(self::T0 + 28, 20, 30, 0));
        $state->markAuthTouched('user-1', self::T0 + 30);
        self::assertSame(18.0, $state->nextWaitSeconds(self::T0 + 30, 20, 30, 0));
        // And the heartbeat again: an idle stream alternates between the two and nothing else.
        self::assertSame(8.0, $state->nextWaitSeconds(self::T0 + 40, 20, 30, 0));
    }

    #[Test]
    public function a_table_row_left_unwritten_is_read_again_a_tick_later_and_once(): void
    {
        // The deliver table is scanned only when something says so; a failed
        // write says so for the next pass, one tick away and not in a spin.
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertFalse($state->takeTableRetry());
        self::assertSame(20.0, $state->nextWaitSeconds(self::T0, 20, 30, 0));

        self::assertTrue($state->recordTableWrite(true, self::T0 + 3), 'a frame that is out is reported out');
        self::assertSame(self::T0 + 3, $state->lastWriteAt(), 'a table frame that reached the client is a write: the heartbeat is measured from it');
        self::assertFalse($state->takeTableRetry());

        self::assertFalse($state->recordTableWrite(false, self::T0 + 4));
        self::assertSame(self::T0 + 3, $state->lastWriteAt());

        self::assertSame(0.2, $state->nextWaitSeconds(self::T0, 20, 30, 0), 'the row waited for the heartbeat');
        self::assertTrue($state->takeTableRetry());
        self::assertFalse($state->takeTableRetry());
        self::assertSame(20.0, $state->nextWaitSeconds(self::T0 + 3, 20, 30, 0));
    }

    #[Test]
    public function an_idle_streams_renewals_are_never_further_apart_than_a_third_of_the_ttl(): void
    {
        // The held loop is the only thing renewing the page's deferred request
        // (TTL 120 s) since the deferred live loop stopped ticking. Worst case:
        // a pass just short of the 15 s the renewal waits for, then nothing
        // until the heartbeat, 20 s after that pass's write.
        $state = new SseHeldOpenState('user-1', self::T0);
        self::assertTrue($state->claimKeepAlive(self::T0));

        $state->markWritten(self::T0 + 14);
        self::assertFalse($state->claimKeepAlive(self::T0 + 14));
        $wait = $state->nextWaitSeconds(self::T0 + 14, 20, 30, 0);

        self::assertSame(16.0, $wait, 'the auth refresh at +30 comes first; without it the heartbeat at +34 would');
        self::assertTrue($state->claimKeepAlive(self::T0 + 14 + (int) $wait), 'the pass an idle stream wakes for did not renew');
        self::assertLessThanOrEqual(40, 14 + (int) $wait, 'two renewals further apart than a third of the 120 s TTL');
    }

    #[Test]
    public function the_age_cap_is_a_wait_deadline_too(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);
        $state->markWritten(self::T0 + 57);
        $state->markAuthTouched('user-1', self::T0 + 57);

        self::assertSame(3.0, $state->nextWaitSeconds(self::T0 + 57, 20, 30, 60), 'a capped connection must wake to be closed on time');
        self::assertSame(20.0, $state->nextWaitSeconds(self::T0 + 57, 20, 30, 0), 'a cap of zero is no deadline');
    }

    #[Test]
    public function a_deadline_already_due_never_turns_the_wait_into_a_spin(): void
    {
        $state = new SseHeldOpenState('user-1', self::T0);

        self::assertSame(0.2, $state->nextWaitSeconds(self::T0 + 20, 20, 30, 0));
        self::assertSame(0.2, $state->nextWaitSeconds(self::T0 + 500, 20, 30, 0));
    }
}
