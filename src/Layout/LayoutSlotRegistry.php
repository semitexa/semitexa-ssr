<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Layout;

use Semitexa\Ssr\Template\ModuleTemplateRegistry;

class LayoutSlotRegistry
{
    public const GLOBAL_HANDLE = '*';

    /**
     * handle => slot => list of { template, context, priority }
     * @var array<string, array<string, array<int, array{template:string, context:array, priority:int}>>>
     */
    private static array $slots = [];

    public static function register(string $handle, string $slot, string $template, array $context = [], int $priority = 0): void
    {
        $handleKey = strtolower($handle);
        $slotKey = strtolower($slot);
        if (!isset(self::$slots[$handleKey][$slotKey])) {
            self::$slots[$handleKey][$slotKey] = [];
        }
        self::$slots[$handleKey][$slotKey][] = [
            'template' => $template,
            'context' => $context,
            'priority' => $priority,
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
            $html .= $twig->render($entry['template'], $context);
        }

        return $html;
    }

    public static function getSlotsForHandle(string $handle): array
    {
        $handleKey = strtolower($handle);
        return self::$slots[$handleKey] ?? [];
    }
}