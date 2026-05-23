<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsComponentMetadataProvider
{
    public function __construct(
        public readonly int $priority = 0,
    ) {}
}
