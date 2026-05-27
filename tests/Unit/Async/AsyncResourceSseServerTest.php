<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Swoole\Coroutine\Channel;

final class AsyncResourceSseServerTest extends TestCase
{
    private const UNSAFE_BEARER_ERROR_MESSAGE = 'Authorization is required for persistent SSE streams. Set SSE_PUBLIC_ANONYMOUS=true to opt in to anonymous persistent streams, or supply a safe-shaped subscriber channel id.';

    #[Test]
    public function authorization_gate_allows_guest_deferred_requests(): void
    {
        self::assertNull($this->resolveSseAuthorizationError(
            authenticated: false,
            anonymousAllowed: false,
            demoStream: '',
            deferredRequestId: 'req-123',
            safeBearerSessionId: false,
        ));
    }

    #[Test]
    public function authorization_gate_rejects_guest_persistent_streams_without_opt_in(): void
    {
        self::assertSame(
            self::UNSAFE_BEARER_ERROR_MESSAGE,
            $this->resolveSseAuthorizationError(
                authenticated: false,
                anonymousAllowed: false,
                demoStream: '',
                deferredRequestId: '',
                safeBearerSessionId: false,
            ),
        );
    }

    #[Test]
    public function authorization_gate_rejects_guest_demo_streams(): void
    {
        self::assertSame(
            'Authorization is required for this SSE demo stream.',
            $this->resolveSseAuthorizationError(
                authenticated: false,
                anonymousAllowed: true,
                demoStream: 'clock',
                deferredRequestId: 'req-123',
                safeBearerSessionId: false,
            ),
        );
    }

    #[Test]
    public function persistent_live_stream_through_deferred_door_still_requires_bearer_or_auth(): void
    {
        // SECURITY INVARIANT: deferred_request_id makes the auth gate
        // permissive so a guest can receive the one-shot deferred DRAIN. But an
        // explicit mode=live request asks the server to hold the connection
        // open as a persistent stream (DeferredBlockOrchestrator keepChannelOpen
        // keeps it alive past the deferred 'done'). A bind-token is held by
        // EVERY client that loaded the deferred page, so it is NOT an auth
        // credential. An anonymous, non-bearer caller therefore MUST NOT obtain
        // a persistent live stream through the deferred door — it must still
        // present the bearer-channel id (or be authenticated / opted in via
        // SSE_PUBLIC_ANONYMOUS).
        self::assertSame(
            self::UNSAFE_BEARER_ERROR_MESSAGE,
            $this->resolveSseAuthorizationError(
                authenticated: false,
                anonymousAllowed: false,
                demoStream: '',
                deferredRequestId: 'req-123',
                safeBearerSessionId: false,
                persistentRequested: true,
            ),
        );
    }

    #[Test]
    public function deferred_drain_through_deferred_door_remains_guest_permissive(): void
    {
        // Complement: a non-persistent (drain / legacy) deferred request keeps
        // the deferred door open for guests — it drains the deferred content
        // then closes, so no long-lived stream is granted.
        self::assertNull($this->resolveSseAuthorizationError(
            authenticated: false,
            anonymousAllowed: false,
            demoStream: '',
            deferredRequestId: 'req-123',
            safeBearerSessionId: false,
            persistentRequested: false,
        ));
    }

    #[Test]
    public function persistent_live_stream_with_deferred_request_id_admits_bearer_channel(): void
    {
        // A legitimate unified connection (after session-id convergence) carries
        // the page's bearer-shaped sse_<32hex> id; the persistent live stream is
        // admitted on the bearer credential even alongside deferred_request_id.
        self::assertNull($this->resolveSseAuthorizationError(
            authenticated: false,
            anonymousAllowed: false,
            demoStream: '',
            deferredRequestId: 'req-123',
            safeBearerSessionId: true,
            persistentRequested: true,
        ));
    }

    #[Test]
    public function persistent_live_stream_with_deferred_request_id_admits_authenticated(): void
    {
        self::assertNull($this->resolveSseAuthorizationError(
            authenticated: true,
            anonymousAllowed: false,
            demoStream: '',
            deferredRequestId: 'req-123',
            safeBearerSessionId: false,
            persistentRequested: true,
        ));
    }

