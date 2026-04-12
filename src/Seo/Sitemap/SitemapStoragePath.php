<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Seo\Sitemap;

use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Core\Tenant\TenantContextInterface;

final class SitemapStoragePath
{
    public static function generatedDirectory(?TenantContextInterface $tenantContext = null): string
    {
        return ProjectRoot::get() . '/var/sitemap/' . self::tenantCacheKey($tenantContext);
    }

    public static function tenantCacheKey(?TenantContextInterface $tenantContext = null): string
    {
        $tenantId = 'default';
        if ($tenantContext !== null && method_exists($tenantContext, 'getTenantId')) {
            $resolvedTenantId = $tenantContext->getTenantId();
            if (is_scalar($resolvedTenantId) || $resolvedTenantId instanceof \Stringable) {
                $tenantId = (string) $resolvedTenantId;
            }
        }

        $tenantId = strtolower(trim($tenantId));
        $tenantId = preg_replace('/[^a-z0-9_-]+/', '-', $tenantId) ?? 'default';

        return $tenantId !== '' ? $tenantId : 'default';
    }
}
