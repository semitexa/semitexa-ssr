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
}
