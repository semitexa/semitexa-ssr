<?php

declare(strict_types=1);

namespace Semitexa\Ssr;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Unlike most packages carrying this class, this one does ship attributes, and
 * each of them already declares its own mechanism. What none of them can say is
 * what the package IS: a reader who has not installed it sees ten mechanisms
 * and no sentence telling them which package to require, or why. The
 * package-level entry sits above the mechanisms rather than duplicating them —
 * they describe what you write, this describes what you install.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'ssr.rendering',
    summary: 'Server-rendered pages: Twig templates, components, layout slots, an asset pipeline and deferred regions.',
    useWhen: 'The page should arrive already rendered, and interactivity is an enhancement rather than the delivery mechanism.',
    avoidWhen: 'The product is a client-owned application where the server only ever returns JSON.',
    replaces: [
        'a JSON API paired with a separate frontend build that re-renders what the server already knew',
        'a template loader and asset manifest hand-rolled once per project',
    ],
    seeAlso: 'ssr.deferred',
    usage: <<<'USAGE'
        // Which one to reach for, each with its own usage (ai:ask mechanisms --id=...):
        // ssr.slot-resource  a region of a page with its own data and template
        // ssr.deferred       that region filled in after the page, or updating by itself
        // ssr.component      a reusable piece of markup with its own template
        // ssr.twig-extension a new function or filter for templates
        //
        // What a page shows goes through its response resource: the handler calls a typed setter
        // such as withTitle(), the setter calls $this->with('title', $title), and the template
        // reads {{ title }}. with() is protected, so a handler cannot call it directly.
        USAGE,
)]
final class Capabilities
{
}
