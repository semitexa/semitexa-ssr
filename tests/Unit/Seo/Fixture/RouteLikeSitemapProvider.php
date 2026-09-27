<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo\Fixture;

use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrl;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrlProviderInterface;

/** Stands in for the route-based default: runs last, no titles, `/` declared by two modules. */
final class RouteLikeSitemapProvider implements SitemapUrlProviderInterface
{
    public function provideUrls(SitemapGenerationContext $context): iterable
    {
        $base = rtrim($context->baseUrl, '/');

        yield new SitemapUrl($base . '/', changefreq: 'weekly', priority: 0.5);
        yield new SitemapUrl($base . '/', changefreq: 'weekly', priority: 0.5);
        yield new SitemapUrl($base . '/pricing', changefreq: 'weekly', priority: 0.5);
    }
}
