<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\Request;

use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Contract\HandlerInterface;
use Semitexa\Core\Contract\PayloadInterface;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Http\Response\GenericResponse;
use Semitexa\Core\Response;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Ssr\Application\Payload\Request\SseEndpointPayload;
use Semitexa\Ssr\Async\AsyncResourceSseServer;

#[AsPayloadHandler(payload: SseEndpointPayload::class, resource: GenericResponse::class)]
final class SseEndpointHandler implements HandlerInterface
{
    public function handle(PayloadInterface $request, ResourceInterface $response): ResourceInterface
    {
        $context = SwooleBootstrap::getCurrentSwooleRequestResponse();
        if ($context === null || $context[2] === null || $context[3] === null || $context[4] === null) {
            return Response::notFound('SSE endpoint not available');
        }

        $sessionWorkerTable = $context[3];
        $deliverTable = $context[4];
        $pendingDeliverTable = $context[5] ?? null;
        [$swooleRequest, $swooleResponse, $server] = array_slice($context, 0, 3);

        if (!class_exists(AsyncResourceSseServer::class)) {
            return Response::notFound('SSE not available');
        }

        AsyncResourceSseServer::setServer($server);
        AsyncResourceSseServer::setTables($sessionWorkerTable, $deliverTable, $pendingDeliverTable);
        AsyncResourceSseServer::handle($swooleRequest, $swooleResponse);

        return Response::alreadySent();
    }
}
