<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Ssr\Application\Service\Asset\AssetCollector;
use Semitexa\Ssr\Application\Service\Asset\AssetManager;
use Semitexa\Ssr\Application\Service\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Application\Service\Asset\AssetRenderer;

final class AssetManagerTest extends TestCase
{
    private string $originalCwd;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd() ?: sys_get_temp_dir();
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-asset-test-' . uniqid('', true);

        mkdir($this->projectRoot . '/src/modules/site/Application/Static/css', 0777, true);
        file_put_contents($this->projectRoot . '/composer.json', "{}\n");
        file_put_contents($this->projectRoot . '/src/modules/site/Application/Static/css/app.css', "body{color:red;}\n");

        chdir($this->projectRoot);
        ProjectRoot::reset();
        ModuleAssetRegistry::reset();
        AssetCollector::resetBoot();
        AssetManager::reset();

        $moduleRegistry = new ModuleRegistry();
        ModuleAssetRegistry::setModuleRegistry($moduleRegistry);
        AssetCollector::setModuleRegistry($moduleRegistry);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        ProjectRoot::reset();
        ModuleAssetRegistry::reset();
        AssetCollector::resetBoot();
        AssetManager::reset();
        $this->deleteDirectory($this->projectRoot);
    }

    public function testAssetRendererAddsContentFingerprintToCssUrl(): void
    {
        $collector = new AssetCollector();
        $collector->require('site:css:app');

        $html = AssetRenderer::renderHead($collector);
        $expectedHash = hash_file('sha256', $this->projectRoot . '/src/modules/site/Application/Static/css/app.css');
        self::assertIsString($expectedHash);
        $expectedHash = substr($expectedHash, 0, 12);

        self::assertStringContainsString('/assets/site/css/app.css?v=' . $expectedHash, $html);
        self::assertStringContainsString('<link rel="stylesheet"', $html);
    }

    public function testAssetUrlChangesWhenStaticFileChanges(): void
    {
        $firstCollector = new AssetCollector();
        $firstCollector->require('site:css:app');
        $firstHtml = AssetRenderer::renderHead($firstCollector);

        file_put_contents($this->projectRoot . '/src/modules/site/Application/Static/css/app.css', "body{color:blue;}\n");
        clearstatcache(true, $this->projectRoot . '/src/modules/site/Application/Static/css/app.css');

        $secondCollector = new AssetCollector();
        $secondCollector->require('site:css:app');
        $secondHtml = AssetRenderer::renderHead($secondCollector);

        self::assertNotSame($firstHtml, $secondHtml);
        self::assertMatchesRegularExpression('/\\/assets\\/site\\/css\\/app\\.css\\?v=[a-f0-9]{12}/', $secondHtml);
    }

    /**
     * A new render must not re-hash an unchanged asset: the fingerprint cache
     * is keyed by mtime and size and outlives resetRenderState(), which is what
     * HtmlResponse calls at the top of every render.
     *
     * Proven by rewriting the file behind the cache's back — same size, mtime
     * restored — so a re-hash would show up as a different URL.
     */
    public function testFingerprintIsNotRecomputedAcrossRendersForAnUnchangedFile(): void
    {
        $file = $this->projectRoot . '/src/modules/site/Application/Static/css/app.css';
        touch($file, 1_700_000_000);
        clearstatcache(true, $file);
        $first = AssetManager::getUrl('css/app.css', 'site');

        AssetManager::resetRenderState();
        file_put_contents($file, "body{color:tan;}\n");
        touch($file, 1_700_000_000);
        clearstatcache(true, $file);

        self::assertSame($first, AssetManager::getUrl('css/app.css', 'site'));
    }

    /** In dev an edited asset must still get a new fingerprint on the next render. */
    public function testEditedAssetGetsANewFingerprintOnTheNextRender(): void
    {
        $file = $this->projectRoot . '/src/modules/site/Application/Static/css/app.css';
        touch($file, 1_700_000_000);
        clearstatcache(true, $file);
        $first = AssetManager::getUrl('css/app.css', 'site');

        AssetManager::resetRenderState();
        file_put_contents($file, "body{color:tan;}\n");
        touch($file, 1_700_000_001);
        clearstatcache(true, $file);

        $second = AssetManager::getUrl('css/app.css', 'site');
        self::assertNotSame($first, $second);
        self::assertStringEndsWith('?v=' . substr((string) hash_file('sha256', $file), 0, 12), $second);
    }

    /**
     * A resolved file is remembered, a miss is not (a deferred template is
     * published into place at runtime), and a remembered file that disappears
     * is not handed out again.
     */
    public function testModuleAssetRegistryRemembersHitsOnly(): void
    {
        $late = $this->projectRoot . '/src/modules/site/Application/Static/css/late.css';

        self::assertNull(ModuleAssetRegistry::resolve('site', 'css/late.css'));

        file_put_contents($late, "a{}\n");
        self::assertSame(realpath($late), ModuleAssetRegistry::resolve('site', 'css/late.css'));

        unlink($late);
        self::assertNull(ModuleAssetRegistry::resolve('site', 'css/late.css'));
    }

    /** The active chain is part of the answer, so it is part of the memo key. */
    public function testModuleAssetRegistryMemoFollowsTheActiveChain(): void
    {
        mkdir($this->projectRoot . '/src/theme/child/site/Static/css', 0777, true);
        file_put_contents($this->projectRoot . '/src/theme/child/site/Static/css/app.css', "body{color:purple;}\n");
        $chain = [];
        ModuleAssetRegistry::setChainResolver(static function () use (&$chain): array {
            return $chain;
        });

        self::assertSame(
            realpath($this->projectRoot . '/src/modules/site/Application/Static/css/app.css'),
            ModuleAssetRegistry::resolve('site', 'css/app.css'),
        );

        $chain = ['child'];
        self::assertSame(
            realpath($this->projectRoot . '/src/theme/child/site/Static/css/app.css'),
            ModuleAssetRegistry::resolve('site', 'css/app.css'),
        );
    }

    public function testModuleAssetRegistryResolvesFirstThemeInActiveChain(): void
    {
        mkdir($this->projectRoot . '/src/theme/base/site/Static/css', 0777, true);
        mkdir($this->projectRoot . '/src/theme/child/site/Static/css', 0777, true);
        file_put_contents($this->projectRoot . '/src/theme/base/site/Static/css/app.css', "body{color:green;}\n");
        file_put_contents($this->projectRoot . '/src/theme/child/site/Static/css/app.css', "body{color:purple;}\n");

        ModuleAssetRegistry::setChainResolver(static fn (): array => ['child', 'base']);

        $path = ModuleAssetRegistry::resolve('site', 'css/app.css');

        self::assertSame(
            realpath($this->projectRoot . '/src/theme/child/site/Static/css/app.css'),
            $path,
        );
    }

    public function testModuleAssetRegistryFallsBackWhenActiveChainIsEmpty(): void
    {
        ModuleAssetRegistry::setChainResolver(static fn (): array => []);

        $path = ModuleAssetRegistry::resolve('site', 'css/app.css');

        self::assertSame(
            realpath($this->projectRoot . '/src/modules/site/Application/Static/css/app.css'),
            $path,
        );
    }

    public function testModuleAssetRegistryRejectsTraversalAndSymlinkEscapes(): void
    {
        mkdir($this->projectRoot . '/src/theme/child/site/Static/css', 0777, true);
        file_put_contents($this->projectRoot . '/secret.css', "body{color:black;}\n");
        $link = $this->projectRoot . '/src/theme/child/site/Static/css/escape.css';
        if (!@symlink($this->projectRoot . '/secret.css', $link)) {
            self::markTestSkipped('Filesystem does not allow symlink creation.');
        }

        ModuleAssetRegistry::setChainResolver(static fn (): array => ['child']);

        self::assertNull(ModuleAssetRegistry::resolve('site', '../secret.css'));
        self::assertNull(ModuleAssetRegistry::resolve('site', 'css/escape.css'));
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

            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
