<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Payload\Request;

use Semitexa\Authorization\Attributes\PublicEndpoint;
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

#[PublicEndpoint]
#[AsPayload(
    path: '/sitemap.json',
    methods: ['GET'],
    public: true,
    responseWith: ResourceResponse::class,
    produces: ['application/json']
)]
final class SitemapJsonPayload
{
}
