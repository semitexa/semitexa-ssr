<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

/**
 * The transport-neutral seam for "publish a data-less scope invalidation so
 * every held-open feed watching `$scope` re-runs".
 *
 * Extracted so consumers depend on the CAPABILITY, not the historically
 * grid-named default ({@see \Semitexa\Ssr\Application\Service\Async\GridScopeInvalidator},
 * which already publishes the identical `ui.invalidate.{tenant}.{scope}`
 * channel for ANY scope string — collection or document). Collaborative-form
 * code touches `formdoc:{formKey}:{recordId}` scopes through this interface
 * without coupling to a "Grid" class name, and tests can substitute a recorder.
 */
interface ScopeInvalidatorInterface
{
    /**
     * Publish a data-less invalidation on `ui.invalidate.{tenant}.{scope}`. A
     * blank scope is a no-op. Best-effort: a swallowed transport failure costs
     * at most one missed re-run, repaired by the next signal.
     */
    public function touch(string $scope): void;
}
