<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Layout;

/**
 * Boot-time registry of slot handler classes keyed by slot resource class.
 * Handler lists are sorted by priority at registration time.
 */
final class SlotHandlerRegistry
{
    /**
     * @worker-scoped Populated at boot by AttributeDiscovery, read-only during requests.
     * slot resource class => list of { handlerClass, priority }
     * @var array<string, list<array{handlerClass: string, priority: int}>>
     */
    private static array $handlers = [];

    public static function register(string $slotClass, string $handlerClass, int $priority = 0): void
    {
        if (!isset(self::$handlers[$slotClass])) {
            self::$handlers[$slotClass] = [];
        }
        self::$handlers[$slotClass][] = ['handlerClass' => $handlerClass, 'priority' => $priority];
        usort(
            self::$handlers[$slotClass],
            static fn (array $a, array $b) => $a['priority'] <=> $b['priority']
        );
    }

    /**
     * Returns handler class names ordered by priority (ascending).
     * @return list<string>
     */
    public static function getHandlerClasses(string $slotClass): array
    {
        return array_map(
            static fn (array $entry) => $entry['handlerClass'],
            self::$handlers[$slotClass] ?? []
        );
    }

    public static function reset(): void
    {
        self::$handlers = [];
    }
}