    #[Test]
    public function guest_persistent_stream_is_rejected_as_unauthorized_before_same_origin_guard(): void
    {
        self::assertSame(
            [
                'status' => 401,
                'message' => self::UNSAFE_BEARER_ERROR_MESSAGE,
            ],
            $this->resolveSseRequestRejection(
                sameOrigin: false,
                authError: self::UNSAFE_BEARER_ERROR_MESSAGE,
            ),
        );
    }

    #[Test]
    public function bearer_safe_shape_admits_anonymous_persistent_stream(): void
    {
        self::assertNull($this->resolveSseAuthorizationError(
            authenticated: false,
            anonymousAllowed: false,
            demoStream: '',
            deferredRequestId: '',
            safeBearerSessionId: true,
        ));
    }

    #[Test]
    public function bearer_safe_shape_does_not_admit_demo_streams(): void
    {
        self::assertSame(
            'Authorization is required for this SSE demo stream.',
            $this->resolveSseAuthorizationError(
                authenticated: false,
                anonymousAllowed: false,
                demoStream: 'clock',
                deferredRequestId: '',
                safeBearerSessionId: true,
            ),
        );
    }

    #[Test]
    public function bearer_safe_shape_unnecessary_when_authenticated(): void
    {
        self::assertNull($this->resolveSseAuthorizationError(
            authenticated: true,
            anonymousAllowed: false,
            demoStream: '',
            deferredRequestId: '',
            safeBearerSessionId: false,
        ));
    }

    #[Test]
    public function bearer_safe_shape_unnecessary_when_anonymous_allowed(): void
    {
        self::assertNull($this->resolveSseAuthorizationError(
            authenticated: false,
            anonymousAllowed: true,
            demoStream: '',
            deferredRequestId: '',
            safeBearerSessionId: false,
        ));
    }

    #[Test]
    public function unsafe_session_id_still_requires_auth_or_anonymous_opt_in(): void
    {
        self::assertSame(
            self::UNSAFE_BEARER_ERROR_MESSAGE,
            $this->resolveSseAuthorizationError(
                authenticated: false,
                anonymousAllowed: false,
                demoStream: '',
                deferredRequestId: '',
                safeBearerSessionId: false,
            ),
        );
    }

    #[Test]
    public function unsafe_session_id_error_message_is_stable(): void
    {
        self::assertSame(
            'Authorization is required for persistent SSE streams. Set SSE_PUBLIC_ANONYMOUS=true to opt in to anonymous persistent streams, or supply a safe-shaped subscriber channel id.',
            $this->resolveSseAuthorizationError(
                authenticated: false,
                anonymousAllowed: false,
                demoStream: '',
                deferredRequestId: '',
                safeBearerSessionId: false,
            ),
        );
    }

