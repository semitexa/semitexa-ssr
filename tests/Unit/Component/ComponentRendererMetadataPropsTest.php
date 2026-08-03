<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Ssr\Application\Service\Component\ComponentCatalog;
use Semitexa\Ssr\Application\Service\Component\ComponentRegistry;
use Semitexa\Ssr\Application\Service\Component\ComponentRenderer;
use Semitexa\Ssr\Application\Service\DataProviderRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateCatalog;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Domain\Contract\DataProviderInterface;
use Semitexa\Ssr\Domain\Model\DataProviderContext;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader;

final class CrmptStaticProvider implements DataProviderInterface
{
    /** @var array<string, mixed> */
    public static array $returnData = [];

    public function resolve(DataProviderContext $context, array $hint = []): array
    {
        return self::$returnData;
    }
}

final class ComponentRendererMetadataPropsTest extends TestCase
{
    protected function setUp(): void
    {
        CrmptStaticProvider::$returnData = [];
        $this->resetRegistries();
        $this->markRegistryInitialized();
        $this->installTwigStub();
    }

    protected function tearDown(): void
    {
        $this->resetRegistries();
        ComponentRenderer::setDataProviderRegistry(null);
        ComponentRenderer::setCurrentRequest(null);
        $catalog = (new \ReflectionClass(ModuleTemplateRegistry::class))
            ->getProperty('catalog')->getValue();
        if (!$catalog instanceof ModuleTemplateCatalog) {
            $catalog = new ModuleTemplateCatalog();
            ModuleTemplateRegistry::setCatalog($catalog);
        }
        $ref = new \ReflectionClass($catalog);
        $ref->getProperty('twig')->setValue($catalog, null);
        $ref->getProperty('loader')->setValue($catalog, null);
        $ref->getProperty('initialized')->setValue($catalog, false);
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
            'components/crmpt_demo.html.twig' =>
                'meta={{ meta }};provider={{ provider }};explicit={{ explicit }};override={{ override }};',
        ]);
        $twig = new TwigEnvironment($loader, ['autoescape' => false, 'cache' => false]);

        $catalog = (new \ReflectionClass(ModuleTemplateRegistry::class))
            ->getProperty('catalog')->getValue();
        if (!$catalog instanceof ModuleTemplateCatalog) {
            $catalog = new ModuleTemplateCatalog();
            ModuleTemplateRegistry::setCatalog($catalog);
        }
        $ref = new \ReflectionClass($catalog);
        $ref->getProperty('twig')->setValue($catalog, $twig);
        $ref->getProperty('loader')->setValue($catalog, $loader);
        $ref->getProperty('initialized')->setValue($catalog, true);
    }

    /** @param array<string, mixed> $providerProps */
    private function registerComponent(?string $dataProviderClass, array $providerProps): void
    {
        ComponentRegistry::register([
            'class' => 'CrmptDemoComponent',
            'name' => 'crmpt_demo',
            'template' => 'components/crmpt_demo.html.twig',
            'layout' => null,
            'cacheable' => true,
            'event' => null,
            'triggers' => [],
            'script' => null,
            'dataProviderClass' => $dataProviderClass,
            'transportMode' => TransportType::Http,
            'deferred' => false,
            'providerProps' => $providerProps,
        ]);
    }

    public function testProviderPropsRenderWhenNoOtherSource(): void
    {
        $this->registerComponent(null, [
            'meta' => 'M', 'provider' => '', 'explicit' => '', 'override' => 'meta',
        ]);

        $html = ComponentRenderer::render('crmpt_demo');

        self::assertSame('meta=M;provider=;explicit=;override=meta;', $html);
    }

    public function testDataProviderDataOverridesProviderProps(): void
    {
        CrmptStaticProvider::$returnData = ['provider' => 'P', 'override' => 'provider'];
        $this->registerComponent(CrmptStaticProvider::class, [
            'meta' => 'M', 'provider' => '', 'explicit' => '', 'override' => 'meta',
        ]);
        ComponentRenderer::setDataProviderRegistry(new DataProviderRegistry());

        $html = ComponentRenderer::render('crmpt_demo');

        self::assertSame('meta=M;provider=P;explicit=;override=provider;', $html);
    }

    public function testExplicitPropsBeatProviderAndMeta(): void
    {
        CrmptStaticProvider::$returnData = ['provider' => 'P', 'override' => 'provider'];
        $this->registerComponent(CrmptStaticProvider::class, [
            'meta' => 'M', 'provider' => '', 'explicit' => '', 'override' => 'meta',
        ]);
        ComponentRenderer::setDataProviderRegistry(new DataProviderRegistry());

        $html = ComponentRenderer::render('crmpt_demo', [
            'explicit' => 'E',
            'override' => 'explicit',
        ]);

        self::assertSame('meta=M;provider=P;explicit=E;override=explicit;', $html);
    }

    public function testMetaPropsAloneRenderWithoutDataProviderRegistry(): void
    {
        $this->registerComponent(CrmptStaticProvider::class, [
            'meta' => 'M', 'provider' => '', 'explicit' => '', 'override' => 'meta',
        ]);
        // DataProviderRegistry NOT wired → provider data layer skipped.
        ComponentRenderer::setDataProviderRegistry(null);

        $html = ComponentRenderer::render('crmpt_demo');

        self::assertSame('meta=M;provider=;explicit=;override=meta;', $html);
    }
}
