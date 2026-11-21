<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Ssr\Application\Service\Async\SseRedisPool;
use Semitexa\Ssr\Application\Service\Async\SseRedisSessionQueue;

/**
 * The session-queue store's contract, exercised without a live Redis.
 *
 * The interesting behaviour here is what happens when Redis is NOT available,
 * because that is the common production degradation and every branch of it used
 * to be inlined in three different places in the server. The rule these cases
 * pin: durability is best-effort and fails soft, EXCEPT on the cross-worker
 * delivery path, where a failure must be reported so the caller can fall back
 * instead of silently dropping the frame.
 */
final class SseRedisSessionQueueTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // Force the "no Redis configured" resolution so these cases never touch
        // a real server and never depend on one being reachable.
        $this->savedEnv['REDIS_HOST'] = getenv('REDIS_HOST');
        putenv('REDIS_HOST=');
    }

    protected function tearDown(): void
    {
        $saved = $this->savedEnv['REDIS_HOST'] ?? false;
        if (is_string($saved) && $saved !== '') {
            putenv('REDIS_HOST=' . $saved);
        } else {
            putenv('REDIS_HOST');
        }
    }

    #[Test]
    public function the_queue_key_is_session_scoped_and_trimmed(): void
    {
        self::assertSame('semitexa_sse_queue:sess-1', SseRedisSessionQueue::key('  sess-1  '));
    }

    #[Test]
    public function encode_drops_unencodable_entries_without_losing_the_rest(): void
    {
        // One poisoned payload must not cost the session every other pending
        // frame — the flush is all-or-nothing per entry, not per batch.
        $encoded = SseRedisSessionQueue::encode([
            ['type' => 'ui.patch'],
            'not-an-array',
            ['type' => 'done'],
            42,
            [NAN],
        ]);

        self::assertSame(['{"type":"ui.patch"}', '{"type":"done"}'], $encoded);
    }

    #[Test]
    public function encode_leaves_slashes_and_unicode_unescaped(): void
    {
        // The wire carries these bytes verbatim; escaping them would change the
        // payload a reconnecting subscriber sees.
        self::assertSame(
            ['{"path":"/a/b","label":"Ідентифікатор"}'],
            SseRedisSessionQueue::encode([['path' => '/a/b', 'label' => 'Ідентифікатор']]),
        );
    }

    #[Test]
    public function encode_of_an_empty_queue_is_empty(): void
    {
        self::assertSame([], SseRedisSessionQueue::encode([]));
    }

    #[Test]
    public function the_queue_reports_itself_unavailable_without_redis(): void
    {
        self::assertFalse($this->queue()->isAvailable());
    }

    #[Test]
    public function a_flush_without_redis_is_a_silent_no_op(): void
    {
        // Fail-soft: losing durability must never take a live stream down.
        $this->expectNotToPerformAssertions();
        $this->queue()->push('sess-1', [['type' => 'ui.patch']]);
        $this->queue()->pushRaw('sess-1', '{"type":"ui.patch"}');
        $this->queue()->publishInvalidation('scope:articles');
    }

    #[Test]
    public function cross_worker_delivery_reports_failure_so_the_caller_can_fall_back(): void
    {
        // The one place where fail-soft is NOT enough: returning true here would
        // claim delivery and skip the Swoole-table fallback, losing the frame.
        self::assertFalse($this->queue()->tryPush('sess-1', ['type' => 'ui.patch']));
    }

    #[Test]
    public function a_pop_without_redis_reports_stop_rather_than_empty(): void
    {
        // `ok=false` ends the drain. Reporting "empty" instead would be wrong in
        // a different way — it would claim the queue was drained when it was
        // merely unreachable.
        self::assertSame(['ok' => false, 'raw' => null], $this->queue()->pop('sess-1'));
    }

    #[Test]
    public function publishing_a_blank_channel_is_refused_before_any_connection(): void
    {
        $this->expectNotToPerformAssertions();
        $this->queue()->publishInvalidation('   ');
    }

    /**
     * tk-idle-sse-stream-polls-redis: the wake is what lets the owning worker's
     * loop stop polling. Each way a frame enters the list publishes one, after
     * the frame is in.
     */
    #[Test]
    public function every_push_marks_the_session_pending_and_then_publishes_its_wake(): void
    {
        // The order is the guarantee: the frame is in the list before the mark,
        // so a sweep that takes the mark finds the frame; the wake comes last.
        $commands = [];
        self::assertTrue(self::recordingQueue($commands)->tryPush(' sess-1 ', ['type' => 'ui.patch']));
        self::assertSame(['RPUSH', 'EXPIRE', 'SADD sess-1', 'EXPIRE', 'PUBLISH semitexa_sse_wake sess-1'], $commands);

        $commands = [];
        self::recordingQueue($commands)->push('sess-2', [['type' => 'ui.patch'], ['type' => 'done']]);
        self::assertSame(['RPUSH', 'EXPIRE', 'SADD sess-2', 'EXPIRE', 'PUBLISH semitexa_sse_wake sess-2'], $commands);

        $commands = [];
        self::recordingQueue($commands)->pushRaw('sess-3', '{"type":"ui.patch"}');
        self::assertSame(['RPUSH', 'EXPIRE', 'SADD sess-3', 'EXPIRE', 'PUBLISH semitexa_sse_wake sess-3'], $commands);
    }

    #[Test]
    public function a_bare_wake_marks_the_session_pending_and_queues_nothing(): void
    {
        // The takeover wake: lost, the replaced connection's loop is found by the sweep.
        $commands = [];
        self::assertTrue(self::recordingQueue($commands)->publishWake('sess-1'));
        self::assertSame(['SADD sess-1', 'EXPIRE', 'PUBLISH semitexa_sse_wake sess-1'], $commands);

        $commands = [];
        self::assertFalse(self::recordingQueue($commands)->publishWake('   '));
        self::assertSame([], $commands, 'a blank session id is refused before any connection');
    }

    #[Test]
    public function a_bare_wake_reports_whether_the_session_was_marked(): void
    {
        // The sweep restores a mark with this and retries on false: the mark
        // is what a later sweep finds, the publish is only the fast path.
        $commands = [];
        self::assertFalse(self::recordingQueue($commands, failCommand: 'SADD')->publishWake('sess-1'), 'an unmarked session was reported marked');
        self::assertFalse($this->queue()->publishWake('sess-1'), 'no Redis at all');

        $commands = [];
        self::assertTrue(self::recordingQueue($commands, failPublish: true)->publishWake('sess-1'), 'the mark is in: a lost publish is not a failed restore');
    }

    #[Test]
    public function a_failed_wake_does_not_report_the_push_as_failed(): void
    {
        // The frame is in the list. Reporting false would send the same frame
        // down the fallback path too, and the client would get it twice.
        $commands = [];
        self::assertTrue(self::recordingQueue($commands, failPublish: true)->tryPush('sess-1', ['type' => 'ui.patch']));
        self::assertSame(['RPUSH', 'EXPIRE', 'SADD sess-1', 'EXPIRE', 'PUBLISH semitexa_sse_wake sess-1'], $commands);
    }

    #[Test]
    public function a_push_that_could_not_mark_the_session_reports_failure(): void
    {
        // Unmarked, a frame whose wake is also lost has nothing left to find it:
        // the caller is told, and takes the fallback path (twice beats never).
        $commands = [];
        self::assertFalse(self::recordingQueue($commands, failCommand: 'SADD')->tryPush('sess-1', ['type' => 'ui.patch']));
        self::assertSame(['RPUSH', 'EXPIRE', 'SADD sess-1'], $commands);
    }

    #[Test]
    public function the_pending_set_is_read_from_two_buckets_and_cleared_in_both(): void
    {
        // A set member cannot expire alone, so the set is one key per time
        // bucket, each expiring whole; a mark made just before a boundary is
        // in the previous bucket when the sweep reads.
        $bucket = intdiv(time(), SseRedisSessionQueue::PENDING_BUCKET_SECONDS);
        $commands = [];

        self::assertSame(['sess-1', 'sess-2'], self::recordingQueue($commands, members: ['sess-1', 'sess-2'])->pendingSessions());
        // A queue per call: on a second borrow the pool checks the client's connection, and this one has none.
        self::recordingQueue($commands)->clearPending(['sess-1']);
        self::recordingQueue($commands)->clearPending([]);

        self::assertSame([
            'SMEMBERS semitexa_sse_pending:' . $bucket,
            'SMEMBERS semitexa_sse_pending:' . ($bucket - 1),
            'SREM semitexa_sse_pending:' . $bucket . ' sess-1',
            'SREM semitexa_sse_pending:' . ($bucket - 1) . ' sess-1',
        ], $commands);
    }

    #[Test]
    public function a_pending_set_that_cannot_be_read_is_not_an_empty_one(): void
    {
        // null sends the sweep to wake everyone; [] would say nobody is pending.
        $commands = [];
        self::assertNull(self::recordingQueue($commands, failCommand: 'SMEMBERS')->pendingSessions());
        self::assertNull($this->queue()->pendingSessions(), 'no Redis at all');
    }

    /**
     * A queue over a client that records its commands and touches no socket.
     *
     * @param list<string> $commands
     * @param list<string> $members what SMEMBERS answers, for either bucket
     */
    private static function recordingQueue(array &$commands, bool $failPublish = false, ?string $failCommand = null, array $members = []): SseRedisSessionQueue
    {
        $client = new class($commands, $failPublish, $failCommand, $members) extends Client {
            /**
             * @param list<string> $commands
             * @param list<string> $members
             */
            public function __construct(private array &$commands, private readonly bool $failPublish, private readonly ?string $failCommand, private readonly array $members)
            {
            }

            /** @return int|list<string> */
            public function __call($commandID, $arguments): int|array
            {
                $name = strtoupper((string) $commandID);
                $key = is_string($arguments[0] ?? null) ? $arguments[0] : '';
                $this->commands[] = match ($name) {
                    'PUBLISH' => 'PUBLISH ' . implode(' ', array_map(strval(...), $arguments)),
                    // The member, not the key: the key carries the clock.
                    'SADD' => 'SADD ' . implode(' ', (array) ($arguments[1] ?? [])),
                    'SMEMBERS' => 'SMEMBERS ' . $key,
                    'SREM' => 'SREM ' . $key . ' ' . implode(' ', (array) ($arguments[1] ?? [])),
                    default => $name,
                };
                if ($name === $this->failCommand || ($name === 'PUBLISH' && $this->failPublish)) {
                    throw new \RuntimeException('WRONGTYPE');
                }
                if ($name === 'SMEMBERS') {
                    // Both buckets hold the same marks here: the read must not report them twice.
                    return $this->members;
                }

                return $name === 'PUBLISH' ? 0 : 1;
            }
        };
        $redis = new RedisConnectionPool(1, ['host' => '127.0.0.1']);
        // Outside a coroutine the pool hands out this one client.
        (new \ReflectionProperty(RedisConnectionPool::class, 'cliFallback'))->setValue($redis, $client);
        $pool = new SseRedisPool();
        (new \ReflectionProperty(SseRedisPool::class, 'pool'))->setValue($pool, $redis);

        return new SseRedisSessionQueue($pool);
    }

    private function queue(): SseRedisSessionQueue
    {
        return new SseRedisSessionQueue(new SseRedisPool());
    }
}
