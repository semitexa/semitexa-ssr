<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

#[Capability(
    id: 'ssr.slot-resource',
    summary: 'Declares a typed resource that fills a layout slot, with its template, transport mode, cache TTL and client modules.',
    useWhen: 'A slot needs a typed payload rather than an inline template fragment.',
    avoidWhen: 'The slot renders a static fragment with no data of its own.',
    replaces: [
        'assembling a region markup as a string inside the page handler',
    ],
    seeAlso: 'ssr.slot-handler',
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
