<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

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
    /** @var array<string, array{callback: callable, options: array}> */
    private array $functions = [];

    /** @var array<string, callable> */
    private array $filters = [];

    private bool $initialized = false;
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

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
            $reflection = new \ReflectionClass($class);

            if (!$reflection->isInstantiable()) {
                continue;
            }

            try {
                $extension = $reflection->newInstance();

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

    /** @return array<string, array{callback: callable, options: array}> */
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
