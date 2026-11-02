<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Http\Response;

use Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse;
use Semitexa\Ssr\Attribute\AsSlotResource;

/** A slot resource declaring every #[AsSlotResource] field (HtmlSlotResponseTest). */
#[AsSlotResource(
    handle: 'shop.page',
    slot: 'sidebar',
    template: '@shop/slots/sidebar.html.twig',
    priority: 7,
    deferred: true,
    cacheTtl: 60,
    skeletonTemplate: '@shop/slots/sidebar-skeleton.html.twig',
    mode: 'stream',
    refreshInterval: 30,
    clientModules: ['shop/sidebar'],
    context: ['title' => 'Basket', 'tone' => 'brand'],
)]
final class DeclaredSlotFixture extends HtmlSlotResponse
{
}
