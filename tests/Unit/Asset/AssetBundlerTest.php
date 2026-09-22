<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Ssr\Application\Service\Asset\AssetBundler;
use Semitexa\Ssr\Application\Service\Asset\AssetCollector;
use Semitexa\Ssr\Application\Service\Asset\AssetEntry;
use Semitexa\Ssr\Application\Service\Asset\AssetManager;
use Semitexa\Ssr\Application\Service\Asset\AssetRenderer;
use Semitexa\Ssr\Application\Service\Asset\ModuleAssetRegistry;

/**
 * A manifest glob emits one render-blocking <link> per stylesheet; a page
 * pulling a kit, a UI library and its own module reaches fifteen. These pin
 * what the bundler is allowed to change about that — and, more importantly,
 * what it is not: cascade order, and where a relative url() points.
 */
final class AssetBundlerTest extends TestCase
{
    private string $originalCwd;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd() ?: sys_get_temp_dir();
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-bundle-test-' . uniqid('', true);

        mkdir($this->projectRoot . '/src/modules/site/Application/Static/css', 0777, true);
        mkdir($this->projectRoot . '/src/modules/site/Application/Static/fonts', 0777, true);
        file_put_contents($this->projectRoot . '/composer.json', "{}\n");

        chdir($this->projectRoot);
        ProjectRoot::reset();
        ModuleAssetRegistry::reset();
        AssetCollector::resetBoot();
        AssetManager::reset();
        AssetBundler::reset();

