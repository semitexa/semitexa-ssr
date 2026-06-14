<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\FanOutNotYetGatedException;

/**
 * Default {@see CanonicalUiMessagePublisherInterface} binding. Forwards
 * to the canonical KISS transport ({@see AsyncResourceSseServer}) — no
 * new SSE endpoint, queue, or stream is introduced.
 *
 * The `_type` field carried by {@see UiSseMessageInterface::toSsePayload()}
 * is consumed by the wire-format chokepoint in {@see AsyncResourceSseServer}
 * (see `buildFrame`), which resolves/validates it against the `UiSseEventType`
 * allow-list and maps it to an SSE `event:` line on a portable
 * {@see \Semitexa\Core\Server\SseFrame}. The publisher itself does no string
 * concatenation onto the wire.
 */
#[SatisfiesServiceContract(of: CanonicalUiMessagePublisherInterface::class)]
final class AsyncResourceSseMessagePublisher implements CanonicalUiMessagePublisherInterface
{
    public function publish(string $sessionId, UiSseMessageInterface $message): void
    {
        AsyncResourceSseServer::deliver($sessionId, $message->toSsePayload());
    }

    /**
     * @internal FENCED FAIL-CLOSED until Track R. This is the non-owner-request-scoped
     *           fan-out wrapper; it forwards to the fenced
     *           {@see AsyncResourceSseServer::deliverToUser()}, which does zero
     *           content-vs-recipient authorization. Throws BEFORE building/forwarding any
     *           payload so no frame can leak. Track R restores the real forward once the
     *           per-recipient entitlement filter exists. Owner-scoped {@see self::publish()}
     *           is unaffected.
     */
    public function publishToUser(string $userId, UiSseMessageInterface $message): int
    {
        throw FanOutNotYetGatedException::forFanOut(__METHOD__);

        // Track R restores: return AsyncResourceSseServer::deliverToUser($userId, $message->toSsePayload());
    }
}
