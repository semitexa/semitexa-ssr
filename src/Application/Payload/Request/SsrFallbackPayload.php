<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Http\Response\GenericResponse;

#[AsPayload(
    path: '/__semitexa_hug',
    methods: ['GET'],
    responseWith: GenericResponse::class,
    name: 'ssr.hug',
)]
class SsrFallbackPayload
{
    protected string $handle = '';
    protected string $slots = '';

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
}
