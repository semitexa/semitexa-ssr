<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Authorization\Attributes\PublicEndpoint;
use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Http\Response\GenericResponse;

#[PublicEndpoint]
#[AsPayload(
    path: '/llms.txt',
    methods: ['GET'],
    public: true,
    responseWith: GenericResponse::class,
    produces: ['text/plain']
)]
final class LlmsTxtPayload
{
}
