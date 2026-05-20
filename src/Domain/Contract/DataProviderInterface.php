<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

use Semitexa\Ssr\Domain\Model\DataProviderContext;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;

interface DataProviderInterface
{
    /**
     * Resolve prepared, display-ready data for a deferred block (slot-based).
     *
     * All values MUST be ready for direct template output:
     * - Translations resolved to strings
     * - URLs fully generated
     * - Dates/numbers pre-formatted
     * - No raw entities or domain objects
     */
    public function resolve(DeferredSlotDefinition $slot, array $pageContext): array;

    /**
     * Resolve data for a component (#[WithDataProvider] companion attribute).
     *
     * Same display-ready contract as resolve(), but driven by a component-centric
     * context instead of a deferred slot. Returned data is merged underneath the
     * caller-supplied props (explicit props win).
     */
    public function resolveForComponent(DataProviderContext $context, array $twigContext): array;
}
