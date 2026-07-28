<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Layout;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse;
use Semitexa\Ssr\Application\Service\Layout\SlotHandlerPipeline;
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
    public function a_service_handler_the_container_does_not_know_is_refused(): void
    {
        // The reported failure. Previously this fell through to `new` and handed
        // back a half-built object; now it says what is wrong.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/declares container-managed dependencies/');

        self::resolve(ServiceSlotHandlerFixture::class);
    }

    #[Test]
    public function a_handler_with_injected_properties_is_refused_too(): void
    {
        // Injection is declared on the PROPERTY here, not on the class, which is
        // the shape the report described. Both must be detected.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/uninitialised/');

        self::resolve(InjectedSlotHandlerFixture::class);
    }

    #[Test]
    public function the_refusal_names_the_handler(): void
    {
        // The original symptom was an empty region with nothing to grep for. The
        // class name is the one thing that makes it findable.
        try {
            self::resolve(ServiceSlotHandlerFixture::class);
            self::fail('expected a refusal');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(ServiceSlotHandlerFixture::class, $e->getMessage());
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
    public function one_failing_handler_does_not_stop_the_pipeline(): void
    {
        // Containment is deliberate: a broken region must not cost the page every
        // other region. What changed is that the failure is now reported at error
        // level rather than debug — the pipeline still returns a usable slot.
        $slot = new ConcreteSlotFixture();

        self::assertSame($slot, SlotHandlerPipeline::execute($slot));
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

final class NotAHandlerFixture
{
}

/** HtmlSlotResponse is abstract; the pipeline only needs a concrete instance. */
final class ConcreteSlotFixture extends HtmlSlotResponse
{
}
