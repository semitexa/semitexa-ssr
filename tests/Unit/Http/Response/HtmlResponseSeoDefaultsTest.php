<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Http\Response;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;
use Semitexa\Ssr\Application\Service\Seo\SeoMeta;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;

final class HtmlResponseSeoDefaultsTest extends TestCase
{
    protected function tearDown(): void
    {
        SeoMeta::reset();
    }

    #[Test]
    public function render_string_derives_seo_from_context_when_handler_does_not_set_it_explicitly(): void
    {
        ModuleTemplateRegistry::setModuleRegistry(new ModuleRegistry());
        TwigExtensionRegistry::setClassDiscovery(new ClassDiscovery());

        $response = new class extends HtmlResponse {
        };

        $response->renderString('{{ 1 }}', [
            'featureTitle' => 'Tenant Context Resolution',
            'summary' => 'See how Semitexa resolves the active tenant from subdomain, header, path, or query input.',
            'highlights' => ['subdomain', ['term' => 'header'], ['label' => 'path']],
            'infoKeywords' => ['tenant context'],
        ]);

        self::assertSame('Tenant Context Resolution', SeoMeta::getTitle());
        self::assertSame('See how Semitexa resolves the active tenant from subdomain, header, path, or query input.', SeoMeta::get('description'));
        self::assertSame('Tenant Context Resolution, tenant context, subdomain, header, path', SeoMeta::get('keywords'));
        self::assertSame('Tenant Context Resolution', SeoMeta::get('og:title'));
        self::assertSame('See how Semitexa resolves the active tenant from subdomain, header, path, or query input.', SeoMeta::get('og:description'));
        self::assertSame('website', SeoMeta::get('og:type'));
    }

    #[Test]
    public function twig_environment_exposes_inject_scripts_function(): void
    {
        ModuleTemplateRegistry::setModuleRegistry(new ModuleRegistry());
        TwigExtensionRegistry::setClassDiscovery(new ClassDiscovery());

        $twig = ModuleTemplateRegistry::getTwig();

        self::assertNotFalse($twig->getFunction('inject_scripts'));
    }
}
