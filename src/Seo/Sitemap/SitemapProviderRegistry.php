<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Seo\Sitemap;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use ReflectionClass;

/**
 * Discovers all classes marked with #[AsSitemapProvider] and provides
 * an ordered list of provider class names for sitemap generation.
 */
#[AsService]
final class SitemapProviderRegistry
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

    /** @var list<array{class: class-string<SitemapUrlProviderInterface>, priority: int}>|null */
    private ?array $providers = null;

    /**
     * @return list<array{class: class-string<SitemapUrlProviderInterface>, priority: int}>
     */
    public function getProviders(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $this->providers = [];

        if (!isset($this->classDiscovery) || !isset($this->moduleRegistry)) {
            return $this->providers;
        }

        $this->classDiscovery->initialize();
        $this->moduleRegistry->initialize();

        $classes = $this->classDiscovery->findClassesWithAttribute(AsSitemapProvider::class);

        foreach ($classes as $className) {
            if (!$this->isEligible($className)) {
                continue;
            }

            try {
                /** @var class-string $className */
                $ref = new ReflectionClass($className);

                if (!$ref->implementsInterface(SitemapUrlProviderInterface::class)) {
                    continue;
                }

                $attrs = $ref->getAttributes(AsSitemapProvider::class);
                if ($attrs === []) {
                    continue;
                }

                /** @var AsSitemapProvider $attr */
                $attr = $attrs[0]->newInstance();

                /** @var class-string<SitemapUrlProviderInterface> $className */
                $this->providers[] = [
                    'class' => $className,
                    'priority' => $attr->priority,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        usort($this->providers, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $this->providers;
    }

    private function isEligible(string $className): bool
    {
        if (str_starts_with($className, 'Semitexa\\')) {
            if (!isset($this->moduleRegistry)) {
                return false;
            }

            return $this->moduleRegistry->isClassActive($className);
        }

        return str_starts_with($className, 'App\\');
    }
}
