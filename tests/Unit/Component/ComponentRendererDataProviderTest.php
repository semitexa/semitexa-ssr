<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Ssr\Application\Service\Component\ComponentRegistry;
use Semitexa\Ssr\Application\Service\Component\ComponentRenderer;
use Semitexa\Ssr\Application\Service\DataProviderRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Domain\Model\DataProviderContext;
use Semitexa\Ssr\Domain\Contract\DataProviderInterface;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader;

final class CrdptCapturingProvider implements DataProviderInterface
{
    /** @var array<int, array{ctx: DataProviderContext, hint: array}> */
    public static array $calls = [];

    /** @var array<string, mixed> */
    public static array $returnData = [];

    public function resolve(DataProviderContext $context, array $hint = []): array
    {
        self::$calls[] = ['ctx' => $context, 'hint' => $hint];
        return self::$returnData;
    }
}

final class ComponentRendererDataProviderTest extends TestCase
{
    protected function setUp(): void
    {
        CrdptCapturingProvider::$calls = [];
        CrdptCapturingProvider::$returnData = [];
        $this->resetRegistries();
        $this->markRegistryInitialized();
        $this->installTwigStub();
    }

    protected function tearDown(): void
    {
        $this->resetRegistries();
        ComponentRenderer::setDataProviderRegistry(null);
        ComponentRenderer::setCurrentRequest(null);
        // Drop the stub Twig so subsequent tests rebuild it from real module paths.
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

    /**
     * Mark the registry as already-initialized so ComponentRegistry::get() does
     * not trigger discovery (which would scan ALL #[AsComponent] classes —
     * including fixtures from sibling tests).
     */
    private function markRegistryInitialized(): void
    {
        $reflection = new \ReflectionClass(ComponentRegistry::class);
        $reflection->getProperty('initialized')->setValue(null, true);
    }

    private function installTwigStub(): void
    {
        $loader = new ArrayLoader([
            'components/crdpt_demo.html.twig' =>
                'title={{ title }};subtitle={{ subtitle }};extra={{ extra }};',
        ]);
        $twig = new TwigEnvironment($loader, ['autoescape' => false, 'cache' => false]);

        $reflection = new \ReflectionClass(ModuleTemplateRegistry::class);
        $reflection->getProperty('twig')->setValue(null, $twig);
        $reflection->getProperty('loader')->setValue(null, $loader);
        $reflection->getProperty('initialized')->setValue(null, true);
    }

    private function registerComponent(?string $providerClass): void
    {
        ComponentRegistry::register([
            'class' => 'CrdptDemoComponent',
            'name' => 'crdpt_demo',
            'template' => 'components/crdpt_demo.html.twig',
            'layout' => null,
            'cacheable' => true,
            'event' => null,
            'triggers' => [],
            'script' => null,
            'dataProviderClass' => $providerClass,
            'transportMode' => TransportType::Http,
            'deferred' => false,
        ]);
    }

    public function testProviderDataIsMergedIntoProps(): void
    {
        CrdptCapturingProvider::$returnData = ['title' => 'from-provider', 'subtitle' => 'sub', 'extra' => 'ex'];
        $this->registerComponent(CrdptCapturingProvider::class);
        ComponentRenderer::setDataProviderRegistry(new DataProviderRegistry());

        $html = ComponentRenderer::render('crdpt_demo');

        self::assertSame('title=from-provider;subtitle=sub;extra=ex;', $html);
        self::assertCount(1, CrdptCapturingProvider::$calls);
    }

    public function testExplicitPropsWinOverProviderData(): void
    {
        CrdptCapturingProvider::$returnData = ['title' => 'from-provider', 'subtitle' => 'from-provider'];
        $this->registerComponent(CrdptCapturingProvider::class);
        ComponentRenderer::setDataProviderRegistry(new DataProviderRegistry());

        $html = ComponentRenderer::render('crdpt_demo', ['title' => 'explicit', 'extra' => 'props-extra']);

        self::assertSame('title=explicit;subtitle=from-provider;extra=props-extra;', $html);
    }

    public function testProviderContextReceivesCurrentRequest(): void
    {
        $request = new \stdClass();
        $this->registerComponent(CrdptCapturingProvider::class);
        ComponentRenderer::setDataProviderRegistry(new DataProviderRegistry());
        ComponentRenderer::setCurrentRequest($request);

        ComponentRenderer::render('crdpt_demo');

        self::assertCount(1, CrdptCapturingProvider::$calls);
        self::assertSame($request, CrdptCapturingProvider::$calls[0]['ctx']->request);
    }

    public function testRendersWithoutErrorWhenProviderRegistryUnset(): void
    {
        CrdptCapturingProvider::$returnData = ['title' => 'never-merged'];
        $this->registerComponent(CrdptCapturingProvider::class);
        ComponentRenderer::setDataProviderRegistry(null);

        $html = ComponentRenderer::render('crdpt_demo', ['title' => 'only-explicit']);

        self::assertSame('title=only-explicit;subtitle=;extra=;', $html);
        self::assertCount(0, CrdptCapturingProvider::$calls);
    }

    public function testRendersWithoutErrorWhenNoDataProviderClass(): void
    {
        $this->registerComponent(null);
        ComponentRenderer::setDataProviderRegistry(new DataProviderRegistry());

        $html = ComponentRenderer::render('crdpt_demo', ['title' => 't', 'subtitle' => 's', 'extra' => 'e']);

        self::assertSame('title=t;subtitle=s;extra=e;', $html);
        self::assertCount(0, CrdptCapturingProvider::$calls);
    }
}

