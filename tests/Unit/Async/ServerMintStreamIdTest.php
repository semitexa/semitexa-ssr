<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;

/**
 * Stream Lifecycle · Axis 1(b) Phase 2 — the server-authoritative mint.
 *
 * Pins the framework mint helper ({@see AsyncResourceSseServer::mintStreamId()})
 * as the single source of new stream ids: it produces the SAME safe shape every
 * store already keys on, and is high-entropy/unique. The first-frame
 * `ui.stream.id` emission itself needs a live Swoole {@see \Swoole\Http\Response}
 * (proven by the live-curl step in the report), but the mint policy + the typed
 * event name — the parts that must never drift — are pinned here.
 */
final class ServerMintStreamIdTest extends TestCase
{
    #[Test]
    public function minted_id_has_the_safe_bearer_shape_every_store_keys_on(): void
    {
        $id = AsyncResourceSseServer::mintStreamId();

        self::assertSame(
            1,
            preg_match(AsyncResourceSseServer::SAFE_BEARER_SESSION_ID_PATTERN, $id),
            'the server-minted id must satisfy the anti-injection table-key validator',
        );
        // sse_ + 32 hex chars = 36 chars; 128 bits of entropy.
        self::assertSame(36, strlen($id));
    }

    #[Test]
    public function each_mint_is_unique_high_entropy(): void
    {
        $ids = [];
        for ($i = 0; $i < 256; $i++) {
            $ids[AsyncResourceSseServer::mintStreamId()] = true;
        }

        self::assertCount(256, $ids, 'CSPRNG mint must not collide across a batch');
    }

    /**
     * The dedicated first-frame channel exists in the typed allow-list, so the
     * wire-format chokepoint promotes its `_type` to `event: ui.stream.id`.
     */
    #[Test]
    public function ui_stream_id_is_a_typed_allowlisted_event(): void
    {
        self::assertSame('ui.stream.id', UiSseEventType::UiStreamId->value);
        self::assertTrue(UiSseEventType::isAllowed('ui.stream.id'));
    }
}
