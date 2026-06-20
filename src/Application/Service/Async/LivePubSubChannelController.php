<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Ssr\Domain\Contract\ChannelSubscriptionControllerInterface;

/**
 * Track R · R8c (C2) — the live {@see ChannelSubscriptionControllerInterface}: the
 * binding that actually attaches the running Redis Pub/Sub consumer to the connect
 * lifecycle (the binding the R5 contract docblock said "is the dispatcher-wiring /
 * R8 brick's concern, not R5's").
 *
 * R5's {@see ConnectCoordinator::reconcileChannels()} computes a subscribe/unsubscribe
 * delta from R1's store and APPLIES it here. R3's {@see ResourceInvalidationSubscriber::run()}
 * owns the blocking `pubSubLoop`, subscribing (on a DEDICATED connection) to exactly
 * {@see ResourceInvalidationSubscriber::desiredChannels()} — the store's current
 * desired set — at launch. So this controller's job is narrow: ensure that loop is
 * ALIVE whenever there is at least one local subscriber. Because the loop reads the
 * desired set itself, "subscribe-on-first" reduces to "launch the loop once a scope
 * exists", and the loop then watches the whole desired set.
 *
 * SINGLE-LOOP MODEL: one worker runs ONE subscribe loop, which snapshots
 * {@see ResourceInvalidationSubscriber::desiredChannels()} at each (re)launch.
 * One Way Phase 4 retired the formerly-deferred multi-scope limitation: when a
 * SECOND distinct scope appears while the loop is already running (two live
 * feeds on one worker — e.g. a pings stream joining a leads stream), this
 * controller detects the uncovered channel ({@see ResourceInvalidationSubscriber::isSubscribedTo()})
 * and INTERRUPTS the blocked loop ({@see ResourceInvalidationSubscriber::interrupt()});
 * the loop's Gap C self-heal then resubscribes with the full desired set within
 * one turn, no backoff. Unsubscribe-on-last stays a no-op on the running loop:
 * when the last subscriber leaves, the desired set empties and any stray message
 * resolves to zero local subscribers (a benign no-op via R1's tenant+scope
 * filter); the stale subscription is corrected at the next interrupt/reconnect
 * or reaped on worker recycle.
 *
 * The loop runs inside a Swoole coroutine; outside one (CLI / unit test) launching
 * is a no-op (the blocking subscribe has no meaning there — tests drive
 * {@see ResourceInvalidationSubscriber::handleMessage()} directly).
 */
final class LivePubSubChannelController implements ChannelSubscriptionControllerInterface
{
    /**
     * Keep-alive tick cadence (ms) for the worker that owns the blocking subscribe
     * loop. See {@see self::$keepAliveTimerId}.
     */
    private const KEEPALIVE_INTERVAL_MS = 1000;

    private bool $loopRunning = false;

    /**
     * Track R · Gap C-2 — the worker-reactor keep-alive timer id, alive for exactly
     * as long as the subscribe loop coroutine runs.
     *
     * The blocking `pubSubLoop` parks its coroutine on a socket read. When it is the
     * ONLY live coroutine in an otherwise-idle worker (subscribe-on-first fired but
     * no request is in flight, e.g. just after WorkerStart), Swoole's scheduler sees
     * a single suspended coroutine with nothing pending and raises the FATAL
     * "all coroutines (count: 1) are asleep - deadlock!", killing the worker — and
     * with it every held-open KISS connection that worker owns, so live invalidations
     * stop re-running until reconnect. A persistent {@see \Swoole\Timer::tick} keeps
     * the reactor's event queue non-empty for the loop's whole lifetime, so the lone
     * parked subscribe coroutine can never be mistaken for a deadlock. The tick body
     * is intentionally empty — its existence, not its work, is the guarantee. Cleared
     * in the loop coroutine's `finally`, so it lives exactly as long as the loop.
     */
    private ?int $keepAliveTimerId = null;

    public function __construct(
        private readonly ResourceInvalidationSubscriber $subscriber,
    ) {}

