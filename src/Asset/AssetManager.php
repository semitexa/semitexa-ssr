<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Asset;

use Semitexa\Core\Environment;
use Semitexa\Core\Util\ProjectRoot;

final class AssetManager
{
    private static ?array $manifest = null;
    private static string $publicPath = '/static';
    private static array $moduleVersions = [];

    public static function getUrl(string $path, ?string $module = null): string
    {
        $module = $module ?? self::detectCurrentModule();
        $version = self::getVersion($module);

        $hashed = self::getManifestPath($path, $module);
        if ($hashed) {
            return self::$publicPath . "/{$module}/{$hashed}";
        }

        return self::$publicPath . "/{$module}/{$path}?v={$version}";
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
        $version = self::getVersion($module);
        
        $path = ltrim($path, '/');
        return "/{$path}?v={$version}";
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

        $version = '1.0.0';
        
        self::$moduleVersions[$module] = $version;
        
        return $version;
    }

    private static function detectCurrentModule(): string
    {
        return 'app';
    }
}
