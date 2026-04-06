<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Asset;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;

final class AssetManager
{
    private static ?array $manifest = null;
    private static string $publicPath = '/assets';
    private static array $moduleVersions = [];
    /**
     * @var array<string, array{mtime:int,size:int,fingerprint:string}>
     */
    private static array $fingerprintCache = [];

    public static function getUrl(string $path, ?string $module = null): string
    {
        $module = $module ?? self::detectCurrentModule();

        $hashed = self::getManifestPath($path, $module);
        if ($hashed) {
            return self::$publicPath . "/{$module}/{$hashed}";
        }

        $url = self::$publicPath . "/{$module}/" . ltrim($path, '/');
        $version = self::getAssetFingerprint($module, $path);

        return $url . '?v=' . rawurlencode($version);
    }

    public static function reset(): void
    {
        self::$manifest = null;
        self::$moduleVersions = [];
        self::$fingerprintCache = [];
    }

    public static function mix(string $path): string
    {
        $manifest = self::getManifest();
        
        if (isset($manifest[$path])) {
            return '/build/' . $manifest[$path];
        }

        return $path;
    }

    public static function version(string $path): string
    {
        $module = self::detectCurrentModule();
        $hashed = self::getManifestPath($path, $module);
        if ($hashed) {
            return '/' . ltrim($hashed, '/');
        }

        $path = ltrim($path, '/');
        return '/' . $path . '?v=' . rawurlencode(self::getBuildVersion());
    }

    /** @return array<string, string> */
    private static function getManifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $manifestPath = ProjectRoot::get() . '/public/mix-manifest.json';
        
        if (file_exists($manifestPath)) {
            self::$manifest = json_decode(file_get_contents($manifestPath), true) ?? [];
        } else {
            self::$manifest = [];
        }

        return self::$manifest;
    }

    private static function getManifestPath(string $path, string $module): ?string
    {
        $manifest = self::getManifest();
        
        if (isset($manifest[$path])) {
            return $manifest[$path];
        }

        $modulePath = "{$module}/{$path}";
        if (isset($manifest[$modulePath])) {
            return $manifest[$modulePath];
        }

        return null;
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
