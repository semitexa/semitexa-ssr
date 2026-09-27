<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Request;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerator;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapRouteEligibility;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrl;

/**
 * /sitemap.json — the route inventory offered to machine agents.
 *
 * Eligibility is the sitemap's own ({@see SitemapRouteEligibility}): the tenant
 * being served, public GET routes, nothing under '/__', no machine files, no
 * #[NotInSitemap] payloads — and each path once. Pages that only a sitemap
 * provider knows about (a blog article, say) are appended with the title and
 * description the provider gave them.
 */
#[AsService]
final class AiSitemapJsonRenderer
{
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    #[InjectAsReadonly]
    protected SitemapGenerator $sitemapGenerator;

    public function render(?Request $request = null, ?TenantContextInterface $tenantContext = null): string
    {
        return json_encode(
            $this->buildDocument($request, $tenantContext),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDocument(?Request $request = null, ?TenantContextInterface $tenantContext = null): array
    {
        $pages = [];
        $endpoints = [];
        $templates = [];

        $routes = $this->attributeDiscovery->getRoutes();
        usort($routes, static fn (array $a, array $b): int => ($a['path'] ?? '') <=> ($b['path'] ?? ''));

        /** @var array<string, true> $seenPaths */
        $seenPaths = [];

        foreach ($routes as $route) {
            if (!SitemapRouteEligibility::isInTenantScope($route, $tenantContext) || !$this->isEligibleRoute($route)) {
                continue;
            }

            $path = (string) $route['path'];
            // Several modules may declare the same path; the document lists it once.
            if (isset($seenPaths[$path])) {
                continue;
            }
            $seenPaths[$path] = true;
            $entry = $this->buildRouteEntry($route, $path, $request, $tenantContext);

            if ($this->isTemplatedPath($path)) {
                $templates[] = $entry + [
                    'path_template' => $path,
                    'parameters' => $this->extractPathParameters($path),
                ];
                continue;
            }

            if ($this->isHtmlLikeRoute($route)) {
                $pages[] = $entry;
                continue;
            }

            $endpoints[] = $entry;
        }

        $pages = [...$pages, ...$this->providerPages($pages, $endpoints, $request, $tenantContext)];

        return [
            'version' => '1.0',
            'generated_at' => gmdate(DATE_ATOM),
            'site' => [
                'ai_sitemap' => AiSitemapLocator::absoluteUrl($request, $tenantContext),
                'robots' => $this->absoluteUrl('/robots.txt', $request, $tenantContext),
                'llms' => $this->absoluteUrl('/llms.txt', $request, $tenantContext),
            ],
            'hints' => [
                'purpose' => 'Crawler-oriented route inventory for LLMs and other machine agents.',
                'preferred_flow' => [
                    'Start from pages for human-readable entry points.',
                    'Append ?_format=json to HTML pages for machine-readable page documents.',
                    'Append ?_format=json&_slot=<slot-name> for slot-level documents when needed.',
                ],
            ],
            'pages' => $pages,
            'endpoints' => $endpoints,
            'templates' => $templates,
        ];
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    private function buildRouteEntry(
        array $route,
        string $path,
        ?Request $request = null,
        ?TenantContextInterface $tenantContext = null,
    ): array
    {
        return [
            'path' => $path,
            'url' => $this->absoluteUrl($path, $request, $tenantContext),
            'route_name' => $route['name'] ?? null,
            'methods' => $this->normalizeMethods($route),
            'payload_class' => $route['class'] ?? null,
            'alternates' => [
                'json' => $this->absoluteUrl($path, $request, $tenantContext) . '?_format=json',
            ],
            'content_types' => $this->normalizeProduces($route),
        ];
    }

    /**
     * Pages a sitemap provider vouches for that no listed route already covers.
     *
     * @param list<array<string, mixed>> $pages
     * @param list<array<string, mixed>> $endpoints
     * @return list<array<string, mixed>>
     */
    private function providerPages(array $pages, array $endpoints, ?Request $request, ?TenantContextInterface $tenantContext): array
    {
        if (!isset($this->sitemapGenerator)) {
            return [];
        }

        try {
            $urls = $this->sitemapGenerator->urls(new SitemapGenerationContext(
                baseUrl: AiSitemapLocator::originUrl($request, $tenantContext),
                tenantContext: $tenantContext,
            ));
        } catch (\Throwable $e) {
            StaticLoggerBridge::warning('ssr', 'AI sitemap could not collect provider pages', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $listed = [];
        foreach ([...$pages, ...$endpoints] as $entry) {
            $listed[(string) $entry['url']] = true;
        }

        $extra = [];
        foreach ($urls as $url) {
            if (isset($listed[$url->loc])) {
                continue;
            }
            $listed[$url->loc] = true;
            $extra[] = $this->buildProviderEntry($url);
        }

        return $extra;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProviderEntry(SitemapUrl $url): array
    {
        $path = parse_url($url->loc, PHP_URL_PATH);

        return array_filter([
            'path' => is_string($path) && $path !== '' ? $path : '/',
            'url' => $url->loc,
            'title' => $url->title,
            'description' => $url->description,
            'lastmod' => $url->lastmod?->format(DATE_ATOM),
            'source' => 'sitemap_provider',
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Tenant-independent eligibility — the same rules sitemap.xml applies.
     *
     * @param array<string, mixed> $route
     */
    private function isEligibleRoute(array $route): bool
    {
        return SitemapRouteEligibility::isListable($route);
    }

    /**
     * @param array<string, mixed> $route
     */
    private function isHtmlLikeRoute(array $route): bool
    {
        $produces = $this->normalizeProduces($route);
        if ($produces === []) {
            return true;
        }

        foreach ($produces as $type) {
            if (str_contains($type, 'html')) {
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
        return SitemapRouteEligibility::methodsOf($route);
    }

    /**
     * @param array<string, mixed> $route
     * @return list<string>
     */
    private function normalizeProduces(array $route): array
    {
        $produces = $route['produces'] ?? [];
        if (!is_array($produces)) {
            $produces = [$produces];
        }

        $normalized = array_values(array_unique(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $produces
        )));

        return array_values(array_filter($normalized, static fn (string $value): bool => $value !== ''));
    }

    private function isTemplatedPath(string $path): bool
    {
        return str_contains($path, '{') && str_contains($path, '}');
    }

    /**
     * @return list<string>
     */
    private function extractPathParameters(string $path): array
    {
        preg_match_all('/\{([a-zA-Z0-9_]+\??)\}/', $path, $matches);

        return array_values(array_map(
            static fn (string $value): string => rtrim($value, '?'),
            $matches[1] ?? []
        ));
    }

    private function absoluteUrl(
        string $path,
        ?Request $request = null,
        ?TenantContextInterface $tenantContext = null,
    ): string
    {
        return AiSitemapLocator::originUrl($request, $tenantContext) . '/' . ltrim($path, '/');
    }
}
