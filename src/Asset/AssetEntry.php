<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Asset;

/**
 * Immutable value object representing a single static asset declaration.
 *
 * Assets are identified by a canonical key following the convention:
 *   {module}:{type}:{name}
 *
 * Example keys:
 *   - platform-wm:css:wm-shell
 *   - platform-user:js:user-profile
 *   - ssr:js:semitexa-twig
 */
final readonly class AssetEntry
{
    /**
     * @param string   $key          Canonical asset key ({module}:{type}:{name})
     * @param string   $module       Module identifier (e.g. "platform-user")
     * @param string   $type         Asset type: css, js, preload, inline-css, inline-js
     * @param string   $path         Path relative to module's resources/ directory
     * @param string   $scope        global | module | page
     * @param string   $position     head | body
     * @param int      $priority     Lower values emit earlier (default: 100)
     * @param array    $attributes   Extra HTML attributes (e.g. ["type" => "module", "defer" => true])
     * @param string[] $dependencies Canonical keys of assets that must load before this one
     */
    public function __construct(
        public string $key,
        public string $module,
        public string $type,
        public string $path,
        public string $scope = 'page',
        public string $position = 'body',
        public int    $priority = 100,
        public array  $attributes = [],
        public array  $dependencies = [],
    ) {}

    /**
     * Create an AssetEntry by parsing a canonical key.
     *
     * Key format: {module}:{type}:{name}
     * Path is inferred as {type}/{name}.{ext}.
     */
    public static function fromKey(string $key): self
    {
        $parts = explode(':', $key, 3);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException(
                "Invalid asset key format: '{$key}'. Expected '{module}:{type}:{name}'."
            );
        }

        [$module, $type, $name] = $parts;

        if ($type === 'preload') {
            throw new \InvalidArgumentException(
                "Preload assets require an explicit target path and must be declared via manifest or overrides."
            );
        }

        $ext = match ($type) {
            'css', 'inline-css' => 'css',
            'js', 'inline-js'  => 'js',
            default             => $type,
        };

        $position = match ($type) {
            'css', 'inline-css', 'preload' => 'head',
            default                        => 'body',
        };

        return new self(
            key: $key,
            module: $module,
            type: $type,
            path: "{$ext}/{$name}.{$ext}",
            scope: 'page',
            position: $position,
            priority: 100,
            attributes: [],
            dependencies: [],
        );
    }

    /**
     * Create a new entry from a manifest array definition.
     *
     * @param string               $key      Canonical asset key
     * @param string               $module   Module identifier
     * @param array<string, mixed> $definition Manifest entry fields
     */
    public static function fromManifest(string $key, string $module, array $definition): self
    {
        $type = $definition['type'] ?? 'js';

        $defaultPosition = match ($type) {
            'css', 'inline-css', 'preload' => 'head',
            default                        => 'body',
        };

        return new self(
            key: $key,
            module: $module,
            type: $type,
            path: $definition['path'] ?? '',
            scope: $definition['scope'] ?? 'page',
            position: $definition['position'] ?? $defaultPosition,
            priority: $definition['priority'] ?? 100,
            attributes: $definition['attributes'] ?? [],
            dependencies: $definition['dependencies'] ?? [],
        );
    }

    /**
     * Return a new entry with selective field overrides.
     *
     * @param array<string, mixed> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        return new self(
            key: $this->key,
            module: $overrides['module'] ?? $this->module,
            type: $overrides['type'] ?? $this->type,
            path: $overrides['path'] ?? $this->path,
            scope: $overrides['scope'] ?? $this->scope,
            position: $overrides['position'] ?? $this->position,
            priority: $overrides['priority'] ?? $this->priority,
            attributes: array_merge($this->attributes, $overrides['attributes'] ?? []),
            dependencies: $overrides['dependencies'] ?? $this->dependencies,
        );
    }

    /**
     * Resolve to a public URL via the /assets/{module}/{path} convention.
     */
    public function toUrl(): string
    {
        return '/assets/' . $this->module . '/' . $this->path;
    }

    /**
     * Validate the canonical key format.
     *
     * Format: {module}:{type}:{name}
     * The name component may contain forward slashes for assets in subdirectories,
     * e.g. "SsrDemo:css:components/card" or "platform-wm:js:modules/window-frame".
     */
    public static function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^[a-z0-9_-]+:[a-z0-9_-]+:[a-z0-9_.\/\-]+$/i', $key);
    }
}
