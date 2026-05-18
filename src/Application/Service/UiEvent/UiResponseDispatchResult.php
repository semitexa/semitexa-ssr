<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

/**
 * Canonical result returned by {@see UiResponseDispatcherInterface::dispatch()}.
 *
 * The endpoint handler ({@see \Semitexa\Ssr\Application\Handler\PayloadHandler\UiEventEndpointHandler})
 * is the only consumer: it serialises this DTO into the HTTP response so
 * dispatchers don't need to know about HTTP status codes, JSON encoding,
 * or response normalisation.
 *
 * Why a typed result rather than letting dispatchers return a `Resource
 * Response` directly:
 *
 *   - The endpoint contract is framework-owned. Dispatchers should not be
 *     able to set arbitrary headers, swap content-types, or stream bodies
 *     — that would let a platform-side dispatcher silently change the
 *     wire contract.
 *   - The endpoint always emits `application/json; charset=utf-8`. A typed
 *     result keeps that invariant locked in one place.
 *   - The endpoint maps `(statusCode, status, phase, reason, message, body)`
 *     into a stable JSON envelope. Future migrations (e.g. adding a typed
 *     SSE event name) update the envelope shape in one place.
 *
 * Stability rule: this DTO is part of the framework contract. New fields
 * may be added with sensible defaults; existing fields MUST NOT be
 * removed without a deprecation cycle that updates the canonical envelope
 * test assertions.
 */
final readonly class UiResponseDispatchResult
{
    /**
     * @param int                  $statusCode  HTTP status the endpoint will
     *                                          emit. Clamped to the
     *                                          conventional `2xx` for accepted
     *                                          envelopes; dispatcher-level
     *                                          failures land here as `5xx`
     *                                          (typically 500 for an
     *                                          unhandled throwable).
     * @param string               $status      Coarse status verb — `accepted`
     *                                          when the dispatcher took
     *                                          ownership, `rejected` when it
     *                                          refused (e.g. authorizer
     *                                          denial), `error` when it
     *                                          failed (e.g. internal
     *                                          exception).
     * @param string               $phase       Lifecycle phase — `foundation`
     *                                          for the not-configured default,
     *                                          `dispatch` once a concrete
     *                                          dispatcher takes over.
     * @param string               $reason      Stable machine-readable reason
     *                                          code. Examples: `accepted`,
     *                                          `dispatcher_not_configured`,
     *                                          `ui_event_dispatcher_failure`.
     *                                          Reasons are part of the public
     *                                          contract; downstream callers
     *                                          rely on them.
     * @param string               $message     Operator-safe human message.
     *                                          MUST NOT carry tokens, signed
     *                                          ctx contents, secret env names,
     *                                          stack traces, FQCNs, or
     *                                          implementation detail. The
     *                                          endpoint surfaces this verbatim.
     * @param array<string, mixed> $body        Optional extra fields the
     *                                          endpoint folds into the JSON
     *                                          envelope under their own keys.
     *                                          MUST NOT collide with the
     *                                          canonical envelope keys
     *                                          (`status`, `phase`, `reason`,
     *                                          `message`, `eventId`,
     *                                          `correlationId`,
     *                                          `semanticEvent`,
     *                                          `schemaVersion`,
     *                                          `signedContext`). The endpoint
     *                                          enforces the no-collision rule
     *                                          via a tight allow-list, so a
     *                                          buggy dispatcher cannot
     *                                          overwrite the canonical shape.
     */
    public function __construct(
        public int $statusCode,
        public string $status,
        public string $phase,
        public string $reason,
        public string $message,
        public array $body = [],
    ) {}

    /**
     * Helper for the canonical "no concrete dispatcher installed" outcome.
     *
     * Kept as a named constructor so the default dispatcher and any future
     * "framework-only" branch produce a byte-identical envelope — the
     * downstream consumer detects "no dispatcher" by reason code alone.
     */
    public static function notConfigured(): self
    {
        return new self(
            statusCode: 202,
            status:     'accepted',
            phase:      'foundation',
            reason:     'dispatcher_not_configured',
            message:    'UI event endpoint is active, but no UI response dispatcher is installed.',
        );
    }
}
