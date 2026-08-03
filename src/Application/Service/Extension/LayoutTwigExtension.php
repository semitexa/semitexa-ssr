<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Extension;

use Semitexa\Ssr\Application\Service\Component\ComponentEventBridge;
use Semitexa\Ssr\Application\Service\Component\ComponentRenderer;
use Semitexa\Ssr\Application\Service\Component\ComponentSlotRenderer;
use Semitexa\Ssr\Application\Service\Isomorphic\PlaceholderRenderer;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Application\Service\Layout\SlotAssetCollector;
use Semitexa\Ssr\Attribute\AsTwigExtension;
use Twig\Markup;

/**
 * Layout and component functions: filling a layout slot, deferring one to SSE,
 * rendering a component, and wiring a component's event triggers.
 *
 * Moved out of ModuleTemplateCatalog::registerFunctions() by
 * ep-slay-template-catalog. `layout_slot` and `layout_slot_deferred` shared their
 * whole rendering tail — deferral is a branch taken *before* that shared path,
 * not a second implementation of it, and it now reads that way.
 *
 * Every function here is `needs_context` except `component()`: slots resolve
 * against the page currently rendering, and the page handle lives in the Twig
 * context under `page_handle` (or `layout_handle` for a layout-level render).
 * Without a handle there is nothing to look a slot up against, so these return
 * an empty string rather than guessing — a page missing its handle is a bug
 * upstream, and inventing a fallback would hide it behind subtly wrong markup.
 */
#[AsTwigExtension]
final class LayoutTwigExtension
{
    private const CONTEXTUAL_HTML = ['needs_context' => true, 'is_safe' => ['html']];

    public function registerFunctions(): void
    {
        if (class_exists(LayoutSlotRegistry::class)) {
            TwigExtensionRegistry::registerFunction('layout_slot', [$this, 'layoutSlot'], self::CONTEXTUAL_HTML);
        }

        if (class_exists(PlaceholderRenderer::class)) {
            TwigExtensionRegistry::registerFunction('layout_slot_deferred', [$this, 'layoutSlotDeferred'], self::CONTEXTUAL_HTML);
        }

        TwigExtensionRegistry::registerFunction('component', [$this, 'component'], ['is_safe' => ['html']]);
        TwigExtensionRegistry::registerFunction('slot', [$this, 'componentSlot'], self::CONTEXTUAL_HTML);
        TwigExtensionRegistry::registerFunction('component_event_attrs', [$this, 'componentEventAttrs'], self::CONTEXTUAL_HTML);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extraContext
     */
    public function layoutSlot(array $context, string $slot, array $extraContext = []): Markup|string
    {
        return self::renderSlot($context, $slot, $extraContext);
    }

    /**
     * A slot that may be deferred: emit its placeholder now and let SSE deliver
     * the real content, or render inline when this slot is not deferred.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extraContext
     */
    public function layoutSlotDeferred(array $context, string $slot, array $extraContext = []): Markup|string
    {
        $deferred = self::findDeferredSlot($context, $slot);
        if ($deferred === null) {
            return self::renderSlot($context, $slot, $extraContext);
        }

        // The slot's own JS never renders server-side, so its module refs have to
        // be collected here — otherwise the placeholder arrives with no code able
        // to hydrate it once the SSE frame lands.
        $pageHandle = self::pageHandle($context);
        if ($pageHandle !== null) {
            SlotAssetCollector::collectModuleRefs(
                LayoutSlotRegistry::getDeferredClientModulesForSlot($pageHandle, $slot),
            );
        }

        return new Markup(PlaceholderRenderer::renderPlaceholder($deferred), 'UTF-8');
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $slots
     */
    public function component(string $name, array $props = [], array $slots = []): Markup
    {
        return new Markup(ComponentRenderer::render($name, $props, $slots), 'UTF-8');
    }

    /**
     * @param array<string, mixed> $context
     */
    public function componentSlot(array $context, string $name): Markup
    {
        return new Markup(ComponentSlotRenderer::render($name, $context), 'UTF-8');
    }

    /**
     * @param array<array-key, mixed> $context
     * @param array<array-key, mixed> $payload
     */
    public function componentEventAttrs(array $context, string $trigger, array $payload = []): Markup
    {
        return new Markup(
            ComponentEventBridge::renderTriggerAttributes($context, $trigger, $payload),
            'UTF-8',
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extraContext
     */
    private static function renderSlot(array $context, string $slot, array $extraContext): Markup|string
    {
        $pageHandle = self::pageHandle($context);
        if ($pageHandle === null) {
            return '';
        }

        return new Markup(
            LayoutSlotRegistry::render($pageHandle, $slot, $context, $extraContext, $context['layout_frame'] ?? null),
            'UTF-8',
        );
    }

    /**
     * The deferred definition for this slot, if the page deferred it.
     *
     * Slot ids are compared lower-cased: templates write them as authors please,
     * the registry stores them normalized.
     *
     * @param array<string, mixed> $context
     */
    private static function findDeferredSlot(array $context, string $slot): ?object
    {
        $deferredSlots = $context['__ssr_deferred_slots'] ?? [];
        if (!is_iterable($deferredSlots)) {
            return null;
        }

        foreach ($deferredSlots as $definition) {
            if (is_object($definition) && ($definition->slotId ?? null) === strtolower($slot)) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function pageHandle(array $context): ?string
    {
        $handle = $context['page_handle'] ?? $context['layout_handle'] ?? null;

        return is_string($handle) && $handle !== '' ? $handle : null;
    }
}
