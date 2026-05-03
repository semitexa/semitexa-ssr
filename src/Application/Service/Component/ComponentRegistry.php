<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

use Semitexa\Core\Attribute\AsEvent;
use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\Ssr\Application\Service\Asset\AssetEntry;
use Semitexa\Core\Discovery\ClassDiscovery;

final class ComponentRegistry
{
    /** @var array<string, array{class: string, name: string, template: ?string, layout: ?string, cacheable: bool, event: ?string, triggers: list<string>, script: ?string}> */
    private static array $components = [];
    private static bool $initialized = false;
    private static ?ClassDiscovery $classDiscovery = null;

    public static function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        self::$classDiscovery = $classDiscovery;
    }

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        if (self::$classDiscovery === null) {
            throw new \LogicException('ComponentRegistry requires ClassDiscovery instance. Call setClassDiscovery() first.');
        }

        $componentClasses = self::$classDiscovery->findClassesWithAttribute(AsComponent::class);

        foreach ($componentClasses as $class) {
            $reflection = new \ReflectionClass($class);
            $attrs = $reflection->getAttributes(AsComponent::class);

            if (empty($attrs)) {
                continue;
            }

            /** @var AsComponent $attr */
            $attr = $attrs[0]->newInstance();
            $triggers = ComponentEventBridge::normalizeTriggers($attr->triggers);

            if ($attr->event === null && $triggers !== []) {
                throw new \LogicException(sprintf(
                    'Component %s declares triggers without an event class.',
                    $class,
                ));
            }

            if ($attr->event !== null) {
                if (!class_exists($attr->event)) {
                    throw new \LogicException(sprintf(
                        'Component %s references missing event class %s.',
                        $class,
                        $attr->event,
                    ));
                }

                $eventReflection = new \ReflectionClass($attr->event);
                if ($eventReflection->getAttributes(AsEvent::class) === []) {
                    throw new \LogicException(sprintf(
                        'Component %s event %s must be marked with #[AsEvent].',
                        $class,
                        $attr->event,
                    ));
                }
            }

            if ($attr->script !== null) {
                $script = trim($attr->script);
                if ($script === '') {
                    throw new \LogicException(sprintf(
                        'Component %s declares an empty script asset key.',
                        $class,
                    ));
                }

                if (!AssetEntry::isValidKey($script)) {
                    throw new \LogicException(sprintf(
                        'Component %s declares invalid script asset key "%s".',
                        $class,
                        $script,
                    ));
                }
            }

            self::$components[$attr->name] = [
                'class' => $class,
                'name' => $attr->name,
                'template' => $attr->template,
                'layout' => $attr->layout,
                'cacheable' => $attr->event === null ? $attr->cacheable : false,
                'event' => $attr->event,
                'triggers' => $triggers,
                'script' => $attr->script !== null ? trim($attr->script) : null,
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

    /**
     * @param array{class: string, name: string, template: ?string, layout: ?string, cacheable: bool, event: ?string, triggers: list<string>, script: ?string} $component
     */
    public static function register(array $component): void
    {
        self::$components[$component['name']] = $component;
    }
}
