<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Pipeline\ReRun\RouteReRunner;
use Semitexa\Core\Pipeline\RouteExecutor;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\ConnectCoordinator;
use Semitexa\Ssr\Application\Service\Async\LivePubSubChannelController;
use Semitexa\Ssr\Application\Service\Async\RedisSubscribeConnectionFactory;
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationSubscriber;
use Semitexa\Ssr\Application\Service\Async\ScanningSubscriberIndex;
use Semitexa\Ssr\Application\Service\Async\SseSessionControlDelivery;

/**
 * Track R · R8c (C2) — wire the per-worker CONSUMER-HALF of the live-update
 * pipeline, the first production wiring of R1–R5.
 *
 * Runs at {@see ServerLifecyclePhase::WorkerStartAfterContainer} (requiresContainer:
 * true) — after `$container->build()`, mirroring {@see WireSseServedPathsListener}
 * and the orm `WireDefaultEventDispatcherListener`. It assembles, from the pre-fork
 * cross-worker tables ({@see TrackRSharedTables}, created by
 * {@see CreateTrackRTablesListener} at PreStart) and the request container:
 *
 *  - R1 reverse index ({@see ScanningSubscriberIndex}) over the shared subscription
 *    table;
 *  - R3 subscriber ({@see ResourceInvalidationSubscriber}) on a DEDICATED Redis
 *    connection ({@see RedisSubscribeConnectionFactory});
 *  - the live channel controller ({@see LivePubSubChannelController}) that launches
 *    that subscriber's loop on subscribe-on-first;
 *  - R5 coordinator ({@see ConnectCoordinator}) the held-open grid stream drives;
 *  - R2 re-runner ({@see RouteReRunner} over {@see RouteExecutor::reExecute()}) — the
 *    auth-first full-chain re-run R4 calls on each `{__ctrl:rerun}` tick.
 *
 * It then hands R4 its collaborators ({@see AsyncResourceSseServer::setReRunner()} /
 * {@see AsyncResourceSseServer::setRerunCoalescer()}) and the held-open serve its
 * coordinator ({@see AsyncResourceSseServer::setConnectCoordinator()}). After this
 * runs, a `{__ctrl:rerun}` is no longer a safe no-op — it re-runs the chain and
 * writes a fresh frame.
 *
 * Fail-soft: without the pre-fork tables (Swoole absent) or without Redis
 * (single-server / in-memory mode there is no cross-instance push), the
 * consumer-half is left unwired (the loop branch stays the safe no-op it was) and
 * the reason is logged — never a silent skip.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterContainer->value,
    priority: -20,
    requiresContainer: true,
)]
final class WireTrackRConsumerListener implements ServerLifecycleListenerInterface
{
    /**
     * The worker container — self-bound under {@see ContainerInterface} by
     * {@see \Semitexa\Core\Container\ContainerFactory}. Injected (not statically
     * accessed) so the re-runner can re-resolve the tenant store + auth gate from
     * the live container on each re-run tick.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    public function handle(ServerLifecycleContext $context): void
    {
        $tables = $context->bootstrapState?->get(SsrBootstrapStateKey::TRACK_R_SHARED_TABLES);
        if (!$tables instanceof TrackRSharedTables) {
            StaticLoggerBridge::debug('ssr', 'track_r_consumer_unwired', [
                'reason' => 'no pre-fork shared tables (Swoole Table unavailable)',
            ]);
            return;
        }

        // Intended Grid Model · Phase 2 — wire the view-change coalescer BEFORE the
        // Redis gate. A view-change command is intra-instance (the browser POSTs to
        // THIS deployment; the control rides the session-addressed queue, which falls
        // back to the Swoole deliver-table when Redis is absent), so the view-change
        // intake must work even single-server with no cross-instance bus.
        AsyncResourceSseServer::setViewChangeCoalescer($tables->viewChangeCoalescer);

        $connectionFactory = RedisSubscribeConnectionFactory::fromEnvironment();
        if ($connectionFactory === null) {
            StaticLoggerBridge::debug('ssr', 'track_r_consumer_unwired', [
                'reason' => 'no Redis configured — no cross-instance invalidation bus (view-change still wired)',
            ]);
            return;
        }

        $container = $this->container;

        // R3 subscriber + R1 index + the existing session-addressed delivery.
        $subscriber = new ResourceInvalidationSubscriber(
            new ScanningSubscriberIndex($tables->subscriptions),
            $tables->subscriptions,
            $tables->coalescer,
            $connectionFactory,
            new SseSessionControlDelivery(),
        );

        // R5 coordinator, driven by the held-open serve on connect/disconnect.
        $coordinator = new ConnectCoordinator(
            $tables->subscriptions,
            $subscriber,
            $tables->coalescer,
            new LivePubSubChannelController($subscriber),
            $tables->viewChangeCoalescer,
        );

        // R2 re-runner — the auth-first full-chain re-run unit. Built like the
        // canonical RoutePhase construction (a RequestScopedContainer wrapping the
        // worker container + the optional AuthBootstrapper) so a re-run re-resolves
        // identity from the live session each tick.
        $authBootstrapper = $container->has(AuthBootstrapperInterface::class)
            ? $container->get(AuthBootstrapperInterface::class)
            : null;
        $reRunner = new RouteReRunner(
            new RouteExecutor(
                new RequestScopedContainer($container),
                $container,
                $authBootstrapper instanceof AuthBootstrapperInterface ? $authBootstrapper : null,
            ),
            $container,
        );

        AsyncResourceSseServer::setConnectCoordinator($coordinator);
        AsyncResourceSseServer::setRerunCoalescer($tables->coalescer);
        AsyncResourceSseServer::setReRunner($reRunner);

        StaticLoggerBridge::debug('ssr', 'track_r_consumer_wired', [
            'note' => 'R5 coordinator + R3 subscriber + R2 re-runner live; held-open grid re-run armed',
        ]);
    }
}
