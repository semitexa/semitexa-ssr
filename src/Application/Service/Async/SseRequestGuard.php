<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Admission control for SSE requests — the first extraction of
 * `ep-slay-sse-god-class` out of {@see AsyncResourceSseServer}.
 *
 * One question, one object: **may this request open a stream, and if not, what
 * does the refusal look like on the wire?** Everything here is a pure decision
 * over the request plus already-resolved facts (is the caller authenticated, is
 * anonymous access opted in), or the rendering of a refusal. Nothing in this
 * class touches session state, Redis, Swoole tables or the connection registry
 * — which is exactly why it could come out first.
 *
 * Stateless by construction: no properties, no constructor. The facade holds one
 * lazily-created instance per worker, and the reentrancy that makes that safe is
 * simply that there is no state to share.
 *
 * Both gates here **fail closed**. That is deliberate and load-bearing: a
 * missing `Host`, an unparseable `Origin`, or a persistent stream requested
 * without any credential are all refusals, never a shrug-and-admit.
 */
final class SseRequestGuard
{
    /**
     * Strict shape for an anonymous bearer-channel subscriber id.
     *
     * 128 bits of entropy (16 random bytes hex-encoded) with the `sse_` prefix.
     * Platform-UI mints ids of this shape; the KISS endpoint admits anonymous
     * bare GET requests only when the supplied session_id matches.
     *
     * Canonical definition. {@see AsyncResourceSseServer::SAFE_BEARER_SESSION_ID_PATTERN}
     * aliases this so the 51 files calling into the facade keep reading the same
     * value from the same name.
     */
    public const SAFE_BEARER_SESSION_ID_PATTERN = '/\Asse_[a-f0-9]{32}\z/';

    /**
     * Same-origin check for the SSE admit path.
     *
     * Fails closed twice over: `Host` is required to compare against, and at
     * least one of `Origin` / `Referer` must be present AND match. Browser
     * EventSource always sends `Origin`, so a request carrying neither header is
     * treated as cross-origin and refused rather than admitted by default.
     */
    public function isSameOriginRequest(Request $request): bool
    {
        $header = [];
        if (is_array($request->header)) {
            foreach ($request->header as $key => $value) {
                if (is_string($key) && (is_scalar($value) || $value === null)) {
                    $header[strtolower($key)] = (string) $value;
                }
            }
        }

        $host = trim($header['host'] ?? '');
        if ($host === '') {
            return false;
        }

        $matched = false;
        foreach (['origin', 'referer'] as $headerName) {
            $value = trim($header[$headerName] ?? '');
            if ($value === '') {
                continue;
            }

            $requestHost = parse_url($value, PHP_URL_HOST);
            if (!is_string($requestHost) || $requestHost === '') {
                return false;
            }

            $requestPort = parse_url($value, PHP_URL_PORT);
            $normalizedHost = strtolower($host);
            $normalizedRequestHost = strtolower($requestHost . ($requestPort !== null ? ':' . $requestPort : ''));

            if ($normalizedRequestHost !== $normalizedHost && strtolower($requestHost) !== $normalizedHost) {
                return false;
            }

            $matched = true;
        }

        return $matched;
    }

    public function isSafeBearerSessionId(mixed $rawSessionId): bool
    {
        if (!is_string($rawSessionId) || $rawSessionId === '') {
            return false;
        }

        return preg_match(self::SAFE_BEARER_SESSION_ID_PATTERN, $rawSessionId) === 1;
    }

    public function resolveClientIp(Request $request): string
    {
        $server = is_array($request->server) ? $request->server : [];
        $ip = trim((string) ($server['remote_addr'] ?? ''));

        return $ip !== '' ? strtolower($ip) : '';
    }

    /**
     * Resolve the admit error (if any) for an SSE request.
     *
     * `deferred_request_id` is normally guest-permissive: a deferred stream
     * runs its delivery then sends done/close, so guests may receive the
     * one-shot deferred drain without auth. But an explicit `mode=live`
     * request ($persistentRequested) asks the server to HOLD THE CONNECTION
     * OPEN past the deferred drain (DeferredBlockOrchestrator keepChannelOpen),
     * turning the deferred door into a persistent stream. The bind-token that
     * gates the deferred door is a request-binding held by every client that
     * loaded the deferred page — NOT an auth credential — so a persistent
     * request must independently satisfy the persistent-stream credential
     * check (authenticated, SSE_PUBLIC_ANONYMOUS, or a safe bearer-channel id)
     * regardless of deferred_request_id. Otherwise an anonymous, non-bearer
     * caller could obtain a long-lived stream through the deferred door.
     */
    public function resolveAuthorizationError(
        bool $authenticated,
        bool $anonymousAllowed,
        string $demoStream,
        string $deferredRequestId,
        bool $safeBearerSessionId,
        bool $persistentRequested = false,
    ): ?string {
        if ($demoStream !== '' && !$authenticated) {
            return 'Authorization is required for this SSE demo stream.';
        }

        // The deferred door is only a bypass for the NON-persistent (drain)
        // case. A persistent (mode=live) request never gets the bypass.
        $deferredBypassesPersistentCheck = $deferredRequestId !== '' && !$persistentRequested;

        if (
            $demoStream === ''
            && !$deferredBypassesPersistentCheck
            && !$authenticated
            && !$anonymousAllowed
            && !$safeBearerSessionId
        ) {
            return 'Authorization is required for persistent SSE streams. Set SSE_PUBLIC_ANONYMOUS=true to opt in to anonymous persistent streams, or supply a safe-shaped subscriber channel id.';
        }

        return null;
    }

    /**
     * Collapse the two admit gates into the single rejection the caller should
     * render, or `null` to admit. Auth outranks origin: a caller that fails both
     * is told it is unauthorized (401), not that it is cross-origin (403).
     *
     * @return array{status: int, message: string}|null
     */
    public function resolveRejection(bool $sameOrigin, ?string $authError): ?array
    {
        if ($authError !== null) {
            return [
                'status' => 401,
                'message' => $authError,
            ];
        }

        if (!$sameOrigin) {
            return [
                'status' => 403,
                'message' => '',
            ];
        }

        return null;
    }

    public function rejectUnauthorized(Response $response, string $message): void
    {
        $response->status(401);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'error' => 'Unauthorized',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function rejectTooManyRequests(Response $response, string $message): void
    {
        $response->status(429);
        $response->header('Content-Type', 'application/json');
        $response->header('Retry-After', '30');
        $response->end(json_encode([
            'error' => 'Too Many Requests',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function rejectBadRequest(Response $response, string $message): void
    {
        $response->status(400);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'error' => 'Bad Request',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
