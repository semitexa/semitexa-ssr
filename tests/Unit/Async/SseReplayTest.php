<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\SseFrame;
use Semitexa\Core\Server\SseTransportInterface;
use Semitexa\Ssr\Application\Service\Async\ReplayingSseTransport;
use Semitexa\Ssr\Application\Service\Async\SseRedisPool;
use Semitexa\Ssr\Application\Service\Async\SseReplayRing;

/**
 * tk-ls-replay: a page's KISS stream numbers its frames and keeps the last of
 * them, so a reconnect naming the last frame it got is given everything after
 * it — or told to reset when that frame is no longer held.
 */
final class SseReplayTest extends TestCase
{
    private string|false $savedRedisHost;

    protected function setUp(): void
    {
        $this->savedRedisHost = getenv('REDIS_HOST');
        putenv('REDIS_HOST='); // the worker-local ring
    }

    protected function tearDown(): void
    {
        $this->savedRedisHost === false ? putenv('REDIS_HOST') : putenv('REDIS_HOST=' . $this->savedRedisHost);
    }

    #[Test]
    public function a_bound_stream_numbers_its_data_frames_and_records_what_it_wrote(): void
    {
        $wire = new RecordingSseTransport();
        $ring = new SseReplayRing(new SseRedisPool());
        $transport = new ReplayingSseTransport($wire, $ring);
        $stream = new \stdClass();
        $transport->bind($stream, 'sse_a');

        $transport->writeFrame($stream, SseFrame::fromResolved(null, 'connected', ['event' => 'connected']));
        $transport->writeFrame($stream, SseFrame::fromResolved(null, 'ui.patch', ['n' => 1]));
        $transport->writeFrame($stream, SseFrame::fromResolved(null, 'ui.patch', ['n' => 2]));
        $transport->writeFrame($stream, SseFrame::fromResolved('7', null, ['deferred' => true]));
        $transport->writeFrame(new \stdClass(), SseFrame::fromResolved(null, 'ui.patch', ['n' => 'other']));

        $ids = array_map(static fn (SseFrame $f): ?string => $f->id(), $wire->frames);
        self::assertNull($ids[0], 'the lifecycle frame is not numbered');
        self::assertMatchesRegularExpression('/\Ak[0-9a-f]{6}\.1\z/', (string) $ids[1]);
        self::assertTrue(ReplayingSseTransport::isReplayId($ids[2]));
        self::assertSame('7', $ids[3], 'a frame that has its own id keeps it');
        self::assertNull($ids[4], 'a stream bound to no session is written as it is');

        $missed = $ring->after('sse_a', (string) $ids[1]);
        self::assertSame([['n' => 2]], array_map(static fn (SseFrame $f): array => $f->data(), $missed ?? []));
        self::assertSame($ids[2], $missed[0]->id(), 'replayed under its first id');
        self::assertSame([], $ring->after('sse_a', (string) $ids[2]), 'nothing missed');
        self::assertNull($ring->after('sse_a', 'kffffff.9'), 'a frame never held: reset');
        self::assertNull($ring->after('sse_b', (string) $ids[1]), 'another session holds none of them');
    }

    #[Test]
    public function a_frame_that_could_not_be_written_is_not_recorded(): void
    {
        $wire = new RecordingSseTransport();
        $wire->fail = true;
        $ring = new SseReplayRing(new SseRedisPool());
        $transport = new ReplayingSseTransport($wire, $ring);
        $stream = new \stdClass();
        $transport->bind($stream, 'sse_a');
        // A frame the ring already holds: what follows it is what got recorded.
        $ring->record('sse_a', SseFrame::fromResolved('kbase00.0', 'ui.patch', ['n' => 0]));

        self::assertFalse($transport->writeFrame($stream, SseFrame::fromResolved(null, 'ui.patch', ['n' => 1])));
        self::assertSame([], $ring->after('sse_a', 'kbase00.0'), 'the frame that failed was not recorded');

        $wire->fail = false;
        $transport->writeFrame($stream, SseFrame::fromResolved(null, 'ui.patch', ['n' => 1]));
        self::assertStringEndsWith('.2', (string) $wire->frames[0]->id(), 're-queued and numbered afresh when it is written');
        self::assertCount(1, $ring->after('sse_a', 'kbase00.0') ?? [], 'only the frame that reached the wire');
    }

    #[Test]
    public function the_ring_keeps_only_the_last_frames(): void
    {
        $ring = new SseReplayRing(new SseRedisPool());
        for ($i = 1; $i <= SseReplayRing::CAPACITY + 5; $i++) {
            $ring->record('sse_a', SseFrame::fromResolved('kabcdef.' . $i, 'ui.patch', ['n' => $i]));
        }

        self::assertNull($ring->after('sse_a', 'kabcdef.5'), 'left the ring: the client must reset');
        self::assertCount(SseReplayRing::CAPACITY - 1, $ring->after('sse_a', 'kabcdef.6') ?? []);
        self::assertFalse(ReplayingSseTransport::isReplayId('12'), 'a deferred id is not ours');
        self::assertFalse(ReplayingSseTransport::isReplayId(null));
    }
}

final class RecordingSseTransport implements SseTransportInterface
{
    /** @var list<SseFrame> */
    public array $frames = [];
    public bool $fail = false;

    public function writeFrame(mixed $stream, SseFrame $frame): bool
    {
        if ($this->fail) {
            return false;
        }
        $this->frames[] = $frame;

        return true;
    }

    public function writeComment(mixed $stream): bool
    {
        return true;
    }
}