    #[Test]
    #[DataProvider('safeBearerSessionIdShapeProvider')]
    public function safe_bearer_session_id_shape_matrix(mixed $rawSessionId, bool $expected): void
    {
        self::assertSame($expected, $this->isSafeBearerSessionId($rawSessionId));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function safeBearerSessionIdShapeProvider(): array
    {
        $hex32 = '0123456789abcdef0123456789abcdef';

        return [
            'valid_lowercase_32_hex' => ['sse_' . $hex32, true],
            'empty_string' => ['', false],
            'null' => [null, false],
            'int' => [42, false],
            'array' => [[], false],
            'bool_false' => [false, false],
            'uppercase_hex' => ['sse_' . strtoupper($hex32), false],
            'mixed_case_hex' => ['sse_0123456789ABCDEF0123456789abcdef', false],
            'too_short' => ['sse_' . substr($hex32, 0, 31), false],
            'too_long' => ['sse_' . $hex32 . '0', false],
            'trailing_extra' => ['sse_' . $hex32 . 'extra', false],
            'wrong_prefix_ui' => ['ui_' . $hex32, false],
            'wrong_prefix_foo' => ['foo_' . $hex32, false],
            'no_prefix' => [$hex32, false],
            'sse_prefix_only' => ['sse_', false],
            'trailing_lf' => ['sse_' . $hex32 . "\n", false],
            'embedded_crlf_injection' => ['sse_' . $hex32 . "\r\nX-Inject: 1", false],
            'embedded_null_byte' => ['sse_' . "\0" . substr($hex32, 1), false],
            'non_hex_chars' => ['sse_zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz', false],
            'embedded_whitespace' => ['sse_' . $hex32 . ' ' . $hex32, false],
            'uniqid_fallback' => ['sse_64a8b9c2d4e8f.12345678', false],
            'leading_whitespace' => [' sse_' . $hex32, false],
            'trailing_whitespace' => ['sse_' . $hex32 . ' ', false],
        ];
    }

    #[Test]
    public function authenticated_stream_still_requires_same_origin_headers(): void
    {
        self::assertSame(
            [
                'status' => 403,
                'message' => '',
            ],
            $this->resolveSseRequestRejection(
                sameOrigin: false,
                authError: null,
            ),
        );
    }

    #[Test]
    public function transport_mode_explicit_drain_is_resolved_drain(): void
    {
        self::assertSame(
            AsyncResourceSseServer::TRANSPORT_MODE_DRAIN,
            $this->resolveTransportMode(
                rawMode: 'drain',
                authenticated: false,
                anonymousAllowed: false,
                safeBearerSessionId: true,
                deferredRequestId: '',
            ),
        );
    }

    #[Test]
    public function transport_mode_explicit_live_is_resolved_live(): void
    {
        self::assertSame(
            AsyncResourceSseServer::TRANSPORT_MODE_LIVE,
            $this->resolveTransportMode(
                rawMode: 'live',
                authenticated: true,
                anonymousAllowed: false,
                safeBearerSessionId: false,
                deferredRequestId: '',
            ),
        );
    }

    #[Test]
    public function transport_mode_missing_with_deferred_request_id_is_legacy(): void
    {
        self::assertSame(
            'legacy',
            $this->resolveTransportMode(
                rawMode: '',
                authenticated: false,
                anonymousAllowed: false,
                safeBearerSessionId: false,
                deferredRequestId: 'req-123',
            ),
        );
    }

    #[Test]
    public function transport_mode_missing_with_authenticated_session_is_legacy(): void
    {
        self::assertSame(
            'legacy',
            $this->resolveTransportMode(
                rawMode: '',
                authenticated: true,
                anonymousAllowed: false,
                safeBearerSessionId: false,
                deferredRequestId: '',
            ),
        );
    }

    #[Test]
    public function transport_mode_missing_with_public_anonymous_opt_in_is_legacy(): void
    {
        self::assertSame(
            'legacy',
            $this->resolveTransportMode(
                rawMode: '',
                authenticated: false,
                anonymousAllowed: true,
                safeBearerSessionId: false,
                deferredRequestId: '',
            ),
        );
    }

    #[Test]
    public function transport_mode_missing_with_anonymous_bearer_is_drain(): void
    {
        // Key invariant: an anonymous bearer caller that forgot to declare
        // a mode MUST NOT silently become a long-lived live stream.
        self::assertSame(
            AsyncResourceSseServer::TRANSPORT_MODE_DRAIN,
            $this->resolveTransportMode(
                rawMode: '',
                authenticated: false,
                anonymousAllowed: false,
                safeBearerSessionId: true,
                deferredRequestId: '',
            ),
        );
    }

    #[Test]
    public function transport_mode_explicit_drain_overrides_authenticated_default(): void
    {
        self::assertSame(
            AsyncResourceSseServer::TRANSPORT_MODE_DRAIN,
            $this->resolveTransportMode(
                rawMode: 'drain',
                authenticated: true,
                anonymousAllowed: false,
                safeBearerSessionId: false,
                deferredRequestId: '',
            ),
        );
    }

    #[Test]
    public function transport_mode_unknown_value_returns_null_for_400(): void
    {
        // Explicit unknown ⇒ caller emits a 400. Do NOT silently normalize.
        self::assertNull($this->resolveTransportMode(
            rawMode: 'foo',
            authenticated: true,
            anonymousAllowed: true,
            safeBearerSessionId: true,
            deferredRequestId: 'req-123',
        ));
    }

    #[Test]
    #[DataProvider('unknownTransportModeProvider')]
    public function transport_mode_unknown_shapes_are_rejected(string $rawMode): void
    {
        self::assertNull($this->resolveTransportMode(
            rawMode: $rawMode,
            authenticated: false,
            anonymousAllowed: false,
            safeBearerSessionId: true,
            deferredRequestId: '',
        ));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownTransportModeProvider(): array
    {
        return [
            'uppercase_drain' => ['DRAIN'],
            'uppercase_live'  => ['LIVE'],
            'mixed_case'      => ['Drain'],
            'typo_drai'       => ['drai'],
            'typo_livee'      => ['livee'],
            'random'          => ['xyz'],
            'numeric'         => ['1'],
            'whitespace_pad'  => [' drain'],
            'trailing_lf'     => ["drain\n"],
            'crlf_injection'  => ["drain\r\nX-Inject: 1"],
            'null_byte'       => ["drain\0"],
        ];
    }

    #[Test]
    public function transport_mode_drain_with_deferred_request_id_yields_drain_but_branch_defers(): void
    {
        // The resolver returns 'drain' on raw value match — the handler
        // then short-circuits the drain branch when deferred_request_id is
        // set so deferred SSR streaming retains its own done/close
        // semantics. The resolver does not encode that policy; the
        // handler does. This test pins the resolver contract.
        self::assertSame(
            AsyncResourceSseServer::TRANSPORT_MODE_DRAIN,
            $this->resolveTransportMode(
                rawMode: 'drain',
                authenticated: false,
                anonymousAllowed: false,
                safeBearerSessionId: true,
                deferredRequestId: 'req-123',
            ),
        );
    }

    #[Test]
    public function drain_complete_payload_satisfies_legacy_close_predicate(): void
    {
        // Defence-in-depth: even if a future caller reuses the drain-
        // complete payload shape inside the live loop, the existing
        // shouldCloseAfterPayload() predicate must already consider it a
        // close signal. Pins the wire contract the drain branch emits.
        $payload = [
            'event'  => 'close',
            'type'   => 'done',
            'close'  => true,
            'live'   => false,
            'reason' => 'drain_complete',
        ];

        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'shouldCloseAfterPayload');
        $method->setAccessible(true);

        self::assertTrue($method->invoke(null, $payload));
    }

    #[Test]
    #[DataProvider('heartbeatDecisionProvider')]
    public function heartbeat_fires_only_after_the_idle_interval_elapses(
        int $now,
        int $lastWriteAt,
        int $intervalSeconds,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->shouldSendHeartbeat($now, $lastWriteAt, $intervalSeconds),
        );
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: bool}>
     */
    public static function heartbeatDecisionProvider(): array
    {
        return [
            // now, lastWriteAt, interval, expected
            'idle_below_interval'      => [1_000_010, 1_000_000, 20, false],
            'idle_one_short'           => [1_000_019, 1_000_000, 20, false],
            'idle_exactly_at_interval' => [1_000_020, 1_000_000, 20, true],
            'idle_past_interval'       => [1_000_045, 1_000_000, 20, true],
            'just_wrote'               => [1_000_000, 1_000_000, 20, false],
            'disabled_zero_interval'   => [9_999_999, 0,         0,  false],
            'disabled_negative'        => [9_999_999, 0,         -1, false],
        ];
    }

