<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;

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
