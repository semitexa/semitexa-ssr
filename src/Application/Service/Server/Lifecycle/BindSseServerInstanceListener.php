<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\SseServer;

/**
 * Pushes the container's {@see SseServer} singleton into the static facade
 * slot before anything else wires SSE collaborators.
 *
 * Priority -30 is load-bearing: {@see WireTrackRConsumerListener} (-20),
 * {@see WireCoreInstancesListener} (-10) and {@see WireSseServedPathsListener}
 * (0) all call facade setters in this same phase, and listeners run in
 * ascending priority order. If any of them ran first, its wiring would land on
 * the facade's process-local fallback instance and be lost when the container
 * singleton replaced it — the shadow-copy failure this binding exists to
 * prevent. Injectable consumers taking `SseServer` via `#[InjectAsReadonly]`
 * therefore see exactly the instance the facade delegates to.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterContainer->value,
    priority: -30,
)]
final class BindSseServerInstanceListener implements ServerLifecycleListenerInterface
{
    #[InjectAsReadonly]
    protected SseServer $sseServer;

    public function handle(ServerLifecycleContext $context): void
    {
        AsyncResourceSseServer::setInstance($this->sseServer);
    }
}
