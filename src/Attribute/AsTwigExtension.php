<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

#[Capability(
    id: 'ssr.twig-extension',
    summary: 'Registers a class as a Twig extension by discovery, with no boot-time wiring.',
    useWhen: 'Templates need a new function or filter.',
    avoidWhen: 'The logic belongs in a handler or resource; template helpers that carry business rules are hard to test.',
    replaces: [
        'registering the extension manually during server boot',
    ],
)]
#[Attribute(Attribute::TARGET_CLASS)]
class AsTwigExtension
{
    public function __construct() {}
}
