<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Server\SseFrame;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;

/**
 * Turns an outbound payload into the frame that goes on the wire — the third
 * extraction of `ep-slay-sse-god-class` out of {@see AsyncResourceSseServer}.
 *
 * Deliberately the *pure* half of frame writing. Choosing the `event:` name and
 * assembling the {@see SseFrame} is a decision about the wire shape and comes
 * out cleanly; actually pushing bytes stays on the facade, because that path
 * runs through the worker-boot `$transport` slot which `tk-sse-wire-di` is going
 * to replace with a container binding. Splitting the pure part off now gets the
 * gnarliest logic in the write path under direct test without disturbing the
 * transport seam that three existing tests swap by reflection.
 *
 * Event-name precedence, highest first:
 *  1. the canonical passthrough key — the only way to get an `event:` line with
 *     no in-body discriminator, which the graphql-sse wire shape requires;
 *  2. `_type`, the UI event vocabulary;
 *  3. `event`, the legacy free-form name.
 *
 * An out-of-vocabulary value at (1) or (2) is dropped, logged, and stripped
 * from the body rather than promoted — an arbitrary attacker- or bug-supplied
 * string must never become an event name a client dispatches on.
 */
final class SseFrameFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public function build(array $data): SseFrame
    {
        [$resolvedEventName, $data] = $this->resolveEventName($data);

        return SseFrame::fromResolved(
            isset($data['id']) ? (string) $data['id'] : null,
            $resolvedEventName,
            $data,
        );
    }

    /**
     * Tag a frame with the subscription it belongs to, so a multiplexed client
     * can demux it. The SAME stamp lands on the initial frame and on every
     * re-run frame, which is what makes those two byte-identical — a pinned
     * invariant. An empty id leaves the frame untouched rather than stamping a
     * meaningless key.
     *
     * @param array<string, mixed> $frameData
     * @return array<string, mixed>
     */
    public static function stampSubscriptionId(array $frameData, string $streamingId): array
    {
        if (trim($streamingId) === '') {
            return $frameData;
        }

        $frameData['streaming_id'] = $streamingId;

        return $frameData;
    }

    /**
     * Resolve the `event:` name and return it alongside the body to render,
     * with any consumed discriminator key stripped.
     *
     * @param array<string, mixed> $data
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    public function resolveEventName(array $data): array
    {
        // Opt-in passthrough mode, evaluated FIRST and independently of the
        // `_type`/legacy logic below. The key is stripped either way so the
        // remaining body renders bare. No pre-existing caller sets this key, so
        // every legacy frame falls through byte-identically.
        if (array_key_exists(SsePassthroughEvent::KEY, $data)) {
            $passthroughEvent = $data[SsePassthroughEvent::KEY];
            unset($data[SsePassthroughEvent::KEY]);
            if (is_string($passthroughEvent) && SsePassthroughEvent::isAllowed($passthroughEvent)) {
                return [$passthroughEvent, $data];
            }

            StaticLoggerBridge::warning('ssr', 'sse_passthrough_event_dropped', [
                'event' => is_string($passthroughEvent) ? $passthroughEvent : gettype($passthroughEvent),
            ]);

            return [null, $data];
        }

        $rawType = $data['_type'] ?? null;
        if (is_string($rawType) && $rawType !== '') {
            if (UiSseEventType::isAllowed($rawType)) {
                return [$rawType, $data];
            }

            StaticLoggerBridge::warning('ssr', 'ui_sse_unknown_type_dropped', [
                'type' => $rawType,
            ]);
            unset($data['_type']);

            return [null, $data];
        }

        if (array_key_exists('_type', $data)) {
            // `_type` was present but non-string or empty → malformed. Strip it
            // so the wire shape stays clean; do not emit `event:`.
            unset($data['_type']);

            return [null, $data];
        }

        $legacyEvent = $data['event'] ?? null;
        if (is_string($legacyEvent) && $legacyEvent !== '') {
            return [$legacyEvent, $data];
        }

        return [null, $data];
    }
}
