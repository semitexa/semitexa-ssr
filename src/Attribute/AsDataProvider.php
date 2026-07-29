<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

#[Capability(
    id: 'ssr.data-provider',
    summary: 'Supplies the data for a given slot across the page handles it declares.',
    useWhen: 'The same data backs a region on several pages.',
    avoidWhen: 'The data is used by a single page - fetch it in that page handler instead.',
    replaces: [
        'fetching the same data again in each page handler',
    ],
    seeAlso: 'ssr.with-data-provider',
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class AsDataProvider
{
    /**
     * @param string $slot Slot ID this provider resolves data for
     * @param string[] $handles Page handles where this provider is active
     */
    public function __construct(
        public string $slot,
        public array $handles = [],
    ) {}
}
