<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

/**
 * Track R · R5 — the narrow seam by which the connect coordinator APPLIES a
 * channel subscribe/unsubscribe delta to the live Pub/Sub loop (design §C.3).
 *
 * R3 computes the delta ({@see \Semitexa\Ssr\Application\Service\Async\ResourceInvalidationSubscriber::channelDiff()}):
 * which `ui.invalidate.{tenant}.{scopeKey}` channels this instance SHOULD now be
 * subscribed to, given the store's current state. R5 (the connect coordinator) is
 * what actually applies that delta to the running `pubSubLoop` on connect/teardown
 * — subscribe-on-first-subscriber-for-scope, unsubscribe-on-last.
 *
 * This capability is a contract for two reasons:
 *  1. The live application target — a {@see \Predis\PubSub\Consumer} created inside
 *     R3's blocking coroutine — only exists inside the running worker runtime, so
 *     R5 must be proven SYNTHETICALLY: a unit test drives the coordinator with a
 *     CAPTURING double that records the (un)subscribe calls, exactly as R3 used a
 *     capturing delivery double. No live Redis / coroutine is required to prove the
 *     subscribe-on-first / unsubscribe-on-last lifecycle.
 *  2. It keeps the coordinator's lifecycle logic (what to (un)subscribe, and when)
 *     independent of HOW the live consumer is mutated — the live binding that
 *     attaches the running consumer and launches the loop is the dispatcher-wiring
 *     / R8 brick's concern, not R5's.
 *
 * It carries ONLY channel names — no row data, no re-run, no fan-out.
 */
interface ChannelSubscriptionControllerInterface
{
    /**
     * Subscribe the live Pub/Sub loop to each given channel (subscribe-on-first).
     * A no-op for an empty list. Channels are `ui.invalidate.{tenant}.{scopeKey}`
     * names; the implementation must be idempotent for an already-watched channel.
     *
     * @param list<string> $channels
     */
    public function subscribe(array $channels): void;

    /**
     * Unsubscribe the live Pub/Sub loop from each given channel (unsubscribe-on-last).
     * A no-op for an empty list.
     *
     * @param list<string> $channels
     */
    public function unsubscribe(array $channels): void;
}
