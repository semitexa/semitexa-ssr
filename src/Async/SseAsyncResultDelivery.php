<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Async;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Contract\AsyncResultDeliveryInterface;
use Semitexa\Ssr\Domain\Model\DeferredBlockPayload;

#[SatisfiesServiceContract(of: AsyncResultDeliveryInterface::class)]
final class SseAsyncResultDelivery implements AsyncResultDeliveryInterface
{
    public function deliver(string $sessionId, object $responseDto, string $handlerClass = ''): void
    {
        $html = $this->renderResource($responseDto);
        $data = [
            'handler' => $handlerClass,
            'resource' => method_exists($responseDto, 'getRenderContext')
                ? $responseDto->getRenderContext()
                : (array) $responseDto,
            'html' => $html,
        ];
        AsyncResourceSseServer::deliver($sessionId, $data);
    }

    public function deliverDeferredBlock(string $sessionId, DeferredBlockPayload $payload): void
    {
        AsyncResourceSseServer::deliver($sessionId, $payload->toArray());
    }

    /**
     * Deliver a raw array payload via SSE.
     */
    public static function deliverRaw(string $sessionId, array $data): void
    {
        AsyncResourceSseServer::deliver($sessionId, $data);
    }

    private function renderResource(object $resource): string
    {
        return AsyncResourceSseServer::renderResource($resource);
    }
}
