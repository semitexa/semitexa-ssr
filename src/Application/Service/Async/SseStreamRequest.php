<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

/**
 * The four query parameters that decide what kind of SSE stream a client is
 * asking for, parsed once instead of re-read from `$request->get` at each step.
 *
 * They are not independent: `deferred_request_id` opens a guest-permissive door,
 * `demo_stream` always demands a session, and an explicit `mode=live` is a
 * persistent request that must not borrow the deferred door's leniency. Holding
 * them together — with the "is this persistent?" question answered in one place —
 * is what keeps that interplay legible.
 *
 * Extracted from AsyncResourceSseServer::handleSse by ep-slay-sse-god-class-2
 * (tk-sse2-admission).
 */
final class SseStreamRequest
{
    private function __construct(
        public readonly string $sessionId,
        public readonly string $demoStream,
        public readonly string $deferredRequestId,
        public readonly string $rawMode,
        public readonly mixed $rawSessionId,
        public readonly ?string $lastEventId,
    ) {
    }

    /**
     * @param mixed $request a Swoole HTTP request
     */
    public static function fromRequest(mixed $request): self
    {
        $get = is_array($request->get ?? null) ? $request->get : [];
        $header = is_array($request->header ?? null) ? $request->header : [];

        $rawSessionId = $get['session_id'] ?? null;
        $lastEventId = $header['last-event-id'] ?? null;

        return new self(
            // A client may bring its own id (that is how a reconnect rejoins its
            // queue); absent one, mint a fresh session rather than refuse.
            sessionId: trim((string) ($rawSessionId ?: uniqid('sse_', true))),
            demoStream: isset($get['demo_stream']) ? trim((string) $get['demo_stream']) : '',
            deferredRequestId: trim((string) ($get['deferred_request_id'] ?? '')),
            rawMode: trim((string) ($get['mode'] ?? '')),
            rawSessionId: $rawSessionId,
            lastEventId: is_string($lastEventId) ? $lastEventId : null,
        );
    }

    public function hasDeferredRequest(): bool
    {
        return $this->deferredRequestId !== '';
    }

    public function hasDemoStream(): bool
    {
        return $this->demoStream !== '';
    }

    /**
     * Did the client explicitly ask for a persistent live stream?
     *
     * Load-bearing: a deferred request is let through the auth gate because its
     * delivery pipeline closes the channel when done. An explicit `mode=live`
     * has no such ending, so it must be treated as persistent even when a
     * `deferred_request_id` rides along, or it would inherit a bypass it has not
     * earned.
     */
    public function isPersistentRequested(): bool
    {
        return $this->rawMode === AsyncResourceSseServer::TRANSPORT_MODE_LIVE;
    }
}
