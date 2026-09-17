<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Integration\Isomorphic;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\CspNonce;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\Asset\AssetRenderer;
use Semitexa\Ssr\Application\Service\Isomorphic\PlaceholderRenderer;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;

/**
 * The half a unit test cannot see: a placeholder renders MID-BODY, long after
 * the head has been written, so styling it registers by the ordinary asset
 * route would arrive too late to be emitted at all.
 *
 * This walks the real seam — head marker, body render, finalize — and asserts
 * the styling lands in the document. Worth its own test because the failure
 * mode is not an error: the CSS is registered, nobody renders it, and the
 * placeholder is invisible exactly as before.
 *
 * Nothing in this workspace renders a default skeleton today: every slot
 * resource here declares a skeletonTemplate and no component uses
 * `#[WithTransport(Sse, deferred: true)]`. That is how a class nothing styled
 * stayed unnoticed through every demo page — and why this test constructs the
 * case by hand rather than pointing at a page.
 */
final class DefaultSkeletonReachesThePageTest extends TestCase
{
    protected function setUp(): void
    {
        AssetCollectorStore::get()->reset();
        CspNonce::reset();
    }

    protected function tearDown(): void
    {
        AssetCollectorStore::get()->reset();
        CspNonce::reset();
    }

    private function renderPage(): string
    {
        $collector = AssetCollectorStore::get();

        // Head first, exactly as a layout does: it leaves the dynamic-CSS
        // marker behind for whatever the body turns out to need.
        $head = AssetRenderer::renderHead($collector);

        $body = PlaceholderRenderer::renderPlaceholder(new DeferredSlotDefinition(
            slotId: 'late_block',
            templateName: 'block.html.twig',
            pageHandle: 'page',
        ));

        return AssetRenderer::finalizeDynamicCss(
            '<html><head>' . $head . '</head><body>' . $body . '</body></html>',
            $collector,
        );
    }

    #[Test]
    public function stylingRegisteredMidBodyStillLandsInTheHead(): void
    {
        $html = $this->renderPage();

        self::assertStringContainsString('<div class="ssr-skeleton"', $html);
        self::assertStringContainsString('.ssr-skeleton{min-height', $html);
        self::assertStringNotContainsString(
            AssetRenderer::DYNAMIC_CSS_MARKER,
            $html,
            'the marker must be resolved, not shipped to the browser'
        );

        $stylePosition = strpos($html, '.ssr-skeleton{min-height');
        $placeholderPosition = strpos($html, '<div class="ssr-skeleton"');
        self::assertNotFalse($stylePosition);
        self::assertNotFalse($placeholderPosition);
        self::assertLessThan(
            $placeholderPosition,
            $stylePosition,
            'the style has to precede the box it sizes, or the first frame is the blank one again'
        );
    }

    #[Test]
    public function theStyleBlockCarriesTheNonceWhenTheApplicationHasOne(): void
    {
        // style-src governs an inline <style> exactly as script-src governs an
        // inline <script>. A skeleton whose styling is refused is the blank
        // box this whole change is about.
        CspNonce::set('page-nonce');

        self::assertStringContainsString('nonce="page-nonce"', $this->renderPage());
    }

    #[Test]
    public function aPageWithNoSkeletonCarriesNoSkeletonCss(): void
    {
        $collector = AssetCollectorStore::get();
        $html = AssetRenderer::finalizeDynamicCss(
            '<html><head>' . AssetRenderer::renderHead($collector) . '</head><body><p>plain</p></body></html>',
            $collector,
        );

        // The page FIRST, then the absence. An empty return satisfies the
        // negative on its own, so without this the test would pass through a
        // regression that drops the document entirely.
        self::assertStringContainsString('<p>plain</p>', $html, 'finalisation keeps the page it was given');
        self::assertStringNotContainsString('ssr-skeleton', $html, 'zero cost for a page that defers nothing');
    }
}
