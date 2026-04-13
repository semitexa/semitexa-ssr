<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Seo\Sitemap;

final readonly class SitemapAlternate
{
    public function __construct(
        public string $href,
        public ?string $hreflang = null,
        public string $rel = 'alternate',
        public ?string $type = null,
    ) {}
}
