<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service;

use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Ssr\Async\SseAsyncResultDelivery;
use Semitexa\Ssr\Domain\Model\DeferredBlockPayload;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;
use Semitexa\Ssr\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Isomorphic\DeferredTemplateRegistry;
use Semitexa\Ssr\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

final class DeferredBlockOrchestrator
{
    #[InjectAsReadonly]
    protected DataProviderRegistry $dataProviderRegistry;

    /**
     * Called after SSE connection established.
     * Launches all DataProvider::resolve() calls concurrently via Swoole coroutines.
     * Streams each block to the client as its DataProvider completes.
     */
    public function streamDeferredBlocks(
        string $sessionId,
        string $pageHandle,
        array $pageContext,
        ?string $lastEventId = null,
        ?string $deferredRequestId = null,
    ): void {
        $slots = $this->getDeferredSlots($pageHandle);

        if ($slots === []) {
            SseAsyncResultDelivery::deliverRaw($sessionId, ['type' => 'done']);
            return;
        }

        // Determine already-delivered slots for reconnect scenario
        $deliveredSlots = [];
        if ($deferredRequestId !== null) {
            $entry = DeferredRequestRegistry::consume($deferredRequestId);
            if ($entry !== null) {
                $deliveredSlots = $entry['delivered'];
            }
        }

        // Filter out already-delivered slots
        if ($lastEventId !== null && $deliveredSlots !== []) {
            $slots = array_filter(
                $slots,
                static fn (DeferredSlotDefinition $s) => !in_array($s->slotId, $deliveredSlots, true)
            );
            $slots = array_values($slots);
        }

        if ($slots === []) {
            SseAsyncResultDelivery::deliverRaw($sessionId, ['type' => 'done']);
            return;
        }

        // Concurrent resolution via Swoole coroutines
        $results = [];
        $wg = new WaitGroup();

        foreach ($slots as $slot) {
            $wg->add();
            Coroutine::create(function () use ($slot, $pageContext, $wg, &$results): void {
                try {
                    $provider = $this->dataProviderRegistry->resolve($slot->slotId, $pageHandle);
                    if ($provider !== null) {
                        $data = $provider->resolve($slot, $pageContext);
                        $results[] = [$slot, $data];
                    }
                } catch (\Throwable $e) {
                    error_log("DataProvider failed for slot {$slot->slotId}: {$e->getMessage()}");
                } finally {
                    $wg->done();
                }
            });
        }

        $wg->wait();

        // Sort by priority (lower = higher priority)
        usort($results, static fn (array $a, array $b) => $a[0]->priority <=> $b[0]->priority);

        // Stream results
        $eventId = $lastEventId !== null ? ((int) $lastEventId) : 0;
        foreach ($results as [$slot, $data]) {
            $eventId++;
            $payload = $this->buildPayload($slot, $data);
            $sseData = $payload->toArray();
            $sseData['id'] = $eventId;

            SseAsyncResultDelivery::deliverRaw($sessionId, $sseData);

            // Mark as delivered for reconnect
            if ($deferredRequestId !== null) {
                DeferredRequestRegistry::markDelivered($deferredRequestId, $slot->slotId);
            }
        }

        SseAsyncResultDelivery::deliverRaw($sessionId, ['type' => 'done']);
    }

    /**
     * Synchronous render of specified slots (for XHR fallback).
     * Always returns server-rendered HTML regardless of slot mode.
     *
     * @return array<string, string> slot_id => rendered HTML
     */
    public function renderDeferredBlocksSync(
        string $pageHandle,
        array $slotNames,
        array $pageContext = [],
    ): array {
        $allSlots = $this->getDeferredSlots($pageHandle);
        $result = [];

        foreach ($allSlots as $slot) {
            if ($slotNames !== [] && !in_array($slot->slotId, $slotNames, true)) {
                continue;
            }

            try {
                $provider = $this->dataProviderRegistry->resolve($slot->slotId, $pageHandle);
                if ($provider === null) {
                    continue;
                }

                $data = $provider->resolve($slot, $pageContext);
                $twig = ModuleTemplateRegistry::getTwig();
                $result[$slot->slotId] = $twig->render($slot->templateName, $data);
            } catch (\Throwable $e) {
                error_log("DeferredBlockOrchestrator sync render failed for slot {$slot->slotId}: {$e->getMessage()}");
            }
        }

        return $result;
    }

    /**
     * @return DeferredSlotDefinition[]
     */
    public function getDeferredSlots(string $pageHandle): array
    {
        return LayoutSlotRegistry::getDeferredSlots($pageHandle);
    }

    private function buildPayload(DeferredSlotDefinition $slot, array $data): DeferredBlockPayload
    {
        $meta = [];
        if ($slot->cacheTtl > 0) {
            $meta['cache_ttl'] = $slot->cacheTtl;
        }
        $meta['priority'] = $slot->priority;

        return match ($slot->mode) {
            'template' => new DeferredBlockPayload(
                slotId: $slot->slotId,
                mode: 'template',
                template: DeferredTemplateRegistry::getPublishedPath($slot->slotId, $slot->pageHandle),
                data: $data,
                meta: $meta,
            ),
            default => new DeferredBlockPayload(
                slotId: $slot->slotId,
                mode: 'html',
                html: $this->renderSlotHtml($slot, $data),
                meta: $meta,
            ),
        };
    }

    private function renderSlotHtml(DeferredSlotDefinition $slot, array $data): string
    {
        try {
            return ModuleTemplateRegistry::getTwig()->render($slot->templateName, $data);
        } catch (\Throwable $e) {
            error_log("Twig render failed for deferred slot {$slot->slotId}: {$e->getMessage()}");
            return '';
        }
    }
}
