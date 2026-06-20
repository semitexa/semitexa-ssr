<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

/**
 * Collaborative Form Data · Phase 1 — the payload contract the SSE
 * single-document feed handler reads. The object-valued sibling of
 * {@see SseCollectionFeedPayloadInterface}: where the collection feed serves a
 * list `{data: [...], meta}`, a document feed serves ONE record
 * `{data: {...}, meta}` — the live shared state of a single collaborative
 * document (a form's draft field values + version + presence/lock projection).
 *
 * It carries no methods of its own: the held-open transport coordinates live
 * on {@see SseFeedPayloadInterface}. The DISTINCT type lets
 * {@see \Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseDocumentFeedHandler}
 * be handed only a document-shaped payload, and the watched scope key (the
 * document's `formdoc:{key}:{id}` channel) rides the payload's `#[WatchScopes]`
 * declaration exactly as a collection feed's does — so a document re-runs and
 * re-frames through the same Track-R machinery, byte-identical choreography,
 * only the envelope cardinality and the `ui.document.*` event names differ.
 */
interface SseDocumentFeedPayloadInterface extends SseFeedPayloadInterface
{
}
