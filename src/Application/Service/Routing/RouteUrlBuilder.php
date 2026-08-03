<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Routing;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Locale\Context\LocaleContextStore;

/**
 * Builds URLs from discovered route definitions.
 *
 * Container-managed callers inject this service directly. {@see UrlGenerator}
 * is the static entry point kept for template and documentation compatibility;
 * it delegates here and holds no logic of its own.
 */
#[AsService]
final class RouteUrlBuilder
{
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    /**
     * @param array<string, mixed> $params
     */
    public function to(string $routeName, array $params = []): string
    {
        $route = $this->attributeDiscovery->findRouteByName($routeName)
            ?? $this->attributeDiscovery->findRoute($routeName, 'GET');

        if ($route === null) {
            throw new \RuntimeException("Route '{$routeName}' not found");
        }

        return $this->prefixLocale($this->buildPath((string) $route['path'], $params));
    }

    private function prefixLocale(string $path): string
    {
        if (!LocaleContextStore::isUrlPrefixEnabled()) {
            return $path;
        }

        $locale = LocaleContextStore::getLocale();

        if ($locale === LocaleContextStore::getDefaultLocale()) {
            return $path;
        }

        return '/' . $locale . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildPath(string $path, array $params): string
    {
        // Only the braced token forms are substituted. A bare `str_replace($key, ...)`
        // used to run as a second pass here, which rewrote the parameter name wherever
        // it appeared as a substring of a static segment: `/id-cards/{id}` with id=7
        // came back as `/7-cards/7`.
        foreach ($params as $key => $value) {
            $encoded = urlencode((string) $value);
            $path = str_replace(['{' . $key . '}', '{' . $key . '?}'], $encoded, $path);
        }

        return preg_replace('/\{(\w+)\?\}/', '', $path) ?? $path;
    }
}
