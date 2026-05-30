<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Http\Response\ResourceResponse;

// The live deferred-SSR stream: client opens it with EventSource and the server
// holds a text/event-stream open via AsyncResourceSseServer::handleSse — the
// single shared SSE code path — so it declares the BearerSession gate model. It
// previously omitted transport: Sse (a coverage hole the reformulated boot guard
// now closes — see assertSseGateCoherence).
#[AsPublicPayload(
    responseWith: ResourceResponse::class,
    path: '/__semitexa_kiss',
    methods: ['GET'],
    name: 'ssr.kiss',
    transport: TransportType::Sse,
    sseGateModel: SseGateModel::BearerSession,
)]
class SseKissPayload
{
    protected string $sessionId = '';
    protected string $deferredRequestId = '';

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getDeferredRequestId(): string
    {
        return $this->deferredRequestId;
    }

    public function setDeferredRequestId(string $deferredRequestId): void
    {
        $this->deferredRequestId = $deferredRequestId;
    }
}
