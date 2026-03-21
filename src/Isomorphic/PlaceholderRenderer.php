<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Isomorphic;

use Semitexa\Ssr\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;

final class PlaceholderRenderer
{
    /**
     * Generate skeleton placeholder HTML for a deferred slot.
     */
    public static function renderPlaceholder(DeferredSlotDefinition $slot): string
    {
        $skeletonHtml = '';

        if ($slot->skeletonTemplate !== null && $slot->skeletonTemplate !== '') {
            try {
                $twig = ModuleTemplateRegistry::getTwig();
                $skeletonHtml = $twig->render($slot->skeletonTemplate, [
                    'slot_id' => $slot->slotId,
                ]);
            } catch (\Throwable) {
                $skeletonHtml = self::defaultSkeleton($slot->slotId);
            }
        } else {
            $skeletonHtml = self::defaultSkeleton($slot->slotId);
        }

        $slotIdEscaped = htmlspecialchars($slot->slotId, ENT_QUOTES, 'UTF-8');

        return '<div data-ssr-deferred="' . $slotIdEscaped . '">'
            . $skeletonHtml
            . '</div>';
    }

    /**
     * Generate <link rel="preload"> hints for template-mode deferred slots.
     *
     * @param DeferredSlotDefinition[] $slots
     */
    public static function renderPreloadHints(array $slots): string
    {
        $html = '';

        foreach ($slots as $slot) {
            if ($slot->mode !== 'template') {
                continue;
            }

            $publishedPath = DeferredTemplateRegistry::getPublishedPath($slot->slotId, $slot->pageHandle);
            if ($publishedPath === null) {
                continue;
            }

            $pathEscaped = htmlspecialchars($publishedPath, ENT_QUOTES, 'UTF-8');
            $html .= '<link rel="preload" href="' . $pathEscaped . '" as="fetch" crossorigin>' . "\n";
        }

        return $html;
    }

    /**
     * Generate the __SSR_DEFERRED manifest script block.
     *
     * @param DeferredSlotDefinition[] $slots
     */
    public static function renderManifest(
        string $requestId,
        string $sessionId,
        array $slots,
    ): string {
        $slotManifest = [];
        foreach ($slots as $slot) {
            $entry = [
                'id' => $slot->slotId,
                'mode' => $slot->mode,
                'priority' => $slot->priority,
            ];

            if ($slot->mode === 'template') {
                $publishedPath = DeferredTemplateRegistry::getPublishedPath($slot->slotId, $slot->pageHandle);
                if ($publishedPath !== null) {
                    $entry['template'] = $publishedPath;
                }
            }

            if ($slot->cacheTtl > 0) {
                $entry['cache_ttl'] = $slot->cacheTtl;
            }

            $slotManifest[] = $entry;
        }

        $manifest = [
            'requestId' => $requestId,
            'sessionId' => $sessionId,
            'slots' => $slotManifest,
        ];

        try {
            $json = json_encode(
                $manifest,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            // Log the error and fall back to a minimal, valid manifest to avoid breaking client initialization.
            error_log('Failed to JSON-encode SSR deferred manifest: ' . $e->getMessage());
            $json = '{"requestId":"","sessionId":"","slots":[]}';
        }

        return '<script>window.__SSR_DEFERRED=' . $json . ';</script>';
    }

    /**
     * Generate the <script defer> tag for the semitexa-twig.js runtime.
     *
     * Served via the standard static asset path. The ?v= query parameter
     * provides cache-busting based on file mtime.
     */
    public static function renderRuntimeScript(): string
    {
        ModuleAssetRegistry::initialize();
        $path = ModuleAssetRegistry::resolve('ssr', 'js/semitexa-twig.js')
            ?? __DIR__ . '/../Application/Static/js/semitexa-twig.js';
        $version = @filemtime($path) ?: 0;
        return '<script src="/assets/ssr/js/semitexa-twig.js?v=' . $version . '" defer></script>';
    }

    private static function defaultSkeleton(string $slotId): string
    {
        $safeId = htmlspecialchars($slotId, ENT_QUOTES, 'UTF-8');
        return '<div class="ssr-skeleton" aria-busy="true" aria-label="Loading ' . $safeId . '"></div>';
    }
}
