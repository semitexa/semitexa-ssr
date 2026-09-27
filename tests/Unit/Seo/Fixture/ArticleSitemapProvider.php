<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo\Fixture;

use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrl;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrlProviderInterface;

/** An app provider that knows its pages: runs first, carries titles. */
final class ArticleSitemapProvider implements SitemapUrlProviderInterface
{
    public function provideUrls(SitemapGenerationContext $context): iterable
    {
        $base = rtrim($context->baseUrl, '/');

        yield new SitemapUrl($base . '/', title: 'Home of the museum');
        yield new SitemapUrl(
            $base . '/blog/first-light',
            lastmod: new \DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            title: 'First light',
            description: 'How the gallery opens at dawn.',
        );
    }
}
