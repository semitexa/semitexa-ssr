<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Versioned URLs for module static assets.
 *
 * getUrl('js/ui-core.js', 'platform-ui') → '/assets/platform-ui/js/ui-core.js
 * ?v=<sha256-12hex>' — the fingerprint makes the URL the cache key, which is
 * what lets StaticAssetHandler answer it with a one-year `immutable`
 * Cache-Control (unversioned URLs get must-revalidate + ETag instead). Twig
 * exposes this as `asset(path, module)`; hardcoding a raw `/assets/...` URL
 * in a template forfeits immutable caching and risks pinning stale copies.
 */
final class AssetManager
{
    private static string $publicPath = '/assets';
    private static array $moduleVersions = [];
    /**
     * @var array<string, array{mtime:int,size:int,fingerprint:string}>
     */
    private static array $fingerprintCache = [];

    public static function getUrl(string $path, ?string $module = null): string
    {
        $module = $module ?? self::detectCurrentModule();

        $url = self::$publicPath . "/{$module}/" . ltrim($path, '/');
        $version = self::getAssetFingerprint($module, $path);

        return $url . '?v=' . rawurlencode($version);
    }

    public static function reset(): void
    {
        self::$moduleVersions = [];
        self::$fingerprintCache = [];
    }

    private static function getVersion(string $module): string
    {
        if (isset(self::$moduleVersions[$module])) {
            return self::$moduleVersions[$module];
        }

        $version = self::getBuildVersion();
        self::$moduleVersions[$module] = $version;

        return $version;
    }

    private static function getBuildVersion(): string
    {
        foreach (['SEMITEXA_ASSET_VERSION', 'SEMITEXA_RELEASE_VERSION', 'APP_VERSION'] as $envKey) {
            $value = Environment::getEnvValue($envKey);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $lockPath = ProjectRoot::get() . '/composer.lock';
        if (is_file($lockPath)) {
            $hash = hash_file('sha256', $lockPath);
            if ($hash !== false) {
                return substr($hash, 0, 12);
            }
        }

        return '1';
    }

    private static function getAssetFingerprint(string $module, string $path): string
    {
        try {
            $resolved = ModuleAssetRegistry::resolve($module, $path);
        } catch (\LogicException) {
            return self::getVersion($module);
        }

        if ($resolved === null) {
            return self::getVersion($module);
        }

        clearstatcache(true, $resolved);
        $mtime = @filemtime($resolved) ?: 0;
        $size = @filesize($resolved) ?: 0;
        $cached = self::$fingerprintCache[$resolved] ?? null;
        if ($cached !== null && $cached['mtime'] === $mtime && $cached['size'] === $size) {
            return $cached['fingerprint'];
        }

        if (!is_readable($resolved)) {
            return self::getVersion($module);
        }

        $hash = @hash_file('sha256', $resolved);
        if ($hash === false) {
            return self::getVersion($module);
        }

        $fingerprint = substr($hash, 0, 12);
        self::$fingerprintCache[$resolved] = [
            'mtime' => $mtime,
            'size' => $size,
            'fingerprint' => $fingerprint,
        ];

        return $fingerprint;
    }

    private static function detectCurrentModule(): string
    {
        return 'app';
    }
}
