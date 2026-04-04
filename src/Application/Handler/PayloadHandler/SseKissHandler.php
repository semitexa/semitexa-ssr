<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Ssr\Application\Payload\Request\SseKissPayload;
use Semitexa\Ssr\Async\AsyncResourceSseServer;

#[AsPayloadHandler(payload: SseKissPayload::class, resource: ResourceResponse::class)]
final class SseKissHandler implements TypedHandlerInterface
{
    public function handle(SseKissPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $context = SwooleBootstrap::getCurrentSwooleRequestResponse();
        if ($context === null || $context[2] === null) {
            throw new NotFoundException('SSE endpoint', 'not available');
        }

        [$swooleRequest, $swooleResponse, $server] = $context;

        if (!class_exists(AsyncResourceSseServer::class)) {
            throw new NotFoundException('SSE', 'not available');
        }

        AsyncResourceSseServer::setServer($server);
        AsyncResourceSseServer::handle($swooleRequest, $swooleResponse);

        $resource->setContent('');
        return $resource;
    }
}