    #[Test]
    public function encode_session_queue_preserves_order_and_skips_unencodable(): void
    {
        $queue = [
            ['type' => 'a', 'n' => 1],
            'not-an-array',          // dropped: not an array
            ['type' => 'b', 's' => 'slash/and-ünïcode'],
            42,                      // dropped: not an array
            ['type' => 'c'],
        ];

        $encoded = $this->encodeSessionQueueForRedis($queue);

        self::assertSame(
            [
                '{"type":"a","n":1}',
                // JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE mirror deliver()
                '{"type":"b","s":"slash/and-ünïcode"}',
                '{"type":"c"}',
            ],
            $encoded,
        );
    }

    #[Test]
    public function encode_session_queue_empty_in_empty_out(): void
    {
        self::assertSame([], $this->encodeSessionQueueForRedis([]));
        self::assertSame([], $this->encodeSessionQueueForRedis(['x', 1, null]));
    }

    #[Test]
    public function session_coroutine_cancellation_clears_registry_and_stops_worker(): void
    {
        if (!extension_loaded('swoole') || !function_exists('Co\\run') || !class_exists(Channel::class)) {
            self::markTestSkipped('Swoole coroutine runtime is required for this test.');
        }

        $sessionId = 'test-session';
        $started = new Channel(1);
        $finished = new Channel(1);
        $property = new \ReflectionProperty(AsyncResourceSseServer::class, 'sessionCoroutines');
        $property->setAccessible(true);
        $cancelMethod = new \ReflectionMethod(AsyncResourceSseServer::class, 'cancelSessionCoroutines');
        $cancelMethod->setAccessible(true);

        try {
            \Co\run(function () use ($sessionId, $started, $finished, $property, $cancelMethod): void {
                $cid = AsyncResourceSseServer::createSessionCoroutine(function () use ($started, $finished): void {
                    $started->push(true);
                    try {
                        while (true) {
                            \Swoole\Coroutine::sleep(0.01);
                        }
                    } finally {
                        $finished->push(true);
                    }
                }, $sessionId);

                self::assertIsInt($cid);
                self::assertTrue($started->pop(1.0));

                $registered = $property->getValue();
                self::assertArrayHasKey($sessionId, $registered);
                self::assertArrayHasKey($cid, $registered[$sessionId]);

                $cancelMethod->invoke(null, $sessionId);

                self::assertTrue($finished->pop(1.0));

                \Swoole\Coroutine::sleep(0.02);
                self::assertSame([], $property->getValue());
            });
        } finally {
            $property->setValue(null, []);
        }
    }

