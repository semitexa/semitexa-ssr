<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Routing;

use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Request;
use Semitexa\Locale\Context\LocaleContextStore;

final class UrlGenerator
{
    private static ?AttributeDiscovery $attributeDiscovery = null;

    public static function setAttributeDiscovery(AttributeDiscovery $attributeDiscovery): void
    {
        self::$attributeDiscovery = $attributeDiscovery;
    }

    public static function to(string $routeName, array $params = []): string
    {
        if (self::$attributeDiscovery === null) {
            throw new \LogicException('UrlGenerator requires AttributeDiscovery instance. Call setAttributeDiscovery() first.');
        }

        $route = self::$attributeDiscovery->findRouteByName($routeName);

        if ($route === null) {
            $route = self::findByPath($routeName);
        }

        if ($route === null) {
            throw new \RuntimeException("Route '{$routeName}' not found");
        }

        $path = self::buildPath($route['path'], $params);

        return self::prefixLocale($path);
    }

    private static function prefixLocale(string $path): string
    {
        if (!LocaleContextStore::isUrlPrefixEnabled()) {
            return $path;
        }

        $locale = LocaleContextStore::getLocale();
        $default = LocaleContextStore::getDefaultLocale();

        if ($locale === $default) {
            return $path;
        }

        return '/' . $locale . '/' . ltrim($path, '/');
    }

    public static function current(Request $request, array $overrides = []): string
    {
        $path = $request->getUri();

        if (!empty($overrides)) {
            $query = http_build_query($overrides);
            $path = strtok($path, '?') . '?' . $query;
        }

        return $path;
    }

    private static function buildPath(string $path, array $params): string
    {
        foreach ($params as $key => $value) {
            $path = str_replace("{{$key}}", urlencode((string) $value), $path);
            $path = str_replace("{$key}", urlencode((string) $value), $path);
        }

        $path = preg_replace('/\{(\w+)\?\}/', '', $path);

        return $path;
    }

    private static function findByPath(string $path): ?array
    {
        return self::$attributeDiscovery->findRoute($path, 'GET');
    }
}
