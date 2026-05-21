<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Ssr\Application\Service\Component\ComponentInstanceStore;
use Semitexa\Ssr\Application\Service\Component\ComponentRegistry;
use Semitexa\Ssr\Application\Service\Component\ComponentRenderer;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader;

final class ComponentRendererDeferredTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetRegistries();
        $this->markRegistryInitialized();
        $this->installTwigStub();
        ComponentInstanceStore::reset();
    }

    protected function tearDown(): void
    {
        $this->resetRegistries();
        ComponentInstanceStore::reset();
        ComponentRenderer::setDataProviderRegistry(null);
        ComponentRenderer::setCurrentRequest(null);
        $reflection = new \ReflectionClass(ModuleTemplateRegistry::class);
        $reflection->getProperty('twig')->setValue(null, null);
        $reflection->getProperty('loader')->setValue(null, null);
        $reflection->getProperty('initialized')->setValue(null, false);
    }

    private function resetRegistries(): void
    {
        $reflection = new \ReflectionClass(ComponentRegistry::class);
        $reflection->getProperty('components')->setValue(null, []);
        $reflection->getProperty('initialized')->setValue(null, false);
    }

    private function markRegistryInitialized(): void
    {
        $reflection = new \ReflectionClass(ComponentRegistry::class);
        $reflection->getProperty('initialized')->setValue(null, true);
    }

    private function installTwigStub(): void
    {
        $loader = new ArrayLoader([
            'components/deferred_demo.html.twig' =>
                'rendered:{{ title }}',
        ]);
        $twig = new TwigEnvironment($loader, ['autoescape' => false, 'cache' => false]);
        $reflection = new \ReflectionClass(ModuleTemplateRegistry::class);
        $reflection->getProperty('twig')->setValue(null, $twig);
        $reflection->getProperty('loader')->setValue(null, $loader);
        $reflection->getProperty('initialized')->setValue(null, true);
    }

    private function registerDeferredSseComponent(): void
    {
        ComponentRegistry::register([
            'class' => 'DeferredDemoComponent',
            'name' => 'deferred_demo',
            'template' => 'components/deferred_demo.html.twig',
            'layout' => null,
            'cacheable' => true,
            'event' => null,
            'triggers' => [],
            'script' => null,
            'dataProviderClass' => null,
            'transportMode' => TransportType::Sse,
            'deferred' => true,
        ]);
    }

    public function testDeferredSseComponentEmitsPlaceholderAndRecordsInstance(): void
    {
        $this->registerDeferredSseComponent();

        $html = ComponentRenderer::render('deferred_demo', ['title' => 'Hello']);

        self::assertStringContainsString('data-ssr-deferred-component="deferred_demo"', $html);
        self::assertStringContainsString('data-ssr-component-instance="cmp_', $html);

        $recorded = ComponentInstanceStore::all();
        self::assertCount(1, $recorded);
        $entry = array_values($recorded)[0];
        self::assertSame('deferred_demo', $entry['name']);
        self::assertSame(['title' => 'Hello'], $entry['props']);
        self::assertStringStartsWith('cmp_', $entry['instance_id']);
    }

    public function testForceImmediateRenderSkipsDeferredShortCircuit(): void
    {
        $this->registerDeferredSseComponent();

        $html = ComponentRenderer::render(
            'deferred_demo',
            ['title' => 'Hello'],
            [],
            forceImmediateRender: true,
        );

        self::assertSame('rendered:Hello', $html);
        self::assertStringNotContainsString('data-ssr-deferred-component', $html);
        self::assertSame([], ComponentInstanceStore::all(), 'Immediate render must not record into the deferred store');
    }

    public function testHasDeferredSseComponentReflectsRegistration(): void
    {
        self::assertFalse(ComponentRegistry::hasDeferredSseComponent());
        $this->registerDeferredSseComponent();
        self::assertTrue(ComponentRegistry::hasDeferredSseComponent());
    }
}
