<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

/**
 * Canonical contract for typed SSE messages published over
 * `/__semitexa_kiss`. Implementations are framework-owned value objects
 * (e.g. {@see UiPatchMessage}, {@see UiComponentStateMessage},
 * {@see UiErrorMessage}); concrete platform packages do not implement
 * this interface directly — they construct an existing VO.
 *
 * The `_type` discriminator surfaces in {@see self::toSsePayload()} and
 * is the field the wire-format chokepoint reads to map a payload to an
 * SSE `event:` line (see {@see UiSseEventType}).
 */
interface UiSseMessageInterface
{
    public function type(): UiSseEventType;

    /**
     * Server-controlled, JSON-serialisable envelope. The returned array
     * MUST carry `_type` set to {@see self::type()}->value so the wire
     * normaliser can route the message; additional keys are message-
     * specific (e.g. `componentInstanceId`, `patch`, `state`, `reason`,
     * `message`, `correlationId`). The body MUST NOT carry exception
     * traces, FQCNs, raw tokens, or secret env values — same rule as
     * {@see UiResponseDispatchResult}.
     *
     * @return array<string, mixed>
     */
    public function toSsePayload(): array;
}
