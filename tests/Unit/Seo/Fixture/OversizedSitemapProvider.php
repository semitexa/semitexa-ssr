<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo\Fixture;

use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrl;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrlProviderInterface;

/** One URL more than a single sitemap may hold, so generation splits into an index and parts. */
final class OversizedSitemapProvider implements SitemapUrlProviderInterface
{
    public const int COUNT = 50_001;

    public function provideUrls(SitemapGenerationContext $context): iterable
    {
        $base = rtrim($context->baseUrl, '/');
        for ($i = 1; $i <= self::COUNT; $i++) {
            yield new SitemapUrl($base . '/item/' . $i);
        }
    }
}
