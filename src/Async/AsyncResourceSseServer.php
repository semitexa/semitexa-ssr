<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Async;

use Semitexa\Core\Environment;
use Semitexa\Core\Session\RedisSessionHandler;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class AsyncResourceSseServer
{
    private const AUTH_SESSION_USER_KEY = '_auth_user_id';

    private static array $sessions = [];

    /** @var array<string, list<array>> Pending messages per session when no connection yet */
    private static array $buffer = [];

    /** @var array<string, list<array>> In-memory queue per session for the loop to send */
    private static array $queues = [];

    private static ?\Swoole\Http\Server $httpServer = null;

    /** @var \Swoole\Table|null session_id -> worker_id (for cross-worker deliver) */
    private static ?\Swoole\Table $sessionWorkerTable = null;

    /** @var \Swoole\Table|null deliver queue: unique_key -> session_id, worker_id, payload */
    private static ?\Swoole\Table $deliverTable = null;

    /** @var \Swoole\Table|null pending messages when client not connected yet: key -> session_id, payload */
    private static ?\Swoole\Table $pendingDeliverTable = null;

    private const RABBITMQ_QUEUE_PREFIX = 'sse.deliver.';

    private static function rabbitQueueName(string $sessionId): string
    {
        return self::RABBITMQ_QUEUE_PREFIX . md5(trim($sessionId));
    }

    /** @var \AMQPChannel|null Lazy-created per worker when RabbitMQ env is set */
    private static ?\AMQPChannel $amqpChannel = null;

    private static function getRabbitMqChannel(): ?\AMQPChannel
    {
        if (self::$amqpChannel !== null) {
            return self::$amqpChannel;
        }
        if (!class_exists(\AMQPConnection::class)) {
            return null;
        }
        $host = Environment::getEnvValue('RABBITMQ_HOST', '');
        if ($host === '') {
            return null;
        }
        try {
            $conn = new \AMQPConnection([
                'host' => $host,
                'port' => (int) Environment::getEnvValue('RABBITMQ_PORT', '5672'),
                'login' => Environment::getEnvValue('RABBITMQ_USER', 'guest'),
                'password' => Environment::getEnvValue('RABBITMQ_PASSWORD', 'guest'),
                'vhost' => Environment::getEnvValue('RABBITMQ_VHOST', '/'),
            ]);
            $conn->connect();
            self::$amqpChannel = new \AMQPChannel($conn);
            return self::$amqpChannel;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function handle(Request $request, Response $response): bool
    {
        $path = $request->server['path_info'] ?? '';
        if ($path === '') {
            $uri = $request->server['request_uri'] ?? '/';
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

        $response->status(200);
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');

        $sessionId = trim((string) ($request->get['session_id'] ?? uniqid('sse_', true)));

        self::$sessions[$sessionId] = [
            'response' => $response,
            'connected_at' => time(),
        ];

        if (self::$sessionWorkerTable !== null && self::$httpServer !== null) {
            $workerId = self::getCurrentWorkerId();
            $key = self::sessionTableKey($sessionId);
            self::$sessionWorkerTable->set($key, ['worker_id' => $workerId]);
        }

        // Declare RabbitMQ queue for this session so deliver() from any worker/server can publish
        $rabbitQueueName = null;
        $channel = self::getRabbitMqChannel();
        if ($channel !== null) {
            try {
                $rabbitQueueName = self::rabbitQueueName($sessionId);
                $queue = new \AMQPQueue($channel);
                $queue->setName($rabbitQueueName);
                $queue->setFlags(\defined('AMQP_DURABLE') ? AMQP_DURABLE : 2);
                $queue->declareQueue();
            } catch (\Throwable $e) {
                $rabbitQueueName = null;
            }
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

        // Flush RabbitMQ queue for this session (messages from any worker/server)
        self::drainRabbitMqQueueForSession($sessionId, $response);

        // Send initial event so the client receives something immediately (fixes "Connecting..." stuck
        // and ensures response is flushed; some proxies don't send headers until first byte).
        self::writeSse($response, ['connected' => true]);

        // Trigger deferred block streaming if deferred_request_id is present
        $deferredRequestId = trim((string) ($request->get['deferred_request_id'] ?? ''));
        $lastEventId = $request->header['last-event-id'] ?? null;
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
        while (!$closed && isset(self::$sessions[$sessionId])) {
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

            if (!$closed) {
                if (self::drainRabbitMqQueueForSession($sessionId, $response)) {
                    $closed = true;
                }
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
        // Delete RabbitMQ queue for this session to avoid buildup (queue recreated on next connect)
        $channel = self::getRabbitMqChannel();
        if ($channel !== null) {
            try {
                $queue = new \AMQPQueue($channel);
                $queue->setName(self::rabbitQueueName($sessionId));
                $queue->delete();
            } catch (\Throwable $e) {
                // ignore
            }
        }
        unset(self::$sessions[$sessionId]);
        unset(self::$queues[$sessionId]);
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

        $debugEnabled = filter_var((string) (\getenv('APP_DEBUG') ?? \getenv('DEBUG') ?? '0'), \FILTER_VALIDATE_BOOLEAN);
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

        $debugLog('registry_found', [
            'deferred_request_id' => $deferredRequestId,
            'page_handle' => $registry['page_handle'],
            'slots' => $registry['slots'],
        ]);

        // Use coroutine to resolve deferred blocks concurrently
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::create(static function () use ($sessionId, $registry, $lastEventId, $deferredRequestId, $debugLog): void {
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

    private static function drainRabbitMqQueueForSession(string $sessionId, Response $response): bool
    {
        $channel = self::getRabbitMqChannel();
        if ($channel === null) {
            return false;
        }
        try {
            $queue = new \AMQPQueue($channel);
            $queue->setName(self::rabbitQueueName($sessionId));
            $queue->setFlags(\defined('AMQP_DURABLE') ? AMQP_DURABLE : 2);
            $queue->declareQueue();
            $count = 0;
            while (true) {
                $envelope = $queue->get(\defined('AMQP_NOPARAM') ? AMQP_NOPARAM : 0);
                if ($envelope === false) {
                    break;
                }
                $data = json_decode($envelope->getBody(), true);
                if (is_array($data) && self::writeSse($response, $data)) {
                    $count++;
                    if (self::shouldCloseAfterPayload($data)) {
                        $queue->ack($envelope->getDeliveryTag());
                        return true;
                    }
                }
                $queue->ack($envelope->getDeliveryTag());
            }
        } catch (\Throwable $e) {
            self::$amqpChannel = null;
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
        $line .= "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        return @$response->write($line);
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
     * Paths: same-worker queue -> RabbitMQ (cross-worker/server) -> Swoole Tables fallback -> pendingTable -> buffer.
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

        // Cross-worker / cross-server: use RabbitMQ when available (scales across machines and offices)
        $channel = self::getRabbitMqChannel();
        if ($channel !== null) {
            try {
                $queueName = self::rabbitQueueName($sessionId);
                $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $queue = new \AMQPQueue($channel);
                $queue->setName($queueName);
                $queue->setFlags(\defined('AMQP_DURABLE') ? AMQP_DURABLE : 2);
                $queue->declareQueue();
                $exchange = new \AMQPExchange($channel);
                $exchange->setName('');
                $exchange->publish($payload, $queueName, \defined('AMQP_NOPARAM') ? AMQP_NOPARAM : 0, ['delivery_mode' => 2]);
                return;
            } catch (\Throwable $e) {
                self::$amqpChannel = null;
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

        if (self::$sessionWorkerTable !== null) {
            return self::$sessionWorkerTable->get(self::sessionTableKey($sessionId)) !== false;
        }

        return false;
    }

    public static function setServer(\Swoole\Http\Server $server): void
    {
        self::$httpServer = $server;
    }

    public static function setTables(\Swoole\Table $sessionWorkerTable, \Swoole\Table $deliverTable, ?\Swoole\Table $pendingDeliverTable = null): void
    {
        self::$sessionWorkerTable = $sessionWorkerTable;
        self::$deliverTable = $deliverTable;
        self::$pendingDeliverTable = $pendingDeliverTable;
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
        return trim((string) (($request->cookie[$cookieName] ?? '')));
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
        $cookieName = Environment::getEnvValue('SESSION_COOKIE_NAME') ?? 'semitexa_session';
        $sessionId = trim((string) ($request->cookie[$cookieName] ?? ''));
        if ($sessionId === '' || !preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            return false;
        }

        try {
            $handler = self::createSessionHandler();
            $data = $handler->read($sessionId);
        } catch (\Throwable) {
            return false;
        }

        $userId = $data[self::AUTH_SESSION_USER_KEY] ?? null;
        return is_string($userId) && trim($userId) !== '';
    }

    private static function createSessionHandler(): SessionHandlerInterface
    {
        $redisHost = Environment::getEnvValue('REDIS_HOST');
        if ($redisHost !== null && $redisHost !== '') {
            return new RedisSessionHandler();
        }

        return new SwooleTableSessionHandler();
    }

    private static function isSameOriginRequest(Request $request): bool
    {
        $host = trim((string) (($request->header['host'] ?? '')));
        if ($host === '') {
            return true;
        }

        foreach (['origin', 'referer'] as $headerName) {
            $value = trim((string) (($request->header[$headerName] ?? '')));
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
