<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Layout;

use Semitexa\Core\Environment;
use Semitexa\Ssr\Http\Response\HtmlSlotResponse;

/**
 * Shared slot rendering service.
 *
 * Resolves slot resources for (handle, slotName), creates instances,
 * runs slot handlers, collects client modules, and renders templates.
 * Results are concatenated in priority order.
 */
final class SlotRenderer
{
    /**
     * Render a single slot resource registry entry that carries a resourceClass.
     *
     * @param array{resourceClass: string, template: string, context: array, priority: int, clientModules: array} $entry
     */
    public static function renderEntry(array $entry, array $context = []): string
    {
        $resourceClass = $entry['resourceClass'];

        try {
            $slot = SlotResourceFactory::create($resourceClass);
            if ($context !== []) {
                $slot = $slot->withRenderContext($context);
            }
            if (($entry['clientModules'] ?? []) !== []) {
                $slot = $slot->withClientModules($entry['clientModules']);
            }
            $slot = SlotHandlerPipeline::execute($slot);
            SlotAssetCollector::collectFromSlot($slot);
            $template = ($entry['template'] ?? '') !== '' ? $entry['template'] : null;

            return $slot->renderTemplate($template);
        } catch (\Throwable $e) {
            if (Environment::getEnvValue('APP_DEBUG') === '1') {
                error_log(
                    "[Semitexa] SlotRenderer::renderEntry failed for '{$resourceClass}': " . $e->getMessage()
                );
            }
            return '';
        }
    }
}
