<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Seo\Sitemap\Provider;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Locale\LocaleConfig;
use Semitexa\Ssr\Seo\AiSitemapLocator;
use Semitexa\Ssr\Seo\Sitemap\AsSitemapProvider;
use Semitexa\Ssr\Seo\Sitemap\SitemapAlternate;
use Semitexa\Ssr\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Seo\Sitemap\SitemapUrl;
use Semitexa\Ssr\Seo\Sitemap\SitemapUrlProviderInterface;

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
    protected ?AttributeDiscovery $attributeDiscovery = null;

    /** @var list<string> */
    private const array EXCLUDED_PATHS = [
        '/robots.txt',
        '/llms.txt',
        '/sitemap.xml',
        AiSitemapLocator::PATH,
    ];

    public function provideUrls(SitemapGenerationContext $context): iterable
    {
        if ($this->attributeDiscovery === null) {
            return;
        }

        $routes = $this->attributeDiscovery->getRoutes();
        usort($routes, fn (array $a, array $b): int => $this->stringValue($a['path'] ?? '') <=> $this->stringValue($b['path'] ?? ''));

        $localeConfig = LocaleConfig::fromEnvironment();
        $supportedLocales = array_values($localeConfig->supportedLocales);
        $defaultLocale = $localeConfig->defaultLocale;
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
     * @param array<string, mixed> $route
     */
    private function isEligible(array $route): bool
    {
        if ($this->normalizeTransport($route['transport'] ?? null) !== TransportType::Http->value) {
            return false;
        }

        if (($route['public'] ?? false) !== true) {
            return false;
        }

        $path = $this->stringValue($route['path'] ?? '');
        if ($path === '' || str_starts_with($path, '/__semitexa')) {
            return false;
        }

        if (in_array($path, self::EXCLUDED_PATHS, true)) {
            return false;
        }

        if (str_contains($path, '{') && str_contains($path, '}')) {
            return false;
        }

        if (!in_array('GET', $this->normalizeMethods($route), true)) {
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

    /**
     * @param array<string, mixed> $route
     * @return list<string>
     */
    private function normalizeMethods(array $route): array
    {
        $methods = $route['methods'] ?? [$route['method'] ?? 'GET'];
        if (!is_array($methods)) {
            $methods = [$methods];
        }

        return array_values(array_unique(array_map(
            fn (mixed $v): string => strtoupper(trim($this->stringValue($v))),
            $methods,
        )));
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }
}
