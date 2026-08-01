<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Boot-time registry of asset declarations discovered from module manifests.
 *
 * Owns what used to be AssetCollector's class-level boot cache: it discovers
 * Application/Static/assets.json across registered modules once per worker and
 * then serves the parsed declarations read-only. AssetCollector keeps only the
 * per-request required-set and reads its metadata from here.
 *
 * The module registry is injected non-nullable without a default, as lint:di
 * requires of container-managed objects. Test bootstraps that construct this
 * class without a container supply it through setModuleRegistry(); until they
 * do, the property stays uninitialised and discovery fails loudly.
 *
 * Manifest format: v2 only (semitexa://asset-manifest/v2).
 * Location: {module}/Application/Static/assets.json
 *           {package}/src/Application/Static/assets.json
 */
#[AsService]
final class AssetManifestRegistry
{
    /** @worker-scoped Boot-time declarations keyed by canonical asset key. Immutable after boot(). */
    /** @var array<string, AssetEntry> */
    private array $declarations = [];

    /** @worker-scoped */
    private bool $booted = false;
    /** @worker-scoped */
    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    public function setModuleRegistry(ModuleRegistry $moduleRegistry): void
    {
        $this->moduleRegistry = $moduleRegistry;
    }

    /**
     * Boot-time initialization: discover and parse all module asset manifests.
     * Must be called once before the first request (e.g. in server bootstrap).
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->discoverManifests();
        $this->booted = true;
    }
    /**
     * Get all boot-time declarations. Used for introspection and testing.
     *
     * @return array<string, AssetEntry>
     */
    public function getDeclarations(): array
    {
        return $this->declarations;
    }

    /**
     * Look up a single declaration without copying the whole map.
     */
    public function get(string $key): ?AssetEntry
    {
        return $this->declarations[$key] ?? null;
    }

    /**
     * Register a single declaration programmatically.
     * Intended for packages that need to register assets outside of assets.json.
     */
    public function declare(AssetEntry $entry): void
    {
        $this->declarations[$entry->key] = $entry;
    }

    /**
     * Reset boot-time state. Used in testing only.
     */
    public function resetBoot(): void
    {
        $this->declarations = [];
        $this->booted = false;
    }

    /**
     * Discover Application/Static/assets.json manifests from all registered modules.
     * Logs an error at boot if a Static/ directory exists without an assets.json, but continues.
     */
    private function discoverManifests(): void
    {
        if (!isset($this->moduleRegistry)) {
            throw new \LogicException('AssetCollector requires ModuleRegistry instance. Call setModuleRegistry() first.');
        }

        $modules = $this->moduleRegistry->getModules();

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

                $this->parseManifestV2($manifestPath, $staticDir, $moduleName);
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
    private function parseManifestV2(string $manifestPath, string $staticDir, string $fallbackModule): void
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
                $specifier    = is_string($override['specifier'] ?? null) ? $override['specifier'] : null;

                if (!AssetEntry::isValidKey($key)) {
                    StaticLoggerBridge::warning('ssr', 'Derived asset key is invalid, skipping', ['key' => $key, 'file' => $fullRelative]);
                    continue;
                }

                $this->declarations[$key] = new AssetEntry(
                    key:          $key,
                    module:       $module,
                    type:         $type,
                    path:         $fullRelative,
                    scope:        $scope,
                    position:     $position,
                    priority:     $priority,
                    attributes:   $attributes,
                    dependencies: $dependencies,
                    specifier:    $specifier,
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
            $this->declarations[$key] = AssetEntry::fromManifest($key, $entryModule, $extra);
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
