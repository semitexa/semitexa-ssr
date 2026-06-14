<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationPublisher;

/**
 * Track R · P3 — the STRUCTURAL synchrony pin, proven at worker boot.
 *
 * Asserts at the earliest worker-start phase that
 * {@see ResourceInvalidationPublisher} is declared `EventExecution::Sync`.
 * If a maintainer ever flips it to Async/Queued, the worker fails to boot
 * with a {@see \Semitexa\Core\Exception\ConfigurationException} — the
 * GATE-1 §T5 cross-tenant leak can never reach production silently. Pure
 * reflection, so it needs no container.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartBeforeContainer->value,
    priority: 0,
    requiresContainer: false,
)]
final class PinResourceInvalidationPublisherSyncListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        ResourceInvalidationPublisher::assertSyncExecutionPinned();
    }
}
