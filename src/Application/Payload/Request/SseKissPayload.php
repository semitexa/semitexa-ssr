<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Http\Response\GenericResponse;

#[AsPayload(
    path: '/__semitexa_kiss',
    methods: ['GET'],
    responseWith: GenericResponse::class,
    name: 'ssr.kiss',
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
