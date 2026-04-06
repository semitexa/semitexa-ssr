<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Ssr\Asset\AssetCollector;
use Semitexa\Ssr\Asset\AssetManager;
use Semitexa\Ssr\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Asset\AssetRenderer;

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
