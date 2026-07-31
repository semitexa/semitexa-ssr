<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Log\StaticLoggerBridge;


/**
 * Collects asset requirements for a single request and resolves them in dependency order.
 *
 * Per-request only. Boot-time manifest discovery now lives in
 * {@see AssetManifestRegistry}; this collector reads declaration metadata from
 * it and owns nothing but the required-set for the request it belongs to.
 *
 * Instance lifetime stays owned by {@see AssetCollectorStore}, which keeps one
 * collector per coroutine. The static members below are transitional delegates
 * for callers that still reach for the old class-level API.
 */
final class AssetCollector
{
    /** @worker-scoped Single wired slot holding the boot-time manifest registry. */
    private static ?AssetManifestRegistry $manifests = null;

    /** @var array<string, AssetEntry> Per-request required assets keyed by canonical key */
    private array $required = [];

    private AssetManifestRegistry $registry;

    public function __construct(?AssetManifestRegistry $registry = null)
    {
        $this->registry = $registry ?? self::manifests();
    }

    public static function setManifestRegistry(AssetManifestRegistry $registry): void
    {
        self::$manifests = $registry;
    }

    private static function manifests(): AssetManifestRegistry
    {
        return self::$manifests ??= new AssetManifestRegistry();
    }

    /**
     * Require an asset by its canonical key.
     *
     * If the key matches a boot-time declaration, that metadata is used.
     * Otherwise, an ad-hoc entry is inferred from the key format.
     *
     * Dependencies are auto-required recursively.
     *
     * @param string               $key       Canonical asset key ({module}:{type}:{name})
     * @param array<string, mixed> $overrides Optional field overrides
     */
    public function require(string $key, array $overrides = []): self
    {
        if (isset($this->required[$key])) {
            if ($overrides !== []) {
                StaticLoggerBridge::warning('ssr', 'Asset required with conflicting overrides; first registration wins', ['key' => $key]);
            }
            return $this; // Deduplication
        }

        $entry = $this->registry->get($key) ?? AssetEntry::fromKey($key);

        if ($overrides !== []) {
            $entry = $entry->withOverrides($overrides);
        }

        $this->required[$key] = $entry;

        // Auto-require dependencies
        foreach ($entry->dependencies as $dep) {
            $this->require($dep);
        }

        return $this;
    }

    /**
     * Require all assets declared with scope=module for the given module.
     */
    public function requireModule(string $module): self
    {
        foreach ($this->registry->getDeclarations() as $key => $entry) {
            if ($entry->module === $module && $entry->scope === 'module') {
                $this->require($key);
            }
        }
        return $this;
    }

    /**
     * Require all assets declared with scope=global.
     */
    public function requireGlobals(): self
    {
        foreach ($this->registry->getDeclarations() as $key => $entry) {
            if ($entry->scope === 'global') {
                $this->require($key);
            }
        }
        return $this;
    }

    /**
     * Return all required assets in dependency-resolved, priority-sorted order.
     *
     * @return AssetEntry[]
     */
    public function resolve(): array
    {
        return AssetResolver::topologicalSort($this->required);
    }

    /**
     * Reset per-request state. Called between requests in Swoole mode.
     */
    public function reset(): void
    {
        $this->required = [];
    }

    /**
     * Check whether a specific asset key has been required in this request.
     */
    public function has(string $key): bool
    {
        return isset($this->required[$key]);
    }

    /**
     * Boot-time initialization. Transitional delegate to the manifest registry.
     */
    public static function boot(): void
    {
        self::manifests()->boot();
    }

    /**
     * Transitional delegate kept for test bootstraps that wire the registry by hand.
     */
    public static function setModuleRegistry(ModuleRegistry $moduleRegistry): void
    {
        self::manifests()->setModuleRegistry($moduleRegistry);
    }

    /**
     * @return array<string, AssetEntry>
     */
    public static function getDeclarations(): array
    {
        return self::manifests()->getDeclarations();
    }

    public static function declare(AssetEntry $entry): void
    {
        self::manifests()->declare($entry);
    }

    /**
     * Reset boot-time state. Used in testing only.
     */
    public static function resetBoot(): void
    {
        self::manifests()->resetBoot();
    }
}
