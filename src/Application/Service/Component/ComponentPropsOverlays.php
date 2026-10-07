<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

use Semitexa\Core\Support\Row;
use Semitexa\Ssr\Domain\Contract\ComponentPropsOverlayInterface;

/**
 * The overlays a package registers at worker boot (worker-lifetime, never
 * request state), applied by the component renderer to the caller's props of
 * every component rendered for a page request.
 */
final class ComponentPropsOverlays
{
    /** @var list<ComponentPropsOverlayInterface> */
    private static array $overlays = [];

    public static function register(ComponentPropsOverlayInterface $overlay): void
    {
        foreach (self::$overlays as $known) {
            if ($known::class === $overlay::class) {
                return; // a re-run boot listener must not stack copies
            }
        }
        self::$overlays[] = $overlay;
    }

    public static function reset(): void
    {
        self::$overlays = [];
    }

    /**
     * @param array<array-key, mixed> $props
     * @return array<array-key, mixed>
     */
    public static function apply(string $componentName, array $props, ?object $request): array
    {
        if (self::$overlays === [] || !self::isPageRequest($request)) {
            return $props;
        }
        $query = property_exists($request, 'query') && is_array($request->query) ? $request->query : [];
        if ($query === []) {
            return $props;
        }
        foreach (self::$overlays as $overlay) {
            $props = $overlay->overlay($componentName, $props, $query);
        }

        return $props;
    }

    /**
     * A GET for a page — not HUG/KISS or any other `/__` door.
     *
     * @phpstan-assert-if-true object $request
     */
    private static function isPageRequest(?object $request): bool
    {
        if ($request === null || !method_exists($request, 'getMethod') || !method_exists($request, 'getPath')) {
            return false;
        }

        return strtoupper(Row::asString($request->getMethod())) === 'GET'
            && !str_starts_with(Row::asString($request->getPath()), '/__');
    }
}
