<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;

#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterServerBindings->value,
    priority: 0,
    requiresContainer: false,
)]
final class BindAsyncResourceSseServerListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        // Worker phases always carry a server; the console phase does not, and
        // this listener has nothing to bind without one.
        if ($context->server === null) {
            return;
        }

        AsyncResourceSseServer::setServer($context->server);

        $tables = $context->bootstrapState?->get(SsrBootstrapStateKey::ASYNC_RESOURCE_SSE_TABLES);
        if ($tables instanceof AsyncResourceSseTables) {
            AsyncResourceSseServer::setTables(
                $tables->sessionWorkerTable,
                $tables->deliverTable,
                $tables->pendingDeliverTable,
            );
        }
    }
}
