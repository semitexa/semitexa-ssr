<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseTransportModePolicy;

/**
 * Direct tests for the extracted stream-lifetime policy.
 *
 * The mode table was previously exercised through reflection from
 * {@see AsyncResourceSseServerTest}; those cases still run there against this
 * class. What this file adds is the whole table as a single data provider —
 * every row of the documented matrix, including the rows nobody had asserted —
 * plus the heartbeat and close predicates at their boundaries.
 */
final class SseTransportModePolicyTest extends TestCase
{
    /**
     * The complete mode table from {@see SseTransportModePolicy::resolveMode()}.
     * If the policy and its docblock ever disagree, this provider is the tiebreak.
     */
    #[Test]
    #[DataProvider('modeTable')]
    public function mode_table_is_honoured(
        string $rawMode,
        string $deferredRequestId,
        bool $authenticated,
        bool $anonymousAllowed,
        bool $safeBearer,
        ?string $expected,
    ): void {
        self::assertSame($expected, (new SseTransportModePolicy())->resolveMode(
            $rawMode,
            $authenticated,
            $anonymousAllowed,
            $safeBearer,
            $deferredRequestId,
        ));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool, 3: bool, 4: bool, 5: ?string}>
     */
    public static function modeTable(): iterable
    {
        $drain = SseTransportModePolicy::MODE_DRAIN;
        $live = SseTransportModePolicy::MODE_LIVE;
        $legacy = SseTransportModePolicy::MODE_LEGACY;

        // An explicit mode always wins, whatever the admit context.
        yield 'explicit drain, bare context' => ['drain', '', false, false, false, $drain];
        yield 'explicit drain, authenticated' => ['drain', 'req-1', true, true, true, $drain];
        yield 'explicit live, bare context' => ['live', '', false, false, false, $live];
        yield 'explicit live, authenticated' => ['live', 'req-1', true, true, true, $live];

        // No mode marker: deferred / authenticated / opted-in all keep the
        // pre-existing long-lived loop.
        yield 'no mode, deferred' => ['', 'req-1', false, false, false, $legacy];
        yield 'no mode, authenticated' => ['', '', true, false, false, $legacy];
        yield 'no mode, anonymous allowed' => ['', '', false, true, false, $legacy];

        // The load-bearing row: a guest page that forgot the marker but carries
        // a bearer id is downgraded to drain rather than silently getting a
        // long-lived stream.
        yield 'no mode, bearer only' => ['', '', false, false, true, $drain];

        // Deferred outranks the bearer downgrade — a deferred stream needs the
        // loop to deliver its blocks.
        yield 'no mode, bearer AND deferred' => ['', 'req-1', false, false, true, $legacy];

        // Defensive row: the auth gate rejects this combination upstream, so
        // reaching it means something changed — treat it conservatively.
        yield 'no mode, nothing at all' => ['', '', false, false, false, $legacy];

        // Anything else is a typo and must not degrade silently.
        yield 'unknown mode' => ['stream', '', true, true, true, null];
        yield 'mode is case sensitive' => ['LIVE', '', true, false, false, null];
        yield 'mode with whitespace is not trimmed' => [' live', '', true, false, false, null];
    }

    #[Test]
    #[DataProvider('heartbeatCases')]
    public function heartbeat_fires_on_or_after_the_interval(
        int $now,
        int $lastWriteAt,
        int $interval,
        bool $expected,
    ): void {
        self::assertSame($expected, (new SseTransportModePolicy())->shouldSendHeartbeat($now, $lastWriteAt, $interval));
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: int, 3: bool}>
     */
    public static function heartbeatCases(): iterable
    {
        yield 'exactly at the interval' => [120, 100, 20, true];
        yield 'past the interval' => [121, 100, 20, true];
        yield 'one second short' => [119, 100, 20, false];
        yield 'just written' => [100, 100, 20, false];
        yield 'zero interval disables heartbeats' => [9999, 0, 0, false];
        yield 'negative interval disables heartbeats' => [9999, 0, -1, false];
        yield 'clock skew backwards does not fire' => [90, 100, 20, false];
    }

    #[Test]
    #[DataProvider('closeCases')]
    public function close_predicate_only_ever_triggers_on_done(array $frame, bool $expected, string $because): void
    {
        self::assertSame($expected, (new SseTransportModePolicy())->shouldCloseAfterPayload($frame), $because);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: bool, 2: string}>
     */
    public static function closeCases(): iterable
    {
        yield 'done with explicit close' => [
            ['type' => 'done', 'close' => true],
            true,
            'the drain branch wire contract',
        ];

        yield 'done, silent about liveness' => [
            ['type' => 'done'],
            true,
            'a done frame closes unless it claims to be live',
        ];

        yield 'done, explicitly live' => [
            ['type' => 'done', 'live' => true],
            false,
            'a live stream survives its own done frame',
        ];

        yield 'done, live true AND close true' => [
            ['type' => 'done', 'close' => true, 'live' => true],
            true,
            'an explicit close outranks the live marker',
        ];

        yield 'not a done frame' => [
            ['type' => 'update', 'close' => true],
            false,
            'only done frames can close, even one asking to',
        ];

        yield 'no type at all' => [[], false, 'an untyped frame never closes'];

        yield 'live is truthy but not true' => [
            ['type' => 'done', 'live' => 1],
            true,
            'the check is strict — only boolean true keeps the stream open',
        ];
    }

    #[Test]
    public function connection_age_cap_falls_back_to_the_default(): void
    {
        // No SSE_MAX_CONNECTION_AGE_SECONDS is set in the test environment, so
        // this pins the shipped default the orphan sweeper also derives from.
        self::assertSame(600, (new SseTransportModePolicy())->maxConnectionAgeSeconds());
    }
}
