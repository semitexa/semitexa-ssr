<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Isomorphic;

use Semitexa\Core\Util\ProjectRoot;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;

final class DeferredTemplateRegistry
{
    /** @var array<string, string> slot_id => public URL path (e.g. /assets/ssr/tpl/sidebar.a1b2c3.twig) */
    private static array $publishedPaths = [];

    private static bool $initialized = false;

    public static function initialize(?IsomorphicConfig $config = null, ?string $tenantId = null): void
    {
        $config ??= IsomorphicConfig::fromEnvironment();

        if (!$config->enabled) {
            return;
        }

        self::$publishedPaths = [];

        $deferredSlots = LayoutSlotRegistry::getAllDeferredSlots();
        $projectRoot = ProjectRoot::get();
        $basePath = rtrim($config->templateAssetsPath, '/');

        $outputDir = $projectRoot . '/' . $basePath;
        if ($tenantId !== null && $tenantId !== '') {
            $outputDir .= '/' . $tenantId;
        }

        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0755, true);
        }

        foreach ($deferredSlots as $slot) {
            if ($slot->mode !== 'template') {
                continue;
            }

            $templatePath = self::resolveTemplatePath($slot->templateName);
            if ($templatePath === null) {
                continue;
            }

            $content = file_get_contents($templatePath);
            if ($content === false) {
                continue;
            }

            $hash = substr(md5($content), 0, 8);
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $slot->slotId);
            $filename = "{$safeName}.{$hash}.twig";

            $outputFile = $outputDir . '/' . $filename;
            file_put_contents($outputFile, $content);

            $urlBase = '/assets/ssr/tpl';
            if ($tenantId !== null && $tenantId !== '') {
                $urlBase .= '/' . $tenantId;
            }
            self::$publishedPaths[$slot->slotId] = $urlBase . '/' . $filename;
        }

        self::$initialized = true;
    }

    public static function getPublishedPath(string $slotId): ?string
    {
        return self::$publishedPaths[$slotId] ?? null;
    }

    /**
     * @return array<string, string> slot_id => public URL path
     */
    public static function getAllPublishedPaths(): array
    {
        return self::$publishedPaths;
    }

    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    private static function resolveTemplatePath(string $templateName): ?string
    {
        try {
            $loader = ModuleTemplateRegistry::getLoader();
            $source = $loader->getSourceContext($templateName);
            return $source->getPath();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function reset(): void
    {
        self::$publishedPaths = [];
        self::$initialized = false;
    }
}
