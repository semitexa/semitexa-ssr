<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Asset;

use Semitexa\Core\ModuleRegistry;

class ModuleAssetRegistry
{
    private const ALLOWED_EXTENSIONS = [
        'js', 'css', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'ico',
        'woff2', 'woff', 'map',
        // .twig is reserved for SSR-published templates in public/assets/ssr/tpl (served as text/plain).
        // Do not publish secrets in these templates.
        'twig',
    ];

    /** @var array<string, string> module name/alias → absolute resources dir */
    private static array $map = [];

    private static bool $initialized = false;

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        foreach (ModuleRegistry::getModules() as $module) {
            $resourcesDir = $module['path'] . '/resources';
            if (!is_dir($resourcesDir)) {
                continue;
            }

            $realResourcesDir = realpath($resourcesDir);
            if ($realResourcesDir === false) {
                continue;
            }

            foreach ($module['aliases'] as $alias) {
                self::$map[$alias] = $realResourcesDir;
            }
            // Also register by name (may already be in aliases, but ensure it)
            self::$map[$module['name']] = $realResourcesDir;
        }

        self::$initialized = true;
    }

    /**
     * Register a custom alias pointing to an absolute directory path.
     * Used for virtual modules (e.g., 'ssr' for compiled template assets).
     */
    public static function registerAlias(string $alias, string $absolutePath): void
    {
        if (!self::$initialized) {
            self::initialize();
        }
        $realPath = realpath($absolutePath);
        if ($realPath !== false && is_dir($realPath)) {
            self::$map[$alias] = $realPath;
            self::$initialized = true;
        }
    }

    /**
     * Resolve a module asset path to an absolute file path.
     *
     * @return string|null Absolute file path, or null if invalid/not found
     */
    public static function resolve(string $module, string $path): ?string
    {
        if (!self::$initialized) {
            return null;
        }

        $resourcesDir = self::$map[$module] ?? null;
        if ($resourcesDir === null) {
            return null;
        }

        // Path traversal protection
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            return null;
        }

        // Extension whitelist
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        $filePath = $resourcesDir . '/' . $path;
        $realFilePath = realpath($filePath);

        // Must exist and must be within the resources directory
        if ($realFilePath === false || !str_starts_with($realFilePath, $resourcesDir . '/')) {
            return null;
        }

        return $realFilePath;
    }
}
