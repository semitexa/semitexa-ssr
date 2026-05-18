<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;

/**
 * Default {@see CanonicalUiMessagePublisherInterface} binding. Forwards
 * to the canonical KISS transport ({@see AsyncResourceSseServer}) — no
 * new SSE endpoint, queue, or stream is introduced.
 *
 * The `_type` field carried by {@see UiSseMessageInterface::toSsePayload()}
 * is consumed by the wire-format chokepoint in {@see AsyncResourceSseServer}
 * (see `composeSseFrame`), which maps it to an SSE `event:` line. The
 * publisher itself does no string concatenation onto the wire.
 */
#[SatisfiesServiceContract(of: CanonicalUiMessagePublisherInterface::class)]
final class AsyncResourceSseMessagePublisher implements CanonicalUiMessagePublisherInterface
{
    public function publish(string $sessionId, UiSseMessageInterface $message): void
    {
        AsyncResourceSseServer::deliver($sessionId, $message->toSsePayload());
    }

    public function publishToUser(string $userId, UiSseMessageInterface $message): int
    {
        return AsyncResourceSseServer::deliverToUser($userId, $message->toSsePayload());
    }
}
