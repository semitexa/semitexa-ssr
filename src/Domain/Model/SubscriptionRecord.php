<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

/**
 * One live subscription as it lives in the cross-worker `subscriptionTable`
 * tier (Track R · R1, design §C.6).
 *
 * This is the **serialized** representation of a subscription: every field is a
 * scalar or a list of scalars, so the whole record round-trips through a
 * {@see \Swoole\Table} and is readable by a worker that is NOT the owner. It is
 * the deliberate counterpart to the worker-static DTO registry tier, which holds
 * the live (never-serialized) re-run state.
 *
 * THE TIER-SEPARATION INVARIANT (the security boundary, design §C.6): this
 * record carries NO live DTO and NO identity-bearing object — only:
 *  - {@see $streamingId} — the subscription id (the table key);
 *  - {@see $sessionId} — the session-addressed delivery coordinate;
 *  - {@see $tenantId} — the queryable tenant discriminator the reverse-index
 *    scan filters on (so a push in tenant A can never resolve tenant B's
 *    subscribers on the same scope key);
 *  - {@see $scopeKeys} — the resource scope keys this subscription watches,
 *    each a P1 `resourceKey` (default = table name), matching exactly what a
 *    P2 `ResourceChangedEvent` / P3 publish carries;
 *  - {@see $tenantBlob} — the OPAQUE serialized tenant context (the
 *    `TenantContext::forSerialization()` form, JSON-encoded by the caller). R1
 *    never interprets it; R2's re-establishment (`fromQueuePayload`) does. It is
 *    here, not in the worker-local registry, precisely because this row is read
 *    cross-worker.
 *
 * A live identity-bearing object cannot be expressed in this shape, which is the
 * structural reason the cross-worker tier cannot leak identity (design §C.6).
 */
final readonly class SubscriptionRecord
{
    /**
     * @param list<string> $scopeKeys P1 resource keys (default = table name) this subscription watches.
     */
    public function __construct(
        public string $streamingId,
        public string $sessionId,
        public string $tenantId,
        public array $scopeKeys,
        public string $tenantBlob,
    ) {}

    public function watchesScope(string $scopeKey): bool
    {
        return in_array($scopeKey, $this->scopeKeys, true);
    }

    public function toRef(): SubscriberRef
    {
        return new SubscriberRef($this->streamingId, $this->sessionId);
    }
}
