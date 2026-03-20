<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service;

use Semitexa\Core\Attributes\AsService;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Ssr\Async\SseAsyncResultDelivery;
use Semitexa\Ssr\Domain\Model\DeferredBlockPayload;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;
use Semitexa\Ssr\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Isomorphic\DeferredTemplateRegistry;
use Semitexa\Ssr\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Layout\SlotAssetCollector;
use Semitexa\Ssr\Layout\SlotHandlerPipeline;
use Semitexa\Ssr\Layout\SlotResourceFactory;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

#[AsService]
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
        ?string $locale = null,
    ): void {
        $slots = $this->getDeferredSlots($pageHandle);

        $debugLog = static function (string $msg, array $data = []): void {
            $entry = json_encode(['ssr_orchestrator' => $msg] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $logPath = defined('SEMITEXA_ROOT') ? SEMITEXA_ROOT . '/var/log/debug.log' : (defined('SEMITEXA_PROJECT_ROOT') ? SEMITEXA_PROJECT_ROOT . '/var/log/debug.log' : '/var/www/html/var/log/debug.log');
            @file_put_contents($logPath, $entry . "\n", FILE_APPEND);
        };

        $debugLog('stream_start', [
            'page_handle' => $pageHandle,
            'slot_count' => count($slots),
            'slots' => array_map(static fn ($s) => $s->slotId, $slots),
            'providers' => \Semitexa\Ssr\Application\Service\DataProviderRegistry::getAll(),
        ]);

        if ($slots === []) {
            SseAsyncResultDelivery::deliverRaw($sessionId, ['type' => 'done']);
            return;
        }

        $this->applyLocale($locale);

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

        $useCoroutine = class_exists(Coroutine::class, false)
            && Coroutine::getCid() > 0
            && class_exists(WaitGroup::class, false);

        if (!$useCoroutine) {
            $results = [];
            foreach ($slots as $slot) {
                $data = [];
                try {
                    $this->applyLocale($locale);
                    $data = $this->resolveSlotData($slot, $pageHandle, $pageContext);
                } catch (\Throwable $e) {
                    error_log("DataProvider failed for slot {$slot->slotId}: {$e->getMessage()}");
                }
                $results[] = [$slot, $data];
            }

            $eventId = $lastEventId !== null ? ((int) $lastEventId) : 0;
            foreach ($results as [$slot, $data]) {
                $eventId++;
                $payload = $this->buildPayload($slot, $data);
                $sseData = $payload->toArray();
                $sseData['id'] = $eventId;

                SseAsyncResultDelivery::deliverRaw($sessionId, $sseData);

                if ($deferredRequestId !== null) {
                    DeferredRequestRegistry::markDelivered($deferredRequestId, $slot->slotId);
                }
            }

        $liveSlots = array_values(array_filter($slots, static fn (DeferredSlotDefinition $s) => $s->refreshInterval > 0));
            SseAsyncResultDelivery::deliverRaw($sessionId, ['type' => 'done', 'live' => $liveSlots !== []]);
            $this->runLiveLoop($sessionId, $pageHandle, $pageContext, $liveSlots, $locale);
            return;
        }

        // Concurrent resolution via Swoole coroutines
        $slotCount = count($slots);
        $channel = class_exists(\Swoole\Coroutine\Channel::class, false)
            ? new \Swoole\Coroutine\Channel($slotCount)
            : null;
        $results = [];
        $wg = new WaitGroup();

        foreach ($slots as $slot) {
            $wg->add();
            Coroutine::create(function () use ($slot, $pageContext, $pageHandle, $wg, &$results, $channel, $locale): void {
                $data = [];
                try {
                    $this->applyLocale($locale);
                    $data = $this->resolveSlotData($slot, $pageHandle, $pageContext);
                } catch (\Throwable $e) {
                    error_log("DataProvider failed for slot {$slot->slotId}: {$e->getMessage()}");
                } finally {
                    if ($channel !== null) {
                        $channel->push([$slot, $data]);
                    } else {
                        $results[] = [$slot, $data];
                    }
                    $wg->done();
                }
            });
        }

        $eventId = $lastEventId !== null ? ((int) $lastEventId) : 0;
        if ($channel !== null) {
            $received = 0;
            while ($received < $slotCount) {
                $item = $channel->pop();
                if ($item === false) {
                    break;
                }
                $received++;
                [$slot, $data] = $item;
                $eventId++;
                $payload = $this->buildPayload($slot, $data);
                $sseData = $payload->toArray();
                $sseData['id'] = $eventId;

                SseAsyncResultDelivery::deliverRaw($sessionId, $sseData);

                if ($deferredRequestId !== null) {
                    DeferredRequestRegistry::markDelivered($deferredRequestId, $slot->slotId);
                }
            }
        } else {
            $wg->wait();
            foreach ($results as [$slot, $data]) {
                $eventId++;
                $payload = $this->buildPayload($slot, $data);
                $sseData = $payload->toArray();
                $sseData['id'] = $eventId;

                SseAsyncResultDelivery::deliverRaw($sessionId, $sseData);

                if ($deferredRequestId !== null) {
                    DeferredRequestRegistry::markDelivered($deferredRequestId, $slot->slotId);
                }
            }
        }

        $wg->wait();

        $liveSlots = array_values(array_filter($slots, static fn (DeferredSlotDefinition $s) => $s->refreshInterval > 0));
        SseAsyncResultDelivery::deliverRaw($sessionId, ['type' => 'done', 'live' => $liveSlots !== []]);

        $this->runLiveLoop($sessionId, $pageHandle, $pageContext, $liveSlots, $locale);
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
        ?string $locale = null,
    ): array {
        $allSlots = $this->getDeferredSlots($pageHandle);
        $result = [];

        foreach ($allSlots as $slot) {
            if ($slotNames !== [] && !in_array($slot->slotId, $slotNames, true)) {
                continue;
            }

            try {
                $this->applyLocale($locale);
                $data = $this->resolveSlotData($slot, $pageHandle, $pageContext);
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

    /**
     * After initial SSE delivery, keep pushing live slots (refreshInterval > 0) until the client disconnects.
     *
     * @param DeferredSlotDefinition[] $liveSlots
     */
    private function runLiveLoop(string $sessionId, string $pageHandle, array $pageContext, array $liveSlots, ?string $locale = null): void
    {
        if ($liveSlots === []) {
            return;
        }

        // Track when each slot was last delivered
        $lastDelivered = [];
        $now = microtime(true);
        foreach ($liveSlots as $slot) {
            $lastDelivered[$slot->slotId] = $now;
        }

        while (\Semitexa\Ssr\Async\AsyncResourceSseServer::isSessionActive($sessionId)) {
            // Sleep 1 second ticks — fine-grained enough for any reasonable interval
            if (class_exists(Coroutine::class, false) && Coroutine::getCid() > 0) {
                Coroutine::sleep(1.0);
            } else {
                usleep(1_000_000);
            }

            if (!\Semitexa\Ssr\Async\AsyncResourceSseServer::isSessionActive($sessionId)) {
                break;
            }

            $now = microtime(true);
            foreach ($liveSlots as $slot) {
                if (($now - ($lastDelivered[$slot->slotId] ?? 0)) < $slot->refreshInterval) {
                    continue;
                }

                try {
                    $this->applyLocale($locale);
                    $data = $this->resolveSlotData($slot, $pageHandle, $pageContext);
                } catch (\Throwable $e) {
                    error_log("[Semitexa SSR] Live slot refresh failed for {$slot->slotId}: {$e->getMessage()}");
                    $data = [];
                }

                SseAsyncResultDelivery::deliverRaw($sessionId, $this->buildPayload($slot, $data)->toArray());
                $lastDelivered[$slot->slotId] = microtime(true);
            }
        }
    }

    private function buildPayload(DeferredSlotDefinition $slot, array $data): DeferredBlockPayload
    {
        $meta = [];
        if ($slot->cacheTtl > 0) {
            $meta['cache_ttl'] = $slot->cacheTtl;
        }
        $meta['priority'] = $slot->priority;
        if ($slot->refreshInterval > 0) {
            $meta['refresh_interval'] = $slot->refreshInterval;
        }

        if ($slot->mode === 'template') {
            $templatePath = DeferredTemplateRegistry::getPublishedPath($slot->slotId, $slot->pageHandle);
            if ($templatePath !== null && $templatePath !== '') {
                return new DeferredBlockPayload(
                    slotId: $slot->slotId,
                    mode: 'template',
                    template: $templatePath,
                    data: $data,
                    meta: $meta,
                );
            }
        }

        return new DeferredBlockPayload(
            slotId: $slot->slotId,
            mode: 'html',
            html: $this->renderSlotHtml($slot, $data),
            meta: $meta,
        );
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

    /**
     * Resolve slot data for rendering.
     * For new-style slot resources (resourceClass set): run the slot handler pipeline.
     * For legacy provider-backed slots: delegate to DataProviderRegistry.
     */
    private function resolveSlotData(DeferredSlotDefinition $slot, string $pageHandle, array $pageContext): array
    {
        if ($slot->resourceClass !== null) {
            $slotInstance = SlotResourceFactory::create($slot->resourceClass);
            $slotInstance = SlotHandlerPipeline::execute($slotInstance);
            SlotAssetCollector::collectFromSlot($slotInstance);
            return $slotInstance->getRenderContext();
        }

        $provider = $this->dataProviderRegistry->resolve($slot->slotId, $pageHandle);
        return $provider?->resolve($slot, $pageContext) ?? [];
    }

    private function applyLocale(?string $locale): void
    {
        if ($locale === null || $locale === '') {
            return;
        }

        if (class_exists(\Semitexa\Locale\Context\LocaleContextStore::class)) {
            \Semitexa\Locale\Context\LocaleContextStore::setLocale($locale);
            return;
        }

        if (class_exists(\Semitexa\Ssr\I18n\Translator::class)) {
            \Semitexa\Ssr\I18n\Translator::setLocale($locale);
        }
    }
}
