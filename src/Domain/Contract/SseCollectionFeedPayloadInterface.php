<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

/**
 * One Way Pattern · Phase 4 — the payload contract the SSE collection feed
 * handler reads. The successor of platform-ui's `GridStreamPayloadInterface`
 * on the canonical `{data, meta}` vocabulary.
 *
 * It carries no methods of its own: the held-open transport coordinates live
 * on {@see SseFeedPayloadInterface}. This interface exists as a DISTINCT type
 * so {@see \Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseCollectionFeedHandler}
 * can only be handed a collection-shaped payload (a list envelope), never a
 * single-document one ({@see SseDocumentFeedPayloadInterface}).
 *
 * A canonical collection feed's request DTO carries the held-open stream's
 * transport metadata (the live HTTP request for content-negotiation, the
 * adopted stream id, and the view-change params for a re-hydrate command); the
 * collection params (`q` / `sort` / `filter` / `page` / `perPage` / `cursor`)
 * live as the payload's own typed `#[LiveFilterParam]` fields, read only by
 * the concrete handler's envelope build.
 */
interface SseCollectionFeedPayloadInterface extends SseFeedPayloadInterface
{
}
