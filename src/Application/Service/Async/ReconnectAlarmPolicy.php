<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

/**
 * Track R · Gap C-3 — the escalation policy for the subscribe loop's reconnects.
 *
 * WHY THIS EXISTS (issue semitexa/semitexa-ssr#100): the Gap C self-heal logged
 * ERROR on EVERY dropped connection, including the ordinary ones it was built to
 * survive. Three `bin/semitexa server:restart` runs produced 40 ERROR lines — one
 * per worker per restart — and every log-reading alerter (OpsAlertJob digests
 * WARNING+) turned each into an incident. An operator action is not an outage.
 *
 * THE RULE: severity tracks the STREAK, not the event.
 *  - a drop that reconnects (streak below {@see self::ESCALATE_TO_WARNING_AFTER})
 *    is DEBUG — kept for forensics, invisible to alerting;
 *  - crossing the WARNING threshold emits ONE warning (the crossing, not every
 *    subsequent attempt — a stuck loop must not re-page every second);
 *  - crossing the ERROR threshold emits ONE error, then re-asserts every
 *    {@see self::REASSERT_EVERY_ATTEMPTS} attempts so a long outage stays visible
 *    without becoming a flood;
 *  - a connection that stayed up for {@see self::HEALTHY_DWELL_SECONDS} clears the
 *    streak, and — if the streak had escalated — yields a recovery notice.
 *
 * FLAP GUARD: the streak is cleared by DWELL, not by "the connect call returned".
 * A Redis that accepts a connection and drops it 200 ms later would otherwise
 * reset the counter on every turn and never escalate — the exact failure mode
 * the alert is for.
 *
 * BACKOFF: the same streak drives {@see self::backoffSeconds()}, so a hard-down
 * Redis backs off exponentially (1s → 30s cap) instead of spinning at 1/s for
 * the whole outage.
 *
 * PURE BY DESIGN: no logging, no clock, no Swoole. Elapsed time is passed IN, so
 * the whole escalation ladder is unit-testable outside a coroutine — which the
 * blocking loop that uses it is not.
 */
final class ReconnectAlarmPolicy
{
    /** Consecutive failed reconnects before the first operator-visible warning. */
    public const ESCALATE_TO_WARNING_AFTER = 3;

    /** Consecutive failed reconnects before this counts as a real outage. */
    public const ESCALATE_TO_ERROR_AFTER = 10;

    /** Once in ERROR, re-assert only every Nth attempt (never once per second). */
    public const REASSERT_EVERY_ATTEMPTS = 10;

    /**
     * How long a subscription must hold before the connection counts as HEALTHY
     * and the failure streak is cleared. Below this, the turn is a flap.
     */
    public const HEALTHY_DWELL_SECONDS = 5.0;

    /** First backoff step; doubles per consecutive failure up to the cap. */
    public const BASE_BACKOFF_SECONDS = 1.0;

    public const MAX_BACKOFF_SECONDS = 30.0;

    private int $consecutiveFailures = 0;

    /**
     * A connection turn ended after living `$uptimeSeconds`. Clears the streak
     * when the connection actually held (see FLAP GUARD above).
     *
     * @return int|null the streak it recovered from when that streak had already
     *                  escalated to WARNING+ (so the caller can emit a "back on"
     *                  notice closing the incident), otherwise null — a self-healed
     *                  drop announces nothing.
     */
    public function recordDwell(float $uptimeSeconds): ?int
    {
        if ($uptimeSeconds < self::HEALTHY_DWELL_SECONDS) {
            return null; // flap: keep the streak, keep escalating.
        }

        $recoveredFrom = $this->consecutiveFailures;
        $this->consecutiveFailures = 0;

        return $recoveredFrom >= self::ESCALATE_TO_WARNING_AFTER ? $recoveredFrom : null;
    }

    /**
     * Record one failed connection turn and resolve the level it should be
     * reported at. Call AFTER {@see self::recordDwell()} for the same turn.
     */
    public function recordDrop(): ReconnectAlarmLevel
    {
        $streak = ++$this->consecutiveFailures;

        if ($streak < self::ESCALATE_TO_WARNING_AFTER) {
            return ReconnectAlarmLevel::Debug;
        }

        if ($streak < self::ESCALATE_TO_ERROR_AFTER) {
            // Only the crossing is operator-visible; the attempts in between are
            // the same fact repeated.
            return $streak === self::ESCALATE_TO_WARNING_AFTER
                ? ReconnectAlarmLevel::Warning
                : ReconnectAlarmLevel::Debug;
        }

        $sinceEscalation = $streak - self::ESCALATE_TO_ERROR_AFTER;

        return $sinceEscalation % self::REASSERT_EVERY_ATTEMPTS === 0
            ? ReconnectAlarmLevel::Error
            : ReconnectAlarmLevel::Debug;
    }

    /**
     * Seconds to wait before the next reconnect attempt: exponential in the
     * current streak, capped, so a hard-down Redis cannot spin a tight loop and a
     * recovered Redis is still picked up within a second.
     */
    public function backoffSeconds(): float
    {
        if ($this->consecutiveFailures <= 1) {
            return self::BASE_BACKOFF_SECONDS;
        }

        $seconds = self::BASE_BACKOFF_SECONDS * (2 ** ($this->consecutiveFailures - 1));

        return min($seconds, self::MAX_BACKOFF_SECONDS);
    }

    /** The current consecutive-failure streak (0 when the loop is healthy). */
    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    /** Clear the streak outright — used on a graceful stop / teardown. */
    public function reset(): void
    {
        $this->consecutiveFailures = 0;
    }
}
