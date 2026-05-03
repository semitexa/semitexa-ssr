<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Ssr\Application\Service\Async\SseAsyncResultDelivery;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Domain\Model\DeferredBlockPayload;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredTemplateRegistry;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Application\Service\Layout\SlotAssetCollector;
use Semitexa\Ssr\Application\Service\Layout\SlotHandlerPipeline;
use Semitexa\Ssr\Application\Service\Layout\SlotResourceFactory;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;
use Swoole\Coroutine;

#[AsService]
final class DeferredBlockOrchestrator
{
    #[InjectAsReadonly]
    protected DataProviderRegistry $dataProviderRegistry;

    #[InjectAsReadonly]
    protected LoggerInterface $logger;

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
        bool $startLiveLoop = true,
    ): void {
        $slots = $this->getDeferredSlots($pageHandle);
        $config = IsomorphicConfig::fromEnvironment();
        $persistentDeferredSse = $config->persistentDeferredSse;
        $liveSlots = $persistentDeferredSse
            ? array_values(array_filter($slots, static fn (DeferredSlotDefinition $s) => $s->refreshInterval > 0))
            : [];

        $this->debugLog('stream_start', [
            'page_handle' => $pageHandle,
            'slot_count' => count($slots),
            'slots' => array_map(static fn ($s) => $s->slotId, $slots),
        ]);

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
            $liveEnabled = $startLiveLoop && $persistentDeferredSse;
            SseAsyncResultDelivery::deliverRaw($sessionId, [
                'type' => 'done',
                'live' => $liveEnabled,
                'close' => !$liveEnabled,
                'reconnect' => $liveEnabled,
            ]);
            if ($liveEnabled) {
                $this->runLiveLoop($sessionId, $pageHandle, $pageContext, $liveSlots, $locale);
            }
            return;
        }

        $useCoroutine = class_exists(Coroutine::class, false)
            && Coroutine::getCid() > 0;

        if (!$useCoroutine) {
            $results = [];
            foreach ($slots as $slot) {
                $data = [];
                try {
                    $this->applyLocale($locale);
                    $data = $this->resolveSlotData($slot, $pageHandle, $pageContext);
                } catch (\Throwable $e) {
                    $this->logger->error('DataProvider failed for slot', [
                        'slot_id' => $slot->slotId,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
                $results[] = [$slot, $data];
            }

            $eventId = $lastEventId !== null ? ((int) $lastEventId) : 0;
            foreach ($results as [$slot, $data]) {
                $eventId++;
                $payload = $this->buildPayload($slot, $data, $persistentDeferredSse);
                $sseData = $payload->toArray();
                $sseData['id'] = $eventId;

                SseAsyncResultDelivery::deliverRaw($sessionId, $sseData);

                if ($deferredRequestId !== null) {
                    DeferredRequestRegistry::markDelivered($deferredRequestId, $slot->slotId);
                }
            }

            $liveEnabled = $startLiveLoop && $persistentDeferredSse;
            SseAsyncResultDelivery::deliverRaw($sessionId, [
                'type' => 'done',
                'live' => $liveEnabled,
                'close' => !$liveEnabled,
                'reconnect' => $liveEnabled,
            ]);
            if ($liveEnabled) {
                $this->runLiveLoop($sessionId, $pageHandle, $pageContext, $liveSlots, $locale);
            }
            return;
        }

        // Concurrent resolution via Swoole coroutines
        $slotCount = count($slots);
        $channel = class_exists(\Swoole\Coroutine\Channel::class, false)
            ? new \Swoole\Coroutine\Channel($slotCount)
            : null;
        $results = [];

        foreach ($slots as $slot) {
            if ($channel === null) {
                $results[] = [$slot, $this->resolveSlotSafely($slot, $pageHandle, $pageContext, $locale)];
                continue;
            }

            \Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::createSessionCoroutine(function () use ($sessionId, $slot, $pageContext, $pageHandle, &$results, $channel, $locale): void {
                if (!\Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::isSessionActive($sessionId)) {
                    return;
                }
                $data = [];
                try {
                    $this->applyLocale($locale);
                    $data = $this->resolveSlotData($slot, $pageHandle, $pageContext);
                } catch (\Throwable $e) {
                    $this->logger->error('DataProvider failed for slot', [
                        'slot_id' => $slot->slotId,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                } finally {
                    if ($channel !== null && \Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::isSessionActive($sessionId)) {
                        $channel->push([$slot, $data]);
                    } else {
                        $results[] = [$slot, $data];
                    }
                }
            }, $sessionId);
        }

        $eventId = $lastEventId !== null ? ((int) $lastEventId) : 0;
        if ($channel !== null) {
            $received = 0;
            while ($received < $slotCount) {
                if (!\Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::isSessionActive($sessionId)) {
                    break;
                }
                $item = $channel->pop();
                if ($item === false) {
                    break;
                }
                $received++;
                [$slot, $data] = $item;
                $eventId++;
                $payload = $this->buildPayload($slot, $data, $persistentDeferredSse);
                $sseData = $payload->toArray();
                $sseData['id'] = $eventId;

                if (!\Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::isSessionActive($sessionId)) {
                    break;
                }
                SseAsyncResultDelivery::deliverRaw($sessionId, $sseData);

                if ($deferredRequestId !== null) {
                    DeferredRequestRegistry::markDelivered($deferredRequestId, $slot->slotId);
                }
            }
        } else {
            foreach ($results as [$slot, $data]) {
                if (!\Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::isSessionActive($sessionId)) {
                    break;
                }
                $eventId++;
                $payload = $this->buildPayload($slot, $data, $persistentDeferredSse);
                $sseData = $payload->toArray();
                $sseData['id'] = $eventId;

                SseAsyncResultDelivery::deliverRaw($sessionId, $sseData);

                if ($deferredRequestId !== null) {
                    DeferredRequestRegistry::markDelivered($deferredRequestId, $slot->slotId);
                }
            }
        }

        $liveEnabled = $startLiveLoop && $persistentDeferredSse;
        if (\Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::isSessionActive($sessionId)) {
            SseAsyncResultDelivery::deliverRaw($sessionId, [
                'type' => 'done',
                'live' => $liveEnabled,
                'close' => !$liveEnabled,
                'reconnect' => $liveEnabled,
            ]);
        }
        if ($liveEnabled) {
            $this->runLiveLoop($sessionId, $pageHandle, $pageContext, $liveSlots, $locale);
        }
    }

    private function resolveSlotSafely(
        DeferredSlotDefinition $slot,
        string $pageHandle,
        array $pageContext,
        ?string $locale,
    ): array {
        try {
            $this->applyLocale($locale);
            return $this->resolveSlotData($slot, $pageHandle, $pageContext);
        } catch (\Throwable $e) {
            $this->logger->error('DataProvider failed for slot', [
                'slot_id' => $slot->slotId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
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
                $this->logger->error('Deferred block sync render failed', [
                    'slot_id' => $slot->slotId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
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

        while (\Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::isSessionActive($sessionId)) {
            // Sleep 1 second ticks — fine-grained enough for any reasonable interval
            if (class_exists(Coroutine::class, false) && Coroutine::getCid() > 0) {
                Coroutine::sleep(1.0);
            } else {
                usleep(1_000_000);
            }

            if (!\Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer::isSessionActive($sessionId)) {
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
                    $this->logger->error('Live slot refresh failed', [
                        'slot_id' => $slot->slotId,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                    $data = [];
                }

                SseAsyncResultDelivery::deliverRaw($sessionId, $this->buildPayload($slot, $data)->toArray());
                $lastDelivered[$slot->slotId] = microtime(true);
            }
        }
    }

    private function buildPayload(
        DeferredSlotDefinition $slot,
        array $data,
        bool $persistentDeferredSse = false,
    ): DeferredBlockPayload
    {
        $meta = [];
        if ($slot->cacheTtl > 0) {
            $meta['cache_ttl'] = $slot->cacheTtl;
        }
        $meta['priority'] = $slot->priority;
        if ($persistentDeferredSse && $slot->refreshInterval > 0) {
            $meta['refresh_interval'] = $slot->refreshInterval;
        }

        if ($slot->mode === 'template') {
            $templatePath = DeferredTemplateRegistry::ensurePublishedPath(
                $slot->slotId,
                $slot->pageHandle,
                IsomorphicConfig::fromEnvironment(),
            );
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
            $this->logger->error('Twig render failed for deferred slot', [
                'slot_id' => $slot->slotId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
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
            if ($pageContext !== []) {
                $slotInstance = $slotInstance->withRenderContext($pageContext);
            }
            $slotInstance = SlotHandlerPipeline::execute($slotInstance);
            SlotAssetCollector::collectFromSlot($slotInstance);
            return array_merge($slotInstance->getStaticContext(), $slotInstance->getRenderContext());
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

        if (class_exists(\Semitexa\Ssr\Application\Service\I18n\Translator::class)) {
            \Semitexa\Ssr\Application\Service\I18n\Translator::setLocale($locale);
        }
    }

    /** @param array<string, mixed> $data */
    private function debugLog(string $message, array $data = []): void
    {
        $this->logger->debug($message, $data);
    }
}
