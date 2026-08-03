<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\Asset\AssetManager;
use Semitexa\Ssr\Application\Service\Asset\AssetRenderer;
use Semitexa\Ssr\Attribute\AsTwigExtension;
use Twig\Markup;

/**
 * Asset functions: resolving a URL for one file, and the collector pair that
 * renders whatever a page asked for into the head and the body.
 *
 * Moved out of ModuleTemplateCatalog::registerFunctions() by
 * ep-slay-template-catalog.
 *
 * ⚠️ `asset()` must keep going through {@see AssetManager::getUrl()}, which
 * fingerprints the path. A hand-written or unversioned URL is not a cosmetic
 * difference: the framework serves fingerprinted assets `immutable`, and an
 * unversioned one silently downgrades to `must-revalidate` + ETag, turning a
 * cache hit into a conditional request on every page view.
 *
 * `asset_require()` returns an empty string because it is a *statement* wearing
 * a function's clothes — templates call it for the side effect of adding a key
 * to the request's collector, and the head/body renderers emit the result later.
 */
#[AsTwigExtension]
final class AssetTwigExtension
{
    public function registerFunctions(): void
    {
        if (class_exists(AssetManager::class)) {
            TwigExtensionRegistry::registerFunction('asset', [$this, 'assetUrl']);
        }

        if (class_exists(AssetCollectorStore::class)) {
            TwigExtensionRegistry::registerFunction('asset_head', [$this, 'renderHead'], ['is_safe' => ['html']]);
            TwigExtensionRegistry::registerFunction('asset_body', [$this, 'renderBody'], ['is_safe' => ['html']]);
            TwigExtensionRegistry::registerFunction('asset_require', [$this, 'requireAsset'], ['is_safe' => ['html']]);
        }
    }

    /**
     * Fingerprinted, immutable-cacheable URL for a module asset.
     */
    public function assetUrl(string $path, ?string $module = null): string
    {
        return AssetManager::getUrl($path, $module);
    }

    public function renderHead(): Markup
    {
        return new Markup(AssetRenderer::renderHead(AssetCollectorStore::get()), 'UTF-8');
    }

    public function renderBody(): Markup
    {
        return new Markup(AssetRenderer::renderBody(AssetCollectorStore::get()), 'UTF-8');
    }

    /**
     * Register a requirement for this render. Emits nothing itself.
     */
    public function requireAsset(string $key): string
    {
        AssetCollectorStore::get()->require($key);

        return '';
    }
}
