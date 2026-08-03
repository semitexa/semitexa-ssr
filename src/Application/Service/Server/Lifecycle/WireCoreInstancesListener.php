<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
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
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionCatalog;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\Routing\RouteUrlBuilder;
use Semitexa\Ssr\Application\Service\Routing\UrlGenerator;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateCatalog;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;

/**
 * Wires instance-based Core services (ClassDiscovery, ModuleRegistry, AttributeDiscovery)
 * into SSR static registries that still use a static API.
 *
 * Must run before any SSR registry initialization (priority -10 ensures this).
 *
 * ## Ordering contract
 *
 * Every facade wired below resolves its collaborator with a `??=` fallback that
 * constructs an *unwired* instance on first use. The setters here replace that
 * instance wholesale, so any state written to a fallback before this listener runs
 * would be silently discarded. The facades are therefore only safe because nothing
 * touches them earlier in boot — a property this listener owns, and which anything
 * adding a new boot-time writer must preserve.
 *
 * Verified 2026-08-03 for the eight facades below:
 *
 * - Listeners run phase-by-phase in enum order, and ascending `priority` within a
 *   phase ({@see \Semitexa\Core\Server\Lifecycle\ServerLifecycleRegistry}). At
 *   `WorkerStartAfterContainer` / `-10` this is the first listener in worker boot
 *   that touches any of them.
 * - No listener at the two earlier phases (`PreStart`,
 *   `WorkerStartBeforeContainer`) references any of the eight.
 * - The known writers all land later: `BootProjectSkinsAssetAliasListener`
 *   (semitexa-theme) registers the `skins` alias at `WorkerStartFinalize`;
 *   `BootPlatformUiRegistryListener` runs at this phase with priority `-5`;
 *   `TwigExtensionRegistry::registerFunction()` is only ever reached from an
 *   extension's own `registerFunctions()`, which `TwigExtensionCatalog::initialize()`
 *   drives after wiring; `AssetCollector`/`DeferredTemplateRegistry` writes are
 *   request-time.
 *
 * Where a fallback could still outlive boot, the class defends itself rather than
 * relying on this ordering: {@see \Semitexa\Ssr\Application\Service\Asset\AssetCollector}
 * resolves its manifest registry per read, because its non-coroutine fallback
 * collector is process-lived.
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
    protected TwigExtensionCatalog $twigExtensionCatalog;

    #[InjectAsReadonly]
    protected ModuleTemplateCatalog $moduleTemplateCatalog;

    #[InjectAsReadonly]
    protected ComponentCatalog $componentCatalog;

    public function handle(ServerLifecycleContext $context): void
    {
        ComponentRegistry::setCatalog($this->componentCatalog);
        ComponentRenderer::setRenderer($this->componentHtmlRenderer);
        TwigExtensionRegistry::setCatalog($this->twigExtensionCatalog);
        ModuleTemplateRegistry::setCatalog($this->moduleTemplateCatalog);
        ModuleAssetRegistry::setResolver($this->moduleAssetResolver);
        AssetCollector::setManifestRegistry($this->assetManifestRegistry);
        UrlGenerator::setBuilder($this->routeUrlBuilder);
        AsyncResourceSseServer::setDeferredBlockOrchestrator($this->deferredBlockOrchestrator);
    }
}
