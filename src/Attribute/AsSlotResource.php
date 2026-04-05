<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AsSlotResource
{
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
