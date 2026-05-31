<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Ssr\Domain\Contract\SessionControlDeliveryInterface;
use Semitexa\Ssr\Domain\Contract\SubscriberIndexInterface;

/**
 * Track R · R3 — the cross-instance push RECEIVER.
 *
 * One coroutine per worker subscribes (on a DEDICATED Predis connection) to the
 * `ui.invalidate.{tenant}.{scopeKey}` channels P3 publishes to. On each message
 * it resolves the local subscriptions watching that scope (R1's reverse index)
 * and routes ONE coalesced `{__ctrl:rerun}` control to each via the existing
 * session-addressed delivery path (design §C.3/§C.4/§C.5). This is the seam where
 * P3's publish + R1's store combine into a working push pipeline — UP TO the
 * control message.
 *
 * SCOPE FENCE — subscriber + routing ONLY:
 *  - It does NOT execute the re-run. It enqueues `{__ctrl:rerun}`; the loop branch
 *    (R4) on the owning worker drains it and calls the re-runner (R2). This class
 *    never references `reExecute` / a re-runner.
 *  - It does NOT drive the connect lifecycle. R5 (the connect coordinator)
 *    populates R1's store on connect/teardown and drives this subscriber's
 *    subscribe/unsubscribe seam ({@see self::desiredChannels()} / {@see self::channelDiff()}).
 *
 * THREE LOAD-BEARING INVARIANTS:
 *  1. DEDICATED connection (HARD, design §C.3): the blocking `pubSubLoop` owns a
 *     connection from {@see RedisSubscribeConnectionFactory}, NEVER the size-1 SSE
 *     pool — this class has no reference to {@see AsyncResourceSseServer::getRedisPool()}
 *     nor {@see \Semitexa\Core\Redis\RedisConnectionPool}.
 *  2. IDEMPOTENT (design §C.3): duplicate signals (reconnect / overlap / N rapid
 *     mutations / two workers resolving the same stream) collapse to ONE pending
 *     re-run per stream via {@see RerunCoalescer}.
 *  3. TENANT ISOLATION (security, R1): `find($tenant, $scopeKey)` filters on BOTH
 *     dimensions parsed from the channel, so a tenant-A signal never resolves a
 *     tenant-B subscriber on a same-named scope.
 */
final class ResourceInvalidationSubscriber
{
    /** The control marker R4 recognises on the stream's queue. */
    public const CTRL_KEY = '__ctrl';
    public const CTRL_RERUN = 'rerun';

    /**
     * Track R · Gap C — backoff between reconnect attempts after a dropped
     * subscribe connection (Redis restart / network blip), so a hard-down Redis
     * cannot spin a tight reconnect loop. Idle no longer drops the connection
     * (read_write_timeout: -1 in {@see RedisSubscribeConnectionFactory}); this
     * covers the remaining real-failure case.
     */
    private const RECONNECT_BACKOFF_SECONDS = 1.0;

    public function __construct(
        private readonly SubscriberIndexInterface $index,
        private readonly SubscriptionTable $subscriptions,
        private readonly RerunCoalescer $coalescer,
        private readonly RedisSubscribeConnectionFactory $connectionFactory,
        private readonly SessionControlDeliveryInterface $delivery,
    ) {}

