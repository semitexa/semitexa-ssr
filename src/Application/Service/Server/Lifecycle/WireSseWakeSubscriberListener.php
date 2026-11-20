<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Ssr\Application\Service\Async\RedisSubscribeConnectionFactory;
use Semitexa\Ssr\Application\Service\Async\SseServer;

/**
 * Give each worker its SSE wake receiver
 * ({@see \Semitexa\Ssr\Application\Service\Async\SseWakeSubscriber}), so a held
 * stream is told when another worker queued a frame for it instead of asking
 * Redis five times a second (tk-idle-sse-stream-polls-redis).
 *
 * Wiring only: nothing connects here. The receiver opens its dedicated
 * connection when the worker's first stream is held open, and
 * {@see StopTrackRConsumerListener} stops it on WorkerExit.
 *
 * Its own listener and not a line in {@see WireTrackRConsumerListener}: that
 * one returns early without the pre-fork Track R tables, and a wake needs
 * nothing but Redis. Fail-soft the same way — no `REDIS_HOST`, no receiver, and
 * every held stream keeps the 0.2 s tick it had before.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterContainer->value,
    priority: -20,
    requiresContainer: true,
)]
final class WireSseWakeSubscriberListener implements ServerLifecycleListenerInterface
{
    #[InjectAsReadonly]
    protected SseServer $sseServer;

    public function handle(ServerLifecycleContext $context): void
    {
        $connectionFactory = RedisSubscribeConnectionFactory::fromEnvironment();
        if ($connectionFactory !== null) {
            $this->sseServer->wireWakeSubscriber($connectionFactory);
        }
    }
}
