<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Layout;

use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Ssr\Domain\Contract\TypedSlotHandlerInterface;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse;
use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Executes all registered slot handlers for a slot resource in priority order.
 */
final class SlotHandlerPipeline
{
    /**
     * Execute the handler pipeline for the given slot resource.
     * Handlers are resolved from the DI container when available.
     */
    public static function execute(HtmlSlotResponse $slot): HtmlSlotResponse
    {
        $slotClass = $slot::class;
        $handlerClasses = SlotHandlerRegistry::getHandlerClasses($slotClass);

        foreach ($handlerClasses as $handlerClass) {
            try {
                $handler = self::resolveHandler($handlerClass);
                $result = $handler->handle($slot);
                if (!$result instanceof HtmlSlotResponse) {
                    throw new \RuntimeException(
                        "Slot handler '{$handlerClass}' must return an HtmlSlotResponse instance."
                    );
                }
                $slot = $result;
            } catch (\Throwable $e) {
                StaticLoggerBridge::debug('ssr', 'SlotHandlerPipeline error', [
                    'handler' => $handlerClass,
                    'slot' => $slotClass,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $slot;
    }

    private static function resolveHandler(string $handlerClass): TypedSlotHandlerInterface
    {
        try {
            $container = ContainerFactory::get();
            if ($container->has($handlerClass)) {
                $instance = $container->get($handlerClass);
                if ($instance instanceof TypedSlotHandlerInterface) {
                    return $instance;
                }
            }
        } catch (\Throwable) {
            // Fall through to direct instantiation
        }

        $instance = new $handlerClass();
        if (!$instance instanceof TypedSlotHandlerInterface) {
            throw new \RuntimeException(
                "Slot handler '{$handlerClass}' must implement TypedSlotHandlerInterface."
            );
        }

        return $instance;
    }
}
