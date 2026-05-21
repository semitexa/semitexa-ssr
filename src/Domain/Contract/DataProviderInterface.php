<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

use Semitexa\Ssr\Domain\Model\DataProviderContext;

interface DataProviderInterface
{
    /**
     * Resolve prepared, display-ready data.
     *
     * Unified entry point for both deferred-slot resolution (orchestrator path)
     * and component resolution (#[WithDataProvider] path). The caller fills the
     * applicable fields on DataProviderContext: slotId+pageHandle for slots,
     * instanceId for components. Both paths populate request when available
     * (Request object in sync render, snapshot array in deferred SSE render).
     *
     * The $hint array carries path-specific context — pageContext for slot
     * resolution, twigContext (props) for component resolution. For components,
     * the returned data is merged underneath the caller-supplied props
     * (explicit props win).
     *
     * All returned values MUST be ready for direct template output:
     * - Translations resolved to strings
     * - URLs fully generated
     * - Dates/numbers pre-formatted
     * - No raw entities or domain objects
     *
     * @param array<string, mixed> $hint
     * @return array<string, mixed>
     */
    public function resolve(DataProviderContext $context, array $hint = []): array;
}
