<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo;

use Semitexa\Ssr\Context\PageRenderContextStore;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;

final class SemanticRenderer
{
    /**
     * The page's WebPage. Its name is the SeoMeta title, else the title the
     * layout rendered (tags and entities undone); a page with neither carries
     * no name rather than an empty one (bench-page-jsonld-empty-name: every
     * generated page said "name": "").
     *
     * @return array<string, mixed>
     */
    public static function generateForResource(object $resource, string $handle, ?string $renderedTitle = null): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
        ];
        $name = SeoMeta::getTitle();
        if ($name === '' && $renderedTitle !== null) {
            $name = trim(html_entity_decode(strip_tags($renderedTitle), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($name !== '') {
            $data['name'] = $name;
        }

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

    public static function render(?string $renderedTitle = null): string
    {
        $html = '';
        $context = self::getCurrentContext();

        if (!$context) {
            return $html;
        }

        $resource = $context['response'] ?? null;
        $handle = $context['page_handle'] ?? $context['layout_handle'] ?? null;

        if (!$resource || !$handle) {
            return $html;
        }

        $schema = self::generateForResource($resource, $handle, $renderedTitle);

        $html .= '<script type="application/ld+json">' . json_encode(
            $schema,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ) . '</script>';

        foreach (self::getDeclaredAlternates($context) as $alternate) {
            $html .= '<link rel="alternate" type="'
                . htmlspecialchars($alternate['type'], ENT_QUOTES, 'UTF-8')
                . '" href="'
                . htmlspecialchars($alternate['href'], ENT_QUOTES, 'UTF-8')
                . '">';
        }

        return $html;
    }

    private static function getCurrentContext(): ?array
    {
        $context = PageRenderContextStore::get();
        return $context !== [] ? $context : null;
    }

    /**
     * @param array<string,mixed> $context
     * @return list<array{type:string,href:string}>
     */
    private static function getDeclaredAlternates(array $context): array
    {
        $alternates = $context['__page_alternates'] ?? null;
        if (!is_array($alternates)) {
            return [];
        }

        return array_values(array_filter($alternates, static function (mixed $entry): bool {
            return is_array($entry)
                && is_string($entry['type'] ?? null)
                && $entry['type'] !== ''
                && is_string($entry['href'] ?? null)
                && $entry['href'] !== '';
        }));
    }
}
