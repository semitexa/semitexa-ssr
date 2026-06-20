<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

/**
 * Collaborative Form Data · Phase 1 — lets a feed payload contribute
 * invalidation scope keys resolved at REQUEST time, merged with the static
 * `#[WatchScopes]` declaration.
 *
 * A grid's watched scope is a static table name knowable from the class alone
 * (`#[WatchScopes('ui_playground_pings')]`). A collaborative document's scope
 * depends on the record being edited (`formdoc:{formKey}:{recordId}`), which is
 * only known once the payload is hydrated with the request — so it cannot ride
 * a class-level attribute. A document feed payload implements this to return
 * its per-record scope (via {@see \Semitexa\Ssr\Domain\Model\FormDocumentScope});
 * {@see \Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseFeedHandler}
 * unions these with the static `#[WatchScopes]` keys when it builds the
 * held-open {@see \Semitexa\Ssr\Domain\Model\SubscriptionRecord}.
 *
 * Implementing the interface is OPTIONAL and additive: a feed that does not
 * implement it behaves exactly as before (static scopes only). Returning an
 * empty list is valid (no dynamic scope this request).
 */
interface DynamicallyScopedFeedInterface
{
    /**
     * Invalidation scope keys resolved from the hydrated request, unioned with
     * the payload's static `#[WatchScopes]`. Empty/blank entries are ignored.
     *
     * @return list<string>
     */
    public function dynamicWatchScopes(): array;
}
