<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Discovery;

use Semitexa\Core\Attribute\AsDiscoveryContributor;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\DiscoveryContributor;
use Semitexa\Core\Exception\ConfigurationException;
use Semitexa\Ssr\Application\Service\DataProviderRegistry;
use Semitexa\Ssr\Attribute\AsDataProvider;

/**
 * Registers `#[AsDataProvider]` classes into the {@see DataProviderRegistry}.
 */
#[AsDiscoveryContributor(priority: 200)]
final class DataProviderContributor implements DiscoveryContributor
{
    public function attribute(): string
    {
        return AsDataProvider::class;
    }

    public function scopedToActiveModules(): bool
    {
        return true;
    }

    public function contribute(string $className, object $attribute, BootDiagnostics $diagnostics): void
    {
        if (!$attribute instanceof AsDataProvider) {
            return;
        }

        if ($attribute->slot === '') {
            // Throwing here does not abort the boot: the discovery loop catches
            // per class and records a skip, which is exactly what the code this
            // replaced did (its throw sat inside the same kind of try/catch).
            // The value is the diagnostic — a provider with no slot can never
            // fire, and a named skip beats silent absence.
            throw new ConfigurationException("AsDataProvider on {$className} is missing slot.");
        }

        DataProviderRegistry::register(
            $attribute->slot,
            $className,
            array_values(array_filter(
                $attribute->handles,
                static fn (string $handle): bool => $handle !== '',
            )),
        );
    }
}
