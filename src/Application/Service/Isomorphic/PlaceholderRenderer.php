<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Isomorphic;

use Semitexa\Ssr\Application\Service\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;

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
     * Generate skeleton placeholder HTML for a deferred component
     * (component using #[WithTransport(Sse, deferred: true)]).
     *
     * Mirrors the slot placeholder envelope but keyed on the component
     * instance id so the client runtime can target it on Sse delivery.
     */
    public static function renderComponentPlaceholder(string $componentName, ?string $instanceId = null): string
    {
        $componentEscaped = htmlspecialchars($componentName, ENT_QUOTES, 'UTF-8');
        $instanceEscaped = htmlspecialchars($instanceId ?? '', ENT_QUOTES, 'UTF-8');
        $skeleton = self::defaultSkeleton($componentName);

        $instanceAttr = $instanceEscaped !== ''
            ? ' data-ssr-component-instance="' . $instanceEscaped . '"'
            : '';

        return '<div data-ssr-deferred-component="' . $componentEscaped . '"' . $instanceAttr . '>'
            . $skeleton
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
     * @param array<int, array{instance_id: string, name: string}> $components
     */
    public static function renderManifest(
        string $requestId,
        string $sessionId,
        array $slots,
        string $bindToken = '',
        array $components = [],
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

        $componentManifest = [];
        foreach ($components as $component) {
            $instanceId = (string) ($component['instance_id'] ?? '');
            $name = (string) ($component['name'] ?? '');
            if ($instanceId === '' || $name === '') {
                continue;
            }
            $componentManifest[] = [
                'instance_id' => $instanceId,
                'name' => $name,
            ];
        }

        $manifest = [
            'requestId' => $requestId,
            'sessionId' => $sessionId,
            'bindToken' => $bindToken,
            'slots' => $slotManifest,
            'components' => $componentManifest,
        ];

        try {
            $json = json_encode(
                $manifest,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            // Log the error and fall back to a minimal, valid manifest to avoid breaking client initialization.
            \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Failed to JSON-encode SSR deferred manifest', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $json = '{"requestId":"","sessionId":"","bindToken":"","slots":[]}';
        }

        return '<script>window.__SSR_DEFERRED=' . $json . ';</script>';
    }

    /**
     * @param DeferredSlotDefinition[] $slots
     *
     * @return DeferredSlotDefinition[]
     */
    public static function filterRenderedSlotsFromHtml(string $html, array $slots): array
    {
        if ($slots === [] || !preg_match_all('/data-ssr-deferred="([^"]+)"/', $html, $matches)) {
            return [];
        }

        $renderedIds = array_fill_keys(array_map('html_entity_decode', $matches[1]), true);

        return array_values(array_filter(
            $slots,
            static fn (DeferredSlotDefinition $slot): bool => isset($renderedIds[$slot->slotId])
        ));
    }

    /**
     * Filter the candidate component instances down to those whose placeholder
     * actually appears in the final rendered HTML.
     *
     * @param array<int|string, array{instance_id: string, name: string, props: array<array-key, mixed>}> $instances
     *
     * @return array<int, array{instance_id: string, name: string, props: array<array-key, mixed>}>
     */
    public static function filterRenderedComponentsFromHtml(string $html, array $instances): array
    {
        if ($instances === []) {
            return [];
        }

        if (!preg_match_all('/data-ssr-component-instance="([^"]+)"/', $html, $matches)) {
            return [];
        }

        $renderedIds = array_fill_keys(array_map('html_entity_decode', $matches[1]), true);

        $out = [];
        foreach ($instances as $instance) {
            $instanceId = $instance['instance_id'] ?? '';
            if ($instanceId !== '' && isset($renderedIds[$instanceId])) {
                $out[] = $instance;
            }
        }
        return $out;
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
        return '<script src="/assets/ssr/js/semitexa-twig.js?v=' . $version . '" defer></script>' . "\n";
    }

    /**
     * Inject $fragment into $html before the closing </body> tag when it
     * is not already present. Falls back to appending when no </body>
     * anchor exists. Empty $fragment is a no-op.
     *
     * Used by the isomorphic render finalizers so component-only deferred
     * pages still emit the manifest + runtime script even when the page
     * template forgot to print {{ __ssr_deferred_manifest|raw }} /
     * {{ __ssr_runtime_script|raw }}.
     */
    public static function injectIfMissing(string $html, string $fragment): string
    {
        if ($fragment === '' || str_contains($html, $fragment)) {
            return $html;
        }

        $pos = stripos($html, '</body>');
        if ($pos === false) {
            return $html . $fragment;
        }

        return substr($html, 0, $pos) . $fragment . substr($html, $pos);
    }

    private static function defaultSkeleton(string $slotId): string
    {
        $safeId = htmlspecialchars($slotId, ENT_QUOTES, 'UTF-8');
        return '<div class="ssr-skeleton" aria-busy="true" aria-label="Loading ' . $safeId . '"></div>';
    }
}
