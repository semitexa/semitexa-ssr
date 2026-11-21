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

    /** How often a held stream renews its page's deferred request (its TTL is 120 s). */
    public const KEEP_ALIVE_INTERVAL_SECONDS = 15;

    /** The shortest wait handed out: a deadline that is already due must not turn the loop into a spin. */
    public const MIN_WAIT_SECONDS = 0.2;

    /**
     * A deliver-table frame could not be written and stays in its row. Nothing
     * will wake the loop for it again (its signal has been read), so the loop
     * reads the table once more a tick from now: not at once, which against a
     * socket that keeps refusing would be a spin.
     */
    private bool $tableRetry = false;

    /** Null until the first renewal, which is due at once: the loop's first pass vouches for its page. */
    private ?int $lastKeepAliveAt = null;

    public function __construct(
        private string $authenticatedUserId,
        private readonly int $startedAt,
    ) {
        $this->lastWriteAt = $startedAt;
        $this->lastAuthTouchAt = $startedAt;
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
     * Yes when a wake said so (`$dirty`), or when nothing is listening for
     * wakes (`$wakeHealthy` false: no Redis, no subscriber, or one that is
     * reconnecting, where every pass polls as it always did). Never on a clock
     * of the stream's own: a lost wake is the worker's to catch, once for all
     * its streams ({@see SsePendingSweep}), and an idle stream asks nothing.
     */
    public function isRedisPollDue(bool $dirty, bool $wakeHealthy): bool
    {
        return $dirty || !$wakeHealthy;
    }

    /**
     * The outcome of writing one deliver-table frame: out, it counts as a
     * write; refused, its row is to be read again ({@see self::takeTableRetry()}).
     *
     * @return bool `$written`, so the caller consumes the row only when it is out
     */
    public function recordTableWrite(bool $written, int $now): bool
    {
        if ($written) {
            $this->lastWriteAt = $now;
        } else {
            $this->tableRetry = true;
        }

        return $written;
    }

    /** Read the retry mark and clear it: the pass that takes it scans the deliver table. */
    public function takeTableRetry(): bool
    {
        $retry = $this->tableRetry;
        $this->tableRetry = false;

        return $retry;
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
     * the heartbeat, the auth refresh and the age cap. Each of them used to be
     * noticed by a 0.2 s tick; now each is a deadline. The deferred request's
     * renewal has none of its own: it rides the heartbeat's pass (20 s against
     * a TTL of 120 s).
     *
     * Never below {@see self::MIN_WAIT_SECONDS}: the clock here is whole
     * seconds, so a deadline can read as due for the rest of its second. And
     * exactly that long while a table row waits to be written again.
     */
    public function nextWaitSeconds(int $now, int $heartbeatIntervalSeconds, int $authTouchIntervalSeconds, int $maxAgeSeconds): float
    {
        if ($this->tableRetry) {
            return self::MIN_WAIT_SECONDS;
        }
        $deadline = min(
            $this->lastWriteAt + $heartbeatIntervalSeconds,
            $this->lastAuthTouchAt + $authTouchIntervalSeconds,
        );
        if ($maxAgeSeconds > 0) {
            $deadline = min($deadline, $this->startedAt + $maxAgeSeconds);
        }

        return max(self::MIN_WAIT_SECONDS, (float) ($deadline - $now));
    }
}
