<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Async;

use Semitexa\Core\Environment;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Core\Session\RedisSessionHandler;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Predis\Client;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class AsyncResourceSseServer
{
    private const AUTH_SESSION_USER_KEY = '_auth_user_id';
    private const AUTH_SESSION_TTL_SECONDS = 7200;
    private const AUTH_SESSION_TOUCH_INTERVAL_SECONDS = 30;
    private const ACTIVE_SESSION_TTL_SECONDS = 45;
    private const REDIS_AUTH_USER_SESSIONS_PREFIX = 'semitexa_sse_auth_user:';
    private const REDIS_AUTH_SESSION_USER_PREFIX = 'semitexa_sse_auth_session:';
    private const REDIS_AUTH_ALL_SESSIONS_KEY = 'semitexa_sse_auth_sessions';
    private const REDIS_ACTIVE_SESSION_PREFIX = 'semitexa_sse_active_session:';
    private const REDIS_SESSION_QUEUE_PREFIX = 'semitexa_sse_queue:';
    private const REDIS_SESSION_QUEUE_TTL_SECONDS = 7200;

    private static array $sessions = [];

    /** @var array<string, list<array>> Pending messages per session when no connection yet */
    private static array $buffer = [];

    /** @var array<string, list<array>> In-memory queue per session for the loop to send */
    private static array $queues = [];

    /** @var array<string, bool> */
    private static array $demoProducers = [];

    private static ?\Swoole\Http\Server $httpServer = null;

    /** @var \Swoole\Table|null session_id -> worker_id (for cross-worker deliver) */
    private static ?\Swoole\Table $sessionWorkerTable = null;

    /** @var \Swoole\Table|null deliver queue: unique_key -> session_id, worker_id, payload */
    private static ?\Swoole\Table $deliverTable = null;

    /** @var \Swoole\Table|null pending messages when client not connected yet: key -> session_id, payload */
    private static ?\Swoole\Table $pendingDeliverTable = null;
    private static ?RedisConnectionPool $redisPool = null;

    public static function handle(Request $request, Response $response): bool
    {
        $server = is_array($request->server) ? $request->server : [];
        $path = $server['path_info'] ?? '';
        if ($path === '') {
            $uri = $server['request_uri'] ?? '/';
            $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        }

        if ($path === '/__semitexa_sse' || $path === '/sse') {
            self::handleSse($request, $response);
            return true;
        }

        if ($path === '/__semitexa_kiss') {
            self::handleSse($request, $response);
            return true;
        }

        return false;
    }

    private static function handleSse(Request $request, Response $response): void
    {
        if (!self::isSameOriginRequest($request)) {
            $response->status(403);
            $response->end();
            return;
        }

        $get = is_array($request->get) ? $request->get : [];
        $header = is_array($request->header) ? $request->header : [];
        $sessionId = trim((string) (($get['session_id'] ?? null) ?: uniqid('sse_', true)));
        $demoStream = '';
        if (isset($get['demo_stream'])) {
            $demoStream = trim((string) $get['demo_stream']);
        }

        if ($demoStream !== '') {
            if (!self::hasAuthenticatedSession($request)) {
                $response->status(401);
                $response->header('Content-Type', 'application/json');
                $response->end(json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Authorization is required for this SSE demo stream.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return;
            }
        }

        $response->status(200);
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');

        self::$sessions[$sessionId] = [
            'response' => $response,
            'connected_at' => time(),
        ];

        $authenticatedUserId = self::resolveAuthenticatedUserId($request);
        if ($authenticatedUserId !== '') {
            self::registerAuthenticatedSession($sessionId, $authenticatedUserId);
        }
        self::touchActiveSession($sessionId);

        if (self::$sessionWorkerTable !== null && self::$httpServer !== null) {
            $workerId = self::getCurrentWorkerId();
            $key = self::sessionTableKey($sessionId);
            self::$sessionWorkerTable->set($key, ['worker_id' => $workerId]);
        }

        if (!isset(self::$queues[$sessionId])) {
            self::$queues[$sessionId] = [];
        }

        // Flush local buffer for this session only
        $bufferCount = 0;
        if (isset(self::$buffer[$sessionId])) {
            foreach (self::$buffer[$sessionId] as $data) {
                self::writeSse($response, $data);
                $bufferCount++;
            }
            unset(self::$buffer[$sessionId]);
        }

        // Flush pending table for this session only
        $pendingCount = 0;
        if (self::$pendingDeliverTable !== null) {
            $toDel = [];
            foreach (self::$pendingDeliverTable as $pendingKey => $row) {
                if (trim((string) $row['session_id']) === $sessionId) {
                    $data = json_decode((string) $row['payload'], true);
                    if (is_array($data)) {
                        self::writeSse($response, $data);
                        $pendingCount++;
                    }
                    $toDel[] = $pendingKey;
                }
            }
            foreach ($toDel as $k) {
                self::$pendingDeliverTable->del($k);
            }
        }

        if (self::drainRedisQueueForSession($sessionId, $response)) {
            if (self::$sessionWorkerTable !== null) {
                self::$sessionWorkerTable->del(self::sessionTableKey($sessionId));
            }
            self::unregisterAuthenticatedSession($sessionId);
            unset(self::$sessions[$sessionId], self::$queues[$sessionId]);
            $response->end();
            return;
        }

        // Send initial event so the client receives something immediately (fixes "Connecting..." stuck
        // and ensures response is flushed; some proxies don't send headers until first byte).
        self::writeSse($response, ['event' => 'connected', 'connected' => true]);

        $enableDemoStream = filter_var((string) (\getenv('APP_DEBUG') ?: ''), FILTER_VALIDATE_BOOLEAN);
        if ($demoStream !== '' && $enableDemoStream) {
            self::startDemoStreamProducer($sessionId, $demoStream);
        }

        // Trigger deferred block streaming if deferred_request_id is present
        $deferredRequestId = trim((string) ($get['deferred_request_id'] ?? ''));
        $lastEventId = $header['last-event-id'] ?? null;
        if ($deferredRequestId !== '') {
            $bindToken = self::getSsrBindToken($request);
            if (!\Semitexa\Ssr\Isomorphic\DeferredRequestRegistry::matchesBindToken($deferredRequestId, $bindToken)) {
                self::writeSse($response, [
                    'type' => 'done',
                    'live' => false,
                    'close' => true,
                    'reconnect' => false,
                ]);
                $response->end();
                self::removeSessionWorkerMapping($sessionId);
                self::unregisterAuthenticatedSession($sessionId);
                unset(self::$sessions[$sessionId], self::$queues[$sessionId]);
                return;
            }
            self::triggerDeferredBlocks(
                $sessionId,
                $deferredRequestId,
                $lastEventId,
                self::canUsePersistentDeferredSse($request),
            );
        }

        $closed = false;
        $lastAuthTouchAt = time();
        while (!$closed && isset(self::$sessions[$sessionId])) {
            if ((time() - $lastAuthTouchAt) >= self::AUTH_SESSION_TOUCH_INTERVAL_SECONDS) {
                $authenticatedUserId = self::refreshAuthenticatedSessionMapping($request, $sessionId, $authenticatedUserId);
                self::touchActiveSession($sessionId);
                $lastAuthTouchAt = time();
            }

            while (isset(self::$queues[$sessionId]) && self::$queues[$sessionId] !== []) {
                $data = array_shift(self::$queues[$sessionId]);
                if (!self::writeSse($response, $data)) {
                    $closed = true;
                    break;
                }
                if (self::shouldCloseAfterPayload($data)) {
                    $closed = true;
                    break;
                }
            }

            if (!$closed && self::$deliverTable !== null && self::$httpServer !== null) {
                $currentWorkerId = self::getCurrentWorkerId();
                $toDel = [];
                $deliverCount = 0;
                foreach (self::$deliverTable as $deliverKey => $row) {
                    if ((int) $row['worker_id'] === $currentWorkerId && trim((string) $row['session_id']) === $sessionId) {
                        $data = json_decode((string) $row['payload'], true);
                        if (is_array($data) && self::writeSse($response, $data)) {
                            $toDel[] = $deliverKey;
                            $deliverCount++;
                            if (self::shouldCloseAfterPayload($data)) {
                                $closed = true;
                                break;
                            }
                        }
                    }
                }
                foreach ($toDel as $k) {
                    self::$deliverTable->del($k);
                }
            }

            if (!$closed && self::drainRedisQueueForSession($sessionId, $response)) {
                $closed = true;
            }

            if ($closed) {
                break;
            }

            if (function_exists('connection_aborted') && connection_aborted()) {
                break;
            }

            \Swoole\Coroutine::sleep(0.2);
        }

        if (self::$sessionWorkerTable !== null) {
            self::$sessionWorkerTable->del(self::sessionTableKey($sessionId));
        }
        self::unregisterAuthenticatedSession($sessionId);
        unset(self::$sessions[$sessionId]);
        unset(self::$queues[$sessionId]);
        unset(self::$demoProducers[$sessionId]);
        $response->end();
    }

    private static function triggerDeferredBlocks(
        string $sessionId,
        string $deferredRequestId,
        ?string $lastEventId,
        bool $allowPersistentDeferredSse,
    ): void
    {
        $registry = \Semitexa\Ssr\Isomorphic\DeferredRequestRegistry::consume($deferredRequestId);

        $debugEnabled = filter_var((string) (\getenv('APP_DEBUG') ?: (\getenv('DEBUG') ?: '0')), \FILTER_VALIDATE_BOOLEAN);
        $debugLog = static function (string $msg, array $data = []) use ($debugEnabled): void {
            if (!$debugEnabled) {
                return;
            }

            $entry = json_encode(['ssr_sse' => $msg] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($entry !== false) {
                error_log($entry);
            }
        };

        if ($registry === null) {
            $debugLog('registry_null', ['deferred_request_id' => $deferredRequestId]);
            self::deliver($sessionId, [
                'type' => 'done',
                'live' => false,
                'close' => true,
                'reconnect' => false,
            ]);
            return;
        }

        $locale = $registry['locale'];

        $debugLog('registry_found', [
            'deferred_request_id' => $deferredRequestId,
            'page_handle' => $registry['page_handle'],
            'slots' => $registry['slots'],
            'locale' => $locale,
        ]);

        // Use coroutine to resolve deferred blocks concurrently
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::create(static function () use ($sessionId, $registry, $lastEventId, $deferredRequestId, $debugLog, $allowPersistentDeferredSse, $locale): void {
                try {
                    $container = ContainerFactory::get();
                    $orchestrator = $container->get(\Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator::class);
                    $debugLog('orchestrator_resolved', ['session_id' => $sessionId]);
                    $orchestrator->streamDeferredBlocks(
                        sessionId: $sessionId,
                        pageHandle: $registry['page_handle'],
                        pageContext: $registry['page_context'],
                        lastEventId: $lastEventId,
                        deferredRequestId: $deferredRequestId,
                        locale: $locale !== '' ? $locale : null,
                        startLiveLoop: $allowPersistentDeferredSse,
                    );
                } catch (\Throwable $e) {
                    $debugLog('streaming_failed', ['error' => $e->getMessage(), 'trace' => substr($e->getTraceAsString(), 0, 500)]);
                    error_log("[Semitexa SSR] Deferred block streaming failed: {$e->getMessage()}");
                    self::deliver($sessionId, [
                        'type' => 'done',
                        'live' => false,
                        'close' => true,
                        'reconnect' => false,
                    ]);
                }
            });
        } else {
            try {
                $container = ContainerFactory::get();
                $orchestrator = $container->get(\Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator::class);
                $debugLog('orchestrator_resolved_sync', ['session_id' => $sessionId]);
                $orchestrator->streamDeferredBlocks(
                    sessionId: $sessionId,
                    pageHandle: $registry['page_handle'],
                    pageContext: $registry['page_context'],
                    lastEventId: $lastEventId,
                    deferredRequestId: $deferredRequestId,
                    locale: $locale !== '' ? $locale : null,
                    startLiveLoop: $allowPersistentDeferredSse,
                );
            } catch (\Throwable $e) {
                $debugLog('streaming_failed_sync', ['error' => $e->getMessage()]);
                error_log("[Semitexa SSR] Deferred block streaming failed (sync): {$e->getMessage()}");
                self::deliver($sessionId, [
                    'type' => 'done',
                    'live' => false,
                    'close' => true,
                    'reconnect' => false,
                ]);
            }
        }
    }

    private static function drainRedisQueueForSession(string $sessionId, Response $response): bool
    {
        $pool = self::getRedisPool();
        if ($pool === null) {
            return false;
        }

        while (true) {
            try {
                $raw = $pool->withConnection(static function ($redis) use ($sessionId): ?string {
                    /** @var Client $redis */
                    $value = $redis->lpop(self::redisSessionQueueKey($sessionId));
                    return is_string($value) && $value !== '' ? $value : null;
                });
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[Semitexa SSR] Redis SSE dequeue failed for session %s: %s',
                    $sessionId,
                    $e->getMessage(),
                ));
                return false;
            }

            if (!is_string($raw)) {
                break;
            }

            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                continue;
            }

            if (!self::writeSse($response, $data)) {
                try {
                    $pool->withConnection(static function ($redis) use ($sessionId, $raw): void {
                        /** @var Client $redis */
                        $queueKey = self::redisSessionQueueKey($sessionId);
                        $redis->rpush($queueKey, [$raw]);
                        $redis->expire($queueKey, self::REDIS_SESSION_QUEUE_TTL_SECONDS);
                    });
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        '[Semitexa SSR] Redis SSE requeue failed for session %s: %s',
                        $sessionId,
                        $e->getMessage(),
                    ));
                }
                return true;
            }

            if (self::shouldCloseAfterPayload($data)) {
                return true;
            }
        }

        return false;
    }

    private static function writeSse(Response $response, array $data): bool
    {
        $line = '';
        if (isset($data['id'])) {
            $safeId = str_replace(["\r", "\n"], '', (string) $data['id']);
            $line .= 'id: ' . $safeId . "\n";
        }
        if (isset($data['event']) && $data['event'] !== '') {
            $line .= "event: message\n";
        }
        $line .= "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        return @$response->write($line);
    }

    private static function startDemoStreamProducer(string $sessionId, string $demoStream): void
    {
        if ($demoStream !== 'showcase') {
            return;
        }

        if (isset(self::$demoProducers[$sessionId])) {
            return;
        }

        self::$demoProducers[$sessionId] = true;

        $producer = static function () use ($sessionId): void {
            \Swoole\Coroutine::sleep(0.35);

            if (!isset(self::$sessions[$sessionId])) {
                unset(self::$demoProducers[$sessionId]);
                return;
            }

            $utcNow = static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            self::deliver($sessionId, [
                'id' => 'demo_attached_' . substr(md5($sessionId), 0, 8),
                'event' => 'notification',
                'level' => 'info',
                'title' => 'Stream attached',
                'message' => 'The backend SSE stream is open. A new server-side minute tick will arrive every 60 seconds.',
                'source' => 'swoole-worker',
                'sent_at' => $utcNow()->format(DATE_ATOM),
            ]);

            $tick = 0;

            while (isset(self::$sessions[$sessionId])) {
                $now = microtime(true);
                $sleepSeconds = 60 - fmod($now, 60.0);

                if ($sleepSeconds < 0.05) {
                    $sleepSeconds += 60.0;
                }

                \Swoole\Coroutine::sleep($sleepSeconds);

                if (!isset(self::$sessions[$sessionId])) {
                    unset(self::$demoProducers[$sessionId]);
                    return;
                }

                $tick++;
                $sentAt = $utcNow();

                self::deliver($sessionId, [
                    'id' => 'demo_minute_' . $tick . '_' . substr(md5($sessionId), 0, 8),
                    'event' => 'scheduler.tick',
                    'level' => 'success',
                    'title' => 'Minute boundary reached',
                    'message' => sprintf(
                        'Backend minute tick #%d emitted at %s. The countdown should now restart for the next full minute.',
                        $tick,
                        $sentAt->format('H:i:s')
                    ),
                    'source' => 'scheduler',
                    'tick' => $tick,
                    'sent_at' => $sentAt->format(DATE_ATOM),
                ]);
            }

            unset(self::$demoProducers[$sessionId]);
        };

        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::create($producer);
            return;
        }

        unset(self::$demoProducers[$sessionId]);
    }

    private static function shouldCloseAfterPayload(array $data): bool
    {
        if (($data['type'] ?? null) !== 'done') {
            return false;
        }

        if (($data['close'] ?? false) === true) {
            return true;
        }

        return ($data['live'] ?? false) !== true;
    }

    /**
     * Deliver payload to session.
     * Paths: same-worker queue -> Redis queue (cross-worker/server) -> Swoole Tables fallback -> pendingTable -> buffer.
     */
    public static function deliver(string $sessionId, array $data): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        $currentWorkerId = self::getCurrentWorkerId();

        // Same worker has the SSE connection: add to local queue
        if (isset(self::$sessions[$sessionId])) {
            if (!isset(self::$queues[$sessionId])) {
                self::$queues[$sessionId] = [];
            }
            self::$queues[$sessionId][] = $data;
            return;
        }

        // Cross-worker / cross-server: use Redis queue when available.
        $pool = self::getRedisPool();
        if ($pool !== null) {
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($payload)) {
                try {
                    $pool->withConnection(static function ($redis) use ($sessionId, $payload): void {
                        /** @var Client $redis */
                        $queueKey = self::redisSessionQueueKey($sessionId);
                        $redis->rpush($queueKey, [$payload]);
                        $redis->expire($queueKey, self::REDIS_SESSION_QUEUE_TTL_SECONDS);
                    });
                    return;
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        '[Semitexa SSR] Redis SSE enqueue failed for session %s: %s',
                        $sessionId,
                        $e->getMessage(),
                    ));
                }
            }
        }

        // Fallback: Swoole Tables (single server only)
        if (self::$sessionWorkerTable !== null && self::$deliverTable !== null && self::$httpServer !== null) {
            $row = self::$sessionWorkerTable->get(self::sessionTableKey($sessionId));
            if ($row !== false) {
                $targetWorkerId = (int) $row['worker_id'];
                if ($targetWorkerId !== $currentWorkerId) {
                    $deliverKey = uniqid('d_', true);
                    self::$deliverTable->set($deliverKey, [
                        'session_id' => $sessionId,
                        'worker_id' => $targetWorkerId,
                        'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                    return;
                }
            }
        }
        if (self::$pendingDeliverTable !== null) {
            $key = uniqid('p_', true);
            self::$pendingDeliverTable->set($key, [
                'session_id' => $sessionId,
                'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return;
        }
        if (!isset(self::$buffer[$sessionId])) {
            self::$buffer[$sessionId] = [];
        }
        self::$buffer[$sessionId][] = $data;
    }

    public static function broadcast(string $sessionId, string $handlerKey, object $resource): void
    {
        $html = self::renderResource($resource);
        $data = [
            'handler' => $handlerKey,
            'resource' => (array) $resource,
            'html' => $html,
        ];
        self::deliver($sessionId, $data);
    }

    public static function renderResource(object $resource): string
    {
        if (!method_exists($resource, 'getRenderHandle')) {
            return '';
        }

        $handle = $resource->getRenderHandle();
        if (!$handle) {
            return '';
        }

        $context = method_exists($resource, 'getRenderContext') ? $resource->getRenderContext() : [];
        $context = array_merge($context, (array) $resource);

        try {
            return \Semitexa\Ssr\Template\ModuleTemplateRegistry::getTwig()->render(
                $handle . '.html.twig',
                $context
            );
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function isSessionActive(string $sessionId): bool
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return false;
        }

        if (isset(self::$sessions[$sessionId])) {
            return true;
        }

        if (self::$sessionWorkerTable !== null && self::$sessionWorkerTable->get(self::sessionTableKey($sessionId)) !== false) {
            return true;
        }

        $pool = self::getRedisPool();
        if ($pool !== null) {
            try {
                $isActive = $pool->withConnection(static function ($redis) use ($sessionId): bool {
                    /** @var Client $redis */
                    return (string) ($redis->get(self::redisActiveSessionKey($sessionId)) ?? '') === '1';
                });
                if ($isActive) {
                    return true;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    public static function setServer(\Swoole\Http\Server $server): void
    {
        self::$httpServer = $server;
    }

    public static function setTables(
        \Swoole\Table $sessionWorkerTable,
        \Swoole\Table $deliverTable,
        ?\Swoole\Table $pendingDeliverTable = null,
    ): void
    {
        self::$sessionWorkerTable = $sessionWorkerTable;
        self::$deliverTable = $deliverTable;
        self::$pendingDeliverTable = $pendingDeliverTable;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function deliverToUser(string $userId, array $data): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            return 0;
        }

        $sessionIds = self::getAuthenticatedUserSessionIds($userId);
        $delivered = 0;
        foreach ($sessionIds as $sessionId) {
            self::deliver($sessionId, $data);
            $delivered++;
        }

        return $delivered;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function deliverToAuthenticatedUsers(array $data): int
    {
        $sessionIds = self::getAllAuthenticatedSessionIds();
        $delivered = 0;
        foreach ($sessionIds as $sessionId) {
            self::deliver($sessionId, $data);
            $delivered++;
        }

        return $delivered;
    }

    private static function getCurrentWorkerId(): int
    {
        if (self::$httpServer === null) {
            return -1;
        }
        if (method_exists(self::$httpServer, 'getWorkerId')) {
            return (int) self::$httpServer->getWorkerId();
        }
        return (int) (self::$httpServer->worker_id ?? -1);
    }

    private static function sessionTableKey(string $sessionId): string
    {
        return strlen($sessionId) > 63 ? md5($sessionId) : $sessionId;
    }

    private static function getSsrBindToken(Request $request): string
    {
        $cookieName = 'semitexa_ssr_bind';
        $cookie = is_array($request->cookie) ? $request->cookie : [];

        return trim((string) ($cookie[$cookieName] ?? ''));
    }

    private static function removeSessionWorkerMapping(string $sessionId): void
    {
        if (self::$sessionWorkerTable === null) {
            return;
        }

        self::$sessionWorkerTable->del(self::sessionTableKey($sessionId));
    }

    private static function registerAuthenticatedSession(string $sessionId, string $userId): void
    {
        $pool = self::getRedisPool();
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
                $redis->sadd(self::REDIS_AUTH_ALL_SESSIONS_KEY, [$sessionId]);
                $redis->sadd(self::redisUserSessionsKey($userId), [$sessionId]);
                $redis->setex(self::redisSessionUserKey($sessionId), self::AUTH_SESSION_TTL_SECONDS, $userId);
                $redis->expire(self::REDIS_AUTH_ALL_SESSIONS_KEY, self::AUTH_SESSION_TTL_SECONDS);
                $redis->expire(self::redisUserSessionsKey($userId), self::AUTH_SESSION_TTL_SECONDS);
            });
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[Semitexa SSR] Failed to register authenticated SSE session %s: %s',
                $sessionId,
                $e->getMessage(),
            ));
        }
    }

    private static function unregisterAuthenticatedSession(string $sessionId): void
    {
        $pool = self::getRedisPool();
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
                $userId = trim((string) ($redis->get(self::redisSessionUserKey($sessionId)) ?? ''));
                if ($userId !== '') {
                    $redis->srem(self::redisUserSessionsKey($userId), $sessionId);
                }
                $redis->srem(self::REDIS_AUTH_ALL_SESSIONS_KEY, $sessionId);
                $redis->del(self::redisSessionUserKey($sessionId));
                $redis->del(self::redisActiveSessionKey($sessionId));
            });
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[Semitexa SSR] Failed to unregister authenticated SSE session %s: %s',
                $sessionId,
                $e->getMessage(),
            ));
        }
    }

    private static function touchActiveSession(string $sessionId): void
    {
        $pool = self::getRedisPool();
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
                $redis->setex(self::redisActiveSessionKey($sessionId), self::ACTIVE_SESSION_TTL_SECONDS, '1');
            });
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[Semitexa SSR] Failed to touch active SSE session %s: %s',
                $sessionId,
                $e->getMessage(),
            ));
        }
    }

    private static function refreshAuthenticatedSessionMapping(
        Request $request,
        string $sessionId,
        string $authenticatedUserId,
    ): string {
        $currentUserId = self::resolveAuthenticatedUserId($request);
        if ($currentUserId === '') {
            if ($authenticatedUserId !== '') {
                self::unregisterAuthenticatedSession($sessionId);
            }
            return '';
        }

        if ($authenticatedUserId !== '' && $currentUserId !== $authenticatedUserId) {
            self::unregisterAuthenticatedSession($sessionId);
        }

        self::registerAuthenticatedSession($sessionId, $currentUserId);

        return $currentUserId;
    }

    private static function canUsePersistentDeferredSse(Request $request): bool
    {
        $config = IsomorphicConfig::fromEnvironment();
        if (!$config->persistentDeferredSse) {
            return false;
        }

        if (!$config->persistentDeferredSseRequireAuth) {
            return true;
        }

        return self::hasAuthenticatedSession($request);
    }

    private static function hasAuthenticatedSession(Request $request): bool
    {
        return self::resolveAuthenticatedUserId($request) !== '';
    }

    private static function resolveAuthenticatedUserId(Request $request): string
    {
        $cookieName = Environment::getEnvValue('SESSION_COOKIE_NAME') ?? 'semitexa_session';
        $cookie = is_array($request->cookie) ? $request->cookie : [];
        $sessionId = trim((string) ($cookie[$cookieName] ?? ''));
        if ($sessionId === '' || !preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            return '';
        }

        try {
            $handler = self::createSessionHandler();
            $data = $handler->read($sessionId);
        } catch (\Throwable) {
            return '';
        }

        $userId = $data[self::AUTH_SESSION_USER_KEY] ?? null;
        return is_string($userId) ? trim($userId) : '';
    }

    private static function createSessionHandler(): SessionHandlerInterface
    {
        $pool = self::getRedisPool();
        if ($pool !== null) {
            return new RedisSessionHandler($pool);
        }

        return new SwooleTableSessionHandler();
    }

    private static function getRedisPool(): ?RedisConnectionPool
    {
        if (self::$redisPool instanceof RedisConnectionPool) {
            return self::$redisPool;
        }

        $redisHost = Environment::getEnvValue('REDIS_HOST');
        if ($redisHost === null || $redisHost === '') {
            return null;
        }

        self::$redisPool = new RedisConnectionPool(1, [
            'scheme' => (string) (Environment::getEnvValue('REDIS_SCHEME', 'tcp') ?? 'tcp'),
            'host' => $redisHost,
            'port' => (int) (Environment::getEnvValue('REDIS_PORT', '6379') ?? '6379'),
            'password' => (string) (Environment::getEnvValue('REDIS_PASSWORD', '') ?? ''),
        ]);

        return self::$redisPool;
    }

    /** @return list<string> */
    private static function getAuthenticatedUserSessionIds(string $userId): array
    {
        $pool = self::getRedisPool();
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
                $members = $redis->smembers(self::redisUserSessionsKey($userId));
                return self::filterActiveSessionIds($redis, array_values($members), $userId);
            });
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[Semitexa SSR] Failed to get authenticated user session IDs for user %s: %s',
                $userId,
                $e->getMessage(),
            ));
            return [];
        }
    }

    /** @return list<string> */
    private static function getAllAuthenticatedSessionIds(): array
    {
        $pool = self::getRedisPool();
        if ($pool === null) {
            return [];
        }

        try {
            return $pool->withConnection(static function ($redis): array {
                /** @var Client $redis */
                $members = $redis->smembers(self::REDIS_AUTH_ALL_SESSIONS_KEY);
                return self::filterActiveSessionIds($redis, array_values($members));
            });
        } catch (\Throwable $e) {
            error_log('[Semitexa SSR] Failed to get all authenticated session IDs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param list<mixed> $sessionIds
     * @return list<string>
     */
    private static function filterActiveSessionIds(mixed $redis, array $sessionIds, ?string $expectedUserId = null): array
    {
        $active = [];
        if (!$redis instanceof Client) {
            return $active;
        }

        foreach ($sessionIds as $rawSessionId) {
            $sessionId = trim((string) $rawSessionId);
            if ($sessionId === '') {
                continue;
            }

            $mappedUserId = trim((string) ($redis->get(self::redisSessionUserKey($sessionId)) ?? ''));
            $isActive = (string) ($redis->get(self::redisActiveSessionKey($sessionId)) ?? '') === '1';
            if (
                $mappedUserId === ''
                || !$isActive
                || ($expectedUserId !== null && $mappedUserId !== $expectedUserId)
            ) {
                $redis->srem(self::REDIS_AUTH_ALL_SESSIONS_KEY, $sessionId);
                if ($expectedUserId !== null) {
                    $redis->srem(self::redisUserSessionsKey($expectedUserId), $sessionId);
                } elseif ($mappedUserId !== '') {
                    $redis->srem(self::redisUserSessionsKey($mappedUserId), $sessionId);
                }
                $redis->del(self::redisSessionUserKey($sessionId));
                $redis->del(self::redisActiveSessionKey($sessionId));
                continue;
            }

            $active[] = $sessionId;
        }

        return $active;
    }

    private static function redisUserSessionsKey(string $userId): string
    {
        return self::REDIS_AUTH_USER_SESSIONS_PREFIX . trim($userId);
    }

    private static function redisSessionUserKey(string $sessionId): string
    {
        return self::REDIS_AUTH_SESSION_USER_PREFIX . trim($sessionId);
    }

    private static function redisSessionQueueKey(string $sessionId): string
    {
        return self::REDIS_SESSION_QUEUE_PREFIX . trim($sessionId);
    }

    private static function redisActiveSessionKey(string $sessionId): string
    {
        return self::REDIS_ACTIVE_SESSION_PREFIX . trim($sessionId);
    }

    private static function isSameOriginRequest(Request $request): bool
    {
        $header = [];
        if (is_array($request->header)) {
            foreach ($request->header as $key => $value) {
                if (is_string($key) && (is_scalar($value) || $value === null)) {
                    $header[$key] = (string) $value;
                }
            }
        }

        $host = trim($header['host'] ?? '');
        if ($host === '') {
            return true;
        }

        foreach (['origin', 'referer'] as $headerName) {
            $value = trim($header[$headerName] ?? '');
            if ($value === '') {
                continue;
            }

            $requestHost = parse_url($value, PHP_URL_HOST);
            if (!is_string($requestHost) || $requestHost === '') {
                continue;
            }

            $requestPort = parse_url($value, PHP_URL_PORT);
            $normalizedHost = strtolower($host);
            $normalizedRequestHost = strtolower($requestHost . ($requestPort !== null ? ':' . $requestPort : ''));

            if ($normalizedRequestHost !== $normalizedHost && strtolower($requestHost) !== $normalizedHost) {
                return false;
            }
        }

        return true;
    }
}
