<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\ProjectRoot;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

readonly class StaticAssetHandler
{
    public function __construct(
        private ModuleRegistry $moduleRegistry,
    ) {
    }

    private const PREFIXES = ['/assets/', '/static/'];

    /**
     * Extensions worth compressing. Text only: an image, a font and an audio
     * file are already compressed, and gzipping them spends CPU to add bytes.
     */
    private const COMPRESSIBLE = ['js', 'css', 'json', 'svg', 'map'];

    /** Below this, the gzip header costs more than the saving. */
    private const COMPRESS_MIN_BYTES = 1024;

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
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
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

        // Resolved BEFORE the ETag, because it decides which representation is
        // being served and the two must not share one. A strong ETag names a
        // representation, so answering an identity request 304 against a tag
        // minted for the gzipped copy would let a cache hand out bytes the
        // client cannot read.
        $gzipped = self::gzippedTwin($filePath, $extension, self::requestHeader($request, 'accept-encoding'));
        $etag = self::etagForFile($filePath);
        if ($etag !== '' && $gzipped !== null) {
            $etag = substr($etag, 0, -1) . '-gzip"';
        }

        $ifNoneMatch = self::requestHeader($request, 'if-none-match');
        if (self::ifNoneMatchMatches($ifNoneMatch, $etag)) {
            $response->status(304);
            $response->header('Cache-Control', $cacheControl);
            $response->header('ETag', $etag);
            if ($gzipped !== null) {
                $response->header('Vary', 'Accept-Encoding');
            }
            $response->end();
            return true;
        }

        $response->status(200);
        $response->header('Content-Type', $contentType);
        $response->header('Cache-Control', $cacheControl);
        if ($etag !== '') {
            $response->header('ETag', $etag);
        }

        if ($gzipped !== null) {
            $response->header('Content-Encoding', 'gzip');
            // Without this a shared cache can hand the compressed copy to a
            // client that never asked for it.
            $response->header('Vary', 'Accept-Encoding');
            $response->sendfile($gzipped);

            return true;
        }

        $response->sendfile($filePath);

        return true;
    }

    /**
     * The gzipped copy of a text asset, built once and kept.
     *
     * MEASURED before this existed: platform-ui shipped 315277 bytes of CSS and
     * JS and every byte of it went out uncompressed — gzip takes the same set
     * to 82720, which is 74% of the transfer for no change to a single
     * character of the delivered code. Nothing in the stack was doing it:
     * Swoole's `http_compression` setting is ACCEPTED and inert on this build
     * (6.2.0, compiled with no compression support — `php --ri swoole` lists
     * openssl, dtls, http2 and json and no zlib), and it would not have covered
     * this path anyway, because Swoole compresses what a response `end()`s and
     * never what it `sendfile()`s.
     *
     * A deployment behind nginx has had this all along, which is why it went
     * unnoticed; one served straight from Swoole — the dev server every
     * developer uses, and any single-container install — has not.
     *
     * Public and static for the same reason {@see cacheControlForUri()} and
     * {@see etagForFile()} are: it is a decision, and a decision that only the
     * request path can reach is a decision nothing can check.
     *
     * Written beside the source rather than served from memory so `sendfile()`
     * survives: the kernel still streams the bytes, and the compression is paid
     * once per file per deployment instead of once per request. The name
     * carries the source's mtime and size, so an edited file gets a new twin
     * and a stale one is never served.
     */
    public static function gzippedTwin(
        string $filePath,
        string $extension,
        ?string $acceptEncoding,
        ?string $cacheDir = null,
    ): ?string
    {
        if (!in_array($extension, self::COMPRESSIBLE, true)) {
            return null;
        }

        if ($acceptEncoding === null || !str_contains(strtolower($acceptEncoding), 'gzip')) {
            return null;
        }

        $size = @filesize($filePath);
        if ($size === false || $size < self::COMPRESS_MIN_BYTES) {
            return null;
        }

        $cacheDir ??= ProjectRoot::get() . '/var/cache/assets';
        $twin = $cacheDir . '/' . hash('xxh128', $filePath . '|' . $size . '|' . (string) @filemtime($filePath)) . '.gz';

        if (is_file($twin)) {
            return $twin;
        }

        $source = @file_get_contents($filePath);
        if ($source === false) {
            return null;
        }

        $compressed = @gzencode($source, 6);
        if ($compressed === false || strlen($compressed) >= $size) {
            return null; // Nothing gained; serve the original.
        }

        // 0775, not 0755: an install where the CLI creates this directory as
        // one user and the web worker writes it as another is ordinary, and a
        // group-writable cache is what lets both use it.
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            self::reportUncacheable($cacheDir, 'the directory could not be created');

            return null;
        }

        // Written through a temporary name and renamed: two workers racing the
        // same first request must never let a reader see a half-written file,
        // and rename is the only atomic move on a local filesystem.
        $temp = $twin . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, $compressed) === false || !@rename($temp, $twin)) {
            @unlink($temp);
            self::reportUncacheable($cacheDir, 'the directory is not writable by this process');

            return null;
        }

        return $twin;
    }

    /**
     * Say, once, that compression is off because the cache cannot be written.
     *
     * Falling back to the uncompressed file is the right thing to do — an asset
     * that serves is better than one that 500s — but doing it in silence means
     * a deployment can ship every byte uncompressed forever with nothing to
     * explain it. MEASURED as a real shape, not imagined: in this workspace the
     * directory was created root-owned by a CLI container while the worker runs
     * as uid 1000, and the only symptom was that nothing was ever compressed.
     *
     * Once per process per directory. A per-request warning on a busy server is
     * how a log stops being read. The tally is a function-static rather than a
     * class property because this class is `readonly`, which forbids one.
     */
    private static function reportUncacheable(string $cacheDir, string $because): void
    {
        /** @var array<string, true> $reported */
        static $reported = [];

        if (isset($reported[$cacheDir])) {
            return;
        }

        $reported[$cacheDir] = true;

        StaticLoggerBridge::warning('ssr', 'Serving assets uncompressed: ' . $because, [
            'cache_dir' => $cacheDir,
            'cost' => 'Text assets go out at full size. platform-ui alone is ~315 KB that gzips to ~83 KB.',
            'fix' => 'Make the directory writable by the user the workers run as.',
        ]);
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
