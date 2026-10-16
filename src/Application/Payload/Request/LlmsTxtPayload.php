<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\ServedRegardlessOfAccept;
use Semitexa\Core\Http\Response\ResourceResponse;

#[AsPublicPayload(
    path: '/llms.txt',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['text/plain']
)]
// Crawlers and agents do not negotiate; a refusal would read as an answer.
#[ServedRegardlessOfAccept]
final class LlmsTxtPayload
{
}
