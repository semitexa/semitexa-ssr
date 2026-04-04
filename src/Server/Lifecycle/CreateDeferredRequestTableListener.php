<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Isomorphic\DeferredRequestRegistry;
use Swoole\Table;

#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::PreStart->value,
    priority: 0,
    requiresContainer: false,
)]
final class CreateDeferredRequestTableListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        if ($context->bootstrapState === null) {
            return;
        }

        if (!class_exists(Table::class, false)
            || !class_exists(DeferredRequestRegistry::class)
            || !method_exists(DeferredRequestRegistry::class, 'createSharedTable')) {
            return;
        }

        $isomorphicConfig = IsomorphicConfig::fromEnvironment();
        if (!$isomorphicConfig->enabled) {
            return;
        }

        $context->bootstrapState->set(
            SsrBootstrapStateKey::DEFERRED_REQUEST_TABLE,
            DeferredRequestRegistry::createSharedTable($isomorphicConfig),
        );
    }
}
