<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

use Semitexa\Core\ModuleRegistry;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

readonly class StaticAssetHandler
{
    public function __construct(
        private ModuleRegistry $moduleRegistry,
    ) {
    }

    private const PREFIXES = ['/assets/', '/static/'];

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
        ModuleAssetRegistry::setModuleRegistry($this->moduleRegistry);

        $uri = isset($request->server['request_uri']) && is_string($request->server['request_uri'])
            ? $request->server['request_uri']
            : '';
        if ($uri !== '' && str_contains($uri, '?')) {
            $uri = explode('?', $uri, 2)[0];
        }

        $prefix = self::matchPrefix($uri);
        if ($prefix === null) {
            return false;
        }

        $rest = substr($uri, strlen($prefix));

        // Parse {module}/{path}
        $slashPos = strpos($rest, '/');
        if ($slashPos === false || $slashPos === 0) {
            $response->status(404);
            $response->end('Not Found');
            return true;
        }

        $module = substr($rest, 0, $slashPos);
        $path = substr($rest, $slashPos + 1);

        if ($path === '') {
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
        $response->header('Cache-Control', 'public, max-age=31536000, immutable');
        $response->sendfile($filePath);

        return true;
    }

    private static function matchPrefix(string $uri): ?string
    {
        foreach (self::PREFIXES as $candidate) {
            if (str_starts_with($uri, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
