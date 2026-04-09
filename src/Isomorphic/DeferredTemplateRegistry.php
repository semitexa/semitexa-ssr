<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Isomorphic;

use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Ssr\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Ssr\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;

final class DeferredTemplateRegistry
{
    /** @var array<string, string> handle::slot_id => public URL path (e.g. /assets/ssr/tpl/sidebar.a1b2c3.twig) */
    private static array $publishedPaths = [];

    private static bool $initialized = false;

    public static function initialize(?IsomorphicConfig $config = null, ?string $tenantId = null): void
    {
        $config ??= IsomorphicConfig::fromEnvironment();

        if (!$config->enabled) {
            return;
        }

        if ($tenantId !== null && $tenantId !== '' && !preg_match('/\A[a-zA-Z0-9_-]+\z/', $tenantId)) {
            throw new \InvalidArgumentException('Invalid tenant ID.');
        }

        self::$publishedPaths = [];

        $deferredSlots = LayoutSlotRegistry::getAllDeferredSlots();
        $projectRoot = ProjectRoot::get();
        $basePath = rtrim($config->templateAssetsPath, '/');
        $assetBasePath = rtrim(dirname($basePath), '/');

        $outputDir = $projectRoot . '/' . $basePath;
        if ($tenantId !== null && $tenantId !== '') {
            $outputDir .= '/' . $tenantId;
        }

        if (!is_dir($outputDir)) {
            $created = @mkdir($outputDir, 0755, true);
            if (!$created && !is_dir($outputDir)) {
                StaticLoggerBridge::warning('ssr', 'Deferred template publishing skipped: unable to create output directory', [
                    'directory' => $outputDir,
                ]);
                return;
            }
        }

        if ($assetBasePath !== '' && $assetBasePath !== '.' && $assetBasePath !== $basePath) {
            $assetRoot = $projectRoot . '/' . $assetBasePath;
            ModuleAssetRegistry::registerAlias('ssr', $assetRoot);
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
            if (is_file($outputFile)) {
                $existing = file_get_contents($outputFile);
                if ($existing === false) {
                    continue;
                }

                if ($existing !== $content && file_put_contents($outputFile, $content) === false) {
                    continue;
                }
            } elseif (file_put_contents($outputFile, $content) === false) {
                continue;
            }

            $publicBase = preg_replace('#^public/?#', '', ltrim($basePath, '/')) ?? $basePath;
            $urlBase = '/' . ltrim($publicBase, '/');
            if ($tenantId !== null && $tenantId !== '') {
                $urlBase .= '/' . $tenantId;
            }
            self::$publishedPaths[self::keyFor($slot->slotId, $slot->pageHandle)] = $urlBase . '/' . $filename;
        }

        self::$initialized = true;
    }

    public static function getPublishedPath(string $slotId, ?string $pageHandle = null): ?string
    {
        if ($pageHandle !== null && $pageHandle !== '') {
            return self::$publishedPaths[self::keyFor($slotId, $pageHandle)] ?? null;
        }
        $direct = self::$publishedPaths[$slotId] ?? null;
        if ($direct !== null) {
            return $direct;
        }

        $slotKey = '::' . strtolower($slotId);
        foreach (self::$publishedPaths as $key => $path) {
            if (str_ends_with($key, $slotKey)) {
                return $path;
            }
        }
        return null;
    }

    public static function ensurePublishedPath(
        string $slotId,
        string $pageHandle,
        ?IsomorphicConfig $config = null,
        ?string $tenantId = null,
    ): ?string {
        $existing = self::getPublishedPath($slotId, $pageHandle);
        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $config ??= IsomorphicConfig::fromEnvironment();
        if (!$config->enabled) {
            return null;
        }

        foreach (LayoutSlotRegistry::getDeferredSlots($pageHandle) as $slot) {
            if (
                $slot->mode !== 'template'
                || strcasecmp($slot->slotId, $slotId) !== 0
            ) {
                continue;
            }

            return self::publishSlot($slot, $config, $tenantId);
        }

        return null;
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
            // Template source resolution is best-effort — may not exist yet
            return null;
        }
    }

    public static function reset(): void
    {
        self::$publishedPaths = [];
        self::$initialized = false;
    }

    private static function keyFor(string $slotId, string $pageHandle): string
    {
        return strtolower($pageHandle) . '::' . strtolower($slotId);
    }

    private static function publishSlot(
        \Semitexa\Ssr\Domain\Model\DeferredSlotDefinition $slot,
        IsomorphicConfig $config,
        ?string $tenantId = null,
    ): ?string {
        $projectRoot = ProjectRoot::get();
        $basePath = rtrim($config->templateAssetsPath, '/');
        $assetBasePath = rtrim(dirname($basePath), '/');

        $outputDir = $projectRoot . '/' . $basePath;
        if ($tenantId !== null && $tenantId !== '') {
            $outputDir .= '/' . $tenantId;
        }

        if (!is_dir($outputDir)) {
            $created = @mkdir($outputDir, 0755, true);
            if (!$created && !is_dir($outputDir)) {
                StaticLoggerBridge::warning('ssr', 'Deferred template publishing skipped: unable to create output directory', [
                    'directory' => $outputDir,
                ]);
                return null;
            }
        }

        if ($assetBasePath !== '' && $assetBasePath !== '.' && $assetBasePath !== $basePath) {
            $assetRoot = $projectRoot . '/' . $assetBasePath;
            ModuleAssetRegistry::registerAlias('ssr', $assetRoot);
        }

        $templatePath = self::resolveTemplatePath($slot->templateName);
        if ($templatePath === null) {
            return null;
        }

        $content = file_get_contents($templatePath);
        if ($content === false) {
            return null;
        }

        $hash = substr(md5($content), 0, 8);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $slot->slotId);
        $filename = "{$safeName}.{$hash}.twig";
        $outputFile = $outputDir . '/' . $filename;

        if (is_file($outputFile)) {
            $existing = file_get_contents($outputFile);
            if ($existing === false) {
                return null;
            }

            if ($existing !== $content && file_put_contents($outputFile, $content) === false) {
                return null;
            }
        } elseif (file_put_contents($outputFile, $content) === false) {
            return null;
        }

        $publicBase = preg_replace('#^public/?#', '', ltrim($basePath, '/')) ?? $basePath;
        $urlBase = '/' . ltrim($publicBase, '/');
        if ($tenantId !== null && $tenantId !== '') {
            $urlBase .= '/' . $tenantId;
        }

        $path = $urlBase . '/' . $filename;
        self::$publishedPaths[self::keyFor($slot->slotId, $slot->pageHandle)] = $path;

        return $path;
    }
}
