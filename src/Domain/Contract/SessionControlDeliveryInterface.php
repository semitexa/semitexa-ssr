<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

/**
 * Track R · R3 — the narrow seam by which the subscriber routes a control
 * message to a single session-addressed queue (design §C.4).
 *
 * The subscriber resolves a scope-invalidation signal to local {@see \Semitexa\Ssr\Domain\Model\SubscriberRef}s
 * (via the R1 reverse index) and, for each, RPUSHes a `{__ctrl:rerun}` marker
 * onto that session's queue so the OWNING worker drains it on its next poll tick
 * and runs the re-run (R4). This contract is exactly that one capability —
 * "deliver a control message to a session" — and nothing else: no fan-out, no
 * row data, no re-run execution.
 *
 * It exists as a contract so the subscriber can be exercised with a capturing
 * double in a unit test (no live Swoole server / Redis), the same way P3's
 * {@see ScopeInvalidationBusInterface} isolates the publisher. The default
 * binding forwards to the EXISTING, unchanged session-addressed transport
 * ({@see \Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::deliver()}).
 */
interface SessionControlDeliveryInterface
{
    /**
     * Enqueue a control message onto the given session's session-addressed queue.
     * The control is a marker (e.g. `['__ctrl' => 'rerun', ...]`), NOT bytes/data
     * to write to the socket — the owning worker interprets it (R4).
     *
     * @param array<string, mixed> $control
     */
    public function deliverControl(string $sessionId, array $control): void;
}
