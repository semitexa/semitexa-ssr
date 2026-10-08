<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Server\SseFrame;
use Semitexa\Core\Server\PageTimeline;
use Semitexa\Core\Server\SseTransportInterface;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;

/**
 * The SSE write port, making a page's KISS stream resumable
 * (ep-platform-live-state · tk-ls-replay).
 *
 * Every write goes through the transport — the server's own frames and the
 * control router's re-run frames alike — so this is the one place a frame can
 * be numbered. A stream {@see bind()}-ed to its session gets an `id:` on every
 * data frame (`k<connection>.<n>`) and each one, once written, recorded in the
 * {@see SseReplayRing}. Frames that already carry an id (the deferred door's
 * numeric ones) and the stream's own lifecycle frames (`connected`, `close`)
 * are written as they are.
 */
final class ReplayingSseTransport implements SseTransportInterface
{
    /** The id prefix that marks a frame this transport numbered. */
    public const ID_PREFIX = 'k';

    private const LIFECYCLE_EVENTS = ['connected', 'close'];

    /** @var \WeakMap<object, array{session: string, tag: string, seq: int}> */
    private \WeakMap $streams;

    public function __construct(
        private readonly SseTransportInterface $inner,
        private readonly SseReplayRing $ring,
    ) {
        $this->streams = new \WeakMap();
    }

    /** Number this stream's frames from now on, under its session's ring. */
    public function bind(mixed $stream, string $sessionId): void
    {
        if (is_object($stream) && $sessionId !== '') {
            $this->streams[$stream] = ['session' => $sessionId, 'tag' => bin2hex(random_bytes(3)), 'seq' => 0];
        }
    }

    /**
     * Number $stream's frames under its session from now on, and give a
     * reconnecting client the frames its last connection wrote after the one
     * it names (Last-Event-ID). When that frame is no longer held, say so
     * (`ui.stream.reset`): the client re-syncs from fresh snapshots. What the
     * connected frame should report.
     *
     * @return array{replayed?: int, reset?: true}
     */
    public function resume(mixed $stream, string $sessionId, ?string $lastEventId): array
    {
        $this->bind($stream, $sessionId);
        if (!self::isReplayId($lastEventId)) {
            return [];
        }

        $missed = $this->ring->after($sessionId, (string) $lastEventId);
        PageTimeline::record($sessionId, $missed === null ? 'reset' : 'replay', ['from' => $lastEventId, 'frames' => $missed === null ? null : count($missed)]);
        if ($missed === null) {
            $this->inner->writeFrame($stream, SseFrame::fromResolved(null, UiSseEventType::UiStreamReset->value, [
                '_type' => UiSseEventType::UiStreamReset->value,
                'reason' => 'not_held',
            ]));

            return ['reset' => true];
        }
        $this->replay($stream, $missed);

        return ['replayed' => count($missed)];
    }

    public static function isReplayId(?string $id): bool
    {
        return $id !== null && preg_match('/\Ak[0-9a-f]{6}\.\d+\z/', $id) === 1;
    }

    /**
     * Write what a reconnecting client missed, as it was first written. They
     * are not recorded again: the ring already holds them.
     *
     * @param list<SseFrame> $frames
     */
    public function replay(mixed $stream, array $frames): bool
    {
        foreach ($frames as $frame) {
            if (!$this->inner->writeFrame($stream, $frame)) {
                return false;
            }
        }

        return true;
    }

    public function writeFrame(mixed $stream, SseFrame $frame): bool
    {
        $bound = is_object($stream) && isset($this->streams[$stream]) ? $this->streams[$stream] : null;
        if ($bound === null || $frame->id() !== null || in_array($frame->event(), self::LIFECYCLE_EVENTS, true)
            || in_array($frame->data()['event'] ?? null, self::LIFECYCLE_EVENTS, true)) {
            $written = $this->inner->writeFrame($stream, $frame);
            if ($written && $bound !== null) {
                self::onTimeline($bound['session'], $frame);
            }

            return $written;
        }

        $bound['seq']++;
        $this->streams[$stream] = $bound;
        $numbered = $frame->withId(self::ID_PREFIX . $bound['tag'] . '.' . $bound['seq']);
        if (!$this->inner->writeFrame($stream, $numbered)) {
            // Not written, so not recorded: the caller re-queues the frame and
            // it is numbered afresh when it is finally written.
            return false;
        }
        $this->ring->record($bound['session'], $numbered);
        self::onTimeline($bound['session'], $numbered);

        return true;
    }

    /** The page's timeline (dev only): every frame its stream wrote. */
    private static function onTimeline(string $session, SseFrame $frame): void
    {
        if (!PageTimeline::isOn()) {
            return;
        }
        $data = $frame->data();
        PageTimeline::record($session, 'frame', [
            'event' => $frame->event() ?? (is_string($data['type'] ?? null) ? $data['type'] : 'message'),
            'id' => $frame->id(),
            'sub' => is_string($data['streaming_id'] ?? null) ? $data['streaming_id'] : null,
            'bytes' => strlen($frame->toWire()),
        ]);
    }

    public function writeComment(mixed $stream): bool
    {
        return $this->inner->writeComment($stream);
    }
}
