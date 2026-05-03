<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap;

use Attribute;

/**
 * Marks a class as a sitemap URL provider.
 *
 * The class must implement SitemapUrlProviderInterface.
 * Providers are discovered automatically and invoked during sitemap generation.
 *
 * Lower priority values execute first.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsSitemapProvider
{
    public function __construct(
        public int $priority = 0,
    ) {}
}
