<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;

/**
 * Teardown semantics for the per-worker session state.
 *
 * The interesting case is what `close()` must NOT leave behind: review caught it
 * discarding the session and its queue while keeping the buffer, which let a
 * reconnect on the same id drain frames belonging to a connection that was
 * already gone.
 */
final class SseSessionRegistryTest extends TestCase
{
    #[Test]
    public function close_clears_the_buffer_too(): void
    {
        $registry = new SseSessionRegistry();
        $registry->buffer('sess-1', ['type' => 'ui.patch']);

        $registry->close('sess-1');

        self::assertSame([], $registry->buffered('sess-1'), 'a stale buffer must not survive teardown');
    }

    #[Test]
    public function a_reconnect_after_close_starts_with_nothing_buffered(): void
    {
        $registry = new SseSessionRegistry();
        $registry->buffer('sess-1', ['type' => 'ui.patch']);
        $registry->close('sess-1');

        $registry->open('sess-1', new \stdClass(), 'tenant-a', 'blob');

        self::assertSame([], $registry->takeBuffered('sess-1'));
    }

    #[Test]
    public function close_discards_the_session_its_queue_and_its_producer_slot(): void
    {
        $registry = new SseSessionRegistry();
        $registry->open('sess-1', new \stdClass(), '', '');
        $registry->enqueue('sess-1', ['a' => 1]);
        self::assertTrue($registry->tryStartDemoProducer('sess-1'));

        $registry->close('sess-1');

        self::assertFalse($registry->isOpen('sess-1'));
        self::assertFalse($registry->hasQueued('sess-1'));
        self::assertTrue($registry->tryStartDemoProducer('sess-1'), 'the producer slot is free again');
    }

    #[Test]
    public function closing_an_unknown_session_is_a_no_op(): void
    {
        $this->expectNotToPerformAssertions();
        (new SseSessionRegistry())->close('never-existed');
    }
}
