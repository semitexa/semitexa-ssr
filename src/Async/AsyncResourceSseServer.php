<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Async;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;

final class AsyncResourceSseServer
{
    private static array $sessions = [];
    private static ?\Swoole\Http\Server $httpServer = null;
    private static int $port = 9503;

    public static function initialize(int $port = 9503): void
    {
        self::$port = $port;
    }

    public static function handle(Request $request, Response $response): bool
    {
        $path = $request->server['path_info'] ?? '';

        if ($path === '/__semitexa_sse') {
            self::handleSse($request, $response);
            return true;
        }

        return false;
    }

    private static function handleSse(Request $request, Response $response): void
    {
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('Access-Control-Allow-Origin', '*');

        $sessionId = $request->get['session_id'] ?? uniqid('sse_');

        self::$sessions[$sessionId] = [
            'fd' => $request->fd,
            'connected_at' => time(),
        ];

        $lastEventId = $request->header['last-event-id'] ?? '';

        while (!connection_aborted() && isset(self::$sessions[$sessionId])) {
            sleep(1);

            $results = self::fetchAsyncResults($sessionId, $lastEventId);

            foreach ($results as $result) {
                $response->write("data: " . json_encode($result) . "\n\n");
            }
        }

        unset(self::$sessions[$sessionId]);
    }

    public static function broadcast(string $sessionId, string $handlerKey, object $resource): void
    {
        if (!isset(self::$sessions[$sessionId])) {
            return;
        }

        $session = self::$sessions[$sessionId];

        if (!self::$httpServer) {
            return;
        }

        $html = self::renderResource($resource);

        $data = [
            'handler' => $handlerKey,
            'resource' => (array) $resource,
            'html' => $html,
        ];

        self::$httpServer->push($session['fd'], json_encode($data));
    }

    private static function fetchAsyncResults(string $sessionId, string $lastEventId = ''): array
    {
        return [];
    }

    private static function renderResource(object $resource): string
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
}
