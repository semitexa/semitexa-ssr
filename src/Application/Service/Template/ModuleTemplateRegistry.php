<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Template;

use Semitexa\Core\ModuleRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\LoaderInterface;

/**
 * Static entry point for module template discovery and the shared Twig
 * environment.
 *
 * This is the hub of the de-staticisation epic: the Twig bridge closures it
 * registers are what still force UrlGenerator, ComponentRenderer and
 * TwigExtensionRegistry to keep delegates of their own. Discovery, the loader,
 * the module path map and the theme chain now live in
 * {@see ModuleTemplateCatalog}, a container-managed service; this class holds
 * exactly one wired slot and no logic.
 *
 * The registered Twig callables still reach ambient statics that this epic does
 * not own — Environment, SwooleBootstrap, LocaleContextStore, Translator,
 * LayoutSlotRegistry — so migrating them is deliberately a separate step.
 *
 * Not documented public API, so this is a full deletion candidate once those
 * closures inject what they need.
 */
final class ModuleTemplateRegistry
{
    private static ?ModuleTemplateCatalog $catalog = null;

    public static function setCatalog(ModuleTemplateCatalog $catalog): void
    {
        self::$catalog = $catalog;
    }

    public static function setChainResolver(?\Closure $resolver): void
    {
        self::catalog()->setChainResolver($resolver);
    }

    /**
     * @return list<string>
     */
    public static function getActiveChain(): array
    {
        return self::catalog()->getActiveChain();
    }

    public static function setModuleRegistry(ModuleRegistry $moduleRegistry): void
    {
        self::catalog()->setModuleRegistry($moduleRegistry);
    }

    public static function initialize(): void
    {
        self::catalog()->initialize();
    }

    public static function getTwig(): TwigEnvironment
    {
        return self::catalog()->getTwig();
    }

    public static function getLoader(): LoaderInterface
    {
        return self::catalog()->getLoader();
    }

    public static function getCacheDir(): ?string
    {
        return self::catalog()->getCacheDir();
    }

    public static function getTemplatePath(string $templateName): ?string
    {
        return self::catalog()->getTemplatePath($templateName);
    }

    public static function reset(): void
    {
        self::catalog()->reset();
    }

    /**
     * @return array<string, array{aliases: list<string>, path: string, type: string}>
     */
    public static function getModulePaths(): array
    {
        return self::catalog()->getModulePaths();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolveLayout(string $handle): ?array
    {
        return self::catalog()->resolveLayout($handle);
    }

    /**
     * Falls back to an unwired catalog so callers driving the static API
     * without a container keep working; they supply the module registry.
     */
    private static function catalog(): ModuleTemplateCatalog
    {
        return self::$catalog ??= new ModuleTemplateCatalog();
    }
}
