<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Ssr\Domain\Contract\SubscriberIndexInterface;
use Semitexa\Ssr\Domain\Model\SubscriberRef;

/**
 * Tier 3 of the three-tier subscription store (Track R · R1, design §C.5): the
 * reverse index, scan implementation.
 *
 * Resolves a `(tenant, scopeKey)` push to the local subscriptions watching that
 * scope by SCANNING the {@see SubscriptionTable} — O(rows) — and returning a
 * {@see SubscriberRef} per row whose `tenant_id` matches AND whose `scope_keys`
 * contains the key. This is the deliberate first implementation (design §C.5,
 * Phase-0 micro-dec #3): correct and simple, no extra index to keep coherent.
 *
 * THE SEAM (design §C.5): the only structural contract callers depend on is
 * {@see SubscriberIndexInterface}. A future keyed-Table implementation — a second
 * {@see \Swoole\Table} keyed by `md5(tenant|scopeKey)` — can replace this scan
 * without touching any caller, once concurrent-streams × invalidation-rate make
 * the O(rows) cost measurable. Until then the scan is the chosen path; the cost
 * profile is logged at the call site (R3), so the scan is never a silent cap.
 *
 * R1 is the store ONLY: this resolves scope→subscribers and stops there. It does
 * not subscribe a channel, deliver a control, or run a re-run (R3/R4 do).
 */
final class ScanningSubscriberIndex implements SubscriberIndexInterface
{
    public function __construct(
        private readonly SubscriptionTable $subscriptions,
    ) {}

    public function find(string $tenant, string $scopeKey): array
    {
        $refs = [];
        foreach ($this->subscriptions->all() as $record) {
            if ($record->tenantId === $tenant && $record->watchesScope($scopeKey)) {
                $refs[] = new SubscriberRef($record->streamingId, $record->sessionId);
            }
        }

        return $refs;
    }
}
