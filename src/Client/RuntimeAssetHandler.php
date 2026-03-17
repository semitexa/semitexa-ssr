<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Client;

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

final class RuntimeAssetHandler
{
    private const RUNTIME_PATH = '/assets/ssr/semitexa-twig.js';

    private static ?string $cachedContent = null;
    private static ?string $cachedEtag = null;

    public static function handle(SwooleRequest $request, SwooleResponse $response): bool
    {
        $uri = $request->server['request_uri'] ?? '';

        if ($uri !== self::RUNTIME_PATH) {
            return false;
        }

        $content = self::getContent();
        if ($content === null) {
            $response->status(404);
            $response->end('Not Found');
            return true;
        }

        $etag = self::getEtag();
        $ifNoneMatch = $request->header['if-none-match'] ?? '';
        if ($ifNoneMatch === $etag) {
            $response->status(304);
            $response->end();
            return true;
        }

        $response->status(200);
        $response->header('Content-Type', 'application/javascript; charset=utf-8');
        $response->header('Cache-Control', 'public, max-age=31536000, immutable');
        $response->header('ETag', $etag);
        $response->end($content);

        return true;
    }

    private static function getContent(): ?string
    {
        if (self::$cachedContent !== null) {
            return self::$cachedContent;
        }

        $path = __DIR__ . '/../../resources/js/semitexa-twig.js';
        if (!is_file($path)) {
            return null;
        }

        self::$cachedContent = file_get_contents($path);
        return self::$cachedContent;
    }

    private static function getEtag(): string
    {
        if (self::$cachedEtag !== null) {
            return self::$cachedEtag;
        }

        $content = self::getContent();
        self::$cachedEtag = '"' . substr(md5($content ?? ''), 0, 16) . '"';
        return self::$cachedEtag;
    }

    public static function reset(): void
    {
        self::$cachedContent = null;
        self::$cachedEtag = null;
    }
}
