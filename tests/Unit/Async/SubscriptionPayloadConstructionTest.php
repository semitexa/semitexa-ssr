<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Http\PayloadFactory;

/**
 * An SSE re-run must rebuild its request DTO the way the first request built
 * it.
 *
 * PipelineSubscriptionFactory used `new $dtoClass()` and nothing else, while
 * the ordinary path goes through RouteExecutor::createBarePayload(): payload
 * PARTS, then PayloadFactory::createInstance(), then PropertyInjector::inject().
 *
 * The consequence was not subtle once it bit. A payload with an
 * #[InjectAsReadonly] dependency came back from a re-run with that property
 * UNINITIALIZED — so a feed that worked on the first request threw on the first
 * re-run, in a draining coroutine far from anything that named the cause.
 *
 * These pin the difference itself rather than the wiring, so the change rests
 * on something measured.
 */
final class SubscriptionPayloadConstructionTest extends TestCase
{
    /** The defect: plain construction leaves an injected property unset. */
    #[Test]
    public function plain_construction_leaves_an_injected_property_uninitialized(): void
    {
        $dto = new SubscriptionPayloadProbe();

        $property = new \ReflectionProperty(SubscriptionPayloadProbe::class, 'collaborator');

        self::assertFalse(
            $property->isInitialized($dto),
            'if new already injected, the factory change would be pointless',
        );
    }

    /** And reading it is a fatal, not a null — which is why it surfaced far away. */
    #[Test]
    public function reading_an_uninitialized_injected_property_throws(): void
    {
        $dto = new SubscriptionPayloadProbe();

        $this->expectException(\Error::class);
        $dto->collaboratorClass();
    }

    /** The canonical sequence the factory now mirrors. */
    #[Test]
    public function the_canonical_sequence_produces_a_usable_payload(): void
    {
        $dto = PayloadFactory::createInstance(SubscriptionPayloadProbe::class, []);
        PropertyInjector::inject($dto, ContainerFactory::get());

        $property = new \ReflectionProperty(SubscriptionPayloadProbe::class, 'collaborator');

        self::assertTrue($property->isInitialized($dto), 'the re-run must get what the request got');
        self::assertNotSame('', $dto->collaboratorClass());
    }

    /**
     * createInstance() with no parts still yields the base class, so a payload
     * that declares none is unaffected by the change.
     */
    #[Test]
    public function a_payload_with_no_parts_is_still_its_own_class(): void
    {
        $dto = PayloadFactory::createInstance(SubscriptionPayloadProbe::class, []);

        self::assertInstanceOf(SubscriptionPayloadProbe::class, $dto);
    }
}

/** A payload shaped like a feed request: one container-managed dependency. */
class SubscriptionPayloadProbe
{
    #[InjectAsReadonly]
    protected \Semitexa\Core\Discovery\AttributeDiscovery $collaborator;

    public function collaboratorClass(): string
    {
        return $this->collaborator::class;
    }
}
