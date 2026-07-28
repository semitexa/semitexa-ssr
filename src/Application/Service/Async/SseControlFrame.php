<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

/**
 * The control-plane vocabulary: what a control marker looks like on the wire,
 * and what handling one can conclude.
 *
 * A control frame is a **signal, never bytes for the wire**. It rides the same
 * per-session queues as data frames, but a drain that failed to recognise one
 * would write `{"__ctrl":"rerun"}` straight to the browser as if it were content.
 * That is the failure this class exists to prevent: the marker key, the four
 * kinds, and the three possible outcomes now have exactly one definition instead
 * of being spelled out at each site that speaks the protocol.
 *
 * There are **three** such drains — the worker-local queue, the Swoole
 * deliver-table fallback, and the Redis session queue — plus the submit helpers
 * that produce the frames. Four places, one vocabulary.
 *
 * Extracted ahead of the handlers themselves (`tk-sse-control-router`): the
 * vocabulary is pure and separable, whereas dispatching needs nine collaborators
 * and is much cleaner once `tk-sse-wire-di` has moved the worker-boot slots into
 * the container.
 */
final class SseControlFrame
{
    /** The key whose presence marks a frame as a control signal. */
    public const KEY = '__ctrl';

    /** Re-run the cached DTO verbatim, no override (Track R · R4). */
    public const RERUN = 'rerun';

    /**
     * Re-run with a FILTER-ONLY parameter override — a new page / limit / sort /
     * filter pushed on the SAME open fd. Identity is never overridable: the
     * override is applied marker-gated in core's `reExecute`, which is the R2
     * anti-poisoning invariant.
     */
    public const VIEWCHANGE = 'viewchange';

    /** Attach a feed subscription to the fd-owning connection (multiplex Phase 1). */
    public const SUBSCRIBE = 'subscribe';

    /** Detach one. */
    public const UNSUBSCRIBE = 'unsubscribe';

    /** Not a control frame — the caller writes it as an ordinary data frame. */
    public const NOT_CONTROL = 0;

    /** Consumed (handled, or a safe no-op); the drain continues. */
    public const HANDLED_CONTINUE = 1;

    /** Consumed and the stream must close — lost access, or a failed write. */
    public const HANDLED_CLOSE = 2;

    /**
     * The control kind carried by a frame, or `null` when it is ordinary data.
     *
     * Deliberately strict: only a string marker counts. A frame carrying, say,
     * `__ctrl: true` is data with an unfortunate key, not a control signal, and
     * must not be silently swallowed by the dispatcher.
     *
     * @param array<string, mixed> $data
     */
    public static function kindOf(array $data): ?string
    {
        $kind = $data[self::KEY] ?? null;

        return is_string($kind) && $kind !== '' ? $kind : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function isControl(array $data): bool
    {
        return self::kindOf($data) !== null;
    }

    /**
     * A mutation-driven re-run signal. `scope_key` names the invalidated scope
     * that produced it and travels with the marker for tracing — the re-run
     * itself is driven by `streaming_id`.
     *
     * @return array<string, mixed>
     */
    public static function rerun(string $streamingId, string $scopeKey): array
    {
        return [
            self::KEY => self::RERUN,
            'streaming_id' => $streamingId,
            'scope_key' => $scopeKey,
        ];
    }

    /**
     * A view change. `$params` is omitted when a coalescer already holds the
     * pending view — the marker then means "read the latest view from the
     * coalescer", which is how N rapid changes collapse into one re-run.
     *
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>
     */
    public static function viewChange(string $streamingId, ?array $params = null): array
    {
        $frame = [
            self::KEY => self::VIEWCHANGE,
            'streaming_id' => $streamingId,
        ];

        if ($params !== null) {
            $frame['params'] = $params;
        }

        return $frame;
    }

    /**
     * @param array<string, mixed> $requestSnapshot
     * @return array<string, mixed>
     */
    public static function subscribe(
        string $streamingId,
        string $routePath,
        string $routeMethod,
        array $requestSnapshot,
    ): array {
        return [
            self::KEY => self::SUBSCRIBE,
            'streaming_id' => $streamingId,
            'route_path' => $routePath,
            'route_method' => $routeMethod !== '' ? $routeMethod : 'GET',
            'request_snapshot' => $requestSnapshot,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function unsubscribe(string $streamingId): array
    {
        return [
            self::KEY => self::UNSUBSCRIBE,
            'streaming_id' => $streamingId,
        ];
    }
}
