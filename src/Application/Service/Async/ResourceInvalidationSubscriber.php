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
    /**
     * @deprecated Aliases of {@see SseControlFrame}, kept because they are public
     *             and tests pin them. The vocabulary has ONE definition now —
     *             this class used to carry a second, independent copy.
     */
    public const CTRL_KEY = SseControlFrame::KEY;
    public const CTRL_RERUN = SseControlFrame::RERUN;

    /**
     * One Way Phase 4 — the live loop's CURRENT subscription snapshot, exposed
     * so the channel controller can detect when a NEW distinct scope appeared
     * after launch (the formerly-deferred multi-scope case) and interrupt the
     * blocked loop into a resubscribe.
     *
     * @var list<string>
     */
    private array $activeChannels = [];

    /** The live loop's dedicated connection — closed by {@see self::interrupt()}. */
    private ?\Predis\Client $activeConnection = null;

    /** Set by interrupt(): the next loop turn resubscribes WITHOUT backoff. */
    private bool $interrupted = false;

    /**
     * Track R · Gap C-3 — the loop is being TORN DOWN, not failing.
     *
     * Set either explicitly ({@see self::stop()}, driven from the WorkerExit
     * lifecycle) or, in the case that actually happens in production, by the loop
     * noticing that its own coroutine was cancelled ({@see self::wasCancelled()}).
     * Both mean the same thing: the drop is an operator action, so the loop
     * returns silently instead of logging a failure and re-parking on a fresh
     * connection inside a worker the manager is trying to drain.
     */
    private bool $stopping = false;

    /**
     * Track R · Gap C-3 — reconnect backoff AND log severity are both derived from
     * the consecutive-failure streak this policy holds. The old flat
     * `RECONNECT_BACKOFF_SECONDS = 1.0` constant is now
     * {@see ReconnectAlarmPolicy::BASE_BACKOFF_SECONDS}, doubling to a 30s cap so a
     * hard-down Redis cannot spin one attempt per second for a whole outage. Idle
     * no longer drops the connection (read_write_timeout: -1 in
     * {@see RedisSubscribeConnectionFactory}); this path is real failures only.
     */
    private readonly ReconnectAlarmPolicy $alarm;

    /**
     * Track R · Gap C-3 — the one-shot timer that closes an open incident.
     *
     * Recovery cannot be observed from the drop path alone: the loop is parked
     * inside `pubSubLoop`, so "this connection has held" only becomes true while
     * nothing is happening. Announcing it on the NEXT drop would mean an operator
     * who was warned might never see it clear — the loop could stay healthy for
     * weeks. So a successful subscribe arms a timer for
     * {@see ReconnectAlarmPolicy::HEALTHY_DWELL_SECONDS}; if the connection is
     * still up when it fires, the incident closes. Cleared when the turn ends, so
     * a connection that dropped before the dwell never announces recovery.
     */
    private ?int $recoveryTimerId = null;

    public function __construct(
        private readonly SubscriberIndexInterface $index,
        private readonly SubscriptionTable $subscriptions,
        private readonly RerunCoalescer $coalescer,
        private readonly RedisSubscribeConnectionFactory $connectionFactory,
        private readonly SessionControlDeliveryInterface $delivery,
        ?ReconnectAlarmPolicy $alarm = null,
    ) {
        // Optional so every existing construction site (the R8c wiring listener,
        // the R3 tests) keeps working unchanged, injectable so the escalation
        // ladder can be driven deterministically from a test.
        $this->alarm = $alarm ?? new ReconnectAlarmPolicy();
    }

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
            if ($this->stopping) {
                return; // worker teardown — not a failure, nothing to report.
            }

            $channels = $this->desiredChannels();
            if ($channels === []) {
                return; // no local subscribers → nothing to subscribe to (C2).
            }

            $connection = $this->connectionFactory->create(); // DEDICATED — never the pool.

            // Publish the live snapshot BEFORE blocking so the controller's
            // covers-check ({@see self::isSubscribedTo()}) sees what this loop
            // turn actually subscribed to.
            $this->activeChannels = $channels;
            $this->activeConnection = $connection;
            $this->interrupted = false;

            $subscribedAt = hrtime(true);

            try {
                /** @var \Predis\PubSub\Consumer $pubsub */
                $pubsub = $connection->pubSubLoop(['subscribe' => $channels]);

                // The subscribe succeeded. If an incident is open, start the clock
                // that closes it — see self::$recoveryTimerId.
                $this->armRecoveryNotice();

                foreach ($pubsub as $message) {
                    if (($message->kind ?? null) === 'message') {
                        $this->handleMessage((string) $message->channel);
                    }
                }
            } catch (\Throwable $e) {
                // FIRST, before anything that could yield: was this coroutine
                // cancelled? That answer is the difference between "Redis died"
                // and "this worker is going down" (see self::wasCancelled()).
                $this->onDropped(
                    $e,
                    (hrtime(true) - $subscribedAt) / 1_000_000_000,
                    self::wasCancelled(),
                );
            } finally {
                $this->disarmRecoveryNotice();
                $this->activeConnection = null;
                $this->activeChannels = [];
                try {
                    $connection->disconnect();
                } catch (\Throwable) {
                    // Best-effort close of the dedicated connection.
                }
            }

            // TEARDOWN ends the loop immediately: no log line, no backoff, and —
            // just as important — no fresh subscribe. Re-parking on a new socket
            // inside the reload_async drain window is what turns a cancelled
            // coroutine into "worker exit timeout, forced termination".
            if ($this->stopping) {
                return; // latchTeardown() already cleared the streak and the notice.
            }

            // An INTERRUPT (a new distinct scope appeared — see interrupt()) is
            // not a failure: resubscribe immediately so the first invalidation
            // on the new scope is not lost to a backoff window.
            if ($this->interrupted) {
                $this->interrupted = false;
                continue;
            }

            // Back off before re-subscribing so a hard-down Redis can't spin a
            // tight loop — exponential in the current streak, capped (Gap C-3).
            // desiredChannels() is re-evaluated at the top of the next iteration.
            \Swoole\Coroutine::sleep($this->alarm->backoffSeconds());

            // The SECOND place a teardown can land. `onWorkerExit` cancels parked
            // coroutines, and a cancelled sleep does NOT throw — it just returns
            // early — so without this check a loop that happened to be backing off
            // (i.e. Redis was already down when the operator restarted) would walk
            // straight back to the top and re-subscribe inside the drain window.
            // Read immediately after the yield, for the reason given in
            // {@see self::wasCancelled()}.
            if (self::wasCancelled()) {
                $this->latchTeardown();

                return;
            }
        }
    }

    /**
     * Track R · Gap C-3 — was THIS coroutine cancelled out from under its blocking
     * read? That is the production teardown signal, and it is the one the loop was
     * missing (issue #100).
     *
     * `SwooleBootstrap`'s `onWorkerExit` handler clears every timer and calls
     * `Swoole\Coroutine::cancel()` on every parked coroutine, on the stated
     * principle that "cancellation turns an immortal wait into a catchable failure
     * on code that already handles transport errors". This loop is exactly such
     * code — but it handled the cancellation as a Redis FAILURE, logged ERROR, and
     * reconnected. With `reload_async` + `max_wait_time: 3` that happens on every
     * `server:restart`, once per worker, which is where the reporter's 40 ERROR
     * lines came from.
     *
     * NOTE ON PLACEMENT: this cannot be a lifecycle listener. `onWorkerExit`
     * cancels coroutines BEFORE it invokes the WorkerExit phase, and the WorkerStop
     * phase runs only after the event loop has already exited — by then this loop
     * has long since caught, logged and reconnected. The signal has to be read from
     * inside the coroutine, at the moment it is resumed.
     *
     * Read once, first thing in the catch: Swoole clears the flag on the next
     * successful yield, so anything that suspends first would lose it.
     */
    private static function wasCancelled(): bool
    {
        if (!class_exists(\Swoole\Coroutine::class, false)) {
            return false;
        }

        // Guarded: isCanceled() landed in Swoole 4.7. On an older runtime the
        // explicit stop() seam is the only teardown signal, which is the pre-Gap-C-3
        // behaviour rather than a new failure mode.
        if (!method_exists(\Swoole\Coroutine::class, 'isCanceled')) {
            return false;
        }

        return \Swoole\Coroutine::isCanceled();
    }

    /**
     * Arm the recovery notice ({@see self::$recoveryTimerId}) for the connection
     * that just subscribed. No-op unless an incident is actually open — in steady
     * state there is nothing to close and no timer worth creating.
     */
    private function armRecoveryNotice(): void
    {
        $this->disarmRecoveryNotice();

        if ($this->alarm->consecutiveFailures() === 0) {
            return;
        }

        if (!class_exists(\Swoole\Timer::class, false)) {
            return; // no reactor (CLI/test): markHealthy() is driven directly.
        }

        $this->recoveryTimerId = \Swoole\Timer::after(
            (int) (ReconnectAlarmPolicy::HEALTHY_DWELL_SECONDS * 1000),
            function (): void {
                $this->recoveryTimerId = null;
                $this->markHealthy();
            },
        );
    }

    /** Drop the pending recovery notice — this turn's connection did not hold. */
    private function disarmRecoveryNotice(): void
    {
        if ($this->recoveryTimerId === null) {
            return;
        }

        if (class_exists(\Swoole\Timer::class, false)) {
            \Swoole\Timer::clear($this->recoveryTimerId);
        }

        $this->recoveryTimerId = null;
    }

    /**
     * The live connection has held for the full dwell: clear the failure streak
     * and, if an incident had been announced, close it.
     *
     * PUBLIC as a test seam, like {@see self::onDropped()} — the timer that calls
     * it only exists inside a worker reactor.
     */
    public function markHealthy(): void
    {
        if ($this->stopping) {
            return;
        }

        $recoveredFrom = $this->alarm->recordDwell(ReconnectAlarmPolicy::HEALTHY_DWELL_SECONDS);
        if ($recoveredFrom === null) {
            return;
        }

        StaticLoggerBridge::info('ssr', 'Resource-invalidation subscribe loop recovered', [
            'recovered_after_failed_reconnects' => $recoveredFrom,
        ]);
    }

    /**
     * Track R · Gap C-3 — handle ONE dropped connection turn: decide whether it was
     * a teardown or a failure, and report a failure at the severity the STREAK
     * deserves rather than the severity the event feels like (issue #100).
     *
     * A drop the loop recovers from is DEBUG: it is the self-heal working, and
     * routing it to WARNING+ turns every transient blip into an ops incident.
     * WARNING and ERROR are reserved for the state the message actually wants to
     * report — "this loop cannot get back on" — and are emitted at the crossings
     * (plus a throttled ERROR re-assert), never once per attempt.
     *
     * PUBLIC as a test seam, for the same reason {@see self::handleMessage()} is:
     * the loop that calls it only runs inside a Swoole coroutine, so the decision
     * has to be drivable from outside one. `$cancelled` is passed IN rather than
     * probed here so a test can exercise the teardown branch without cancelling a
     * real coroutine.
     *
     * @param bool $cancelled the coroutine was cancelled out from under the read —
     *                        worker teardown, NOT a Redis failure
     *                        ({@see self::wasCancelled()})
     */
    public function onDropped(\Throwable $e, float $uptimeSeconds, bool $cancelled = false): void
    {
        if ($cancelled) {
            // Latch teardown: the loop returns on its next check, silently.
            $this->latchTeardown();

            return;
        }

        if ($this->interrupted || $this->stopping) {
            return; // an announced transition, not a failure.
        }

        // A connection that HELD before dropping closes any open incident first.
        $recoveredFrom = $this->alarm->recordDwell($uptimeSeconds);
        if ($recoveredFrom !== null) {
            StaticLoggerBridge::info('ssr', 'Resource-invalidation subscribe loop recovered', [
                'recovered_after_failed_reconnects' => $recoveredFrom,
                'uptime_seconds' => round($uptimeSeconds, 3),
            ]);
        }

        $level = $this->alarm->recordDrop();
        $context = [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'consecutive_failures' => $this->alarm->consecutiveFailures(),
            'uptime_seconds' => round($uptimeSeconds, 3),
            'next_retry_in_seconds' => $this->alarm->backoffSeconds(),
        ];

        match ($level) {
            ReconnectAlarmLevel::Error => StaticLoggerBridge::error(
                'ssr',
                'Resource-invalidation subscribe loop cannot reconnect; invalidations are not being received',
                $context,
            ),
            ReconnectAlarmLevel::Warning => StaticLoggerBridge::warning(
                'ssr',
                'Resource-invalidation subscribe loop has failed several reconnects; still retrying',
                $context,
            ),
            ReconnectAlarmLevel::Debug => StaticLoggerBridge::debug(
                'ssr',
                'Resource-invalidation subscribe loop dropped; reconnecting',
                $context,
            ),
            ReconnectAlarmLevel::Silent => null,
        };
    }

    /**
     * Track R · Gap C-3 — mark the loop as torn down, from either of the two places
     * a cancellation can land: out of the blocking read ({@see self::onDropped()})
     * or out of the backoff sleep. Clearing the streak and the pending recovery
     * notice here means neither survives into the next worker's state.
     *
     * Idempotent, and deliberately does NOT touch {@see self::$activeConnection} —
     * on the read path the `finally` already closed it, and on the backoff path
     * there is nothing open. {@see self::stop()} is the variant that also has to
     * break a live connection.
     */
    private function latchTeardown(): void
    {
        $this->stopping = true;
        $this->alarm->reset();
        $this->disarmRecoveryNotice();
    }

    /**
     * Track R · Gap C-3 — end the loop for teardown (worker exit, worker recycle,
     * an explicit shutdown). Mirror of {@see self::interrupt()}: it closes the
     * dedicated connection out from under the blocking read, but marks the
     * resulting failure as EXPECTED, so the loop returns without logging it and
     * without reconnecting into a worker that is going away.
     *
     * Driven by {@see ConnectCoordinator::shutdown()} from the WorkerExit phase.
     * That listener is a SECOND line of defence, not the primary one — see
     * {@see self::wasCancelled()} for why the primary signal has to be read from
     * inside the coroutine. Idempotent and safe with no live loop.
     */
    public function stop(): void
    {
        $this->latchTeardown();

        $connection = $this->activeConnection;
        if ($connection === null) {
            return;
        }

        try {
            $connection->disconnect();
        } catch (\Throwable) {
            // Best-effort: the worker is going down either way.
        }
    }

    /**
     * Has this subscriber been told to tear down? Exposed so the teardown seam is
     * assertable without a live coroutine (the loop itself is not).
     */
    public function isStopping(): bool
    {
        return $this->stopping;
    }

    /**
     * One Way Phase 4 — does the live loop's CURRENT subscription cover every
     * requested channel? False when the loop is not running (no snapshot) or a
     * NEW distinct scope appeared after launch — the controller then calls
     * {@see self::interrupt()} so the loop resubscribes with the full desired set.
     *
     * @param list<string> $channels
     */
    public function isSubscribedTo(array $channels): bool
    {
        foreach ($channels as $channel) {
            if (!in_array($channel, $this->activeChannels, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One Way Phase 4 — kick the blocked loop into a resubscribe. Closes the
     * dedicated connection out from under the blocking `pubSubLoop` read; the
     * Gap C self-heal catches the resulting failure, skips the backoff (the
     * `interrupted` flag), re-reads {@see self::desiredChannels()} — which now
     * includes the new scope — and subscribes the full set. This retires the
     * single-loop model's deferred multi-scope limitation: a second distinct
     * live scope on a worker (e.g. a pings feed joining a leads feed) is
     * picked up within one loop turn instead of never.
     */
    public function interrupt(): void
    {
        $connection = $this->activeConnection;
        if ($connection === null) {
            return;
        }

        $this->interrupted = true;
        try {
            $connection->disconnect();
        } catch (\Throwable) {
            // Best-effort: if the close races the loop's own teardown, the
            // while(true) re-read covers the new scope anyway.
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

            $this->delivery->deliverControl(
                $ref->sessionId,
                SseControlFrame::rerun($ref->streamingId, $scopeKey),
            );
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
