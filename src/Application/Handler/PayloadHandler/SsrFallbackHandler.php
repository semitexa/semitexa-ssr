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

#[AsPayloadHandler(payload: SsrFallbackPayload::class, resource: GenericResponse::class)]
final class SsrFallbackHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected DeferredBlockOrchestrator $orchestrator;

    public function handle(SsrFallbackPayload $payload, GenericResponse $resource): GenericResponse
    {
        $handle = $payload->getHandle();
        if ($handle === '') {
            throw new NotFoundException('Page handle', '(empty)');
        }

        $slotNames = array_filter(explode(',', $payload->getSlots()));

        $registeredSlots = $this->orchestrator->getDeferredSlots($handle);
        $registeredIds = array_map(static fn ($s) => $s->slotId, $registeredSlots);
        $invalid = array_diff($slotNames, $registeredIds);

        if ($invalid !== []) {
            throw new NotFoundException('Deferred slot', implode(', ', $invalid));
        }

        $rendered = $this->orchestrator->renderDeferredBlocksSync($handle, $slotNames);
        $resource->setContext($rendered);

        return $resource;
    }
}
