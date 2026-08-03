<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Discovery;

use Semitexa\Core\Attribute\AsDiscoveryContributor;
use Semitexa\Core\Config\EnvValueResolver;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\DiscoveryContributor;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Attribute\AsLayoutSlot;

/**
 * Registers `#[AsLayoutSlot]` declarations into the {@see LayoutSlotRegistry}.
 *
 * Runs first: slot handlers and slot resources both contribute against slots, so
 * the slots themselves have to exist before anything can attach to them.
 */
#[AsDiscoveryContributor(priority: 300)]
final class LayoutSlotContributor implements DiscoveryContributor
{
    use AttributeContextMap;

    public function attribute(): string
    {
        return AsLayoutSlot::class;
    }

    public function scopedToActiveModules(): bool
    {
        // Deliberately unscoped, preserving the behaviour this replaced: layout
        // slots are framework-level furniture and register regardless of which
        // modules a tenant has switched on.
        return false;
    }

    public function contribute(string $className, object $attribute, BootDiagnostics $diagnostics): void
    {
        if (!$attribute instanceof AsLayoutSlot) {
            return;
        }

        LayoutSlotRegistry::register(
            $attribute->handle,
            $attribute->slot,
            EnvValueResolver::resolve($attribute->template),
            self::coerceStringMap(EnvValueResolver::resolve($attribute->context)),
            $attribute->priority,
            $attribute->deferred,
            $attribute->cacheTtl,
            $attribute->dataProvider,
            $attribute->skeletonTemplate,
            $attribute->mode,
            $attribute->refreshInterval,
        );
    }
}
