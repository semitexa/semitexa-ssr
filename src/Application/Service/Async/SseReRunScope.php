<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Swoole\Coroutine;

/**
 * Track R · R8c — re-run reentrancy depth, per coroutine.
 *
 * A control-frame re-run re-invokes the FULL handler chain
 * (`ReRunnerInterface::reRun()` → `RouteExecutor::reExecute()`), which re-enters
 * the SAME own-route handler that is currently streaming. That handler must
 * produce the fresh frame BODY (JSON) and must NOT grab the live socket and open
 * a second held-open stream — doing so would break the fd it is already
 * streaming on, or recurse. Handlers ask {@see isInProgress()} to take their JSON
 * branch on a re-run tick.
 *
 * **The depth is coroutine-local, and that is the whole point.** A re-run may
 * yield on I/O to another session's connect coroutine; a per-worker counter would
 * then report "re-run in progress" to a completely unrelated connection, which
 * would take its JSON branch and never open its stream. So the depth lives in
 * `Coroutine::getContext()`, which is per-coroutine storage, and each coroutine
 * sees only its own nesting.
 *
 * The integer field below is the **fallback for the no-coroutine path only** —
 * CLI and tests, where `Coroutine::getCid()` is negative or the extension is
 * absent entirely. It is not the primary mechanism, and it must never become
 * one: under Swoole every real request runs in a coroutine and takes the context
 * branch. The instance is a per-worker singleton, so this fallback keeps exactly
 * the process-wide semantics it had as a static.
 *
 * Depth rather than a boolean because re-runs legitimately nest, and `end()`
 * clamps at zero so an unbalanced call can never drive it negative and wedge
 * every later re-run into looking "not in progress".
 */
final class SseReRunScope
{
    private const CONTEXT_KEY = '__semitexa_track_r_rerun_depth';

    /** Non-coroutine (CLI / test) fallback depth. See the class note. */
    private int $depthFallback = 0;

    public function begin(): void
    {
        $context = $this->coroutineContext();
        if ($context !== null) {
            $context[self::CONTEXT_KEY] = ((int) ($context[self::CONTEXT_KEY] ?? 0)) + 1;

            return;
        }

        $this->depthFallback++;
    }

    public function end(): void
    {
        $context = $this->coroutineContext();
        if ($context !== null) {
            $depth = ((int) ($context[self::CONTEXT_KEY] ?? 0)) - 1;
            $context[self::CONTEXT_KEY] = $depth > 0 ? $depth : 0;

            return;
        }

        if ($this->depthFallback > 0) {
            $this->depthFallback--;
        }
    }

    public function isInProgress(): bool
    {
        $context = $this->coroutineContext();
        if ($context !== null) {
            return ((int) ($context[self::CONTEXT_KEY] ?? 0)) > 0;
        }

        return $this->depthFallback > 0;
    }

    /**
     * The current coroutine's context, or `null` when there is no coroutine to
     * scope to — either Swoole is absent (CLI/test) or we are on the main
     * thread. Callers fall back to the process-wide counter in that case.
     */
    private function coroutineContext(): ?Coroutine\Context
    {
        if (!class_exists(Coroutine::class, false)) {
            return null;
        }

        $cid = Coroutine::getCid();
        if (!is_int($cid) || $cid < 0) {
            return null;
        }

        return Coroutine::getContext();
    }
}
