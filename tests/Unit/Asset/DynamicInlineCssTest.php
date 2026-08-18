<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Asset\AssetCollector;
use Semitexa\Ssr\Application\Service\Asset\AssetRenderer;

/**
 * The raw inline CSS pipeline (semitexa/semitexa-ssr#51): a module may
 * register CSS *content* — no physical file — and it must reach the head
 * whether it was registered before the head rendered (emitted in place) or
 * after it (the marker asset_head leaves behind is resolved once the page
 * render completes). The post-render seam is the collector's onFinalize
 * callback: it sees the rendered HTML and registers what it compiled.
 *
 * The invariants worth pinning: every entry renders exactly once no matter
 * how many finalize passes touch the request; the marker never leaks into
 * delivered HTML; and a page with no dynamic CSS is byte-identical to before
 * the seam existed (an empty marker resolves to nothing).
 */
final class DynamicInlineCssTest extends TestCase
{
    public function testCssRegisteredBeforeHeadRendersInHead(): void
    {
        $collector = new AssetCollector();
        $collector->inlineCss('ui:slices', '.btn{color:red}');

        $head = AssetRenderer::renderHead($collector);

        $this->assertStringContainsString('<style data-asset-key="ui:slices">.btn{color:red}</style>', $head);
        $this->assertStringContainsString(AssetRenderer::DYNAMIC_CSS_MARKER, $head);
    }

    public function testCssRegisteredAfterHeadResolvesTheMarker(): void
    {
        $collector = new AssetCollector();
        $head = AssetRenderer::renderHead($collector);
        $html = '<html><head>' . $head . '</head><body><p class="late"></p></body></html>';

        $collector->inlineCss('ui:late', '.late{display:none}');
        $final = AssetRenderer::finalizeDynamicCss($html, $collector);

        $this->assertStringContainsString('<style data-asset-key="ui:late">.late{display:none}</style>', $final);
        $this->assertStringNotContainsString(AssetRenderer::DYNAMIC_CSS_MARKER, $final);
    }

    public function testOnFinalizeCallbackSeesRenderedHtmlAndContributes(): void
    {
        $collector = new AssetCollector();
        $collector->onFinalize(static function (AssetCollector $c, string $html): void {
            // The platform-ui shape: scan what actually rendered, compile, register.
            if (str_contains($html, 'sx-chip')) {
                $c->inlineCss('ui:chip', '.sx-chip{border-radius:9px}');
            }
        });

        $html = '<html><head>' . AssetRenderer::renderHead($collector) . '</head>'
            . '<body><span class="sx-chip"></span></body></html>';
        $final = AssetRenderer::finalizeDynamicCss($html, $collector);

        $this->assertStringContainsString('.sx-chip{border-radius:9px}', $final);
    }

    public function testEntriesRenderExactlyOnceAcrossTwoFinalizePasses(): void
    {
        $collector = new AssetCollector();
        $ran = 0;
        $collector->onFinalize(static function (AssetCollector $c) use (&$ran): void {
            $ran++;
            $c->inlineCss('ui:once', '.x{}');
        });

        $html = '<html><head>' . AssetRenderer::renderHead($collector) . '</head><body></body></html>';
        $once = AssetRenderer::finalizeDynamicCss($html, $collector);
        $twice = AssetRenderer::finalizeDynamicCss($once, $collector);

        $this->assertSame(1, $ran, 'Finalize callbacks drain on first use.');
        $this->assertSame(1, substr_count($twice, 'data-asset-key="ui:once"'));
    }

    public function testPriorityOrdersTheStyles(): void
    {
        $collector = new AssetCollector();
        $collector->inlineCss('ui:overrides', '.b{}', 200);
        $collector->inlineCss('ui:base', '.a{}', 10);

        $head = AssetRenderer::renderHead($collector);

        $this->assertLessThan(
            strpos($head, 'ui:overrides'),
            strpos($head, 'ui:base'),
            'Lower priority renders first so higher-priority CSS cascades over it.',
        );
    }

    public function testReRegisteringAKeyOverwritesItsContent(): void
    {
        $collector = new AssetCollector();
        $collector->inlineCss('ui:slices', '.draft{}');
        $collector->inlineCss('ui:slices', '.final{}');

        $head = AssetRenderer::renderHead($collector);

        $this->assertStringContainsString('.final{}', $head);
        $this->assertStringNotContainsString('.draft{}', $head);
    }

    public function testNoMarkerFallsBackToHeadInjection(): void
    {
        // A page that never called asset_head() still gets its dynamic CSS.
        $collector = new AssetCollector();
        $collector->inlineCss('ui:x', '.x{}');

        $final = AssetRenderer::finalizeDynamicCss('<html><head><title>t</title></head><body></body></html>', $collector);

        $this->assertMatchesRegularExpression('#<style[^>]*>\.x\{\}</style>\n</head>#', $final);
    }

    public function testNoDynamicCssLeavesHtmlByteIdentical(): void
    {
        $collector = new AssetCollector();
        $head = AssetRenderer::renderHead($collector);
        $html = '<html><head>' . $head . '</head><body></body></html>';

        $final = AssetRenderer::finalizeDynamicCss($html, $collector);

        $this->assertStringNotContainsString(AssetRenderer::DYNAMIC_CSS_MARKER, $final);
        $this->assertSame(str_replace(AssetRenderer::DYNAMIC_CSS_MARKER, '', $html), $final);
    }

    public function testStyleCloseSequenceCannotBreakOutOfTheTag(): void
    {
        $collector = new AssetCollector();
        $collector->inlineCss('ui:evil', '.x{}</style><script>alert(1)</script>');

        $head = AssetRenderer::renderHead($collector);

        $this->assertStringNotContainsString('</style><script>alert(1)</script>', $head);
    }

    public function testResetDropsRawCssAndCallbacks(): void
    {
        $collector = new AssetCollector();
        $ran = false;
        $collector->inlineCss('ui:x', '.x{}');
        $collector->onFinalize(static function () use (&$ran): void {
            $ran = true;
        });

        $collector->reset();
        $final = AssetRenderer::finalizeDynamicCss('<html><head></head><body></body></html>', $collector);

        $this->assertFalse($ran);
        $this->assertStringNotContainsString('ui:x', $final);
    }
}
