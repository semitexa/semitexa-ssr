<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Contract\PayloadInterface;
use Semitexa\Core\Contract\ValidatablePayload;
use Semitexa\Core\Http\PayloadValidationResult;
use Semitexa\Core\Http\Response\GenericResponse;

#[AsPayload(path: '/sse', methods: ['GET'], responseWith: GenericResponse::class)]
class SseEndpointPayload implements PayloadInterface, ValidatablePayload
{
    public string $sessionId = '';

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function validate(): PayloadValidationResult
    {
        return new PayloadValidationResult(true, []);
    }
}
