<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Attribute;

use Attribute;
use Semitexa\Core\Attribute\Capability;
use Semitexa\Core\Attribute\TransportType;

/** @api */
#[Capability(
    id: 'ssr.transport',
    summary: 'Declares how a resource reaches the browser - plain HTTP or a live SSE stream - and whether it is deferred.',
    useWhen: 'Content should update after the page has loaded, without the user reloading.',
    avoidWhen: 'The content does not change after load. A live stream then costs an open connection per viewer for nothing.',
    replaces: [
        'a hand-written EventSource or WebSocket client plus a bespoke endpoint',
        'setInterval polling a JSON route',
    ],
    seeAlso: 'ssr.deferred',
    usage: <<<'USAGE'
        // A region every open page keeps current, for signed-in visitors. No script, no polling:
        #[AsComponent(name: 'shop.board', template: '@project-layouts-Shop/board.html.twig', cacheable: false)]
        #[WithDataProvider(BoardData::class)]
        final class BoardComponent implements UiIslandInterface
        {
            public function watches(array $props): array
            {
                return [ResourceMetadata::for(OrderResource::class)->getResourceKey()];
            }
        }

        // In the page: {{ component('shop.board', {}) }}. Any ORM write to that resource, from
        // anywhere, redraws it on every open page and a dropped connection catches up by itself.
        // A raw SQL write is not seen: call ScopeInvalidatorInterface::touch() after it.
        // #[WithTransport] itself is not needed for this.

        // An island does not update for guests. For a public page, serve a feed instead:
        #[AsPublicPayload(path: '/board/feed', name: 'board.feed', methods: ['GET'],
            responseWith: JsonResourceResponse::class, renderProfile: RenderProfile::Json,
            transport: TransportType::Sse, sseGateModel: SseGateModel::BearerSession)]
        #[WatchScopes('orders')]
        final class BoardFeedPayload implements SseFeedPayloadInterface { /* stream id and request accessors */ }

        // Its handler extends AbstractSseFeedHandler; buildResponse() returns ['data' => ['html' => $html]].
        // The page calls {{ ui_page_sse_session_meta('live') }} and, after asset_body(), a small
        // script with openFeedChannel({feed, dataEvent: 'ui.collection.data',
        // errorEvent: 'ui.collection.error', onData}) that swaps the html in.
        USAGE,
)]
#[Attribute(Attribute::TARGET_CLASS)]
final class WithTransport
{
    public function __construct(
        public readonly TransportType $mode = TransportType::Http,
        public readonly bool $deferred = false,
    ) {}
}
