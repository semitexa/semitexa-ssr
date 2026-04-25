<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Asset;

use Semitexa\Core\Environment;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Maps module aliases to their Application/Static/ directories for asset serving.
 *
 * Resolution order for every asset path:
 *   1. src/theme/{active-chain-theme}/{module}/Static/{path}
 *      (per-request chain override, leaf first, if a resolver is bound)
 *   2. src/theme/{THEME}/{module}/Static/{path}
 *      (legacy boot-time environment override)
 *   3. {module}/Application/Static/{path}
 *      (module default)
 *
 * Legacy THEME paths are resolved once at boot. Per-request theme chains are
 * resolved on every lookup through setChainResolver().
 */
class ModuleAssetRegistry
{
    private const ALLOWED_EXTENSIONS = [
        'js', 'css', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'ico',
        'woff2', 'woff', 'map',
        // .twig is reserved for SSR-published templates in public/assets/ssr/tpl (served as text/plain).
        // Do not publish secrets in these templates.
        'twig',
    ];

    /** @var array<string, string[]> module name/alias → list of absolute base dirs (searched in order) */
    private static array $map = [];

    /** @var array<string, string> module name/alias → absolute theme Static/ dir (optional) */
    private static array $themeMap = [];

    /**
     * Per-request active theme chain resolver. When set, `resolve()` walks the
     * chain (leaf first) checking `src/theme/<theme>/<module>/Static/<path>`
     * for each theme before falling back to `$themeMap` (boot-time env THEME
     * single-theme override) and then to the registered module base dirs.
     *
     * @var \Closure(): list<string>|null
     */
    private static ?\Closure $chainResolver = null;

    private static bool $initialized = false;
    private static ?ModuleRegistry $moduleRegistry = null;

    public static function setChainResolver(?\Closure $resolver): void
    {
        self::$chainResolver = $resolver;
    }

    public static function setModuleRegistry(ModuleRegistry $moduleRegistry): void
    {
        self::$moduleRegistry = $moduleRegistry;
    }

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        if (self::$moduleRegistry === null) {
            throw new \LogicException('ModuleAssetRegistry requires ModuleRegistry instance. Call setModuleRegistry() first.');
        }

        $activeTheme = self::resolveActiveTheme();
        $themeRoot   = $activeTheme !== '' ? ProjectRoot::get() . '/src/theme/' . $activeTheme : null;

        foreach (self::$moduleRegistry->getModules() as $module) {
            $modulePath = $module['path'];

            // Locate Application/Static/ — check src/ first (PSR-4 packages), then bare (local modules)
            $staticDirCandidates = [
                $modulePath . '/src/Application/Static',
                $modulePath . '/Application/Static',
            ];

            $staticDir = null;
            foreach ($staticDirCandidates as $candidate) {
                if (is_dir($candidate)) {
                    $staticDir = realpath($candidate) ?: $candidate;
                    break;
                }
            }

            if ($staticDir === null) {
                continue;
            }

            foreach ($module['aliases'] as $alias) {
                self::$map[$alias] = [$staticDir];
            }
            self::$map[$module['name']] = [$staticDir];

            // Resolve theme override directory for this module
            if ($themeRoot !== null) {
                $moduleThemeStatic = $themeRoot . '/' . $module['name'] . '/Static';
                if (is_dir($moduleThemeStatic)) {
                    $realThemeStatic = realpath($moduleThemeStatic) ?: $moduleThemeStatic;
                    foreach ($module['aliases'] as $alias) {
                        self::$themeMap[$alias] = $realThemeStatic;
                    }
                    self::$themeMap[$module['name']] = $realThemeStatic;
                }
            }
        }

        self::$initialized = true;
    }

    public static function reset(): void
    {
        self::$map = [];
        self::$themeMap = [];
        self::$initialized = false;
        self::$moduleRegistry = null;
        self::$chainResolver = null;
    }

    /**
     * Register a custom alias pointing to an absolute directory path.
     * If the alias already has base directories registered, the new path is prepended
     * (highest priority). Used for virtual modules (e.g., 'ssr' for compiled template assets).
     */
    public static function registerAlias(string $alias, string $absolutePath): void
    {
        if (!self::$initialized) {
            self::initialize();
        }
        $realPath = realpath($absolutePath);
        if ($realPath !== false && is_dir($realPath)) {
            $existing = self::$map[$alias] ?? [];
            if (!in_array($realPath, $existing, true)) {
                array_unshift($existing, $realPath);
            }
            self::$map[$alias] = $existing;
            self::$initialized = true;
        }
    }

    /**
     * Resolve a module asset path to an absolute file path.
     *
     * Resolution order:
     *   1. Theme override directory (if THEME is set and overrides exist)
     *   2. Registered base directories in priority order (first registered wins if found)
     *
     * @return string|null Absolute file path, or null if invalid/not found
     */
    public static function resolve(string $module, string $path): ?string
    {
        if (!self::$initialized) {
            self::initialize();
        }

        $baseDirs = self::$map[$module] ?? null;
        if ($baseDirs === null) {
            return null;
        }

        if (!self::isPathSafe($path)) {
            return null;
        }

        // Per-request theme chain takes priority over boot-time env THEME.
        // Walks leaf → root; first existing file wins. Falls through to
        // legacy $themeMap + base dirs when no chain override matches.
        if (self::$chainResolver !== null) {
            $chain = self::normalizeChain((self::$chainResolver)());
            if ($chain !== []) {
                $projectRoot = ProjectRoot::get();
                foreach ($chain as $themeId) {
                    $base = $projectRoot . '/src/theme/' . $themeId . '/' . $module . '/Static';
                    $realBase = realpath($base);
                    $themeFile = $base . '/' . $path;
                    $realTheme = realpath($themeFile);
                    if ($realBase !== false && $realTheme !== false && str_starts_with($realTheme, $realBase . '/') && is_file($realTheme)) {
                        return $realTheme;
                    }
                }
            }
        }

        // Legacy theme override (boot-time env THEME)
        if (isset(self::$themeMap[$module])) {
            $themeFile = self::$themeMap[$module] . '/' . $path;
            $realTheme = realpath($themeFile);
            if ($realTheme !== false && str_starts_with($realTheme, self::$themeMap[$module] . '/') && is_file($realTheme)) {
                return $realTheme;
            }
        }

        // Search all registered base directories in priority order
        foreach ($baseDirs as $staticDir) {
            $filePath     = $staticDir . '/' . $path;
            $realFilePath = realpath($filePath);
            if ($realFilePath !== false && str_starts_with($realFilePath, $staticDir . '/') && is_file($realFilePath)) {
                return $realFilePath;
            }
        }

        return null;
    }

    private static function isPathSafe(string $path): bool
    {
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    private static function resolveActiveTheme(): string
    {
        try {
            $env = Environment::create();
            return $env->get('THEME', '');
        } catch (\Throwable) {
            // Environment may not be available in all contexts — fall back to no theme
            return '';
        }
    }

    /**
     * @param mixed $chain
     * @return list<string>
     */
    private static function normalizeChain(mixed $chain): array
    {
        return is_array($chain) ? array_values(array_filter($chain, 'is_string')) : [];
    }

}
