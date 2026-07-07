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
        // to the catalog without this. Derived from each registered module's
        // template path (locales is its sibling), keyed by the module alias —
        // the same name trans() keys are prefixed with (e.g. `os.enter`).
        if (isset($this->moduleRegistry)) {
            $dirs = [];
            foreach ($this->moduleRegistry->getModules() as $module) {
                // Key by the module's SHORT alias (e.g. 'os', not 'semitexa-os') —
                // the same name asset URLs and trans() key prefixes use. The
                // registry lists aliases longest-first; pick the shortest
                // non-prefixed one, falling back to the registry name.
                $name = is_string($module['name'] ?? null) ? $module['name'] : '';
                $aliases = is_array($module['aliases'] ?? null) ? $module['aliases'] : [];
                foreach ($aliases as $alias) {
                    if (is_string($alias) && $alias !== '' && !str_starts_with($alias, 'project-layouts-')
                        && ($name === '' || \strlen($alias) < \strlen($name))
                    ) {
                        $name = $alias;
                    }
                }
                if ($name === '') {
                    continue;
                }
                foreach ($module['templatePaths'] ?? [] as $templatesPath) {
                    $localesDir = \dirname((string) $templatesPath) . '/locales';
                    if (is_dir($localesDir)) {
                        $dirs[$name] = $localesDir;
                        break;
                    }
                }
            }
            if ($dirs !== []) {
                Translator::setPackageLocaleDirs($dirs);
            }
        }

        if (isset($this->overrides)) {
            Translator::setOverrideProvider($this->overrides);
        }
    }
}
