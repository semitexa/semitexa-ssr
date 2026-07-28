<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Predis\Client;
use Semitexa\Core\Environment;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Session\RedisSessionHandler;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Swoole\Http\Request;

/**
 * Who is on which SSE stream — the user↔session index that makes
 * "deliver to this user" and "broadcast to everyone logged in" possible across
 * workers and servers.
 *
 * Three Redis structures, all TTL'd so a crashed worker cannot leave the index
 * claiming connections that no longer exist:
 *  - a set of every authenticated session id;
 *  - a set of session ids per user;
 *  - `session → user`, plus a short-lived `session is alive` heartbeat key.
 *
 * The heartbeat is what makes the index self-healing. Membership in the sets is
 * never trusted on its own: {@see filterActive()} treats a session as real only
 * if it still maps to a user AND its liveness key is present, and it **evicts**
 * anything that fails on the spot. Without that, a worker killed mid-stream
 * would leave phantom members that every later fan-out would try to deliver to.
 *
 * Fail-soft throughout, and asymmetrically so on purpose: a read that cannot
 * reach Redis returns "nobody" rather than throwing, because a fan-out failing
 * to find recipients degrades delivery, while a fan-out that propagates an
 * exception would take down the request that triggered it.
 */
final class SseAuthSessionMap
{
    /** Session-data key the auth package writes the user id under. */
    private const AUTH_SESSION_USER_KEY = '_auth_user_id';

    private const AUTH_SESSION_TTL_SECONDS = 7200;
    private const ACTIVE_SESSION_TTL_SECONDS = 45;

    private const ALL_SESSIONS_KEY = 'semitexa_sse_auth_sessions';
    private const USER_SESSIONS_PREFIX = 'semitexa_sse_auth_user:';
    private const SESSION_USER_PREFIX = 'semitexa_sse_auth_session:';
    private const ACTIVE_SESSION_PREFIX = 'semitexa_sse_active_session:';

    /**
     * How often a live stream refreshes its liveness key. Must stay comfortably
     * below {@see ACTIVE_SESSION_TTL_SECONDS} or a healthy stream would be
     * evicted between touches.
     */
    public const TOUCH_INTERVAL_SECONDS = 30;

    public function __construct(private readonly SseRedisPool $pool)
    {
    }

    public static function userSessionsKey(string $userId): string
    {
        return self::USER_SESSIONS_PREFIX . trim($userId);
    }

    public static function sessionUserKey(string $sessionId): string
    {
        return self::SESSION_USER_PREFIX . trim($sessionId);
    }

    public static function activeSessionKey(string $sessionId): string
    {
        return self::ACTIVE_SESSION_PREFIX . trim($sessionId);
    }

    /**
     * Read the authenticated user id off the request's session cookie.
     *
     * The cookie value is shape-checked before it is used as a session id — an
     * arbitrary string must never reach the session store as a lookup key.
     * Any failure to read the session is treated as "not authenticated".
     */
    public function resolveUserId(Request $request): string
    {
        $cookieName = Environment::getEnvValue('SESSION_COOKIE_NAME') ?? 'semitexa_session';
        $cookie = is_array($request->cookie) ? $request->cookie : [];
        $sessionValue = $cookie[$cookieName] ?? null;
        $sessionId = is_string($sessionValue) ? trim($sessionValue) : '';
        if ($sessionId === '' || !preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            return '';
        }

        try {
            $data = $this->createSessionHandler()->read($sessionId);
        } catch (\Throwable) {
            return '';
        }

        $userId = $data[self::AUTH_SESSION_USER_KEY] ?? null;

        return is_string($userId) ? trim($userId) : '';
    }

    public function isAuthenticated(Request $request): bool
    {
        return $this->resolveUserId($request) !== '';
    }

    public function createSessionHandler(): SessionHandlerInterface
    {
        $pool = $this->pool->get();

        return $pool !== null ? new RedisSessionHandler($pool) : new SwooleTableSessionHandler();
    }

    public function register(string $sessionId, string $userId): void
    {
        $pool = $this->pool->get();
        if ($pool === null) {
            return;
        }

        $sessionId = trim($sessionId);
        $userId = trim($userId);
        if ($sessionId === '' || $userId === '') {
            return;
        }

        try {
            $pool->withConnection(static function ($redis) use ($sessionId, $userId): void {
                /** @var Client $redis */
                $redis->sadd(self::ALL_SESSIONS_KEY, [$sessionId]);
                $redis->sadd(self::userSessionsKey($userId), [$sessionId]);
                $redis->setex(self::sessionUserKey($sessionId), self::AUTH_SESSION_TTL_SECONDS, $userId);
                $redis->expire(self::ALL_SESSIONS_KEY, self::AUTH_SESSION_TTL_SECONDS);
                $redis->expire(self::userSessionsKey($userId), self::AUTH_SESSION_TTL_SECONDS);
            });
        } catch (\Throwable $e) {
            $this->logFailure('Failed to register authenticated SSE session', $e, ['session_id' => $sessionId]);
        }
    }

