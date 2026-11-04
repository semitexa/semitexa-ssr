<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

/** @api */
#[Capability(
    id: 'ssr.slot-handler',
    summary: 'Server-side handler that populates a slot resource; handlers for one slot run in priority order.',
    useWhen: 'A slot content needs logic, or several parties must each contribute to the same region.',
    avoidWhen: 'The slot content is static, or exactly one party ever contributes to it and a resource alone would do.',
    replaces: [
        'computing region content inside the page handler and passing it down',
        'an if/else chain deciding what a shared region shows',
    ],
    usage: <<<'USAGE'
        #[AsSlotHandler(slot: StatsSlot::class)]
        final class StatsSlotHandler implements TypedSlotHandlerInterface
        {
            public function handle(object $slot): object
            {
                return $slot->withRenderContext(['orders' => $this->orders->count()]);
            }
        }

        // handle() gets the slot and nothing else: there is no request here. What the page
        // knows arrives in $slot->getRenderContext(), from what the page's resource was given
        // with $this->with('key', $value). The slot's template reads the context by key.
        USAGE,
)]
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AsSlotHandler
{
    public function __construct(
        public string $slot,
        public int $priority = 0,
    ) {}
}
