<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Layout;

use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\Asset\AssetRenderer;
use Semitexa\Ssr\Application\Service\Http\RenderSpan;
use Semitexa\Ssr\Application\Service\Seo\SiteHead\SiteHeadStore;

/**
 * The last pass over a rendered page: styles registered after the head had
 * already rendered, the site's own head tags, then whatever registered page
 * contributors place before </body>.
 *
 * Every full-page render path ends here — HtmlResponse::render(),
 * renderString() and LayoutRenderer — so a page gets both whichever way it was
 * rendered, and a step added later is added once rather than three times.
 */
final class PageDocumentFinalizer
{
    public static function finalize(string $html): string
    {
        $html = RenderSpan::around('ssr.document.css', static fn (): string => AssetRenderer::finalizeDynamicCss($html, AssetCollectorStore::get()));
        $html = RenderSpan::around('ssr.document.head', static fn (): string => SiteHeadStore::inject($html));

        return RenderSpan::around('ssr.document.body_end', static fn (): string => PageBodyEndStore::inject($html));
    }
}
