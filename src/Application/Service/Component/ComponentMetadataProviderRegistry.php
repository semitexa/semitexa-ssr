<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Attribute\AsComponentMetadataProvider;
use Semitexa\Ssr\Domain\Contract\ComponentMetadataProviderInterface;
use Semitexa\Ssr\Domain\Exception\InvalidComponentConfigurationException;

#[AsService]
final class ComponentMetadataProviderRegistry
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /** @var list<array{class: class-string, priority: int}> */
    private array $providerMeta = [];

    private bool $built = false;

    /**
     * Return providers (ascending priority) whose supports($componentClass) is true.
     *
     * Raw throwables from container resolution or supports() are wrapped as
     * InvalidComponentConfigurationException so every boot-time provider
     * failure surfaces on the same configuration-error path.
     *
     * @param ReflectionClass<object> $componentClass
     * @return list<ComponentMetadataProviderInterface>
     */
    public function getProviders(ReflectionClass $componentClass): array
    {
        $this->ensureBuilt();

        $matched = [];
        foreach ($this->providerMeta as $meta) {
            try {
                $instance = $this->resolveInstance($meta['class']);
                if ($instance->supports($componentClass)) {
                    $matched[] = $instance;
                }
            } catch (InvalidComponentConfigurationException $e) {
                // resolveInstance raises this with a specific message
                // (e.g. "Resolved class X does not implement Y") — keep it.
                throw $e;
            } catch (\Throwable $e) {
                throw new InvalidComponentConfigurationException(
                    sprintf(
                        'Metadata provider %s failed for component %s: %s',
                        $meta['class'],
                        $componentClass->getName(),
                        $e->getMessage(),
                    ),
                    previous: $e,
                );
            }
        }
        return $matched;
    }

    public function ensureBuilt(): void
    {
        if ($this->built) {
            return;
        }
        $this->classDiscovery->initialize();
        $this->moduleRegistry->initialize();

        $classes = $this->classDiscovery->findClassesWithAttribute(AsComponentMetadataProvider::class);
        $filtered = array_filter(
            $classes,
            fn(string $class) => !str_starts_with($class, 'Semitexa\\')
                || $this->moduleRegistry->isClassActive($class),
        );

        foreach ($filtered as $className) {
            /** @var class-string $className */
            $ref = new ReflectionClass($className);
            $attrs = $ref->getAttributes(AsComponentMetadataProvider::class);
            if ($attrs === []) {
                continue;
            }

            if (!$ref->implementsInterface(ComponentMetadataProviderInterface::class)) {
                throw new InvalidComponentConfigurationException(sprintf(
                    'Class %s is marked #[AsComponentMetadataProvider] but does not implement %s.',
                    $className,
                    ComponentMetadataProviderInterface::class,
                ));
            }

            /** @var AsComponentMetadataProvider $attr */
            $attr = $attrs[0]->newInstance();
            $this->providerMeta[] = [
                'class' => $className,
                'priority' => $attr->priority,
            ];
        }

        usort(
            $this->providerMeta,
            static fn(array $a, array $b): int => $a['priority'] <=> $b['priority'],
        );
        $this->built = true;
    }

    /**
     * @param class-string $className
     */
    private function resolveInstance(string $className): ComponentMetadataProviderInterface
    {
        if (isset($this->container) && $this->container->has($className)) {
            /** @var object $instance */
            $instance = $this->container->get($className);
        } else {
            /** @var object $instance */
            $instance = new $className();
        }

        if (!$instance instanceof ComponentMetadataProviderInterface) {
            throw new InvalidComponentConfigurationException(sprintf(
                'Resolved class %s does not implement %s.',
                $className,
                ComponentMetadataProviderInterface::class,
            ));
        }

        return $instance;
    }
}
