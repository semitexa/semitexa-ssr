<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;
use Semitexa\Ssr\Domain\Contract\SseDocumentFeedPayloadInterface;
use Semitexa\Ssr\Domain\Contract\SseFeedPayloadInterface;

/**
 * Collaborative Form Data · Phase 1 — SSE single-document serving on the
 * canonical `{data, meta}` envelope (record cardinality).
 *
 * The object-valued sibling of {@see AbstractSseCollectionFeedHandler}: where a
 * collection feed serves `{data: [...], meta}`, a document feed serves
 * `{data: {...}, meta}` — the live shared state of ONE collaborative document
 * (a form's draft field values + version + presence/lock projection). It
 * inherits the ENTIRE held-open choreography from {@see AbstractSseFeedHandler}
 * verbatim (server-minted stream id, the held-open serve, the
 * `X-Semitexa-Stream-Rehydrate` re-hydrate intake, SSE-vs-JSON negotiation,
 * the JSON degrade, the `#[WatchScopes]` subscription, and the Track-R re-run
 * loop) and only PINS the document vocabulary: the `buildDocumentResponse()`
 * seam and the `ui.document.*` frame types.
 *
 * Because the live mechanism is the generic one, a document re-runs and
 * re-frames exactly like a grid: when its watched scope (the document's
 * `formdoc:{key}:{id}` channel — declared via `#[WatchScopes]`) is touched,
 * every subscriber's held-open stream re-runs the chain and pushes the fresh
 * single-record envelope. That is the seam the collaborative-form inbound
 * handler drives — it mutates the draft/presence/lock store and touches the
 * document scope; this feed re-projects the new shared state to all editors.
 *
 * A concrete document feed handler supplies ONE seam —
 * {@see buildDocumentResponse()}, "load the document's current shared state
 * and render the canonical envelope" — and delegates its `handle()` to the
 * inherited {@see serve()}.
 */
abstract class AbstractSseDocumentFeedHandler extends AbstractSseFeedHandler
{
    /**
     * Build the feed's canonical single-record response from its typed payload.
     * Document deviations (unknown record key, access denied to the document)
     * keep raising their typed {@see \Semitexa\Core\Exception\DomainException}s;
     * the base decides per transport whether they propagate (JSON pull) or
     * become a `ui.document.error` frame (SSE).
     */
    abstract protected function buildDocumentResponse(
        SseDocumentFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse;

    /**
     * The generic seam, narrowed to the document contract. The handler is only
     * ever invoked with its own document payload, so the runtime object always
     * satisfies the assertion (the cast is for the static analyser).
     */
    protected function buildResponse(
        SseFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        \assert($payload instanceof SseDocumentFeedPayloadInterface);

        return $this->buildDocumentResponse($payload, $response);
    }

    protected function successEventType(): UiSseEventType
    {
        return UiSseEventType::UiDocumentData;
    }

    protected function errorEventType(): UiSseEventType
    {
        return UiSseEventType::UiDocumentError;
    }

    /**
     * Static document framing — the declaration-level test seam and a stable,
     * self-describing helper for the `ui.document.*` types. The held-open
     * pipeline frames through the instance {@see frame()}; this mirrors it for
     * the document vocabulary. The `['_type' => …] + $envelope` order makes the
     * frame type ALWAYS win over a row-sourced `_type`.
     *
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    public static function frameData(array $envelope, bool $success): array
    {
        $type = $success ? UiSseEventType::UiDocumentData : UiSseEventType::UiDocumentError;

        return ['_type' => $type->value] + $envelope;
    }
}
