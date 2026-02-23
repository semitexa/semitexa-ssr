<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsComponent
{
    public function __construct(
        public string $name,
        public ?string $template = null,
        public ?string $layout = null,
        public bool $cacheable = true,
    ) {}
}
