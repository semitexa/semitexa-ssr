<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

#[Capability(
    id: 'ssr.layout-slot',
    summary: 'Binds a template into a named layout slot for a set of page handles, optionally deferred and cached.',
    useWhen: 'A region should appear on a set of pages without every page handler having to know about it.',
    avoidWhen: 'Only one page shows the region; binding it layout-wide hides a local concern in a global place.',
    replaces: [
        'editing every page template to include the same block',
        'threading a region data through handlers that have nothing to do with it',
    ],
    seeAlso: 'ssr.slot-resource',
)]
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class AsLayoutSlot
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $handle,
        public string $slot,
        public string $template,
        public array $context = [],
        public int $priority = 0,
        public bool $deferred = false,
        public int $cacheTtl = 0,
        public ?string $dataProvider = null,
        public ?string $skeletonTemplate = null,
        public string $mode = 'html',
        public int $refreshInterval = 0,
    ) {}
}
