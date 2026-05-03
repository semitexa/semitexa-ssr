<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap;

final readonly class SitemapUrl
{
    /** @var list<SitemapAlternate> */
    public array $alternates;

    /**
     * @param list<mixed> $alternates
     */
    public function __construct(
        public string $loc,
        public ?\DateTimeInterface $lastmod = null,
        public ?string $changefreq = null,
        public ?float $priority = null,
        array $alternates = [],
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
