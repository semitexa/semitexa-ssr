<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Http;

use Semitexa\Core\Pipeline\CurrentRequestTracer;

/**
 * A span inside `response.render`, for the steps a page render takes: on the
 * dev stack render was 47-49% of every HTML route with no child span, so half
 * of each request read as one block (ai:observe, 2026-11-03). Where no tracer
 * is registered, as in production, the step runs with one null check.
 */
final class RenderSpan
{
    /**
     * @template T
     * @param \Closure(): T $step
     * @return T
     */
    public static function around(string $name, \Closure $step): mixed
    {
        $tracer = CurrentRequestTracer::get();
        if ($tracer === null) {
            return $step();
        }
        $tracer->begin($name);
        try {
            return $step();
        } finally {
            $tracer->end($name);
        }
    }
}
