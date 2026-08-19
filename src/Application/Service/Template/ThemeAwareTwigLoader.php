<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Template;

use Semitexa\Core\Support\ProjectRoot;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Wraps a FilesystemLoader and intercepts `@<module>/*` lookups (the
 * canonical bare module alias) AND `@project-layouts-<module>/*` lookups
 * (the legacy back-compat form). For each lookup consults a chain-resolver
 * closure; walks the returned theme chain leaf-first, returning the first
 * override file that exists under
 * `src/theme/<theme>/<module>/templates/<relative>`. Falls back to the
 * wrapped loader (module default) when no override is found in any theme.
 *
 * Any namespace whose template-relative path or theme-override directory
 * is not present delegates straight through — the wrapped loader handles
 * the lookup unchanged.
 *
 * When the chain resolver returns a non-list or an empty array (no provider
 * bound, or provider returned empty), behavior matches the legacy wrapped
 * loader exactly — env-THEME-based overrides already registered at boot time
 * are the source of truth.
 */
final class ThemeAwareTwigLoader implements LoaderInterface
{
    /** @var \Closure(): list<string> */
    private readonly \Closure $chainResolver;

    /** @var \Closure(): ?\Semitexa\Core\Pipeline\RequestTracerInterface */
    private readonly \Closure $tracerResolver;

    public function __construct(
        private readonly FilesystemLoader $delegate,
        \Closure $chainResolver,
        ?\Closure $tracerResolver = null,
    ) {
        $this->chainResolver = $chainResolver;
        // Same late-binding shape as the chain resolver: the loader lives for
        // the worker, the tracer is per-environment and optional.
        $this->tracerResolver = $tracerResolver ?? static fn (): ?\Semitexa\Core\Pipeline\RequestTracerInterface => null;
    }

    public function getSourceContext(string $name): Source
    {
        $override = $this->resolveOverride($name);
        if ($override !== null) {
            $source = file_get_contents($override);
            if ($source === false) {
                throw new LoaderError(sprintf('Unable to read template override "%s".', $override));
            }

            $this->traceResolution($name, $override, true);

            return new Source(
                $source,
                $name,
                $override,
            );
        }

        $resolved = $this->delegate->getSourceContext($name);
        $this->traceResolution($name, $resolved->getPath(), false);

        return $resolved;
    }

    /**
     * Tell the dev tracer which FILE a logical template name compiled from and
     * whether a theme override intervened — the question override debugging
     * opens a trace to answer, and one the rendered page cannot.
     *
     * Compile-time only by nature: Twig consults the loader on cache miss, so a
     * warm worker shows no template marks. That is acceptable — the marks are
     * needed exactly when templates just changed, which is when they recompile.
     */
    private function traceResolution(string $name, string $file, bool $override): void
    {
        try {
            ($this->tracerResolver)()?->mark('template.resolve', [
                'template' => $name,
                'file' => $file,
                'override' => $override,
            ]);
        } catch (\Throwable) {
            // Rendering must never fail because observing it did.
        }
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
     * Parse `@<module>/<relative>` (canonical) or
     * `@project-layouts-<module>/<relative>` (legacy back-compat) and
     * return the first override absolute path from the active chain, or
     * null if none exists.
     */
    private function resolveOverride(string $name): ?string
    {
        if ($name === '' || $name[0] !== '@') {
            return null;
        }
        // Optional `project-layouts-` prefix so legacy `@project-layouts-X`
        // references keep working until they migrate to the canonical
        // bare-alias form.
        if (! preg_match('#^@(?:project-layouts-)?([^/]+)/(.+)$#', $name, $m)) {
            return null;
        }
        $module = $m[1];
        $relative = $m[2];

        if (str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, "\0")) {
            return null;
        }

        $chain = self::normalizeChain(($this->chainResolver)());
        if ($chain === []) {
            return null;
        }

        $projectRoot = ProjectRoot::get();
        foreach ($chain as $themeId) {
            $base = $projectRoot . '/src/theme/' . $themeId . '/' . $module . '/templates';
            $realBase = realpath($base);
            if ($realBase === false) {
                continue;
            }

            $candidate = $base . '/' . $relative;
            $realCandidate = realpath($candidate);
            if ($realCandidate !== false && str_starts_with($realCandidate, $realBase . '/') && is_file($realCandidate)) {
                return $realCandidate;
            }
        }
        return null;
    }

    /**
     * @param mixed $chain
     * @return list<string>
     */
    private static function normalizeChain(mixed $chain): array
    {
        return is_array($chain) ? array_values(array_filter($chain, 'is_string')) : [];
    }
}
