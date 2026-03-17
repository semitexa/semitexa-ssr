<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Asset;

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

readonly class StaticAssetHandler
{
    private const PREFIX = '/assets/';

    private const CONTENT_TYPES = [
        'js'   => 'application/javascript',
        'css'  => 'text/css',
        'json' => 'application/json',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'ico'  => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'map'  => 'application/json',
        'twig' => 'text/plain; charset=utf-8',
    ];

    public function handle(SwooleRequest $request, SwooleResponse $response): bool
    {
        $uri = $request->server['request_uri'] ?? '';

        if (!str_starts_with($uri, self::PREFIX)) {
            return false;
        }

        $rest = substr($uri, strlen(self::PREFIX));

        // Parse {module}/{path}
        $slashPos = strpos($rest, '/');
        if ($slashPos === false || $slashPos === 0) {
            $response->status(404);
            $response->end('Not Found');
            return true;
        }

        $module = substr($rest, 0, $slashPos);
        $path = substr($rest, $slashPos + 1);

        if ($path === '' || $path === false) {
            $response->status(404);
            $response->end('Not Found');
            return true;
        }

        // Check for path traversal in the raw URI
        if (str_contains($path, '..') || str_contains($module, '..')) {
            $response->status(403);
            $response->end('Forbidden');
            return true;
        }

        $filePath = ModuleAssetRegistry::resolve($module, $path);

        if ($filePath === null) {
            $response->status(404);
            $response->end('Not Found');
            return true;
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $contentType = self::CONTENT_TYPES[$extension] ?? 'application/octet-stream';

        $response->status(200);
        $response->header('Content-Type', $contentType);
        $response->header('Cache-Control', 'public, max-age=3600');
        $response->sendfile($filePath);

        return true;
    }
}
