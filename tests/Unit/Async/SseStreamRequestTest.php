<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\SseStreamRequest;

/**
 * Direct tests for {@see SseStreamRequest}, extracted from the head of
 * AsyncResourceSseServer::handleSse by ep-slay-sse-god-class-2 (tk-sse2-admission).
 *
 * Parsing looks trivial until you notice how much rides on it: whether a session
 * is reused or minted, and whether the request counts as *persistent* — the flag
 * that decides if a client may borrow the deferred door's guest-permissive
 * bypass. Those were inline expressions in a 92-statement method and had no
 * direct coverage.
 */
final class SseStreamRequestTest extends TestCase
{
    #[Test]
    public function a_supplied_session_id_is_reused(): void
    {
        // This is how a reconnecting client rejoins its own queue instead of
        // starting a fresh, empty session.
        $stream = SseStreamRequest::fromRequest(self::request(['session_id' => 'sse_abc']));

        self::assertSame('sse_abc', $stream->sessionId);
        self::assertSame('sse_abc', $stream->rawSessionId);
    }

    #[Test]
    public function a_missing_session_id_is_minted_rather_than_refused(): void
    {
        $stream = SseStreamRequest::fromRequest(self::request([]));

        self::assertNotSame('', $stream->sessionId);
        self::assertStringStartsWith('sse_', $stream->sessionId);
        self::assertNull($stream->rawSessionId, 'the raw value stays null for the bearer-shape check');
    }

    #[Test]
    public function an_empty_session_id_is_treated_as_absent(): void
    {
        // '' is falsy, so it mints rather than producing a session keyed on the
        // empty string — which every other session would then collide with.
        $stream = SseStreamRequest::fromRequest(self::request(['session_id' => '']));

        self::assertStringStartsWith('sse_', $stream->sessionId);
    }

    #[Test]
    public function an_array_session_id_is_treated_as_absent(): void
    {
        // `?session_id[]=x` hands Swoole an array. Casting it would warn and yield
        // the literal 'Array' — one queue key shared by every request shaped that
        // way, so one client could read another's frames.
        $stream = SseStreamRequest::fromRequest(self::request(['session_id' => ['x']]));

        self::assertStringStartsWith('sse_', $stream->sessionId);
        self::assertNotSame('Array', $stream->sessionId);
        self::assertNull($stream->rawSessionId, 'a non-string raw value must not reach the bearer-shape check');
    }

    #[Test]
    public function a_minted_session_id_has_the_accepted_channel_shape(): void
    {
        // Minted ids must satisfy the same sse_<32hex> shape the server accepts as
        // a channel id; uniqid() produced a '.' and did not.
        $stream = SseStreamRequest::fromRequest(self::request([]));

        self::assertMatchesRegularExpression('/\Asse_[a-f0-9]{32}\z/', $stream->sessionId);
    }

    #[Test]
    public function absent_parameters_read_as_empty_strings(): void
    {
        $stream = SseStreamRequest::fromRequest(self::request([]));

        self::assertSame('', $stream->demoStream);
        self::assertSame('', $stream->deferredRequestId);
        self::assertSame('', $stream->rawMode);
        self::assertFalse($stream->hasDemoStream());
        self::assertFalse($stream->hasDeferredRequest());
        self::assertNull($stream->lastEventId);
    }

    #[Test]
    public function surrounding_whitespace_is_stripped(): void
    {
        $stream = SseStreamRequest::fromRequest(self::request([
            'deferred_request_id' => '  dr_1  ',
            'mode' => "  live\n",
            'demo_stream' => ' showcase ',
        ]));

        self::assertSame('dr_1', $stream->deferredRequestId);
        self::assertSame('live', $stream->rawMode);
        self::assertSame('showcase', $stream->demoStream);
        self::assertTrue($stream->isPersistentRequested(), 'a padded mode=live is still mode=live');
    }

    #[Test]
    public function only_an_explicit_live_mode_counts_as_persistent(): void
    {
        self::assertTrue(
            SseStreamRequest::fromRequest(self::request(['mode' => AsyncResourceSseServer::TRANSPORT_MODE_LIVE]))
                ->isPersistentRequested(),
        );

        foreach (['', AsyncResourceSseServer::TRANSPORT_MODE_DRAIN, 'LIVE', 'legacy'] as $mode) {
            self::assertFalse(
                SseStreamRequest::fromRequest(self::request(['mode' => $mode]))->isPersistentRequested(),
                "mode '{$mode}' must not be treated as persistent",
            );
        }
    }

    #[Test]
    public function a_deferred_request_asking_for_live_is_still_persistent(): void
    {
        // The bypass this guards: a deferred request is admitted for guests
        // because its pipeline closes the channel when delivery finishes. An
        // explicit mode=live never ends on its own, so it must NOT inherit that
        // leniency just by carrying a deferred id alongside.
        $stream = SseStreamRequest::fromRequest(self::request([
            'deferred_request_id' => 'dr_1',
            'mode' => AsyncResourceSseServer::TRANSPORT_MODE_LIVE,
        ]));

        self::assertTrue($stream->hasDeferredRequest());
        self::assertTrue($stream->isPersistentRequested());
    }

    #[Test]
    public function the_resume_point_is_read_from_the_last_event_id_header(): void
    {
        $stream = SseStreamRequest::fromRequest(self::request([], ['last-event-id' => 'evt-42']));

        self::assertSame('evt-42', $stream->lastEventId);
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $header
     */
    private static function request(array $get, array $header = []): object
    {
        return new class ($get, $header) {
            /**
             * @param array<string, mixed> $get
             * @param array<string, mixed> $header
             */
            public function __construct(public array $get, public array $header)
            {
            }
        };
    }
}
