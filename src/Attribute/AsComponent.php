<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsComponent
{
    public function __construct(
        public string $name,
        public ?string $template = null,
        public ?string $layout = null,
        public bool $cacheable = true,
        public ?string $event = null,
        /** @var list<string> */
        public array $triggers = [],
        public ?string $script = null,
    ) {}
}
