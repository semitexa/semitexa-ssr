<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Semitexa\Core\Discovery\ClassDiscovery;

/**
 * Static entry point for discovered Twig functions and filters.
 *
 * Retained while ModuleTemplateRegistry — still static itself — pulls the
 * registered callables in from a static context, and while modules register
 * their own extensions without a container to hand. It holds exactly one wired
 * slot and no logic; discovery lives in {@see TwigExtensionCatalog}, which
 * container-managed callers inject directly.
 *
 * Not documented public API, so this is a full deletion candidate once the
 * template registry stops being static.
 */
final class TwigExtensionRegistry
{
    private static ?TwigExtensionCatalog $catalog = null;

    public static function setCatalog(TwigExtensionCatalog $catalog): void
    {
        self::$catalog = $catalog;
    }

    public static function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        self::catalog()->setClassDiscovery($classDiscovery);
    }

    public static function initialize(): void
    {
        self::catalog()->initialize();
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function registerFunction(
        string $name,
        callable $callback,
        array $options = []
    ): void {
        self::catalog()->registerFunction($name, $callback, $options);
    }

    public static function registerFilter(string $name, callable $callback): void
    {
        self::catalog()->registerFilter($name, $callback);
    }

    /**
     * @return array<string, array{callback: callable, options: array}>
     */
    public static function getFunctions(): array
    {
        return self::catalog()->getFunctions();
    }

    /**
     * @return array<string, callable>
     */
    public static function getFilters(): array
    {
        return self::catalog()->getFilters();
    }

    /**
     * Falls back to an unwired catalog so callers driving the static API
     * without a container keep working; they set class discovery themselves.
     */
    private static function catalog(): TwigExtensionCatalog
    {
        return self::$catalog ??= new TwigExtensionCatalog();
    }
}
