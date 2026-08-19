<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Core\Pipeline\ReRun\ReRunnerInterface;
use Semitexa\Core\Pipeline\RequestTracerInterface;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Domain\Contract\SubscriptionFactoryInterface;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Static entry point for the SSE serving path, delegating to {@see SseServer}.
 *
 * `ep-kill-static-facades` / `tk-facades-sse-server`: every method here is a
 * one-line delegate over ONE wired slot. The behaviour, the collaborators and
 * the per-worker state all live on the container-managed {@see SseServer};
 * this class holds no logic and no state beyond the slot. Injectable callers
 * should take `SseServer` via `#[InjectAsReadonly]` instead of calling these
 * statics; the static surface remains for static-context callers and is a
 * deletion candidate once they can inject.
 *
 * The slot follows the same convention as the other seven retired facades:
 * worker boot pushes the container singleton via {@see setInstance()}, and the
 * fallback self-creates only where no container ever boots (CLI, bare tests),
 * so both paths see exactly one instance per process.
 */
final class AsyncResourceSseServer
{
    /** @see SseServer::SAFE_BEARER_SESSION_ID_PATTERN */
    public const SAFE_BEARER_SESSION_ID_PATTERN = SseServer::SAFE_BEARER_SESSION_ID_PATTERN;

    /** @see SseServer::TRANSPORT_MODE_DRAIN */
    public const TRANSPORT_MODE_DRAIN = SseServer::TRANSPORT_MODE_DRAIN;

    /** @see SseServer::TRANSPORT_MODE_LIVE */
    public const TRANSPORT_MODE_LIVE = SseServer::TRANSPORT_MODE_LIVE;

    private static ?SseServer $instance = null;

    public static function setInstance(SseServer $server): void
    {
        self::$instance = $server;
    }

    /**
     * The instance behind the facade — the container singleton once worker
     * boot pushed it, a process-local fallback otherwise. Public so tests can
     * reach the state that used to live in private statics here, and so a
     * migration can hand the instance onward instead of re-resolving it.
     */
    public static function instance(): SseServer
    {
        return self::$instance ??= new SseServer();
    }

    public static function mintStreamId(): string
    {
        return self::instance()->mintStreamId();
    }

    public static function handle(Request $request, Response $response): bool
    {
        return self::instance()->handle($request, $response);
    }

    public static function serveResourceStream(Request $request, Response $response, string $sessionId, array $initialFrameData, ?SubscriptionRecord $record = null, ?ReRunContext $context = null, string $serverStreamId = ''): void
    {
        self::instance()->serveResourceStream($request, $response, $sessionId, $initialFrameData, $record, $context, $serverStreamId);
    }

    public static function deliver(string $sessionId, array $data): void
    {
        self::instance()->deliver($sessionId, $data);
    }

    public static function broadcast(string $sessionId, string $handlerKey, object $resource): void
    {
        self::instance()->broadcast($sessionId, $handlerKey, $resource);
    }

    public static function renderResource(object $resource): string
    {
        return self::instance()->renderResource($resource);
    }

    public static function isSessionActive(string $sessionId): bool
    {
        return self::instance()->isSessionActive($sessionId);
    }

    public static function createSessionCoroutine(callable $callback, string $sessionId): int|false
    {
        return self::instance()->createSessionCoroutine($callback, $sessionId);
    }

    public static function setServer(\Swoole\Http\Server $server): void
    {
        self::instance()->setServer($server);
    }

    public static function setSseServedPaths(array $paths): void
    {
        self::instance()->setSseServedPaths($paths);
    }

    public static function setTables(\Swoole\Table $sessionWorkerTable, \Swoole\Table $deliverTable, ?\Swoole\Table $pendingDeliverTable = null): void
    {
        self::instance()->setTables($sessionWorkerTable, $deliverTable, $pendingDeliverTable);
    }

    public static function setDeferredBlockOrchestrator(?DeferredBlockOrchestrator $orchestrator): void
    {
        self::instance()->setDeferredBlockOrchestrator($orchestrator);
    }

    public static function setRequestTracer(?RequestTracerInterface $tracer): void
    {
        self::instance()->setRequestTracer($tracer);
    }

    public static function traceMark(string $name, array $context = []): void
    {
        self::instance()->traceMark($name, $context);
    }

    public static function setReRunner(?ReRunnerInterface $reRunner): void
    {
        self::instance()->setReRunner($reRunner);
    }

    public static function setSubscriptionFactory(?SubscriptionFactoryInterface $factory): void
    {
        self::instance()->setSubscriptionFactory($factory);
    }

    public static function attachSubscription(SubscriptionRecord $record, ReRunContext $context): void
    {
        self::instance()->attachSubscription($record, $context);
    }

    public static function detachSubscription(string $streamingId): void
    {
        self::instance()->detachSubscription($streamingId);
    }

    public static function setRerunCoalescer(?RerunCoalescer $coalescer): void
    {
        self::instance()->setRerunCoalescer($coalescer);
    }

    public static function setViewChangeCoalescer(?ViewChangeCoalescer $coalescer): void
    {
        self::instance()->setViewChangeCoalescer($coalescer);
    }

    public static function submitViewChange(string $sessionId, array $params, ?string $streamingId = null): bool
    {
        return self::instance()->submitViewChange($sessionId, $params, $streamingId);
    }

    public static function submitSubscribe(string $sessionId, string $streamingId, string $routePath, string $routeMethod, array $requestSnapshot): bool
    {
        return self::instance()->submitSubscribe($sessionId, $streamingId, $routePath, $routeMethod, $requestSnapshot);
    }

    public static function submitUnsubscribe(string $sessionId, string $streamingId): bool
    {
        return self::instance()->submitUnsubscribe($sessionId, $streamingId);
    }

    public static function setConnectCoordinator(?ConnectCoordinator $coordinator): void
    {
        self::instance()->setConnectCoordinator($coordinator);
    }

    public static function isReRunInProgress(): bool
    {
        return self::instance()->isReRunInProgress();
    }

    public static function deliverToUser(string $userId, array $data): int
    {
        return self::instance()->deliverToUser($userId, $data);
    }

    public static function deliverToAuthenticatedUsers(array $data): int
    {
        return self::instance()->deliverToAuthenticatedUsers($data);
    }

    public static function maxConnectionAgeSeconds(): int
    {
        return self::instance()->maxConnectionAgeSeconds();
    }

    public static function publishScopeInvalidation(string $channel): void
    {
        self::instance()->publishScopeInvalidation($channel);
    }
}
