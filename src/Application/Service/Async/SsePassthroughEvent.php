<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

/**
 * Canonical contract for the opt-in "passthrough" SSE frame mode.
 *
 * The SSR frame chokepoint ({@see AsyncResourceSseServer::resolveSseEventName()})
 * normally keeps a frame's discriminator key INSIDE the JSON body (the typed
 * `_type` envelope is self-describing, the legacy `event` key is rendered
 * verbatim). That coupling means an `event:` line always implies an in-body
 * discriminator — fine for UI frames, but wrong for protocols that mandate a
 * BARE body (e.g. the graphql-sse `next`/`complete`/`error` vocabulary, whose
 * `data:` line must be a bare GraphQL `{data, errors}` ExecutionResult with no
 * extra discriminator key).
 *
 * This contract defines the single opt-in seam that decouples them: a frame
 * carrying {@see KEY} with a value in {@see ALLOWED} emits `event: <value>`
 * and has the key STRIPPED from the rendered body — so the remaining body is
 * rendered verbatim and bare. The mode is purely additive: every existing
 * frame (collection `ui.collection.data`, KISS, `ui.stream.id`, demo producer, close)
 * sets neither key and is untouched, hitting the unchanged `_type`/legacy
 * branches byte-identically.
 *
 * The allowed set is the closed graphql-sse event vocabulary — NOT a free
 * string and NOT the UI {@see \Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType}
 * set; a value outside it is treated as invalid (no event line, key stripped),
 * mirroring the unknown-`_type` posture. This class is the single source of
 * truth for both the key name and the vocabulary; soft-dependent packages
 * (e.g. `semitexa-graphql`) reference the literal values under a
 * `class_exists()` guard rather than importing this class.
 */
final class SsePassthroughEvent
{
    /**
     * The opt-in body key. When present, its value (if allowed) becomes the
     * SSE `event:` line and the key is removed from the body before render.
     */
    public const KEY = '_sse_event';

    /**
     * The closed graphql-sse event vocabulary. A passthrough frame may only
     * emit one of these names; any other value is treated as invalid.
     *
     * @var list<string>
     */
    public const ALLOWED = ['next', 'complete', 'error'];

    public static function isAllowed(string $event): bool
    {
        return in_array($event, self::ALLOWED, true);
    }
}