    public function unregister(string $sessionId): void
    {
        $pool = $this->pool->get();
        if ($pool === null) {
            return;
        }

        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        try {
            $pool->withConnection(static function ($redis) use ($sessionId): void {
                /** @var Client $redis */
                // Resolve the owner first: without it the per-user set would keep
                // a member pointing at a session that no longer exists.
                $userId = trim((string) ($redis->get(self::sessionUserKey($sessionId)) ?? ''));
                if ($userId !== '') {
                    $redis->srem(self::userSessionsKey($userId), $sessionId);
                }
                $redis->srem(self::ALL_SESSIONS_KEY, $sessionId);
                $redis->del(self::sessionUserKey($sessionId));
                $redis->del(self::activeSessionKey($sessionId));
            });
        } catch (\Throwable $e) {
            $this->logFailure('Failed to unregister authenticated SSE session', $e, ['session_id' => $sessionId]);
        }
    }

    /**
     * Refresh the liveness key for a stream that is still connected.
     */
    public function touch(string $sessionId): void
    {
        $pool = $this->pool->get();
        if ($pool === null) {
            return;
        }

        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        try {
            $pool->withConnection(static function ($redis) use ($sessionId): void {
                /** @var Client $redis */
                $redis->setex(self::activeSessionKey($sessionId), self::ACTIVE_SESSION_TTL_SECONDS, '1');
            });
        } catch (\Throwable $e) {
            $this->logFailure('Failed to touch active SSE session', $e, ['session_id' => $sessionId]);
        }
    }

    /**
     * Re-resolve who owns a live stream and correct the index if it changed.
     *
     * Covers the two ways a long-lived stream can drift from its identity: the
     * user logged out (drop the mapping), or a different user is now on the same
     * connection (drop the old mapping before claiming the new one — otherwise
     * the previous user keeps a set member pointing at a stream that is now
     * somebody else's, and a fan-out to them would deliver across accounts).
     *
     * @return string the currently authenticated user id, or `''` if none.
     */
    public function refresh(Request $request, string $sessionId, string $knownUserId): string
    {
        $currentUserId = $this->resolveUserId($request);

        if ($currentUserId === '') {
            if ($knownUserId !== '') {
                $this->unregister($sessionId);
            }

            return '';
        }

        if ($knownUserId !== '' && $currentUserId !== $knownUserId) {
            $this->unregister($sessionId);
        }

        $this->register($sessionId, $currentUserId);

        return $currentUserId;
    }

    /**
     * Every live authenticated session id across all workers.
     *
     * @return list<string>
     */
    public function allSessionIds(): array
    {
        $pool = $this->pool->get();
        if ($pool === null) {
            return [];
        }

        try {
            return $pool->withConnection(static function ($redis): array {
                /** @var Client $redis */
                return self::filterActive($redis, array_values($redis->smembers(self::ALL_SESSIONS_KEY)));
            });
        } catch (\Throwable $e) {
            $this->logFailure('Failed to get all authenticated session IDs', $e);

            return [];
        }
    }

    /**
     * Every live session id belonging to one user.
     *
     * @return list<string>
     */
    public function sessionIdsForUser(string $userId): array
    {
        $pool = $this->pool->get();
        if ($pool === null) {
            return [];
        }

        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }

        try {
            return $pool->withConnection(static function ($redis) use ($userId): array {
                /** @var Client $redis */
                return self::filterActive($redis, array_values($redis->smembers(self::userSessionsKey($userId))), $userId);
            });
        } catch (\Throwable $e) {
            $this->logFailure('Failed to get authenticated user session IDs', $e, ['user_id' => $userId]);

            return [];
        }
    }

    /**
     * Keep only genuinely live sessions, evicting the rest as we go.
     *
     * A member survives only if it still maps to a user AND its liveness key is
     * present AND — when filtering one user's set — that mapping still names the
     * expected user. Anything else is a phantom left by a crashed worker or a
     * re-authenticated connection, and is deleted from every structure here
     * rather than merely skipped, so the index converges instead of accumulating
     * garbage that every later fan-out re-scans.
     *
     * @param list<mixed> $sessionIds
     * @return list<string>
     */
    private static function filterActive(mixed $redis, array $sessionIds, ?string $expectedUserId = null): array
    {
        $active = [];
        if (!$redis instanceof Client) {
            return $active;
        }

        foreach ($sessionIds as $rawSessionId) {
            if (!is_scalar($rawSessionId) && !$rawSessionId instanceof \Stringable) {
                continue;
            }

            $sessionId = trim((string) $rawSessionId);
            if ($sessionId === '') {
                continue;
            }

            $mappedUserId = trim((string) ($redis->get(self::sessionUserKey($sessionId)) ?? ''));
            $isAlive = (string) ($redis->get(self::activeSessionKey($sessionId)) ?? '') === '1';

            if (
                $mappedUserId === ''
                || !$isAlive
                || ($expectedUserId !== null && $mappedUserId !== $expectedUserId)
            ) {
                $redis->srem(self::ALL_SESSIONS_KEY, $sessionId);
                if ($expectedUserId !== null) {
                    $redis->srem(self::userSessionsKey($expectedUserId), $sessionId);
                } elseif ($mappedUserId !== '') {
                    $redis->srem(self::userSessionsKey($mappedUserId), $sessionId);
                }
                $redis->del(self::sessionUserKey($sessionId));
                $redis->del(self::activeSessionKey($sessionId));
                continue;
            }

            $active[] = $sessionId;
        }

        return $active;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logFailure(string $message, \Throwable $e, array $context = []): void
    {
        StaticLoggerBridge::error('ssr', $message, $context + [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
