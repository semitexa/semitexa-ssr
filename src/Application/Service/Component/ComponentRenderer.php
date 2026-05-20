<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\DataProviderRegistry;
use Semitexa\Ssr\Application\Service\Isomorphic\PlaceholderRenderer;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Semitexa\Ssr\Domain\Model\DataProviderContext;

final class ComponentRenderer
{
    private const CTX_RENDERED_SLOTS = '__ssr_rendered_slots';
    private const CTX_CURRENT_REQUEST = '__ssr_current_request';

    private static ?DataProviderRegistry $dataProviderRegistry = null;

    public static function setDataProviderRegistry(?DataProviderRegistry $registry): void
    {
        self::$dataProviderRegistry = $registry;
    }

    public static function setCurrentRequest(?object $request): void
    {
        CoroutineLocal::set(self::CTX_CURRENT_REQUEST, $request);
    }

    /**
     * @param array<array-key, mixed> $props
     * @param array<array-key, mixed> $slots
     */
    public static function render(string $name, array $props = [], array $slots = []): string
    {
        $component = ComponentRegistry::get($name);

        if ($component === null) {
            return "<!-- Component '{$name}' not found -->";
        }

        /** @var array{class: string, name: string, template: ?string, layout: ?string, cacheable: bool, event: ?string, triggers: list<string>, script: ?string, dataProviderClass: ?string, transportMode: TransportType, deferred: bool} $component */
        $currentSlots = CoroutineLocal::get(self::CTX_RENDERED_SLOTS, []);
        $previousSlots = $currentSlots;
        $currentSlots[$name] = $slots;
        CoroutineLocal::set(self::CTX_RENDERED_SLOTS, $currentSlots);

        try {
            $template = $component['template'] ?? "components/{$name}.html.twig";
            $manifest = null;
            $componentId = null;

            if (($component['event'] ?? null) !== null || ($component['script'] ?? null) !== null) {
                $componentId = 'cmp_' . bin2hex(random_bytes(8));
            }

            $transportMode = $component['transportMode'] ?? TransportType::Http;
            $deferred = $component['deferred'] ?? false;
            if ($deferred && $transportMode === TransportType::Sse) {
                return PlaceholderRenderer::renderComponentPlaceholder($name, $componentId);
            }

            $providerClass = $component['dataProviderClass'] ?? null;
            if ($providerClass !== null && self::$dataProviderRegistry !== null) {
                $provider = self::$dataProviderRegistry->resolveByClass($providerClass);
                if ($provider !== null) {
                    $providerData = $provider->resolveForComponent(
                        new DataProviderContext(
                            request: CoroutineLocal::get(self::CTX_CURRENT_REQUEST, null),
                            instanceId: $componentId,
                            subscriberId: null,
                        ),
                        $props,
                    );
                    // Provider data underlays — explicit props win.
                    $props = array_merge($providerData, $props);
                }
            }

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

            $html = self::processNestedComponents($html);

            if ($componentId !== null) {
                $html = ComponentEventBridge::annotateRoot($html, $component, $componentId, $manifest);
            }

            return $html;
        } finally {
            CoroutineLocal::set(self::CTX_RENDERED_SLOTS, $previousSlots);
        }
    }

    private static function processNestedComponents(string $html): string
    {
        return preg_replace_callback(
            '/\{\{\s*component\(\s*["\']([^"\']+)["\']\s*(?:,\s*(\{[^\}]*\}))?\s*\)\s*\}\}/',
            static function (array $matches): string {
                $name = $matches[1];
                $props = isset($matches[2]) ? json_decode($matches[2], true) : [];
                $props = is_array($props) ? $props : [];

                return self::render($name, $props, []);
            },
            $html
        );
    }

    public static function getSlot(string $componentName, string $slotName, array $default = []): array
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