    /**
     * Subscribe-on-first: ensure the worker's subscribe loop is alive, and —
     * One Way Phase 4 — that its live subscription COVERS the requested
     * channels. The loop snapshots the desired set at launch; when a NEW
     * distinct scope appears afterwards (a second live feed on this worker,
     * e.g. a pings stream joining a leads stream), the blocked loop is
     * interrupted ({@see ResourceInvalidationSubscriber::interrupt()}) so its
     * Gap C self-heal resubscribes with the full desired set within one turn.
     * This retires the former single-loop "deferred multi-scope" limitation,
     * under which the second scope stayed silently deaf for the worker's life.
     *
     * @param list<string> $channels
     */
    public function subscribe(array $channels): void
    {
        if ($channels === []) {
            return;
        }

        if ($this->loopRunning && !$this->subscriber->isSubscribedTo($channels)) {
            StaticLoggerBridge::debug('ssr', 'track_r_channel_resubscribe', [
                'channels' => $channels,
                'note' => 'new distinct scope after launch — interrupting the subscribe loop into a full resubscribe',
            ]);
            $this->subscriber->interrupt();

            return;
        }

        $this->ensureLoopRunning();
    }

    /**
     * Unsubscribe-on-last: a no-op on the running single loop (the desired set has
     * already emptied in R1's store, so the loop resolves stray messages to zero
     * local subscribers). Logged so the single-loop model is never silent.
     *
     * @param list<string> $channels
     */
    public function unsubscribe(array $channels): void
    {
        if ($channels === []) {
            return;
        }

        StaticLoggerBridge::debug('ssr', 'track_r_channel_unsubscribe_noop', [
            'channels' => $channels,
            'note' => 'single-loop model: channel left subscribed; stray messages resolve to zero local subscribers',
        ]);
    }

    /**
     * (Re)launch the blocking subscribe loop in its own coroutine when one is not
     * already running. Relaunchable: the running flag is reset in the coroutine's
     * `finally`, so a loop that exits (connection dropped, desired set was empty)
     * is restarted on the next connect rather than dying silently.
     */
    private function ensureLoopRunning(): void
    {
        if ($this->loopRunning) {
            return;
        }

        if (!class_exists(\Swoole\Coroutine::class, false) || \Swoole\Coroutine::getCid() < 0) {
            // No coroutine runtime (CLI / unit test): the blocking pubSubLoop has no
            // meaning here. Tests drive handleMessage() directly.
            return;
        }

        $this->loopRunning = true;
        $this->startKeepAlive();
        \Swoole\Coroutine::create(function (): void {
            try {
                $this->subscriber->run();
            } catch (\Throwable $e) {
                StaticLoggerBridge::error('ssr', 'track_r_subscribe_loop_crashed', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            } finally {
                $this->loopRunning = false;
                $this->stopKeepAlive();
            }
        });
    }

    /**
     * Arm the worker-reactor keep-alive for the subscribe loop's lifetime so the lone
     * parked `pubSubLoop` coroutine cannot trip Swoole's deadlock detector
     * ({@see self::$keepAliveTimerId}). No-op when already armed or `Swoole\Timer` is
     * unavailable (CLI/test — the loop itself is a no-op there).
     */
    private function startKeepAlive(): void
    {
        if ($this->keepAliveTimerId !== null || !class_exists(\Swoole\Timer::class, false)) {
            return;
        }

        $this->keepAliveTimerId = \Swoole\Timer::tick(self::KEEPALIVE_INTERVAL_MS, static function (): void {
            // Intentionally empty: a pending timer keeps the reactor non-empty so a
            // lone parked subscribe coroutine is never seen as a deadlock.
        });
    }

    /** Disarm the keep-alive when the subscribe loop exits (its lifetime ended). */
    private function stopKeepAlive(): void
    {
        if ($this->keepAliveTimerId === null || !class_exists(\Swoole\Timer::class, false)) {
            $this->keepAliveTimerId = null;

            return;
        }

        \Swoole\Timer::clear($this->keepAliveTimerId);
        $this->keepAliveTimerId = null;
    }
}
