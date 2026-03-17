<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class AsLayoutSlot
{
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
    ) {}
}