    private function resolveSseAuthorizationError(
        bool $authenticated,
        bool $anonymousAllowed,
        string $demoStream,
        string $deferredRequestId,
        bool $safeBearerSessionId,
        bool $persistentRequested = false,
    ): ?string {
        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'resolveSseAuthorizationError');
        $method->setAccessible(true);

        $result = $method->invoke(
            null,
            $authenticated,
            $anonymousAllowed,
            $demoStream,
            $deferredRequestId,
            $safeBearerSessionId,
            $persistentRequested,
        );

        return is_string($result) ? $result : null;
    }

    private function isSafeBearerSessionId(mixed $rawSessionId): bool
    {
        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'isSafeBearerSessionId');
        $method->setAccessible(true);

        return (bool) $method->invoke(null, $rawSessionId);
    }

    private function shouldSendHeartbeat(int $now, int $lastWriteAt, int $intervalSeconds): bool
    {
        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'shouldSendHeartbeat');
        $method->setAccessible(true);

        return (bool) $method->invoke(null, $now, $lastWriteAt, $intervalSeconds);
    }

    /**
     * @param list<mixed> $queue
     * @return list<string>
     */
    private function encodeSessionQueueForRedis(array $queue): array
    {
        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'encodeSessionQueueForRedis');
        $method->setAccessible(true);

        /** @var list<string> $result */
        $result = $method->invoke(null, $queue);

        return $result;
    }

    /**
     * @return array{status: int, message: string}|null
     */
    private function resolveSseRequestRejection(bool $sameOrigin, ?string $authError): ?array
    {
        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'resolveSseRequestRejection');
        $method->setAccessible(true);

        $result = $method->invoke(
            null,
            $sameOrigin,
            $authError,
        );

        return is_array($result) ? $result : null;
    }

    private function resolveTransportMode(
        string $rawMode,
        bool $authenticated,
        bool $anonymousAllowed,
        bool $safeBearerSessionId,
        string $deferredRequestId,
    ): ?string {
        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'resolveTransportMode');
        $method->setAccessible(true);

        $result = $method->invoke(
            null,
            $rawMode,
            $authenticated,
            $anonymousAllowed,
            $safeBearerSessionId,
            $deferredRequestId,
        );

        return is_string($result) ? $result : null;
    }
}
