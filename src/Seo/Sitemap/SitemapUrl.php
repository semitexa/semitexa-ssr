<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Seo\Sitemap;

final readonly class SitemapUrl
{
    /**
     * @param list<SitemapAlternate> $alternates
     */
    public function __construct(
        public string $loc,
        public ?\DateTimeInterface $lastmod = null,
        public ?string $changefreq = null,
        public ?float $priority = null,
        public array $alternates = [],
    ) {}
}
