<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\UiEvent\UiComponentStateMessage;
use Semitexa\Ssr\Application\Service\UiEvent\UiErrorMessage;
use Semitexa\Ssr\Application\Service\UiEvent\UiPatchMessage;

/**
 * Wire-format tests for the `_type` chokepoint introduced in Phase 2 of
 * the SSE KISS typed-message work. The chokepoint lives in
 * `AsyncResourceSseServer::buildFrame()` (private static, reached via
 * Reflection — same pattern as the existing
 * `AsyncResourceSseServerTest::session_coroutine_cancellation_…` test),
 * which resolves/validates the event name on the SSR consumer boundary and
 * returns a portable `Semitexa\Core\Server\SseFrame`; the mechanical
 * byte composition is then rendered by `SseFrame::toWire()` in core. This
 * test exercises the combined path so the wire output stays byte-identical.
 */
final class TypedSseFrameTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private static function compose(array $data): string
    {
        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'buildFrame');
        $method->setAccessible(true);
        $frame = $method->invoke(null, $data);
        self::assertInstanceOf(\Semitexa\Core\Server\SseFrame::class, $frame);
        return $frame->toWire();
    }

    #[Test]
    public function payload_without_type_emits_no_event_line_preserving_default_message_path(): void
    {
        $frame = self::compose([
            'type'    => 'deferred_block',
            'mode'    => 'html',
            'slot_id' => 'leads',
            'html'    => '<div>ok</div>',
        ]);

        self::assertStringNotContainsString("event: ", $frame);
        self::assertStringContainsString('data: ', $frame);
        self::assertStringEndsWith("\n\n", $frame);
    }

    #[Test]
    public function payload_without_type_preserves_existing_event_field_byte_for_byte(): void
    {
        // Mirrors what the demo producer pushes via deliver().
        $frame = self::compose([
            'id'    => 'demo_attached_abcd',
            'event' => 'notification',
            'level' => 'info',
            'title' => 'Stream attached',
        ]);

        self::assertStringContainsString("id: demo_attached_abcd\n", $frame);
        self::assertStringContainsString("event: notification\n", $frame);
        self::assertStringContainsString('"event":"notification"', $frame);
    }

    #[Test]
    public function ssr_fragment_type_promotes_to_event_line(): void
    {
        $frame = self::compose([
            '_type'   => 'ssr.fragment',
            'slot_id' => 'leads',
            'html'    => '<div>x</div>',
        ]);

        self::assertStringContainsString("event: ssr.fragment\n", $frame);
        self::assertStringContainsString('"_type":"ssr.fragment"', $frame);
    }

    #[Test]
    public function ui_patch_type_promotes_to_event_line(): void
    {
        $message = new UiPatchMessage('cmp-1', ['v' => 42]);
        $frame   = self::compose($message->toSsePayload());

        self::assertStringContainsString("event: ui.patch\n", $frame);
        self::assertStringContainsString('"componentInstanceId":"cmp-1"', $frame);
        self::assertStringContainsString('"patch":{"v":42}', $frame);
    }

    #[Test]
    public function ui_component_state_type_promotes_to_event_line(): void
    {
        $message = new UiComponentStateMessage('grid-leads', ['rows' => []]);
        $frame   = self::compose($message->toSsePayload());

        self::assertStringContainsString("event: ui.componentState\n", $frame);
        self::assertStringContainsString('"state":{"rows":[]}', $frame);
    }

    #[Test]
    public function ui_error_type_promotes_to_event_line_and_carries_safe_body(): void
    {
        $message = new UiErrorMessage('validation_failed', 'Field is required.', 'corr-1');
        $frame   = self::compose($message->toSsePayload());

        self::assertStringContainsString("event: ui.error\n", $frame);
        self::assertStringContainsString('"reason":"validation_failed"', $frame);
        self::assertStringContainsString('"message":"Field is required."', $frame);
        self::assertStringContainsString('"correlationId":"corr-1"', $frame);
        // ui.error must NOT leak FQCNs / traces / stack-like markers.
        self::assertStringNotContainsString('Exception', $frame);
        self::assertStringNotContainsString('Stack trace', $frame);
        self::assertStringNotContainsString('#0 /', $frame);
    }

    #[Test]
    public function unknown_type_is_dropped_and_no_event_line_is_emitted(): void
    {
        $frame = self::compose([
            '_type' => 'evil.spoofed',
            'note'  => 'should still land as default-message event',
        ]);

        self::assertStringNotContainsString("event: ", $frame);
        self::assertStringNotContainsString('"_type"', $frame);
        self::assertStringNotContainsString('evil.spoofed', $frame);
        self::assertStringContainsString('"note":"should still land as default-message event"', $frame);
    }

    #[Test]
    public function unknown_type_does_not_fall_back_to_caller_supplied_event_name(): void
    {
        $frame = self::compose([
            '_type' => 'evil.spoofed',
            'event' => 'attacker.spoofed',
            'note'  => 'still delivered as default-message event',
        ]);

        self::assertStringNotContainsString("event: attacker.spoofed\n", $frame);
        self::assertStringNotContainsString("event: evil.spoofed\n", $frame);
        self::assertStringNotContainsString('"_type"', $frame);
        self::assertStringContainsString('"event":"attacker.spoofed"', $frame);
        self::assertStringContainsString('"note":"still delivered as default-message event"', $frame);
    }

    #[Test]
    public function newline_injection_in_type_cannot_inject_arbitrary_sse_headers(): void
    {
        $frame = self::compose([
            '_type' => "ui.patch\nevent: spoof\ndata: stolen",
            'note'  => 'attacker payload',
        ]);

        // CR/LF-bearing `_type` is not an allow-list match → dropped.
        // No injected event line, no injected data line.
        self::assertStringNotContainsString("event: spoof", $frame);
        self::assertStringNotContainsString("data: stolen", $frame);
        self::assertStringNotContainsString("event: ui.patch\n", $frame);
    }

    #[Test]
    public function typed_type_overrides_caller_supplied_event_field(): void
    {
        $frame = self::compose([
            '_type' => 'ui.patch',
            'event' => 'attacker.spoofed',
            'patch' => ['v' => 1],
        ]);

        self::assertStringContainsString("event: ui.patch\n", $frame);
        // The caller-supplied `event` must not become the wire event name.
        self::assertStringNotContainsString("event: attacker.spoofed", $frame);
    }

    #[Test]
    public function id_field_is_still_sanitised_and_present_on_typed_payloads(): void
    {
        $frame = self::compose([
            'id'    => "42\nevent: spoof",
            '_type' => 'ssr.fragment',
            'html'  => '<x/>',
        ]);

        self::assertStringContainsString("id: 42event: spoof\n", $frame);
        self::assertStringContainsString("event: ssr.fragment\n", $frame);
        // Only the one legitimate event line.
        self::assertSame(1, substr_count($frame, "\nevent: "));
    }

    #[Test]
    public function malformed_non_string_type_is_stripped_safely(): void
    {
        $frame = self::compose([
            '_type' => ['nested' => 'value'],
            'note'  => 'still delivered',
        ]);

        self::assertStringNotContainsString("event: ", $frame);
        self::assertStringNotContainsString('"_type"', $frame);
        self::assertStringContainsString('"note":"still delivered"', $frame);
    }

    #[Test]
    public function frame_ends_with_blank_line_per_sse_protocol(): void
    {
        $frame = self::compose([
            '_type' => 'ui.patch',
            'patch' => ['v' => 1],
        ]);

        self::assertStringEndsWith("\n\n", $frame);
    }

    #[Test]
    public function json_encode_failure_falls_back_to_empty_object_instead_of_malformed_frame(): void
    {
        // Invalid UTF-8 makes json_encode throw under JSON_THROW_ON_ERROR; the
        // chokepoint must NOT let `false`/malformed JSON reach the wire.
        $frame = self::compose([
            '_type' => 'ui.patch',
            'patch' => ['bad' => "\xC3\x28"],
        ]);

        self::assertStringContainsString("event: ui.patch\n", $frame);
        self::assertStringContainsString("data: {}\n\n", $frame);
        self::assertStringNotContainsString('data: false', $frame);
    }

    // ---------------------------------------------------------------------
    // Phase 2 (PROMPT 117) Step 2.0 — opt-in passthrough frame.
    // A frame carrying `_sse_event` (∈ {next, complete, error}) emits the
    // event line AND strips the key so the body renders bare (graphql-sse
    // ExecutionResult shape). Absent ⇒ existing logic byte-identical.
    // ---------------------------------------------------------------------

    #[Test]
    public function passthrough_next_emits_bare_execution_result_body(): void
    {
        $frame = self::compose([
            '_sse_event' => 'next',
            'data'       => ['x' => 1],
            'errors'     => [],
        ]);

        // Exact wire: event line + bare {data,errors} body, no discriminator key.
        self::assertSame("event: next\ndata: {\"data\":{\"x\":1},\"errors\":[]}\n\n", $frame);
        self::assertStringNotContainsString('_sse_event', $frame);
    }

    #[Test]
    public function passthrough_accepts_complete_and_error_vocabulary(): void
    {
        $complete = self::compose(['_sse_event' => 'complete']);
        self::assertSame("event: complete\ndata: []\n\n", $complete);
        self::assertStringNotContainsString('_sse_event', $complete);

        $error = self::compose(['_sse_event' => 'error', 'errors' => [['message' => 'boom']]]);
        self::assertStringContainsString("event: error\n", $error);
        self::assertStringContainsString('"errors":[{"message":"boom"}]', $error);
        self::assertStringNotContainsString('_sse_event', $error);
    }

    #[Test]
    public function passthrough_out_of_vocabulary_value_emits_no_event_line_and_strips_key(): void
    {
        $frame = self::compose([
            '_sse_event' => 'bogus',
            'data'       => ['x' => 1],
        ]);

        // Invalid value: no event line, key stripped, remaining body preserved.
        self::assertStringNotContainsString("event: ", $frame);
        self::assertStringNotContainsString('_sse_event', $frame);
        self::assertStringNotContainsString('bogus', $frame);
        self::assertStringContainsString('"data":{"x":1}', $frame);
    }

    #[Test]
    public function passthrough_non_string_value_is_stripped_safely(): void
    {
        $frame = self::compose([
            '_sse_event' => ['nested' => 'value'],
            'note'       => 'still delivered',
        ]);

        self::assertStringNotContainsString("event: ", $frame);
        self::assertStringNotContainsString('_sse_event', $frame);
        self::assertStringContainsString('"note":"still delivered"', $frame);
    }

    // ---------------------------------------------------------------------
    // Byte-identical regression — the load-bearing proof that the Step 2.0
    // branch is purely additive. These frames set neither passthrough key, so
    // they must render EXACTLY as before the change. Asserted as full-string
    // equality (not substring) for the three representative frame families.
    // ---------------------------------------------------------------------

    #[Test]
    public function regression_collection_data_frame_is_byte_identical(): void
    {
        $frame = self::compose([
            '_type' => 'ui.collection.data',
            'id'    => 'grid-leads',
            'data'  => [['name' => 'Ada']],
            'meta'  => ['pagination' => ['mode' => 'page']],
        ]);

        self::assertSame(
            "id: grid-leads\n"
            . "event: ui.collection.data\n"
            . "data: {\"_type\":\"ui.collection.data\",\"id\":\"grid-leads\",\"data\":[{\"name\":\"Ada\"}],\"meta\":{\"pagination\":{\"mode\":\"page\"}}}\n\n",
            $frame,
        );
    }

    #[Test]
    public function deleted_v1_grid_type_no_longer_promotes_to_an_event_line(): void
    {
        // `ui.grid.data` left the allow-list in the One Way Phase 6 sweep —
        // it must now behave exactly like any unknown `_type`: dropped.
        $frame = self::compose([
            '_type' => 'ui.grid.data',
            'rows'  => [['name' => 'Ada']],
        ]);

        self::assertStringNotContainsString("event: ", $frame);
        self::assertStringNotContainsString('"_type"', $frame);
    }

    #[Test]
    public function regression_legacy_event_frame_is_byte_identical(): void
    {
        $frame = self::compose([
            'id'    => 'demo_attached_abcd',
            'event' => 'notification',
            'level' => 'info',
            'title' => 'Stream attached',
        ]);

        self::assertSame(
            "id: demo_attached_abcd\n"
            . "event: notification\n"
            . "data: {\"id\":\"demo_attached_abcd\",\"event\":\"notification\",\"level\":\"info\",\"title\":\"Stream attached\"}\n\n",
            $frame,
        );
    }

    #[Test]
    public function regression_stream_id_announce_frame_is_byte_identical(): void
    {
        $frame = self::compose([
            '_type'     => 'ui.stream.id',
            'stream_id' => 'sse_0123456789abcdef0123456789abcdef',
        ]);

        self::assertSame(
            "event: ui.stream.id\n"
            . "data: {\"_type\":\"ui.stream.id\",\"stream_id\":\"sse_0123456789abcdef0123456789abcdef\"}\n\n",
            $frame,
        );
    }
}
