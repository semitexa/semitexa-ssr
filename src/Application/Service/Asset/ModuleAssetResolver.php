<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

use Semitexa\Core\Environment;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
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
#[AsService]
final class ModuleAssetResolver
{
    private const ALLOWED_EXTENSIONS = [
        'js', 'css', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'ico',
        'woff2', 'woff', 'map',
        // .twig is reserved for SSR-published templates in public/assets/ssr/tpl (served as text/plain).
        // Do not publish secrets in these templates.
        'twig',
        // Audio: module-bundled media (e.g. semitexa-music's generated playlist).
        'ogg', 'mp3', 'm4a', 'wav',
        // Modern image formats: semitexa-media variant output defaults to webp.
        'webp', 'avif',
        // Video: module-bundled promo/hero clips served via Swoole sendfile (range-capable).
        'mp4', 'webm',
    ];

    /** @var array<string, string[]> module name/alias → list of absolute base dirs (searched in order) */
    private array $map = [];

    /** @var array<string, string> module name/alias → absolute theme Static/ dir (optional) */
    private array $themeMap = [];

    /**
     * Per-request active theme chain resolver. When set, `resolve()` walks the
     * chain (leaf first) checking `src/theme/<theme>/<module>/Static/<path>`
     * for each theme before falling back to `$themeMap` (boot-time env THEME
     * single-theme override) and then to the registered module base dirs.
     *
     * @var \Closure(): list<string>|null
     */
    private ?\Closure $chainResolver = null;

    /**
     * Resolved files, keyed by module, active chain and requested path.
     *
     * Only hits are kept. Resolution walks up to three candidate locations with
     * realpath() each, and a realpath() that fails — a theme with no override
     * for this module, which is the usual case — is not in PHP's realpath
     * cache, so every asset URL on every page paid those syscalls again:
     * MEASURED at ~15% of a dev `/` request. Which file a (module, chain, path)
     * lands on only changes when files are added or removed under a theme or
     * module Static/ dir, and the worker already treats its template tree the
     * same way (Twig's loader keeps what it found for the worker's lifetime).
     *
     * A hit is re-checked with realpath() and is_file() before it is trusted,
     * so a deleted file is not served from memory and a file replaced by a
     * symlink goes back through the containment check. Misses are never kept: a deferred
     * template is published into the `ssr` alias at runtime, and a 404 probe
     * must not be able to grow this. For the same reason the memo is capped —
     * StaticAssetHandler resolves request paths, and `a/./b.css` spellings of
     * one real file are unbounded.
     *
     * @var array<string, string>
     */
    private array $resolved = [];

    private const RESOLVED_MEMO_LIMIT = 4096;

    private bool $initialized = false;
    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    public function setChainResolver(?\Closure $resolver): void
    {
        $this->chainResolver = $resolver;
        $this->resolved = [];
    }

    public function setModuleRegistry(ModuleRegistry $moduleRegistry): void
    {
        $this->moduleRegistry = $moduleRegistry;
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        if (!isset($this->moduleRegistry)) {
            throw new \LogicException('ModuleAssetRegistry requires ModuleRegistry instance. Call setModuleRegistry() first.');
        }

        $activeTheme = $this->resolveActiveTheme();
        $themeRoot   = $activeTheme !== '' ? ProjectRoot::get() . '/src/theme/' . $activeTheme : null;

        foreach ($this->moduleRegistry->getModules() as $module) {
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
                $this->map[$alias] = [$staticDir];
            }
            $this->map[$module['name']] = [$staticDir];

            // Resolve theme override directory for this module
            if ($themeRoot !== null) {
                $moduleThemeStatic = $themeRoot . '/' . $module['name'] . '/Static';
                if (is_dir($moduleThemeStatic)) {
                    $realThemeStatic = realpath($moduleThemeStatic) ?: $moduleThemeStatic;
                    foreach ($module['aliases'] as $alias) {
                        $this->themeMap[$alias] = $realThemeStatic;
                    }
                    $this->themeMap[$module['name']] = $realThemeStatic;
                }
            }
        }

        $this->initialized = true;
    }

    public function reset(): void
    {
        $this->map = [];
        $this->themeMap = [];
        $this->initialized = false;
        unset($this->moduleRegistry);
        $this->chainResolver = null;
        $this->resolved = [];
    }

    /**
     * Register a custom alias pointing to an absolute directory path.
     * If the alias already has base directories registered, the new path is prepended
     * (highest priority). Used for virtual modules (e.g., 'ssr' for compiled template assets).
     */
    public function registerAlias(string $alias, string $absolutePath): void
    {
        if (!$this->initialized) {
            $this->initialize();
        }
        $realPath = realpath($absolutePath);
        if ($realPath !== false && is_dir($realPath)) {
            $existing = $this->map[$alias] ?? [];
            if (!in_array($realPath, $existing, true)) {
                array_unshift($existing, $realPath);
            }
            $this->map[$alias] = $existing;
            $this->initialized = true;
            $this->resolved = [];
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
    public function resolve(string $module, string $path): ?string
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $baseDirs = $this->map[$module] ?? null;
        if ($baseDirs === null) {
            return null;
        }

        if (!$this->isPathSafe($path)) {
            return null;
        }

        $chain = $this->chainResolver !== null ? $this->normalizeChain(($this->chainResolver)()) : [];
        $memoKey = $module . "\0" . implode("\0", $chain) . "\0\0" . $path;
        $memo = $this->resolved[$memoKey] ?? null;
        if ($memo !== null) {
            // The memo holds a canonical path that already passed locate()'s
            // containment check; if it no longer canonicalises to itself (the
            // file was swapped for a symlink, say), check it all again.
            if (realpath($memo) === $memo && is_file($memo)) {
                return $memo;
            }
            unset($this->resolved[$memoKey]);
        }

        $found = $this->locate($module, $path, $baseDirs, $chain);
        if ($found !== null && count($this->resolved) < self::RESOLVED_MEMO_LIMIT) {
            $this->resolved[$memoKey] = $found;
        }

        return $found;
    }

    /**
     * @param string[] $baseDirs
     * @param list<string> $chain
     */
    private function locate(string $module, string $path, array $baseDirs, array $chain): ?string
    {
        // Per-request theme chain takes priority over boot-time env THEME.
        // Walks leaf → root; first existing file wins. Falls through to
        // legacy $themeMap + base dirs when no chain override matches.
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

        // Legacy theme override (boot-time env THEME)
        if (isset($this->themeMap[$module])) {
            $themeFile = $this->themeMap[$module] . '/' . $path;
            $realTheme = realpath($themeFile);
            if ($realTheme !== false && str_starts_with($realTheme, $this->themeMap[$module] . '/') && is_file($realTheme)) {
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

    private function isPathSafe(string $path): bool
    {
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    private function resolveActiveTheme(): string
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
    private function normalizeChain(mixed $chain): array
    {
        return is_array($chain) ? array_values(array_filter($chain, 'is_string')) : [];
    }

}
