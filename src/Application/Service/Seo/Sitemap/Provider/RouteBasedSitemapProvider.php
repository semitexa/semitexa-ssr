<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap\Provider;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Locale\Configuration\LocaleConfig;
use Semitexa\Locale\Domain\Contract\LocalePackProviderInterface;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\AsSitemapProvider;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapAlternate;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapRouteEligibility;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrl;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrlProviderInterface;

/**
 * Default sitemap provider that yields URLs for all public, GET, HTML-like
 * routes discovered via AttributeDiscovery.
 *
 * Uses a higher numeric priority value (1000) so this default provider runs
 * after custom module providers.
 */
#[AsService]
#[AsSitemapProvider(priority: 1000)]
final class RouteBasedSitemapProvider implements SitemapUrlProviderInterface
{
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    /**
     * The tenant's own language pack. Needed because generation usually happens
     * on a schedule, where no request has resolved one.
     */
    #[InjectAsReadonly]
    protected LocalePackProviderInterface $localePacks;

    public function provideUrls(SitemapGenerationContext $context): iterable
    {
        if (!isset($this->attributeDiscovery)) {
            return;
        }

        $routes = $this->attributeDiscovery->getRoutes();

        // One site's sitemap must list one site's pages. Route discovery is
        // install-wide, so without this filter a five-tenant install gave every
        // domain every other tenant's paths — the museum advertising a hair
        // school's /courses — and three copies of /about, one per module that
        // declares it. A route with no tenant scope belongs to all of them.
        $routes = array_values(array_filter(
            $routes,
            static fn (array $route): bool => SitemapRouteEligibility::isInTenantScope($route, $context->tenantContext),
        ));

        usort($routes, fn (array $a, array $b): int => $this->stringValue($a['path'] ?? '') <=> $this->stringValue($b['path'] ?? ''));

        // Per-request (a tenant's domain serving its sitemap): the locale phase
        // stored the tenant's EFFECTIVE pack — the sitemap must list THAT
        // tenant's locales/default. Cron/pre-resolution: empty → the tenant's
        // own settings pack, and only then the install-wide config.
        $localeConfig = $this->resolveLocaleConfig();
        $tenantSupported = \Semitexa\Locale\Context\LocaleContextStore::getSupportedLocales();
        $supportedLocales = $tenantSupported !== []
            ? array_values($tenantSupported)
            : array_values($localeConfig->supportedLocales);
        $defaultLocale = $tenantSupported !== []
            ? \Semitexa\Locale\Context\LocaleContextStore::getDefaultLocale()
            : $localeConfig->defaultLocale;
        $urlPrefixEnabled = $localeConfig->urlPrefixEnabled;

        foreach ($routes as $route) {
            if (!$this->isEligible($route)) {
                continue;
            }

            $path = $this->stringValue($route['path'] ?? '');
            $baseUrl = rtrim($context->baseUrl, '/');
            $url = $baseUrl . '/' . ltrim($path, '/');

            $alternates = $this->buildAlternates($url, $path, $baseUrl, $supportedLocales, $defaultLocale, $urlPrefixEnabled);

            yield new SitemapUrl(
                loc: $url,
                changefreq: 'weekly',
                priority: 0.5,
                alternates: $alternates,
            );
        }
    }

    /**
     * The language set this sitemap should advertise.
     *
     * `LocaleConfig::fromEnvironment()` is the install's global set — for a
     * multi-tenant install that is one tenant's languages imposed on all of
     * them, which is how a Ukrainian museum's sitemap came to offer Italian
     * alternates. The pack provider reads the tenant's own settings, and the
     * settings store is tenant-scoped, so inside a per-tenant run it answers
     * for that tenant.
     */
    private function resolveLocaleConfig(): LocaleConfig
    {
        $base = LocaleConfig::fromEnvironment();

        if (!isset($this->localePacks)) {
            return $base;
        }

        try {
            return $this->localePacks->resolvedPack($base);
        } catch (\Throwable) {
            return $base;
        }
    }

    /**
     * @param list<string> $supportedLocales
     * @return list<SitemapAlternate>
     */
    private function buildAlternates(string $canonicalUrl, string $path, string $baseUrl, array $supportedLocales, string $defaultLocale, bool $urlPrefixEnabled): array
    {
        $alternates = [];

        if ($urlPrefixEnabled && count($supportedLocales) > 0) {
            foreach ($supportedLocales as $locale) {
                $locale = (string) $locale;
                $localePath = $this->buildLocalePath($path, $locale, $defaultLocale);
                $localeUrl = $baseUrl . '/' . ltrim($localePath, '/');

                $alternates[] = new SitemapAlternate(
                    href: $localeUrl,
                    hreflang: $locale,
                );
            }

            $alternates[] = new SitemapAlternate(
                href: $baseUrl . '/' . ltrim($path, '/'),
                hreflang: 'x-default',
            );
        }

        return $alternates;
    }

    private function buildLocalePath(string $path, string $locale, string $defaultLocale): string
    {
        if ($locale === $defaultLocale) {
            return $path;
        }

        return '/' . $locale . '/' . ltrim($path, '/');
    }

    /**
     * The shared rules ({@see SitemapRouteEligibility}) plus what only a sitemap
     * needs: plain HTTP, a concrete path, and an HTML response.
     *
     * @param array<string, mixed> $route
     */
    private function isEligible(array $route): bool
    {
        if ($this->normalizeTransport($route['transport'] ?? null) !== TransportType::Http->value) {
            return false;
        }

        if (!SitemapRouteEligibility::isListable($route)) {
            return false;
        }

        $path = $this->stringValue($route['path'] ?? '');
        if (str_contains($path, '{') && str_contains($path, '}')) {
            return false;
        }

        return $this->isHtmlLikeRoute($route);
    }

    private function normalizeTransport(mixed $transport): string
    {
        if ($transport instanceof TransportType) {
            return $transport->value;
        }

        $value = $this->stringValue($transport);

        return $value !== '' ? $value : TransportType::Http->value;
    }

    /**
     * @param array<string, mixed> $route
     */
    private function isHtmlLikeRoute(array $route): bool
    {
        $produces = $route['produces'] ?? [];
        if (!is_array($produces) || $produces === []) {
            return true;
        }

        foreach ($produces as $type) {
            if (is_scalar($type) && str_contains((string) $type, 'html')) {
                return true;
            }
        }

        return false;
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }
}
