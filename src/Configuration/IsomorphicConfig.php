<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Configuration;

use Semitexa\Core\Environment;

readonly class IsomorphicConfig
{
    public function __construct(
        public bool $enabled = false,
        public bool $crawlerFullRender = true,
        public int $sseHeartbeatIntervalSeconds = 15,
        public int $defaultCacheTtl = 0,
        public string $templateAssetsPath = 'public/assets/ssr/tpl',
        public int $deferredContextSize = 8192,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            enabled: Environment::getEnvValue('SSR_ISOMORPHIC_ENABLED', 'false') === 'true',
            crawlerFullRender: Environment::getEnvValue('SSR_CRAWLER_FULL_RENDER', 'true') === 'true',
            sseHeartbeatIntervalSeconds: (int) Environment::getEnvValue('SSR_SSE_HEARTBEAT_SECONDS', '15'),
            defaultCacheTtl: (int) Environment::getEnvValue('SSR_DEFAULT_CACHE_TTL', '0'),
            templateAssetsPath: Environment::getEnvValue('SSR_TEMPLATE_ASSETS_PATH', 'public/assets/ssr/tpl'),
            deferredContextSize: (int) Environment::getEnvValue('SSR_DEFERRED_CONTEXT_SIZE', '8192'),
        );
    }
}
