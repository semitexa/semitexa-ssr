<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

use Semitexa\Core\Request;

/**
 * One Way Pattern · Phase 4 — the minimal payload contract the SSE
 * collection feed handler reads.
 *
 * A canonical collection feed's request DTO carries the held-open stream's
 * transport metadata (the live HTTP request for content-negotiation, the
 * adopted stream id, and the view-change params for a re-hydrate command) so
 * the generic {@see \Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseCollectionFeedHandler}
 * can drive the held-open serve + the one-URL re-hydrate intake without
 * knowing the concrete feed. The successor of platform-ui's
 * `GridStreamPayloadInterface` on the canonical `{data, meta}` vocabulary.
 *
 * The interface is deliberately narrow: the collection params (`q` / `sort` /
 * `filter` / `page` / `perPage` / `cursor`) live as the payload's own typed
 * `#[LiveFilterParam]` fields, read only by the concrete handler's envelope
 * build — only the transport coordinates leak across this seam, and
 * `streamId` is NEVER an overridable filter (the anti-poisoning invariant).
 */
interface SseCollectionFeedPayloadInterface
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
