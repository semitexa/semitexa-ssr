<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\WatchScopes;
use Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseCollectionFeedHandler;

/**
 * One Way Phase 4: the SSE collection serving base's pure seams —
 * the canonical-envelope frame typing (`ui.collection.data` /
 * `ui.collection.error` through the single `_type` chokepoint) and the
 * payload-side `#[WatchScopes]` resolution that replaced the legacy
 * `#[GridFeed]` reverse lookup. The held-open choreography itself is
 * proven live (Phase 4 proof gates a–e); these tests pin the
 * declaration-level contracts.
 */
final class AbstractSseCollectionFeedHandlerTest extends TestCase
{
    #[Test]
    public function a_success_envelope_frames_as_ui_collection_data_with_the_envelope_intact(): void
    {
        $envelope = ['data' => [['id' => 'p1']], 'meta' => ['pagination' => ['mode' => 'page']]];

        $framed = AbstractSseCollectionFeedHandler::frameData($envelope, true);

        self::assertSame('ui.collection.data', $framed['_type']);
        self::assertSame($envelope['data'], $framed['data']);
        self::assertSame($envelope['meta'], $framed['meta']);
    }

    #[Test]
    public function an_error_envelope_frames_as_ui_collection_error(): void
    {
        $framed = AbstractSseCollectionFeedHandler::frameData(
            ['error' => 'invalid_sort', 'message' => 'nope', 'context' => []],
            false,
        );

        self::assertSame('ui.collection.error', $framed['_type']);
        self::assertSame('invalid_sort', $framed['error']);
    }

    #[Test]
    public function the_frame_type_key_wins_over_a_payload_supplied_type_field(): void
    {
        // `['_type' => …] + $envelope` — the chokepoint's `_type` must always
        // win; a row-sourced `_type` can never become the SSE event name.
        $framed = AbstractSseCollectionFeedHandler::frameData(['_type' => 'spoofed', 'data' => []], true);

        self::assertSame('ui.collection.data', $framed['_type']);
    }

    #[Test]
    public function watch_scopes_resolve_from_the_payload_class_declaration(): void
    {
        self::assertSame(
            ['scope_a', 'scope_b'],
            AbstractSseCollectionFeedHandler::watchScopesOf(ScopedFeedPayloadFixture::class),
        );
    }

    #[Test]
    public function a_payload_without_watch_scopes_resolves_to_an_empty_subscription(): void
    {
        self::assertSame(
            [],
            AbstractSseCollectionFeedHandler::watchScopesOf(UnscopedFeedPayloadFixture::class),
        );
    }
}

#[WatchScopes('scope_a', 'scope_b')]
final class ScopedFeedPayloadFixture
{
}

final class UnscopedFeedPayloadFixture
{
}
