<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Component;

use Semitexa\Ssr\Attributes\AsComponent;
use Semitexa\Core\Discovery\ClassDiscovery;

final class ComponentRegistry
{
    /** @var array<string, array{class: string, name: string, template: ?string, layout: ?string, cacheable: bool}> */
    private static array $components = [];
    private static bool $initialized = false;

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        $componentClasses = ClassDiscovery::findClassesWithAttribute(AsComponent::class);

        foreach ($componentClasses as $class) {
            $reflection = new \ReflectionClass($class);
            $attrs = $reflection->getAttributes(AsComponent::class);

            if (empty($attrs)) {
                continue;
            }

            /** @var AsComponent $attr */
            $attr = $attrs[0]->newInstance();

            self::$components[$attr->name] = [
                'class' => $class,
                'name' => $attr->name,
                'template' => $attr->template,
                'layout' => $attr->layout,
                'cacheable' => $attr->cacheable,
            ];
        }

        self::$initialized = true;
    }

    public static function get(string $name): ?array
    {
        self::initialize();
        return self::$components[$name] ?? null;
    }

    public static function all(): array
    {
        self::initialize();
        return self::$components;
    }

    public static function register(array $component): void
    {
        self::$components[$component['name']] = $component;
    }
}
