<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;

#[Capability(
    id: 'ssr.publishes-scope',
    summary: 'Declares that this class invalidates a live scope from code, so a grid watching it is wired even though no resource publishes that key.',
    useWhen: 'A handler or service calls ScopeInvalidatorInterface::touch() for a scope that has no #[ResourceKey] or #[FromTable] behind it.',
    avoidWhen: 'A resource already publishes the key — the resource is the declaration, and repeating it here says nothing new.',
    replaces: [
        'a live wire that only the handler body knows about, which reads as dead to anything that joins watchers to publishers',
    ],
    seeAlso: 'ui.event-intent',
)]
/**
 * The publishing half of a live wire that no resource owns.
 *
 * `#[WatchScopes]` says a payload re-runs when a scope is invalidated, and a
 * resource's `#[ResourceKey]` / `#[FromTable]` normally says who invalidates
 * it. Between them the live-tenancy guard can tell a working wire from a grid
 * that claims to be live and never re-runs.
 *
 * Some scopes have no resource at all: the publish is a `touch()` call in a
 * handler, and the data behind it is a store rather than a table. Read only
 * through attributes, such a wire is indistinguishable from a dead one — and
 * the call itself cannot be read instead, because the scope arrives as a class
 * constant or a variable as often as a literal.
 *
 * So the publisher says so. What this declares is narrow: the wire exists and
 * fires. It claims nothing about tenancy — the guard's cross-tenant half joins
 * watchers to *resources*, and there is no resource here to scope or exempt.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class PublishesScope
{
    /** @var list<string> */
    public array $scopes;

    public function __construct(string ...$scopes)
    {
        $this->scopes = array_values($scopes);
    }
}
