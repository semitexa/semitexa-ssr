<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Ssr\Domain\Contract\ChannelSubscriptionControllerInterface;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * Track R · R5 — the per-connection lifecycle that POPULATES (and depopulates)
 * the three-tier subscription store the rest of Track R consumes (design §D R5,
 * §B.1/§B.5, §C.4-§C.6).
 *
 * R5 is the brick that turns R1's tiers, R3's subscribe seam, and R3's coalescer
 * table into a live per-connection lifecycle. On a subject-gated kiss subscription
 * it places each piece on the CORRECT plane, and on disconnect it reaps every
 * piece so no zombie subscription, leaked channel subscription, or orphaned DTO
 * remains.
 *
 * TWO PLANES (the cross-worker nuance, design §C.6) — each piece on its surface:
 *  - WORKER-LOCAL (tier 2): the live {@see ReRunContext} (R2) goes into the
 *    worker-static {@see SubscriptionDtoRegistry} on the OWNING worker — the worker
 *    whose fd holds this stream (`dispatch_mode 2` pins the fd for the stream's
 *    life). It is NEVER serialized and NEVER placed on another worker.
 *  - CROSS-WORKER (tier 1 + coalescer): the {@see SubscriptionRecord} row
 *    (scope_keys, tenant_blob, …) goes into the shared {@see SubscriptionTable}
 *    visible to all workers; the {@see RerunCoalescer} table is shared mmap.
 *
 * SCOPE FENCE — populate/depopulate ONLY:
 *  - It does NOT catch the `{__ctrl:rerun}` control and does NOT run the re-run
 *    (R4). This class references no re-runner / `reExecute` / `ReRunContext` method
 *    call — it only STORES the {@see ReRunContext} R4 will later consume.
 *  - It is NOT the live kiss endpoint (R8). R5 is proven on a SYNTHETIC connect
 *    (a test supplying a sessionId + scope + a {@see ReRunContext}); the live
 *    HTTP/kiss subscription that calls {@see onConnect()} / {@see onDisconnect()}
 *    is R8.
 *
 * The coordinator is WORKER-LOCAL: one instance per worker, tracking the channels
 * THIS worker's `pubSubLoop` is currently subscribed to in {@see $currentChannels}.
 */
final class ConnectCoordinator
{
    /**
     * The channels this worker's live Pub/Sub loop is currently subscribed to.
     * Reconciled against the store's desired set on every connect/disconnect, so
     * after any lifecycle event it mirrors {@see ResourceInvalidationSubscriber::desiredChannels()}.
     *
     * @var list<string>
     */
    private array $currentChannels = [];

    public function __construct(
        private readonly SubscriptionTable $subscriptions,
        private readonly ResourceInvalidationSubscriber $subscriber,
        private readonly RerunCoalescer $coalescer,
        private readonly ChannelSubscriptionControllerInterface $channels,
        private readonly ?ViewChangeCoalescer $viewChanges = null,
    ) {}

    /**
     * C1 — On connect (a subject-gated kiss subscription): populate BOTH planes
     * and subscribe-on-first.
     *
     *  1. Insert the tier-1 {@see SubscriptionRecord} (streaming_id, session_id,
     *     tenant_id, scope_keys derived from the watched resources via P1
     *     `resourceKey`, tenant_blob = serialized tenant) into the cross-worker
     *     {@see SubscriptionTable}.
     *  2. Store the live {@see ReRunContext} (R2) in the worker-local
     *     {@see SubscriptionDtoRegistry} under `streaming_id`, on THIS (the owning)
     *     worker. Never serialized, never another worker.
     *  3. Drive R3's subscribe seam: reconcile the channel set so the new scope's
     *     `ui.invalidate.{tenant}.{scopeKey}` channel is subscribed on the FIRST
     *     local subscriber for that scope — and not re-subscribed when a later
     *     subscription already watches it.
     *
     * The record and context share `streaming_id` — the linkage R4 follows from a
     * cross-worker control back to the worker-local re-run state.
     */
    public function onConnect(SubscriptionRecord $record, ReRunContext $context): void
    {
        // Tier 1 (cross-worker, serialized): the routable, identity-free row.
        $this->subscriptions->insert($record);

        // Tier 2 (worker-local, owning worker): the live re-run state. This is the
        // ONLY tier that holds a live object; it never reaches the cross-worker
        // table (the tier-separation invariant, R1).
        SubscriptionDtoRegistry::set($record->streamingId, $context);

        // Subscribe-on-first: the row is now in the store, so desiredChannels()
        // includes this scope's channel; the diff yields a subscribe only when no
        // prior subscription already watched it.
        $this->reconcileChannels();
    }

