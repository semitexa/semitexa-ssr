<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class WithDataProvider
{
    public function __construct(
        public readonly string $providerClass,
    ) {}
}
