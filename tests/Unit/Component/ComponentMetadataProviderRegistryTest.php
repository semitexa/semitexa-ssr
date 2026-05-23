<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\Component\ComponentMetadataProviderRegistry;
use Semitexa\Ssr\Attribute\AsComponentMetadataProvider;
use Semitexa\Ssr\Domain\Contract\ComponentMetadataProviderInterface;
use Semitexa\Ssr\Domain\Exception\InvalidComponentConfigurationException;

#[AsComponentMetadataProvider(priority: 50)]
final class CmprtMidProvider implements ComponentMetadataProviderInterface
{
    public bool $injected = false;

    public function supports(ReflectionClass $componentClass): bool
    {
        return true;
    }

    public function getProps(ReflectionClass $componentClass): array
    {
        return ['mid' => 'mid', 'override' => 'mid'];
    }
}

#[AsComponentMetadataProvider(priority: 10)]
final class CmprtEarlyProvider implements ComponentMetadataProviderInterface
{
    public function supports(ReflectionClass $componentClass): bool
    {
        return true;
    }

    public function getProps(ReflectionClass $componentClass): array
    {
        return ['early' => 'early', 'override' => 'early'];
    }
}

#[AsComponentMetadataProvider(priority: 100)]
final class CmprtSpecificProvider implements ComponentMetadataProviderInterface
{
    public function supports(ReflectionClass $componentClass): bool
    {
        return $componentClass->getName() === CmprtTargetComponent::class;
    }

    public function getProps(ReflectionClass $componentClass): array
    {
        return ['specific' => 'yes'];
    }
}

#[AsComponentMetadataProvider]
final class CmprtBrokenProvider
{
    public function supports(ReflectionClass $componentClass): bool
    {
        return true;
    }
}

#[AsComponentMetadataProvider]
final class CmprtSupportsThrowsProvider implements ComponentMetadataProviderInterface
{
    public function supports(ReflectionClass $componentClass): bool
    {
        throw new \RuntimeException('supports() blew up');
    }

    public function getProps(ReflectionClass $componentClass): array
    {
        return [];
    }
}

#[AsComponentMetadataProvider]
final class CmprtCtorThrowsProvider implements ComponentMetadataProviderInterface
{
    public function __construct()
    {
        throw new \RuntimeException('ctor blew up');
    }

    public function supports(ReflectionClass $componentClass): bool
    {
        return true;
    }

    public function getProps(ReflectionClass $componentClass): array
    {
        return [];
    }
}

final class CmprtTargetComponent {}
final class CmprtOtherComponent {}

final class CmprtFakeClassDiscovery extends ClassDiscovery
{
    /** @param list<string> $classes */
    public function __construct(private readonly array $classes)
    {
    }

    public function initialize(): void
    {
    }

    public function findClassesWithAttribute(string $attributeClass): array
    {
        return $this->classes;
    }
}

final class CmprtFakeModuleRegistry extends ModuleRegistry
{
    public function __construct()
    {
    }

    public function initialize(): void
    {
    }

    public function isClassActive(string $className): bool
    {
        return true;
    }
}

final class CmprtFakeContainer implements ContainerInterface
{
    /** @var array<string, object> */
    private array $items = [];

    public function set(string $id, object $item): void
    {
        $this->items[$id] = $item;
    }

    public function get(string $id): object
    {
        if (!isset($this->items[$id])) {
            throw new \RuntimeException('not bound: ' . $id);
        }
        return $this->items[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->items[$id]);
    }
}

final class ComponentMetadataProviderRegistryTest extends TestCase
{
    /** @param list<string> $providerClasses */
    private function buildRegistry(array $providerClasses, ?ContainerInterface $container = null): ComponentMetadataProviderRegistry
    {
        $registry = new ComponentMetadataProviderRegistry();
        $ref = new ReflectionClass(ComponentMetadataProviderRegistry::class);
        $ref->getProperty('classDiscovery')->setValue($registry, new CmprtFakeClassDiscovery($providerClasses));
        $ref->getProperty('moduleRegistry')->setValue($registry, new CmprtFakeModuleRegistry());
        $ref->getProperty('container')->setValue($registry, $container ?? new CmprtFakeContainer());
        return $registry;
    }

    public function testProvidersSortedByPriorityAscending(): void
    {
        $registry = $this->buildRegistry([CmprtMidProvider::class, CmprtEarlyProvider::class]);

        $providers = $registry->getProviders(new ReflectionClass(CmprtTargetComponent::class));

        self::assertCount(2, $providers);
        self::assertInstanceOf(CmprtEarlyProvider::class, $providers[0]);
        self::assertInstanceOf(CmprtMidProvider::class, $providers[1]);
    }

    public function testSupportsFiltersProviders(): void
    {
        $registry = $this->buildRegistry([
            CmprtEarlyProvider::class,
            CmprtSpecificProvider::class,
        ]);

        $targetProviders = $registry->getProviders(new ReflectionClass(CmprtTargetComponent::class));
        $otherProviders = $registry->getProviders(new ReflectionClass(CmprtOtherComponent::class));

        self::assertCount(2, $targetProviders);
        self::assertCount(1, $otherProviders);
        self::assertInstanceOf(CmprtEarlyProvider::class, $otherProviders[0]);
    }

    public function testClassWithoutInterfaceThrowsAtBuildTime(): void
    {
        $registry = $this->buildRegistry([CmprtBrokenProvider::class]);

        $this->expectException(InvalidComponentConfigurationException::class);
        $this->expectExceptionMessage(CmprtBrokenProvider::class);

        $registry->ensureBuilt();
    }

    public function testSupportsThrowingIsWrappedAsConfigError(): void
    {
        $registry = $this->buildRegistry([CmprtSupportsThrowsProvider::class]);

        $this->expectException(InvalidComponentConfigurationException::class);
        $this->expectExceptionMessage(CmprtSupportsThrowsProvider::class);
        $this->expectExceptionMessage(CmprtTargetComponent::class);
        $this->expectExceptionMessage('supports() blew up');

        $registry->getProviders(new ReflectionClass(CmprtTargetComponent::class));
    }

    public function testResolutionFailureIsWrappedAsConfigError(): void
    {
        // Container::has() returns false (CmprtFakeContainer is empty), so
        // ComponentMetadataProviderRegistry::resolveInstance falls through to
        // `new $className()` — which here throws from the ctor.
        $registry = $this->buildRegistry([CmprtCtorThrowsProvider::class]);

        $this->expectException(InvalidComponentConfigurationException::class);
        $this->expectExceptionMessage(CmprtCtorThrowsProvider::class);
        $this->expectExceptionMessage('ctor blew up');

        $registry->getProviders(new ReflectionClass(CmprtTargetComponent::class));
    }

    public function testContainerResolvedInstanceIsUsed(): void
    {
        $container = new CmprtFakeContainer();
        $injectedInstance = new CmprtMidProvider();
        $injectedInstance->injected = true;
        $container->set(CmprtMidProvider::class, $injectedInstance);

        $registry = $this->buildRegistry([CmprtMidProvider::class], $container);

        $providers = $registry->getProviders(new ReflectionClass(CmprtTargetComponent::class));

        self::assertCount(1, $providers);
        self::assertSame($injectedInstance, $providers[0]);
        self::assertTrue($providers[0]->injected);
    }
}
