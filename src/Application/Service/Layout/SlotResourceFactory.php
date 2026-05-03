<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Layout;

use Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse;

/**
 * Creates fresh HtmlSlotResponse instances for rendering.
 */
final class SlotResourceFactory
{
    /**
     * Instantiate a slot resource class.
     * The AsSlotResource attribute metadata is initialized in the constructor via initFromAttribute().
     */
    public static function create(string $resourceClass): HtmlSlotResponse
    {
        if (!is_a($resourceClass, HtmlSlotResponse::class, true)) {
            throw new \InvalidArgumentException(
                "Slot resource class '{$resourceClass}' must extend HtmlSlotResponse."
            );
        }

        return new $resourceClass();
    }
}
