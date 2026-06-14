<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\GridScopeInvalidator;
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationPublisher;
use Semitexa\Ssr\Domain\Contract\ScopeInvalidationBusInterface;
use Semitexa\Tenancy\Context\TenantContext;

/**
 * Track R · the raw-DB one-liner, proven in ISOLATION.
 *
 * Proves {@see GridScopeInvalidator::touch()} publishes the IDENTICAL
 * `ui.invalidate.{tenant}.{scope}` message the ORM auto-publish
 * ({@see ResourceInvalidationPublisher}) does — same channel naming, same
 * tenant resolution, same data-less bus surface — so a raw-DB write goes live
 * on the SAME held-open grid stream an ORM write would. No Redis, no
 * subscriber: a capturing bus double records the channel.
 */
final class GridScopeInvalidatorTest extends TestCase
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
     * Build the helper and inject the bus the way the container does
     * (property injection — the helper is a container-managed `#[AsService]`).
     */
    private function invalidator(ScopeInvalidationBusInterface $bus): GridScopeInvalidator
    {
        $invalidator = new GridScopeInvalidator();
        $property = new \ReflectionProperty(GridScopeInvalidator::class, 'bus');
        $property->setValue($invalidator, $bus);

        return $invalidator;
    }

    #[Test]
    public function touch_publishes_the_data_less_signal_on_the_default_tenant_channel(): void
    {
        TenantContext::clear();
        $bus = $this->captureBus();

        $this->invalidator($bus)->touch('ui_playground_leads');

        self::assertSame(['ui.invalidate.default.ui_playground_leads'], $bus->channels);
    }

    #[Test]
    public function touch_scopes_the_channel_to_the_ambient_tenant(): void
    {
        TenantContext::set(TenantContext::fromResolution('t1', 'test'));
        $bus = $this->captureBus();

        $this->invalidator($bus)->touch('ui_playground_leads');

        self::assertSame(['ui.invalidate.t1.ui_playground_leads'], $bus->channels);
    }

    #[Test]
    public function touch_publishes_the_exact_channel_the_orm_auto_publish_names(): void
    {
        // The whole point of the helper: a raw-DB touch() and an ORM-driven
        // publish are INDISTINGUISHABLE on the wire. Both name the channel via
        // ResourceInvalidationPublisher::channelFor() with the same tenant +
        // scope, so the subscriber (R3) re-runs identically for either origin.
        TenantContext::clear();
        $bus = $this->captureBus();

        $this->invalidator($bus)->touch('ui_playground_pings');

        self::assertSame(
            [ResourceInvalidationPublisher::channelFor('default', 'ui_playground_pings')],
            $bus->channels,
        );
    }

    #[Test]
    public function touch_with_a_blank_scope_is_a_no_op(): void
    {
        $bus = $this->captureBus();

        $this->invalidator($bus)->touch('   ');
        $this->invalidator($bus)->touch('');

        self::assertSame([], $bus->channels);
    }
}
