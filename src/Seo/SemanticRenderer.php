<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Seo;

use Semitexa\Ssr\Layout\LayoutSlotRegistry;

final class SemanticRenderer
{
    public static function generateForResource(object $resource, string $handle): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => SeoMeta::getTitle(),
        ];

        $data['hasPart'] = self::getPageParts($handle);

        return $data;
    }

    public static function getPageParts(string $handle): array
    {
        $parts = [];

        $slots = LayoutSlotRegistry::getSlotsForHandle($handle);

        foreach ($slots as $slotName => $entries) {
            foreach ($entries as $entry) {
                $parts[] = [
                    '@type' => 'WebPageElement',
                    'name' => $slotName,
                    'template' => $entry['template'],
                ];
            }
        }

        return $parts;
    }

    public static function render(): string
    {
        $context = self::getCurrentContext();
        
        if (!$context) {
            return '';
        }

        $resource = $context['response'] ?? null;
        $handle = $context['page_handle'] ?? $context['layout_handle'] ?? null;

        if (!$resource || !$handle) {
            return '';
        }

        $schema = self::generateForResource($resource, $handle);

        return '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }

    private static function getCurrentContext(): ?array
    {
        return null;
    }
}
