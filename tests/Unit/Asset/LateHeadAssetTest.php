<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Asset\AssetCollector;
use Semitexa\Ssr\Application\Service\Asset\AssetRenderer;

/**
 * A stylesheet asked for while the body renders must still reach the head.
 *
 * Twig renders <head> before <body>, so a component that requires its own
 * stylesheet — which is the right thing for it to do, since the markup is what
 * knows the asset is needed — asks after asset_head() has already run. A <link>
 * is forced to the head by R1 and renderBody() skips CSS deliberately, so
 * before renderLateHeadAssets() such a request matched NO renderer at all.
 *
 * MEASURED when this was found: moving platform-ui's calendar.css and
 * date-field.css to page scope and requiring them from their own templates gave
 * pages that returned 200, carried the runtime, and were missing the stylesheet.
 * No error, no log line — the component simply came back unstyled. That silence
 * is what these cases exist to keep out.
 */
final class LateHeadAssetTest extends TestCase
{
    private function collector(): AssetCollector
    {
        return new AssetCollector();
    }

    #[Test]
    public function a_stylesheet_required_after_the_head_rendered_still_reaches_it(): void
    {
        $collector = $this->collector();

        $head = AssetRenderer::renderHead($collector);
        self::assertStringNotContainsString('late-demo', $head, 'nothing asked for it yet');

        // What a component's markup does while the body renders.
        $collector->require('demo:css:late-demo');

        $page = AssetRenderer::finalizeDynamicCss('<html><head>' . $head . '</head><body>x</body></html>', $collector);

        self::assertStringContainsString('late-demo.css', $page, 'the late stylesheet must be emitted');
        self::assertSame(1, substr_count($page, 'late-demo.css'), 'and exactly once');

        $headEnd = strpos($page, '</head>');
        self::assertIsInt($headEnd);
        self::assertLessThan($headEnd, strpos($page, 'late-demo.css'), 'it belongs in the head, not the body');
    }

    /**
     * The reason the collector remembers what the head printed: resolve()
     * returns the whole set every time, so without that memory the finalize
     * pass would print every stylesheet on the page a second time.
     */
    #[Test]
    public function a_stylesheet_the_head_already_printed_is_not_printed_again(): void
    {
        $collector = $this->collector();
        $collector->require('demo:css:early-demo');

        $head = AssetRenderer::renderHead($collector);
        self::assertStringContainsString('early-demo.css', $head);

        $page = AssetRenderer::finalizeDynamicCss('<html><head>' . $head . '</head><body>x</body></html>', $collector);

        self::assertSame(1, substr_count($page, 'early-demo.css'), 'the head printed it; finalize must not repeat it');
    }

    /**
     * The finalize pass is idempotent by contract — a nested render can run it
     * twice — so a late asset must not be emitted once per pass.
     */
    #[Test]
    public function finalizing_twice_emits_the_late_stylesheet_once(): void
    {
        $collector = $this->collector();
        $head = AssetRenderer::renderHead($collector);
        $collector->require('demo:css:late-demo');

        $page = AssetRenderer::finalizeDynamicCss('<html><head>' . $head . '</head><body>x</body></html>', $collector);
        $page = AssetRenderer::finalizeDynamicCss($page, $collector);

        self::assertSame(1, substr_count($page, 'late-demo.css'));
    }

    /**
     * Scripts were never stranded — asset_body() runs after the markup that
     * required them — so the late pass must not start emitting them into the
     * head, where a module would execute before the DOM it acts on exists.
     */
    #[Test]
    public function a_late_script_is_left_for_the_body_renderer(): void
    {
        $collector = $this->collector();
        $head = AssetRenderer::renderHead($collector);
        $collector->require('demo:js:late-script');

        $page = AssetRenderer::finalizeDynamicCss('<html><head>' . $head . '</head><body>x</body></html>', $collector);

        self::assertStringNotContainsString('late-script.js', $page, 'the head pass must not claim scripts');
        self::assertStringContainsString('late-script.js', AssetRenderer::renderBody($collector));
    }
}
