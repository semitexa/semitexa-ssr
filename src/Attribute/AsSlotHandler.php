<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

#[Capability(
    id: 'ssr.slot-handler',
    summary: 'Server-side handler that populates a slot resource; handlers for one slot run in priority order.',
    useWhen: 'A slot content needs logic, or several parties must each contribute to the same region.',
    avoidWhen: 'The slot content is static, or exactly one party ever contributes to it and a resource alone would do.',
    replaces: [
        'computing region content inside the page handler and passing it down',
        'an if/else chain deciding what a shared region shows',
    ],
)]
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AsSlotHandler
{
    public function __construct(
        public string $slot,
        public int $priority = 0,
    ) {}
}
