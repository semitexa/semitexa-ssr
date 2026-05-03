<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Collects asset requirements for a single request and resolves them in dependency order.
 *
 * Boot-time: discovers asset manifests (assets.json) from all registered modules.
 * Request-time: modules and handlers call require() to declare which assets are needed.
 *
 * This class uses a static $declarations map (populated once at boot, shared read-only
 * across Swoole workers) and per-instance $required state (request-scoped).
 *
 * Manifest format: v2 only (semitexa://asset-manifest/v2).
 * Location: {module}/Application/Static/assets.json
 *           {package}/src/Application/Static/assets.json
 */
final class AssetCollector
{
    /** @worker-scoped Boot-time declarations keyed by canonical asset key. Immutable after boot(). */
    /** @var array<string, AssetEntry> */
    private static array $declarations = [];

    /** @worker-scoped */
    private static bool $booted = false;
    /** @worker-scoped */
    private static ?ModuleRegistry $moduleRegistry = null;

    public static function setModuleRegistry(ModuleRegistry $moduleRegistry): void
    {
        self::$moduleRegistry = $moduleRegistry;
    }

    /** @var array<string, AssetEntry> Per-request required assets keyed by canonical key */
    private array $required = [];

    /**
     * Boot-time initialization: discover and parse all module asset manifests.
     * Must be called once before the first request (e.g. in server bootstrap).
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::discoverManifests();
        self::$booted = true;
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

        $entry = self::$declarations[$key] ?? AssetEntry::fromKey($key);

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
        foreach (self::$declarations as $key => $entry) {
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
        foreach (self::$declarations as $key => $entry) {
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
     * Get all boot-time declarations. Used for introspection and testing.
     *
     * @return array<string, AssetEntry>
     */
    public static function getDeclarations(): array
    {
        return self::$declarations;
    }

    /**
     * Register a single declaration programmatically.
     * Intended for packages that need to register assets outside of assets.json.
     */
    public static function declare(AssetEntry $entry): void
    {
        self::$declarations[$entry->key] = $entry;
    }

    /**
     * Reset boot-time state. Used in testing only.
     */
    public static function resetBoot(): void
    {
        self::$declarations = [];
        self::$booted = false;
    }

    /**
     * Discover Application/Static/assets.json manifests from all registered modules.
     * Logs an error at boot if a Static/ directory exists without an assets.json, but continues.
     */
    private static function discoverManifests(): void
    {
        if (self::$moduleRegistry === null) {
            throw new \LogicException('AssetCollector requires ModuleRegistry instance. Call setModuleRegistry() first.');
        }

        $modules = self::$moduleRegistry->getModules();

        foreach ($modules as $module) {
            $moduleName = $module['name'];
            $modulePath = $module['path'];

            if ($moduleName === '' || $modulePath === '') {
                continue;
            }

            // Check src/Application/Static/ first (PSR-4 packages), then Application/Static/ (local modules)
            $staticDirCandidates = [
                $modulePath . '/src/Application/Static',
                $modulePath . '/Application/Static',
            ];

            $existingStaticDirs = [];
            $manifestLoaded = false;

            foreach ($staticDirCandidates as $staticDir) {
                if (!is_dir($staticDir)) {
                    continue;
                }

                $existingStaticDirs[] = $staticDir;

                $manifestPath = $staticDir . '/assets.json';

                if (!is_file($manifestPath)) {
                    continue;
                }

                self::parseManifestV2($manifestPath, $staticDir, $moduleName);
                $manifestLoaded = true;
                break;
            }

            if (!$manifestLoaded && $existingStaticDirs !== []) {
                StaticLoggerBridge::warning('ssr', 'Module has Application/Static/ directory but no assets.json manifest', [
                    'module' => $moduleName,
                    'candidates' => $existingStaticDirs,
                ]);
            }
        }
    }

    /**
     * Parse a v2 assets.json manifest file.
     *
     * v2 format uses include rules (glob patterns) for auto-discovery of files
     * in Application/Static/css/ and Application/Static/js/, with optional
     * overrides, excludes, and extras for assets requiring explicit configuration.
     */
    private static function parseManifestV2(string $manifestPath, string $staticDir, string $fallbackModule): void
    {
        $content = file_get_contents($manifestPath);
        if ($content === false) {
            StaticLoggerBridge::error('ssr', 'Failed to read asset manifest', ['path' => $manifestPath]);
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            StaticLoggerBridge::error('ssr', 'Invalid JSON in asset manifest', ['path' => $manifestPath]);
            return;
        }

        $schema = $data['$schema'] ?? '';
        if (!str_contains($schema, 'asset-manifest/v2')) {
            StaticLoggerBridge::warning('ssr', 'Asset manifest is not v2 format, skipping', ['path' => $manifestPath]);
            return;
        }

        $include = $data['include'] ?? null;
        if (!is_array($include)) {
            StaticLoggerBridge::warning('ssr', 'Asset manifest missing required include block, skipping', ['path' => $manifestPath]);
            return;
        }

        $module   = is_string($data['module'] ?? null) ? $data['module'] : $fallbackModule;
        $overrides = is_array($data['overrides'] ?? null) ? $data['overrides'] : [];
        $exclude   = is_array($data['exclude'] ?? null) ? $data['exclude'] : [];
        $extras    = is_array($data['extras'] ?? null) ? $data['extras'] : [];

        // Auto-discover files by include patterns
        foreach (['css', 'js'] as $type) {
            $patterns = $include[$type] ?? [];
            if (!is_array($patterns) || $patterns === []) {
                continue;
            }

            $typeDir = $staticDir . '/' . $type;
            if (!is_dir($typeDir)) {
                continue;
            }

            $files = self::scanStaticDirectory($typeDir, $type, $patterns);

            foreach ($files as $fullRelative) {
                // fullRelative is relative to staticDir (e.g. "css/demo.css", "js/modules/wm.js")
                if (in_array($fullRelative, $exclude, true)) {
                    continue;
                }

                // Derive logical name: strip type prefix + extension
                $relativeToTypeDir = substr($fullRelative, strlen($type) + 1);
                $nameWithoutExt    = preg_replace('/\.[^.\/]+$/', '', $relativeToTypeDir);
                $key               = $module . ':' . $type . ':' . $nameWithoutExt;

                // Convention defaults by type
                if ($type === 'css') {
                    $scope    = 'module';
                    $position = 'head';
                    $priority = 50;
                } else {
                    $scope    = 'page';
                    $position = 'body';
                    $priority = 90;
                }

                // Apply per-file overrides (keyed by path relative to staticDir)
                $override     = is_array($overrides[$fullRelative] ?? null) ? $overrides[$fullRelative] : [];
                $scope        = is_string($override['scope'] ?? null)    ? $override['scope']    : $scope;
                $position     = is_string($override['position'] ?? null) ? $override['position'] : $position;
                $priority     = is_int($override['priority'] ?? null)    ? $override['priority'] : $priority;
                $attributes   = is_array($override['attributes'] ?? null)  ? $override['attributes']  : [];
                $dependencies = is_array($override['dependencies'] ?? null) ? $override['dependencies'] : [];

                if (!AssetEntry::isValidKey($key)) {
                    StaticLoggerBridge::warning('ssr', 'Derived asset key is invalid, skipping', ['key' => $key, 'file' => $fullRelative]);
                    continue;
                }

                self::$declarations[$key] = new AssetEntry(
                    key:          $key,
                    module:       $module,
                    type:         $type,
                    path:         $fullRelative,
                    scope:        $scope,
                    position:     $position,
                    priority:     $priority,
                    attributes:   $attributes,
                    dependencies: $dependencies,
                );
            }
        }

        // Process extras: explicit declarations for assets requiring special configuration
        // (e.g. preload hints, assets with custom types not derivable from extension)
        foreach ($extras as $extra) {
            if (!is_array($extra)) {
                continue;
            }

            $key = $extra['key'] ?? '';
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (!AssetEntry::isValidKey($key)) {
                StaticLoggerBridge::warning('ssr', 'Invalid asset key in extras, skipping', ['key' => $key, 'manifest' => $manifestPath]);
                continue;
            }

            $parts       = explode(':', $key, 3);
            $entryModule = $parts[0];

            /** @var array<string, mixed> $extra */
            self::$declarations[$key] = AssetEntry::fromManifest($key, $entryModule, $extra);
        }
    }

    /**
     * Recursively scan a type directory (css/ or js/) and return paths relative
     * to the staticDir (e.g. "css/demo.css", "js/modules/wm.js").
     *
     * Uses RecursiveDirectoryIterator — do not rely on glob("**") semantics.
     *
     * @param  string   $typeDir    Absolute path to the type directory
     * @param  string   $type       "css" or "js"
     * @param  string[] $patterns   Include patterns relative to staticDir (e.g. "css/**\/*.css")
     * @return string[]             Sorted list of relative paths
     */
    private static function scanStaticDirectory(string $typeDir, string $type, array $patterns): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($typeDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            // Normalize to forward slashes and compute relative path from staticDir parent
            $absPath      = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            $typeDirNorm  = str_replace(DIRECTORY_SEPARATOR, '/', $typeDir);
            $relToTypeDir = ltrim(substr($absPath, strlen($typeDirNorm)), '/');
            $fullRelative = $type . '/' . $relToTypeDir;

            // Match against include patterns using glob semantics:
            //   **  matches any sequence of characters including path separators (zero or more levels)
            //   *   matches any sequence of characters except path separators
            foreach ($patterns as $pattern) {
                if (self::matchesGlobPattern($pattern, $fullRelative)) {
                    $found[] = $fullRelative;
                    break;
                }
            }
        }

        sort($found); // Lexicographic order (tertiary sort per spec §4.3.5)
        return $found;
    }

    /**
     * Match a path against a glob pattern with proper double-star support.
     *
     * A "**" followed by "/" matches zero or more path segments including the
     * case where the file sits directly in the typed root (no subdirectory).
     * A single "*" matches any non-separator sequence within one path segment.
     *
     * PHP's built-in fnmatch() with FNM_PATHNAME does NOT implement this
     * correctly — double-star is treated identically to single-star, so the
     * pattern "css/** /*.css" will not match "css/demo.css".
     */
    private static function matchesGlobPattern(string $pattern, string $path): bool
    {
        // Escape all regex metacharacters first, then restore wildcards.
        $regex = preg_quote($pattern, '#');

        // \*\*/ (two stars + slash) → optional any-depth prefix "(.*/)?".
        // This makes css/**/*.css match BOTH css/demo.css and css/sub/demo.css.
        $regex = str_replace('\*\*/', '(.*/)?', $regex);

        // \*\* at end of pattern (no trailing slash) → .* (match any remainder).
        $regex = str_replace('\*\*', '.*', $regex);

        // Single \* → any non-separator sequence within one path segment.
        $regex = str_replace('\*', '[^/]*', $regex);

        return (bool) preg_match('#^' . $regex . '$#', $path);
    }

}
