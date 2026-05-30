<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Http\Response\ResourceResponse;

// Long-lived SSE stream gated in-server by the shared bearer/session admit chain
// in AsyncResourceSseServer::handleSse — declared as SseGateModel::BearerSession
// (identical to /__semitexa_kiss, which shares the same code path).
#[AsPublicPayload(
    responseWith: ResourceResponse::class,
    path: '/sse',
    methods: ['GET'],
    transport: TransportType::Sse,
    produces: ['text/event-stream'],
    sseGateModel: SseGateModel::BearerSession,
)]
class SseEndpointPayload
{
    protected string $sessionId = '';

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }
}
