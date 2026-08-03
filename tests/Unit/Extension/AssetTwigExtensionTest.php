<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Asset\AssetManager;
use Semitexa\Ssr\Application\Service\Extension\AssetTwigExtension;

/**
 * Tests for {@see AssetTwigExtension}, moved out of
 * ModuleTemplateCatalog::registerFunctions() by ep-slay-template-catalog
 * (tk-mtc-assets).
 *
 * TwigFunctionContractTest already proves `asset()` is *registered*. What it
 * cannot see is whether the URL still carries a fingerprint — and that is the
 * property with teeth: the framework serves fingerprinted assets `immutable`,
 * so an unversioned URL does not break a page, it silently downgrades every
 * asset to `must-revalidate` + ETag and turns cache hits into conditional
 * requests on every view. Exactly the kind of regression a "the function exists"
 * test waves through.
 */
final class AssetTwigExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        // AssetManager memoizes per-module versions and file fingerprints in
        // statics that outlive a single test. Clearing them here keeps these
        // assertions independent of whatever ran before them in the suite.
        AssetManager::reset();
    }

    #[Test]
    public function an_asset_url_carries_a_cache_busting_version(): void
    {
        $url = (new AssetTwigExtension())->assetUrl('app.css');

        // Deliberately not pinned to a hex shape: the version is a file
        // fingerprint only when the file is on disk, and otherwise falls back to
        // SEMITEXA_ASSET_VERSION / SEMITEXA_RELEASE_VERSION / APP_VERSION, which
        // are release strings like `2026.07.22.1910`. What has teeth is that a
        // non-empty version is present at all — without it the framework serves
        // the asset must-revalidate instead of immutable.
        self::assertMatchesRegularExpression(
            '/\?v=.+$/',
            $url,
            'asset() lost its fingerprint; every asset silently drops to must-revalidate caching',
        );
    }

    #[Test]
    public function a_module_asset_is_served_from_that_modules_namespace(): void
    {
        $url = (new AssetTwigExtension())->assetUrl('js/ui-core.js', 'PlatformUi');

        self::assertStringStartsWith('/assets/PlatformUi/js/ui-core.js', $url);
        self::assertStringContainsString('?v=', $url);
    }

    #[Test]
    public function an_asset_with_no_module_falls_back_to_the_app_namespace(): void
    {
        $url = (new AssetTwigExtension())->assetUrl('app.css');

        self::assertStringStartsWith('/assets/app/app.css', $url);
    }

    #[Test]
    public function requiring_an_asset_emits_nothing_of_its_own(): void
    {
        // asset_require() is a statement wearing a function's clothes: templates
        // call it for the side effect, and anything it returned would land in
        // the middle of the markup.
        self::assertSame('', (new AssetTwigExtension())->requireAsset('PlatformUi:js:ui-core'));
    }

    #[Test]
    public function a_malformed_asset_key_is_rejected_rather_than_silently_ignored(): void
    {
        // Keys are '{module}:{type}:{name}'. Templates write these by hand, so a
        // typo is likely — and failing loudly at render beats a page that simply
        // renders without the script it asked for, which looks like a bug
        // somewhere else entirely.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid asset key format/');

        (new AssetTwigExtension())->requireAsset('some-bundle');
    }
}
