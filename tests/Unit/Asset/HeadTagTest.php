<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Asset\AssetCollector;
use Semitexa\Ssr\Application\Service\Asset\AssetRenderer;

/**
 * A tag several parts of a page ask for lands in <head> once. Every live island
 * used to print the page's KISS session meta in front of itself: two islands,
 * two copies.
 */
final class HeadTagTest extends TestCase
{
    private const META = '<meta name="semitexa-ui-sse-session" content="sse_1">';

    #[Test]
    public function asked_for_twice_it_lands_once_before_the_head_closes(): void
    {
        $collector = new AssetCollector();
        $collector->headTag('session', self::META, 'name="semitexa-ui-sse-session"');
        $collector->headTag('session', '<meta name="semitexa-ui-sse-session" content="sse_2">', 'name="semitexa-ui-sse-session"');

        $html = AssetRenderer::finalizeDynamicCss('<html><head><title>t</title></head><body><p>island</p><p>island</p></body></html>', $collector);

        self::assertSame(1, substr_count($html, 'semitexa-ui-sse-session'));
        self::assertStringContainsString(self::META . '</head>', $html, 'the first ask wins, and it goes into <head>');
    }

    #[Test]
    public function it_is_skipped_when_the_page_printed_the_tag_itself(): void
    {
        $collector = new AssetCollector();
        $collector->headTag('session', self::META, 'name="semitexa-ui-sse-session"');

        $html = AssetRenderer::finalizeDynamicCss('<html><head></head><body>' . self::META . '</body></html>', $collector);

        self::assertSame(1, substr_count($html, 'semitexa-ui-sse-session'));
    }

    #[Test]
    public function page_text_that_only_mentions_the_name_does_not_suppress_it(): void
    {
        $collector = new AssetCollector();
        $collector->headTag('session', self::META, 'name="semitexa-ui-sse-session"');

        $page = '<html><head></head><body><p>uses meta name="semitexa-ui-sse-session"</p>'
            . '<script>if (a<b) document.querySelector(\'meta[name="semitexa-ui-sse-session"]\');</script></body></html>';
        $html = AssetRenderer::finalizeDynamicCss($page, $collector);

        self::assertStringContainsString(self::META . '</head>', $html, 'a mention is not the tag');
    }

    #[Test]
    public function it_takes_the_place_asset_head_left_for_late_arrivals(): void
    {
        $collector = new AssetCollector();
        $head = AssetRenderer::renderHead($collector);
        $collector->headTag('session', self::META);

        $html = AssetRenderer::finalizeDynamicCss('<html><head>' . $head . '<title>t</title></head><body></body></html>', $collector);

        self::assertStringContainsString(self::META . '<title>t</title>', $html);
        self::assertStringNotContainsString(AssetRenderer::DYNAMIC_CSS_MARKER, $html);
    }

    #[Test]
    public function a_finalized_page_does_not_print_it_again(): void
    {
        $collector = new AssetCollector();
        $collector->headTag('session', self::META);
        AssetRenderer::finalizeDynamicCss('<html><head></head><body></body></html>', $collector);

        self::assertSame('<p>x</p>', AssetRenderer::finalizeDynamicCss('<p>x</p>', $collector), 'drained on first use');
    }
}
