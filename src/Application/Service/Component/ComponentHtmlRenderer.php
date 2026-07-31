<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\DataProviderRegistry;
use Semitexa\Ssr\Application\Service\Isomorphic\PlaceholderRenderer;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Domain\Model\DataProviderContext;

/**
 * Renders a registered component to HTML.
 *
 * Container-managed callers inject this service directly. {@see ComponentRenderer}
 * is the static entry point retained while ModuleTemplateRegistry still registers
 * the Twig `component()` bridge from a static context; it delegates here.
 *
 * The registry is nullable on purpose. DataProviderRegistry::resolveByClass()
 * instantiates the provider class directly rather than consulting a map, so an
 * empty registry is not equivalent to no registry: a null registry disables
 * provider resolution entirely, which is the behaviour unit tests rely on.
 */
#[AsService]
final class ComponentHtmlRenderer
{
    private const CTX_RENDERED_SLOTS = '__ssr_rendered_slots';
    private const CTX_CURRENT_REQUEST = '__ssr_current_request';

    #[InjectAsReadonly]
    protected ?DataProviderRegistry $dataProviderRegistry = null;

    public function setDataProviderRegistry(?DataProviderRegistry $registry): void
    {
        $this->dataProviderRegistry = $registry;
    }

    public function setCurrentRequest(?object $request): void
    {
        CoroutineLocal::set(self::CTX_CURRENT_REQUEST, $request);
    }

    /**
     * @param array<array-key, mixed> $props
     * @param array<array-key, mixed> $slots
     * @param bool $forceImmediateRender Skip the #[WithTransport(Sse, deferred:true)] short-circuit
     *                                   and render synchronously. Used by DeferredBlockOrchestrator
     *                                   when resolving a previously-deferred instance for SSE delivery.
     */
    public function render(
        string $name,
        array $props = [],
        array $slots = [],
        bool $forceImmediateRender = false,
    ): string {
        $component = ComponentRegistry::get($name);

        if ($component === null) {
            return "<!-- Component '{$name}' not found -->";
        }

        /** @var array{class: string, name: string, template: ?string, layout: ?string, cacheable: bool, event: ?string, triggers: list<string>, script: ?string, dataProviderClass: ?string, transportMode: TransportType, deferred: bool, providerProps: array<string, mixed>} $component */
        $currentSlots = CoroutineLocal::get(self::CTX_RENDERED_SLOTS, []);
        $previousSlots = $currentSlots;
        $currentSlots[$name] = $slots;
        CoroutineLocal::set(self::CTX_RENDERED_SLOTS, $currentSlots);

        try {
            $template = $component['template'] ?? "components/{$name}.html.twig";
            $manifest = null;
            $componentId = null;

            $transportMode = $component['transportMode'] ?? TransportType::Http;
            $deferred = $component['deferred'] ?? false;
            $deferringNow = $deferred && $transportMode === TransportType::Sse && !$forceImmediateRender;

            if (
                ($component['event'] ?? null) !== null
                || ($component['script'] ?? null) !== null
                || $deferringNow
            ) {
                $componentId = 'cmp_' . bin2hex(random_bytes(8));
            }

            if ($deferringNow) {
                ComponentInstanceStore::record($componentId ?? '', $name, $props);
                return PlaceholderRenderer::renderComponentPlaceholder($name, $componentId);
            }

            // Merge layering (bottom → top, later wins):
            //   1. providerProps    — structural metadata captured at boot
            //   2. DataProvider data — runtime data resolved per request
            //   3. explicit caller props
            $metaProps = $component['providerProps'];
            $explicitProps = $props;

            $providerData = [];
            $providerClass = $component['dataProviderClass'] ?? null;
            if ($providerClass !== null && $this->dataProviderRegistry !== null) {
                $provider = $this->dataProviderRegistry->resolveByClass($providerClass);
                if ($provider !== null) {
                    $providerData = $provider->resolve(
                        new DataProviderContext(
                            request: CoroutineLocal::get(self::CTX_CURRENT_REQUEST, null),
                            instanceId: $componentId,
                        ),
                        $explicitProps,
                    );
                }
            }

            $props = array_merge($metaProps, $providerData, $explicitProps);

            if (($component['event'] ?? null) !== null) {
                $manifest = ComponentEventBridge::buildManifest($component, $componentId);
            }

            $collector = AssetCollectorStore::get();

            if (($component['event'] ?? null) !== null) {
                $collector->require('ssr:js:component-events');
            }

            if (($component['script'] ?? null) !== null) {
                $collector->require('ssr:js:component-runtime');
                $collector->require($component['script']);
            }

            $context = array_merge($props, [
                '_component' => $component,
                '_component_event_manifest' => $manifest,
                '_component_id' => $componentId,
                '_slots' => $slots,
            ]);

            $html = ModuleTemplateRegistry::getTwig()->render($template, $context);

            $html = $this->processNestedComponents($html);

            if ($componentId !== null) {
                $html = ComponentEventBridge::annotateRoot($html, $component, $componentId, $manifest);
            }

            return $html;
        } finally {
            CoroutineLocal::set(self::CTX_RENDERED_SLOTS, $previousSlots);
        }
    }

    private function processNestedComponents(string $html): string
    {
        return preg_replace_callback(
            '/\{\{\s*component\(\s*["\']([^"\']+)["\']\s*(?:,\s*(\{[^\}]*\}))?\s*\)\s*\}\}/',
            function (array $matches): string {
                $name = $matches[1];
                $props = isset($matches[2]) ? json_decode($matches[2], true) : [];
                $props = is_array($props) ? $props : [];

                return $this->render($name, $props, []);
            },
            $html
        ) ?? $html;
    }

    /**
     * @param array<array-key, mixed> $default
     * @return array<array-key, mixed>
     */
    public function getSlot(string $componentName, string $slotName, array $default = []): array
    {
        $slots = CoroutineLocal::get(self::CTX_RENDERED_SLOTS, []);
        if (!is_array($slots)) {
            return $default;
        }

        $componentSlots = $slots[$componentName] ?? null;
        if (!is_array($componentSlots)) {
            return $default;
        }

        $slot = $componentSlots[$slotName] ?? null;
        return is_array($slot) ? $slot : $default;
    }
}
