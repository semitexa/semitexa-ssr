<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Pipeline\ReRun\ReRunContext;

/**
 * Tier 2 of the three-tier subscription store (Track R · R1, design §C.6): the
 * worker-static DTO registry.
 *
 * A worker-LOCAL map `streaming_id => ReRunContext`, parallel to
 * {@see AsyncResourceSseServer::$sessions}. It holds the live cached Payload DTO
 * and the live re-run state (R2's {@see ReRunContext}, which carries the cached
 * DTO, request snapshot, subject ref and the live tenant context) that R4's loop
 * branch consumes on each re-run tick.
 *
 * THE TIER-SEPARATION INVARIANT (the security boundary, design §C.6): this tier,
 * and ONLY this tier, holds the live identity-bearing object. It is:
 *  - **worker-local** — a process-static map, never shared across workers. Safe
 *    because `dispatch_mode 2` pins a stream's fd to its owning worker W for the
 *    stream's life (design §C / §B.2), so only W ever re-runs `streaming_id` S;
 *  - **never serialized** — the {@see ReRunContext} is a live object and never
 *    crosses a worker boundary or a wire. The serialized form a cross-worker
 *    reader needs (tenant blob, scope keys) lives in the {@see SubscriptionTable}
 *    tier instead.
 *
 * If this live state ever entered the cross-worker {@see SubscriptionTable} it
 * would break on a non-owner worker AND re-open the poisoning vector R2 closed
 * (identity-bearing state must never reach a shared serialized surface). Keeping
 * the two tiers in distinct representations is what enforces that.
 *
 * R1 is the store ONLY: this registry has no connect/loop logic. It is populated
 * by the connect coordinator (R5) and read by the loop branch (R4). A worker
 * recycle silently drops this map mid-stream — correct behaviour (the client
 * reconnects and re-auths), bounding effective stream life (design §B.5).
 */
final class SubscriptionDtoRegistry
{
    /** @var array<string, ReRunContext> worker-local: streaming_id => live re-run state */
    private static array $entries = [];

    public static function set(string $streamingId, ReRunContext $context): void
    {
        self::$entries[$streamingId] = $context;
    }

    public static function get(string $streamingId): ?ReRunContext
    {
        return self::$entries[$streamingId] ?? null;
    }

    public static function has(string $streamingId): bool
    {
        return isset(self::$entries[$streamingId]);
    }

    public static function remove(string $streamingId): void
    {
        unset(self::$entries[$streamingId]);
    }

    public static function count(): int
    {
        return count(self::$entries);
    }

    /**
     * Drop all entries. Used on worker recycle and for test isolation. NEVER a
     * cross-worker operation — it clears only this worker's local map.
     */
    public static function clear(): void
    {
        self::$entries = [];
    }
}
