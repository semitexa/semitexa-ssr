<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

#[Capability(
    id: 'ssr.component',
    summary: 'A reusable server-rendered component with its own template, optional client script and event triggers.',
    useWhen: 'The same block of markup appears on more than one page, or a block needs its own script and event wiring.',
    avoidWhen: 'The markup appears once and carries no behaviour - a plain template fragment is cheaper to read.',
    replaces: [
        'the same Twig include copied across several templates',
        'per-page <script> tags wiring one block by hand',
    ],
)]
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
