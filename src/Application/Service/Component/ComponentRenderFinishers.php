<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

/**
 * The finishers packages register at worker boot, applied by the component
 * renderer to every component's rendered HTML, in registration order.
 */
final class ComponentRenderFinishers
{
    /** @var list<ComponentRenderFinisherInterface> */
    private static array $finishers = [];

    public static function register(ComponentRenderFinisherInterface $finisher): void
    {
        foreach (self::$finishers as $known) {
            if ($known::class === $finisher::class) {
                return; // a re-run boot listener must not stack copies
            }
        }
        self::$finishers[] = $finisher;
    }

    public static function reset(): void
    {
        self::$finishers = [];
    }

    /** @param array<array-key, mixed> $props */
    public static function apply(string $componentName, string $componentClass, string $instanceId, array $props, string $html): string
    {
        foreach (self::$finishers as $finisher) {
            $html = $finisher->finish($componentName, $componentClass, $instanceId, $props, $html);
        }

        return $html;
    }
}
