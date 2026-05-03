<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;
use Swoole\Table;

#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterServerBindings->value,
    priority: 10,
    requiresContainer: false,
)]
final class InitializeDeferredRequestRegistryListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        $isomorphicConfig = IsomorphicConfig::fromEnvironment();
        if (!$isomorphicConfig->enabled || !class_exists(Table::class, false)) {
            return;
        }

        $deferredRequestTable = $context->bootstrapState?->get(SsrBootstrapStateKey::DEFERRED_REQUEST_TABLE);
        if ($deferredRequestTable instanceof Table && method_exists(DeferredRequestRegistry::class, 'setTable')) {
            DeferredRequestRegistry::setTable($deferredRequestTable);
        }

        DeferredRequestRegistry::initialize($isomorphicConfig);
    }
}
