<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Routing;

use Semitexa\Core\Request;

/**
 * Static entry point for URL generation.
 *
 * Retained deliberately: this is documented public API (AI_BEST_PRACTICES.md
 * recommends `UrlGenerator::to()` to template and handler authors) and the
 * Twig `url()` function is registered from a static context that cannot inject.
 *
 * It holds exactly one wired slot and no logic — everything lives in
 * {@see RouteUrlBuilder}, which container-managed callers inject directly.
 * The wired instance is worker-lifetime and immutable, so the static slot
 * carries no request-scoped state across coroutines.
 */
final class UrlGenerator
{
    private static ?RouteUrlBuilder $builder = null;

    public static function setBuilder(RouteUrlBuilder $builder): void
    {
        self::$builder = $builder;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function to(string $routeName, array $params = []): string
    {
        return self::builder()->to($routeName, $params);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function current(Request $request, array $overrides = []): string
    {
        return self::builder()->current($request, $overrides);
    }

    private static function builder(): RouteUrlBuilder
    {
        if (self::$builder === null) {
            throw new \LogicException(
                'UrlGenerator is not wired. RouteUrlBuilder is attached at worker start; '
                . 'outside the worker lifecycle, inject RouteUrlBuilder directly.'
            );
        }

        return self::$builder;
    }
}
