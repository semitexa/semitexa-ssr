<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Server\SseFrame;
use Semitexa\Core\Server\SseTransportInterface;
use Swoole\Http\Response;

/**
 * Swoole adapter for the {@see SseTransportInterface} port.
 *
 * Mirrors {@see \Semitexa\Core\Http\SwooleResponseEmitter}: the opaque `mixed`
 * handle is cast to a `Swoole\Http\Response` here — the one place a Swoole type
 * appears. Writing is best-effort (`@`) because the socket may already be gone;
 * a falsey `write()` return is the disconnect signal the caller treats as a
 * closed connection.
 *
 * This adapter currently lives in `semitexa-ssr` (the established home of the
 * SSE transport mechanism). The port + frame value object live in `core`, so a
 * future consumer can depend on the contract without depending on `ssr`; the
 * physical relocation of the Swoole mechanism into `core/src/Server` is a
 * deferred follow-up (see var/docs/multimodal-api-sse-layering-diagnostic.md §3).
 */
final class SwooleSseTransport implements SseTransportInterface
{
    public function writeFrame(mixed $stream, SseFrame $frame): bool
    {
        if (!$stream instanceof Response) {
            return false;
        }

        return (bool) @$stream->write($frame->toWire());
    }

    public function writeComment(mixed $stream): bool
    {
        if (!$stream instanceof Response) {
            return false;
        }

        return (bool) @$stream->write(":\n\n");
    }
}
