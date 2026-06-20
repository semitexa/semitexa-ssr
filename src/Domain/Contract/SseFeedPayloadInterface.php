<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

use Semitexa\Core\Request;

/**
 * The transport-coordinate contract shared by every held-open SSE feed,
 * collection and single-document alike. It is the minimal surface the generic
 * {@see \Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseFeedHandler}
 * reads to drive the held-open serve + the one-URL re-hydrate intake without
 * knowing the concrete feed shape.
 *
 * The interface is deliberately narrow: a feed's domain params (a collection's
 * `q`/`sort`/`filter`/`page`/`perPage`/`cursor`, a document's record key)
 * live as the payload's own typed `#[LiveFilterParam]` fields, read ONLY by
 * the concrete handler's envelope build — only the transport coordinates leak
 * across this seam, and `streamId` is NEVER an overridable filter (the
 * anti-poisoning invariant).
 *
 * Specialised by {@see SseCollectionFeedPayloadInterface} (list envelope) and
 * {@see SseDocumentFeedPayloadInterface} (single-record envelope); the two add
 * no methods — they exist as distinct types so a handler base can only be
 * handed the matching payload shape.
 */
interface SseFeedPayloadInterface
{
    /**
     * The live request the framework hands the payload during hydration. The
     * feed handler reads ONLY transport metadata from it (the `Accept` header
     * for content-negotiation, the `X-Semitexa-Stream-Rehydrate` intent
     * header) — never a filter.
     */
    public function getHttpRequest(): ?Request;

    /**
     * The adopted server-minted stream id the POST re-hydrate command carries
     * in its body to address the open stream. Null on the GET connect (the
     * server mints + announces its own id; the client adopts it).
     */
    public function getStreamId(): ?string;

    /**
     * The flat view-change params for the re-hydrate intake, forwarded
     * verbatim to {@see \Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::submitViewChange()},
     * which applies ONLY the keys the feed DTO marks `#[LiveFilterParam]`
     * (so the stream coordinate / identity is un-overridable by construction).
     *
     * @return array<string, mixed>
     */
    public function toViewParams(): array;
}
