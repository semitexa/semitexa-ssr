<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

use Semitexa\Core\Attribute\AsEvent;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\Ssr\Attribute\WithDataProvider;
use Semitexa\Ssr\Attribute\WithTransport;
use Semitexa\Ssr\Application\Service\Asset\AssetEntry;
use Semitexa\Ssr\Domain\Contract\DataProviderInterface;
use Semitexa\Ssr\Domain\Exception\InvalidComponentConfigurationException;
use Semitexa\Core\Discovery\ClassDiscovery;

final class ComponentRegistry
{
    /** @var array<string, array{class: string, name: string, template: ?string, layout: ?string, cacheable: bool, event: ?string, triggers: list<string>, script: ?string, dataProviderClass: ?string, transportMode: TransportType, deferred: bool, providerProps: array<string, mixed>}> */
    private static array $components = [];
    private static bool $initialized = false;
    private static ?ClassDiscovery $classDiscovery = null;
    private static ?ComponentMetadataProviderRegistry $metadataProviders = null;

    public static function setClassDiscovery(ClassDiscovery $classDiscovery): void
    {
        self::$classDiscovery = $classDiscovery;
    }

    public static function setMetadataProviderRegistry(?ComponentMetadataProviderRegistry $registry): void
    {
        self::$metadataProviders = $registry;
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

            $dataProviderClass = null;
            $withDataProviderAttrs = $reflection->getAttributes(WithDataProvider::class);
            if ($withDataProviderAttrs !== []) {
                /** @var WithDataProvider $withDataProvider */
                $withDataProvider = $withDataProviderAttrs[0]->newInstance();
                if (!is_a($withDataProvider->providerClass, DataProviderInterface::class, true)) {
                    throw new InvalidComponentConfigurationException(sprintf(
                        "Component '%s': WithDataProvider class %s must implement %s",
                        $attr->name,
                        $withDataProvider->providerClass,
                        DataProviderInterface::class,
                    ));
                }
                $dataProviderClass = $withDataProvider->providerClass;
            }

            $transportMode = TransportType::Http;
            $deferred = false;
            $withTransportAttrs = $reflection->getAttributes(WithTransport::class);
            if ($withTransportAttrs !== []) {
                /** @var WithTransport $withTransport */
                $withTransport = $withTransportAttrs[0]->newInstance();
                if ($withTransport->deferred && $withTransport->mode !== TransportType::Sse) {
                    throw new InvalidComponentConfigurationException(sprintf(
                        "Component '%s': WithTransport deferred:true requires mode:TransportType::Sse, got %s",
                        $attr->name,
                        $withTransport->mode->name,
                    ));
                }
                $transportMode = $withTransport->mode;
                $deferred = $withTransport->deferred;
            }

            $providerProps = [];
            if (self::$metadataProviders !== null) {
                foreach (self::$metadataProviders->getProviders($reflection) as $provider) {
                    try {
                        $providerProps = array_merge(
                            $providerProps,
                            $provider->getProps($reflection),
                        );
                    } catch (\Throwable $e) {
                        throw new InvalidComponentConfigurationException(
                            sprintf(
                                "Component '%s': metadata provider %s failed: %s",
                                $attr->name,
                                $provider::class,
                                $e->getMessage(),
                            ),
                            previous: $e,
                        );
                    }
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
                'dataProviderClass' => $dataProviderClass,
                'transportMode' => $transportMode,
                'deferred' => $deferred,
                'providerProps' => $providerProps,
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
     * True when any registered component declares #[WithTransport(mode: Sse, deferred: true)].
     * Used by isomorphic gates that must allocate a deferred request even when the page
     * has no layout-level deferred slots, because Twig may still render a deferred-Sse
     * component placeholder during page render.
     */
    public static function hasDeferredSseComponent(): bool
    {
        self::initialize();
        foreach (self::$components as $component) {
            if (($component['deferred'] ?? false) && ($component['transportMode'] ?? null) === TransportType::Sse) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{class: string, name: string, template: ?string, layout: ?string, cacheable: bool, event: ?string, triggers: list<string>, script: ?string, dataProviderClass?: ?string, transportMode?: TransportType, deferred?: bool, providerProps?: array<string, mixed>} $component
     */
    public static function register(array $component): void
    {
        $component['dataProviderClass'] = $component['dataProviderClass'] ?? null;
        $component['transportMode'] = $component['transportMode'] ?? TransportType::Http;
        $component['deferred'] = $component['deferred'] ?? false;
        $component['providerProps'] = $component['providerProps'] ?? [];
        self::$components[$component['name']] = $component;
    }
}
