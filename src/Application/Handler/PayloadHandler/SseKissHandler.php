<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\Http\Response\GenericResponse;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Ssr\Application\Payload\Request\SseKissPayload;
use Semitexa\Ssr\Async\AsyncResourceSseServer;

#[AsPayloadHandler(payload: SseKissPayload::class, resource: GenericResponse::class)]
final class SseKissHandler implements TypedHandlerInterface
{
    public function handle(SseKissPayload $payload, GenericResponse $resource): GenericResponse
    {
        $context = SwooleBootstrap::getCurrentSwooleRequestResponse();
        if ($context === null || $context[2] === null || $context[3] === null || $context[4] === null) {
            throw new NotFoundException('SSE endpoint', 'not available');
        }

        $sessionWorkerTable = $context[3];
        $deliverTable = $context[4];
        $pendingDeliverTable = $context[5] ?? null;
        [$swooleRequest, $swooleResponse, $server] = array_slice($context, 0, 3);

        if (!class_exists(AsyncResourceSseServer::class)) {
            throw new NotFoundException('SSE', 'not available');
        }

        AsyncResourceSseServer::setServer($server);
        AsyncResourceSseServer::setTables($sessionWorkerTable, $deliverTable, $pendingDeliverTable);
        AsyncResourceSseServer::handle($swooleRequest, $swooleResponse);

        $resource->setContent('');
        return $resource;
    }
}
