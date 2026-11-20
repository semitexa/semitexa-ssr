<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

/**
 * The mutable bookkeeping of one held-open SSE connection.
 *
 * The drain loop tracks four moving parts — whether the connection is finished,
 * when something last went out, when the session was last re-authorized, and
 * which user it belongs to — plus the fixed moment it opened. Held as locals in
 * a 93-statement method they could only be threaded between phases by reference;
 * as an object the loop reads as a sequence of named steps and the two scheduling
 * rules become directly testable.
 *
 * One instance belongs to exactly one connection, living inside that connection's
 * coroutine. It is never shared, so there is no coroutine-safety question here —
 * unlike a `private static`, which is the trap described in the framework's
 * coroutine-shared-static notes.
 */
final class SseHeldOpenState
{
    private bool $closed = false;

    /**
     * When something last reached the client. Seeded from the `connected` frame
     * the caller has already written, so the first heartbeat is measured from
     * that frame rather than from an idle clock.
     */
    private int $lastWriteAt;

    private int $lastAuthTouchAt;

    /**
     * How long a clean session goes without asking Redis anyway. A wake is a
     * publish, and a publish can be lost (a subscriber that is reconnecting, a
     * connection that died without saying so); the frame itself stays in the
     * list, so this is the most a lost wake can cost.
     */
    public const REDIS_SAFETY_POLL_SECONDS = 5;

    /** How often a held stream renews its page's deferred request (its TTL is 120 s). */
    public const KEEP_ALIVE_INTERVAL_SECONDS = 15;

    /** The shortest wait handed out: a deadline that is already due must not turn the loop into a spin. */
    public const MIN_WAIT_SECONDS = 0.2;

    /** Seeded from the connect-time drain the caller ran just before the loop. */
    private int $lastRedisPollAt;

    /** Null until the first renewal, which is due at once: the loop's first pass vouches for its page. */
    private ?int $lastKeepAliveAt = null;

    public function __construct(
        private string $authenticatedUserId,
        private readonly int $startedAt,
    ) {
        $this->lastWriteAt = $startedAt;
        $this->lastAuthTouchAt = $startedAt;
        $this->lastRedisPollAt = $startedAt;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function authenticatedUserId(): string
    {
        return $this->authenticatedUserId;
    }

    public function markWritten(int $now): void
    {
        $this->lastWriteAt = $now;
    }

    /**
     * Has the connection outlived its hard cap?
     *
     * A cap of zero or less disables the check — that is how an operator asks for
     * unbounded connections, and it must never be read as "everything has already
     * expired".
     */
    public function hasOutlivedCap(int $now, int $maxAgeSeconds): bool
    {
        return $maxAgeSeconds > 0 && ($now - $this->startedAt) >= $maxAgeSeconds;
    }

    public function isAuthTouchDue(int $now, int $intervalSeconds): bool
    {
        return ($now - $this->lastAuthTouchAt) >= $intervalSeconds;
    }

    /**
     * Record that the session mapping was refreshed, and adopt whatever identity
     * that refresh resolved to.
     */
    public function markAuthTouched(string $authenticatedUserId, int $now): void
    {
        $this->authenticatedUserId = $authenticatedUserId;
        $this->lastAuthTouchAt = $now;
    }

    /**
     * Seconds since the last outbound write — what the heartbeat rule measures.
     */
    public function idleSeconds(int $now): int
    {
        return $now - $this->lastWriteAt;
    }

    public function lastWriteAt(): int
    {
        return $this->lastWriteAt;
    }

    /**
     * Should this pass ask Redis for the session's frames?
     *
     * Yes when a wake said so (`$dirty`), when nothing is listening for wakes
     * (`$wakeHealthy` false: no Redis, no subscriber, or one that is
     * reconnecting, where every pass polls as it always did), or when the
     * safety interval has passed. An idle stream with a live subscriber costs
     * one LPOP per interval instead of one per tick.
     */
    public function isRedisPollDue(int $now, bool $dirty, bool $wakeHealthy): bool
    {
        return $dirty || !$wakeHealthy || ($now - $this->lastRedisPollAt) >= self::REDIS_SAFETY_POLL_SECONDS;
    }

    public function markRedisPolled(int $now): void
    {
        $this->lastRedisPollAt = $now;
    }

    /** {@see self::isRedisPollDue()}, and when it is, the poll counts as made now: the loop's one call. */
    public function claimRedisPoll(int $now, bool $dirty, bool $wakeHealthy): bool
    {
        $due = $this->isRedisPollDue($now, $dirty, $wakeHealthy);
        if ($due) {
            $this->markRedisPolled($now);
        }

        return $due;
    }

    public function isKeepAliveDue(int $now): bool
    {
        return $this->lastKeepAliveAt === null || ($now - $this->lastKeepAliveAt) >= self::KEEP_ALIVE_INTERVAL_SECONDS;
    }

    public function markKeptAlive(int $now): void
    {
        $this->lastKeepAliveAt = $now;
    }

    /** {@see self::isKeepAliveDue()}, and when it is, the renewal counts as made now. */
    public function claimKeepAlive(int $now): bool
    {
        $due = $this->isKeepAliveDue($now);
        if ($due) {
            $this->markKeptAlive($now);
        }

        return $due;
    }

    /**
     * How long the loop may park when nothing wakes it: until the earliest of
     * the heartbeat, the auth refresh, the age cap and the safety poll. Each of
     * them used to be noticed by a 0.2 s tick; now each is a deadline.
     *
     * Never below {@see self::MIN_WAIT_SECONDS}: the clock here is whole
     * seconds, so a deadline can read as due for the rest of its second.
     */
    public function nextWaitSeconds(int $now, int $heartbeatIntervalSeconds, int $authTouchIntervalSeconds, int $maxAgeSeconds): float
    {
        $deadline = min(
            $this->lastWriteAt + $heartbeatIntervalSeconds,
            $this->lastAuthTouchAt + $authTouchIntervalSeconds,
            $this->lastRedisPollAt + self::REDIS_SAFETY_POLL_SECONDS,
        );
        if ($maxAgeSeconds > 0) {
            $deadline = min($deadline, $this->startedAt + $maxAgeSeconds);
        }

        return max(self::MIN_WAIT_SECONDS, (float) ($deadline - $now));
    }
}
