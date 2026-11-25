<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Swoole\Http\Response;

/**
 * The HTTP side of an SSE response: its status and headers, and its end.
 *
 * A class of its own because two callers need the head and only one of them
 * opens a session. {@see SseServer} sends it for every admitted stream and then
 * registers the connection. {@see SseDeferredDoor::turnsAway()} sends it for a
 * redeem it refuses BEFORE the open: that answer is still an event stream (the
 * client reads its one frame), yet it must claim nothing of the session.
 *
 * Anything that is not a Swoole response is left alone, as the door is driven
 * with other doubles in its tests.
 */
final class SseStreamHead
{
    /**
     * `X-Accel-Buffering: no` is the load-bearing header: without it nginx
     * buffers the stream and every frame arrives late, in a batch.
     */
    public static function send(mixed $stream): void
    {
        if (!$stream instanceof Response) {
            return;
        }

        $stream->status(200);
        $stream->header('Content-Type', 'text/event-stream');
        $stream->header('Cache-Control', 'no-cache');
        $stream->header('Connection', 'keep-alive');
        $stream->header('X-Accel-Buffering', 'no');
    }

    public static function end(mixed $stream): void
    {
        if ($stream instanceof Response) {
            @$stream->end();
        }
    }
}
