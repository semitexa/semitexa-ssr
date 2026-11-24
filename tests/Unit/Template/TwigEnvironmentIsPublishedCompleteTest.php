<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Environment;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionCatalog;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateCatalog;
use Twig\Environment as TwigEnvironment;

/**
 * The catalog's Twig environment is visible only once it is complete.
 *
 * On a cold worker two coroutines could build it at once. A request rendered
 * with the environment one of them had already put in place while it was still
 * registering the module extensions; Twig then refused the build's addGlobal()
 * ("Unable to add global sse_port as the runtime or the extensions have already
 * been initialized"), the fallback called addGlobal() again, and the error page
 * fell back to its plain page during restart bursts (a consumer's production,
 * 2026-11-07 to 2026-11-21).
 */
final class TwigEnvironmentIsPublishedCompleteTest extends TestCase
{
    private ?TwigExtensionCatalog $extensionsBefore = null;

    protected function setUp(): void
    {
        $this->extensionsBefore = TwigExtensionRegistry::getCatalog();
    }

    protected function tearDown(): void
    {
        TwigExtensionRegistry::setCatalog($this->extensionsBefore);
    }

    #[Test]
    public function a_request_during_the_build_finds_no_half_built_environment(): void
    {
        $catalog = new ModuleTemplateCatalog();
        $catalog->setModuleRegistry(new ModuleRegistry());
        $seen = new \ArrayObject();

        // Stands for the other coroutine: while the build registers the module
        // extensions, a request renders with whatever environment the catalog
        // holds, which initialises that environment's extensions.
        $discovery = new class ($catalog, $seen) extends ClassDiscovery {
            public function __construct(
                private readonly ModuleTemplateCatalog $catalog,
                private readonly \ArrayObject $seen,
            ) {
            }

            public function initialize(): void
            {
            }

            public function findClassesWithAttribute(string $attributeClass): array
            {
                $twig = (new \ReflectionProperty(ModuleTemplateCatalog::class, 'twig'))->getValue($this->catalog);
                $this->seen[] = $twig instanceof TwigEnvironment ? 'published' : 'none';
                if ($twig instanceof TwigEnvironment) {
                    $twig->createTemplate('{{ 1 }}')->render();
                }

                return [];
            }
        };
        $extensions = new TwigExtensionCatalog();
        $extensions->setClassDiscovery($discovery);
        TwigExtensionRegistry::setCatalog($extensions);

        $twig = $catalog->getTwig();

        self::assertSame(['none'], $seen->getArrayCopy(), 'nothing is published while the extensions register');
        self::assertSame(Environment::create()->swooleSsePort, $twig->getGlobals()['sse_port']);
    }
}
