<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Async;

use Swoole\Http\Request;
use Swoole\Http\Response;

final class AsyncResourceSseServer
{
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
        $host = getenv('RABBITMQ_HOST') ?: '';
        if ($host === '') {
            return null;
        }
        try {
            $conn = new \AMQPConnection([
                'host' => $host,
                'port' => (int) (getenv('RABBITMQ_PORT') ?: '5672'),
                'login' => getenv('RABBITMQ_USER') ?: 'guest',
                'password' => getenv('RABBITMQ_PASSWORD') ?: 'guest',
                'vhost' => getenv('RABBITMQ_VHOST') ?: '/',
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

        return false;
    }

    private static function handleSse(Request $request, Response $response): void
    {
        $response->status(200);
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');
        $response->header('Access-Control-Allow-Origin', '*');

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

        $closed = false;
        while (!$closed && isset(self::$sessions[$sessionId])) {
            while (isset(self::$queues[$sessionId]) && self::$queues[$sessionId] !== []) {
                $data = array_shift(self::$queues[$sessionId]);
                if (!self::writeSse($response, $data)) {
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
                        }
                    }
                }
                foreach ($toDel as $k) {
                    self::$deliverTable->del($k);
                }
            }

            if (!$closed) {
                self::drainRabbitMqQueueForSession($sessionId, $response);
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

    private static function drainRabbitMqQueueForSession(string $sessionId, Response $response): void
    {
        $channel = self::getRabbitMqChannel();
        if ($channel === null) {
            return;
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
                }
                $queue->ack($envelope->getDeliveryTag());
            }
        } catch (\Throwable $e) {
            self::$amqpChannel = null;
        }
    }

    private static function writeSse(Response $response, array $data): bool
    {
        $line = "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        return @$response->write($line);
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
}
