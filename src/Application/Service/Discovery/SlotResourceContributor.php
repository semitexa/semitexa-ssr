<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Discovery;

use Semitexa\Core\Attribute\AsDiscoveryContributor;
use Semitexa\Core\Config\EnvValueResolver;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\DiscoveryContributor;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Attribute\AsSlotResource;

/**
 * Registers `#[AsSlotResource]` classes as layout slots backed by a resource.
 *
 * Same registry as {@see LayoutSlotContributor}, but the slot's content comes
 * from a resource class rather than a data provider — hence the null
 * `dataProvider` and the extra resource/clientModules arguments.
 */
#[AsDiscoveryContributor(priority: 100)]
final class SlotResourceContributor implements DiscoveryContributor
{
    use AttributeContextMap;

    public function attribute(): string
    {
        return AsSlotResource::class;
    }

    public function scopedToActiveModules(): bool
    {
        return true;
    }

    public function contribute(string $className, object $attribute, BootDiagnostics $diagnostics): void
    {
        if (!$attribute instanceof AsSlotResource) {
            return;
        }

        /** @var string $template */
        $template = EnvValueResolver::resolve($attribute->template);

        LayoutSlotRegistry::register(
            handle: $attribute->handle,
            slot: $attribute->slot,
            template: $template,
            context: self::coerceStringMap(EnvValueResolver::resolve($attribute->context)),
            priority: $attribute->priority,
            deferred: $attribute->deferred,
            cacheTtl: $attribute->cacheTtl,
            dataProvider: null,
            skeletonTemplate: $attribute->skeletonTemplate,
            mode: $attribute->mode,
            refreshInterval: $attribute->refreshInterval,
            resourceClass: $className,
            clientModules: array_values(array_filter(
                $attribute->clientModules,
                static fn (string $module): bool => $module !== '',
            )),
        );
    }
}
