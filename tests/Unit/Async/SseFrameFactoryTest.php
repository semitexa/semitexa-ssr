<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseFrameFactory;
use Semitexa\Ssr\Application\Service\Async\SsePassthroughEvent;

/**
 * Direct tests for the extracted wire-shape factory.
 *
 * The precedence between the three ways a frame can name its `event:` was the
 * densest branch in the write path and had no test of its own — only indirect
 * coverage through frame-level tests. This pins each rule and, more
 * importantly, the security posture shared by all of them: an unrecognized
 * discriminator is dropped and stripped, never promoted to an event name a
 * client would dispatch on.
 */
final class SseFrameFactoryTest extends TestCase
{
    #[Test]
    public function passthrough_outranks_type_and_legacy_event(): void
    {
        [$event, $body] = (new SseFrameFactory())->resolveEventName([
            SsePassthroughEvent::KEY => 'next',
            '_type' => 'ui.patch',
            'event' => 'legacy',
            'payload' => 1,
        ]);

        self::assertSame('next', $event);
        self::assertArrayNotHasKey(SsePassthroughEvent::KEY, $body, 'the consumed key is stripped');
        self::assertSame('ui.patch', $body['_type'] ?? null, 'lower-precedence keys are left alone');
        self::assertSame('legacy', $body['event'] ?? null);
    }

    #[Test]
    #[DataProvider('rejectedPassthroughValues')]
    public function an_unrecognized_passthrough_value_is_dropped_not_promoted(mixed $value): void
    {
        [$event, $body] = (new SseFrameFactory())->resolveEventName([
            SsePassthroughEvent::KEY => $value,
            'payload' => 1,
        ]);

        self::assertNull($event, 'an out-of-vocabulary value must never become an event name');
        self::assertArrayNotHasKey(SsePassthroughEvent::KEY, $body, 'and is stripped from the body either way');
        self::assertSame(['payload' => 1], $body);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function rejectedPassthroughValues(): iterable
    {
        yield 'not in the vocabulary' => ['arbitrary'];
        yield 'a UI type is not a passthrough value' => ['ui.patch'];
        yield 'wrong case' => ['NEXT'];
        yield 'empty string' => [''];
        yield 'not a string' => [42];
        yield 'null' => [null];
        yield 'array' => [['next']];
    }

    #[Test]
    #[DataProvider('allowedPassthroughValues')]
    public function every_declared_passthrough_value_is_honoured(string $value): void
    {
        [$event] = (new SseFrameFactory())->resolveEventName([SsePassthroughEvent::KEY => $value]);

        self::assertSame($value, $event);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function allowedPassthroughValues(): iterable
    {
        // Driven off the declared vocabulary so adding a member without
        // thinking about the wire contract shows up here.
        foreach (SsePassthroughEvent::ALLOWED as $value) {
            yield $value => [$value];
        }
    }

    #[Test]
    public function a_known_type_becomes_the_event_and_stays_in_the_body(): void
    {
        [$event, $body] = (new SseFrameFactory())->resolveEventName(['_type' => 'ui.patch', 'html' => '<b/>']);

        self::assertSame('ui.patch', $event);
        self::assertSame('ui.patch', $body['_type'] ?? null, '_type is a discriminator the client also reads in-body');
    }

    #[Test]
    public function an_unknown_type_is_stripped_and_emits_no_event(): void
    {
        [$event, $body] = (new SseFrameFactory())->resolveEventName(['_type' => 'ui.evil', 'html' => '<b/>']);

        self::assertNull($event);
        self::assertArrayNotHasKey('_type', $body);
        self::assertSame(['html' => '<b/>'], $body);
    }

    #[Test]
    #[DataProvider('malformedTypes')]
    public function a_malformed_type_is_stripped_and_never_falls_through_to_legacy(mixed $rawType): void
    {
        // Note the asymmetry worth knowing about: a present-but-malformed
        // `_type` suppresses the legacy `event` key entirely rather than
        // falling back to it.
        [$event, $body] = (new SseFrameFactory())->resolveEventName([
            '_type' => $rawType,
            'event' => 'legacy',
        ]);

        self::assertNull($event);
        self::assertArrayNotHasKey('_type', $body);
        self::assertSame('legacy', $body['event'] ?? null, 'the legacy key survives in the body, unused');
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function malformedTypes(): iterable
    {
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'integer' => [7];
        yield 'array' => [['ui.patch']];
    }

    #[Test]
    public function the_legacy_event_key_is_the_last_resort(): void
    {
        [$event, $body] = (new SseFrameFactory())->resolveEventName(['event' => 'close', 'reason' => 'drain']);

        self::assertSame('close', $event, 'free-form legacy names are NOT vocabulary-checked');
        self::assertSame(['event' => 'close', 'reason' => 'drain'], $body);
    }

    #[Test]
    public function a_frame_with_no_discriminator_gets_no_event_name(): void
    {
        [$event, $body] = (new SseFrameFactory())->resolveEventName(['rows' => []]);

        self::assertNull($event);
        self::assertSame(['rows' => []], $body);
    }

    #[Test]
    public function an_empty_legacy_event_is_ignored(): void
    {
        [$event] = (new SseFrameFactory())->resolveEventName(['event' => '']);

        self::assertNull($event);
    }

    #[Test]
    public function build_carries_the_id_across_as_a_string(): void
    {
        $frame = (new SseFrameFactory())->build(['id' => 42, '_type' => 'ui.patch']);

        self::assertStringContainsString('id: 42', $frame->toWire());
        self::assertStringContainsString('event: ui.patch', $frame->toWire());
    }

    #[Test]
    public function build_omits_the_id_line_when_there_is_no_id(): void
    {
        $frame = (new SseFrameFactory())->build(['_type' => 'ui.patch']);

        self::assertStringNotContainsString('id:', $frame->toWire());
    }
}
