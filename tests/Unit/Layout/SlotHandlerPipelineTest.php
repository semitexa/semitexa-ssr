<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Layout;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse;
use Semitexa\Ssr\Application\Service\Layout\SlotHandlerPipeline;
use Semitexa\Ssr\Application\Service\Layout\SlotHandlerRegistry;
use Semitexa\Ssr\Domain\Contract\TypedSlotHandlerInterface;

/**
 * Handler resolution, after the silent-failure report from a consumer project.
 *
 * The defect was not that resolution could fail — it was that failure was
 * indistinguishable from success with nothing to render. A missing binding fell
 * through to a bare `new`, which for a handler expecting injected collaborators
 * produced an object with uninitialised properties; whatever it then threw was
 * recorded at debug level and the region simply came out empty.
 */
final class SlotHandlerPipelineTest extends TestCase
{
    /**
     * The registry is a process-wide static that `AttributeDiscovery` fills
     * exactly once, at boot, behind a guard. `reset()` therefore does not clear
     * it "for this test" — it clears it for every test that runs afterwards in
     * the same process, and nothing ever puts the entries back.
     *
     * This test used to reset() in a finally and leave it empty, which silently
     * emptied the slot pipeline for the rest of the suite. Any later test that
     * renders a real slot then saw a region with no handlers and no error: the
     * exact indistinguishable-empty symptom this file exists to pin.
     *
     * So snapshot the real contents and put them back, rather than assuming the
     * registry was empty to begin with.
     *
     * @return array<string, list<array{handlerClass: string, priority: int}>>
     */
    private static function snapshotRegistry(): array
    {
        $p = new ReflectionProperty(SlotHandlerRegistry::class, 'handlers');
        $p->setAccessible(true);

        /** @var array<string, list<array{handlerClass: string, priority: int}>> $handlers */
        $handlers = $p->getValue();

        return $handlers;
    }

    /**
     * @param array<string, list<array{handlerClass: string, priority: int}>> $handlers
     */
    private static function restoreRegistry(array $handlers): void
    {
        $p = new ReflectionProperty(SlotHandlerRegistry::class, 'handlers');
        $p->setAccessible(true);
        $p->setValue(null, $handlers);
    }

    private static function resolve(string $handlerClass): mixed
    {
        $m = new ReflectionMethod(SlotHandlerPipeline::class, 'resolveHandler');
        $m->setAccessible(true);

        return $m->invoke(null, $handlerClass);
    }

    #[Test]
    public function a_plain_handler_is_instantiated_directly(): void
    {
        // Nothing injected, nothing declared — `new` is legitimate here and must
        // keep working, or every simple slot handler would need a binding.
        self::assertInstanceOf(PlainSlotHandlerFixture::class, self::resolve(PlainSlotHandlerFixture::class));
    }

    #[Test]
    public function a_service_handler_with_nothing_injected_is_still_built(): void
    {
        // Self-review correction: #[AsService] alone does NOT make `new` unsafe.
        // A service with a parameterless constructor and no injected properties
        // constructs perfectly well, so refusing it would break setups that work
        // today. It is reported as a probable missing binding and then built.
        self::assertInstanceOf(ServiceSlotHandlerFixture::class, self::resolve(ServiceSlotHandlerFixture::class));
    }

    #[Test]
    public function a_handler_with_injected_properties_is_refused(): void
    {
        // The provable case: these properties can only be filled by the
        // container, so `new` yields an object that fatals on first access.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/uninitialised/');

        self::resolve(InjectedSlotHandlerFixture::class);
    }

    #[Test]
    public function the_refusal_names_the_property_that_would_be_uninitialised(): void
    {
        // Naming the class is not enough to act on — the property is what tells
        // the reader which binding is missing.
        try {
            self::resolve(InjectedSlotHandlerFixture::class);
            self::fail('expected a refusal');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('$collaborator', $e->getMessage());
        }
    }

    #[Test]
    public function a_service_that_also_injects_is_refused(): void
    {
        // Both markers present: the injected property decides, not the class
        // attribute.
        $this->expectException(\RuntimeException::class);

        self::resolve(InjectingServiceSlotHandlerFixture::class);
    }

    #[Test]
    public function the_refusal_names_the_handler(): void
    {
        // The original symptom was an empty region with nothing to grep for. The
        // class name is the one thing that makes it findable.
        try {
            self::resolve(InjectedSlotHandlerFixture::class);
            self::fail('expected a refusal');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(InjectedSlotHandlerFixture::class, $e->getMessage());
        }
    }

    #[Test]
    public function a_class_that_is_not_a_slot_handler_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must implement TypedSlotHandlerInterface/');

        self::resolve(NotAHandlerFixture::class);
    }

    #[Test]
    public function one_failing_handler_does_not_stop_the_ones_after_it(): void
    {
        // Review caught that this used to register nothing, so it passed whether
        // or not containment worked — the exact shape of test this PR exists to
        // criticise elsewhere. It now registers a handler that throws, followed by
        // one that records, and asserts the second still ran.
        $snapshot = self::snapshotRegistry();
        SlotHandlerRegistry::reset();
        RecordingSlotHandlerFixture::$ran = false;

        try {
            // Ascending priority, so the throwing handler must have the LOWER
            // number to run first. Registered the other way round, the recorder
            // would run before the exception and the assertion below would hold
            // even if the pipeline stopped dead on failure.
            SlotHandlerRegistry::register(ConcreteSlotFixture::class, ThrowingSlotHandlerFixture::class, 0);
            SlotHandlerRegistry::register(ConcreteSlotFixture::class, RecordingSlotHandlerFixture::class, 10);

            $slot = new ConcreteSlotFixture();
            $result = SlotHandlerPipeline::execute($slot);

            self::assertTrue(
                RecordingSlotHandlerFixture::$ran,
                'a handler that throws must not stop the handlers queued after it',
            );
            self::assertInstanceOf(ConcreteSlotFixture::class, $result);
        } finally {
            self::restoreRegistry($snapshot);
            RecordingSlotHandlerFixture::$ran = false;
        }
    }
}

final class PlainSlotHandlerFixture implements TypedSlotHandlerInterface
{
    public function handle(object $slot): object
    {
        return $slot;
    }
}

#[AsService]
final class ServiceSlotHandlerFixture implements TypedSlotHandlerInterface
{
    public function handle(object $slot): object
    {
        return $slot;
    }
}

final class InjectedSlotHandlerFixture implements TypedSlotHandlerInterface
{
    #[InjectAsReadonly]
    protected \stdClass $collaborator;

    public function handle(object $slot): object
    {
        // Would fatal on an uninitialised typed property — which is exactly the
        // half-built object the old bare `new` fallback produced.
        return $slot;
    }
}

#[AsService]
final class InjectingServiceSlotHandlerFixture implements TypedSlotHandlerInterface
{
    #[InjectAsReadonly]
    protected \stdClass $collaborator;

    public function handle(object $slot): object
    {
        return $slot;
    }
}

final class NotAHandlerFixture
{
}

final class ThrowingSlotHandlerFixture implements TypedSlotHandlerInterface
{
    public function handle(object $slot): object
    {
        throw new \RuntimeException('handler blew up');
    }
}

final class RecordingSlotHandlerFixture implements TypedSlotHandlerInterface
{
    public static bool $ran = false;

    public function handle(object $slot): object
    {
        self::$ran = true;

        return $slot;
    }
}

/** HtmlSlotResponse is abstract; the pipeline only needs a concrete instance. */
final class ConcreteSlotFixture extends HtmlSlotResponse
{
}
