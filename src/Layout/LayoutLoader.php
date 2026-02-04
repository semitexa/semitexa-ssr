<?php

declare(strict_types=1);

namespace Semitexa\Frontend\Layout;

class LayoutLoader
{
    /**
     * Locate an active (project) layout template for the given handle.
     *
     * @return array{template:string,module:string,path:string}|null
     */
    public static function loadHandle(string $handle): ?array
    {
        $projectLayout = self::findProjectLayout($handle);
        if ($projectLayout !== null) {
            return $projectLayout;
        }

        error_log("Layout '{$handle}' is not activated. Run 'bin/semitexa layout:generate {$handle}' to copy it into src/.");
        return null;
    }

    private static function findProjectLayout(string $handle): ?array
    {
        $modulesRoot = self::getProjectRoot() . '/src/modules';
        if (!is_dir($modulesRoot)) {
            return null;
        }

        $moduleDirs = glob($modulesRoot . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($moduleDirs as $moduleDir) {
            $module = basename($moduleDir);

            // 1. Primary: Standard Module Layout — Application/View/templates/
            $templatesDir = $moduleDir . '/src/Application/View/templates';
            if (is_dir($templatesDir)) {
                $found = self::findTemplateInDir($templatesDir, $handle);
                if ($found !== null) {
                    [$path, $relativePath] = $found;
                    $alias = self::aliasForModule($module);
                    return [
                        'template' => "@{$alias}/" . $relativePath,
                        'module' => $module,
                        'path' => $path,
                    ];
                }
            }

            // 2. Fallback: legacy Layout/ at module root (backward compatibility)
            $layoutFile = $moduleDir . '/Layout/' . $handle . '.html.twig';
            if (is_file($layoutFile)) {
                $alias = self::aliasForModule($module);
                return [
                    'template' => "@{$alias}/" . basename($layoutFile),
                    'module' => $module,
                    'path' => $layoutFile,
                ];
            }
        }

        return null;
    }

    /**
     * Look for {handle}.html.twig in directory, then in any subdirectory (one level: category/).
     *
     * @return array{0: string, 1: string}|null [fullPath, relativePath] or null
     */
    private static function findTemplateInDir(string $dir, string $handle): ?array
    {
        $direct = $dir . '/' . $handle . '.html.twig';
        if (is_file($direct)) {
            return [$direct, $handle . '.html.twig'];
        }
        $subdirs = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($subdirs as $subdir) {
            $file = $subdir . '/' . $handle . '.html.twig';
            if (is_file($file)) {
                $relativePath = basename($subdir) . '/' . $handle . '.html.twig';
                return [$file, $relativePath];
            }
        }
        return null;
    }

    private static function aliasForModule(string $module): string
    {
        return 'project-layouts-' . $module;
    }

    /**
     * Project root (where composer.json and src/modules/ live). Public so TwigFactory can use the same root for namespace resolution.
     * Does not rely on fixed path depth so it works for both vendor/semitexa/core-frontend and vendor/semitexa/module-core-frontend.
     */
    public static function getProjectRoot(): string
    {
        static $root = null;
        if ($root !== null) {
            return $root;
        }

        // 1. Try known roots (Docker app at /var/www/html, or CWD when running from project)
        $candidates = ['/var/www/html'];
        $cwd = getcwd();
        if ($cwd !== false && $cwd !== '') {
            $candidates[] = $cwd;
        }
        foreach ($candidates as $dir) {
            if (is_file($dir . '/composer.json') && is_dir($dir . '/src/modules')) {
                $root = $dir;
                return $root;
            }
        }

        // 2. Walk up from this file until we find composer.json + src/modules (works for any vendor path depth)
        $dir = __DIR__;
        while ($dir !== '/' && $dir !== '') {
            if (is_file($dir . '/composer.json') && is_dir($dir . '/src/modules')) {
                $root = $dir;
                return $root;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        // Last resort: /var/www/html only when it is a valid project root (Docker)
        $dockerRoot = '/var/www/html';
        if (is_file($dockerRoot . '/composer.json') && is_dir($dockerRoot . '/src/modules')) {
            $root = $dockerRoot;
            return $root;
        }

        $root = $dockerRoot;
        return $root;
    }
}