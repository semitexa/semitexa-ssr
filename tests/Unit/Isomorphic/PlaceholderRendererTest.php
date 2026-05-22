<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;
use Semitexa\Ssr\Application\Service\Isomorphic\PlaceholderRenderer;

final class PlaceholderRendererTest extends TestCase
{
    public function testRenderComponentPlaceholderEmitsExpectedDataAttributes(): void
    {
        $html = PlaceholderRenderer::renderComponentPlaceholder('ui-playground.leads-grid', 'cmp_abcd1234');

        self::assertStringContainsString('data-ssr-deferred-component="ui-playground.leads-grid"', $html);
        self::assertStringContainsString('data-ssr-component-instance="cmp_abcd1234"', $html);
        self::assertStringNotContainsString('data-ssr-deferred=', $html, 'Must not overlap with layout-slot placeholder attribute');
    }

    public function testRenderComponentPlaceholderEscapesAttributes(): void
    {
        $html = PlaceholderRenderer::renderComponentPlaceholder('<script>', '"&onerror=alert(1)');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&quot;&amp;onerror=alert(1)', $html);
    }

    public function testRenderManifestIncludesComponents(): void
    {
        $manifest = PlaceholderRenderer::renderManifest(
            'dr_abc',
            'sse_def',
            [],
            'bind_xyz',
            [
                ['instance_id' => 'cmp_1', 'name' => 'ui-playground.leads-grid', 'props' => ['x' => 1]],
                ['instance_id' => '', 'name' => 'dropped'],
                ['instance_id' => 'cmp_2', 'name' => ''],
            ],
        );

        self::assertStringContainsString('"components":[', $manifest);
        self::assertStringContainsString('"instance_id":"cmp_1"', $manifest);
        self::assertStringContainsString('"name":"ui-playground.leads-grid"', $manifest);
        self::assertStringNotContainsString('"name":"dropped"', $manifest);
        self::assertStringNotContainsString('"instance_id":"cmp_2"', $manifest);
    }

    public function testFilterRenderedComponentsFromHtmlReturnsOnlyRendered(): void
    {
        $instances = [
            ['instance_id' => 'cmp_a', 'name' => 'grid', 'props' => []],
            ['instance_id' => 'cmp_b', 'name' => 'chart', 'props' => []],
            ['instance_id' => 'cmp_c', 'name' => 'sidebar', 'props' => []],
        ];

        $html = <<<HTML
<div data-ssr-deferred-component="grid" data-ssr-component-instance="cmp_a"></div>
<div data-ssr-deferred-component="chart" data-ssr-component-instance="cmp_c"></div>
HTML;

        $rendered = PlaceholderRenderer::filterRenderedComponentsFromHtml($html, $instances);

        self::assertSame(
            ['cmp_a', 'cmp_c'],
            array_map(static fn (array $c): string => $c['instance_id'], $rendered)
        );
    }

    public function testInjectIfMissingInsertsFragmentBeforeBodyClose(): void
    {
        $html = "<!doctype html><html><body><p>hi</p></body></html>";
        $fragment = '<script>window.__SSR_DEFERRED={"slots":[]};</script>';

        $out = PlaceholderRenderer::injectIfMissing($html, $fragment);

        self::assertStringContainsString($fragment . '</body>', $out);
        self::assertSame(1, substr_count($out, $fragment));
    }

    public function testInjectIfMissingIsNoopWhenFragmentAlreadyPresent(): void
    {
        $fragment = '<script>window.__SSR_DEFERRED={"slots":[]};</script>';
        $html = "<!doctype html><html><body><p>hi</p>{$fragment}</body></html>";

        $out = PlaceholderRenderer::injectIfMissing($html, $fragment);

        self::assertSame($html, $out);
        self::assertSame(1, substr_count($out, $fragment));
    }

    public function testInjectIfMissingAppendsWhenNoBodyClose(): void
    {
        $html = '<div>partial fragment with no body tag</div>';
        $fragment = '<script>window.__SSR_DEFERRED={"slots":[]};</script>';

        $out = PlaceholderRenderer::injectIfMissing($html, $fragment);

        self::assertSame($html . $fragment, $out);
    }

    public function testInjectIfMissingIsNoopWhenFragmentIsEmpty(): void
    {
        $html = "<!doctype html><html><body></body></html>";

        $out = PlaceholderRenderer::injectIfMissing($html, '');

        self::assertSame($html, $out);
    }

    public function testInjectIfMissingHandlesUppercaseBodyClose(): void
    {
        $html = "<!doctype html><html><BODY><p>hi</p></BODY></html>";
        $fragment = '<script>window.__SSR_DEFERRED={"slots":[]};</script>';

        $out = PlaceholderRenderer::injectIfMissing($html, $fragment);

        self::assertStringContainsString($fragment . '</BODY>', $out);
    }

    public function testFilterRenderedSlotsFromHtmlReturnsOnlyRenderedPlaceholders(): void
    {
        $product = new DeferredSlotDefinition(
            slotId: 'deferred_product_carousel',
            templateName: 'product.html.twig',
            pageHandle: 'demo_deferred_blocks',
        );
        $notification = new DeferredSlotDefinition(
            slotId: 'deferred_notification',
            templateName: 'notification.html.twig',
            pageHandle: 'demo_deferred_blocks',
            refreshInterval: 5,
        );
        $chart = new DeferredSlotDefinition(
            slotId: 'deferred_chart_widget',
            templateName: 'chart.html.twig',
            pageHandle: 'demo_deferred_blocks',
        );

        $html = <<<HTML
<!doctype html>
<div class="deferred-blocks-grid">
  <div data-ssr-deferred="deferred_product_carousel"></div>
  <div data-ssr-deferred="deferred_chart_widget"></div>
</div>
HTML;

        $renderedSlots = PlaceholderRenderer::filterRenderedSlotsFromHtml($html, [
            $product,
            $notification,
            $chart,
        ]);

        self::assertSame(
            ['deferred_product_carousel', 'deferred_chart_widget'],
            array_map(static fn (DeferredSlotDefinition $slot): string => $slot->slotId, $renderedSlots)
        );
    }
}
