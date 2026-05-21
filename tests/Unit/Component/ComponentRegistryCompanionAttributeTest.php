<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Ssr\Application\Service\Component\ComponentRegistry;
use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\Ssr\Attribute\WithDataProvider;
use Semitexa\Ssr\Attribute\WithTransport;
use Semitexa\Ssr\Domain\Model\DataProviderContext;
use Semitexa\Ssr\Domain\Contract\DataProviderInterface;
use Semitexa\Ssr\Domain\Exception\InvalidComponentConfigurationException;

#[AsComponent(name: 'cratest_plain')]
final class CraTestPlainComponent
{
}

#[AsComponent(name: 'cratest_with_provider')]
#[WithDataProvider(providerClass: CraTestValidProvider::class)]
final class CraTestComponentWithValidProvider
{
}

#[AsComponent(name: 'cratest_bad_provider')]
#[WithDataProvider(providerClass: CraTestNonProviderClass::class)]
final class CraTestComponentWithBadProvider
{
}

#[AsComponent(name: 'cratest_with_transport_sse_deferred')]
#[WithTransport(mode: TransportType::Sse, deferred: true)]
final class CraTestComponentWithSseDeferred
{
}

#[AsComponent(name: 'cratest_with_transport_http_deferred')]
#[WithTransport(mode: TransportType::Http, deferred: true)]
final class CraTestComponentWithInvalidHttpDeferred
{
}

final class CraTestValidProvider implements DataProviderInterface
{
    public function resolve(DataProviderContext $context, array $hint = []): array
    {
        return [];
    }
}

final class CraTestNonProviderClass
{
}

final class ComponentRegistryCompanionAttributeTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetRegistry();
    }

    protected function tearDown(): void
    {
        $this->resetRegistry();
    }

    private function resetRegistry(): void
    {
        $reflection = new \ReflectionClass(ComponentRegistry::class);
        $components = $reflection->getProperty('components');
        $components->setValue(null, []);
        $initialized = $reflection->getProperty('initialized');
        $initialized->setValue(null, false);
    }

    private function bootRegistryWith(array $componentClasses): void
    {
        $discovery = new class($componentClasses) extends ClassDiscovery {
            public function __construct(private array $classes) {}
            public function findClassesWithAttribute(string $attributeClass): array
            {
                return $attributeClass === AsComponent::class ? $this->classes : [];
            }
        };
        ComponentRegistry::setClassDiscovery($discovery);
        ComponentRegistry::initialize();
    }

    public function testPlainComponentHasDefaultCompanionMetadata(): void
    {
        $this->bootRegistryWith([CraTestPlainComponent::class]);

        $component = ComponentRegistry::get('cratest_plain');
        self::assertNotNull($component);
        self::assertNull($component['dataProviderClass']);
        self::assertSame(TransportType::Http, $component['transportMode']);
        self::assertFalse($component['deferred']);
    }

    public function testWithDataProviderStoresProviderClass(): void
    {
        $this->bootRegistryWith([CraTestComponentWithValidProvider::class]);

        $component = ComponentRegistry::get('cratest_with_provider');
        self::assertNotNull($component);
        self::assertSame(CraTestValidProvider::class, $component['dataProviderClass']);
    }

    public function testWithDataProviderRejectsNonProvider(): void
    {
        $this->expectException(InvalidComponentConfigurationException::class);
        $this->expectExceptionMessage('must implement');
        $this->bootRegistryWith([CraTestComponentWithBadProvider::class]);
    }

    public function testWithTransportSseDeferredStoresFields(): void
    {
        $this->bootRegistryWith([CraTestComponentWithSseDeferred::class]);

        $component = ComponentRegistry::get('cratest_with_transport_sse_deferred');
        self::assertNotNull($component);
        self::assertSame(TransportType::Sse, $component['transportMode']);
        self::assertTrue($component['deferred']);
    }

    public function testWithTransportHttpDeferredIsRejected(): void
    {
        $this->expectException(InvalidComponentConfigurationException::class);
        $this->expectExceptionMessage('requires mode:TransportType::Sse');
        $this->bootRegistryWith([CraTestComponentWithInvalidHttpDeferred::class]);
    }
}
