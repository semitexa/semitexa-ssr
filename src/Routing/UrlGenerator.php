<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Routing;

use Semitexa\Core\Discovery\AttributeDiscovery;

final class UrlGenerator
{
    public static function to(string $routeName, array $params = []): string
    {
        $route = AttributeDiscovery::findRouteByName($routeName);
        
        if ($route === null) {
            $route = self::findByPath($routeName);
        }

        if ($route === null) {
            throw new \RuntimeException("Route '{$routeName}' not found");
        }

        return self::buildPath($route['path'], $params);
    }

    public static function current(array $overrides = []): string
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        
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
        return AttributeDiscovery::findRoute($path, 'GET');
    }
}
