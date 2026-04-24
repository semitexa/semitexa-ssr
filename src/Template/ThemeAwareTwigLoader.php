<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Template;

use Semitexa\Core\Support\ProjectRoot;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Wraps a FilesystemLoader and intercepts `@project-layouts-<module>/*`
 * lookups. For each lookup consults a chain-resolver closure; walks the
 * returned theme chain leaf-first, returning the first override file
 * that exists under `src/theme/<theme>/<module>/templates/<relative>`.
 * Falls back to the wrapped loader (module default) when no override is
 * found in any theme.
 *
 * Any namespace other than `project-layouts-*` delegates straight through.
 *
 * When the chain resolver returns an empty array (no provider bound, or
 * provider returned empty), behavior matches the legacy wrapped loader
 * exactly — env-THEME-based overrides already registered at boot time
 * are the source of truth.
 */
final class ThemeAwareTwigLoader implements LoaderInterface
{
    /** @var \Closure(): list<string> */
    private readonly \Closure $chainResolver;

    public function __construct(
        private readonly FilesystemLoader $delegate,
        \Closure $chainResolver,
    ) {
        $this->chainResolver = $chainResolver;
    }

    public function getSourceContext(string $name): Source
    {
        $override = $this->resolveOverride($name);
        if ($override !== null) {
            return new Source(
                (string) file_get_contents($override),
                $name,
                $override,
            );
        }
        return $this->delegate->getSourceContext($name);
    }

    public function getCacheKey(string $name): string
    {
        $override = $this->resolveOverride($name);
        if ($override !== null) {
            // Include absolute path so Twig's cache differentiates between
            // identically-named templates resolved from different themes
            // across requests.
            return 'theme-aware:' . $override;
        }
        return $this->delegate->getCacheKey($name);
    }

    public function isFresh(string $name, int $time): bool
    {
        $override = $this->resolveOverride($name);
        if ($override !== null) {
            $mtime = @filemtime($override);
            return $mtime !== false ? $mtime < $time : false;
        }
        return $this->delegate->isFresh($name, $time);
    }

    public function exists(string $name): bool
    {
        if ($this->resolveOverride($name) !== null) {
            return true;
        }
        return $this->delegate->exists($name);
    }

    /**
     * Parse `@project-layouts-<module>/<relative>` and return the first
     * override absolute path from the active chain, or null if none exists.
     */
    private function resolveOverride(string $name): ?string
    {
        if ($name === '' || $name[0] !== '@') {
            return null;
        }
        if (! preg_match('#^@project-layouts-([^/]+)/(.+)$#', $name, $m)) {
            return null;
        }
        $module = $m[1];
        $relative = $m[2];

        $chain = ($this->chainResolver)();
        if ($chain === []) {
            return null;
        }

        $projectRoot = ProjectRoot::get();
        foreach ($chain as $themeId) {
            $candidate = $projectRoot . '/src/theme/' . $themeId . '/' . $module . '/templates/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
