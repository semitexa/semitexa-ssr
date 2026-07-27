<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

    private function queue(): SseRedisSessionQueue
    {
        return new SseRedisSessionQueue(new SseRedisPool());
    }
}
