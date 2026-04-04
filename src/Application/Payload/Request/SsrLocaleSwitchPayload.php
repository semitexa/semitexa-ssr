<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Authorization\Attributes\PublicEndpoint;
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

#[AsPayload(
    responseWith: ResourceResponse::class,
    path: '/__semitexa_locale',
    methods: ['GET'],
    name: 'ssr.locale',
)]
#[PublicEndpoint]
class SsrLocaleSwitchPayload
{
    protected string $sessionId = '';
    protected string $deferredRequestId = '';
    protected string $locale = '';

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

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }
}
