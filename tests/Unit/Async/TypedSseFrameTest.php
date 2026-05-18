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
 * `AsyncResourceSseServer::composeSseFrame()` (private static, reached
 * via Reflection — same pattern as the existing
 * `AsyncResourceSseServerTest::session_coroutine_cancellation_…` test).
 */
final class TypedSseFrameTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private static function compose(array $data): string
    {
        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'composeSseFrame');
        $method->setAccessible(true);
        $frame = $method->invoke(null, $data);
        self::assertIsString($frame);
        return $frame;
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
}
