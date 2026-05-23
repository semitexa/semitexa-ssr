<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\Component\ComponentMetadataProviderRegistry;
use Semitexa\Ssr\Application\Service\Component\ComponentRegistry;
use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\Ssr\Attribute\AsComponentMetadataProvider;
use Semitexa\Ssr\Domain\Contract\ComponentMetadataProviderInterface;
use Semitexa\Ssr\Domain\Exception\InvalidComponentConfigurationException;

#[AsComponent(name: 'crmp_test_component')]
final class CrmpTestComponent {}

#[AsComponent(name: 'crmp_other_component')]
final class CrmpOtherComponent {}

#[AsComponentMetadataProvider]
final class CrmpSupportingProvider implements ComponentMetadataProviderInterface
{
    public function supports(ReflectionClass $componentClass): bool
    {
        return $componentClass->getName() === CrmpTestComponent::class;
    }

    public function getProps(ReflectionClass $componentClass): array
    {
        return ['from_provider' => 'yes', 'count' => 1];
    }
}

#[AsComponentMetadataProvider]
final class CrmpBrokenProvider implements ComponentMetadataProviderInterface
{
    public function supports(ReflectionClass $componentClass): bool
    {
        return true;
    }

    public function getProps(ReflectionClass $componentClass): array
    {
        throw new \RuntimeException('provider crashed');
    }
}

/**
 * Fake ClassDiscovery that returns different class lists depending on the
 * attribute being queried — lets a single instance serve both
 * ComponentRegistry (AsComponent queries) and ComponentMetadataProviderRegistry
 * (AsComponentMetadataProvider queries).
 */
final class CrmpFakeClassDiscovery extends ClassDiscovery
{
    /** @param array<string, list<string>> $classesByAttribute */
    public function __construct(private readonly array $classesByAttribute)
    {
    }

    public function initialize(): void
    {
    }

    public function findClassesWithAttribute(string $attributeClass): array
    {
        return $this->classesByAttribute[$attributeClass] ?? [];
    }
}

final class CrmpFakeModuleRegistry extends ModuleRegistry
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

final class CrmpFakeContainer implements ContainerInterface
{
    public function get(string $id): never
    {
        throw new \RuntimeException('not bound: ' . $id);
    }

    public function has(string $id): bool
    {
        return false;
    }
}

final class ComponentRegistryMetadataProviderTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetRegistry();
    }

    protected function tearDown(): void
    {
        $this->resetRegistry();
        ComponentRegistry::setMetadataProviderRegistry(null);
    }

    private function resetRegistry(): void
    {
        $ref = new ReflectionClass(ComponentRegistry::class);
        $ref->getProperty('components')->setValue(null, []);
        $ref->getProperty('initialized')->setValue(null, false);
    }

    /**
     * @param list<string> $componentClasses
     * @param list<string> $providerClasses
     */
    private function wireRegistries(array $componentClasses, array $providerClasses): void
    {
        $discovery = new CrmpFakeClassDiscovery([
            AsComponent::class => $componentClasses,
            AsComponentMetadataProvider::class => $providerClasses,
        ]);
        ComponentRegistry::setClassDiscovery($discovery);

        $providerRegistry = new ComponentMetadataProviderRegistry();
        $ref = new ReflectionClass(ComponentMetadataProviderRegistry::class);
        $ref->getProperty('classDiscovery')->setValue($providerRegistry, $discovery);
        $ref->getProperty('moduleRegistry')->setValue($providerRegistry, new CrmpFakeModuleRegistry());
        $ref->getProperty('container')->setValue($providerRegistry, new CrmpFakeContainer());
        ComponentRegistry::setMetadataProviderRegistry($providerRegistry);
    }

    public function testComponentWithSupportingProviderHasProviderProps(): void
    {
        $this->wireRegistries([CrmpTestComponent::class], [CrmpSupportingProvider::class]);

        $component = ComponentRegistry::get('crmp_test_component');

        self::assertNotNull($component);
        self::assertSame(['from_provider' => 'yes', 'count' => 1], $component['providerProps']);
    }

    public function testComponentWithoutSupportingProviderHasEmptyProviderProps(): void
    {
        $this->wireRegistries([CrmpOtherComponent::class], [CrmpSupportingProvider::class]);

        $component = ComponentRegistry::get('crmp_other_component');

        self::assertNotNull($component);
        self::assertSame([], $component['providerProps']);
    }

    public function testNoProviderRegistryWiredYieldsEmptyProviderProps(): void
    {
        ComponentRegistry::setClassDiscovery(new CrmpFakeClassDiscovery([
            AsComponent::class => [CrmpTestComponent::class],
        ]));

        $component = ComponentRegistry::get('crmp_test_component');

        self::assertNotNull($component);
        self::assertSame([], $component['providerProps']);
    }

    public function testProviderThrowingPropagatesAsInvalidComponentConfiguration(): void
    {
        $this->wireRegistries([CrmpTestComponent::class], [CrmpBrokenProvider::class]);

        $this->expectException(InvalidComponentConfigurationException::class);
        $this->expectExceptionMessage("crmp_test_component");
        $this->expectExceptionMessage(CrmpBrokenProvider::class);
        $this->expectExceptionMessage('provider crashed');

        ComponentRegistry::get('crmp_test_component');
    }

    public function testRegisterTestSeamDefaultsProviderPropsToEmpty(): void
    {
        // Mark registry as already-initialized so register() is the only path
        // exercised and get() does not trigger discovery.
        $ref = new ReflectionClass(ComponentRegistry::class);
        $ref->getProperty('initialized')->setValue(null, true);

        ComponentRegistry::register([
            'class' => 'X',
            'name' => 'crmp_via_register',
            'template' => null,
            'layout' => null,
            'cacheable' => true,
            'event' => null,
            'triggers' => [],
            'script' => null,
        ]);

        $component = ComponentRegistry::get('crmp_via_register');

        self::assertNotNull($component);
        self::assertSame([], $component['providerProps']);
    }
}
