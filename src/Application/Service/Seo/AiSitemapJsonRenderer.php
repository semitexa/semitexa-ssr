<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Request;
use Semitexa\Core\Tenant\TenantContextInterface;

#[AsService]
final class AiSitemapJsonRenderer
{
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

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

        foreach ($routes as $route) {
            if (!$this->isEligibleRoute($route)) {
                continue;
            }

            $path = (string) $route['path'];
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
     * @param array<string, mixed> $route
     */
    private function isEligibleRoute(array $route): bool
    {
        if (($route['public'] ?? false) !== true) {
            return false;
        }

        $path = (string) ($route['path'] ?? '');
        if ($path === '' || str_starts_with($path, '/__semitexa_')) {
            return false;
        }

        if (in_array($path, ['/robots.txt', '/llms.txt', AiSitemapLocator::PATH], true)) {
            return false;
        }

        return in_array('GET', $this->normalizeMethods($route), true);
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
        $methods = $route['methods'] ?? [$route['method'] ?? 'GET'];
        $normalized = array_values(array_unique(array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            is_array($methods) ? $methods : [$methods]
        )));
        sort($normalized);

        return array_values(array_filter($normalized, static fn (string $value): bool => $value !== ''));
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
