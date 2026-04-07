<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Ssr\Asset\AssetCollector;
use Semitexa\Ssr\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;

#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterContainer->value,
    priority: 0,
    requiresContainer: true,
)]
final class BootAssetRegistryListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        $moduleRegistry = ContainerFactory::get()->get(ModuleRegistry::class);
        ModuleTemplateRegistry::setModuleRegistry($moduleRegistry);
        ModuleAssetRegistry::setModuleRegistry($moduleRegistry);
        AssetCollector::setModuleRegistry($moduleRegistry);
        ModuleAssetRegistry::initialize();
        AssetCollector::boot();
    }
}
