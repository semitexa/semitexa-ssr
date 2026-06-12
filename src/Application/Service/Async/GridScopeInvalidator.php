<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Ssr\Domain\Contract\ScopeInvalidationBusInterface;
use Semitexa\Tenancy\Context\TenantContext;

/**
 * Track R · the raw-DB one-liner for live-on-events.
 *
 * The ORM-backed path goes live for FREE: the `AggregateWriteEngine` emits a
 * {@see \Semitexa\Orm\Domain\Event\ResourceChangedEvent} on every write and
 * {@see ResourceInvalidationPublisher} turns it into a
 * `ui.invalidate.{tenant}.{scopeKey}` publish — zero publish code in the
 * application. A NON-ORM (raw-DB / hand-rolled SQL) write path has no such
 * chokepoint, so it would otherwise have to hand-assemble the channel exactly
 * like the leads demo-add does:
 *
 *   $bus->publish(ResourceInvalidationPublisher::channelFor('default', $scope));
 *
 * This helper collapses that boilerplate to ONE call any raw-DB write can make
 * after it commits:
 *
 *   $this->gridScopeInvalidator->touch('ui_playground_leads');
 *
 * It publishes the IDENTICAL channel/message the ORM auto-publish does — same
 * {@see ResourceInvalidationPublisher::channelFor()} naming, same
 * {@see TenantContext} resolution, same {@see ScopeInvalidationBusInterface}
 * transport — so a held-open collection stream whose feed payload declares
 * `#[WatchScopes('<scope>')]` re-runs and shows the new row live whether
 * the write came through the ORM or a raw query. The two producers are
 * indistinguishable to the subscriber (R3) by construction.
 *
 * Container-managed (`#[AsService]`): the bus is property-injected via
 * `#[InjectAsReadonly]`; a parameterless constructor is required for the
 * container to instantiate then populate it. The publish is best-effort and
 * data-less, exactly like the publisher's: a swallowed failure costs at most
 * one missed re-query, repaired by the next mutation's signal.
 */
#[AsService]
final class GridScopeInvalidator
{
    #[InjectAsReadonly]
    protected ScopeInvalidationBusInterface $bus;

    /**
     * Publish a data-less scope-invalidation on `ui.invalidate.{tenant}.{scope}`
     * — the SAME channel the ORM auto-publish names — so every held-open grid
     * stream watching `$scope` re-runs. A blank scope is a no-op (nothing to
     * key a channel on). Tenant resolves from the ambient {@see TenantContext},
     * defaulting to `default`, identically to {@see ResourceInvalidationPublisher::handle()}.
     */
    public function touch(string $scope): void
    {
        $scope = trim($scope);
        if ($scope === '') {
            return;
        }

        $tenant = TenantContext::get()?->getTenantId() ?? 'default';

        $this->bus->publish(ResourceInvalidationPublisher::channelFor($tenant, $scope));
    }
}
