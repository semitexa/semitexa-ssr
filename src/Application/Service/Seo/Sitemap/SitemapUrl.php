<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap;

/**
 * One page a sitemap provider vouches for.
 *
 * `title` and `description` are not part of the sitemap protocol and are never
 * written into sitemap.xml. They exist for the machine-readable summaries built
 * from the same URL list — /sitemap.json and llms.txt — where a provider that
 * knows a page's title (a blog article, a product) can say what the page is.
 *
 * They are plain (not readonly) on purpose: a provider that must also run on an
 * older release sets them after construction behind a property_exists() guard,
 * which a readonly property would refuse.
 */
final class SitemapUrl
{
    /** @var list<SitemapAlternate> */
    public readonly array $alternates;

    /**
     * @param list<mixed> $alternates
     */
    public function __construct(
        public readonly string $loc,
        public readonly ?\DateTimeInterface $lastmod = null,
        public readonly ?string $changefreq = null,
        public readonly ?float $priority = null,
        array $alternates = [],
        public ?string $title = null,
        public ?string $description = null,
    ) {
        foreach ($alternates as $index => $alternate) {
            if (!$alternate instanceof SitemapAlternate) {
                throw new \InvalidArgumentException(sprintf(
                    'SitemapUrl::$alternates[%d] must be a %s instance.',
                    $index,
                    SitemapAlternate::class,
                ));
            }
        }

        /** @var list<SitemapAlternate> $alternates */
        $this->alternates = $alternates;
    }
}
