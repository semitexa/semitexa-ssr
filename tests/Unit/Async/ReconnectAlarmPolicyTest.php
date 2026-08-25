<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\ReconnectAlarmLevel;
use Semitexa\Ssr\Application\Service\Async\ReconnectAlarmPolicy;

/**
 * Track R · Gap C-3 — the escalation ladder behind issue semitexa/semitexa-ssr#100.
 *
 * The regression this pins: a drop the loop RECOVERS from must never reach
 * WARNING+, because a `server:restart` produces one such drop per worker and any
 * log-reading alerter (OpsAlertJob digests WARNING+) turns each into an incident.
 * What must reach WARNING+ is the state the message actually wants to report —
 * "this loop cannot get back on".
 *
 * The policy is pure (no clock, no logger, no Swoole), so the whole ladder is
 * provable here even though the blocking loop that consumes it is not testable
 * outside a coroutine.
 */
final class ReconnectAlarmPolicyTest extends TestCase
{
    private const HEALTHY = ReconnectAlarmPolicy::HEALTHY_DWELL_SECONDS + 1.0;

    #[Test]
    public function a_restart_style_drop_that_self_heals_never_alerts(): void
    {
        // The #100 shape: the connection was up for hours, the worker restarted,
        // the loop reconnects on the next turn. One drop, streak of one.
        $policy = new ReconnectAlarmPolicy();

        self::assertNull($policy->recordDwell(3_600.0));
        self::assertSame(ReconnectAlarmLevel::Debug, $policy->recordDrop());
        self::assertSame(1, $policy->consecutiveFailures());

        // ...and the reconnect holds, so the streak clears with nothing announced.
        self::assertNull($policy->recordDwell(self::HEALTHY));
        self::assertSame(0, $policy->consecutiveFailures());
    }

    #[Test]
    public function forty_independent_restart_drops_produce_zero_warnings(): void
    {
        // The literal report: 40 lines across three restarts, every one of them
        // ERROR. Same sequence through the policy must yield 40 x DEBUG.
        $policy = new ReconnectAlarmPolicy();

        for ($i = 0; $i < 40; $i++) {
            $policy->recordDwell(600.0); // the connection had been up
            self::assertSame(
                ReconnectAlarmLevel::Debug,
                $policy->recordDrop(),
                "drop #{$i} escalated, but it self-healed",
            );
            $policy->recordDwell(self::HEALTHY); // and got back on
        }
    }

    #[Test]
    public function a_stuck_loop_warns_once_at_the_threshold(): void
    {
        $policy = new ReconnectAlarmPolicy();

        // Every attempt below the threshold fails to reconnect (zero dwell).
        for ($i = 1; $i < ReconnectAlarmPolicy::ESCALATE_TO_WARNING_AFTER; $i++) {
            self::assertNull($policy->recordDwell(0.0));
            self::assertSame(ReconnectAlarmLevel::Debug, $policy->recordDrop());
        }

        self::assertSame(ReconnectAlarmLevel::Warning, $policy->recordDrop());

        // The next attempts are the SAME fact — they must not re-page.
        self::assertSame(ReconnectAlarmLevel::Debug, $policy->recordDrop());
        self::assertSame(ReconnectAlarmLevel::Debug, $policy->recordDrop());
    }

    #[Test]
    public function a_sustained_outage_errors_at_the_threshold_then_reasserts_on_cadence(): void
    {
        $policy = new ReconnectAlarmPolicy();
        $levels = [];

        for ($i = 0; $i < ReconnectAlarmPolicy::ESCALATE_TO_ERROR_AFTER
            + (2 * ReconnectAlarmPolicy::REASSERT_EVERY_ATTEMPTS); $i++) {
            $policy->recordDwell(0.0);
            $levels[] = $policy->recordDrop();
        }

        $errors = array_keys($levels, ReconnectAlarmLevel::Error, true);

        // Streak 10, 20, 30 → indexes 9, 19, 29. Three lines for a 30-attempt
        // outage, not thirty.
        self::assertSame([9, 19, 29], $errors);
    }

    #[Test]
    public function a_flapping_redis_still_escalates(): void
    {
        // The failure mode a naive "reset on successful connect" would hide:
        // Redis accepts the connection and drops it immediately, forever.
        $policy = new ReconnectAlarmPolicy();

        for ($i = 1; $i < ReconnectAlarmPolicy::ESCALATE_TO_WARNING_AFTER; $i++) {
            $policy->recordDwell(0.2); // connected — but nowhere near the dwell
            $policy->recordDrop();
        }

        $policy->recordDwell(0.2);
        self::assertSame(ReconnectAlarmLevel::Warning, $policy->recordDrop());
    }

    #[Test]
    public function recovery_from_an_escalated_streak_is_announced_once(): void
    {
        $policy = new ReconnectAlarmPolicy();

        for ($i = 0; $i < ReconnectAlarmPolicy::ESCALATE_TO_WARNING_AFTER; $i++) {
            $policy->recordDwell(0.0);
            $policy->recordDrop();
        }

        // The connection finally holds: the incident closes, naming its size.
        self::assertSame(
            ReconnectAlarmPolicy::ESCALATE_TO_WARNING_AFTER,
            $policy->recordDwell(self::HEALTHY),
        );

        // ...and only once — the streak is already clear.
        self::assertNull($policy->recordDwell(self::HEALTHY));
        self::assertSame(0, $policy->consecutiveFailures());
    }

    #[Test]
    public function backoff_grows_with_the_streak_and_is_capped(): void
    {
        $policy = new ReconnectAlarmPolicy();

        self::assertSame(ReconnectAlarmPolicy::BASE_BACKOFF_SECONDS, $policy->backoffSeconds());

        $previous = $policy->backoffSeconds();
        for ($i = 0; $i < 20; $i++) {
            $policy->recordDwell(0.0);
            $policy->recordDrop();
            $current = $policy->backoffSeconds();

            self::assertGreaterThanOrEqual($previous, $current);
            self::assertLessThanOrEqual(ReconnectAlarmPolicy::MAX_BACKOFF_SECONDS, $current);
            $previous = $current;
        }

        self::assertSame(ReconnectAlarmPolicy::MAX_BACKOFF_SECONDS, $policy->backoffSeconds());
    }

    #[Test]
    public function reset_clears_the_streak_for_a_graceful_stop(): void
    {
        $policy = new ReconnectAlarmPolicy();

        $policy->recordDwell(0.0);
        $policy->recordDrop();
        $policy->reset();

        self::assertSame(0, $policy->consecutiveFailures());
        self::assertSame(ReconnectAlarmPolicy::BASE_BACKOFF_SECONDS, $policy->backoffSeconds());
    }
}
