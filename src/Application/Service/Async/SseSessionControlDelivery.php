<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Ssr\Domain\Contract\SessionControlDeliveryInterface;

/**
 * Default {@see SessionControlDeliveryInterface} binding (Track R · R3).
 *
 * Forwards a control message to the EXISTING session-addressed transport —
 * {@see AsyncResourceSseServer::deliver()} — unchanged. `deliver()` already
 * routes by `session_id`: same-worker → the local in-memory queue; cross-worker
 * / cross-instance → the `semitexa_sse_queue:{session_id}` Redis list the owning
 * worker drains on its 0.2s poll tick (design §C.4, "Transport 100%; only the
 * loop branch is new"). R3 adds no new queue or transport — it only enqueues a
 * `{__ctrl:rerun}` marker the owning worker (R4) will recognise.
 */
final class SseSessionControlDelivery implements SessionControlDeliveryInterface
{
    public function deliverControl(string $sessionId, array $control): void
    {
        AsyncResourceSseServer::deliver($sessionId, $control);
    }
}
