<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Ssr\Asset\AssetCollector;
use Semitexa\Ssr\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Component\ComponentRegistry;
use Semitexa\Ssr\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Routing\UrlGenerator;
use Semitexa\Ssr\Seo\AiSitemapJsonRenderer;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;

/**
 * Wires instance-based Core services (ClassDiscovery, ModuleRegistry, AttributeDiscovery)
 * into SSR static registries that still use a static API.
 *
 * Must run before any SSR registry initialization (priority -10 ensures this).
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterContainer->value,
    priority: -10,
    requiresContainer: true,
)]
final class WireCoreInstancesListener implements ServerLifecycleListenerInterface
{
    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly AttributeDiscovery $attributeDiscovery,
    ) {
    }

    public function handle(ServerLifecycleContext $context): void
    {
        ComponentRegistry::setClassDiscovery($this->classDiscovery);
        TwigExtensionRegistry::setClassDiscovery($this->classDiscovery);
        ModuleTemplateRegistry::setModuleRegistry($this->moduleRegistry);
        ModuleAssetRegistry::setModuleRegistry($this->moduleRegistry);
        AssetCollector::setModuleRegistry($this->moduleRegistry);
        AiSitemapJsonRenderer::setAttributeDiscovery($this->attributeDiscovery);
        UrlGenerator::setAttributeDiscovery($this->attributeDiscovery);
    }
}
