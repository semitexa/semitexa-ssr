<?php

declare(strict_types=1);

namespace Semitexa\Frontend\View;

use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Environment;
use Semitexa\Core\Util\ProjectRoot;
use Semitexa\Frontend\Layout\LayoutLoader;
use Semitexa\Frontend\Layout\LayoutSlotRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class TwigFactory
{
    private static ?TwigEnvironment $twig = null;

    /**
     * Reset Twig instance (useful for testing or after module registration)
     */
    public static function reset(): void
    {
        self::$twig = null;
    }

    public static function get(): TwigEnvironment
    {
        if (self::$twig instanceof TwigEnvironment) {
            return self::$twig;
        }

        $loader = new FilesystemLoader();

        $modules = ModuleRegistry::getModules();
        $env = Environment::create();
        $activeTheme = $env->get('THEME', '');

        // Register themes first (override), then regular modules
        $themes = array_filter($modules, fn($m) => ($m['composerType'] ?? '') === 'semitexa-theme');
        $others = array_filter($modules, fn($m) => ($m['composerType'] ?? '') !== 'semitexa-theme');

        // If active theme specified, keep only matching themes (by alias or name)
        if ($activeTheme !== '') {
            $themes = array_filter($themes, function($m) use ($activeTheme) {
                $aliases = $m['aliases'] ?? [];
                return in_array($activeTheme, $aliases, true) || ($m['name'] ?? '') === $activeTheme;
            });
        }

        $ordered = array_merge($themes, $others);

        foreach ($ordered as $module) {
            $paths = $module['templatePaths'] ?? [];
            $aliases = $module['aliases'] ?? [$module['name']];
            foreach ($paths as $p) {
                if (!is_dir($p)) { continue; }
                foreach ($aliases as $alias) {
                    $loader->addPath($p, (string)$alias);
                }
            }
        }

        // Theme overrides first (so Twig finds them first), then project module templates as fallback
        $projectPaths = self::discoverProjectLayoutPaths();
        $themePaths = self::discoverThemePaths($projectPaths, $activeTheme);
        foreach ($themePaths as $module => $themePath) {
            $loader->addPath($themePath, self::layoutAlias($module));
        }
        foreach ($projectPaths as $module => $path) {
            $loader->addPath($path, self::layoutAlias($module));
        }

        $cacheDir = self::getWritableCacheDir();
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        self::$twig = new TwigEnvironment($loader, [
            'cache' => $cacheDir,
            'auto_reload' => true,
            'strict_variables' => false,
        ]);

        self::registerFunctions();

        return self::$twig;
    }

    private static function getCacheDir(): string
    {
        return ProjectRoot::get() . '/var/cache/twig';
    }

    /**
     * Return a Twig cache directory that is writable (project var/cache/twig or fallback to system temp).
     * Uses 0755; when Swoole runs in Docker as root, run cache:clear via Docker (--via-docker or auto-fallback).
     */
    private static function getWritableCacheDir(): string
    {
        $cacheDir = self::getCacheDir();
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            return $cacheDir;
        }
        $fallback = sys_get_temp_dir() . '/semitexa-twig-cache';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0755, true);
        }
        return $fallback;
    }

    /**
     * Discover template paths per module for Twig alias project-layouts-{Module}.
     * When Application/View/templates/ exists (Standard Module Layout), use it.
     * When both Application/View/templates/ and Layout/ exist, use Application/View/templates/ so that
     * @project-layouts-{Module}/layout/base.html.twig resolves correctly. Fallback to Layout/ only when
     * Application/View/templates/ does not exist. Uses same project root as LayoutLoader for consistency.
     */
    private static function discoverProjectLayoutPaths(): array
    {
        $projectRoot = ProjectRoot::get();
        $modulesRoot = $projectRoot . '/src/modules';
        if (!is_dir($modulesRoot)) {
            return [];
        }

        $paths = [];
        $moduleDirs = glob($modulesRoot . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($moduleDirs as $moduleDir) {
            $module = basename($moduleDir);
            $templatesDir = $moduleDir . '/Application/View/templates';
            $layoutDir = $moduleDir . '/Layout';

            // Prefer Application/View/templates/ when it exists (including when both exist)
            if (is_dir($templatesDir)) {
                $paths[$module] = realpath($templatesDir) ?: $templatesDir;
            } elseif (is_dir($layoutDir)) {
                $paths[$module] = realpath($layoutDir) ?: $layoutDir;
            }
        }

        return $paths;
    }

    private static function layoutAlias(string $module): string
    {
        return 'project-layouts-' . $module;
    }

    /**
     * Discover theme override paths for layout alias project-layouts-{Module}.
     * When THEME is set in .env: src/theme/{ThemeName}/{ModuleName}/ (one theme, any module).
     * When THEME is empty: legacy src/theme/{ModuleName}/ (flat; each dir = one module override).
     * Only paths that exist are returned; theme is registered first in the loader so it overrides module templates.
     *
     * @param array<string, string> $projectPaths module => path (from discoverProjectLayoutPaths)
     * @param string $activeTheme THEME from .env (e.g. "default", "custom") or empty for legacy
     * @return array<string, string> module => absolute theme path
     */
    private static function discoverThemePaths(array $projectPaths, string $activeTheme): array
    {
        $projectRoot = ProjectRoot::get();
        $themeRoot = $projectRoot . '/src/theme';
        if (!is_dir($themeRoot)) {
            return [];
        }

        $paths = [];

        if ($activeTheme !== '') {
            $themeDir = $themeRoot . '/' . $activeTheme;
            if (!is_dir($themeDir)) {
                return [];
            }
            foreach (array_keys($projectPaths) as $module) {
                $moduleThemePath = $themeDir . '/' . $module;
                if (is_dir($moduleThemePath)) {
                    $paths[$module] = realpath($moduleThemePath) ?: $moduleThemePath;
                }
            }
        } else {
            $dirs = glob($themeRoot . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($dirs as $dir) {
                $module = basename($dir);
                if (isset($projectPaths[$module])) {
                    $paths[$module] = realpath($dir) ?: $dir;
                }
            }
        }

        return $paths;
    }

    private static function registerFunctions(): void
    {
        if (!(self::$twig instanceof TwigEnvironment)) {
            return;
        }

        if (class_exists(LayoutSlotRegistry::class)) {
            self::$twig->addFunction(new TwigFunction(
                'layout_slot',
                /**
                 * @param array<string, mixed> $context
                 */
                function (array $context, string $slot, array $extraContext = []): string {
                    $pageHandle = $context['page_handle'] ?? $context['layout_handle'] ?? null;
                    if ($pageHandle === null || $pageHandle === '') {
                        return '';
                    }
                    $layoutFrame = $context['layout_frame'] ?? null;
                    return LayoutSlotRegistry::render($pageHandle, $slot, $context, $extraContext, $layoutFrame);
                },
                ['needs_context' => true, 'is_safe' => ['html']]
            ));
        }
    }
}


