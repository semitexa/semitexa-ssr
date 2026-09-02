<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Template;

use Semitexa\Core\Environment;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Psr\Container\ContainerInterface;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Pipeline\RequestTracerInterface;
use Semitexa\Core\Pipeline\SafeRequestTracer;
use Semitexa\Core\Support\ProjectRoot;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\TwigFunction;

/**
 * Registers Twig template paths per semitexa-module and wires theme-aware
 * overrides.
 *
 * Namespace contract (stable across the framework — do not rename without
 * coordinated migration; 200+ call sites in SSR/Theme/tooling/consumer modules):
 *
 *   `@project-layouts-<module-alias>/<relative-path>`
 *
 * where `<module-alias>` is the value declared in a package's
 * `composer.json → extra.semitexa-module.name`, or one of its registered
 * aliases. Examples:
 *   - `@project-layouts-theme-base/pages/error-page.html.twig`
 *     (theme-base is published by `semitexa/theme` — framework-canonical)
 *   - `@project-layouts-core-frontend/...`
 *     (core-frontend is semitexa/ssr's own alias)
 *
 * Project-local overrides: when an active theme is bound via the chain
 * resolver, `ThemeAwareTwigLoader` intercepts every lookup under this
 * namespace and first checks
 *   `<project-root>/src/theme/<active-theme>/<module-alias>/templates/<relative-path>`
 * before falling through to the module's own published file. This lets any
 * project override any module's templates per active theme without forking.
 */
#[AsService]
final class ModuleTemplateCatalog
{
    private ?TwigEnvironment $twig = null;
    private ?LoaderInterface $loader = null;

    /** @var array<string, array{aliases: list<string>, path: string, type: string}> */
    private array $modulePaths = [];
    private bool $initialized = false;
    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /**
     * Per-request active theme chain resolver (leaf-first). Null = legacy
     * boot-time env `THEME` behavior. When a package (typically
     * semitexa/theme) binds a closure, `ThemeAwareTwigLoader` wraps the
     * `FilesystemLoader` and walks the chain on every template lookup.
     *
     * @var \Closure(): list<string>|null
     */
    private ?\Closure $chainResolver = null;

    public function setChainResolver(?\Closure $resolver): void
    {
        $this->chainResolver = $resolver;
    }

    /**
     * Public accessor for the current active theme chain, used by other SSR
     * components (HtmlResponse auto-require) without introducing a separate
     * registry. Returns [] when no resolver is bound or it yields no chain.
     *
     * @return list<string>
     */
    public function getActiveChain(): array
    {
        if ($this->chainResolver === null) {
            return [];
        }
        return $this->normalizeChain(($this->chainResolver)());
    }

    public function setModuleRegistry(ModuleRegistry $moduleRegistry): void
    {
        $this->moduleRegistry = $moduleRegistry;
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->discoverModulePaths();
        $this->buildTwigLoader();

        $this->initialized = true;
    }

    public function getTwig(): TwigEnvironment
    {
        $this->initialize();
        if (!($this->twig instanceof TwigEnvironment)) {
            throw new \LogicException('ModuleTemplateCatalog Twig environment was not initialized.');
        }

        return $this->twig;
    }

    public function getLoader(): LoaderInterface
    {
        $this->initialize();
        if (!($this->loader instanceof LoaderInterface)) {
            throw new \LogicException('ModuleTemplateCatalog loader was not initialized.');
        }

        return $this->loader;
    }

    public function getCacheDir(): ?string
    {
        return $this->getWritableCacheDir();
    }

