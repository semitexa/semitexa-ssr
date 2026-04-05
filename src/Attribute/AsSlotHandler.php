<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AsSlotHandler
{
    public function __construct(
        public string $slot,
        public int $priority = 0,
    ) {}
}
