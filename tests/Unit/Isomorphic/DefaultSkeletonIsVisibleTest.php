<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\Isomorphic\PlaceholderRenderer;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;

/**
 * The placeholder a slot gets when it declares no skeleton of its own.
 *
 * Measured on a real consumer 2026-09-16: no slot in the project declared a
 * skeletonTemplate, so every deferred region fell back to a bare
 * `div.ssr-skeleton` — a class that nothing in the framework, or in any
 * package, styled. Zero height, no colour, invisible. The deferral that was
 * meant to buy patience bought a blank page instead, and the only way to find
 * out was to open dev tools.
 */
final class DefaultSkeletonIsVisibleTest extends TestCase
{
    protected function setUp(): void
    {
        AssetCollectorStore::get()->reset();
    }

    protected function tearDown(): void
    {
        AssetCollectorStore::get()->reset();
    }

    private function slot(?string $skeletonTemplate = null): DeferredSlotDefinition
    {
        return new DeferredSlotDefinition(
            slotId: 'ssrp_block',
            templateName: 'block.html.twig',
            pageHandle: 'page',
            skeletonTemplate: $skeletonTemplate,
        );
    }

    #[Test]
    public function theDefaultPlaceholderBringsItsOwnStyling(): void
    {
        $html = PlaceholderRenderer::renderPlaceholder($this->slot());

        self::assertStringContainsString('class="ssr-skeleton"', $html);

        $css = $this->skeletonCss();
        self::assertStringContainsString('.ssr-skeleton', $css);
        self::assertStringContainsString('min-height', $css, 'a zero-height placeholder is an invisible one');
    }

    #[Test]
    public function twentySkeletonsCarryOneCopyOfTheCss(): void
    {
        for ($i = 0; $i < 20; $i++) {
            PlaceholderRenderer::renderPlaceholder($this->slot());
        }

        self::assertCount(1, AssetCollectorStore::get()->takeRawInlineCss());
    }

    #[Test]
    public function aVisitorWhoAskedForNoMotionGetsNone(): void
    {
        PlaceholderRenderer::renderPlaceholder($this->slot());

        self::assertStringContainsString('prefers-reduced-motion', $this->skeletonCss());
    }

    #[Test]
    public function theStylingIsNeutralOnALightOrADarkPage(): void
    {
        // Alpha-only greys, no named colours and no hex: a default that fights
        // the skin is a default people replace, and then the class is back to
        // being unstyled for everyone who did not.
        PlaceholderRenderer::renderPlaceholder($this->slot());

        $css = $this->skeletonCss();

        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', $css);
        self::assertStringContainsString('rgba(128,128,128', $css);
    }

    private function skeletonCss(): string
    {
        foreach (AssetCollectorStore::get()->takeRawInlineCss() as $entry) {
            if ($entry['key'] === 'ssr:skeleton') {
                return $entry['css'];
            }
        }

        self::fail('the default skeleton registered no styling');
    }

    #[Test]
    public function theDefaultPlaceholderStaysAccessible(): void
    {
        $html = PlaceholderRenderer::renderPlaceholder($this->slot());

        self::assertStringContainsString('aria-busy="true"', $html);
        self::assertStringContainsString('aria-label="Loading ssr', $html);
    }
}