    private function discoverModulePaths(): void
    {
        $modulesRoot = ProjectRoot::get() . '/src/modules';

        if (!is_dir($modulesRoot)) {
            return;
        }

        foreach (glob($modulesRoot . '/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $module = basename($moduleDir);

            $templatesDir = $moduleDir . '/Application/View/templates';
            if (is_dir($templatesDir)) {
                $this->modulePaths[$module] = [
                    'aliases' => [$module],
                    'path' => realpath($templatesDir) ?: $templatesDir,
                    'type' => 'standard',
                ];
                continue;
            }

            $layoutDir = $moduleDir . '/Layout';
            if (is_dir($layoutDir)) {
                $this->modulePaths[$module] = [
                    'aliases' => [$module],
                    'path' => realpath($layoutDir) ?: $layoutDir,
                    'type' => 'legacy',
                ];
            }
        }

        if (!isset($this->moduleRegistry)) {
            throw new \LogicException('ModuleTemplateCatalog requires ModuleRegistry instance. Call setModuleRegistry() first.');
        }

        $modules = $this->moduleRegistry->getModules();
        foreach ($modules as $module) {
            $templatePaths = $module['templatePaths'];
            foreach ($templatePaths as $path) {
                if (is_dir($path)) {
                    $moduleName = $module['name'];
                    if ($moduleName === '') {
                        continue;
                    }

                    $this->modulePaths[$moduleName] = [
                        'aliases' => $this->aliasesForRegisteredModule($module),
                        'path' => $path,
                        'type' => 'package',
                    ];
                }
            }
        }
    }

    private function buildTwigLoader(): void
    {
        $loader = new FilesystemLoader();
        $namespaceOwners = [];

        // Register each module's template path under every alias it owns. Per-request
        // theme overrides go through ThemeAwareTwigLoader (below) — the theme.json
        // manifest system is the single authoritative override surface.
        foreach ($this->modulePaths as $module => $config) {
            if (!is_array($config)) {
                continue;
            }

            $aliases = $this->normalizeAliases(
                isset($config['aliases']) && is_array($config['aliases']) ? $config['aliases'] : [$module],
                $module,
            );

            foreach ($aliases as $alias) {
                // Canonical bare-alias namespace (e.g. `SsrPolygon` → `@SsrPolygon/...`).
                // Modules under `src/modules/*` should reference templates
                // via this form; the legacy `project-layouts-` prefix below
                // is only kept for back-compat with existing in-tree code
                // and tests until that migration is complete.
                $this->registerNamespaceOnce($loader, $alias, $config['path'], $namespaceOwners, $module);

                // Legacy back-compat: also register `project-layouts-<alias>`
                // unless the alias is already that form (so we never emit
                // `project-layouts-project-layouts-X`).
                if (!str_starts_with($alias, 'project-layouts-')) {
                    $this->registerNamespaceOnce(
                        $loader,
                        $this->aliasForModule($alias),
                        $config['path'],
                        $namespaceOwners,
                        $module,
                    );
                }
            }
        }

        // Per-request theme chain walking: always wrap the FilesystemLoader so
        // late-bound resolver changes are observed by the active Twig
        // environment. When no resolver is configured, return an empty chain
        // so lookups fall straight through to the module-owned template path.
        $effectiveLoader = new ThemeAwareTwigLoader(
            $loader,
            fn (): array => $this->chainResolver === null
                ? []
                : $this->normalizeChain(($this->chainResolver)()),
            // Optional dev tracer, resolved lazily per template compile — the
            // loader outlives requests, so binding an instance here would
            // freeze whatever was resolvable at catalog build time.
            fn (): ?RequestTracerInterface => SafeRequestTracer::wrap(
                $this->resolveOptionalTracer(),
            ),
        );
        $this->loader = $effectiveLoader;

        $cacheDir = $this->getWritableCacheDir();

        $this->twig = new TwigEnvironment($effectiveLoader, [
            'cache' => $cacheDir ?? false,
            'auto_reload' => true,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);

        $this->registerFunctions();

        try {
            $env = Environment::create();
            $this->twig->addGlobal('sse_port', $env->swooleSsePort);
        } catch (\Throwable $e) {
            $this->twig->addGlobal('sse_port', 9503);
        }
    }

    private function aliasForModule(string $module): string
    {
        return 'project-layouts-' . $module;
    }

    /**
     * Add a path to the FilesystemLoader under $namespace, but only if no
     * other module already owns that namespace (first-writer-wins, mirrors
     * the prior behavior of buildTwigLoader's owner check).
     *
     * @param array<string, string> $namespaceOwners namespace → owning module name; mutated in place
     */
    private function registerNamespaceOnce(
        FilesystemLoader $loader,
        string $namespace,
        string $path,
        array &$namespaceOwners,
        string $module,
    ): void {
        $existingOwner = $namespaceOwners[$namespace] ?? null;
        if (is_string($existingOwner) && $existingOwner !== $module) {
            return;
        }
        if ($existingOwner === $module) {
            // Already registered for this module — don't double-add.
            return;
        }
        $namespaceOwners[$namespace] = $module;
        $loader->addPath($path, $namespace);
    }

    /**
     * @param array{name?: mixed, aliases?: mixed} $module
     * @return list<string>
     */
    private function aliasesForRegisteredModule(array $module): array
    {
        $name = is_string($module['name'] ?? null) ? trim($module['name']) : '';
        $aliases = is_array($module['aliases'] ?? null) ? $module['aliases'] : [];

        return $this->normalizeAliases($aliases, $name);
    }

    /**
     * @param array<mixed> $aliases
     * @return list<string>
     */
    private function normalizeAliases(array $aliases, string $name): array
    {
        $normalized = [];

        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                continue;
            }

            $alias = trim($alias);
            if ($alias !== '') {
                $normalized[] = $alias;
            }
        }

        if ($name !== '') {
            $normalized[] = $name;

            if (!str_starts_with($name, 'semitexa-')) {
                $normalized[] = 'semitexa-' . $name;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function getWritableCacheDir(): ?string
    {
        $cacheDir = ProjectRoot::get() . '/var/cache/twig';

        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            $cacheDir = null;
        }

        if (is_string($cacheDir) && is_dir($cacheDir) && is_writable($cacheDir)) {
            return $cacheDir;
        }

        $fallback = sys_get_temp_dir() . '/semitexa-twig-cache';
        if (!is_dir($fallback) && !@mkdir($fallback, 0755, true) && !is_dir($fallback)) {
            return null;
        }

        if (is_dir($fallback) && is_writable($fallback)) {
            return $fallback;
        }

        return null;
    }

    private function registerFunctions(): void
    {
        if (!($this->twig instanceof TwigEnvironment)) {
            return;
        }

        // Custom Twig Extensions from modules
        if (class_exists(\Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry::class)) {
            \Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry::initialize();

            foreach (\Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry::getFunctions() as $name => $def) {
                $this->twig->addFunction(new TwigFunction($name, $def['callback'], $def['options']));
            }

            foreach (\Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry::getFilters() as $name => $callback) {
                $this->twig->addFilter(new \Twig\TwigFilter($name, $callback));
            }
        }

    }

    /**
     * Resolve a template name to its absolute file path.
     * Returns null if the template cannot be found.
     */
    public function getTemplatePath(string $templateName): ?string
    {
        $this->initialize();

        try {
            $source = $this->getLoader()->getSourceContext($templateName);
            $path = $source->getPath();
            return ($path !== '' && is_file($path)) ? $path : null;
        } catch (\Throwable) {
            // Template may not exist or loader may not be initialized — return null
            return null;
        }
    }

    public function reset(): void
    {
        $this->twig = null;
        $this->loader = null;
        $this->modulePaths = [];
        $this->initialized = false;
        $this->chainResolver = null;
    }

    /**
     * @return array<string, array{aliases: list<string>, path: string, type: string}>
     */
    public function getModulePaths(): array
    {
        $this->initialize();
        return $this->modulePaths;
    }

    /**
     * @param mixed $chain
     * @return list<string>
     */
    private function normalizeChain(mixed $chain): array
    {
        return is_array($chain) ? array_values(array_filter($chain, 'is_string')) : [];
    }

    public function resolveLayout(string $handle): ?array
    {
        $this->initialize();

        foreach ($this->modulePaths as $module => $config) {
            $relative = $this->findTemplateRelative($config['path'], $handle);
            if ($relative !== null) {
                return [
                    'template' => '@' . $this->aliasForModule($module) . '/' . $relative,
                    'module' => $module,
                    'type' => 'module',
                ];
            }
        }

        return null;
    }

    /**
     * Returns the relative path to the template file (e.g. "homepage.html.twig" or "layouts/one-column.html.twig")
     * so the caller can prefix it with the correct Twig namespace (@project-layouts-{Module}).
     */
    private function findTemplateRelative(string $dir, string $handle): ?string
    {
        $direct = $dir . '/' . $handle . '.html.twig';
        if (is_file($direct)) {
            return $handle . '.html.twig';
        }

        $layoutsDir = $dir . '/layouts';
        if (is_dir($layoutsDir)) {
            $directLayout = $layoutsDir . '/' . $handle . '.html.twig';
            if (is_file($directLayout)) {
                return 'layouts/' . $handle . '.html.twig';
            }

            foreach (glob($layoutsDir . '/*/' . $handle . '.html.twig') as $file) {
                return str_replace($dir . '/', '', $file);
            }
        }

        return null;
    }

    /**
     * The dev tracer when the container carries one, properly narrowed —
     * a PSR container's get() promises only `object`.
     */
    private function resolveOptionalTracer(): ?RequestTracerInterface
    {
        // Wrapped whole: get() can throw even after has() said true, and an
        // optional observer failing to RESOLVE must degrade to no observer —
        // never fail the template compile it wanted to watch.
        try {
            if (!$this->container->has(RequestTracerInterface::class)) {
                return null;
            }
            $resolved = $this->container->get(RequestTracerInterface::class);

            return $resolved instanceof RequestTracerInterface ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
