<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseControlFrame;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;

/**
 * A consumer that stops reading must not grow the worker without a bound.
 *
 * Both maps here are worker-local PHP arrays and every data frame carries
 * rendered HTML. Before this, a throttled tab or a stalled socket accumulated
 * frames until the socket closed — and on the buffer path, for a session that
 * never connects to this worker, until the worker died.
 */
final class SseSessionQueueBackpressureTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['SSE_SESSION_QUEUE_MAX'], $_ENV['SSE_SESSION_BUFFER_MAX']);
        putenv('SSE_SESSION_QUEUE_MAX');
        putenv('SSE_SESSION_BUFFER_MAX');
        parent::tearDown();
    }

    #[Test]
    public function an_overflowing_queue_collapses_to_one_rerun(): void
    {
        putenv('SSE_SESSION_QUEUE_MAX=4');
        $registry = new SseSessionRegistry();

        for ($i = 0; $i < 5; $i++) {
            $registry->enqueue('s1', ['html' => "frame {$i}"]);
        }

        $queued = $registry->queued('s1');

        self::assertCount(1, $queued, 'the backlog is replaced, not appended to');
        self::assertSame(
            [SseControlFrame::KEY => SseControlFrame::RERUN],
            $queued[0],
            'the client is told to re-query rather than handed a stream with a hole in it',
        );
    }

    #[Test]
    public function a_queue_under_the_cap_is_left_exactly_as_it_was(): void
    {
        putenv('SSE_SESSION_QUEUE_MAX=4');
        $registry = new SseSessionRegistry();

        $registry->enqueue('s1', ['html' => 'a']);
        $registry->enqueue('s1', ['html' => 'b']);

        self::assertSame([['html' => 'a'], ['html' => 'b']], $registry->queued('s1'));
    }

    #[Test]
    public function each_session_is_capped_on_its_own(): void
    {
        putenv('SSE_SESSION_QUEUE_MAX=2');
        $registry = new SseSessionRegistry();

        $registry->enqueue('slow', ['html' => 'a']);
        $registry->enqueue('slow', ['html' => 'b']);
        $registry->enqueue('slow', ['html' => 'c']);
        $registry->enqueue('healthy', ['html' => 'x']);

        self::assertCount(1, $registry->queued('slow'), 'the slow consumer collapsed');
        self::assertSame([['html' => 'x']], $registry->queued('healthy'), 'its neighbour is untouched');
    }

    #[Test]
    public function an_overflowing_buffer_keeps_the_newest_frames(): void
    {
        putenv('SSE_SESSION_BUFFER_MAX=3');
        $registry = new SseSessionRegistry();

        foreach (['a', 'b', 'c', 'd', 'e'] as $frame) {
            $registry->buffer('s1', ['html' => $frame]);
        }

        self::assertSame(
            [['html' => 'c'], ['html' => 'd'], ['html' => 'e']],
            $registry->buffered('s1'),
            'a late-connecting client needs the most recent state, not the first frames ever sent',
        );
    }
}
