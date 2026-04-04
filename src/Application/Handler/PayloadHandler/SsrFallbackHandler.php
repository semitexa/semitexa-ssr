<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Payload\Request\SsrFallbackPayload;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Isomorphic\DeferredRequestRegistry;

#[AsPayloadHandler(payload: SsrFallbackPayload::class, resource: ResourceResponse::class)]
final class SsrFallbackHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected DeferredBlockOrchestrator $orchestrator;

    #[InjectAsMutable]
    protected Request $request;

    public function handle(SsrFallbackPayload $payload, ResourceResponse $resource): ResourceResponse
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
                $bindToken = $this->request->getCookie('semitexa_ssr_bind', '');
                if ($bindToken === '' || !hash_equals($entry['bind_token'], $bindToken)) {
                    throw new NotFoundException('Deferred request', $requestId);
                }
                $pageContext = $entry['page_context'];
            }
        }

        $rendered = $this->orchestrator->renderDeferredBlocksSync($handle, $slotNames, $pageContext);
        $resource->setContext($rendered);

        return $resource;
    }
}
