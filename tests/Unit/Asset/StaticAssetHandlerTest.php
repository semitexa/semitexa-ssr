<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Application\Service\Asset\StaticAssetHandler;

final class StaticAssetHandlerTest extends TestCase
{
    /**
     * Regression guard: module-bundled video (promo/hero clips) must be both
     * allow-listed for resolution and mapped to a proper video/* content-type.
     * These used to live only in a gitignored vendor patch that composer
     * rolled back on every ssr bump, silently 404-ing hero videos on prod.
     */
    #[Test]
    public function video_assets_are_allow_listed_and_typed(): void
    {
        $contentTypes = new \ReflectionClassConstant(StaticAssetHandler::class, 'CONTENT_TYPES');
        $map = $contentTypes->getValue();
        self::assertSame('video/mp4', $map['mp4'] ?? null);
        self::assertSame('video/webm', $map['webm'] ?? null);

        $allowed = new \ReflectionClassConstant(ModuleAssetRegistry::class, 'ALLOWED_EXTENSIONS');
        $extensions = $allowed->getValue();
        self::assertContains('mp4', $extensions);
        self::assertContains('webm', $extensions);
    }

    #[Test]
    public function match_prefix_accepts_assets_and_static_routes(): void
    {
        $method = new \ReflectionMethod(StaticAssetHandler::class, 'matchPrefix');
        $method->setAccessible(true);

        self::assertSame('/assets/', $method->invoke(null, '/assets/semitexa-site/css/site.css?v=1'));
        self::assertSame('/static/', $method->invoke(null, '/static/semitexa-site/css/site.css?v=1'));
        self::assertNull($method->invoke(null, '/foo/semitexa-site/css/site.css?v=1'));
    }

    /**
     * `immutable` is a one-year-no-revalidate promise — only safe when the
     * URL itself encodes the content version. Hardcoded module asset URLs
     * with no `?v=` get pinned in browsers for a year (this is the regression
     * that hid a CSRF JS fix from the live PipelineTest page). The handler
     * must downgrade unversioned URLs to a revalidating cache directive.
     */
    #[Test]
    public function unversioned_uri_downgrades_cache_control_to_must_revalidate(): void
    {
        self::assertSame(
            'public, max-age=0, must-revalidate',
            StaticAssetHandler::cacheControlForUri('/assets/PipelineTest/js/pipeline-test.js'),
        );
        self::assertSame(
            'public, max-age=0, must-revalidate',
            StaticAssetHandler::cacheControlForUri('/assets/PipelineTest/js/pipeline-test.js?other=1'),
        );
        self::assertSame(
            'public, max-age=0, must-revalidate',
            StaticAssetHandler::cacheControlForUri('/assets/PipelineTest/js/pipeline-test.js?v='),
        );
    }

    #[Test]
    public function versioned_uri_keeps_immutable_cache_control(): void
    {
        self::assertSame(
            'public, max-age=31536000, immutable',
            StaticAssetHandler::cacheControlForUri('/assets/PipelineTest/js/pipeline-test.js?v=ab12cd34ef56'),
        );
        self::assertSame(
            'public, max-age=31536000, immutable',
            StaticAssetHandler::cacheControlForUri('/assets/site/css/site.css?other=1&v=hash'),
        );
    }

    #[Test]
    public function etag_for_file_is_quoted_sha256_hash(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'static-asset-etag-');
        self::assertNotFalse($tmp);
        try {
            file_put_contents($tmp, 'hello world');
            $expected = '"' . hash('sha256', 'hello world') . '"';

            self::assertSame($expected, StaticAssetHandler::etagForFile($tmp));
            self::assertStringStartsWith('"', StaticAssetHandler::etagForFile($tmp));
            self::assertStringEndsWith('"', StaticAssetHandler::etagForFile($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function etag_for_missing_file_is_empty_so_no_header_is_sent(): void
    {
        $missing = sys_get_temp_dir() . '/static-asset-handler-test-missing-' . bin2hex(random_bytes(6));
        self::assertFileDoesNotExist($missing);

        // An empty ETag sentinel signals handle() to skip emitting the header.
        // This keeps the conditional-GET path safe when stat() fails.
        self::assertSame('', @StaticAssetHandler::etagForFile($missing));
    }

    #[Test]
    public function if_none_match_accepts_star_lists_and_weak_matches(): void
    {
        $method = new \ReflectionMethod(StaticAssetHandler::class, 'ifNoneMatchMatches');
        $method->setAccessible(true);
        $etag = '"abc123"';

        self::assertTrue($method->invoke(null, '*', $etag));
        self::assertTrue($method->invoke(null, $etag, $etag));
        self::assertTrue($method->invoke(null, 'W/"abc123"', $etag));
        self::assertTrue($method->invoke(null, '"other", W/"abc123"', $etag));
        self::assertFalse($method->invoke(null, '"other", W/"else"', $etag));
        self::assertFalse($method->invoke(null, null, $etag));
    }
}
