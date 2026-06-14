<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

/**
 * Allow-list of typed SSE event names emitted by the canonical
 * `/__semitexa_kiss` stream. Per `packages/semitexa-platform-ui/docs/
 * transport-architecture.md` ADR-0001, the canonical outbound channel is
 * `GET /__semitexa_kiss`; typed event names MUST come from this enum so
 * arbitrary client/user-controlled strings can never become SSE event
 * names (which would let a hostile payload spoof framework messages).
 *
 * The enum is the single source of truth: the wire-format chokepoint
 * inside {@see \Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer}
 * consults {@see self::isAllowed()} before promoting a payload's `_type`
 * field to an SSE `event:` line.
 */
enum UiSseEventType: string
{
    case SsrFragment       = 'ssr.fragment';
    case UiPatch           = 'ui.patch';
    case UiComponentState  = 'ui.componentState';
    case UiError           = 'ui.error';

    // Stream Lifecycle · Axis 1(b) — the server-authoritative stream id, handed
    // to the client as a dedicated FIRST SSE event on connect (one line BEFORE
    // the initial collection data frame). It rides its OWN one-shot channel
    // precisely so the data-frame shape never changes: the byte-identical
    // initial-connect / re-run data-frame invariant is preserved because the
    // id is NOT a field on the data frame. Payload: `{"stream_id":"sse_<32hex>"}`.
    // The client adopts the id via `addEventListener('ui.stream.id', …)`.
    // Promoting it to this allow-list keeps the rule intact — no
    // client-controlled string can ever become the event name.
    case UiStreamId        = 'ui.stream.id';

    // One Way Pattern · Phase 4 — the canonical-envelope collection stream's
    // typed frames. A `ui.collection.data` frame carries the canonical
    // `{data, meta}` collection envelope — the SAME projection the JSON pull
    // mode returns — so the client renders both transports from one code
    // path. (The legacy `ui.grid.data`/`ui.grid.error` members that carried
    // the v1 UiGridDataResponse shape were deleted in the Phase 6 sweep.)
    // Emitted by AbstractSseCollectionFeedHandler on initial connect,
    // rehydration, and Track-R-driven re-runs; errors travel as
    // `ui.collection.error`. Same allow-list rule: no client-controlled
    // string can ever become the event name.
    case UiCollectionData  = 'ui.collection.data';
    case UiCollectionError = 'ui.collection.error';

    public static function isAllowed(string $type): bool
    {
        return self::tryFrom($type) !== null;
    }

    /** @return list<string> */
    public static function allowedValues(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
