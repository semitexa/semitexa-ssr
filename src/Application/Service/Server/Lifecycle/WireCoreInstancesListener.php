<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Ssr\Application\Service\Asset\AssetCollector;
use Semitexa\Ssr\Application\Service\Asset\AssetManifestRegistry;
use Semitexa\Ssr\Application\Service\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Application\Service\Asset\ModuleAssetResolver;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Component\ComponentHtmlRenderer;
use Semitexa\Ssr\Application\Service\Component\ComponentCatalog;
use Semitexa\Ssr\Application\Service\Component\ComponentRegistry;
use Semitexa\Ssr\Application\Service\Component\ComponentRenderer;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\Routing\RouteUrlBuilder;
use Semitexa\Ssr\Application\Service\Routing\UrlGenerator;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;

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
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    #[InjectAsReadonly]
    protected RouteUrlBuilder $routeUrlBuilder;

    #[InjectAsReadonly]
    protected DeferredBlockOrchestrator $deferredBlockOrchestrator;

    #[InjectAsReadonly]
    protected ComponentHtmlRenderer $componentHtmlRenderer;

    #[InjectAsReadonly]
    protected AssetManifestRegistry $assetManifestRegistry;

    #[InjectAsReadonly]
    protected ModuleAssetResolver $moduleAssetResolver;

    #[InjectAsReadonly]
    protected ComponentCatalog $componentCatalog;

    public function handle(ServerLifecycleContext $context): void
    {
        ComponentRegistry::setCatalog($this->componentCatalog);
        ComponentRenderer::setRenderer($this->componentHtmlRenderer);
        TwigExtensionRegistry::setClassDiscovery($this->classDiscovery);
        ModuleTemplateRegistry::setModuleRegistry($this->moduleRegistry);
        ModuleAssetRegistry::setResolver($this->moduleAssetResolver);
        AssetCollector::setManifestRegistry($this->assetManifestRegistry);
        UrlGenerator::setBuilder($this->routeUrlBuilder);
        AsyncResourceSseServer::setDeferredBlockOrchestrator($this->deferredBlockOrchestrator);
    }
}
