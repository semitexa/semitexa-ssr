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
use Semitexa\Ssr\Application\Service\DeferredComponentFallbackRenderer;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredBindCookie;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;

#[AsPayloadHandler(payload: SsrFallbackPayload::class, resource: ResourceResponse::class)]
final class SsrFallbackHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected DeferredBlockOrchestrator $orchestrator;

    #[InjectAsReadonly]
    protected DeferredComponentFallbackRenderer $components;

    #[InjectAsMutable]
    protected Request $request;

    public function handle(SsrFallbackPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $instanceIds = self::idList($payload->getComponents());
        if ($instanceIds !== []) {
            $resource->setContext($this->renderComponents($payload, $instanceIds));

            return $resource;
        }

        $handle = trim($payload->getHandle());
        if ($handle === '') {
            throw new NotFoundException('Page handle', '(empty)');
        }

        $slotNames = self::idList($payload->getSlots());

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
                $bindToken = DeferredBindCookie::tokenIn($this->request->cookies, $requestId);
                if ($bindToken === '' || !hash_equals($entry->bindToken, $bindToken)) {
                    throw new NotFoundException('Deferred request', $requestId);
                }
                $pageContext = $entry->pageContext;
            }
        }

        $rendered = $this->orchestrator->renderDeferredBlocksSync($handle, $slotNames, $pageContext);
        $resource->setContext($rendered);

        return $resource;
    }

    /**
     * The deferred components a stream left pending, asked for by instance id. A
     * component's props were recorded with the page, so the request must be one
     * this browser holds the bind cookie of: it names instances, never props, and
     * only the ones its page recorded.
     *
     * @param non-empty-list<string> $instanceIds
     * @return array<string, string> instance id => rendered HTML
     */
    private function renderComponents(SsrFallbackPayload $payload, array $instanceIds): array
    {
        $requestId = $payload->getDeferredRequestId();
        $entry = $requestId === '' ? null : DeferredRequestRegistry::consume($requestId);
        if ($entry === null) {
            throw new NotFoundException('Deferred request', $requestId === '' ? '(empty)' : $requestId);
        }
        $bindToken = DeferredBindCookie::tokenIn($this->request->cookies, $requestId);
        if ($bindToken === '' || !hash_equals($entry->bindToken, $bindToken)) {
            throw new NotFoundException('Deferred request', $requestId);
        }

        $recorded = [];
        foreach ($entry->components as $instance) {
            $id = $instance['instance_id'] ?? null;
            if (is_string($id) && $id !== '') {
                $recorded[$id] = $instance;
            }
        }
        $invalid = array_diff($instanceIds, array_keys($recorded));
        if ($invalid !== []) {
            throw new NotFoundException('Deferred component', implode(', ', $invalid));
        }

        return $this->components->render(
            array_map(static fn (string $id): array => $recorded[$id], $instanceIds),
            $entry->pageContext,
        );
    }

    /** @return list<string> */
    private static function idList(string $csv): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $csv)),
            static fn (string $id): bool => $id !== ''
        )));
    }
}
