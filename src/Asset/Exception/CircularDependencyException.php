<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Asset\Exception;

/**
 * Thrown when the asset dependency graph contains a cycle.
 */
final class CircularDependencyException extends \RuntimeException
{
    /**
     * @param string[] $cycle Ordered list of asset keys forming the cycle
     */
    public function __construct(array $cycle)
    {
        $path = implode(' → ', $cycle);
        parent::__construct("Circular asset dependency detected: {$path}");
    }
}
