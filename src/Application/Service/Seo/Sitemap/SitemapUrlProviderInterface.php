<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap;

/**
 * Implement this interface and mark the class with #[AsSitemapProvider]
 * to contribute URLs to the generated sitemap.xml.
 *
 * Providers are discovered automatically by the framework.
 * Any module can add its own provider without configuration.
 */
interface SitemapUrlProviderInterface
{
    /**
     * @return iterable<SitemapUrl>
     */
    public function provideUrls(SitemapGenerationContext $context): iterable;
}
