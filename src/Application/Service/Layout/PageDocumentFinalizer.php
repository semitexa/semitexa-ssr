<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Layout;

use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\Asset\AssetRenderer;
use Semitexa\Ssr\Application\Service\Seo\SiteHead\SiteHeadStore;

/**
 * The last pass over a rendered page: styles registered after the head had
 * already rendered, then the site's own head tags.
 *
 * Every full-page render path ends here — HtmlResponse::render(),
 * renderString() and LayoutRenderer — so a page gets both whichever way it was
 * rendered, and a step added later is added once rather than three times.
 */
final class PageDocumentFinalizer
{
    public static function finalize(string $html): string
    {
        $html = AssetRenderer::finalizeDynamicCss($html, AssetCollectorStore::get());

        return SiteHeadStore::inject($html);
    }
}
