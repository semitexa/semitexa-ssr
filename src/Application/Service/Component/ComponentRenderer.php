<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

use Semitexa\Ssr\Application\Service\DataProviderRegistry;

/**
 * Static entry point for component rendering.
 *
 * Retained while ModuleTemplateRegistry registers the Twig `component()` bridge
 * from a static context that cannot inject. It holds exactly one wired slot and
 * no logic — everything lives in {@see ComponentHtmlRenderer}, which
 * container-managed callers inject directly.
 *
 * Unlike UrlGenerator this is not documented public API, so it is a full
 * deletion candidate once the template registry stops being static.
 */
final class ComponentRenderer
{
    private static ?ComponentHtmlRenderer $renderer = null;

    public static function setRenderer(ComponentHtmlRenderer $renderer): void
    {
        self::$renderer = $renderer;
    }

    public static function setDataProviderRegistry(?DataProviderRegistry $registry): void
    {
        self::renderer()->setDataProviderRegistry($registry);
    }

    public static function setCurrentRequest(?object $request): void
    {
        self::renderer()->setCurrentRequest($request);
    }

    /**
     * @param array<array-key, mixed> $props
     * @param array<array-key, mixed> $slots
     */
    public static function render(
        string $name,
        array $props = [],
        array $slots = [],
        bool $forceImmediateRender = false,
    ): string {
        return self::renderer()->render($name, $props, $slots, $forceImmediateRender);
    }

    /**
     * @param array<array-key, mixed> $default
     * @return array<array-key, mixed>
     */
    public static function getSlot(string $componentName, string $slotName, array $default = []): array
    {
        return self::renderer()->getSlot($componentName, $slotName, $default);
    }

    /**
     * Falls back to an unwired instance so unit tests that drive the static API
     * without a container keep the historical behaviour: no injected registry,
     * therefore no provider resolution.
     */
    private static function renderer(): ComponentHtmlRenderer
    {
        return self::$renderer ??= new ComponentHtmlRenderer();
    }
}
