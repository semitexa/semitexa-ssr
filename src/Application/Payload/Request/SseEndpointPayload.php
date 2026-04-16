<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Authorization\Attribute\PublicEndpoint;
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Http\Response\ResourceResponse;

#[AsPayload(
    responseWith: ResourceResponse::class,
    path: '/sse',
    methods: ['GET'],
    transport: TransportType::Sse,
    produces: ['text/event-stream'],
)]
#[PublicEndpoint]
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
