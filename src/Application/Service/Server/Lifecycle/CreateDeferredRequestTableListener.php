<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;
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

        // Swoole\Table only: the extension genuinely may be absent, and this
        // listener runs on a server that may not be Swoole. DeferredRequestRegistry
        // ships in this package, so asking whether it and its method exist was
        // asking whether semitexa/ssr is installed correctly.
        if (!class_exists(Table::class, false)) {
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
