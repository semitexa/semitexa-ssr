<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Discovery\ClassDiscovery;

/**
 * Static entry point for the component catalog.
 *
 * Retained while HtmlResponse, LayoutRenderer and the module test bootstraps
 * still reach for the class-level API. It holds exactly one wired slot and no
 * logic — discovery and metadata assembly live in {@see ComponentCatalog},
 * which container-managed callers inject directly.
 *
 * Not documented public API, so this is a full deletion candidate once the last
 * static caller is migrated.
 */
final class ComponentRegistry
{
    private static ?ComponentCatalog $catalog = null;

    public static function setCatalog(ComponentCatalog $catalog): void
    {
        self::$catalog = $catalog;
    }

    public static function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        self::catalog()->setClassDiscovery($classDiscovery);
    }

    public static function setMetadataProviderRegistry(?ComponentMetadataProviderRegistry $registry): void
    {
        self::catalog()->setMetadataProviderRegistry($registry);
    }

    public static function initialize(): void
    {
        self::catalog()->initialize();
    }

    /**
     * @return array{class: string, name: string, template: ?string, layout: ?string, cacheable: bool, event: ?string, triggers: list<string>, script: ?string, dataProviderClass: ?string, transportMode: TransportType, deferred: bool, providerProps: array<string, mixed>}|null
     */
    public static function get(string $name): ?array
    {
        return self::catalog()->get($name);
    }

    /**
     * @return array<string, array{class: string, name: string, template: ?string, layout: ?string, cacheable: bool, event: ?string, triggers: list<string>, script: ?string, dataProviderClass: ?string, transportMode: TransportType, deferred: bool, providerProps: array<string, mixed>}>
     */
    public static function all(): array
    {
        return self::catalog()->all();
    }

    public static function hasDeferredSseComponent(): bool
    {
        return self::catalog()->hasDeferredSseComponent();
    }

    /**
     * @param array<string, mixed> $component
     */
    public static function register(array $component): void
    {
        self::catalog()->register($component);
    }

    /**
     * Falls back to an unwired catalog so test bootstraps that drive the static
     * API without a container keep working: they set class discovery by hand.
     */
    private static function catalog(): ComponentCatalog
    {
        return self::$catalog ??= new ComponentCatalog();
    }
}
