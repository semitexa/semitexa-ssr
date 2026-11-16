<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredTemplateRegistry;
use Semitexa\Ssr\Domain\Exception\DeferredRenderingException;

/**
 * Publish the deferred templates once the worker is up.
 *
 * A template the client cannot render is refused in dev, and the refusal stays
 * here instead of leaving: a listener that throws in this phase stops the worker
 * from starting, so one slot template took the whole dev server down, every
 * page with it, in a restart loop. Reported 2026-11-16 by an agent whose
 * `mode: 'template'` slot used `{% import %}`.
 *
 * The refusal is not lost. The registry remembers it, and every request that
 * reaches a deferred slot calls initialize() again and fails with the same
 * message until the template's content changes, so the page that needs the
 * slot still says what is wrong while the rest of the application runs. The log
 * says it once at boot too. Anything else that goes wrong while publishing still
 * stops the worker, as before.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartFinalize->value,
    priority: 0,
    requiresContainer: false,
)]
final class InitializeDeferredTemplateRegistryListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        try {
            DeferredTemplateRegistry::initialize();
        } catch (DeferredRenderingException $e) {
            StaticLoggerBridge::error('ssr', 'A deferred template was refused at boot; the pages that defer to it fail until it is fixed', [
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
