<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

#[Capability(
    id: 'ssr.with-data-provider',
    summary: 'Binds a specific data provider class to a resource.',
    useWhen: 'One resource needs a named provider rather than slot-wide resolution.',
    avoidWhen: 'Slot-wide provider resolution already picks the right provider.',
    replaces: [
        'resolving the provider by hand inside the resource',
    ],
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class WithDataProvider
{
    public function __construct(
        public readonly string $providerClass,
    ) {}
}
