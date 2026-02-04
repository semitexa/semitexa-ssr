<?php

declare(strict_types=1);

namespace Semitexa\Frontend\View;

use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Environment;
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

        foreach (self::discoverProjectLayoutPaths() as $module => $path) {
            $loader->addPath($path, self::layoutAlias($module));
        }

        $cacheDir = self::getCacheDir();
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
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
        return LayoutLoader::getProjectRoot() . '/var/cache/twig';
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
        $projectRoot = LayoutLoader::getProjectRoot();
        $modulesRoot = $projectRoot . '/src/modules';
        if (!is_dir($modulesRoot)) {
            return [];
        }

        $paths = [];
        $moduleDirs = glob($modulesRoot . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($moduleDirs as $moduleDir) {
            $module = basename($moduleDir);
            $templatesDir = $moduleDir . '/src/Application/View/templates';
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
                    $handle = $context['layout_handle'] ?? null;
                    if (!$handle) {
                        return '';
                    }

                    return LayoutSlotRegistry::render($handle, $slot, $context, $extraContext);
                },
                ['needs_context' => true, 'is_safe' => ['html']]
            ));
        }
    }
}


