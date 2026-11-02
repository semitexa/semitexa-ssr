<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Http\Response;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a slot resource reads off its #[AsSlotResource], and how its render
 * context and client modules grow.
 */
final class HtmlSlotResponseTest extends TestCase
{
    #[Test]
    public function a_slot_resource_carries_what_its_attribute_declares(): void
    {
        $slot = new DeclaredSlotFixture();

        self::assertSame(
            ['shop.page', 'sidebar', '@shop/slots/sidebar.html.twig', true, 7, 60, '@shop/slots/sidebar-skeleton.html.twig', 'stream', 30],
            [$slot->getSlotHandle(), $slot->getSlotName(), $slot->getDeclaredTemplate(), $slot->isDeferred(), $slot->getPriority(), $slot->getCacheTtl(), $slot->getSkeletonTemplate(), $slot->getMode(), $slot->getRefreshInterval()],
        );
        self::assertSame(['shop/sidebar'], $slot->getClientModules());
        self::assertSame(['title' => 'Basket', 'tone' => 'brand'], $slot->getStaticContext());
        $second = new DeclaredSlotFixture();
        self::assertSame([$slot->getSlotHandle(), $slot->getStaticContext()], [$second->getSlotHandle(), $second->getStaticContext()], 'a second instance reads the cached attribute alike');
    }

    #[Test]
    public function a_slot_resource_without_the_attribute_keeps_the_defaults(): void
    {
        $slot = new UndeclaredSlotFixture();

        self::assertSame(['', '', null, false, 0, 0, null, 'html', 0, [], []], [
            $slot->getSlotHandle(), $slot->getSlotName(), $slot->getDeclaredTemplate(), $slot->isDeferred(), $slot->getPriority(),
            $slot->getCacheTtl(), $slot->getSkeletonTemplate(), $slot->getMode(), $slot->getRefreshInterval(), $slot->getClientModules(), $slot->getStaticContext(),
        ]);
    }

    /** Later values win; an integer key names no template variable and is not taken. */
    #[Test]
    public function render_context_takes_named_entries_on_a_copy(): void
    {
        $slot = new DeclaredSlotFixture();
        $more = $slot->withRenderContext(['basket' => 2, 'title' => 'Cart'])->withRenderContext(['basket' => 3, 0 => 'positional']);

        self::assertSame([], $slot->getRenderContext(), 'the original is untouched');
        self::assertSame(['basket' => 3, 'title' => 'Cart'], $more->getRenderContext());
    }

    #[Test]
    public function client_modules_are_added_once_and_only_as_names(): void
    {
        $slot = new DeclaredSlotFixture();
        $more = $slot->withClientModules(['shop/cart', 'shop/sidebar', '', 42, 'shop/cart']);

        self::assertSame(['shop/sidebar'], $slot->getClientModules());
        self::assertSame(['shop/sidebar', 'shop/cart'], $more->getClientModules());
        self::assertSame($slot, $slot->withClientModules([]), 'nothing to add is the same instance');
    }
}
