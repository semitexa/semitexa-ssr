<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Layout;

use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;
use Semitexa\Ssr\Template\ModuleTemplateRegistry;

class LayoutSlotRegistry
{
    public const GLOBAL_HANDLE = '*';

    /**
     * handle => slot => list of { template, context, priority, ... }
     * @var array<string, array<string, array<int, array{template:string, context:array, priority:int, deferred:bool, cacheTtl:int, dataProvider:?string, skeletonTemplate:?string, mode:string, refreshInterval:int, resourceClass:?string, clientModules:array}>>>
     */
    private static array $slots = [];

    public static function register(
        string $handle,
        string $slot,
        string $template,
        array $context = [],
        int $priority = 0,
        bool $deferred = false,
        int $cacheTtl = 0,
        ?string $dataProvider = null,
        ?string $skeletonTemplate = null,
        string $mode = 'html',
        int $refreshInterval = 0,
        ?string $resourceClass = null,
        array $clientModules = [],
    ): void {
        $handleKey = strtolower($handle);
        $slotKey = strtolower($slot);
        if (!isset(self::$slots[$handleKey][$slotKey])) {
            self::$slots[$handleKey][$slotKey] = [];
        }
        self::$slots[$handleKey][$slotKey][] = [
            'template' => $template,
            'context' => $context,
            'priority' => $priority,
            'deferred' => $deferred,
            'cacheTtl' => $cacheTtl,
            'dataProvider' => $dataProvider,
            'skeletonTemplate' => $skeletonTemplate,
            'mode' => $mode,
            'refreshInterval' => $refreshInterval,
            'resourceClass' => $resourceClass,
            'clientModules' => $clientModules,
        ];
        usort(self::$slots[$handleKey][$slotKey], static fn ($a, $b) => $a['priority'] <=> $b['priority']);
    }

    /**
     * Render slot content for the given page/layout. Gathers entries for:
     * - handle '*' (global),
     * - layoutHandle (if not null),
     * - pageHandle,
     * then merges and renders in priority order.
     */
    public static function render(
        string $pageHandle,
        string $slot,
        array $baseContext = [],
        array $inlineContext = [],
        ?string $layoutHandle = null,
    ): string {
        $slotKey = strtolower($slot);
        $entries = [];

        foreach ([self::GLOBAL_HANDLE, $layoutHandle, $pageHandle] as $h) {
            if ($h === null || $h === '') {
                continue;
            }
            $handleKey = strtolower($h);
            $list = self::$slots[$handleKey][$slotKey] ?? [];
            foreach ($list as $entry) {
                $entries[] = $entry;
            }
        }

        if (empty($entries)) {
            return '';
        }

        usort($entries, static fn ($a, $b) => $a['priority'] <=> $b['priority']);

        $twig = ModuleTemplateRegistry::getTwig();
        $html = '';
        foreach ($entries as $entry) {
            $context = array_merge($baseContext, $entry['context'], $inlineContext);
            if (($entry['resourceClass'] ?? null) !== null) {
                $html .= SlotRenderer::renderEntry($entry, $context);
            } else {
                $html .= $twig->render($entry['template'], $context);
            }
        }

        return $html;
    }

    public static function getSlotsForHandle(string $handle): array
    {
        $handleKey = strtolower($handle);
        return self::$slots[$handleKey] ?? [];
    }

    /**
     * Get all deferred slot definitions for a given page handle.
     *
     * @return DeferredSlotDefinition[]
     */
    public static function getDeferredSlots(string $handle): array
    {
        $handleKey = strtolower($handle);
        $result = [];

        $handleSlots = self::$slots[$handleKey] ?? [];
        $globalSlots = self::$slots[self::GLOBAL_HANDLE] ?? [];
        $mergedSlots = $globalSlots;
        foreach ($handleSlots as $slotName => $entries) {
            if (isset($mergedSlots[$slotName])) {
                $mergedSlots[$slotName] = array_merge($mergedSlots[$slotName], $entries);
            } else {
                $mergedSlots[$slotName] = $entries;
            }
        }

        foreach ($mergedSlots as $slotName => $entries) {
            foreach ($entries as $entry) {
                if (!($entry['deferred'] ?? false)) {
                    continue;
                }
                $result[] = new DeferredSlotDefinition(
                    slotId: $slotName,
                    templateName: $entry['template'],
                    pageHandle: $handle,
                    mode: $entry['mode'] ?? 'html',
                    priority: $entry['priority'] ?? 0,
                    cacheTtl: $entry['cacheTtl'] ?? 0,
                    dataProviderClass: $entry['dataProvider'] ?? null,
                    skeletonTemplate: $entry['skeletonTemplate'] ?? null,
                    refreshInterval: $entry['refreshInterval'] ?? 0,
                    resourceClass: $entry['resourceClass'] ?? null,
                );
            }
        }

        usort($result, static fn (DeferredSlotDefinition $a, DeferredSlotDefinition $b) => $a->priority <=> $b->priority);

        return $result;
    }

    /**
     * Get all deferred slot definitions across all handles.
     *
     * @return DeferredSlotDefinition[]
     */
    public static function getAllDeferredSlots(): array
    {
        $result = [];

        foreach (self::$slots as $handleKey => $slotMap) {
            foreach ($slotMap as $slotName => $entries) {
                foreach ($entries as $entry) {
                    if (!($entry['deferred'] ?? false)) {
                        continue;
                    }
                    $result[] = new DeferredSlotDefinition(
                        slotId: $slotName,
                        templateName: $entry['template'],
                        pageHandle: $handleKey,
                        mode: $entry['mode'] ?? 'html',
                        priority: $entry['priority'] ?? 0,
                        cacheTtl: $entry['cacheTtl'] ?? 0,
                        dataProviderClass: $entry['dataProvider'] ?? null,
                        skeletonTemplate: $entry['skeletonTemplate'] ?? null,
                        refreshInterval: $entry['refreshInterval'] ?? 0,
                        resourceClass: $entry['resourceClass'] ?? null,
                    );
                }
            }
        }

        return $result;
    }
}
