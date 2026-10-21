<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Locale\Domain\Contract\TranslationOverrideProviderInterface;
use Semitexa\Ssr\Application\Service\I18n\Translator;

/**
 * Registers the per-tenant translation override provider into the static
 * {@see Translator} facade at worker boot. Translator cannot reach the
 * container itself (staticContainerAccess rule), so this container-managed
 * listener injects the bound provider and hands it over — after which every
 * trans() checks the current tenant's overrides before the global catalog.
 *
 * Container-only: on a build without the DB-backed store bound, the contract
 * is absent, the property stays unset, and Translator keeps the global-catalog
 * behaviour.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterContainer->value,
    priority: 0,
    requiresContainer: true,
)]
final class WireTranslationOverridesListener implements ServerLifecycleListenerInterface
{
    #[InjectAsReadonly]
    protected TranslationOverrideProviderInterface $overrides;

    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    public function handle(ServerLifecycleContext $context): void
    {
        // Package locale packs: JsonFileLoader's modulesRoot scan covers only
        // src/modules/*, so installed packages' View/locales dirs are invisible
        // to the catalog without this.
        if (isset($this->moduleRegistry)) {
            $dirs = self::packageLocaleDirs($this->moduleRegistry->getModules());
            if ($dirs !== []) {
                Translator::setPackageLocaleDirs($dirs);
            }
        }

        if (isset($this->overrides)) {
            Translator::setOverrideProvider($this->overrides);
        }
    }

    /**
     * moduleName => locales dir for every registered module that ships one.
     *
     * Keyed by the module's SHORT alias (e.g. 'os', not 'semitexa-os') — the
     * same name asset URLs and trans() key prefixes use (`os.enter`). The
     * registry lists aliases longest-first; the shortest non-layout one wins,
     * falling back to the registry name.
     *
     * The dir is looked for beside each template path first and then at the
     * module's own canonical (and legacy) View/locales. The second lookup is
     * not redundant: a package with no Twig templates — semitexa/cms renders
     * its console as standalone HTML — has no template path at all, and
     * deriving locales only from templates left its catalog unregistered, so
     * every key resolved to the caller's English fallback in every language.
     *
     * @param iterable<array{name: string, path?: string, aliases: list<string>, templatePaths: list<string>}> $modules
     * @return array<string, string>
     */
    public static function packageLocaleDirs(iterable $modules): array
    {
        $dirs = [];
        foreach ($modules as $module) {
            $name = $module['name'];
            foreach ($module['aliases'] as $alias) {
                if ($alias !== '' && !str_starts_with($alias, 'project-layouts-')
                    && ($name === '' || \strlen($alias) < \strlen($name))
                ) {
                    $name = $alias;
                }
            }
            if ($name === '') {
                continue;
            }

            $candidates = [];
            foreach ($module['templatePaths'] as $templatesPath) {
                $candidates[] = \dirname($templatesPath) . '/locales';
            }
            $path = $module['path'] ?? '';
            if ($path !== '') {
                $candidates[] = $path . '/src/Application/View/locales';
                $candidates[] = $path . '/Application/View/locales';
            }

            foreach ($candidates as $localesDir) {
                if (is_dir($localesDir)) {
                    $dirs[$name] = $localesDir;
                    break;
                }
            }
        }

        return $dirs;
    }
}
