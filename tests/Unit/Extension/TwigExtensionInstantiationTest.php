<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionMethod;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionCatalog;

/** A container that has never heard of the class. */
final class UnknownToTheContainer extends \RuntimeException implements NotFoundExceptionInterface {}

/**
 * How a Twig extension is built, and which container failures may be absorbed.
 *
 * The catalog builds extensions through the container so #[InjectAs*] property
 * injection runs, and falls back to `new` for the static-only extensions the
 * container does not manage. The fallback has to be narrow: "the container does
 * not know this class" is the one failure `new` answers. Anything else means
 * the container KNEW it and could not build it — a collaborator that is missing,
 * a boot that raised — and building it anyway with `new` hands back an
 * extension without its dependencies, which does not fail here. It fails inside
 * a template, as a typed property accessed before initialization, naming Twig
 * instead of the wiring that actually broke.
 */
final class TwigExtensionInstantiationTest extends TestCase
{
    private function instantiate(TwigExtensionCatalog $catalog, string $class): object
    {
        $method = new ReflectionMethod(TwigExtensionCatalog::class, 'instantiate');

        return $method->invoke($catalog, $class, new \ReflectionClass($class));
    }

    private function catalogWith(ContainerInterface $container): TwigExtensionCatalog
    {
        $catalog = (new \ReflectionClass(TwigExtensionCatalog::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(TwigExtensionCatalog::class, 'container'))->setValue($catalog, $container);

        return $catalog;
    }

    /** The container is what runs property injection, so it is asked first. */
    #[Test]
    public function an_extension_the_container_manages_comes_from_the_container(): void
    {
        $managed = new \stdClass();

        $catalog = $this->catalogWith(new class ($managed) implements ContainerInterface {
            public function __construct(private object $managed) {}

            public function get(string $id): object
            {
                return $this->managed;
            }

            public function has(string $id): bool
            {
                return true;
            }
        });

        self::assertSame($managed, $this->instantiate($catalog, \stdClass::class));
    }

    /** The one absorbed failure: a static-only extension the container does not manage. */
    #[Test]
    public function an_extension_the_container_does_not_know_falls_back_to_new(): void
    {
        $catalog = $this->catalogWith(new class implements ContainerInterface {
            public function get(string $id): object
            {
                throw new UnknownToTheContainer($id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        });

        self::assertInstanceOf(\stdClass::class, $this->instantiate($catalog, \stdClass::class));
    }

    /**
     * A container that knew the class and could not build it must not be
     * absorbed. Swallowing it produces an extension missing its collaborators
     * and moves the failure into a template, several layers from the cause.
     */
    #[Test]
    public function a_real_construction_failure_is_not_swallowed(): void
    {
        $catalog = $this->catalogWith(new class implements ContainerInterface {
            public function get(string $id): object
            {
                throw new \RuntimeException('Cannot inject FooRepository into ' . $id);
            }

            public function has(string $id): bool
            {
                return true;
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot inject FooRepository');

        $this->instantiate($catalog, \stdClass::class);
    }
}
