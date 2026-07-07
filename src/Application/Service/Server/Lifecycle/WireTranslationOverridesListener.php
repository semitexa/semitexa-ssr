<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
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

    public function handle(ServerLifecycleContext $context): void
    {
        if (isset($this->overrides)) {
            Translator::setOverrideProvider($this->overrides);
        }
    }
}
