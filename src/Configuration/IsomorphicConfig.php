<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Configuration;

use Semitexa\Core\Environment;

readonly class IsomorphicConfig
{
    public bool $enabled;
    public bool $crawlerFullRender;
    public bool $persistentDeferredSse;
    public bool $persistentDeferredSseRequireAuth;
    public int $sseHeartbeatIntervalSeconds;
    public int $defaultCacheTtl;
    public string $templateAssetsPath;
    public int $deferredContextSize;
    public int $requestSnapshotSize;

    /**
     * @param string $templateAssetsPath Project-relative public asset directory, e.g. public/assets/ssr/tpl
     */
    public function __construct(
        bool $enabled = false,
        bool $crawlerFullRender = true,
        bool $persistentDeferredSse = false,
        bool $persistentDeferredSseRequireAuth = true,
        int $sseHeartbeatIntervalSeconds = 15,
        int $defaultCacheTtl = 0,
        string $templateAssetsPath = 'public/assets/ssr/tpl',
        int $deferredContextSize = 32768,
        int $requestSnapshotSize = 4096,
    ) {
        $this->enabled = $enabled;
        $this->crawlerFullRender = $crawlerFullRender;
        $this->persistentDeferredSse = $persistentDeferredSse;
        $this->persistentDeferredSseRequireAuth = $persistentDeferredSseRequireAuth;
        $this->sseHeartbeatIntervalSeconds = $sseHeartbeatIntervalSeconds;
        $this->defaultCacheTtl = $defaultCacheTtl;
        $this->templateAssetsPath = self::normalizeTemplateAssetsPath($templateAssetsPath);
        $this->deferredContextSize = $deferredContextSize;
        $this->requestSnapshotSize = $requestSnapshotSize;
    }

    public static function fromEnvironment(): self
    {
        return new self(
            enabled: Environment::getEnvValue('SSR_ISOMORPHIC_ENABLED', 'false') === 'true',
            crawlerFullRender: Environment::getEnvValue('SSR_CRAWLER_FULL_RENDER', 'true') === 'true',
            persistentDeferredSse: Environment::getEnvValue('SSR_DEFERRED_PERSISTENT_SSE', 'false') === 'true',
            persistentDeferredSseRequireAuth: Environment::getEnvValue('SSR_DEFERRED_PERSISTENT_SSE_REQUIRE_AUTH', 'true') === 'true',
            sseHeartbeatIntervalSeconds: (int) Environment::getEnvValue('SSR_SSE_HEARTBEAT_SECONDS', '15'),
            defaultCacheTtl: (int) Environment::getEnvValue('SSR_DEFAULT_CACHE_TTL', '0'),
            templateAssetsPath: Environment::getEnvValue('SSR_TEMPLATE_ASSETS_PATH', 'public/assets/ssr/tpl'),
            deferredContextSize: (int) Environment::getEnvValue('SSR_DEFERRED_CONTEXT_SIZE', '32768'),
            requestSnapshotSize: (int) Environment::getEnvValue('SSR_REQUEST_SNAPSHOT_SIZE', '4096'),
        );
    }

    private static function normalizeTemplateAssetsPath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            throw new \InvalidArgumentException('SSR_TEMPLATE_ASSETS_PATH must not be empty.');
        }

        if ($normalized !== 'public' && !str_starts_with($normalized, 'public/')) {
            throw new \InvalidArgumentException(sprintf(
                'SSR_TEMPLATE_ASSETS_PATH must point inside the public/ tree; got "%s".',
                $path,
            ));
        }

        return $normalized;
    }
}
