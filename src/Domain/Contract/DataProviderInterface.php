<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;

interface DataProviderInterface
{
    /**
     * Resolve prepared, display-ready data for a deferred block.
     *
     * All values MUST be ready for direct template output:
     * - Translations resolved to strings
     * - URLs fully generated
     * - Dates/numbers pre-formatted
     * - No raw entities or domain objects
     */
    public function resolve(DeferredSlotDefinition $slot, array $pageContext): array;
}
