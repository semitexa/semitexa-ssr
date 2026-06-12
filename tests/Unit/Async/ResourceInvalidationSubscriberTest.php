<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\RedisSubscribeConnectionFactory;
use Semitexa\Ssr\Application\Service\Async\RerunCoalescer;
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationPublisher;
use Semitexa\Ssr\Application\Service\Async\ResourceInvalidationSubscriber;
use Semitexa\Ssr\Application\Service\Async\ScanningSubscriberIndex;
use Semitexa\Ssr\Application\Service\Async\SubscriptionTable;
use Semitexa\Ssr\Domain\Contract\SessionControlDeliveryInterface;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * Track R · R3 — the push receiver, proven in ISOLATION.
 *
 * Subscriptions are inserted directly (R1) and signals are driven by calling
 * {@see ResourceInvalidationSubscriber::handleMessage()} with the channel a
 * MANUAL `PUBLISH` would carry (as P3 would emit) — no live ORM write, no live
 * Redis, no running coroutine loop. A capturing {@see SessionControlDeliveryInterface}
 * double records exactly what lands on each session-addressed queue, the same way
 * P3's tests used a capturing bus.
 *
 * Proves: publish → exactly one `{__ctrl:rerun}` per stream; tenant isolation;
 * the DEDICATED-connection invariant (structural); idempotent coalescing of N
 * rapid signals; subscribe-on-first / unsubscribe-on-last lifecycle; and that R3
 * does NOT run the re-run (it only enqueues the control — R4's job).
 */
final class ResourceInvalidationSubscriberTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Swoole\Table::class, false)) {
            self::markTestSkipped('Swoole extension not loaded.');
        }
    }

    /** A capturing control-delivery double: records (sessionId, control) tuples. */
    private function captureDelivery(): SessionControlDeliveryInterface
    {
        return new class implements SessionControlDeliveryInterface {
            /** @var list<array{session_id: string, control: array<string, mixed>}> */
            public array $delivered = [];

            public function deliverControl(string $sessionId, array $control): void
            {
                $this->delivered[] = ['session_id' => $sessionId, 'control' => $control];
            }
        };
    }

    /**
     * The EXECUTABLE source of a class with all comments/docblocks stripped, so a
     * structural "the code never references X" assertion is not tripped by prose
     * that legitimately NAMES the forbidden symbol while documenting the invariant.
     */
    private function codeWithoutComments(string $class): string
    {
        $file = (string) (new \ReflectionClass($class))->getFileName();
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    private function record(string $streamingId, string $sessionId, string $tenant, string $scopeKey): SubscriptionRecord
    {
        return new SubscriptionRecord(
            streamingId: $streamingId,
            sessionId: $sessionId,
            tenantId: $tenant,
            scopeKeys: [$scopeKey],
            tenantBlob: '',
        );
    }

    /**
     * Wire a subscriber over real R1 + R3 stores (real Swoole\Tables), with a
     * capturing delivery double and a throwaway connection factory.
     *
     * @return array{0: ResourceInvalidationSubscriber, 1: SubscriptionTable, 2: RerunCoalescer, 3: object}
     */
    private function subscriber(): array
    {
        $subscriptions = SubscriptionTable::create(64);
        $coalescer = RerunCoalescer::create(64);
        $delivery = $this->captureDelivery();
        $factory = new RedisSubscribeConnectionFactory([
            'scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'password' => '',
        ]);

        $subscriber = new ResourceInvalidationSubscriber(
            new ScanningSubscriberIndex($subscriptions),
            $subscriptions,
            $coalescer,
            $factory,
            $delivery,
        );

        return [$subscriber, $subscriptions, $coalescer, $delivery];
    }

    // -----------------------------------------------------------------------
    // #1 — publish → exactly one control delivered to the right session queue
    // -----------------------------------------------------------------------

    #[Test]
    public function publish_resolves_to_exactly_one_rerun_control_on_the_session_queue(): void
    {
        [$subscriber, $subscriptions, , $delivery] = $this->subscriber();
        $subscriptions->insert($this->record('str_a', 'sse_session_a', 't1', 'lead_submission'));

        // What a MANUAL `PUBLISH ui.invalidate.t1.lead_submission` carries (as P3 emits).
        $channel = ResourceInvalidationPublisher::channelFor('t1', 'lead_submission');
        $enqueued = $subscriber->handleMessage($channel);

        self::assertSame(1, $enqueued);
        self::assertCount(1, $delivery->delivered);
        self::assertSame('sse_session_a', $delivery->delivered[0]['session_id']);
        self::assertSame(
            ['__ctrl' => 'rerun', 'streaming_id' => 'str_a', 'scope_key' => 'lead_submission'],
            $delivery->delivered[0]['control'],
        );
    }

    #[Test]
    public function multiple_subscribers_on_one_scope_each_get_one_control(): void
    {
        [$subscriber, $subscriptions, , $delivery] = $this->subscriber();
        $subscriptions->insert($this->record('str_a', 'sse_session_a', 't1', 'lead_submission'));
        $subscriptions->insert($this->record('str_b', 'sse_session_b', 't1', 'lead_submission'));

        $enqueued = $subscriber->handleMessage(ResourceInvalidationPublisher::channelFor('t1', 'lead_submission'));

        self::assertSame(2, $enqueued);
        $sessions = array_map(static fn (array $d): string => $d['session_id'], $delivery->delivered);
        sort($sessions);
        self::assertSame(['sse_session_a', 'sse_session_b'], $sessions);
    }

    #[Test]
    public function malformed_channel_routes_nothing(): void
    {
        [$subscriber, $subscriptions, , $delivery] = $this->subscriber();
        $subscriptions->insert($this->record('str_a', 'sse_session_a', 't1', 'lead_submission'));

        self::assertSame(0, $subscriber->handleMessage('not.our.channel'));
        self::assertSame(0, $subscriber->handleMessage('ui.invalidate.t1')); // missing scopeKey
        self::assertSame([], $delivery->delivered);
    }

    // -----------------------------------------------------------------------
    // #2 — tenant isolation end-to-end through the subscriber
    // -----------------------------------------------------------------------

    #[Test]
    public function a_tenant_t2_subscription_is_not_signalled_by_a_t1_publish(): void
    {
        [$subscriber, $subscriptions, , $delivery] = $this->subscriber();
        $subscriptions->insert($this->record('str_t1', 'sse_session_t1', 't1', 'lead_submission'));
        $subscriptions->insert($this->record('str_t2', 'sse_session_t2', 't2', 'lead_submission'));

        // A publish in t1 must resolve ONLY the t1 subscriber, never t2's
        // (same scope key, different tenant) — the security boundary.
        $subscriber->handleMessage(ResourceInvalidationPublisher::channelFor('t1', 'lead_submission'));

        self::assertCount(1, $delivery->delivered);
        self::assertSame('sse_session_t1', $delivery->delivered[0]['session_id']);
        self::assertSame('str_t1', $delivery->delivered[0]['control']['streaming_id']);
    }

    // -----------------------------------------------------------------------
    // #3 — DEDICATED connection (HARD) — structural proof
    // -----------------------------------------------------------------------

    #[Test]
    public function the_subscribe_connection_is_dedicated_not_borrowed_from_the_pool(): void
    {
        $factory = new RedisSubscribeConnectionFactory([
            'scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'password' => '',
        ]);

        // Each call yields a DISTINCT Predis client — a brand-new exclusive
        // connection. A pool would hand back the SAME parked connection; distinct
        // instances prove the blocking loop owns its connection, not a shared one.
        $a = $factory->create();
        $b = $factory->create();
        self::assertInstanceOf(\Predis\Client::class, $a);
        self::assertNotSame($a, $b);

        // The subscriber has NO dependency on the SSE pool: no RedisConnectionPool
        // typed constructor parameter, and its source never references the pool
        // accessor — so it cannot borrow the size-1 pool for a blocking subscribe.
        $ctor = (new \ReflectionClass(ResourceInvalidationSubscriber::class))->getConstructor();
        self::assertNotNull($ctor);
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            self::assertNotSame(\Semitexa\Core\Redis\RedisConnectionPool::class, $typeName);
        }

        $code = $this->codeWithoutComments(ResourceInvalidationSubscriber::class);
        self::assertStringNotContainsString('getRedisPool', $code);
        self::assertStringNotContainsString('RedisConnectionPool', $code);

        // The factory itself only ever builds fresh connections — never the pool.
        $factoryCode = $this->codeWithoutComments(RedisSubscribeConnectionFactory::class);
        self::assertStringNotContainsString('getRedisPool', $factoryCode);
        self::assertStringNotContainsString('RedisConnectionPool', $factoryCode);
    }

    // -----------------------------------------------------------------------
    // #4 — idempotency: N rapid signals → ONE re-run per stream (coalesced)
    // -----------------------------------------------------------------------

    #[Test]
    public function n_rapid_signals_enqueue_one_rerun_per_stream(): void
    {
        [$subscriber, $subscriptions, $coalescer, $delivery] = $this->subscriber();
        $subscriptions->insert($this->record('str_a', 'sse_session_a', 't1', 'lead_submission'));
        $channel = ResourceInvalidationPublisher::channelFor('t1', 'lead_submission');

        // Five rapid duplicate signals for the same scope.
        $first = $subscriber->handleMessage($channel);
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(0, $subscriber->handleMessage($channel), 'duplicate signal must coalesce');
        }

        self::assertSame(1, $first);
        self::assertCount(1, $delivery->delivered, 'N signals → exactly ONE control for the stream');
        self::assertTrue($coalescer->isPending('str_a'));

        // After R4 drains+runs the re-run (clearPending is the R4 seam), a FRESH
        // mutation's signal is free to enqueue again — proving the collapse window
        // is bounded to one pending re-run, not a permanent suppression.
        $coalescer->clearPending('str_a');
        self::assertFalse($coalescer->isPending('str_a'));
        self::assertSame(1, $subscriber->handleMessage($channel));
        self::assertCount(2, $delivery->delivered);
    }

    #[Test]
    public function coalescing_is_per_stream_not_global(): void
    {
        [$subscriber, $subscriptions, , $delivery] = $this->subscriber();
        $subscriptions->insert($this->record('str_a', 'sse_session_a', 't1', 'lead_submission'));
        $subscriptions->insert($this->record('str_b', 'sse_session_b', 't1', 'lead_submission'));
        $channel = ResourceInvalidationPublisher::channelFor('t1', 'lead_submission');

        // First signal: both streams get one control. Second signal: both coalesce.
        self::assertSame(2, $subscriber->handleMessage($channel));
        self::assertSame(0, $subscriber->handleMessage($channel));
        self::assertCount(2, $delivery->delivered);
    }

    // -----------------------------------------------------------------------
    // #5 — lifecycle: channel set tracks R1 state (subscribe-on-first / off-last)
    // -----------------------------------------------------------------------

    #[Test]
    public function channel_set_tracks_subscribe_on_first_and_unsubscribe_on_last(): void
    {
        [$subscriber, $subscriptions] = $this->subscriber();
        $channel = ResourceInvalidationPublisher::channelFor('t1', 'lead_submission');

        // Empty store → no channels.
        self::assertSame([], $subscriber->desiredChannels());

        // First subscriber for the scope → channel appears; diff says SUBSCRIBE.
        $subscriptions->insert($this->record('str_a', 'sse_session_a', 't1', 'lead_submission'));
        self::assertSame([$channel], $subscriber->desiredChannels());
        self::assertSame(
            ['subscribe' => [$channel], 'unsubscribe' => []],
            $subscriber->channelDiff([]),
        );

        // Second subscriber, SAME scope → still one channel; already subscribed →
        // no new subscribe (subscribe-on-FIRST only).
        $subscriptions->insert($this->record('str_b', 'sse_session_b', 't1', 'lead_submission'));
        self::assertSame([$channel], $subscriber->desiredChannels());
        self::assertSame(
            ['subscribe' => [], 'unsubscribe' => []],
            $subscriber->channelDiff([$channel]),
        );

        // Remove one of two → channel stays (still has a subscriber): no unsubscribe.
        $subscriptions->remove('str_a');
        self::assertSame([$channel], $subscriber->desiredChannels());
        self::assertSame([], $subscriber->channelDiff([$channel])['unsubscribe']);

        // Remove the LAST → channel disappears; diff says UNSUBSCRIBE.
        $subscriptions->remove('str_b');
        self::assertSame([], $subscriber->desiredChannels());
        self::assertSame(
            ['subscribe' => [], 'unsubscribe' => [$channel]],
            $subscriber->channelDiff([$channel]),
        );
    }

    #[Test]
    public function desired_channels_span_distinct_tenant_scope_pairs(): void
    {
        [$subscriber, $subscriptions] = $this->subscriber();
        $subscriptions->insert($this->record('s1', 'sess1', 't1', 'lead_submission'));
        $subscriptions->insert($this->record('s2', 'sess2', 't2', 'lead_submission'));
        $subscriptions->insert($this->record('s3', 'sess3', 't1', 'inventory_item'));

        $channels = $subscriber->desiredChannels();
        sort($channels);

        self::assertSame([
            'ui.invalidate.t1.inventory_item',
            'ui.invalidate.t1.lead_submission',
            'ui.invalidate.t2.lead_submission',
        ], $channels);
    }

    // -----------------------------------------------------------------------
    // Channel parse — exact inverse of the publisher's channelFor()
    // -----------------------------------------------------------------------

    #[Test]
    public function parse_channel_is_the_inverse_of_channel_for(): void
    {
        self::assertSame(['t1', 'lead_submission'], ResourceInvalidationSubscriber::parseChannel(
            ResourceInvalidationPublisher::channelFor('t1', 'lead_submission'),
        ));

        // A dotted scope key keeps its dots (only the tenant segment is split off).
        self::assertSame(['t1', 'a.b.c'], ResourceInvalidationSubscriber::parseChannel('ui.invalidate.t1.a.b.c'));

        self::assertNull(ResourceInvalidationSubscriber::parseChannel('ui.invalidate.t1'));
        self::assertNull(ResourceInvalidationSubscriber::parseChannel('other.t1.scope'));
        self::assertNull(ResourceInvalidationSubscriber::parseChannel(''));
    }

    // -----------------------------------------------------------------------
    // Scope fence: R3 does NOT run the re-run (no re-runner reference)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_subscriber_never_runs_the_rerun(): void
    {
        // R3 enqueues {__ctrl:rerun}; R4 catches it and calls R2. The subscriber's
        // EXECUTABLE code must not reach for the re-runner or reExecute (the
        // docblocks may name them while explaining the fence — hence comments are
        // stripped before the assertion).
        $code = $this->codeWithoutComments(ResourceInvalidationSubscriber::class);
        self::assertStringNotContainsString('reExecute', $code);
        self::assertStringNotContainsString('ReRunner', $code);
        self::assertStringNotContainsString('ReRunContext', $code);

        // No constructor dependency is a re-runner type — R3 structurally cannot
        // execute a re-run, only enqueue the control that asks for one. (The
        // RerunCoalescer is the idempotency gate, NOT a re-run executor.)
        $ctor = (new \ReflectionClass(ResourceInvalidationSubscriber::class))->getConstructor();
        self::assertNotNull($ctor);
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            self::assertStringNotContainsString('ReRunner', $typeName);
            self::assertStringNotContainsString('RouteExecutor', $typeName);
        }
    }

    // -----------------------------------------------------------------------
    // One Way Phase 4 — the multi-scope resubscribe seam (interrupt-on-diff)
    // -----------------------------------------------------------------------

    #[Test]
    public function without_a_live_loop_no_channel_is_covered(): void
    {
        // No loop turn has published a snapshot → any non-empty request is
        // uncovered (the controller then interrupts / relaunches), while the
        // empty request is vacuously covered.
        [$subscriber] = $this->subscriber();

        self::assertFalse($subscriber->isSubscribedTo(['ui.invalidate.default.ui_playground_pings']));
        self::assertTrue($subscriber->isSubscribedTo([]));
    }

    #[Test]
    public function interrupt_without_a_live_connection_is_a_safe_noop(): void
    {
        // Interrupting between loop turns (snapshot cleared) must not throw —
        // the while(true) re-read covers the new scope on its own.
        [$subscriber] = $this->subscriber();

        $subscriber->interrupt();

        self::assertFalse($subscriber->isSubscribedTo(['ui.invalidate.default.anything']));
    }
}
