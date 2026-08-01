<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

use Semitexa\Core\ModuleRegistry;

/**
 * Static entry point for module asset resolution.
 *
 * Retained because callers reach it from static contexts that cannot inject:
 * semitexa-theme binds the per-request theme chain through setChainResolver()
 * and registers the skins alias, DeferredTemplateRegistry registers its own,
 * and three module test bootstraps wire the module registry by hand.
 *
 * It holds exactly one wired slot and no logic — resolution, the alias map and
 * the theme chain live in {@see ModuleAssetResolver}, which container-managed
 * callers inject directly.
 *
 * Not documented public API, so this is a full deletion candidate once those
 * static callers are migrated.
 */
class ModuleAssetRegistry
{
    private static ?ModuleAssetResolver $resolver = null;

    public static function setResolver(ModuleAssetResolver $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function setChainResolver(?\Closure $chainResolver): void
    {
        self::resolver()->setChainResolver($chainResolver);
    }

    public static function setModuleRegistry(ModuleRegistry $moduleRegistry): void
    {
        self::resolver()->setModuleRegistry($moduleRegistry);
    }

    public static function initialize(): void
    {
        self::resolver()->initialize();
    }

    public static function reset(): void
    {
        self::resolver()->reset();
    }

    public static function registerAlias(string $alias, string $absolutePath): void
    {
        self::resolver()->registerAlias($alias, $absolutePath);
    }

    public static function resolve(string $module, string $path): ?string
    {
        return self::resolver()->resolve($module, $path);
    }

    /**
     * Falls back to an unwired resolver so test bootstraps driving the static
     * API without a container keep working: they supply the module registry
     * themselves through setModuleRegistry().
     */
    private static function resolver(): ModuleAssetResolver
    {
        return self::$resolver ??= new ModuleAssetResolver();
    }
}
