<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Template;

use Semitexa\Core\Environment;
use Semitexa\Core\ModuleRegistry;
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
final class ModuleTemplateRegistry
{
    private static ?TwigEnvironment $twig = null;
    private static ?LoaderInterface $loader = null;

    private static array $modulePaths = [];
    private static bool $initialized = false;
    private static ?ModuleRegistry $moduleRegistry = null;

    /**
     * Per-request active theme chain resolver (leaf-first). Null = legacy
     * boot-time env `THEME` behavior. When a package (typically
     * semitexa/theme) binds a closure, `ThemeAwareTwigLoader` wraps the
     * `FilesystemLoader` and walks the chain on every template lookup.
     *
     * @var \Closure(): list<string>|null
     */
    private static ?\Closure $chainResolver = null;

    public static function setChainResolver(?\Closure $resolver): void
    {
        self::$chainResolver = $resolver;
    }

    /**
     * Public accessor for the current active theme chain, used by other SSR
     * components (HtmlResponse auto-require) without introducing a separate
     * registry. Returns [] when no resolver is bound or it yields no chain.
     *
     * @return list<string>
     */
    public static function getActiveChain(): array
    {
        if (self::$chainResolver === null) {
            return [];
        }
        return self::normalizeChain((self::$chainResolver)());
    }

    public static function setModuleRegistry(ModuleRegistry $moduleRegistry): void
    {
        self::$moduleRegistry = $moduleRegistry;
    }

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        self::discoverModulePaths();
        self::buildTwigLoader();

