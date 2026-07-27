<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

/**
 * How long a stream lives and when it closes — the second extraction of
 * `ep-slay-sse-god-class` out of {@see AsyncResourceSseServer}.
 *
 * Four decisions, all pure: which transport mode an admitted request gets,
 * whether the held-open loop owes a keepalive, whether a delivered frame ends
 * the stream, and the hard connection-age cap. None of them touch a socket,
 * a session or Redis — they take scalars and return scalars, which is why they
 * came out in the low-risk tier.
 *
 * Two neighbours deliberately stayed behind on the facade:
 *  - `shouldServeAsSse()` reads the worker-boot `$sseServedPaths` set, so it
 *    belongs with route/transport wiring rather than with stream lifetime;
 *  - `canUsePersistentDeferredSse()` needs `hasAuthenticatedSession()`, which
 *    does not exist as a seam until `tk-sse-auth-session-map` lands.
 * Dragging either one in now would have meant inventing a dependency that the
 * next tier is about to create properly.
 */
final class SseTransportModePolicy
{
    /**
     * Short-lived KISS transport: flush queued frames + close. Default for
     * public/guest pages that opt into the canonical subscriber channel —
     * bounds worker coroutine / FD pressure by not holding the connection
     * open after the queue is drained.
     */
    public const MODE_DRAIN = 'drain';

    /**
     * Long-lived KISS transport: enter the held-open loop and stay open until
     * max-age / done / disconnect. Reserved for authenticated dashboards,
     * admin/internal tools, monitoring, terminal-like interfaces, and other
     * explicitly trusted deployments.
     */
    public const MODE_LIVE = 'live';

    /**
     * No explicit mode supplied — preserve the pre-existing long-lived loop for
     * legacy callers and deferred SSR streams.
     */
    public const MODE_LEGACY = 'legacy';

    private const DEFAULT_MAX_CONNECTION_AGE_SECONDS = 600;

    /**
     * Keepalive cadence for persistent SSE loops. After this many seconds with
     * no outbound frame the loop writes an inert SSE comment (":\n\n"), so an
     * idle-but-healthy stream is not silently dropped by an intermediary
     * (nginx's default 60s `proxy_read_timeout` being the canonical offender)
     * and a dead socket is detected promptly on the next write. Comfortably
     * under that 60s window. Drain streams short-circuit before the loop, so
     * the heartbeat only ever applies to live / legacy / persistent-deferred
     * streams.
     */
    public const HEARTBEAT_INTERVAL_SECONDS = 20;

    /**
     * Resolve the requested KISS transport mode against the admit context.
     *
     * Called once per admit, AFTER {@see SseRequestGuard::resolveAuthorizationError()}
     * has already approved the request.
     *
     * Mode policy (only callers admitted by the auth gate reach here):
     *
     *   | rawMode | deferred | authed | anonAllow | bearer | result        |
     *   | ------- | -------- | ------ | --------- | ------ | ------------- |
     *   | drain   | *        | *      | *         | *      | drain         |
     *   | live    | *        | *      | *         | *      | live          |
     *   | ''      | yes      | *      | *         | *      | legacy        |
     *   | ''      | no       | yes    | *         | *      | legacy        |
     *   | ''      | no       | no     | yes       | *      | legacy        |
     *   | ''      | no       | no     | no        | yes    | drain ← key   |
     *   | other   | *        | *      | *         | *      | null (400)    |
     *
     * The anonymous-bearer + missing-mode → drain rule prevents a guest page
     * that forgets the mode marker from silently opening a long-lived stream.
     * Explicit unknown values are rejected so a typo never silently degrades to
     * legacy behaviour.
     *
     * @return self::MODE_DRAIN|self::MODE_LIVE|self::MODE_LEGACY|null
     *         `null` ⇒ explicit unknown mode → caller emits 400.
     */
    public function resolveMode(
        string $rawMode,
        bool $authenticated,
        bool $anonymousAllowed,
        bool $safeBearerSessionId,
        string $deferredRequestId,
    ): ?string {
        if ($rawMode === self::MODE_DRAIN) {
            return self::MODE_DRAIN;
        }
        if ($rawMode === self::MODE_LIVE) {
            return self::MODE_LIVE;
        }
        if ($rawMode === '') {
            if ($deferredRequestId !== '' || $authenticated || $anonymousAllowed) {
                return self::MODE_LEGACY;
            }
            if ($safeBearerSessionId) {
                return self::MODE_DRAIN;
            }
            // Defensive: the auth gate would have rejected this combination
            // before mode resolution. Treat conservatively as legacy.
            return self::MODE_LEGACY;
        }

        return null;
    }

    /**
     * The resolved hard connection-age cap (`SSE_MAX_CONNECTION_AGE_SECONDS`;
     * `0` disables the loop's own cap).
     *
     * The held-open loop reads this to force-close + reap a stream at the cap,
     * and the crashed-worker orphan sweeper
     * ({@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\ReapStaleSubscriptionsListener})
     * derives its cap+grace staleness threshold from the SAME value — one
     * source of truth for the cap. Read per call rather than cached at
     * construction, exactly as the facade did.
     */
    public function maxConnectionAgeSeconds(): int
    {
        return SseEnv::int('SSE_MAX_CONNECTION_AGE_SECONDS', self::DEFAULT_MAX_CONNECTION_AGE_SECONDS);
    }

    /**
     * Whether the held-open loop owes a keepalive comment. A non-positive
     * interval disables heartbeats entirely.
     */
    public function shouldSendHeartbeat(int $now, int $lastWriteAt, int $intervalSeconds): bool
    {
        if ($intervalSeconds <= 0) {
            return false;
        }

        return ($now - $lastWriteAt) >= $intervalSeconds;
    }

    /**
     * Whether a delivered frame terminates the stream.
     *
     * Only a `done` frame can close. Within that, an explicit `close: true`
     * always closes, and otherwise the stream closes unless the frame marks
     * itself `live: true` — so a `done` frame that says nothing about liveness
     * closes, which is what keeps one-shot deferred drains from lingering.
     *
     * @param array<string, mixed> $data
     */
    public function shouldCloseAfterPayload(array $data): bool
    {
        if (($data['type'] ?? null) !== 'done') {
            return false;
        }

        if (($data['close'] ?? false) === true) {
            return true;
        }

        return ($data['live'] ?? false) !== true;
    }
}
