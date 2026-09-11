<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Ssr\Attribute\AsTwigExtension;
use Semitexa\Core\Log\StaticLoggerBridge;
use Twig\TwigFunction;
use Twig\TwigFilter;

#[AsService]
final class TwigExtensionCatalog
{
    /**
     * Twig's own option bag: is_safe, needs_context, needs_environment and the
     * like. Stated as a map of strings to anything rather than a bare `array`
     * because a value type is what makes the property checkable at all.
     *
     * @var array<string, array{callback: callable, options: array<string, mixed>}>
     */
    private array $functions = [];

    /** @var array<string, callable> */
    private array $filters = [];

    private bool $initialized = false;
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    /**
     * So an extension can have collaborators.
     *
     * Every extension shipped before this was static-only, which hid the fact
     * that `new` was the only way one was ever built: the first extension to
     * declare an #[InjectAsReadonly] property got an uninitialised typed
     * property and a Twig RuntimeError the first time its function ran —
     * at render time, on a page, not at boot.
     *
     * Absent in a build that wires the catalog by hand, which is why the
     * fallback to `new` stays rather than becoming a hard requirement.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    public function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        $this->classDiscovery = $classDiscovery;
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        if (!isset($this->classDiscovery)) {
            throw new \LogicException('TwigExtensionCatalog requires ClassDiscovery instance. Call setClassDiscovery() first.');
        }

        $extensionClasses = $this->classDiscovery->findClassesWithAttribute(AsTwigExtension::class);

        // Set before the loop, not after: discovered extensions register through
        // the TwigExtensionRegistry facade, and any of them that also *reads*
        // getFunctions()/getFilters() while registering re-enters initialize().
        // With the flag still false at that point the guard above would not hold
        // and discovery would restart underneath the running loop.
        $this->initialized = true;

        foreach ($extensionClasses as $class) {
            // Discovery hands back plain strings; ReflectionClass wants a
            // class-string. The guard is not ceremony — a stale discovery cache
            // can name a class that no longer exists, and reflecting it throws
            // rather than skipping.
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if (!$reflection->isInstantiable()) {
                continue;
            }

            try {
                $extension = $this->instantiate($class, $reflection);

                if (method_exists($extension, 'registerFunctions')) {
                    $extension->registerFunctions();
                }

                if (method_exists($extension, 'registerFilters')) {
                    $extension->registerFilters();
                }
            } catch (\Throwable $e) {
                StaticLoggerBridge::error('ssr', 'Failed to load Twig extension', [
                    'class' => $class,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Build one extension, through the container when it can.
     *
     * The container is what runs #[InjectAs*] property injection; `new` does
     * not, and an extension with an uninjected property fails later, inside a
     * template, where the message names Twig rather than the wiring. Falling
     * back to `new` keeps every static-only extension working in a build where
     * the catalog was assembled without a container.
     *
     * @param class-string $class
     * @param \ReflectionClass<object> $reflection
     */
    private function instantiate(string $class, \ReflectionClass $reflection): object
    {
        if (isset($this->container)) {
            try {
                return $this->container->get($class);
            } catch (NotFoundExceptionInterface) {
                // The ONLY failure `new` is an answer to: the container has
                // never heard of this class, which is what a static-only
                // extension looks like. Any other throwable means the container
                // knew it and could not build it — a missing collaborator, a
                // constructor that raised — and swallowing that would hand back
                // an extension without its dependencies. That does not fail
                // here; it fails inside a template, as a property accessed
                // before initialization, naming Twig instead of the wiring.
                // Let it reach initialize(), which logs the real boot error.
            }
        }

        return $reflection->newInstance();
    }

    /**
     * @param array<string, mixed> $options Twig function options, passed through untouched
     */
    public function registerFunction(
        string $name,
        callable $callback,
        array $options = []
    ): void {
        $this->functions[$name] = [
            'callback' => $callback,
            'options' => $options,
        ];
    }

    public function registerFilter(string $name, callable $callback): void
    {
        $this->filters[$name] = $callback;
    }

    /** @return array<string, array{callback: callable, options: array<string, mixed>}> */
    public function getFunctions(): array
    {
        $this->initialize();
        return $this->functions;
    }

    /** @return array<string, callable> */
    public function getFilters(): array
    {
        $this->initialize();
        return $this->filters;
    }
}
