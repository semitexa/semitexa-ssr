<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Ssr\Application\Service\Async\SseServer;
use Semitexa\Core\Contract\AsyncResultDeliveryInterface;
use Semitexa\Ssr\Domain\Model\DeferredBlockPayload;

#[SatisfiesServiceContract(of: AsyncResultDeliveryInterface::class)]
final class SseAsyncResultDelivery implements AsyncResultDeliveryInterface
{
    #[InjectAsReadonly]
    protected SseServer $sseServer;

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
        $this->sseServer->deliver($sessionId, $data);
    }

    public function deliverDeferredBlock(string $sessionId, DeferredBlockPayload $payload): void
    {
        $this->sseServer->deliver($sessionId, $payload->toArray());
    }

    /**
     * Deliver a raw array payload via SSE.
     */
    public static function deliverRaw(string $sessionId, array $data): void
    {
        // Static helper with static callers — the facade is the sanctioned
        // path here; it delegates to the same wired SseServer instance.
        AsyncResourceSseServer::deliver($sessionId, $data);
    }

    private function renderResource(object $resource): string
    {
        return $this->sseServer->renderResource($resource);
    }
}