        $moduleRegistry = new ModuleRegistry();
        ModuleAssetRegistry::setModuleRegistry($moduleRegistry);
        AssetCollector::setModuleRegistry($moduleRegistry);
    }

    protected function tearDown(): void
    {
        putenv('SEMITEXA_ASSET_BUNDLE');
        chdir($this->originalCwd);
        ProjectRoot::reset();
        ModuleAssetRegistry::reset();
        AssetCollector::resetBoot();
        AssetManager::reset();
        AssetBundler::reset();
        $this->deleteDirectory($this->projectRoot);
    }

    public function testConcatenatesInTheOrderTheCascadeExpects(): void
    {
        $this->writeCss('a.css', '.a{color:red}');
        $this->writeCss('b.css', '.b{color:blue}');

        $urls = AssetBundler::bundle([$this->entry('a.css'), $this->entry('b.css')]);

        self::assertIsArray($urls);
        self::assertCount(1, $urls);

        $bundle = $this->readBundle($urls[0]);
        self::assertStringContainsString('.a', $bundle);
        self::assertStringContainsString('.b', $bundle);
        self::assertLessThan(
            strpos($bundle, '.b'),
            strpos($bundle, '.a'),
            'the later stylesheet must stay later, or the cascade changes meaning',
        );
    }

    public function testRelativeUrlIsRewrittenAgainstItsOwnModule(): void
    {
        $this->writeCss('fonts.css', "@font-face{src:url(../fonts/syne.woff2) format('woff2')}");
        $this->writeCss('app.css', '.a{color:red}');

        $urls = AssetBundler::bundle([$this->entry('fonts.css'), $this->entry('app.css')]);
        self::assertIsArray($urls);

        // Served from /assets/ssr/bundle/, so ../fonts/ would have resolved to
        // /assets/ssr/fonts/ and 404'd — every self-hosted face on the estate.
        self::assertStringContainsString('/assets/site/fonts/syne.woff2', $this->readBundle($urls[0]));
    }

    public function testUrlsThatAlreadyResolveAreLeftAlone(): void
    {
        $this->writeCss('a.css', implode("\n", [
            '.r{background:url(/assets/site/img/a.png)}',
            '.h{background:url("https://cdn.example.com/b.png")}',
            '.d{background:url(data:image/gif;base64,R0lGOD)}',
            '.f{filter:url(#blur)}',
        ]));
        $this->writeCss('b.css', '.b{color:blue}');

        $urls = AssetBundler::bundle([$this->entry('a.css'), $this->entry('b.css')]);
        self::assertIsArray($urls);
        $bundle = $this->readBundle($urls[0]);

        self::assertStringContainsString('/assets/site/img/a.png', $bundle);
        self::assertStringContainsString('https://cdn.example.com/b.png', $bundle);
        self::assertStringContainsString('data:image/gif;base64,R0lGOD', $bundle);
        self::assertStringContainsString('#blur', $bundle);
        self::assertStringNotContainsString('/assets/site/css/https:', $bundle);
    }

    public function testGlobalsGetTheirOwnBundleSoASecondPageReusesThem(): void
    {
        $this->writeCss('global.css', '.g{color:green}');
        $this->writeCss('page.css', '.p{color:pink}');

        $urls = AssetBundler::bundle([
            $this->entry('global.css', scope: 'global'),
            $this->entry('page.css'),
        ]);

        self::assertIsArray($urls);
        self::assertCount(2, $urls);
        self::assertStringContainsString('/global.', $urls[0]);
        self::assertStringContainsString('/page.', $urls[1]);
        self::assertStringContainsString('.g', $this->readBundle($urls[0]));
        self::assertStringContainsString('.p', $this->readBundle($urls[1]));
    }

    public function testAGlobalArrivingAfterAPageAssetIsNotHoistedOutOfOrder(): void
    {
        $this->writeCss('page.css', '.p{color:pink}');
        $this->writeCss('late.css', '.l{color:lime}');

        $urls = AssetBundler::bundle([
            $this->entry('page.css'),
            $this->entry('late.css', scope: 'global'),
        ]);

        // Splitting here would move `late` ahead of `page` and silently flip
        // which rule wins. One bundle keeps the order the renderer resolved.
        self::assertIsArray($urls);
        self::assertCount(1, $urls);
        $bundle = $this->readBundle($urls[0]);
        self::assertLessThan(strpos($bundle, '.l'), strpos($bundle, '.p'));
    }

    public function testASingleStylesheetIsNotWorthABundle(): void
    {
        $this->writeCss('only.css', '.o{color:olive}');

        self::assertNull(AssetBundler::bundle([$this->entry('only.css')]));
    }

    public function testAnUnreadableSourceFallsBackInsteadOfShippingAGap(): void
    {
        $this->writeCss('a.css', '.a{color:red}');

        // b.css is declared but never written: a bundle missing half the page's
        // rules is worse than the round trips bundling was meant to save.
        self::assertNull(AssetBundler::bundle([$this->entry('a.css'), $this->entry('b.css')]));
    }

    public function testTheSameContentPublishesToTheSameUrlTwice(): void
    {
        $this->writeCss('a.css', '.a{color:red}');
        $this->writeCss('b.css', '.b{color:blue}');
        $entries = [$this->entry('a.css'), $this->entry('b.css')];

        $first = AssetBundler::bundle($entries);
        AssetBundler::reset();
        $second = AssetBundler::bundle($entries);

        self::assertSame($first, $second, 'a content hash that moves on its own defeats the cache');
    }

    public function testTheHeadLinksBundlesWhereItWouldHaveLinkedTheFirstSheet(): void
    {
        $this->writeCss('a.css', '.a{color:red}');
        $this->writeCss('b.css', '.b{color:blue}');
        putenv('SEMITEXA_ASSET_BUNDLE=1');

        $collector = new AssetCollector();
        $collector->require('site:css:a');
        $collector->require('site:css:b');

        $html = AssetRenderer::renderHead($collector);

        self::assertSame(1, substr_count($html, '<link rel="stylesheet"'));
        self::assertStringContainsString('/assets/ssr/bundle/', $html);
        self::assertStringNotContainsString('/assets/site/css/a.css', $html);
    }

    public function testADevelopmentTreeKeepsItsIndividualFiles(): void
    {
        $this->writeCss('a.css', '.a{color:red}');
        $this->writeCss('b.css', '.b{color:blue}');
        putenv('SEMITEXA_ASSET_BUNDLE=0');

        $collector = new AssetCollector();
        $collector->require('site:css:a');
        $collector->require('site:css:b');

        $html = AssetRenderer::renderHead($collector);

        self::assertSame(2, substr_count($html, '<link rel="stylesheet"'));
        self::assertStringContainsString('/assets/site/css/a.css', $html);
        self::assertStringNotContainsString('/assets/ssr/bundle/', $html);
    }

    private function entry(string $file, string $scope = 'page'): AssetEntry
    {
        return new AssetEntry(
            key: 'site:css:' . pathinfo($file, PATHINFO_FILENAME),
            module: 'site',
            type: 'css',
            path: 'css/' . $file,
            scope: $scope,
        );
    }

    private function writeCss(string $file, string $contents): void
    {
        file_put_contents($this->projectRoot . '/src/modules/site/Application/Static/css/' . $file, $contents . "\n");
    }

    private function readBundle(string $url): string
    {
        $path = $this->projectRoot . '/public' . $url;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