    /**
     * C3 — On disconnect: full teardown (no zombies, design §B.5).
     *
     *  - Remove the tier-1 row;
     *  - remove the tier-2 {@see ReRunContext};
     *  - unsubscribe-on-last (if this was the last local subscriber for the scope,
     *    unsubscribe its channel via the channel diff);
     *  - clear any pending coalescer mark for the stream, so a stale mark cannot
     *    suppress a future subscription's re-run.
     *
     * After this, nothing about the stream remains in ANY tier.
     */
    public function onDisconnect(string $streamingId): void
    {
        // Tier 1 row gone.
        $this->subscriptions->remove($streamingId);

        // Tier 2 live state gone (orphaned-DTO guard).
        SubscriptionDtoRegistry::remove($streamingId);

        // Clear any pending re-run mark for this stream. Dropping the stream while a
        // re-run was coalesced (pending) would otherwise leak a counter row; clearing
        // it both reaps the row and frees a later stream's signal to enqueue afresh.
        $this->coalescer->clearPending($streamingId);

        // Same discipline for a pending view-change: streaming ids are minted
        // random and never reused, so a row left by a disconnect-before-drain
        // would leak in the shared table for the worker's whole life.
        $this->viewChanges?->consume($streamingId);

        // Unsubscribe-on-last: the row is now gone, so desiredChannels() no longer
        // includes this scope's channel WHEN it was the last subscriber; the diff
        // yields an unsubscribe only then (never while another subscription remains).
        $this->reconcileChannels();
    }

    /**
     * SSE transport unification · Phase 1.5 — reap EVERY subscription attached to a
     * closing connection's session, in one sweep.
     *
     * A multiplexed KISS connection holds N subscriptions (distinct streaming_ids,
     * one shared session_id). The standalone own-route stream reaps its single
     * streaming_id explicitly on teardown, but the KISS close path has no
     * per-subscription handle — so when the fd dies, this scans the cross-worker
     * table for every row bound to that session and runs the full {@see onDisconnect()}
     * teardown on each, leaving no zombie row that R3 would keep delivering re-run
     * controls to. Streaming ids are collected first, then disconnected, so the
     * table is not mutated mid-iteration. Idempotent: a row already reaped (e.g. the
     * standalone path's explicit onDisconnect) is simply absent.
     *
     * @return list<string> the streaming ids reaped (for logging / tests)
     */
    public function reapSession(string $sessionId): array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return [];
        }

        $streamingIds = [];
        foreach ($this->subscriptions->all() as $record) {
            if ($record->sessionId === $sessionId) {
                $streamingIds[] = $record->streamingId;
            }
        }

        foreach ($streamingIds as $streamingId) {
            $this->onDisconnect($streamingId);
        }

        return $streamingIds;
    }

    /**
     * The channels this worker's Pub/Sub loop is currently subscribed to (mirrors
     * the store's desired set after the last reconcile). Exposed for the synthetic
     * proof of subscribe-on-first / unsubscribe-on-last.
     *
     * @return list<string>
     */
    public function currentChannels(): array
    {
        return $this->currentChannels;
    }

    /**
     * Apply R3's channel diff to the live Pub/Sub loop and re-sync the local
     * current-channel set to the store's desired set.
     *
     * Driving R3's seam ({@see ResourceInvalidationSubscriber::channelDiff()})
     * keeps the subscribe-on-first / unsubscribe-on-last decision in ONE place:
     * the diff reports a channel under `subscribe` only on the transition from
     * "no subscriber for this scope" to "one", and under `unsubscribe` only on the
     * transition from "one" to "none" — every intermediate connect/disconnect is a
     * no-op. (`desiredChannels()` spans this instance's shared cross-worker store —
     * R1 omitted a `worker_id` column — so the channel reach is instance-wide by
     * R3's established model; the coalescer makes any cross-worker duplicate
     * resolution harmless.)
     */
    private function reconcileChannels(): void
    {
        $diff = $this->subscriber->channelDiff($this->currentChannels);

        if ($diff['subscribe'] !== []) {
            $this->channels->subscribe($diff['subscribe']);
        }
        if ($diff['unsubscribe'] !== []) {
            $this->channels->unsubscribe($diff['unsubscribe']);
        }

        // After applying the delta, the loop is subscribed to exactly the desired set.
        $this->currentChannels = $this->subscriber->desiredChannels();
    }
}
