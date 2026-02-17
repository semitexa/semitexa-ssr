<?php

declare(strict_types=1);

namespace Semitexa\Frontend\Layout;

use Semitexa\Core\Util\ProjectRoot;

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
        $modulesRoot = ProjectRoot::get() . '/src/modules';
        if (!is_dir($modulesRoot)) {
            return null;
        }

        $moduleDirs = glob($modulesRoot . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($moduleDirs as $moduleDir) {
            $module = basename($moduleDir);

            // 1. Primary: Standard Module Layout — Application/View/templates/ (PSR-4: no inner src/)
            $templatesDir = $moduleDir . '/Application/View/templates';
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

}