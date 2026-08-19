<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Pipeline\RequestTracerInterface;
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
 * `tk-facades-retire-wiring` (2026-08-19): this is now the SINGLE facade-wiring
 * listener — every facade-slot push in the framework happens in the eight lines
 * below, including `AsyncResourceSseServer::setInstance()`. SSE *collaborator*
 * wiring no longer rides the facade at all: {@see WireTrackRConsumerListener}
 * (-20) and {@see WireSseServedPathsListener} (0) inject {@see \Semitexa\Ssr\Application\Service\Async\SseServer}
 * and mutate the container singleton directly, so their ordering relative to the
 * slot push stopped mattering — both talk to the same instance either way. The
 * only facade writers left outside this listener are the two deliberately
 * container-independent ones ({@see BindAsyncResourceSseServerListener},
 * {@see ReapStaleSubscriptionsListener} — `requiresContainer: false`), which run
 * in later phases where the slot already holds the container singleton, and in
 * containerless boots operate the facade's process-local fallback exactly as
 * before. `semitexa.staticFacadeAccess` (PHPStan) pins this shape.
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
    protected ContainerInterface $container;

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

    #[InjectAsReadonly]
    protected \Semitexa\Ssr\Application\Service\Async\SseServer $sseServer;

    public function handle(ServerLifecycleContext $context): void
    {
        ComponentRegistry::setCatalog($this->componentCatalog);
        ComponentRenderer::setRenderer($this->componentHtmlRenderer);
        TwigExtensionRegistry::setCatalog($this->twigExtensionCatalog);
        ModuleTemplateRegistry::setCatalog($this->moduleTemplateCatalog);
        ModuleAssetRegistry::setResolver($this->moduleAssetResolver);
        AssetCollector::setManifestRegistry($this->assetManifestRegistry);
        UrlGenerator::setBuilder($this->routeUrlBuilder);
        AsyncResourceSseServer::setInstance($this->sseServer);
        $this->sseServer->setDeferredBlockOrchestrator($this->deferredBlockOrchestrator);

        // Optional, and absent in production. Resolved through has()/get() rather
        // than injected as a property, because an injected optional service is a
        // container error when nothing provides it.
        /** @var RequestTracerInterface|null $tracer */
        $tracer = $this->container->has(RequestTracerInterface::class)
            ? $this->container->get(RequestTracerInterface::class)
            : null;
        $this->sseServer->setRequestTracer($tracer);
    }
}
