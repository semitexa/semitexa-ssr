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

    public static function handle(Request $request, Response $response): bool
    {
        $path = $request->server['path_info'] ?? '';
        if ($path === '') {
            $uri = $request->server['request_uri'] ?? '/';
            $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        }

        if ($path === '/__semitexa_sse') {
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

        $sessionId = $request->get['session_id'] ?? uniqid('sse_', true);

        self::$sessions[$sessionId] = [
            'response' => $response,
            'connected_at' => time(),
        ];

        if (self::$sessionWorkerTable !== null && self::$httpServer !== null) {
            $workerId = self::getCurrentWorkerId();
            $key = self::sessionTableKey($sessionId);
            self::$sessionWorkerTable->set($key, ['worker_id' => $workerId]);
        }

        if (!isset(self::$queues[$sessionId])) {
            self::$queues[$sessionId] = [];
        }

        // Flush buffer first (messages that arrived before client connected)
        if (isset(self::$buffer[$sessionId])) {
            foreach (self::$buffer[$sessionId] as $data) {
                self::writeSse($response, $data);
            }
            unset(self::$buffer[$sessionId]);
        }

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
                foreach (self::$deliverTable as $deliverKey => $row) {
                    if ((int) $row['worker_id'] === $currentWorkerId && (string) $row['session_id'] === $sessionId) {
                        $data = json_decode((string) $row['payload'], true);
                        if (is_array($data) && self::writeSse($response, $data)) {
                            $toDel[] = $deliverKey;
                        }
                    }
                }
                foreach ($toDel as $k) {
                    self::$deliverTable->del($k);
                }
            }

            if ($closed) {
                break;
            }

            if (function_exists('connection_aborted') && connection_aborted()) {
                break;
            }

            usleep(200_000);
        }

        if (self::$sessionWorkerTable !== null) {
            self::$sessionWorkerTable->del(self::sessionTableKey($sessionId));
        }
        unset(self::$sessions[$sessionId]);
        unset(self::$queues[$sessionId]);
        $response->end();
    }

    private static function writeSse(Response $response, array $data): bool
    {
        $line = "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        return @$response->write($line);
    }

    /**
     * Deliver payload to session: add to local queue if this worker has the connection,
     * else push to shared deliver table for the worker that owns the session.
     */
    public static function deliver(string $sessionId, array $data): void
    {
        if ($sessionId === '') {
            return;
        }

        if (isset(self::$sessions[$sessionId])) {
            if (!isset(self::$queues[$sessionId])) {
                self::$queues[$sessionId] = [];
            }
            self::$queues[$sessionId][] = $data;
            return;
        }

        if (self::$sessionWorkerTable !== null && self::$deliverTable !== null && self::$httpServer !== null) {
            $row = self::$sessionWorkerTable->get(self::sessionTableKey($sessionId));
            if ($row !== false) {
                $targetWorkerId = (int) $row['worker_id'];
                $currentWorkerId = self::getCurrentWorkerId();
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

    public static function setTables(\Swoole\Table $sessionWorkerTable, \Swoole\Table $deliverTable): void
    {
        self::$sessionWorkerTable = $sessionWorkerTable;
        self::$deliverTable = $deliverTable;
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
