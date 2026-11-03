<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Ssr\Application\Service\Component\ComponentInstanceStore;
use Semitexa\Ssr\Application\Service\Component\ComponentCatalog;
use Semitexa\Ssr\Application\Service\Component\ComponentRegistry;
use Semitexa\Ssr\Application\Service\Component\ComponentRenderer;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateCatalog;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Context\IsomorphicContextStore;
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
        IsomorphicContextStore::reset();
        $this->resetRegistries();
        ComponentInstanceStore::reset();
        ComponentRenderer::setDataProviderRegistry(null);
        ComponentRenderer::setCurrentRequest(null);
        $catalog = (new \ReflectionClass(ModuleTemplateRegistry::class))
            ->getProperty('catalog')->getValue();
        if (!$catalog instanceof ModuleTemplateCatalog) {
            $catalog = new ModuleTemplateCatalog();
            ModuleTemplateRegistry::setCatalog($catalog);
        }
        $reflection = new \ReflectionClass($catalog);
        $reflection->getProperty('twig')->setValue($catalog, null);
        $reflection->getProperty('loader')->setValue($catalog, null);
        $reflection->getProperty('initialized')->setValue($catalog, false);
    }

    private function resetRegistries(): void
    {
        ComponentRegistry::setCatalog(new ComponentCatalog());
    }

    private function markRegistryInitialized(): void
    {
        $catalog = (new \ReflectionClass(ComponentRegistry::class))->getProperty('catalog')->getValue();
        if (!$catalog instanceof ComponentCatalog) {
            $catalog = new ComponentCatalog();
            ComponentRegistry::setCatalog($catalog);
        }
        (new \ReflectionClass($catalog))->getProperty('initialized')->setValue($catalog, true);
    }

    private function installTwigStub(): void
    {
        $loader = new ArrayLoader([
            'components/deferred_demo.html.twig' =>
                'rendered:{{ title }}',
        ]);
        $twig = new TwigEnvironment($loader, ['autoescape' => false, 'cache' => false]);
        $catalog = (new \ReflectionClass(ModuleTemplateRegistry::class))
            ->getProperty('catalog')->getValue();
        if (!$catalog instanceof ModuleTemplateCatalog) {
            $catalog = new ModuleTemplateCatalog();
            ModuleTemplateRegistry::setCatalog($catalog);
        }
        $reflection = new \ReflectionClass($catalog);
        $reflection->getProperty('twig')->setValue($catalog, $twig);
        $reflection->getProperty('loader')->setValue($catalog, $loader);
        $reflection->getProperty('initialized')->setValue($catalog, true);
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
        IsomorphicContextStore::setPageHandle('demo.page'); // a page whose deferral is set up

        $html = ComponentRenderer::render('deferred_demo', ['title' => 'Hello']);

        self::assertStringContainsString('data-ssr-deferred-component="deferred_demo"', $html);
        self::assertStringContainsString('data-ssr-component-instance="uci_', $html);

        $recorded = ComponentInstanceStore::all();
        self::assertCount(1, $recorded);
        $entry = array_values($recorded)[0];
        self::assertSame('deferred_demo', $entry['name']);
        self::assertSame(['title' => 'Hello'], $entry['props']);
        self::assertStringStartsWith('uci_', $entry['instance_id']);
    }

    /**
     * tk-ls-async-props: a placeholder is only drawn where a deferred stream
     * will fill it — not on a page whose deferral is not set up, and not in
     * what a deferred render nests.
     */
    public function testWithoutADeferredPageTheComponentRendersNow(): void
    {
        $this->registerDeferredSseComponent();

        self::assertSame('rendered:Hello', ComponentRenderer::render('deferred_demo', ['title' => 'Hello']));
        self::assertSame([], ComponentInstanceStore::all());
    }

    /**
     * A page with no deferred slots stores its request late. Whether it fits
     * is asked by the first deferred component, once; a page that renders
     * none never asks (the demo home page encoded 172 KB per request to learn
     * it did not fit).
     */
    public function testALateRequestThatDoesNotFitRendersTheComponentNowAndIsAskedOnce(): void
    {
        $this->registerDeferredSseComponent();
        IsomorphicContextStore::setPageHandle('demo.page');
        $asked = 0;
        IsomorphicContextStore::setLateDeferral(static function () use (&$asked): bool {
            $asked++;

            return false;
        });

        self::assertSame('rendered:Hello', ComponentRenderer::render('deferred_demo', ['title' => 'Hello']));
        self::assertSame('rendered:Again', ComponentRenderer::render('deferred_demo', ['title' => 'Again']));
        self::assertSame([], ComponentInstanceStore::all(), 'no placeholder a stored request would never fill');
        self::assertSame(1, $asked, 'asked once per request');
    }

    public function testALateRequestThatFitsStillDefersAndAPageWithoutComponentsNeverAsks(): void
    {
        $this->registerDeferredSseComponent();
        IsomorphicContextStore::setPageHandle('demo.page');
        $asked = 0;
        IsomorphicContextStore::setLateDeferral(static function () use (&$asked): bool {
            $asked++;

            return true;
        });
        self::assertSame(0, $asked, 'nothing rendered, nothing asked');

        $html = ComponentRenderer::render('deferred_demo', ['title' => 'Hello']);

        self::assertStringContainsString('data-ssr-deferred-component="deferred_demo"', $html);
        self::assertSame(1, $asked);
        IsomorphicContextStore::setLateDeferral(null);
        self::assertTrue(IsomorphicContextStore::lateDeferralFits(), 'a request stored up front is not asked again');
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
