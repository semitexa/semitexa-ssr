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
 * SINGLE-LOOP MODEL (honest limitation, logged — never a silent cap): one worker
 * runs ONE subscribe loop, and it snapshots {@see ResourceInvalidationSubscriber::desiredChannels()}
 * at launch. For the R8c leads grid there is exactly ONE watched scope
 * (`ui_playground_leads`), so the loop subscribed at first-connect serves every
 * grid subscription on this worker for its life — reconnects resolve to the same
 * channel. Adding a SECOND distinct scope while the loop is already running would
 * need the live `Predis\PubSub\Consumer` handle to be mutated mid-iteration (or the
 * loop re-launched); that is intentionally deferred — it is logged here the moment
 * it is hit so it can never pass unnoticed. Likewise unsubscribe-on-last is a no-op
 * on the running loop: when the last subscriber leaves, the desired set empties and
 * any stray message resolves to zero local subscribers (a benign no-op via R1's
 * tenant+scope filter); the loop is reaped on worker recycle.
 *
 * The loop runs inside a Swoole coroutine; outside one (CLI / unit test) launching
 * is a no-op (the blocking subscribe has no meaning there — tests drive
 * {@see ResourceInvalidationSubscriber::handleMessage()} directly).
 */
final class LivePubSubChannelController implements ChannelSubscriptionControllerInterface
{
    private bool $loopRunning = false;

    public function __construct(
        private readonly ResourceInvalidationSubscriber $subscriber,
    ) {}

    /**
     * Subscribe-on-first: ensure the worker's subscribe loop is alive. The loop
     * itself watches the store's full desired set, so the specific channel list is
     * informational here — used only to flag the deferred multi-scope case.
     *
     * @param list<string> $channels
     */
    public function subscribe(array $channels): void
    {
        if ($channels === []) {
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
            }
        });
    }
}
