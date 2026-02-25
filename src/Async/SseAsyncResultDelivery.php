<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Async;

use Semitexa\Core\Attributes\AsServiceContract;
use Semitexa\Core\Contract\AsyncResultDeliveryInterface;

#[AsServiceContract(of: AsyncResultDeliveryInterface::class)]
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

    private function renderResource(object $resource): string
    {
        return AsyncResourceSseServer::renderResource($resource);
    }
}
