<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap;

use Semitexa\Core\Tenant\TenantContextInterface;

final readonly class SitemapGenerationContext
{
    public function __construct(
        public string $baseUrl,
        public ?TenantContextInterface $tenantContext = null,
    ) {}
}
