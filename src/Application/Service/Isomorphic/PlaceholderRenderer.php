<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Isomorphic;

use Semitexa\Core\Http\CspNonce;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;

final class PlaceholderRenderer
{
    /**
     * Identifies the manifest data block to the client runtime.
     *
     * An attribute rather than an id: a page may legitimately carry more than
     * one (the layout finalizer replaces the marker it emitted, and the
     * fail-safe injects one when the template printed none), and duplicate ids
     * are a different bug from the one this fixes.
     */
    public const MANIFEST_ATTRIBUTE = 'data-ssr-deferred-manifest';

    /** @see self::requireSkeletonStyles() */
    private const SKELETON_CSS = <<<'CSS'
        .ssr-skeleton{min-height:3rem;border-radius:.375rem;
        background:linear-gradient(90deg,rgba(128,128,128,.10) 25%,rgba(128,128,128,.20) 37%,rgba(128,128,128,.10) 63%);
        background-size:400% 100%;animation:ssr-skeleton-sheen 1.4s ease infinite}
        @keyframes ssr-skeleton-sheen{0%{background-position:100% 50%}100%{background-position:0 50%}}
        @media (prefers-reduced-motion:reduce){.ssr-skeleton{animation:none}}
        CSS;

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
     * Generate the deferred manifest block the client runtime reads.
     *
     * A DATA block, not an executable one. It used to be
     * `<script>window.__SSR_DEFERRED={…}</script>`, which a strict
     * `script-src 'nonce-…'` refuses — and refuses silently: the skeleton
     * renders, the runtime loads (it is served from 'self'), and only the
     * browser knows that the global it subscribes through never existed.
     * `type="application/json"` is not governed by script-src at all, so the
     * manifest needs no nonce and no policy change from the host.
     *
     * The global stays the contract: {@see semitexa-twig.js} parses this block
     * into `window.__SSR_DEFERRED` before anything reads it. JSON_HEX_TAG is
     * what keeps a payload from closing the tag it sits in.
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

        return '<script type="application/json" ' . self::MANIFEST_ATTRIBUTE . '>' . $json . '</script>';
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

        // 'self' already allows a src= script, but a policy that names only a
        // nonce does not — and the runtime is the one script whose absence
        // takes every deferred slot on the page with it.
        return '<script src="/assets/ssr/js/semitexa-twig.js?v=' . $version . '" defer'
            . CspNonce::attribute() . '></script>' . "\n";
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

    /**
     * The placeholder a slot gets when it declares no skeleton of its own.
     *
     * It used to be an empty `div.ssr-skeleton` and nothing else — and nothing
     * in the framework, or in any package, ever styled that class. Measured on
     * a real consumer 2026-09-16: not one of its slots declared a
     * skeletonTemplate, so every deferred region was a zero-height invisible
     * box. The page looked like it had rendered nothing, and the deferral that
     * was supposed to buy patience bought a blank.
     *
     * So the default now brings its own styling, registered as inline CSS
     * rather than a stylesheet link: a placeholder is emitted mid-body, long
     * after the head rendered, and the dynamic-CSS seam is the one that still
     * lands. Registering the same key twice is a no-op, so a page with twenty
     * skeletons carries one copy.
     */
    private static function defaultSkeleton(string $slotId): string
    {
        self::requireSkeletonStyles();

        $safeId = htmlspecialchars($slotId, ENT_QUOTES, 'UTF-8');

        return '<div class="ssr-skeleton" aria-busy="true" aria-label="Loading ' . $safeId . '"></div>';
    }

    /**
     * Grey, rounded, gently animated, and neutral on a light or a dark page —
     * alpha-only colours so it never fights a skin, and no motion at all for a
     * visitor who asked for none.
     */
    private static function requireSkeletonStyles(): void
    {
        // The collector is per-request and created on demand, so this is safe
        // to call from anywhere a placeholder renders. Outside a request it
        // registers against a fallback collector nobody renders — the markup
        // is still correct, which is what a CLI render was asking for anyway.
        AssetCollectorStore::get()->inlineCss('ssr:skeleton', self::SKELETON_CSS, 5);
    }
}
