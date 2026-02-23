<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Component;

use Semitexa\Ssr\Template\ModuleTemplateRegistry;

final class ComponentSlotRenderer
{
    public static function render(string $name, array $context = []): string
    {
        $component = $context['_component'] ?? null;
        $slots = $context['_slots'] ?? [];

        if ($component === null) {
            return '';
        }

        $slot = $slots[$name] ?? null;

        if ($slot === null) {
            return '';
        }

        if (is_callable($slot)) {
            return $slot($context);
        }

        if (is_array($slot)) {
            if ($name === 'default') {
                return $slot['content'] ?? '';
            }
            return ModuleTemplateRegistry::getTwig()->render(
                $slot['template'] ?? "components/slots/{$name}.html.twig",
                array_merge($context, $slot['props'] ?? [])
            );
        }

        return (string) $slot;
    }
}
