<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Authorization\Attributes\PublicEndpoint;
use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Http\Response\GenericResponse;

#[AsPayload(
    path: '/__semitexa_hug',
    methods: ['GET'],
    responseWith: GenericResponse::class,
    name: 'ssr.hug',
)]
#[PublicEndpoint]
class SsrFallbackPayload
{
    protected string $handle = '';
    protected string $slots = '';
    protected string $deferredRequestId = '';

    public function getHandle(): string
    {
        return $this->handle;
    }

    public function setHandle(string $handle): void
    {
        $this->handle = $handle;
    }

    public function getSlots(): string
    {
        return $this->slots;
    }

    public function setSlots(string $slots): void
    {
        $this->slots = $slots;
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