        self::$initialized = true;
    }

    public static function getTwig(): TwigEnvironment
    {
        self::initialize();
        return self::$twig;
    }

    public static function getLoader(): LoaderInterface
    {
        self::initialize();
        if (!(self::$loader instanceof LoaderInterface)) {
            throw new \LogicException('ModuleTemplateRegistry loader was not initialized.');
        }

        return self::$loader;
    }

    public static function getCacheDir(): ?string
    {
        return self::getWritableCacheDir();
    }

    private static function discoverModulePaths(): void
    {
        $modulesRoot = ProjectRoot::get() . '/src/modules';

        if (!is_dir($modulesRoot)) {
            return;
        }

        foreach (glob($modulesRoot . '/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $module = basename($moduleDir);

            $templatesDir = $moduleDir . '/Application/View/templates';
            if (is_dir($templatesDir)) {
                self::$modulePaths[$module] = [
                    'aliases' => [$module],
                    'path' => realpath($templatesDir) ?: $templatesDir,
                    'type' => 'standard',
                ];
                continue;
            }

            $layoutDir = $moduleDir . '/Layout';
            if (is_dir($layoutDir)) {
                self::$modulePaths[$module] = [
                    'aliases' => [$module],
                    'path' => realpath($layoutDir) ?: $layoutDir,
                    'type' => 'legacy',
                ];
            }
        }

        if (self::$moduleRegistry === null) {
            throw new \LogicException('ModuleTemplateRegistry requires ModuleRegistry instance. Call setModuleRegistry() first.');
        }

        $modules = self::$moduleRegistry->getModules();
        foreach ($modules as $module) {
            $templatePaths = $module['templatePaths'];
            foreach ($templatePaths as $path) {
                if (is_dir($path)) {
                    $moduleName = $module['name'];
                    if ($moduleName === '') {
                        continue;
                    }

                    self::$modulePaths[$moduleName] = [
                        'aliases' => self::aliasesForRegisteredModule($module),
                        'path' => $path,
                        'type' => 'package',
                    ];
                }
            }
        }
    }

    private static function buildTwigLoader(): void
    {
        $loader = new FilesystemLoader();
        $namespaceOwners = [];

        // Register each module's template path under every alias it owns. Per-request
        // theme overrides go through ThemeAwareTwigLoader (below) — the theme.json
        // manifest system is the single authoritative override surface.
        foreach (self::$modulePaths as $module => $config) {
            if (!is_array($config)) {
                continue;
            }

            $aliases = self::normalizeAliases(
                isset($config['aliases']) && is_array($config['aliases']) ? $config['aliases'] : [$module],
                $module,
            );

            foreach ($aliases as $alias) {
                $namespace = self::aliasForModule($alias);
                $owner = $namespaceOwners[$namespace] ?? null;
                if (is_string($owner) && $owner !== $module) {
                    continue;
                }

                $namespaceOwners[$namespace] = $module;
                $loader->addPath($config['path'], $namespace);
            }
        }

        // Per-request theme chain walking: always wrap the FilesystemLoader so
        // late-bound resolver changes are observed by the active Twig
        // environment. When no resolver is configured, return an empty chain
        // so lookups fall straight through to the module-owned template path.
        $effectiveLoader = new ThemeAwareTwigLoader(
            $loader,
            static fn (): array => self::$chainResolver === null
                ? []
                : self::normalizeChain((self::$chainResolver)()),
        );
        self::$loader = $effectiveLoader;

        $cacheDir = self::getWritableCacheDir();

        self::$twig = new TwigEnvironment($effectiveLoader, [
            'cache' => $cacheDir ?? false,
            'auto_reload' => true,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);

        self::registerFunctions();

        try {
            $env = Environment::create();
            self::$twig->addGlobal('sse_port', $env->swooleSsePort);
        } catch (\Throwable $e) {
            self::$twig->addGlobal('sse_port', 9503);
        }
    }

    private static function aliasForModule(string $module): string
    {
        return 'project-layouts-' . $module;
    }

    /**
     * @param array{name?: mixed, aliases?: mixed} $module
     * @return list<string>
     */
    private static function aliasesForRegisteredModule(array $module): array
    {
        $name = is_string($module['name'] ?? null) ? trim($module['name']) : '';
        $aliases = is_array($module['aliases'] ?? null) ? $module['aliases'] : [];

        return self::normalizeAliases($aliases, $name);
    }

    /**
     * @param array<mixed> $aliases
     * @return list<string>
     */
    private static function normalizeAliases(array $aliases, string $name): array
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

    private static function getWritableCacheDir(): ?string
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

    private static function registerFunctions(): void
    {
        if (!(self::$twig instanceof TwigEnvironment)) {
            return;
        }

        // layout_slot() - existing
        if (class_exists(\Semitexa\Ssr\Layout\LayoutSlotRegistry::class)) {
            self::$twig->addFunction(new TwigFunction(
                'layout_slot',
                function (array $context, string $slot, array $extraContext = []) {
                    /** @var array<string, mixed> $context */
                    /** @var array<string, mixed> $extraContext */
                    $pageHandle = $context['page_handle'] ?? $context['layout_handle'] ?? null;
                    if ($pageHandle === null || $pageHandle === '') {
                        return '';
                    }
                    $layoutFrame = $context['layout_frame'] ?? null;
                    $html = \Semitexa\Ssr\Layout\LayoutSlotRegistry::render($pageHandle, $slot, $context, $extraContext, $layoutFrame);
                    return new \Twig\Markup($html, 'UTF-8');
                },
                ['needs_context' => true, 'is_safe' => ['html']]
            ));
        }

        // layout_slot_deferred() - renders deferred placeholder or full content
        if (class_exists(\Semitexa\Ssr\Isomorphic\PlaceholderRenderer::class)) {
            self::$twig->addFunction(new TwigFunction(
                'layout_slot_deferred',
                function (array $context, string $slot, array $extraContext = []) {
                    /** @var array<string, mixed> $context */
                    /** @var array<string, mixed> $extraContext */
                    $deferredSlots = $context['__ssr_deferred_slots'] ?? [];
                    foreach ($deferredSlots as $slotDef) {
                        if ($slotDef->slotId === strtolower($slot)) {
                            $pageHandle = $context['page_handle'] ?? $context['layout_handle'] ?? null;
                            if ($pageHandle !== null && $pageHandle !== '') {
                                \Semitexa\Ssr\Layout\SlotAssetCollector::collectModuleRefs(
                                    \Semitexa\Ssr\Layout\LayoutSlotRegistry::getDeferredClientModulesForSlot(
                                        $pageHandle,
                                        $slot,
                                    )
                                );
                            }

                            return new \Twig\Markup(
                                \Semitexa\Ssr\Isomorphic\PlaceholderRenderer::renderPlaceholder($slotDef),
                                'UTF-8'
                            );
                        }
                    }
                    // Not deferred — render normally
                    $pageHandle = $context['page_handle'] ?? $context['layout_handle'] ?? null;
                    if ($pageHandle === null || $pageHandle === '') {
                        return '';
                    }
                    $layoutFrame = $context['layout_frame'] ?? null;
                    $html = \Semitexa\Ssr\Layout\LayoutSlotRegistry::render($pageHandle, $slot, $context, $extraContext, $layoutFrame);
                    return new \Twig\Markup($html, 'UTF-8');
                },
                ['needs_context' => true, 'is_safe' => ['html']]
            ));
        }

        // component() - new
        self::$twig->addFunction(new TwigFunction(
            'component',
            function (string $name, array $props = [], array $slots = []) {
                $html = \Semitexa\Ssr\Component\ComponentRenderer::render($name, $props, $slots);
                return new \Twig\Markup($html, 'UTF-8');
            },
            ['is_safe' => ['html']]
        ));

        // slot() - for component slots
        self::$twig->addFunction(new TwigFunction(
            'slot',
            function (array $context, string $name) {
                $html = \Semitexa\Ssr\Component\ComponentSlotRenderer::render($name, $context);
                return new \Twig\Markup($html, 'UTF-8');
            },
            ['needs_context' => true, 'is_safe' => ['html']]
        ));

        self::$twig->addFunction(new TwigFunction(
            'component_event_attrs',
            /**
             * @param array<array-key, mixed> $context
             * @param array<array-key, mixed> $payload
             */
            function (array $context, string $trigger, array $payload = []) {
                return new \Twig\Markup(
                    \Semitexa\Ssr\Component\ComponentEventBridge::renderTriggerAttributes($context, $trigger, $payload),
                    'UTF-8'
                );
            },
            ['needs_context' => true, 'is_safe' => ['html']]
        ));

        // SEO functions - page_title, meta
        if (class_exists(\Semitexa\Ssr\Seo\SeoMeta::class)) {
            self::$twig->addFunction(new TwigFunction(
                'page_title',
                function (?string $title = null) { return \Semitexa\Ssr\Seo\SeoMeta::getTitle($title); }
            ));

            self::$twig->addFunction(new TwigFunction(
                'meta',
                function (string $name, ?string $content = null) { return new \Twig\Markup(\Semitexa\Ssr\Seo\SeoMeta::tag($name, $content), 'UTF-8'); },
                ['is_safe' => ['html']]
            ));
        }

        // Custom Twig Extensions from modules
        if (class_exists(\Semitexa\Ssr\Extension\TwigExtensionRegistry::class)) {
            \Semitexa\Ssr\Extension\TwigExtensionRegistry::initialize();
            
            foreach (\Semitexa\Ssr\Extension\TwigExtensionRegistry::getFunctions() as $name => $def) {
                self::$twig->addFunction(new TwigFunction($name, $def['callback'], $def['options']));
            }

            foreach (\Semitexa\Ssr\Extension\TwigExtensionRegistry::getFilters() as $name => $callback) {
                self::$twig->addFilter(new \Twig\TwigFilter($name, $callback));
            }
        }

        // Asset functions - asset(), mix(), version()
        if (class_exists(\Semitexa\Ssr\Asset\AssetManager::class)) {
            self::$twig->addFunction(new TwigFunction(
                'asset',
                fn (string $path, ?string $module = null) => \Semitexa\Ssr\Asset\AssetManager::getUrl($path, $module)
            ));

            self::$twig->addFunction(new TwigFunction(
                'mix',
                fn (string $path) => \Semitexa\Ssr\Asset\AssetManager::mix($path)
            ));

            self::$twig->addFunction(new TwigFunction(
                'version',
                fn (string $path) => \Semitexa\Ssr\Asset\AssetManager::version($path)
            ));
        }

        // Unified asset management - asset_head(), asset_body(), asset_require()
        if (class_exists(\Semitexa\Ssr\Asset\AssetCollectorStore::class)) {
            self::$twig->addFunction(new TwigFunction(
                'asset_head',
                function () {
                    $collector = \Semitexa\Ssr\Asset\AssetCollectorStore::get();
                    return new \Twig\Markup(
                        \Semitexa\Ssr\Asset\AssetRenderer::renderHead($collector),
                        'UTF-8'
                    );
                },
                ['is_safe' => ['html']]
            ));

            self::$twig->addFunction(new TwigFunction(
                'asset_body',
                function () {
                    $collector = \Semitexa\Ssr\Asset\AssetCollectorStore::get();
                    return new \Twig\Markup(
                        \Semitexa\Ssr\Asset\AssetRenderer::renderBody($collector),
                        'UTF-8'
                    );
                },
                ['is_safe' => ['html']]
            ));

            self::$twig->addFunction(new TwigFunction(
                'asset_require',
                function (string $key) {
                    $collector = \Semitexa\Ssr\Asset\AssetCollectorStore::get();
                    $collector->require($key);
                    return '';
                },
                ['is_safe' => ['html']]
            ));
        }

        // URL functions - url()
        if (class_exists(\Semitexa\Ssr\Routing\UrlGenerator::class)) {
            self::$twig->addFunction(new TwigFunction(
                'url',
                fn (string $route, array $params = []) => \Semitexa\Ssr\Routing\UrlGenerator::to($route, $params)
            ));

            self::$twig->addFunction(new TwigFunction(
                'current_url',
                function (array $overrides = []) {
                    $ctx = \Semitexa\Core\Server\SwooleBootstrap::getCurrentSwooleRequestResponse();
                    $path = '/';
                    if ($ctx !== null) {
                        $request = $ctx[0];
                        $server = [];
                        foreach ((is_array($request->server) ? $request->server : []) as $key => $value) {
                            if (is_string($key) && (is_scalar($value) || $value === null)) {
                                $server[$key] = (string) $value;
                            }
                        }
                        $requestUri = $server['request_uri'] ?? '/';
                        $path = $requestUri !== '' ? $requestUri : '/';
                    }
                    if (!empty($overrides)) {
                        $query = http_build_query($overrides);
                        $basePath = parse_url($path, PHP_URL_PATH);
                        $path = (is_string($basePath) && $basePath !== '' ? $basePath : '/') . '?' . $query;
                    }
                    return $path;
                }
            ));

            self::$twig->addFunction(new TwigFunction(
                'current_absolute_url',
                function (array $overrides = []) {
                    $ctx = \Semitexa\Core\Server\SwooleBootstrap::getCurrentSwooleRequestResponse();
                    $path = '/';
                    if ($ctx !== null) {
                        $request = $ctx[0];
                        $server = [];
                        foreach ((is_array($request->server) ? $request->server : []) as $key => $value) {
                            if (is_string($key) && (is_scalar($value) || $value === null)) {
                                $server[$key] = (string) $value;
                            }
                        }
                        $requestUri = $server['request_uri'] ?? '/';
                        $path = $requestUri !== '' ? $requestUri : '/';
                    }
                    if (!empty($overrides)) {
                        $query = http_build_query($overrides);
                        $basePath = parse_url($path, PHP_URL_PATH);
                        $path = (is_string($basePath) && $basePath !== '' ? $basePath : '/') . '?' . $query;
                    }

                    $origin = '';
                    if ($ctx !== null) {
                        $request = $ctx[0];
                        $headers = [];
                        foreach ((is_array($request->header) ? $request->header : []) as $key => $value) {
                            if (is_string($key) && (is_scalar($value) || $value === null)) {
                                $headers[$key] = (string) $value;
                            }
                        }
                        $server = [];
                        foreach ((is_array($request->server) ? $request->server : []) as $key => $value) {
                            if (is_string($key) && (is_scalar($value) || $value === null)) {
                                $server[$key] = (string) $value;
                            }
                        }

                        $hostHeader = $headers['x-forwarded-host'] ?? $headers['host'] ?? '';
                        $hostParts = array_values(array_filter(
                            array_map(
                                static fn (string $value): string => trim($value),
                                explode(',', $hostHeader)
                            ),
                            static fn (string $value): bool => $value !== ''
                        ));
                        $host = $hostParts[0] ?? '';
                        if ($host !== '') {
                            $schemeHeader = $headers['x-forwarded-proto'] ?? '';
                            $schemeParts = array_values(array_filter(
                                array_map(
                                    static fn (string $value): string => trim($value),
                                    explode(',', $schemeHeader)
                                ),
                                static fn (string $value): bool => $value !== ''
                            ));
                            $scheme = $schemeParts[0] ?? '';
                            if ($scheme === '') {
                                $https = strtolower($server['https'] ?? '');
                                $scheme = ($https === 'on' || $https === '1') ? 'https' : '';
                            }
                            if ($scheme === '') {
                                $scheme = trim((string) (Environment::getEnvValue('APP_SCHEME') ?? 'http'));
                            }
                            $origin = sprintf('%s://%s', $scheme, $host);
                        }
                    }

                    if ($origin === '') {
                        $appUrl = trim((string) (Environment::getEnvValue('APP_URL') ?? ''));
                        if ($appUrl !== '') {
                            $origin = $appUrl;
                        } else {
                            $appHost = trim((string) (Environment::getEnvValue('APP_HOST') ?? ''));
                            if ($appHost !== '') {
                                $scheme = trim((string) (Environment::getEnvValue('APP_SCHEME') ?? 'http'));
                                $origin = sprintf('%s://%s', $scheme, $appHost);
                            }
                        }
                    }

                    return $origin !== '' ? rtrim($origin, '/') . $path : $path;
                }
            ));
        }

        // Locale URL functions - locale_url(), locale_switch_url()
        if (class_exists(\Semitexa\Locale\Context\LocaleContextStore::class)) {
            self::$twig->addFunction(new TwigFunction(
                'locale_url',
                function (string $path, ?string $locale = null): string {
                    if (!\Semitexa\Locale\Context\LocaleContextStore::isUrlPrefixEnabled()) {
                        return $path;
                    }
                    $locale ??= \Semitexa\Locale\Context\LocaleContextStore::getLocale();
                    $default = \Semitexa\Locale\Context\LocaleContextStore::getDefaultLocale();
                    if ($locale === $default) {
                        return $path;
                    }
                    return '/' . $locale . '/' . ltrim($path, '/');
                }
            ));

            $localeConfig = \Semitexa\Locale\LocaleConfig::fromEnvironment();
            self::$twig->addFunction(new TwigFunction(
                'locale_switch_url',
                function (string $targetLocale) use ($localeConfig): string {
                    $ctx = \Semitexa\Core\Server\SwooleBootstrap::getCurrentSwooleRequestResponse();
                    $path = '/';
                    if ($ctx !== null) {
                        $request = $ctx[0];
                        $server = [];
                        foreach ((is_array($request->server) ? $request->server : []) as $key => $value) {
                            if (is_string($key) && (is_scalar($value) || $value === null)) {
                                $server[$key] = (string) $value;
                            }
                        }
                        $requestUri = $server['request_uri'] ?? '/';
                        $path = $requestUri !== '' ? $requestUri : '/';
                    }

                    // Strip query string
                    $basePath = parse_url($path, PHP_URL_PATH);
                    $path = is_string($basePath) && $basePath !== '' ? $basePath : '/';

                    // Strip existing locale prefix if present
                    $trimmed = ltrim($path, '/');
                    $segments = explode('/', $trimmed, 2);
                    if (in_array($segments[0], $localeConfig->supportedLocales, true)) {
                        $path = '/' . ($segments[1] ?? '');
                    }

                    if (!\Semitexa\Locale\Context\LocaleContextStore::isUrlPrefixEnabled()) {
                        return $path;
                    }
                    $default = \Semitexa\Locale\Context\LocaleContextStore::getDefaultLocale();
                    if ($targetLocale === $default) {
                        return $path;
                    }
                    return '/' . $targetLocale . '/' . ltrim($path, '/');
                }
            ));
        }

        // Translation functions - trans()
        if (class_exists(\Semitexa\Ssr\I18n\Translator::class)) {
            self::$twig->addFunction(new TwigFunction(
                'trans',
                fn (string $key, array $params = []) => \Semitexa\Ssr\I18n\Translator::trans($key, $params)
            ));

            self::$twig->addFunction(new TwigFunction(
                'trans_choice',
                fn (string $key, int $count, array $params = []) => \Semitexa\Ssr\I18n\Translator::transChoice($key, $count, $params)
            ));

            self::$twig->addFunction(new TwigFunction(
                'locale',
                fn () => \Semitexa\Ssr\I18n\Translator::getLocale()
            ));
        }

        // Semantic/JSON-LD for AI agents
        if (class_exists(\Semitexa\Ssr\Seo\SemanticRenderer::class)) {
            self::$twig->addFunction(new TwigFunction(
                'semantic_head',
                function () { return new \Twig\Markup(\Semitexa\Ssr\Seo\SemanticRenderer::render(), 'UTF-8'); },
                ['is_safe' => ['html']]
            ));
        }
    }

    /**
     * Resolve a template name to its absolute file path.
     * Returns null if the template cannot be found.
     */
    public static function getTemplatePath(string $templateName): ?string
    {
        self::initialize();

        try {
            $source = self::getLoader()->getSourceContext($templateName);
            $path = $source->getPath();
            return ($path !== '' && is_file($path)) ? $path : null;
        } catch (\Throwable) {
            // Template may not exist or loader may not be initialized — return null
            return null;
        }
    }

    public static function reset(): void
    {
        self::$twig = null;
        self::$loader = null;
        self::$modulePaths = [];
        self::$initialized = false;
        self::$chainResolver = null;
    }

    public static function getModulePaths(): array
    {
        self::initialize();
        return self::$modulePaths;
    }

    /**
     * @param mixed $chain
     * @return list<string>
     */
    private static function normalizeChain(mixed $chain): array
    {
        return is_array($chain) ? array_values(array_filter($chain, 'is_string')) : [];
    }

    public static function resolveLayout(string $handle): ?array
    {
        self::initialize();

        foreach (self::$modulePaths as $module => $config) {
            $relative = self::findTemplateRelative($config['path'], $handle);
            if ($relative !== null) {
                return [
                    'template' => '@' . self::aliasForModule($module) . '/' . $relative,
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
    private static function findTemplateRelative(string $dir, string $handle): ?string
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
}
