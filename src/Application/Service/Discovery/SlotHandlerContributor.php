<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Discovery;

use Semitexa\Core\Attribute\AsDiscoveryContributor;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\DiscoveryContributor;
use Semitexa\Ssr\Application\Service\Layout\SlotHandlerRegistry;
use Semitexa\Ssr\Attribute\AsSlotHandler;

/**
 * Registers `#[AsSlotHandler]` classes into the {@see SlotHandlerRegistry}.
 *
 * Lowest priority of the four: a handler attaches to a slot, so it runs after
 * every contributor that can declare one.
 */
#[AsDiscoveryContributor(priority: 50)]
final class SlotHandlerContributor implements DiscoveryContributor
{
    public function attribute(): string
    {
        return AsSlotHandler::class;
    }

    public function scopedToActiveModules(): bool
    {
        return true;
    }

    public function contribute(string $className, object $attribute, BootDiagnostics $diagnostics): void
    {
        if (!$attribute instanceof AsSlotHandler) {
            return;
        }

        SlotHandlerRegistry::register(
            slotClass: $attribute->slot,
            handlerClass: $className,
            priority: $attribute->priority,
        );
    }
}
