<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Ssr\Application\Service\Template\ThemeAwareTwigLoader;
use Twig\Loader\FilesystemLoader;

final class ThemeAwareTwigLoaderTest extends TestCase
{
    private string $originalCwd;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd() ?: sys_get_temp_dir();
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-theme-loader-test-' . uniqid('', true);

        mkdir($this->projectRoot . '/src/modules/site/Application/View/templates/pages', 0777, true);
        mkdir($this->projectRoot . '/src/theme/base/site/templates/pages', 0777, true);
        mkdir($this->projectRoot . '/src/theme/child/site/templates/pages', 0777, true);
        file_put_contents($this->projectRoot . '/composer.json', "{}\n");
        file_put_contents($this->projectRoot . '/src/modules/site/Application/View/templates/pages/home.html.twig', 'module');
        file_put_contents($this->projectRoot . '/src/theme/base/site/templates/pages/home.html.twig', 'base');
        file_put_contents($this->projectRoot . '/src/theme/child/site/templates/pages/home.html.twig', 'child');

        chdir($this->projectRoot);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        ProjectRoot::reset();
        $this->deleteDirectory($this->projectRoot);
    }

    public function testUsesLeafThemeOverrideBeforeParentTheme(): void
    {
        $loader = $this->makeLoader(static fn (): array => ['child', 'base']);

        $source = $loader->getSourceContext('@project-layouts-site/pages/home.html.twig');

        self::assertSame('child', $source->getCode());
        self::assertStringContainsString('/src/theme/child/site/templates/pages/home.html.twig', $source->getPath());
    }

    public function testFallsBackToDelegateWhenChainIsEmpty(): void
    {
        $loader = $this->makeLoader(static fn (): array => []);

        $source = $loader->getSourceContext('@project-layouts-site/pages/home.html.twig');

        self::assertSame('module', $source->getCode());
    }

    public function testRejectsTraversalAndSymlinkEscapes(): void
    {
        file_put_contents($this->projectRoot . '/secret.html.twig', 'secret');
        $link = $this->projectRoot . '/src/theme/child/site/templates/pages/leak.html.twig';
        if (!@symlink($this->projectRoot . '/secret.html.twig', $link)) {
            self::markTestSkipped('Filesystem does not allow symlink creation.');
        }

        $loader = $this->makeLoader(static fn (): array => ['child']);

        self::assertFalse($loader->exists('@project-layouts-site/pages/leak.html.twig'));
        self::assertFalse($loader->exists('@project-layouts-site/../secret.html.twig'));
    }

    public function testResolvesOverrideForBareModuleAlias(): void
    {
        // Canonical path: app modules use the bare module name as alias
        // (`@site/...`), without the legacy `project-layouts-` prefix.
        $loader = $this->makeLoader(static fn (): array => ['child', 'base']);

        $source = $loader->getSourceContext('@site/pages/home.html.twig');

        self::assertSame('child', $source->getCode());
        self::assertStringContainsString('/src/theme/child/site/templates/pages/home.html.twig', $source->getPath());
    }

    public function testBareAliasFallsBackToDelegateWhenChainIsEmpty(): void
    {
        $loader = $this->makeLoader(static fn (): array => []);

        $source = $loader->getSourceContext('@site/pages/home.html.twig');

        self::assertSame('module', $source->getCode());
    }

    public function testBareAliasRejectsTraversalAndSymlinkEscapes(): void
    {
        file_put_contents($this->projectRoot . '/secret.html.twig', 'secret');
        $link = $this->projectRoot . '/src/theme/child/site/templates/pages/leak.html.twig';
        if (!@symlink($this->projectRoot . '/secret.html.twig', $link)) {
            self::markTestSkipped('Filesystem does not allow symlink creation.');
        }

        $loader = $this->makeLoader(static fn (): array => ['child']);

        self::assertFalse($loader->exists('@site/pages/leak.html.twig'));
        self::assertFalse($loader->exists('@site/../secret.html.twig'));
    }

    /**
     * @param \Closure(): list<string> $chainResolver
     */
    private function makeLoader(\Closure $chainResolver): ThemeAwareTwigLoader
    {
        $delegate = new FilesystemLoader();
        $delegate->addPath(
            $this->projectRoot . '/src/modules/site/Application/View/templates',
            'project-layouts-site',
        );
        // Register bare alias too, so the canonical-form tests above can
        // resolve when no theme override is present.
        $delegate->addPath(
            $this->projectRoot . '/src/modules/site/Application/View/templates',
            'site',
        );

        return new ThemeAwareTwigLoader($delegate, $chainResolver);
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
