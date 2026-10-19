<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Predis\Client;
use Semitexa\Core\Environment;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Session\RedisSessionHandler;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Semitexa\Core\Support\Row;
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
 *  - `session → user`, plus a short-lived `session is alive` heartbeat key;
 *  - `session → connection`: which connection holds it. A reconnect reuses the
 *    session id, and the previous connection — on another node its loop can
 *    still be refreshing — must not erase or take back the new one's
 *    registration: opening a stream claims the session, while a refresh and an
 *    unregister write only while the token is still their own, atomically.
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
    private const SESSION_CONNECTION_PREFIX = 'semitexa_sse_auth_connection:';
    /**
     * What an unregister leaves as the owner: a value no connection token equals,
     * kept longer than a stream may live (max age 600 s), so a stream superseded
     * and closed meanwhile is refused instead of re-registering. A claim — the
     * next stream to open — overwrites it.
     */
    private const CLOSED_OWNER = 'closed';
    private const CLOSED_OWNER_TTL_SECONDS = 900;

    /**
     * KEYS: connection, all sessions, the user's sessions, session→user. ARGV:
     * the caller's token ('' = no owner), '1' to claim the session whoever held
     * it, the session id, the user id, the TTL.
     */
    private const REGISTER_SCRIPT = <<<'LUA'
        if ARGV[1] ~= '' then
            local owner = redis.call('GET', KEYS[1])
            if ARGV[2] ~= '1' and owner and owner ~= ARGV[1] then
                return 0
            end
            redis.call('SETEX', KEYS[1], ARGV[5], ARGV[1])
        end
        local previous = redis.call('GET', KEYS[4])
        if previous and previous ~= '' and previous ~= ARGV[4] then
            redis.call('SREM', ARGV[6] .. previous, ARGV[3])
        end
        redis.call('SADD', KEYS[2], ARGV[3])
        redis.call('SADD', KEYS[3], ARGV[3])
        redis.call('SETEX', KEYS[4], ARGV[5], ARGV[4])
        redis.call('EXPIRE', KEYS[2], ARGV[5])
        redis.call('EXPIRE', KEYS[3], ARGV[5])
        return 1
        LUA;

    /**
     * KEYS: connection, session→user, all sessions, alive. ARGV: the caller's
     * token ('' = no comparison), the per-user set prefix, the session id.
     */
    private const UNREGISTER_SCRIPT = <<<'LUA'
        local owner = redis.call('GET', KEYS[1])
        if ARGV[1] ~= '' and owner and owner ~= ARGV[1] then
            return 0
        end
        local user = redis.call('GET', KEYS[2])
        if user and user ~= '' then
            redis.call('SREM', ARGV[2] .. user, ARGV[3])
        end
        redis.call('SREM', KEYS[3], ARGV[3])
        redis.call('DEL', KEYS[2], KEYS[4])
        redis.call('SETEX', KEYS[1], ARGV[4], ARGV[5])
        return 1
        LUA;

    /** @var \WeakMap<object, string>|null each connection's token */
    private ?\WeakMap $tokens = null;

    /**
     * How often a live stream refreshes its liveness key. Must stay comfortably
     * below {@see ACTIVE_SESSION_TTL_SECONDS} or a healthy stream would be
     * evicted between touches.
     */
    public const TOUCH_INTERVAL_SECONDS = 30;

    /**
     * @param null|\Closure(): SessionHandlerInterface $sessionHandlerFactory how session data is
     *        read; null = Redis, or the Swoole table when Redis is absent
     */
    public function __construct(
        private readonly SseRedisPool $pool,
        private readonly ?\Closure $sessionHandlerFactory = null,
    )
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

    public static function sessionConnectionKey(string $sessionId): string
    {
        return self::SESSION_CONNECTION_PREFIX . trim($sessionId);
    }

    private function tokenFor(?object $connection): string
    {
        if ($connection === null) {
            return '';
        }
        $this->tokens ??= new \WeakMap();

        return $this->tokens[$connection] ??= bin2hex(random_bytes(8));
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
        return $this->readUserId($request) ?? '';
    }

    /** @return string|null the user id, '' for a guest, null when the session could not be read */
    private function readUserId(Request $request): ?string
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
            return null;
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
        if ($this->sessionHandlerFactory !== null) {
            return ($this->sessionHandlerFactory)();
        }
        $pool = $this->pool->get();

        return $pool !== null ? new RedisSessionHandler($pool) : new SwooleTableSessionHandler();
    }

    /**
     * @param object|null $connection the stream's response; null registers without an owner
     * @param bool $claim true when the stream opens (it takes the session over); false
     *                    on a refresh, which writes only while the session is still its own
     * @return bool whether the registration was written
     */
    public function register(string $sessionId, string $userId, ?object $connection = null, bool $claim = true): bool
    {
        $pool = $this->pool->get();
        if ($pool === null) {
            return false;
        }

        $sessionId = trim($sessionId);
        $userId = trim($userId);
        if ($sessionId === '' || $userId === '') {
            return false;
        }

        $token = $this->tokenFor($connection);
        try {
            // One script, so an unregister cannot land between the owner check and the writes.
            // A non-claim write is refused by any other owner — including the tombstone
            // an unregister leaves, so a stream whose session a reconnect claimed and
            // closed cannot write itself back. A missing owner key (Redis lost it, or the
            // register at open failed) still heals on the next refresh.
            // A session moving to another user leaves the previous user's set here too.
            return $pool->withConnection(static fn ($redis): bool => Row::asInt($redis->eval(
                self::REGISTER_SCRIPT,
                4,
                self::sessionConnectionKey($sessionId),
                self::ALL_SESSIONS_KEY,
                self::userSessionsKey($userId),
                self::sessionUserKey($sessionId),
                $token,
                $claim ? '1' : '0',
                $sessionId,
                $userId,
                (string) self::AUTH_SESSION_TTL_SECONDS,
                self::USER_SESSIONS_PREFIX,
            )) === 1);
        } catch (\Throwable $e) {
            $this->logFailure('Failed to register authenticated SSE session', $e, ['session_id' => $sessionId]);

            return false;
        }
    }

    /**
     * @param object|null $connection the stream's response; null removes the session whoever registered it
     * @return bool whether the session was removed (false: another connection holds it, or Redis failed)
     */
    public function unregister(string $sessionId, ?object $connection = null): bool
    {
        $pool = $this->pool->get();
        if ($pool === null) {
            return false;
        }

        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return false;
        }

        $token = $this->tokenFor($connection);
        try {
            // One script: the owner check and the deletes cannot be split by a
            // reconnect's register. The user is resolved inside it, so the
            // per-user set does not keep a member for a session that is gone.
            return $pool->withConnection(static fn ($redis): bool => Row::asInt($redis->eval(
                self::UNREGISTER_SCRIPT,
                4,
                self::sessionConnectionKey($sessionId),
                self::sessionUserKey($sessionId),
                self::ALL_SESSIONS_KEY,
                self::activeSessionKey($sessionId),
                $token,
                self::USER_SESSIONS_PREFIX,
                $sessionId,
                (string) self::CLOSED_OWNER_TTL_SECONDS,
                self::CLOSED_OWNER,
            )) === 1);
        } catch (\Throwable $e) {
            $this->logFailure('Failed to unregister authenticated SSE session', $e, ['session_id' => $sessionId]);

            return false;
        }
    }

    /**
     * A stream opened on this session id: a signed-in one claims the session, a
     * guest one drops whatever mapping an earlier connection of the tab left.
     */
    public function opened(string $sessionId, string $userId, ?object $connection): void
    {
        $userId === '' ? $this->forgetForGuest($sessionId) : $this->register($sessionId, $userId, $connection);
    }

    /**
     * A guest stream opened on this session id: whatever auth mapping it still
     * has belongs to an earlier connection of the same tab, whose user is no
     * longer signed in here. Removed whoever registered it — that connection's
     * teardown leaves it once it is superseded, and the guest's own teardown is
     * not the owner — or the old user's fan-out reaches this stream until the
     * mapping expires.
     */
    public function forgetForGuest(string $sessionId): void
    {
        $this->unregister($sessionId);
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
     * connection (the register moves the session off the old user in the same
     * script — otherwise the previous user keeps a set member pointing at a
     * stream that is now somebody else's, and a fan-out to them would deliver
     * across accounts).
     *
     * @return string the currently authenticated user id, or `''` if none.
     */
    public function refresh(Request $request, string $sessionId, string $knownUserId, ?object $connection = null): string
    {
        $currentUserId = $this->readUserId($request);
        // A read that failed says nothing about who is signed in: leave the
        // mapping as it is. Read as a logout, it tombstoned a signed-in stream
        // out of its user's fan-out for the rest of its life.
        if ($currentUserId === null) {
            return $knownUserId;
        }

        if ($currentUserId === '') {
            if ($knownUserId !== '') {
                $this->unregister($sessionId, $connection);
            }

            return '';
        }

        // Same user or another one on this connection: one write, which moves the
        // session off the previous user and refuses when this connection no longer
        // owns it. Unregistering first left a gap a reconnect could claim and close
        // in, after which this stream wrote itself back as live.
        $this->register($sessionId, $currentUserId, $connection, claim: false);

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
