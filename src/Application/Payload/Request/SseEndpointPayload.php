<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Authorization\Attributes\PublicEndpoint;
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

#[AsPayload(
    responseWith: ResourceResponse::class,
    path: '/sse',
    methods: ['GET']
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
