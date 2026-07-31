<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

#[Capability(
    id: 'ssr.deferred',
    summary: 'Renders a slot after the main page and streams it in when it is ready.',
    useWhen: 'A region is slow enough to delay first paint - an external call, a heavy query - and the rest of the page should not wait for it.',
    avoidWhen: 'The region is fast. Deferring adds a round trip and a visible skeleton, so it makes a quick region slower and jumpier.',
    replaces: [
        'a client-side fetch() against a bespoke JSON route, with manual DOM insertion',
        'blocking the whole page render on one slow dependency',
        'a hand-written loading skeleton toggled by page JavaScript',
    ],
    seeAlso: 'ssr.transport',
)]
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
