<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Ssr\Application\Service\Async\SseServer;

/**
 * Track R · Gap C-3 — the teardown half of {@see WireTrackRConsumerListener}.
 *
 * WHY (issue semitexa/semitexa-ssr#100): R3's blocking subscribe loop used to learn
 * that its worker was going down only by having its socket read fail, which is
 * indistinguishable from a Redis outage — so every `bin/semitexa server:restart`
 * produced one ERROR line per worker, and every log-reading alerter read an
 * operator action as an incident.
 *
 * WHY **WorkerExit** AND NOT WorkerStop — this ordering was measured against
 * `SwooleBootstrap`, not assumed:
 *
 *  1. `reload_async: true` + `max_wait_time: 3` ({@see \Semitexa\Core\Server\ServerConfigurator})
 *     means a restart is a graceful drain, not a kill.
 *  2. Swoole raises `onWorkerExit` REPEATEDLY through that drain window, and the
 *     bootstrap's handler clears every timer and `Swoole\Coroutine::cancel()`s every
 *     parked coroutine — the subscribe loop included.
 *  3. `onWorkerStop` runs only AFTER the event loop has exited. A listener there
 *     would fire long after the loop had already caught the cancellation, logged it
 *     and reconnected: too late to prevent the very line this fixes.
 *
 * Even on WorkerExit this listener is the SECOND line of defence, because the
 * bootstrap cancels coroutines BEFORE invoking the phase. The primary signal is
 * read from inside the coroutine itself
 * ({@see \Semitexa\Ssr\Application\Service\Async\ResourceInvalidationSubscriber::wasCancelled()}).
 * What this listener adds is durable: WorkerExit fires repeatedly, so from the
 * first firing onward the subscriber is latched into teardown and cannot re-park on
 * a fresh connection inside a worker the manager is trying to drain.
 *
 * Fail-soft, exactly like the wiring half: no coordinator (no Redis, no shared
 * tables) means there is no loop to stop, and this is a no-op.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerExit->value,
    priority: -90,
    requiresContainer: true,
)]
final class StopTrackRConsumerListener implements ServerLifecycleListenerInterface
{
    /**
     * The worker singleton, injected the same way {@see WireTrackRConsumerListener}
     * injects it, so both halves talk to the same instance.
     */
    #[InjectAsReadonly]
    protected SseServer $sseServer;

    public function handle(ServerLifecycleContext $context): void
    {
        $this->sseServer->shutdownInvalidationSubscriber();
    }
}
