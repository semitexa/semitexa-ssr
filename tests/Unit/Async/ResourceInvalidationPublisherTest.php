<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Event\EventExecution;
use Semitexa\Core\Exception\ConfigurationException;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationPublisher;
use Semitexa\Ssr\Domain\Contract\ScopeInvalidationBusInterface;
use Semitexa\Tenancy\Context\TenantContext;

/**
 * Track R · P3 — the publisher side of the push path, proven in ISOLATION.
 *
 * A manual event dispatch (NOT a live ORM write) drives the listener through a
 * capturing bus double; no Redis, no subscriber, no dispatcher-into-OrmManager
 * wiring. Proves: data-less publish on the correct `ui.invalidate.{tenant}.{scopeKey}`
 * channel, the channel matches R1's `find(tenant, scopeKey)` key, and the
 * STRUCTURAL synchrony pin fires for any execution mode other than Sync.
 */
final class ResourceInvalidationPublisherTest extends TestCase
{
    protected function tearDown(): void
    {
        TenantContext::clear();
    }

    private function captureBus(): ScopeInvalidationBusInterface
    {
        return new class implements ScopeInvalidationBusInterface {
            /** @var list<string> */
            public array $channels = [];

            public function publish(string $channel): void
            {
                $this->channels[] = $channel;
            }
        };
    }

    /**
     * Build the listener and inject the bus the way the container does
     * (property injection, not a constructor arg — the publisher is a
     * container-managed type, so #[InjectAsReadonly] is the DI channel).
     */
    private function publisher(ScopeInvalidationBusInterface $bus): ResourceInvalidationPublisher
    {
        $publisher = new ResourceInvalidationPublisher();
        $property = new \ReflectionProperty(ResourceInvalidationPublisher::class, 'bus');
        $property->setValue($publisher, $bus);

        return $publisher;
    }

    /** A stand-in event exposing only the `resourceKey` the publisher reads. */
    private function event(string $resourceKey): object
    {
        return new class($resourceKey) {
            public function __construct(public string $resourceKey) {}
        };
    }

    #[Test]
    public function publishes_data_less_signal_on_the_tenant_scoped_channel(): void
    {
        TenantContext::set(TenantContext::fromResolution('t1', 'test'));
        $bus = $this->captureBus();

        $this->publisher($bus)->handle($this->event('lead_submission'));

        // Exactly one publish, on the correct channel, carrying NO row data
        // (the bus surface has no payload parameter — data-less by construction).
        self::assertSame(['ui.invalidate.t1.lead_submission'], $bus->channels);
    }

    #[Test]
    public function defaults_to_the_default_tenant_when_no_context_is_set(): void
    {
        TenantContext::clear();
        $bus = $this->captureBus();

        $this->publisher($bus)->handle($this->event('lead_submission'));

        self::assertSame(['ui.invalidate.default.lead_submission'], $bus->channels);
    }

    #[Test]
    public function skips_publishing_when_the_event_carries_no_resource_key(): void
    {
        TenantContext::set(TenantContext::fromResolution('t1', 'test'));
        $bus = $this->captureBus();

        // An event with a blank key, and one with no key at all.
        $this->publisher($bus)->handle($this->event('   '));
        $this->publisher($bus)->handle(new \stdClass());

        self::assertSame([], $bus->channels);
    }

    #[Test]
    public function channel_is_built_from_the_same_tenant_and_p1_resource_key_R1_keys_on(): void
    {
        // The (tenant, scopeKey) pair R1's SubscriberIndexInterface::find() keys
        // on must be EXACTLY what the publisher names the channel from.
        $channel = ResourceInvalidationPublisher::channelFor('t1', 'lead_submission');
        self::assertSame('ui.invalidate.t1.lead_submission', $channel);

        // Round-trip: the channel decomposes back to the (tenant, scopeKey) pair.
        $segments = explode('.', $channel);
        self::assertSame(ResourceInvalidationPublisher::CHANNEL_PREFIX, $segments[0] . '.' . $segments[1]);
        self::assertSame('t1', $segments[2]);
        self::assertSame('lead_submission', $segments[3]);
    }

    #[Test]
    public function channel_matches_the_resource_key_a_real_P2_event_carries(): void
    {
        // Producer (P2 event) and subscriber index agree BY CONSTRUCTION because
        // both carry the same P1-derived resourceKey string. Proven against the
        // real orm event when present (orm is installed in this env, so this runs).
        $eventClass = ResourceInvalidationPublisher::EVENT_CLASS;
        $opEnum = 'Semitexa\\Orm\\Domain\\Enum\\ResourceChangeOperation';
        if (!class_exists($eventClass) || !enum_exists($opEnum)) {
            self::markTestSkipped('semitexa-orm not installed in this environment.');
        }

        /** @var object $event */
        $event = new $eventClass('lead_submission', $opEnum::Insert);
        TenantContext::set(TenantContext::fromResolution('default', 'test'));
        $bus = $this->captureBus();

        $this->publisher($bus)->handle($event);

        // The scopeKey segment of the published channel === the event's resourceKey.
        self::assertSame(
            ResourceInvalidationPublisher::channelFor('default', $event->resourceKey),
            $bus->channels[0] ?? null,
        );
        self::assertSame('ui.invalidate.default.lead_submission', $bus->channels[0] ?? null);
    }

    #[Test]
    public function synchrony_pin_accepts_sync(): void
    {
        // Pure-core guard accepts Sync, and the REAL declared attribute is Sync.
        ResourceInvalidationPublisher::assertExecutionModePinned(EventExecution::Sync);
        ResourceInvalidationPublisher::assertSyncExecutionPinned();

        // Constructing the listener (the boot/instantiation guard) succeeds.
        $this->expectNotToPerformAssertions();
        new ResourceInvalidationPublisher();
    }

    #[Test]
    public function synchrony_pin_rejects_async(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('MUST run with EventExecution::Sync');
        ResourceInvalidationPublisher::assertExecutionModePinned(EventExecution::Async);
    }

    #[Test]
    public function synchrony_pin_rejects_queued(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cross-tenant invalidation leak');
        ResourceInvalidationPublisher::assertExecutionModePinned(EventExecution::Queued);
    }

    #[Test]
    public function the_listener_is_declared_sync_on_its_attribute(): void
    {
        // The structural anchor: the real declared execution mode is Sync, so
        // flipping the attribute to Async/Queued (the deliberate-red proof) makes
        // assertSyncExecutionPinned() throw via the rejects_* paths above.
        $attributes = (new \ReflectionClass(ResourceInvalidationPublisher::class))
            ->getAttributes(AsEventListener::class);

        self::assertNotSame([], $attributes, 'publisher must declare #[AsEventListener]');

        /** @var AsEventListener $listener */
        $listener = $attributes[0]->newInstance();
        self::assertSame(EventExecution::Sync, $listener->execution);
        self::assertSame(ResourceInvalidationPublisher::EVENT_CLASS, $listener->event);
    }
}