    /**
     * The blocking subscribe loop — the per-worker coroutine entry (C1). R5
     * launches it (`Coroutine::create`) at the first local subscription. It owns
     * a DEDICATED connection (never the pool) and processes each invalidation
     * message via {@see self::handleMessage()} until the connection closes.
     *
     * Guarded to no-op outside a Swoole coroutine (CLI/test): the blocking
     * `pubSubLoop` is only meaningful inside the coroutinized worker runtime
     * (`Runtime::enableCoroutine(SWOOLE_HOOK_ALL)` coroutinizes the socket read).
     * Unit tests drive {@see self::handleMessage()} directly, exactly as P3's were
     * driven by a manual dispatch.
     */
    public function run(): void
    {
        if (!class_exists(\Swoole\Coroutine::class, false) || \Swoole\Coroutine::getCid() < 0) {
            return;
        }

        // Track R · Gap C — the loop SELF-HEALS. Before, a single dropped connection
        // (idle read-timeout, Redis restart, network blip) logged + returned, and the
        // dead loop was only ever relaunched on the NEXT connect's channel-diff — so a
        // drop while idle-but-subscribed left the worker permanently deaf to
        // invalidations. Now the blocking subscribe is wrapped in a reconnect loop: it
        // returns ONLY when there are no local subscribers left (graceful teardown);
        // any connection failure logs, backs off, re-reads the desired channels, and
        // re-subscribes. (read_write_timeout: -1 means idle no longer drops it at all,
        // so this path is reached only on a genuine connection failure.)
        while (true) {
            $channels = $this->desiredChannels();
            if ($channels === []) {
                return; // no local subscribers → nothing to subscribe to (C2).
            }

            $connection = $this->connectionFactory->create(); // DEDICATED — never the pool.

            try {
                /** @var \Predis\PubSub\Consumer $pubsub */
                $pubsub = $connection->pubSubLoop(['subscribe' => $channels]);
                foreach ($pubsub as $message) {
                    if (($message->kind ?? null) === 'message') {
                        $this->handleMessage((string) $message->channel);
                    }
                }
            } catch (\Throwable $e) {
                StaticLoggerBridge::error('ssr', 'Resource-invalidation subscribe loop failed; reconnecting', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            } finally {
                try {
                    $connection->disconnect();
                } catch (\Throwable) {
                    // Best-effort close of the dedicated connection.
                }
            }

            // Back off before re-subscribing so a hard-down Redis can't spin a tight
            // loop. desiredChannels() is re-evaluated at the top of the next iteration.
            \Swoole\Coroutine::sleep(self::RECONNECT_BACKOFF_SECONDS);
        }
    }

    /**
     * Handle one invalidation message: parse `(tenant, scopeKey)` from the channel,
     * resolve the local subscribers (R1 reverse index), and route ONE coalesced
     * `{__ctrl:rerun}` control to each (C3). Idempotent: duplicate signals for the
     * same stream collapse (the coalescer admits only the first).
     *
     * @return int the number of `{__ctrl:rerun}` controls actually enqueued
     *             (after coalescing) — used by tests/logging, NOT a re-run count.
     */
    public function handleMessage(string $channel): int
    {
        $parsed = self::parseChannel($channel);
        if ($parsed === null) {
            StaticLoggerBridge::warning('ssr', 'Ignoring malformed scope-invalidation channel', [
                'channel' => $channel,
            ]);
            return 0;
        }

        [$tenant, $scopeKey] = $parsed;

        // R1 reverse index — filters on BOTH tenant and scope (the security
        // boundary): a tenant-A signal cannot resolve a tenant-B subscriber.
        $refs = $this->index->find($tenant, $scopeKey);

        $enqueued = 0;
        foreach ($refs as $ref) {
            // Coalesce per stream: only the signal that flips the stream from
            // "no pending re-run" to "one pending" enqueues a control; duplicates
            // (reconnect / overlap / N rapid signals / multi-worker race) collapse.
            if (!$this->coalescer->requestRerun($ref->streamingId)) {
                continue;
            }

            $this->delivery->deliverControl($ref->sessionId, [
                self::CTRL_KEY => self::CTRL_RERUN,
                'streaming_id' => $ref->streamingId,
                'scope_key' => $scopeKey,
            ]);
            $enqueued++;
        }

        // The scan cost profile, logged at the call site so the O(rows) reverse
        // index (design §C.5) is never a silent cap.
        StaticLoggerBridge::debug('ssr', 'Routed scope-invalidation signal', [
            'channel' => $channel,
            'tenant' => $tenant,
            'scope_key' => $scopeKey,
            'candidates' => count($refs),
            'enqueued' => $enqueued,
            'coalesced' => count($refs) - $enqueued,
        ]);

        return $enqueued;
    }

    /**
     * The channel set this worker SHOULD be subscribed to, derived from R1's
     * current store state (C2): one `ui.invalidate.{tenant}.{scopeKey}` channel
     * per distinct `(tenant_id, scopeKey)` any live subscription watches. As the
     * store gains the first subscriber for a scope the channel appears here; as it
     * loses the last, the channel disappears — so {@see self::channelDiff()} drives
     * subscribe-on-first / unsubscribe-on-last.
     *
     * @return list<string>
     */
    public function desiredChannels(): array
    {
        $set = [];
        foreach ($this->subscriptions->all() as $record) {
            foreach ($record->scopeKeys as $scopeKey) {
                $channel = ResourceInvalidationPublisher::channelFor($record->tenantId, $scopeKey);
                $set[$channel] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * The subscribe/unsubscribe seam R5 drives (C2): diff the desired channel set
     * (R1's current state) against what the loop is CURRENTLY subscribed to.
     * R5 applies the result to the live `pubSubLoop` on connect/teardown — R3
     * computes the delta, it does not own the connect lifecycle.
     *
     * @param list<string> $currentChannels
     * @return array{subscribe: list<string>, unsubscribe: list<string>}
     */
    public function channelDiff(array $currentChannels): array
    {
        $desired = $this->desiredChannels();
        $current = array_values(array_unique($currentChannels));

        return [
            'subscribe' => array_values(array_diff($desired, $current)),
            'unsubscribe' => array_values(array_diff($current, $desired)),
        ];
    }

    /**
     * Parse a `ui.invalidate.{tenant}.{scopeKey}` channel back to its
     * `(tenant, scopeKey)` pair — the exact inverse of
     * {@see ResourceInvalidationPublisher::channelFor()} (so producer and receiver
     * agree by construction). Splits the tenant off the first segment after the
     * prefix; the remainder is the scopeKey (a P1 resourceKey may itself be a
     * dotted name, so only the first separator is consumed). Returns null for any
     * channel that is not a well-formed invalidation channel.
     *
     * @return array{0: string, 1: string}|null `[tenant, scopeKey]`
     */
    public static function parseChannel(string $channel): ?array
    {
        $channel = trim($channel);
        $prefix = ResourceInvalidationPublisher::CHANNEL_PREFIX . '.';
        if (!str_starts_with($channel, $prefix)) {
            return null;
        }

        $rest = substr($channel, strlen($prefix));
        $parts = explode('.', $rest, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$tenant, $scopeKey] = $parts;
        if ($tenant === '' || $scopeKey === '') {
            return null;
        }

        return [$tenant, $scopeKey];
    }
}
