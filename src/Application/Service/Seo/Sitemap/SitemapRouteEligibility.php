<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap;

use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Support\TenantModuleScopeResolver;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Ssr\Application\Service\Seo\AiSitemapLocator;

/**
 * Which discovered routes a site may advertise to machines — one answer for
 * sitemap.xml and /sitemap.json alike.
 *
 * The two used to decide separately, and only sitemap.xml was ever fixed: the
 * AI sitemap of a multi-tenant install listed other tenants' pages, five copies
 * of `/`, and the dev module's /__observatory and /__trace, which production
 * answers with 404. Every rule lives here so that cannot happen twice.
 */
final class SitemapRouteEligibility
{
    /** Machine files describe the site; they are not pages of it. */
    public const array MACHINE_FILES = [
        '/robots.txt',
        '/llms.txt',
        '/sitemap.xml',
        AiSitemapLocator::PATH,
    ];

    /**
     * The full gate for one tenant: in the tenant's scope AND listable at all.
     *
     * @param array<string, mixed> $route
     */
    public static function isListableFor(array $route, ?TenantContextInterface $tenantContext): bool
    {
        return self::isInTenantScope($route, $tenantContext) && self::isListable($route);
    }

    /**
     * One site's map lists one site's routes. Route discovery is install-wide;
     * a route with no tenant scope belongs to every tenant.
     *
     * @param array<string, mixed> $route
     */
    public static function isInTenantScope(array $route, ?TenantContextInterface $tenantContext): bool
    {
        return TenantModuleScopeResolver::isRouteAllowedForTenant($route, $tenantContext);
    }

    /**
     * Tenant-independent rules: public, GET, not framework-internal, not a
     * machine file, not opted out with {@see NotInSitemap}.
     *
     * @param array<string, mixed> $route
     */
    public static function isListable(array $route): bool
    {
        if (self::accessTypeOf($route) !== 'public') {
            return false;
        }

        $path = self::stringValue($route['path'] ?? '');
        // Any '/__' path is framework-internal by convention, not just '/__semitexa'.
        if ($path === '' || str_starts_with($path, '/__')) {
            return false;
        }

        if (in_array($path, self::MACHINE_FILES, true)) {
            return false;
        }

        if (!in_array('GET', self::methodsOf($route), true)) {
            return false;
        }

        return !self::isOptedOut($route);
    }

    /**
     * Whether the payload behind this route asked to stay out — see {@see NotInSitemap}.
     * Read by reflection off the route's own class: sitemap membership is an SSR
     * concern, and core has no reason to learn about it.
     *
     * @param array<string, mixed> $route
     */
    public static function isOptedOut(array $route): bool
    {
        $class = self::stringValue($route['class'] ?? '');
        if ($class === '' || !class_exists($class)) {
            return false;
        }

        return (new \ReflectionClass($class))->getAttributes(NotInSitemap::class) !== [];
    }

    /**
     * @param array<string, mixed> $route
     * @return list<string> upper-cased, unique, sorted, non-empty
     */
    public static function methodsOf(array $route): array
    {
        $methods = $route['methods'] ?? [$route['method'] ?? 'GET'];
        if (!is_array($methods)) {
            $methods = [$methods];
        }

        $normalized = array_values(array_unique(array_map(
            static fn (mixed $v): string => strtoupper(trim(self::stringValue($v))),
            $methods,
        )));
        sort($normalized);

        return array_values(array_filter($normalized, static fn (string $v): bool => $v !== ''));
    }

    /**
     * Routes carry accessType, a PayloadAccessType enum (or its string value in
     * hand-built arrays). A raw route has no 'access' and no 'public' key; both
     * were assumed at different times and each silently rejected every route.
     *
     * @param array<string, mixed> $route
     */
    public static function accessTypeOf(array $route): ?string
    {
        $value = $route['accessType'] ?? null;

        if ($value instanceof PayloadAccessType) {
            return $value->value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function stringValue(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }
}
