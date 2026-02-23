namespace Semitexa\Ssr\Template;

use Semitexa\Core\Environment;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Util\ProjectRoot;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ModuleTemplateRegistry
{
    private static ?TwigEnvironment $twig = null;
    private static ?FilesystemLoader $loader = null;

    private static array $modulePaths = [];
    private static array $themePaths = [];
    private static bool $initialized = false;

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        self::discoverModulePaths();
        self::discoverThemePaths();
        self::buildTwigLoader();

        self::$initialized = true;
    }

    public static function getTwig(): TwigEnvironment
    {
        self::initialize();
        return self::$twig;
    }

    public static function getLoader(): FilesystemLoader
    {
        self::initialize();
        return self::$loader;
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
                    'path' => realpath($templatesDir) ?: $templatesDir,
                    'type' => 'standard',
                ];
                continue;
            }

            $layoutDir = $moduleDir . '/Layout';
            if (is_dir($layoutDir)) {
                self::$modulePaths[$module] = [
                    'path' => realpath($layoutDir) ?: $layoutDir,
                    'type' => 'legacy',
                ];
            }
        }

        $modules = ModuleRegistry::getModules();
        foreach ($modules as $module) {
            $templatePaths = $module['templatePaths'] ?? [];
            foreach ($templatePaths as $path) {
                if (is_dir($path)) {
                    self::$modulePaths[$module['name']] = [
                        'path' => $path,
                        'type' => 'package',
                    ];
                }
            }
        }
    }

    private static function discoverThemePaths(): void
    {
        $projectRoot = ProjectRoot::get();
        $themeRoot = $projectRoot . '/src/theme';
        $env = Environment::create();
        $activeTheme = $env->get('THEME', '');

        if (!is_dir($themeRoot)) {
            return;
        }

        if ($activeTheme !== '') {
            $themeDir = $themeRoot . '/' . $activeTheme;
            if (!is_dir($themeDir)) {
                return;
            }

            foreach (self::$modulePaths as $module => $config) {
                $moduleThemePath = $themeDir . '/' . $module;
                if (is_dir($moduleThemePath)) {
                    self::$themePaths[$module] = realpath($moduleThemePath) ?: $moduleThemePath;
                }
            }
        } else {
            foreach (glob($themeRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $module = basename($dir);
                if (isset(self::$modulePaths[$module])) {
                    self::$themePaths[$module] = realpath($dir) ?: $dir;
                }
            }
        }
    }

    private static function buildTwigLoader(): void
    {
        $loader = new FilesystemLoader();

        foreach (self::$themePaths as $module => $path) {
            $loader->addPath($path, self::aliasForModule($module));
        }

        foreach (self::$modulePaths as $module => $config) {
            if (!isset(self::$themePaths[$module])) {
                $loader->addPath($config['path'], self::aliasForModule($module));
            }
        }

        self::$loader = $loader;

        $cacheDir = self::getWritableCacheDir();
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        self::$twig = new TwigEnvironment($loader, [
            'cache' => $cacheDir,
            'auto_reload' => true,
            'strict_variables' => false,
            'autoescape' => 'html',
        ]);

        self::registerFunctions();
    }

    private static function aliasForModule(string $module): string
    {
        return 'project-layouts-' . $module;
    }

    private static function getWritableCacheDir(): string
    {
        $cacheDir = ProjectRoot::get() . '/var/cache/twig';

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            return $cacheDir;
        }

        $fallback = sys_get_temp_dir() . '/semitexa-twig-cache';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0755, true);
        }

        return $fallback;
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
                function (array $context, string $slot, array $extraContext = []): string {
                    $pageHandle = $context['page_handle'] ?? $context['layout_handle'] ?? null;
                    if ($pageHandle === null || $pageHandle === '') {
                        return '';
                    }
                    $layoutFrame = $context['layout_frame'] ?? null;
                    return \Semitexa\Ssr\Layout\LayoutSlotRegistry::render($pageHandle, $slot, $context, $extraContext, $layoutFrame);
                },
                ['needs_context' => true, 'is_safe' => ['html']]
            ));
        }

        // component() - new
        if (class_exists(\Semitexa\Ssr\Component\ComponentRenderer::class)) {
            self::$twig->addFunction(new TwigFunction(
                'component',
                function (string $name, array $props = [], array $slots = []) {
                    return \Semitexa\Ssr\Component\ComponentRenderer::render($name, $props, $slots);
                }
            ));

            // slot() - for component slots
            self::$twig->addFunction(new TwigFunction(
                'slot',
                function (string $name, array $context = []) {
                    return \Semitexa\Ssr\Component\ComponentSlotRenderer::render($name, $context);
                },
                ['needs_context' => true, 'is_safe' => ['html']]
            ));
        }

        // SEO functions - page_title, meta
        if (class_exists(\Semitexa\Ssr\Seo\SeoMeta::class)) {
            self::$twig->addFunction(new TwigFunction(
                'page_title',
                fn (?string $title = null) => \Semitexa\Ssr\Seo\SeoMeta::getTitle($title)
            ));

            self::$twig->addFunction(new TwigFunction(
                'meta',
                fn (string $name, ?string $content = null) => \Semitexa\Ssr\Seo\SeoMeta::tag($name, $content)
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

        // URL functions - url()
        if (class_exists(\Semitexa\Ssr\Routing\UrlGenerator::class)) {
            self::$twig->addFunction(new TwigFunction(
                'url',
                fn (string $route, array $params = []) => \Semitexa\Ssr\Routing\UrlGenerator::to($route, $params)
            ));

            self::$twig->addFunction(new TwigFunction(
                'current_url',
                fn (array $overrides = []) => \Semitexa\Ssr\Routing\UrlGenerator::current($overrides)
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
                fn () => \Semitexa\Ssr\Seo\SemanticRenderer::render()
            ));
        }
    }

    public static function reset(): void
    {
        self::$twig = null;
        self::$loader = null;
        self::$modulePaths = [];
        self::$themePaths = [];
        self::$initialized = false;
    }

    public static function getModulePaths(): array
    {
        self::initialize();
        return self::$modulePaths;
    }

    public static function getThemePaths(): array
    {
        self::initialize();
        return self::$themePaths;
    }

    public static function resolveLayout(string $handle): ?array
    {
        self::initialize();

        foreach (self::$themePaths as $module => $path) {
            $template = self::findTemplate($path, $handle);
            if ($template) {
                return [
                    'template' => $template,
                    'module' => $module,
                    'type' => 'theme',
                ];
            }
        }

        foreach (self::$modulePaths as $module => $config) {
            $template = self::findTemplate($config['path'], $handle);
            if ($template) {
                return [
                    'template' => $template,
                    'module' => $module,
                    'type' => 'module',
                ];
            }
        }

        return null;
    }

    private static function findTemplate(string $dir, string $handle): ?string
    {
        $direct = $dir . '/' . $handle . '.html.twig';
        if (is_file($direct)) {
            return '@' . basename($dir) . '/' . $handle . '.html.twig';
        }

        $layoutsDir = $dir . '/layouts';
        if (is_dir($layoutsDir)) {
            $directLayout = $layoutsDir . '/' . $handle . '.html.twig';
            if (is_file($directLayout)) {
                return '@' . basename($dir) . '/layouts/' . $handle . '.html.twig';
            }

            foreach (glob($layoutsDir . '/*/' . $handle . '.html.twig') as $file) {
                $relative = str_replace($dir . '/', '', $file);
                return '@' . basename($dir) . '/' . $relative;
            }
        }

        return null;
    }
}
