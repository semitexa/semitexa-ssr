<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;
use Semitexa\Ssr\Application\Service\Isomorphic\PlaceholderRenderer;

final class PlaceholderRendererTest extends TestCase
{
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
