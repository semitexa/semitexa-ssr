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
        'ogg'  => 'audio/ogg',
        'mp3'  => 'audio/mpeg',
        'm4a'  => 'audio/mp4',
        'wav'  => 'audio/wav',
    ];

    public function handle(SwooleRequest $request, SwooleResponse $response): bool
    {
        ModuleAssetRegistry::setModuleRegistry($this->moduleRegistry);

        $rawUri = isset($request->server['request_uri']) && is_string($request->server['request_uri'])
            ? $request->server['request_uri']
            : '';
        $queryString = isset($request->server['query_string']) && is_string($request->server['query_string'])
            ? $request->server['query_string']
            : (str_contains($rawUri, '?') ? explode('?', $rawUri, 2)[1] ?? '' : '');
        $uri = $rawUri;
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

        $cacheControl = self::cacheControlForUri($queryString === '' ? $uri : $uri . '?' . $queryString);
        $etag = self::etagForFile($filePath);

        $ifNoneMatch = self::requestHeader($request, 'if-none-match');
        if (self::ifNoneMatchMatches($ifNoneMatch, $etag)) {
            $response->status(304);
            $response->header('Cache-Control', $cacheControl);
            $response->header('ETag', $etag);
            $response->end();
            return true;
        }

        $response->status(200);
        $response->header('Content-Type', $contentType);
        $response->header('Cache-Control', $cacheControl);
        if ($etag !== '') {
            $response->header('ETag', $etag);
        }
        $response->sendfile($filePath);

        return true;
    }

    /**
     * Pick the cache-control header for an asset URI.
     *
     * `immutable` is a one-year-no-revalidate promise. We may only make that
     * promise when the URL itself encodes the content version (`?v=<hash>`),
     * because the URL is the cache key. AssetManager::getUrl() always appends
     * `?v=<sha256-12hex>` of the file. A hardcoded URL with no version string
     * would otherwise pin a stale copy in every browser for a year, which is
     * how the PipelineTest CSRF "fix" silently never reached the page.
     */
    public static function cacheControlForUri(string $uri): string
    {
        return self::isVersionedUri($uri)
            ? 'public, max-age=31536000, immutable'
            : 'public, max-age=0, must-revalidate';
    }

    /**
     * Strong ETag derived from file content. Returns the quoted RFC 7232
     * form, or '' when hashing fails.
     */
    public static function etagForFile(string $filePath): string
    {
        $hash = @hash_file('sha256', $filePath);
        if (!is_string($hash) || $hash === '') {
            return '';
        }
        return sprintf('"%s"', $hash);
    }

    private static function isVersionedUri(string $uri): bool
    {
        $q = strpos($uri, '?');
        if ($q === false) {
            return false;
        }
        parse_str(substr($uri, $q + 1), $params);
        $v = $params['v'] ?? null;
        return is_string($v) && $v !== '';
    }

    private static function requestHeader(SwooleRequest $request, string $loweredName): ?string
    {
        if (!is_array($request->header)) {
            return null;
        }
        $value = $request->header[$loweredName] ?? null;
        return is_string($value) ? $value : null;
    }

    private static function ifNoneMatchMatches(?string $headerValue, string $etag): bool
    {
        if ($headerValue === null || $etag === '') {
            return false;
        }

        $normalizedCurrent = self::normalizeEtagToken($etag);
        if ($normalizedCurrent === null) {
            return false;
        }

        foreach (explode(',', $headerValue) as $rawToken) {
            $token = trim($rawToken);
            if ($token === '') {
                continue;
            }
            if ($token === '*') {
                return true;
            }
            if (self::normalizeEtagToken($token) === $normalizedCurrent) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeEtagToken(string $token): ?string
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            return null;
        }
        if (str_starts_with($trimmed, 'W/')) {
            $trimmed = substr($trimmed, 2);
        }

        return trim($trimmed);
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
