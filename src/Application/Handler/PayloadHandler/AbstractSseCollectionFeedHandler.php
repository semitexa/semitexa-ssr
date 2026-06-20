<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;
use Semitexa\Ssr\Domain\Contract\SseCollectionFeedPayloadInterface;
use Semitexa\Ssr\Domain\Contract\SseFeedPayloadInterface;

/**
 * One Way Pattern · Phase 4 — SSE collection serving on the canonical
 * `{data, meta}` envelope (list cardinality).
 *
 * The re-homed, canonical-vocabulary successor of platform-ui's
 * `AbstractGridStreamFeedHandler`. The entire held-open serving choreography
 * (server-minted stream id + `ui.stream.id` first frame, the
 * `AsyncResourceSseServer::serveResourceStream()` hand-off, the
 * `X-Semitexa-Stream-Rehydrate` re-hydrate intake, SSE-vs-JSON negotiation,
 * the JSON degrade, the `#[WatchScopes]` subscription) now lives ONCE in the
 * generic {@see AbstractSseFeedHandler}; this class only PINS the collection
 * vocabulary: the `buildCollectionResponse()` seam and the `ui.collection.*`
 * frame types. Its object-valued sibling is
 * {@see AbstractSseDocumentFeedHandler}.
 *
 * A concrete feed handler supplies ONE seam — {@see buildCollectionResponse()},
 * "bind a source to CollectionCriteria and render the canonical envelope" —
 * and delegates its `handle()` to the inherited {@see serve()}.
 */
abstract class AbstractSseCollectionFeedHandler extends AbstractSseFeedHandler
{
    /**
     * Build the feed's canonical collection response from its typed payload —
     * the single envelope-resolution path, shared by both response modes
     * (typically: `CollectionFeedSupport::criteriaFor()` → compiler push-down
     * → `JsonResourceResponse::withResources()`). Collection deviations keep
     * raising their typed {@see \Semitexa\Core\Exception\DomainException}s; the
     * base decides per transport whether they propagate (JSON pull) or become
     * a `ui.collection.error` frame (SSE).
     */
    abstract protected function buildCollectionResponse(
        SseCollectionFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse;

    /**
     * The generic seam, narrowed to the collection contract. The handler is
     * only ever invoked with its own collection payload, so the runtime object
     * always satisfies the assertion (the cast is for the static analyser).
     */
    protected function buildResponse(
        SseFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        \assert($payload instanceof SseCollectionFeedPayloadInterface);

        return $this->buildCollectionResponse($payload, $response);
    }

    protected function successEventType(): UiSseEventType
    {
        return UiSseEventType::UiCollectionData;
    }

    protected function errorEventType(): UiSseEventType
    {
        return UiSseEventType::UiCollectionError;
    }

    /**
     * Static collection framing — retained as a stable, self-describing helper
     * (and the declaration-level test seam) for the `ui.collection.*` types.
     * The held-open pipeline frames through the instance {@see frame()}; this
     * mirrors it for the collection vocabulary. The `['_type' => …] + $envelope`
     * order makes the frame type ALWAYS win over a row-sourced `_type`.
     *
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    public static function frameData(array $envelope, bool $success): array
    {
        $type = $success ? UiSseEventType::UiCollectionData : UiSseEventType::UiCollectionError;

        return ['_type' => $type->value] + $envelope;
    }
}
