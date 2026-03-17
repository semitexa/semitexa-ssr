<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsDeferred
{
    public function __construct(
        public string $slot,
        public string $mode = 'html',
        public int $priority = 0,
        public int $cacheTtl = 0,
        public ?string $skeletonTemplate = null,
    ) {}
}
