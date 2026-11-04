<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/** @api */
#[Capability(
    id: 'ssr.deferred',
    summary: 'Renders a slot after the main page and streams it in when it is ready.',
    useWhen: 'A region is slow enough to delay first paint - an external call, a heavy query - and the rest of the page should not wait for it.',
    avoidWhen: 'The region is fast. Deferring adds a round trip and a visible skeleton, so it makes a quick region slower and jumpier.',
    replaces: [
        'a client-side fetch() against a bespoke JSON route, with manual DOM insertion',
        'blocking the whole page render on one slow dependency',
        'a hand-written loading skeleton toggled by page JavaScript',
    ],
    seeAlso: 'ssr.transport',
    usage: <<<'USAGE'
        // The slot, its handle being the page's #[AsResource] handle:
        #[AsSlotResource(handle: 'dashboard', slot: 'revenue', deferred: true, mode: 'template',
            template: '@project-layouts-Shop/slots/revenue.html.twig',
            skeletonTemplate: '@project-layouts-Shop/slots/revenue.skeleton.html.twig')]
        final class RevenueSlot extends HtmlSlotResponse {}

        // The page template: the skeleton is in the first response, the slot follows by itself.
        {{ layout_slot_deferred('revenue') }}

        // The slot handler. It has no request: what the page knows reaches it as render context.
        #[AsSlotHandler(slot: RevenueSlot::class)]
        final class RevenueSlotHandler implements TypedSlotHandlerInterface
        {
            public function handle(object $slot): object
            {
                $year = $slot->getRenderContext()['year'];

                return $slot->withRenderContext(['revenue' => $this->total($year)]);
            }
        }

        // The page's resource puts it there, a query value coming through the payload:
        public function withYear(int $year): self { return $this->with('year', $year); }
        return $resource->withYear($payload->getYear());   // in the page handler

        // No script and no route: never write a fetch() for this. A failure is the handler's
        // to catch and show, through its context, so the rest of the page is unaffected.
        USAGE,
)]
#[Capability(
    id: 'ssr.slot-resource',
    summary: 'Declares a typed resource that fills a layout slot, with its template, transport mode, cache TTL and client modules.',
    useWhen: 'A slot needs a typed payload rather than an inline template fragment.',
    avoidWhen: 'The slot renders a static fragment with no data of its own.',
    replaces: [
        'assembling a region markup as a string inside the page handler',
    ],
    seeAlso: 'ssr.slot-handler',
    usage: <<<'USAGE'
        #[AsSlotResource(handle: 'dashboard', slot: 'stats',
            template: '@project-layouts-Shop/slots/stats.html.twig')]
        final class StatsSlot extends HtmlSlotResponse {}

        // handle is the page's #[AsResource] handle. In the page template:
        {{ layout_slot('stats') }}

        // Data comes from a #[AsSlotHandler] for this class, or the page's own context.
        USAGE,
)]
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AsSlotResource
{
    /**
     * @param list<string> $clientModules
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $handle,
        public string $slot,
        public string $template,
        public int $priority = 0,
        public bool $deferred = false,
        public int $cacheTtl = 0,
        public ?string $skeletonTemplate = null,
        public string $mode = 'html',
        public int $refreshInterval = 0,
        public array $clientModules = [],
        public array $context = [],
    ) {}
}
