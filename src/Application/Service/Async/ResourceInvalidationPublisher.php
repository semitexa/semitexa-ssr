<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Event\EventExecution;
use Semitexa\Core\Exception\ConfigurationException;
use Semitexa\Ssr\Domain\Contract\ScopeInvalidationBusInterface;
use Semitexa\Tenancy\Context\TenantContext;

/**
 * Track R · P3 — the cross-instance push ORIGIN.
 *
 * Listens for the ORM's {@see \Semitexa\Orm\Domain\Event\ResourceChangedEvent}
 * (P2, emitted from the `AggregateWriteEngine` write chokepoint) and PUBLISHes
 * a DATA-LESS scope-invalidation signal to `ui.invalidate.{tenant}.{scopeKey}`.
 * The subscriber (R3) maps that channel back to local subscribers via R1's
 * reverse index `find(tenant, scopeKey)` and triggers each owning worker's
 * re-run — so the channel name is the contract between producer and subscriber.
 *
 * Publisher side ONLY: no SUBSCRIBE, no reverse-index lookup, no fan-out, no
 * dispatcher wiring into OrmManager (that is a separate runtime brick).
 *
 * The event class is referenced by FQCN string (not imported) on purpose:
 * ssr does not declare a Composer dependency on semitexa-orm, and the
 * publisher only needs the event's `resourceKey` (a plain string). Operation
 * is deliberately NOT consumed — the channel name carries the full routing
 * key, and the re-run re-queries fresh regardless of the operation.
 *
 * SECURITY INVARIANT (GATE-1 §T5): the event is data-less and the tenant is
 * resolved from ambient {@see TenantContext} at run-time, so this listener
 * MUST run synchronously in the same request/coroutine as the write. Drift to
 * Async (deferred — context may be torn down) or Queued (another worker —
 * context definitely lost) silently mis-scopes the channel → cross-tenant
 * invalidation leak. The invariant is pinned STRUCTURALLY (not by comment):
 * {@see self::assertSyncExecutionPinned()} reads this class's own declared
 * execution mode and boot-fails if it is ever flipped off Sync — enforced at
 * worker boot ({@see PinResourceInvalidationPublisherSyncListener}) and again
 * on every instantiation (the constructor below).
 */
#[AsEventListener(event: self::EVENT_CLASS, execution: EventExecution::Sync)]
final class ResourceInvalidationPublisher
{
    /** FQCN of the P2 event (semitexa-orm); referenced as a string to avoid a cross-package import/dependency. */
    public const EVENT_CLASS = 'Semitexa\\Orm\\Domain\\Event\\ResourceChangedEvent';

    /** The single tenant-scoped channel namespace the subscriber (R3) keys on. */
    public const CHANNEL_PREFIX = 'ui.invalidate';

    // Property injection (not constructor args): this listener is a
    // container-managed type, so DI is via #[InjectAsReadonly] properties.
    #[InjectAsReadonly]
    protected ScopeInvalidationBusInterface $bus;

    public function __construct()
    {
        // Fail-closed at instantiation: even if the boot guard were bypassed,
        // the listener cannot be constructed to handle an event off Sync.
        self::assertSyncExecutionPinned();
    }

    /**
     * @param object $event A {@see self::EVENT_CLASS} instance (typed `object`
     *                      to avoid importing the orm event into ssr).
     */
    public function handle(object $event): void
    {
        $scopeKey = self::resourceKeyOf($event);
        if ($scopeKey === null) {
            return;
        }

        $tenant = TenantContext::get()?->getTenantId() ?? 'default';

        $this->bus->publish(self::channelFor($tenant, $scopeKey));
    }

    /**
     * The channel name R3 resolves back via R1's `find(tenant, scopeKey)`:
     * the same `tenant` resolved here + the same P1-derived `resourceKey` the
     * event carries. Producer and subscriber agree by construction.
     */
    public static function channelFor(string $tenant, string $scopeKey): string
    {
        return self::CHANNEL_PREFIX . '.' . $tenant . '.' . $scopeKey;
    }

    /**
     * Read the P1 `resourceKey` (a non-empty string) off the event without
     * coupling to the orm type. Returns null when absent/blank (the publish
     * is then skipped).
     */
    private static function resourceKeyOf(object $event): ?string
    {
        if (!property_exists($event, 'resourceKey')) {
            return null;
        }

        /** @var mixed $value */
        $value = $event->resourceKey;
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * STRUCTURAL synchrony pin (GATE-1 §T5). Reads this class's OWN declared
     * `#[AsEventListener]` execution mode and boot-fails unless it is Sync.
     * A maintainer flipping the attribute to Async/Queued hits this hard
     * failure rather than a silent cross-tenant leak.
     */
    public static function assertSyncExecutionPinned(): void
    {
        $attributes = (new \ReflectionClass(self::class))->getAttributes(AsEventListener::class);
        if ($attributes === []) {
            throw new ConfigurationException(sprintf(
                '%s must declare #[AsEventListener] (the synchrony pin has nothing to verify).',
                self::class,
            ));
        }

        /** @var AsEventListener $listener */
        $listener = $attributes[0]->newInstance();
        self::assertExecutionModePinned($listener->execution);
    }

    /**
     * The pin's pure core: any execution mode other than Sync is a hard
     * configuration error. Separated so it is provable for every enum case
     * without mutating the real attribute.
     */
    public static function assertExecutionModePinned(EventExecution $declared): void
    {
        if ($declared !== EventExecution::Sync) {
            throw new ConfigurationException(sprintf(
                '%s MUST run with EventExecution::Sync (GATE-1 §T5: the data-less event resolves tenant '
                . 'from ambient context, so an Async/Queued hop loses the tenant and mis-scopes '
                . 'ui.invalidate.{tenant}.{scopeKey} → cross-tenant invalidation leak); declared as "%s".',
                self::class,
                $declared->value,
            ));
        }
    }
}
