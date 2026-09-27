<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

require_once __DIR__ . '/Fixture/SitemapTestKit.php';
require_once __DIR__ . '/Fixture/ArticleSitemapProvider.php';
require_once __DIR__ . '/Fixture/RouteLikeSitemapProvider.php';

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrl;
use Semitexa\Ssr\Tests\Unit\Seo\Fixture\ArticleSitemapProvider;
use Semitexa\Ssr\Tests\Unit\Seo\Fixture\RouteLikeSitemapProvider;
use Semitexa\Ssr\Tests\Unit\Seo\Fixture\SitemapTestKit;

/**
 * One URL, one <url> entry — MEASURED on production: once the stale persisted
 * file was gone, the freshly generated sitemap listed `/` twice, because two
 * modules declare it and the generator collected whatever the providers yielded.
 */
final class SitemapDeduplicationTest extends TestCase
{
    private const string BASE = 'https://museum.test';

    #[Test]
    public function a_loc_is_listed_once_in_sitemap_xml(): void
    {
        $xml = SitemapTestKit::generator([ArticleSitemapProvider::class, RouteLikeSitemapProvider::class])
            ->generate(new SitemapGenerationContext(self::BASE))['xml'];

        self::assertSame(1, substr_count($xml, '<loc>' . self::BASE . '/</loc>'), $xml);
        self::assertSame(3, substr_count($xml, '<loc>'), 'home, the article and /pricing');
    }

    #[Test]
    public function the_first_provider_in_priority_order_wins(): void
    {
        $urls = SitemapTestKit::generator([ArticleSitemapProvider::class, RouteLikeSitemapProvider::class])
            ->urls(new SitemapGenerationContext(self::BASE));

        $byLoc = [];
        foreach ($urls as $url) {
            $byLoc[$url->loc] = $url;
        }

        self::assertCount(3, $urls);
        self::assertSame(
            'Home of the museum',
            $byLoc[self::BASE . '/']->title,
            'the custom provider runs first and knows the title; the default must not shadow it',
        );
        self::assertSame(
            [self::BASE . '/', self::BASE . '/blog/first-light', self::BASE . '/pricing'],
            array_map(static fn (SitemapUrl $u): string => $u->loc, $urls),
            'order is provider order, then yield order',
        );
    }

    #[Test]
    public function positional_construction_stays_compatible(): void
    {
        $url = new SitemapUrl('https://x.test/a', null, 'daily', 0.8, []);

        self::assertSame('daily', $url->changefreq);
        self::assertNull($url->title);
        self::assertNull($url->description);
    }

    /**
     * An app provider running on older ssr releases sets these after construction
     * behind property_exists(); they must exist and be writable.
     */
    #[Test]
    public function title_and_description_are_settable_after_construction(): void
    {
        $url = new SitemapUrl('https://x.test/a');

        self::assertTrue(property_exists($url, 'title'));
        self::assertTrue(property_exists($url, 'description'));

        $url->title = 'A';
        $url->description = 'About A';

        self::assertSame('A', $url->title);
        self::assertSame('About A', $url->description);
    }

    #[Test]
    public function title_and_description_never_reach_sitemap_xml(): void
    {
        $xml = SitemapTestKit::generator([ArticleSitemapProvider::class])
            ->generate(new SitemapGenerationContext(self::BASE))['xml'];

        self::assertStringContainsString('<lastmod>2026-09-01</lastmod>', $xml);
        self::assertStringNotContainsString('First light', $xml);
        self::assertStringNotContainsString('dawn', $xml);
    }
}
