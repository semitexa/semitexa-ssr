<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

/**
 * Canonical publisher for typed UI messages delivered over
 * `GET /__semitexa_kiss`. Concrete implementations MUST use the existing
 * KISS transport — no new SSE endpoint, queue, or stream is allowed
 * (ADR-0001 §6).
 *
 * Why a typed publisher rather than letting callers hit
 * {@see \Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::deliver()}
 * directly:
 *
 *   - The static `deliver()` accepts arbitrary arrays. A typed publisher
 *     gates everything through the {@see UiSseMessageInterface} contract,
 *     so `_type` always comes from the {@see UiSseEventType} allow-list.
 *   - Downstream packages (semitexa-platform-ui, future grid integrations)
 *     bind against this contract via `#[SatisfiesServiceContract]`. They
 *     never need to know how the underlying transport routes the message
 *     (same-worker queue / Redis / Swoole tables / pending / buffer).
 *   - Tests can substitute an in-memory publisher to assert what would
 *     have been delivered without bringing up Swoole.
 */
interface CanonicalUiMessagePublisherInterface
{
    /**
     * Publish a typed message to a specific KISS session.
     *
     * The session id is the same opaque string used by the frontend
     * runtime when it opens `GET /__semitexa_kiss?session_id=…`.
     * Implementations MUST forward to the canonical transport — no new
     * transport / queue / endpoint.
     */
    public function publish(string $sessionId, UiSseMessageInterface $message): void;

    /**
     * Publish to every active SSE session for an authenticated user.
     *
     * @return int Number of sessions the message was enqueued to.
     *             Implementations that cannot enumerate sessions (e.g.
     *             Redis is unavailable) MUST return `0` rather than
     *             throw — same defensive contract as the underlying
     *             {@see \Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::deliverToUser()}.
     */
    public function publishToUser(string $userId, UiSseMessageInterface $message): int;
}
