<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo\Fixture;

use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrl;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrlProviderInterface;

/** Counts how often generation asked for URLs. */
final class CountingSitemapProvider implements SitemapUrlProviderInterface
{
    public static int $calls = 0;

    public function provideUrls(SitemapGenerationContext $context): iterable
    {
        self::$calls++;

        yield new SitemapUrl(rtrim($context->baseUrl, '/') . '/');
    }
}
