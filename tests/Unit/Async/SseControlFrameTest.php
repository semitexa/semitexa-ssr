<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationSubscriber;
use Semitexa\Ssr\Application\Service\Async\SseControlFrame;

/**
 * The control-plane wire vocabulary.
 *
 * Worth pinning hard: a control marker rides the same queues as data, and three
 * separate drains decide "control or content" from these values. Getting one of
 * them wrong does not fail loudly — it writes `{"__ctrl":"rerun"}` to the browser
 * as if it were a data frame.
 */
final class SseControlFrameTest extends TestCase
{
    #[Test]
    #[DataProvider('recognisedKinds')]
    public function a_string_marker_is_recognised(string $kind): void
    {
        self::assertSame($kind, SseControlFrame::kindOf([SseControlFrame::KEY => $kind, 'x' => 1]));
        self::assertTrue(SseControlFrame::isControl([SseControlFrame::KEY => $kind]));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function recognisedKinds(): iterable
    {
        yield 'rerun' => [SseControlFrame::RERUN];
        yield 'viewchange' => [SseControlFrame::VIEWCHANGE];
        yield 'subscribe' => [SseControlFrame::SUBSCRIBE];
        yield 'unsubscribe' => [SseControlFrame::UNSUBSCRIBE];
    }

    #[Test]
    #[DataProvider('nonControlFrames')]
    public function anything_that_is_not_a_string_marker_is_ordinary_data(array $frame, string $because): void
    {
        self::assertNull(SseControlFrame::kindOf($frame), $because);
        self::assertFalse(SseControlFrame::isControl($frame), $because);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function nonControlFrames(): iterable
    {
        yield 'no marker' => [['type' => 'ui.patch'], 'an ordinary data frame'];
        yield 'empty frame' => [[], 'nothing to read'];
        yield 'marker is true' => [
            [SseControlFrame::KEY => true],
            'a boolean marker is data with an unfortunate key, not a signal',
        ];
        yield 'marker is null' => [[SseControlFrame::KEY => null], 'null is not a kind'];
        yield 'marker is an empty string' => [[SseControlFrame::KEY => ''], 'an empty kind names nothing'];
        yield 'marker is an int' => [[SseControlFrame::KEY => 1], 'only strings name a kind'];
        yield 'marker is an array' => [[SseControlFrame::KEY => ['rerun']], 'only strings name a kind'];
    }

    #[Test]
    public function an_unknown_kind_is_still_reported_so_the_dispatcher_can_refuse_it(): void
    {
        // kindOf() reports the shape, not the vocabulary — the dispatcher decides
        // an unrecognised kind is NOT_CONTROL. Swallowing it here would hide a
        // protocol mismatch instead of surfacing it as an unhandled frame.
        self::assertSame('teleport', SseControlFrame::kindOf([SseControlFrame::KEY => 'teleport']));
    }

    #[Test]
    public function the_outcome_codes_are_distinct(): void
    {
        $outcomes = [
            SseControlFrame::NOT_CONTROL,
            SseControlFrame::HANDLED_CONTINUE,
            SseControlFrame::HANDLED_CLOSE,
        ];

        self::assertSame($outcomes, array_values(array_unique($outcomes)));
    }

    #[Test]
    public function a_rerun_frame_carries_the_stream_and_the_scope_that_caused_it(): void
    {
        self::assertSame(
            ['__ctrl' => 'rerun', 'streaming_id' => 'sse_a', 'scope_key' => 'articles:1'],
            SseControlFrame::rerun('sse_a', 'articles:1'),
        );
    }

    #[Test]
    public function a_view_change_carries_params_only_when_they_are_not_coalesced(): void
    {
        // With a coalescer holding the pending view, the marker deliberately
        // omits params — it means "read the latest view from the coalescer",
        // which is how N rapid changes collapse into one re-run.
        self::assertSame(
            ['__ctrl' => 'viewchange', 'streaming_id' => 'sse_a'],
            SseControlFrame::viewChange('sse_a'),
        );

        self::assertSame(
            ['__ctrl' => 'viewchange', 'streaming_id' => 'sse_a', 'params' => ['page' => 2]],
            SseControlFrame::viewChange('sse_a', ['page' => 2]),
        );
    }

    #[Test]
    public function an_empty_params_array_is_still_transmitted(): void
    {
        // `[]` means "an explicit empty view", which is not the same as "no params
        // supplied" — only null omits the key.
        self::assertArrayHasKey('params', SseControlFrame::viewChange('sse_a', []));
    }

    #[Test]
    public function a_subscribe_frame_defaults_the_method_to_get(): void
    {
        self::assertSame(
            [
                '__ctrl' => 'subscribe',
                'streaming_id' => 'sse_a',
                'route_path' => '/feed',
                'route_method' => 'GET',
                'request_snapshot' => ['q' => 'x'],
            ],
            SseControlFrame::subscribe('sse_a', '/feed', '', ['q' => 'x']),
        );
    }

    #[Test]
    public function a_subscribe_frame_keeps_an_explicit_method(): void
    {
        self::assertSame('POST', SseControlFrame::subscribe('sse_a', '/feed', 'POST', [])['route_method']);
    }

    #[Test]
    public function an_unsubscribe_frame_names_only_the_stream(): void
    {
        self::assertSame(
            ['__ctrl' => 'unsubscribe', 'streaming_id' => 'sse_a'],
            SseControlFrame::unsubscribe('sse_a'),
        );
    }

    #[Test]
    public function every_builder_produces_a_frame_the_reader_recognises(): void
    {
        // Round-trip: whatever the submit side builds, the drain side must
        // classify as control. A builder and a reader that disagree is precisely
        // the bug that writes a control marker to the browser.
        $frames = [
            SseControlFrame::rerun('sse_a', 'scope'),
            SseControlFrame::viewChange('sse_a', ['page' => 1]),
            SseControlFrame::subscribe('sse_a', '/feed', 'GET', []),
            SseControlFrame::unsubscribe('sse_a'),
        ];

        foreach ($frames as $frame) {
            self::assertTrue(SseControlFrame::isControl($frame));
        }
    }

    #[Test]
    public function the_subscriber_shares_the_one_vocabulary(): void
    {
        // ResourceInvalidationSubscriber used to declare its OWN CTRL_KEY /
        // CTRL_RERUN. They are aliases now; this fails if a second independent
        // copy ever reappears and drifts.
        self::assertSame(SseControlFrame::KEY, ResourceInvalidationSubscriber::CTRL_KEY);
        self::assertSame(SseControlFrame::RERUN, ResourceInvalidationSubscriber::CTRL_RERUN);
    }
}
