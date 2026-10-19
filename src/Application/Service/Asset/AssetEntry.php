<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

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
     * @param array<string, mixed> $attributes Extra HTML attributes (e.g. ["type" => "module", "defer" => true])
     * @param string[] $dependencies Canonical keys of assets that must load before this one
     * @param ?string  $specifier    Bare import-map specifier (e.g. "platform-ui/core") — when set,
     *                               the page's server-generated <script type="importmap"> maps it to
     *                               this asset's fingerprinted URL so ES modules can `import` it
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
        public ?string $specifier = null,
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
        // Manifests are hand-written JSON: a wrongly typed field falls back to
        // its default instead of a TypeError during registry boot.
        $type = is_string($definition['type'] ?? null) ? $definition['type'] : 'js';

        $defaultPosition = match ($type) {
            'css', 'inline-css', 'preload' => 'head',
            default                        => 'body',
        };

        return new self(
            key: $key,
            module: $module,
            type: $type,
            path: is_string($definition['path'] ?? null) ? $definition['path'] : '',
            scope: is_string($definition['scope'] ?? null) ? $definition['scope'] : 'page',
            position: is_string($definition['position'] ?? null) ? $definition['position'] : $defaultPosition,
            priority: is_int($definition['priority'] ?? null) ? $definition['priority'] : 100,
            attributes: self::attributeMap($definition['attributes'] ?? null),
            dependencies: self::stringList($definition['dependencies'] ?? null),
            specifier: is_string($definition['specifier'] ?? null) ? $definition['specifier'] : null,
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
            module: is_string($overrides['module'] ?? null) ? $overrides['module'] : $this->module,
            type: is_string($overrides['type'] ?? null) ? $overrides['type'] : $this->type,
            path: is_string($overrides['path'] ?? null) ? $overrides['path'] : $this->path,
            scope: is_string($overrides['scope'] ?? null) ? $overrides['scope'] : $this->scope,
            position: is_string($overrides['position'] ?? null) ? $overrides['position'] : $this->position,
            priority: is_int($overrides['priority'] ?? null) ? $overrides['priority'] : $this->priority,
            attributes: array_merge($this->attributes, self::attributeMap($overrides['attributes'] ?? null)),
            dependencies: isset($overrides['dependencies']) ? self::stringList($overrides['dependencies']) : $this->dependencies,
            specifier: is_string($overrides['specifier'] ?? null) ? $overrides['specifier'] : $this->specifier,
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

    /**
     * Manifest and override lists arrive as decoded JSON. Like the registry's
     * own reading of overrides, a value of the wrong type falls back rather
     * than reaching the constructor: here, non-string entries are dropped.
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * HTML attributes from decoded JSON: only string-named ones are attributes.
     *
     * @return array<string, mixed>
     */
    public static function attributeMap(mixed $value): array
    {
        return is_array($value) ? array_filter($value, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }
}
