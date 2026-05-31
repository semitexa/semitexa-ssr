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

    // Track R · R8c — the held-open resource-grid stream's typed frames. A grid
    // data/error frame is a first-class UI SSE event, so it travels the same
    // typed-`_type` chokepoint as every other framework message: the INITIAL
    // rows frame written on connect AND every R4-driven fresh frame on re-run go
    // through {@see \Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::buildFrame()},
    // producing a byte-identical `event: ui.grid.data` line for both. Promoting
    // these to the allow-list (rather than a raw `event` string) keeps the rule
    // intact — no client-controlled string can ever become the event name.
    case UiGridData        = 'ui.grid.data';
    case UiGridError       = 'ui.grid.error';

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
