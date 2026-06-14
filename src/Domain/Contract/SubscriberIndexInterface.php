<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

use Semitexa\Ssr\Domain\Model\SubscriberRef;

/**
 * The reverse index (Track R · R1, design §C.5): resolve a `(tenant, scopeKey)`
 * push to the local-instance subscriptions that watch that scope.
 *
 * When the cross-instance subscriber (R3) wakes on a Redis Pub/Sub message for
 * channel `ui.invalidate.{tenant}.{scopeKey}`, it must answer "which of THIS
 * instance's live subscriptions care about this scope?" so it can RPUSH a
 * `{__ctrl:rerun}` control onto each one's session-addressed queue. No such
 * resource→subscribers index exists today (both delivery queues presuppose a
 * known `session_id`); this contract is it.
 *
 * The `(tenant, scopeKey)` pair is the full lookup key (design §C.2/§C.5): the
 * publisher names the channel `ui.invalidate.{tenant}.{scopeKey}`, so the index
 * must filter on BOTH dimensions — tenant isolation here is a security boundary,
 * not a convenience (a mutation in tenant A must never resolve tenant B's
 * subscribers on a same-named scope). `scopeKey` is a P1 `resourceKey`
 * (default = table name), the same value a P2 `ResourceChangedEvent` carries.
 *
 * The seam (design §C.5): the first implementation SCANS the subscription store
 * (O(rows)); a future keyed-Table implementation can be swapped in behind this
 * interface — keyed by `md5(tenant|scopeKey)` — without touching callers, once
 * concurrent-streams × invalidation-rate make the scan measurable.
 */
interface SubscriberIndexInterface
{
    /**
     * Resolve the local subscriptions watching `$scopeKey` within `$tenant`.
     *
     * @return list<SubscriberRef> one ref per matching subscription (empty when none).
     */
    public function find(string $tenant, string $scopeKey): array;
}
