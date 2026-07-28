<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Layout;

use ReflectionClass;
use ReflectionException;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsFactory;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlSlotResponse;
use Semitexa\Ssr\Domain\Contract\TypedSlotHandlerInterface;

/**
 * Executes all registered slot handlers for a slot resource in priority order.
 *
 * A handler that fails does not stop the others: one broken region must not cost
 * the page every other region. But it is reported at **error** level, because a
 * slot that failed and a slot that legitimately had nothing to render produce
 * the identical empty output, and the only difference a reader can see is the
 * log line.
 */
final class SlotHandlerPipeline
{
    /**
     * Property attributes whose collaborators arrive AFTER construction.
     *
     * A handler declaring any of these cannot be built with `new`: the typed
     * properties stay uninitialised, so it either fatals on first access or
     * quietly returns nothing. This is the provable case, and the only one worth
     * refusing outright.
     */
    private const INJECTED_PROPERTY_ATTRIBUTES = [
        InjectAsReadonly::class,
        InjectAsMutable::class,
        InjectAsFactory::class,
    ];

    /**
     * Class attributes that mean "the container was meant to build this".
     *
     * Weaker evidence than an injected property: a service with a parameterless
     * constructor and nothing injected constructs perfectly well with `new`.
     * Refusing on this alone would break setups that work today, so an
     * unresolved one is reported and then built directly.
     */
    private const CONTAINER_BUILT_ATTRIBUTES = [
        AsService::class,
        ExecutionScoped::class,
    ];

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
                // Error, not debug. A failed slot renders as an empty region, so
                // at any normal log level this used to be indistinguishable from
                // a slot that had nothing to show — the failure was only findable
                // by abandoning the render path entirely.
                StaticLoggerBridge::error('ssr', 'Slot handler failed; region rendered empty', [
                    'handler' => $handlerClass,
                    'slot' => $slotClass,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $slot;
    }

    /**
     * Resolve a handler, preferring the container.
     *
     * Three outcomes, deliberately distinct:
     *  - the container knows the class → use it, and a failure there is a real
     *    failure, not a reason to try something weaker;
     *  - the container does not know it but the class declares container-managed
     *    dependencies → refuse, because `new` would hand back an object with
     *    uninitialised collaborators and the symptom would surface far away;
     *  - a plain class with nothing injected → `new` is legitimate.
     *
     * The previous version collapsed all three into "try the container, fall
     * through to `new` on any problem", which is what turned a missing binding
     * into an empty region with no explanation.
     */
    private static function resolveHandler(string $handlerClass): TypedSlotHandlerInterface
    {
        $container = null;
        $containerError = null;
        try {
            $container = ContainerFactory::get();
        } catch (\Throwable $e) {
            // No container at all (CLI, early boot). Plain classes still work.
            $containerError = $e;
        }

        if ($container !== null && $container->has($handlerClass)) {
            $instance = $container->get($handlerClass);
            if (!$instance instanceof TypedSlotHandlerInterface) {
                throw new \RuntimeException(
                    "Slot handler '{$handlerClass}' resolved from the container but does not implement "
                    . TypedSlotHandlerInterface::class . '.'
                );
            }

            return $instance;
        }

        $injected = self::injectedProperties($handlerClass);
        if ($injected !== []) {
            throw new \RuntimeException(
                "Slot handler '{$handlerClass}' declares injected propert"
                . (count($injected) === 1 ? 'y' : 'ies') . ' (' . implode(', ', $injected) . ') '
                . 'but the container did not resolve it'
                . ($containerError !== null ? ' (no container available: ' . $containerError->getMessage() . ')' : '')
                . '. Constructing it directly would leave those uninitialised, so the handler is refused '
                . 'rather than run half-built.'
            );
        }

        if (self::isContainerBuilt($handlerClass)) {
            // Not fatal: nothing is injected, so `new` produces a usable object.
            // Still worth saying, because a service the container does not know
            // is usually a missing binding rather than an intent.
            StaticLoggerBridge::warning('ssr', 'Slot handler is container-declared but was not resolved', [
                'handler' => $handlerClass,
                'note' => 'constructed directly; nothing is injected so this is safe, but the binding is likely missing',
            ]);
        }

        $instance = new $handlerClass();
        if (!$instance instanceof TypedSlotHandlerInterface) {
            throw new \RuntimeException(
                "Slot handler '{$handlerClass}' must implement TypedSlotHandlerInterface."
            );
        }

        return $instance;
    }

    /**
     * Names of properties the container was supposed to fill.
     *
     * A class we cannot reflect yields none, so an unloadable handler still
     * reaches `new` and fails with its own error rather than a misleading one
     * about dependency injection.
     *
     * @return list<string>
     */
    private static function injectedProperties(string $handlerClass): array
    {
        try {
            $reflection = new ReflectionClass($handlerClass);
        } catch (ReflectionException) {
            return [];
        }

        $names = [];
        foreach ($reflection->getProperties() as $property) {
            foreach (self::INJECTED_PROPERTY_ATTRIBUTES as $attribute) {
                if ($property->getAttributes($attribute) !== []) {
                    $names[] = '$' . $property->getName();
                    continue 2;
                }
            }
        }

        return $names;
    }

    private static function isContainerBuilt(string $handlerClass): bool
    {
        try {
            $reflection = new ReflectionClass($handlerClass);
        } catch (ReflectionException) {
            return false;
        }

        foreach (self::CONTAINER_BUILT_ATTRIBUTES as $attribute) {
            if ($reflection->getAttributes($attribute) !== []) {
                return true;
            }
        }

        return false;
    }
}
