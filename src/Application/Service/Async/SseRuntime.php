<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Pipeline\ReRun\ReRunnerInterface;
use Semitexa\Core\Server\SseTransportInterface;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Domain\Contract\SubscriptionFactoryInterface;

/**
 * The collaborators the SSE server is handed at worker boot, in one place.
 *
 * These eight used to be eight separate `private static` slots on the facade,
 * each with its own setter, filled by three different lifecycle listeners. That
 * scattering is what made the control plane hard to extract: a router that needs
 * five of them had no single thing to take.
 *
 * This is **not** dependency injection and does not pretend to be. It is a
 * holder: the same late-wired collaborators, gathered so there is one seam
 * instead of eight. Real DI for this facade requires it to stop being static —
 * a static class cannot resolve anything from the container, which the
 * `semitexa.staticContainerAccess` rule enforces — and that work is
 * `ep-kill-static-facades` / `tk-facades-sse-server`. When it lands, this class
 * becomes the natural constructor argument list.
 *
 * **Everything is nullable and stays null until wired**, deliberately. Each
 * consumer treats a null collaborator as a documented safe no-op — a control
 * frame arriving before the re-runner exists is dropped rather than crashing the
 * drain — so the SSE path works during boot and in tests that wire nothing.
 */
final class SseRuntime
{
    /**
     * Swoole-free SSE write port. The Swoole adapter binds lazily as a soft
     * runtime dependency, so the byte-writing path goes through the contract
     * rather than touching `Swoole\Http\Response::write()` directly.
     */
    public ?SseTransportInterface $transport = null;

    /** Track R · R2 — re-runs the full handler chain for a control frame. */
    public ?ReRunnerInterface $reRunner = null;

    /**
     * Track R · R3 — the cross-worker idempotency table that collapses N
     * duplicate invalidation signals into one pending re-run per stream.
     */
    public ?RerunCoalescer $rerunCoalescer = null;

    /**
     * The view-change counterpart, kept distinct from {@see $rerunCoalescer} so a
     * mutation re-run and a view-change re-run can never suppress each other.
     */
    public ?ViewChangeCoalescer $viewChangeCoalescer = null;

    /** Track R · R5 — populates and reaps the subscription store on connect. */
    public ?ConnectCoordinator $connectCoordinator = null;

    /** Builds a multiplexed subscription from a subscribe control. */
    public ?SubscriptionFactoryInterface $subscriptionFactory = null;

    /** Renders the deferred blocks a page requested. */
    public ?DeferredBlockOrchestrator $deferredBlockOrchestrator = null;

    /**
     * Track R · R8a — request paths served by the SSE intercept, keyed for O(1)
     * membership. Populated per worker from every discovered route whose
     * transport is `Sse`, so serve dispatch keys on the route's declared
     * transport rather than on a hardcoded path.
     *
     * @var array<string, true>
     */
    public array $sseServedPaths = [];

    public function servesPath(string $path): bool
    {
        return isset($this->sseServedPaths[$path]);
    }

    /**
     * Rebuild the served-path index. Non-string and empty entries are dropped
     * rather than indexed: a bogus key would sit in the membership set forever
     * and could only ever produce a false positive.
     *
     * @param list<mixed> $paths
     */
    public function setServedPaths(array $paths): void
    {
        $served = [];
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $served[$path] = true;
            }
        }

        $this->sseServedPaths = $served;
    }
}
