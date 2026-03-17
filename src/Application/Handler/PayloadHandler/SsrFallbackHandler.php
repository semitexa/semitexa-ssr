<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\Http\Response\GenericResponse;
use Semitexa\Ssr\Application\Payload\Request\SsrFallbackPayload;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Isomorphic\DeferredRequestRegistry;

#[AsPayloadHandler(payload: SsrFallbackPayload::class, resource: GenericResponse::class)]
final class SsrFallbackHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected DeferredBlockOrchestrator $orchestrator;

    public function handle(SsrFallbackPayload $payload, GenericResponse $resource): GenericResponse
    {
        $handle = trim($payload->getHandle());
        if ($handle === '') {
            throw new NotFoundException('Page handle', '(empty)');
        }

        $slotNames = array_values(array_filter(
            array_map('trim', explode(',', $payload->getSlots())),
            static fn (string $slot): bool => $slot !== ''
        ));

        $registeredSlots = $this->orchestrator->getDeferredSlots($handle);
        $registeredIds = array_map(static fn ($s) => $s->slotId, $registeredSlots);
        $invalid = array_diff($slotNames, $registeredIds);

        if ($invalid !== []) {
            throw new NotFoundException('Deferred slot', implode(', ', $invalid));
        }

        $pageContext = [];
        $requestId = $payload->getDeferredRequestId();
        if ($requestId !== '') {
            $entry = DeferredRequestRegistry::consume($requestId);
            if ($entry !== null) {
                $pageContext = $entry['page_context'];
            }
        }

        $rendered = $this->orchestrator->renderDeferredBlocksSync($handle, $slotNames, $pageContext);
        $resource->setContext($rendered);

        return $resource;
    }
}
