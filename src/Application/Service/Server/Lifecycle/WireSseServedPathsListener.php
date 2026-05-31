<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;

/**
 * Track R · R8a — generalize SSE serving to `transport === Sse`.
 *
 * The SSE serve dispatch ({@see AsyncResourceSseServer::handle()}) was hardcoded
 * to the single `/__semitexa_kiss` path. This listener removes that coupling: it
 * reads every discovered route's declared transport and registers the paths of
 * the ones declaring {@see TransportType::Sse} into the server, so the intercept
 * keys on the route's transport, not on its path. `/__semitexa_kiss` itself
 * declares `transport: Sse` (see {@see \Semitexa\Ssr\Application\Payload\Request\SseKissPayload}),
 * so it lands in the set and is served by the same generalized path — KISS is
 * unchanged. An own-route `#[AsProtectedPayload(transport: Sse)]` endpoint is
 * served on equal footing the moment it is discovered.
 *
 * Runs at {@see ServerLifecyclePhase::WorkerStartAfterContainer} (requiresContainer:
 * true) — AFTER `$container->build()`, so the {@see RouteRegistry} is populated and
 * resolvable. Mirrors the orm `WireDefaultEventDispatcherListener` wiring pattern.
 * The served-path set is per-worker static state ({@see AsyncResourceSseServer}), so
 * it is re-established in every worker.
 *
 * This is purely additive plumbing: it ENABLES own-route SSE serving but switches
 * nothing on. No grid route is declared here (that is R8b), no transport is cut
 * over (that is R8c), and no consumer-half connect is launched.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterContainer->value,
    priority: 0,
    requiresContainer: true,
)]
final class WireSseServedPathsListener implements ServerLifecycleListenerInterface
{
    #[InjectAsReadonly]
    protected RouteRegistry $routeRegistry;

    public function handle(ServerLifecycleContext $context): void
    {
        AsyncResourceSseServer::setSseServedPaths(self::collectSsePaths($this->routeRegistry));
    }

    /**
     * Collect the distinct, non-empty paths of every route whose declared
     * transport is {@see TransportType::Sse}. Extracted as a pure static so the
     * "keys on transport, not path" guarantee is provable without standing up a
     * live {@see \Swoole\Http\Server} or container.
     *
     * Route paths are exact strings here (kiss, and the future own-route grid
     * stream are static paths). Pattern routes carrying `{...}` placeholders are
     * intentionally NOT pre-resolved at this layer; if a future SSE endpoint
     * needs a parameterised path the dispatch must grow a pattern match — out of
     * scope for R8a, which only generalizes path → transport.
     *
     * @return list<string>
     */
    public static function collectSsePaths(RouteRegistry $registry): array
    {
        $paths = [];
        foreach ($registry->getAll() as $route) {
            $transport = $route['transport'] ?? null;
            if ($transport !== TransportType::Sse->value) {
                continue;
            }

            $path = $route['path'] ?? null;
            if (is_string($path) && $path !== '' && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
