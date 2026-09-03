<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\TenantModuleScopeResolver;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextInterface;
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

    /**
     * The providers that speak for one site.
     *
     * A provider declared inside a tenant's module knows that tenant's content
     * and nothing else, so it must not contribute to another tenant's sitemap —
     * without this, the museum's 141 pages landed in the hair school's map too.
     * A provider outside any tenant-scoped module (the framework's own, or a
     * module every tenant runs) speaks for all of them.
     *
     * @return list<array{class: class-string<SitemapUrlProviderInterface>, priority: int}>
     */
    public function getProvidersForTenant(?TenantContextInterface $tenantContext): array
    {
        $tenantId = TenantContextAccess::tenantId($tenantContext);

        return array_values(array_filter(
            $this->getProviders(),
            function (array $provider) use ($tenantId): bool {
                $scopes = $this->tenantScopesOf($provider['class']);

                return $scopes === [] || ($tenantId !== null && in_array($tenantId, $scopes, true));
            },
        ));
    }

    /** @return list<string> tenant ids the provider's module belongs to; empty means all. */
    private function tenantScopesOf(string $className): array
    {
        if (!isset($this->moduleRegistry)) {
            return [];
        }

        return TenantModuleScopeResolver::scopesForModule(
            $this->moduleRegistry->getModuleNameForClass($className),
        );
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
