<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Page;

use Semitexa\Core\Config\EnvValueResolver;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Application\Service\Seo\SeoMeta;

final class PageDocumentProjector
{
    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $route
     * @return array<string,mixed>
     */
    public static function project(
        object $resource,
        Request $request,
        string $handle,
        array $context,
        array $route = [],
    ): array {
        $pageIri = self::buildHtmlIri($request);
        $jsonIri = self::buildJsonIri($request);
        $layoutFrame = is_string($context['layout_frame'] ?? null) ? $context['layout_frame'] : null;

        $slot = trim($request->getQuery('_slot'));
        $expand = self::parseExpand($request->getQuery('_expand'));

        if ($slot !== '') {
            return self::buildSlotDocument($resource, $handle, $slot, $context, $layoutFrame, $request);
        }

        $slots = self::buildSlotIndex($handle, $layoutFrame, $request);
        $mainContent = self::buildMainDocument($resource, $request, $context);

        if (self::expandAllSlots($expand)) {
            foreach (array_keys($slots) as $slotName) {
                $slots[$slotName]['content'] = self::buildSlotDocument(
                    $resource,
                    $handle,
                    $slotName,
                    $context,
                    $layoutFrame,
                    $request,
                );
            }
        } else {
            foreach (array_keys($slots) as $slotName) {
                if (in_array('slots.' . $slotName, $expand, true)) {
                    $slots[$slotName]['content'] = self::buildSlotDocument(
                        $resource,
                        $handle,
                        $slotName,
                        $context,
                        $layoutFrame,
                        $request,
                    );
                }
            }
        }

        return [
            'page' => [
                'iri' => $pageIri,
                'type' => 'page',
                'handle' => $handle,
                'title' => SeoMeta::getTitle(),
            ],
            'meta' => self::buildMetaDocument($pageIri, $jsonIri),
            'content' => $mainContent,
            'slots' => $slots,
            'links' => [
                'self' => $jsonIri,
                'html' => $pageIri,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function buildSlotDocument(
        object $resource,
        string $handle,
        string $slot,
        array $context,
        ?string $layoutFrame,
        Request $request,
    ): array {
        if ($slot === 'main') {
            return self::buildMainDocument($resource, $request, $context);
        }

        $slotIndex = self::buildSlotIndex($handle, $layoutFrame, $request);

        if (!isset($slotIndex[$slot])) {
            if (method_exists($resource, 'setStatusCode')) {
                $resource->setStatusCode(404);
            }

            return [
                'error' => 'Unknown slot',
                'message' => 'Available slots: ' . implode(', ', array_keys($slotIndex)),
            ];
        }

        return [
            'slot' => $slot,
            'iri' => self::buildSlotIri($request, $slot),
            'deferred' => (bool) ($slotIndex[$slot]['deferred'] ?? false),
            'content_type' => 'text/html',
            'html' => LayoutSlotRegistry::render($handle, $slot, $context, [], $layoutFrame),
        ];
    }

    /**
     * @return array<string,array{iri:string,deferred:bool}>
     */
    private static function buildSlotIndex(string $handle, ?string $layoutFrame, Request $request): array
    {
        $slots = [
            'main' => [
                'iri' => self::buildSlotIri($request, 'main'),
                'deferred' => false,
            ],
        ];

        foreach (LayoutSlotRegistry::describeSlotsForPage($handle, $layoutFrame) as $slotName => $meta) {
            $slots[$slotName] = [
                'iri' => self::buildSlotIri($request, $slotName),
                'deferred' => (bool) ($meta['deferred'] ?? false),
            ];
        }

        return $slots;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function buildMainDocument(object $resource, Request $request, array $context): array
    {
        return [
            'slot' => 'main',
            'iri' => self::buildSlotIri($request, 'main'),
            'content_type' => 'application/json',
            'data' => self::sanitizeContext($context),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function buildMetaDocument(string $pageIri, string $jsonIri): array
    {
        $meta = SeoMeta::all();

        return [
            'title' => SeoMeta::getTitle(),
            'description' => $meta['description'] ?? null,
            'keywords' => self::normalizeKeywords($meta['keywords'] ?? null),
            'canonical' => $pageIri,
            'alternates' => [
                'html' => $pageIri,
                'json' => $jsonIri,
            ],
            'open_graph' => array_filter([
                'title' => $meta['og:title'] ?? null,
                'description' => $meta['og:description'] ?? null,
                'type' => $meta['og:type'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    private static function buildHtmlIri(Request $request): string
    {
        $query = $request->query;
        unset($query['_format'], $query['_slot'], $query['_expand']);

        $path = $request->getPath();
        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query);
    }

    private static function buildJsonIri(Request $request): string
    {
        $query = $request->query;
        unset($query['_slot'], $query['_expand']);
        $query['_format'] = 'json';

        return $request->getPath() . '?' . http_build_query($query);
    }

    private static function buildSlotIri(Request $request, string $slot): string
    {
        $query = $request->query;
        unset($query['_expand']);
        $query['_format'] = 'json';
        $query['_slot'] = $slot;

        return $request->getPath() . '?' . http_build_query($query);
    }

    /**
     * @return list<string>
     */
    private static function parseExpand(string $expand): array
    {
        if ($expand === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $expand)
        )));
    }

    /**
     * @param list<string> $expand
     */
    private static function expandAllSlots(array $expand): bool
    {
        return in_array('slots', $expand, true);
    }

    /**
     * @return list<string>
     */
    private static function normalizeKeywords(mixed $keywords): array
    {
        if (is_array($keywords)) {
            return array_values(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $keywords
            )));
        }

        if (!is_string($keywords) || $keywords === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $keywords)
        )));
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (str_starts_with((string) $key, '__')) {
                continue;
            }

            $normalized = self::normalizeValue($value);
            if ($normalized !== null) {
                $sanitized[$key] = $normalized;
            }
        }

        return $sanitized;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return EnvValueResolver::resolve($value);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $itemNormalized = self::normalizeValue($item);
                if ($itemNormalized !== null) {
                    $normalized[$key] = $itemNormalized;
                }
            }

            return $normalized;
        }

        return null;
    }
}
