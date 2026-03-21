<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Layout;

use Semitexa\Ssr\Http\Response\HtmlSlotResponse;

/**
 * Collects clientModules from slot resources and registers them with the asset pipeline.
 *
 * clientModules use the format: @project-static-{ModuleName}/{path}.js
 * This is converted to the canonical asset key: {ModuleName}:js:{path-without-extension}
 */
final class SlotAssetCollector
{
    public static function collectFromSlot(HtmlSlotResponse $slot): void
    {
        self::collectModuleRefs($slot->getClientModules());
    }

    public static function collectModuleRefs(array $modules): void
    {
        if ($modules === []) {
            return;
        }

        if (!class_exists(\Semitexa\Ssr\Asset\AssetCollectorStore::class)) {
            return;
        }

        \Semitexa\Ssr\Asset\AssetCollector::boot();
        $collector = \Semitexa\Ssr\Asset\AssetCollectorStore::get();

        foreach ($modules as $moduleRef) {
            $key = self::convertModuleRef($moduleRef);
            if ($key === null) {
                continue;
            }
            try {
                $collector->require($key);
            } catch (\Throwable) {
                // Unknown key — not in manifest, skip silently
            }
        }
    }

    /**
     * Convert @project-static-{ModuleName}/{path}.js to {ModuleName}:js:{path-without-ext}
     */
    public static function convertModuleRef(string $ref): ?string
    {
        if (!preg_match('#^@project-static-([^/]+)/(.+)\.js$#', $ref, $m)) {
            return null;
        }

        return $m[1] . ':js:' . $m[2];
    }
}
